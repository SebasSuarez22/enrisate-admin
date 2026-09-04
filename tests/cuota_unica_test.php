<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/facturas.php';

$fallos = 0;
function verificar(bool $cond, string $msg): void
{
    if ($cond) {
        echo "OK   - $msg\n";
    } else {
        echo "FAIL - $msg\n";
        $GLOBALS['fallos']++;
    }
}

/* --- rotulo_cuota: "Pago único" solo cuando de verdad hay una sola cuota --- */

verificar(rotulo_cuota(0, 1) === 'Pago único',
    'rotulo_cuota: con 1 sola cuota total, dice "Pago único"');
verificar(rotulo_cuota(0, 2) === 'Primera cuota',
    'rotulo_cuota: con 2 cuotas totales, la primera sigue diciendo "Primera cuota"');
verificar(rotulo_cuota(1, 2) === 'Segunda cuota',
    'rotulo_cuota: con 2 cuotas totales, la segunda sigue diciendo "Segunda cuota"');
verificar(rotulo_cuota(0) === 'Primera cuota',
    'rotulo_cuota: sin pasar el total (uso viejo), sigue funcionando igual que antes');

/* --- Un "pago único a plazo" real: factura con plan cuotas y un solo elemento en cuotas --- */

$ruta     = ruta_facturas();
$respaldo = is_file($ruta) ? file_get_contents($ruta) : null;

try {
    $numero = crear_factura([
        'cliente' => '__TEST_UNICA__', 'telefono' => '3000000000', 'nota' => '', 'lineas' => [],
        'subtotal' => 80000, 'descuento' => 0, 'total' => 80000, 'costo' => 0, 'margen' => 80000,
        'plan' => 'cuotas', 'abono' => 0, 'saldo_aplicado' => 0,
        'cuotas' => [
            ['monto' => 80000, 'vence' => '2026-01-01', 'abonos' => []],
        ],
        'pagado' => 0, 'fecha_pago' => null,
    ]);

    $f = factura_por_numero($numero);
    verificar(count($f['cuotas']) === 1,
        'pago único: la factura queda con un solo elemento en "cuotas"');
    verificar(cuota_esta_pagada($f['cuotas'][0]) === false,
        'pago único: no queda marcada como pagada al crearla (a diferencia de "contado")');
    verificar(esta_vencida($f['cuotas'][0]['vence']) === true,
        'pago único: con fecha pasada, cuenta como vencida (misma regla que cualquier cuota)');

    verificar(registrar_abono($numero, 0, 80000) === true,
        'pago único: el botón de "Registrar abono" por el monto completo la marca pagada');
    $f = factura_por_numero($numero);
    verificar(cuota_esta_pagada($f['cuotas'][0]) === true,
        'pago único: queda pagada tras registrar el abono completo');
} finally {
    if ($respaldo === null) {
        @unlink($ruta);
    } else {
        file_put_contents($ruta, $respaldo, LOCK_EX);
    }
}

echo "\n" . ($fallos === 0 ? "TODO OK" : "$fallos prueba(s) fallaron") . "\n";
exit($fallos === 0 ? 0 : 1);
