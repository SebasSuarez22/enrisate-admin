<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/pedidos.php';

/* IP real del visitante y límite de pedidos por IP.

   En Railway la app ve direcciones internas del proxy (100.64.x.x), distintas
   en cada petición; la IP real llega en X-Real-IP. Solo se le cree a esa
   cabecera si la conexión viene del proxy: si no, cualquiera inventaría su IP. */

function visita(string $remota, ?string $realIp = null): void
{
    $_SERVER['REMOTE_ADDR'] = $remota;
    if ($realIp === null) {
        unset($_SERVER['HTTP_X_REAL_IP']);
    } else {
        $_SERVER['HTTP_X_REAL_IP'] = $realIp;
    }
}

/* --- ip_visitante() --- */
visita('100.64.0.13', '190.24.5.6');
verificar(ip_visitante() === '190.24.5.6', 'detrás del proxy de Railway se usa la IP real (X-Real-IP)');
visita('100.127.255.254', '190.24.5.6');
verificar(ip_visitante() === '190.24.5.6', 'el último tramo del rango del proxy (100.127.x.x) también se reconoce');
visita('100.128.0.1', '190.24.5.6');
verificar(ip_visitante() === '100.128.0.1', 'una dirección justo fuera del rango del proxy no puede imponer su X-Real-IP');
visita('127.0.0.1', '8.8.8.8');
verificar(ip_visitante() === '127.0.0.1', 'sin proxy (XAMPP, conexión directa) la cabecera se ignora: nadie inventa su IP');
visita('190.24.5.6', '1.1.1.1');
verificar(ip_visitante() === '190.24.5.6', 'una IP pública que manda X-Real-IP no puede hacerse pasar por otra');
visita('100.64.0.1', 'no-es-una-ip');
verificar(ip_visitante() === '100.64.0.1', 'una X-Real-IP inválida se descarta');
visita('100.64.0.1', '1.2.3.4, 5.6.7.8');
verificar(ip_visitante() === '100.64.0.1', 'una X-Real-IP con varias direcciones se descarta');
visita('100.64.0.1', '2800:e2:1a80:1234:abcd::1');
verificar(ip_visitante() === '2800:e2:1a80:1234:abcd::1', 'la IP real puede ser IPv6');
visita('100.64.0.1');
verificar(ip_visitante() === '100.64.0.1', 'sin X-Real-IP se usa la dirección de la conexión');
unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_REAL_IP']);
verificar(ip_visitante() === '0.0.0.0', 'sin ninguna dirección se usa 0.0.0.0 (no falla)');

/* --- clave_limite_ip() --- */
verificar(clave_limite_ip('190.24.5.6') === '190.24.5.6', 'IPv4: la clave es la dirección tal cual');
verificar(clave_limite_ip('2800:e2:1a80:1234:abcd::1') === clave_limite_ip('2800:e2:1a80:1234:ffff:ffff:ffff:9'),
    'IPv6: dos direcciones del mismo bloque /64 cuentan como la misma persona');
verificar(clave_limite_ip('2800:e2:1a80:1234::1') !== clave_limite_ip('2800:e2:1a80:1235::1'),
    'IPv6: bloques /64 distintos cuentan por separado');

/* --- Límite de pedidos (contra MySQL) --- */
db()->exec('DELETE FROM limite_pedidos');
register_shutdown_function(fn() => db()->exec('DELETE FROM limite_pedidos'));

// La misma clienta, llegando cada vez por una dirección distinta del proxy (lo que pasa de verdad).
$bloqueadaEn = null;
for ($i = 1; $i <= MAX_PEDIDOS_POR_HORA + 1; $i++) {
    visita('100.64.0.' . $i, '190.24.5.6');
    if (pedido_ip_bloqueada()) {
        $bloqueadaEn = $i;
        break;
    }
    registrar_pedido_ip();
}
verificar($bloqueadaEn === MAX_PEDIDOS_POR_HORA + 1,
    'la misma persona queda limitada a ' . MAX_PEDIDOS_POR_HORA . ' pedidos por hora aunque el proxy le cambie la dirección en cada visita'
    . ' (bloqueada en el pedido ' . var_export($bloqueadaEn, true) . ')');

visita('100.64.0.1', '181.50.7.8');
verificar(!pedido_ip_bloqueada(), 'otra clienta, desde otra IP, sigue pudiendo pedir');

// Alguien que rota su IPv6 dentro de su propio /64
db()->exec('DELETE FROM limite_pedidos');
for ($i = 1; $i <= MAX_PEDIDOS_POR_HORA; $i++) {
    visita('100.64.0.2', sprintf('2800:e2:1a80:1234::%x', $i));
    registrar_pedido_ip();
}
visita('100.64.0.2', '2800:e2:1a80:1234::ffff');
verificar(pedido_ip_bloqueada(), 'rotar la IPv6 dentro del mismo /64 no permite saltarse el límite');

// Lo de hace más de una hora no cuenta y se borra
db()->exec('DELETE FROM limite_pedidos');
$viejo = db()->prepare('INSERT INTO limite_pedidos (ip_clave, momento) VALUES (?, ?)');
for ($i = 0; $i < MAX_PEDIDOS_POR_HORA; $i++) {
    $viejo->execute(['190.24.5.6', time() - 3601]);
}
visita('100.64.0.3', '190.24.5.6');
verificar(!pedido_ip_bloqueada(), 'los pedidos de hace más de una hora ya no cuentan');
registrar_pedido_ip();
verificar((int) db()->query('SELECT COUNT(*) FROM limite_pedidos')->fetchColumn() === 1,
    'al registrar un pedido se borran los registros de hace más de una hora');

terminar_pruebas();
