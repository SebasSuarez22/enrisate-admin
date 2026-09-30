<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/pedidos.php';
require_once __DIR__ . '/../includes/clientes.php';

/* Clientas, pedidos y catálogo guardados a la vez no deben perder datos.

   Igual que concurrencia_test.php (que cubre las facturas): lanza procesos PHP
   reales que escriben al mismo tiempo, y comprueba que no falte nada.
   Cada uno de estos almacenes reescribe TODO en cada cambio, así que sin
   turno la segunda escritura borra lo que hizo la primera sin avisar. */

const POR_PROCESO = 10;

function limpiar_otros(): void
{
    foreach (['clientes', 'pedidos', 'productos'] as $t) {
        db()->exec("DELETE FROM $t");
    }
}
limpiar_otros();
register_shutdown_function('limpiar_otros');

// Estado inicial: catálogo sembrado, una clienta con saldo a favor y dos SKU para los precios.
$baseProductos = count(catalogo());
$skuA = catalogo()[0]['sku'];
$skuB = catalogo()[1]['sku'];
agregar_cliente('Clienta con saldo', '3001112222', '');
$idSaldo = leer_clientes()[0]['id'];
actualizar_cliente($idSaldo, 'Clienta con saldo', '3001112222', '', 100000);

$inicio = microtime(true) + 2.0;
$worker = __DIR__ . '/_worker_concurrencia_otros.php';
$lanzar = function (string $rol, int $cant, string $dato = '') use ($worker, $inicio) {
    $p = proc_open([PHP_BINARY, $worker, $rol, (string) $cant, sprintf('%.6F', $inicio), $dato],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    return [$p, $pipes];
};
$procesos = [
    $lanzar('clientes', POR_PROCESO), $lanzar('clientes', POR_PROCESO),
    $lanzar('saldo', POR_PROCESO, $idSaldo), $lanzar('saldo', POR_PROCESO, $idSaldo),
    $lanzar('pedidos', POR_PROCESO), $lanzar('pedidos', POR_PROCESO),
    $lanzar('catalogo', POR_PROCESO), $lanzar('catalogo', POR_PROCESO),
    $lanzar('precios', POR_PROCESO, $skuA), $lanzar('precios', POR_PROCESO, $skuB),
];
$res = [];
foreach ($procesos as [$p, $pipes]) {
    $salida = stream_get_contents($pipes[1]);
    $err    = stream_get_contents($pipes[2]);
    proc_close($p);
    $j = json_decode(trim($salida), true);
    $res[] = is_array($j) ? $j : ['rol' => '?', 'ok' => 0, 'errores' => 1, 'primer_error' => trim($err ?: $salida)];
    echo '     ' . json_encode(end($res), JSON_UNESCAPED_UNICODE) . "\n";
}
$errores = array_sum(array_column($res, 'errores'));

// Se lee de MySQL directo, no de la caché en memoria de esta petición.
$n = fn(string $t): int => (int) db()->query("SELECT COUNT(*) FROM $t")->fetchColumn();
$saldo = (int) db()->query('SELECT saldo_favor FROM clientes WHERE id = ' . db()->quote($idSaldo))->fetchColumn();
$precio = fn(string $sku): int => (int) db()->query('SELECT precio FROM productos WHERE sku = ' . db()->quote($sku))->fetchColumn();
$pedidos = db()->query('SELECT numero FROM pedidos')->fetchAll(PDO::FETCH_COLUMN);

verificar($errores === 0, 'ningún proceso terminó con error (sin choques ni bloqueos vencidos)');
verificar($n('clientes') === 1 + 2 * POR_PROCESO,
    'las ' . (2 * POR_PROCESO) . ' clientas agregadas a la vez quedaron todas (hay ' . ($n('clientes') - 1) . ')');
verificar($saldo === 100000 - 2 * POR_PROCESO * 1000,
    'el saldo a favor descontado desde dos procesos suma exacto (queda ' . $saldo . ', esperado ' . (100000 - 2 * POR_PROCESO * 1000) . ')');
verificar(count($pedidos) === 2 * POR_PROCESO && count($pedidos) === count(array_unique($pedidos)),
    'los ' . (2 * POR_PROCESO) . ' pedidos creados a la vez quedaron todos y sin números repetidos (hay ' . count($pedidos) . ')');
verificar($n('productos') === $baseProductos + 2 * POR_PROCESO,
    'los ' . (2 * POR_PROCESO) . ' productos agregados a la vez quedaron todos (hay ' . ($n('productos') - $baseProductos) . ')');
verificar($precio($skuA) === 1000 + POR_PROCESO && $precio($skuB) === 1000 + POR_PROCESO,
    'cambiar el precio de dos productos a la vez conserva los dos cambios (' . $precio($skuA) . ' y ' . $precio($skuB) . ', esperado ' . (1000 + POR_PROCESO) . ')');

terminar_pruebas();
