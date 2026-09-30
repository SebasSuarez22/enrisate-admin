<?php
declare(strict_types=1);

/* Proceso auxiliar de tests/concurrencia_otros_test.php (el guion bajo evita
   que correr_todas.php lo tome como una prueba). No se corre solo.

       php _worker_concurrencia_otros.php <rol> <cantidad> <inicio_unix_microtime> <dato>

   Roles:
     clientes  agrega <cantidad> clientas con nombre único
     saldo     resta 1.000 de saldo a favor a la clienta <dato> (su id), <cantidad> veces
     pedidos   crea <cantidad> pedidos
     catalogo  agrega <cantidad> productos con SKU único
     precios   sube <cantidad> veces el precio del SKU <dato> (1, 2, 3...)

   Imprime una línea JSON con lo que pasó. */

if (PHP_SAPI !== 'cli') { exit; }
require_once __DIR__ . '/../includes/pedidos.php';
require_once __DIR__ . '/../includes/clientes.php';
if (!str_ends_with(DB_NOMBRE, '_test')) { fwrite(STDERR, "solo en bases _test\n"); exit(2); }

[, $rol, $cantidad, $inicio, $dato] = $argv + [4 => ''];
$cantidad = (int) $cantidad;
$pid = getmypid();

while (microtime(true) < (float) $inicio) { usleep(200); }

$r = ['rol' => $rol, 'ok' => 0, 'errores' => 0, 'primer_error' => ''];
for ($i = 1; $i <= $cantidad; $i++) {
    try {
        switch ($rol) {
            case 'clientes':
                $r['ok'] += agregar_cliente("Clienta $pid-$i", '300' . str_pad((string) ($pid % 10000), 4, '0', STR_PAD_LEFT) . str_pad((string) $i, 3, '0', STR_PAD_LEFT), '') === '' ? 1 : 0;
                break;
            case 'saldo':
                $r['ok'] += restar_saldo_favor($dato, 1000) === 1000 ? 1 : 0;
                break;
            case 'pedidos':
                crear_pedido(['cliente' => "Pedido $pid", 'telefono' => '3000000000', 'lineas' => [], 'subtotal' => 1000, 'total' => 1000, 'plan' => 'contado', 'num_cuotas_preferido' => null]);
                $r['ok']++;
                break;
            case 'catalogo':
                $r['ok'] += agregar_producto("CONC-$pid-$i", 'Prueba', "Producto $pid-$i", 1000, 500) === '' ? 1 : 0;
                break;
            case 'precios':
                $r['ok'] += actualizar_precio_costo($dato, 1000 + $i, 500) ? 1 : 0;
                break;
        }
    } catch (Throwable $e) {
        $r['errores']++;
        $r['primer_error'] = $r['primer_error'] ?: substr($e->getMessage(), 0, 120);
    }
}
echo json_encode($r, JSON_UNESCAPED_UNICODE), "\n";
