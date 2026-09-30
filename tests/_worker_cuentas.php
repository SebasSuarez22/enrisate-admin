<?php
declare(strict_types=1);

/* Proceso auxiliar de tests/cuentas_test.php (el guion bajo evita que
   correr_todas.php lo tome como una prueba). No se corre solo.

       php _worker_cuentas.php <rol> <inicio_unix_microtime> <dato1> <dato2>

   Roles:
     saldo    crea una cuenta de contado para la clienta <dato1> aplicando todo su saldo (<dato2> = SKU)
     pedido   crea una cuenta desde el pedido <dato1> (<dato2> = SKU)

   Imprime una línea JSON con lo que pasó. */

if (PHP_SAPI !== 'cli') { exit; }
require_once __DIR__ . '/../includes/cuentas.php';
if (!str_ends_with(DB_NOMBRE, '_test')) { fwrite(STDERR, "solo en bases _test\n"); exit(2); }

[, $rol, $inicio, $dato1, $dato2] = $argv;
while (microtime(true) < (float) $inicio) { usleep(200); }

$post = ['cliente' => 'Clienta con saldo', 'telefono' => '3001112222', 'plan' => 'contado', 'descuento' => 0,
         'items' => json_encode([['sku' => $dato2, 'cant' => 1]])];
if ($rol === 'saldo')  { $post += ['cliente_id' => $dato1, 'saldo_aplicado' => 999999]; }
if ($rol === 'pedido') { $post += ['desde_pedido' => $dato1]; }

$r = ['rol' => $rol, 'numero' => null, 'rechazada' => '', 'error' => ''];
try {
    $r['numero'] = crear_cuenta_desde_formulario($post);
} catch (DomainException $e) {
    $r['rechazada'] = $e->getMessage();
} catch (Throwable $e) {
    $r['error'] = substr($e->getMessage(), 0, 140);
}
echo json_encode($r, JSON_UNESCAPED_UNICODE), "\n";
