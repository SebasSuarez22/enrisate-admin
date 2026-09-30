<?php
declare(strict_types=1);

// Solo desde la terminal: si alguien la abre desde el navegador, no existe.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/* Prueba TEMPORAL de escritura de extremo a extremo contra MySQL.
   No forma parte de la app — se borra después de validar la migración. */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/facturas.php';
require_once __DIR__ . '/../includes/clientes.php';
require_once __DIR__ . '/../includes/pedidos.php';

$errores = 0;
function chk(bool $cond, string $msg): void
{
    global $errores;
    echo ($cond ? 'ok: ' : 'FALLO: ') . $msg . "\n";
    if (!$cond) {
        $errores++;
    }
}

/* --- Productos --- */
$msg = agregar_producto('ZZZ-TEST', 'MarcaPrueba', 'Producto de prueba', 10000, 6000);
chk($msg === '', "agregar_producto sin error (mensaje: '$msg')");
$p = producto_por_sku('ZZZ-TEST');
chk($p !== null && $p['precio'] === 10000, 'producto_por_sku encuentra el producto recién creado');

chk(actualizar_precio_costo('ZZZ-TEST', 12000, 7000), 'actualizar_precio_costo devuelve true');
chk(producto_por_sku('ZZZ-TEST')['precio'] === 12000, 'el precio quedó actualizado en 12000');

chk(actualizar_producto('ZZZ-TEST', 'MarcaPrueba2', 'Nombre nuevo'), 'actualizar_producto devuelve true');
chk(producto_por_sku('ZZZ-TEST')['marca'] === 'MarcaPrueba2', 'la marca quedó actualizada');

/* --- Clientes --- */
$errCli = agregar_cliente('Clienta de Prueba', '3001112233', 'nota de prueba');
chk($errCli === '', "agregar_cliente sin error (mensaje: '$errCli')");

$clientes = leer_clientes();
$nueva = null;
foreach ($clientes as $c) {
    if ($c['nombre'] === 'Clienta de Prueba') { $nueva = $c; break; }
}
chk($nueva !== null, 'la clienta de prueba aparece en leer_clientes()');

if ($nueva) {
    $errAct = actualizar_cliente($nueva['id'], 'Clienta de Prueba', '3001112233', 'nota editada', 5000);
    chk($errAct === '', "actualizar_cliente sin error (mensaje: '$errAct')");
    $recargada = cliente_por_id($nueva['id']);
    chk($recargada['saldo_favor'] === 5000, 'saldo_favor quedó en 5000 tras actualizar_cliente');

    $usado = restar_saldo_favor($nueva['id'], 2000);
    chk($usado === 2000, 'restar_saldo_favor descontó 2000');
    chk(cliente_por_id($nueva['id'])['saldo_favor'] === 3000, 'saldo_favor quedó en 3000 tras restar_saldo_favor');
}

/* --- Facturas (contado) --- */
$numAntes = siguiente_numero();
$numeroContado = crear_factura([
    'cliente'        => 'Clienta de Prueba',
    'telefono'       => '3001112233',
    'nota'           => '',
    'lineas'         => [['sku' => 'ZZZ-TEST', 'nombre' => 'Nombre nuevo', 'marca' => 'MarcaPrueba2', 'precio' => 12000, 'costo' => 7000, 'cant' => 2]],
    'subtotal'       => 24000,
    'descuento'      => 0,
    'total'          => 24000,
    'costo'          => 14000,
    'margen'         => 10000,
    'plan'           => 'contado',
    'abono'          => 0,
    'saldo_aplicado' => 0,
    'cuotas'         => [],
    'pagado'         => 24000,
    'fecha_pago'     => date('Y-m-d'),
]);
chk($numeroContado === $numAntes, "crear_factura devolvió el número esperado ($numeroContado)");
$facturaContado = factura_por_numero($numeroContado);
chk($facturaContado !== null && $facturaContado['total'] === 24000, 'factura_por_numero encuentra la factura de contado con el total correcto');
chk(count($facturaContado['lineas']) === 1 && $facturaContado['lineas'][0]['cant'] === 2, 'la línea de la factura quedó con cant=2');

/* --- Facturas (a cuotas) + abono + anulación --- */
$numeroCuotas = crear_factura([
    'cliente'        => 'Clienta de Prueba',
    'telefono'       => '3001112233',
    'nota'           => '',
    'lineas'         => [['sku' => 'ZZZ-TEST', 'nombre' => 'Nombre nuevo', 'marca' => 'MarcaPrueba2', 'precio' => 12000, 'costo' => 7000, 'cant' => 1]],
    'subtotal'       => 12000,
    'descuento'      => 0,
    'total'          => 12000,
    'costo'          => 7000,
    'margen'         => 5000,
    'plan'           => 'cuotas',
    'abono'          => 2000,
    'saldo_aplicado' => 0,
    'cuotas'         => [
        ['monto' => 5000, 'vence' => date('Y-m-d', strtotime('+30 days')), 'abonos' => []],
        ['monto' => 5000, 'vence' => date('Y-m-d', strtotime('+60 days')), 'abonos' => []],
    ],
    'pagado'         => 2000,
    'fecha_pago'     => null,
]);
chk(registrar_abono($numeroCuotas, 0, 5000), 'registrar_abono aplica el pago completo de la primera cuota');
$facturaCuotas = factura_por_numero($numeroCuotas);
chk(cuota_esta_pagada($facturaCuotas['cuotas'][0]), 'la primera cuota queda marcada como pagada');
chk(!cuota_esta_pagada($facturaCuotas['cuotas'][1]), 'la segunda cuota sigue pendiente');
chk($facturaCuotas['pagado'] === 7000, "pagado quedó en 7000 (abono 2000 + cuota 5000): {$facturaCuotas['pagado']}");

chk(!registrar_abono($numeroCuotas, 1, 999999), 'registrar_abono rechaza un monto mayor al pendiente');

chk(anular_factura($numeroCuotas, 'prueba'), 'anular_factura funciona');
chk(factura_por_numero($numeroCuotas)['anulada'] === true, 'la factura queda marcada como anulada');
chk(reactivar_factura($numeroCuotas), 'reactivar_factura funciona');
chk(factura_por_numero($numeroCuotas)['anulada'] === false, 'la factura queda reactivada');

/* --- Pedidos --- */
$numPedido = crear_pedido([
    'cliente'  => 'Clienta de Prueba',
    'telefono' => '3001112233',
    'lineas'   => [['sku' => 'ZZZ-TEST', 'nombre' => 'Nombre nuevo', 'marca' => 'MarcaPrueba2', 'precio' => 12000, 'cant' => 1]],
    'subtotal' => 12000,
    'total'    => 12000,
    'plan'     => 'contado',
]);
chk(str_starts_with($numPedido, 'P-'), "crear_pedido devuelve numero con formato P- ($numPedido)");
chk(pedido_por_numero($numPedido)['estado'] === 'pendiente', 'el pedido queda pendiente');
chk(marcar_pedido_confirmado($numPedido, $numeroContado), 'marcar_pedido_confirmado funciona');
$pedidoConfirmado = pedido_por_numero($numPedido);
chk($pedidoConfirmado['estado'] === 'confirmado' && $pedidoConfirmado['factura_numero'] === $numeroContado, 'el pedido queda confirmado y enlazado a la factura');

/* --- Limpieza de los datos de prueba --- */
chk(eliminar_productos(['ZZZ-TEST']) === 1, 'se limpió el producto de prueba');
if ($nueva) {
    chk(eliminar_clientes([$nueva['id']]) === 1, 'se limpió la clienta de prueba');
}
// Las facturas y el pedido de prueba se dejan anulados/confirmados de ejemplo
// para no complicar la limpieza vía funciones existentes (no hay "eliminar_factura").
// Se borran directamente para dejar la base de prueba limpia.
$pdo = db();
$pdo->prepare('DELETE FROM facturas WHERE numero IN (:a, :b)')->execute([':a' => $numeroContado, ':b' => $numeroCuotas]);
$pdo->prepare('DELETE FROM pedidos WHERE numero = :n')->execute([':n' => $numPedido]);

echo "\n" . ($errores === 0 ? "TODO OK — 0 fallos." : "HAY $errores FALLOS.") . "\n";
exit($errores === 0 ? 0 : 1);
