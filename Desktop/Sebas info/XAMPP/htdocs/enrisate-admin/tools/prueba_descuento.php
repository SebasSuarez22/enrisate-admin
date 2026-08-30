<?php
declare(strict_types=1);

/* Prueba TEMPORAL de aplicar_descuento_cuota() contra MySQL.
   No forma parte de la app — se borra después de validar la funcionalidad. */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/facturas.php';

$errores = 0;
function chk(bool $cond, string $msg): void
{
    global $errores;
    echo ($cond ? 'ok: ' : 'FALLO: ') . $msg . "\n";
    if (!$cond) {
        $errores++;
    }
}

$numAntes = siguiente_numero();
$numero = crear_factura([
    'cliente'        => 'Clienta Descuento Prueba',
    'telefono'       => '3009998877',
    'nota'           => '',
    'lineas'         => [['sku' => 'ZZZ-DESC', 'nombre' => 'Producto prueba', 'marca' => 'Prueba', 'precio' => 10000, 'costo' => 6000, 'cant' => 1]],
    'subtotal'       => 10000,
    'descuento'      => 0,
    'total'          => 10000,
    'costo'          => 6000,
    'margen'         => 4000,
    'plan'           => 'cuotas',
    'abono'          => 0,
    'saldo_aplicado' => 0,
    'cuotas'         => [
        ['monto' => 5000, 'vence' => date('Y-m-d', strtotime('+30 days')), 'abonos' => []],
        ['monto' => 5000, 'vence' => date('Y-m-d', strtotime('+60 days')), 'abonos' => []],
    ],
    'pagado'         => 0,
    'fecha_pago'     => null,
]);
chk($numero === $numAntes, "crear_factura devolvió el número esperado ($numero)");

/* --- Rechazos --- */
chk(!aplicar_descuento_cuota($numero, 1, 5000, ''), 'rechaza motivo vacío');
chk(!aplicar_descuento_cuota($numero, 1, 5000, '   '), 'rechaza motivo solo con espacios');
chk(!aplicar_descuento_cuota($numero, 1, 999999, 'cliente frecuente'), 'rechaza monto mayor al pendiente');
chk(!aplicar_descuento_cuota($numero, 9, 1000, 'cliente frecuente'), 'rechaza índice de cuota inexistente');
chk(!aplicar_descuento_cuota($numero, 1, 0, 'cliente frecuente'), 'rechaza monto en cero');

/* Nada de lo anterior debió cambiar la cuota */
$f = factura_por_numero($numero);
chk(cuota_pendiente($f['cuotas'][1]) === 5000, 'la segunda cuota sigue en 5000 pendiente tras los rechazos');

/* --- Descuento parcial: deja $5.000 en la segunda cuota, perdona $3.000 --- */
chk(aplicar_descuento_cuota($numero, 1, 3000, 'Cliente frecuente, se acuerda descuento'), 'aplica descuento parcial de 3000');
$f = factura_por_numero($numero);
chk(cuota_pendiente($f['cuotas'][1]) === 2000, 'la segunda cuota queda en 2000 pendiente tras el descuento parcial');
chk(!cuota_esta_pagada($f['cuotas'][1]), 'la segunda cuota sigue sin estar "pagada" del todo');

$abonoDescuento = $f['cuotas'][1]['abonos'][0] ?? null;
chk($abonoDescuento !== null && $abonoDescuento['tipo'] === 'descuento', 'el abono quedó marcado como tipo=descuento');
chk($abonoDescuento !== null && $abonoDescuento['motivo'] === 'Cliente frecuente, se acuerda descuento', 'el motivo del descuento se guardó tal cual');

/* --- Se completa con un abono real de los $2.000 que faltan --- */
chk(registrar_abono($numero, 1, 2000), 'registrar_abono cubre lo que falta después del descuento');
$f = factura_por_numero($numero);
chk(cuota_esta_pagada($f['cuotas'][1]), 'la segunda cuota queda completamente pagada (descuento + abono real)');

$tipos = array_column($f['cuotas'][1]['abonos'], 'tipo');
chk($tipos === ['descuento', 'abono'], 'la cuota conserva un abono de cada tipo, en orden: ' . implode(',', $tipos));

/* Ya no debería aceptar más descuentos: no queda nada pendiente */
chk(!aplicar_descuento_cuota($numero, 1, 100, 'ya no debería aplicar'), 'rechaza descuento cuando ya no hay pendiente');

/* --- Factura anulada: no debe permitir descuentos --- */
chk(anular_factura($numero, 'prueba'), 'anular_factura funciona (preparación)');
chk(!aplicar_descuento_cuota($numero, 0, 1000, 'no debería aplicar en anulada'), 'rechaza descuento en factura anulada');
chk(reactivar_factura($numero), 'reactivar_factura funciona (limpieza)');

/* --- Limpieza --- */
$pdo = db();
$pdo->prepare('DELETE FROM facturas WHERE numero = :n')->execute([':n' => $numero]);
chk(factura_por_numero($numero) === null, 'la factura de prueba quedó eliminada');

echo "\n" . ($errores === 0 ? "TODO OK — 0 fallos." : "HAY $errores FALLOS.") . "\n";
exit($errores === 0 ? 0 : 1);
