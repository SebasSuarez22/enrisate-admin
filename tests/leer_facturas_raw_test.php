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

$ruta     = ruta_facturas();
$respaldo = is_file($ruta) ? file_get_contents($ruta) : null;

try {
    // Sembrar una factura vieja (formato cuota1/cuota2) directamente en el
    // archivo, simulando datos reales previos a la migración.
    $crudo = is_array(json_decode((string) $respaldo, true)) ? json_decode((string) $respaldo, true) : [];
    $crudo[] = [
        'numero' => '__TEST_VIEJA__',
        'cliente' => '__TEST__', 'telefono' => '', 'nota' => '', 'lineas' => [],
        'subtotal' => 0, 'descuento' => 0, 'total' => 100000, 'costo' => 0, 'margen' => 100000,
        'plan' => 'cuotas', 'abono' => 0, 'saldo_aplicado' => 0,
        'cuota1' => 60000, 'cuota2' => 40000, 'vence1' => '2026-01-01', 'vence2' => '2026-02-01',
        'cuota1_pagada' => false, 'cuota2_pagada' => false,
        'pagado' => 0, 'fecha_pago' => null, 'creada' => '2024-01-01T00:00:00-05:00',
    ];
    file_put_contents($ruta, json_encode($crudo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

    verificar(!isset(leer_facturas_raw()[count($crudo) - 1]['cuotas']),
        'leer_facturas_raw: la factura vieja sembrada NO tiene la clave "cuotas" en crudo');

    // Crear una factura nueva (dispara guardar_facturas con la lista completa).
    $numeroNueva = crear_factura([
        'cliente' => '__TEST_NUEVA__', 'telefono' => '', 'nota' => '', 'lineas' => [],
        'subtotal' => 50000, 'descuento' => 0, 'total' => 50000, 'costo' => 0, 'margen' => 50000,
        'plan' => 'contado', 'abono' => 0, 'saldo_aplicado' => 0, 'cuotas' => [],
        'pagado' => 50000, 'fecha_pago' => '2026-01-01',
    ]);

    // Releer el archivo crudo directamente del disco y confirmar que la
    // factura vieja sembrada SIGUE sin la clave "cuotas": crear_factura()
    // no debió rehornear la migración sobre ella.
    $crudoDespues = json_decode((string) file_get_contents($ruta), true);
    $viejaDespues = null;
    foreach ($crudoDespues as $f) {
        if ($f['numero'] === '__TEST_VIEJA__') {
            $viejaDespues = $f;
        }
    }
    verificar($viejaDespues !== null, 'setup: la factura vieja sembrada sigue en el archivo tras crear_factura()');
    verificar(!isset($viejaDespues['cuotas']),
        'crear_factura(): NO agrega la clave "cuotas" a una factura vieja que no se estaba tocando');

    // Ahora actualizar la factura NUEVA (registrar_abono/anular_factura usan
    // actualizar_factura) y confirmar que la VIEJA sigue intacta.
    anular_factura($numeroNueva);
    $crudoFinal = json_decode((string) file_get_contents($ruta), true);
    $viejaFinal = null;
    foreach ($crudoFinal as $f) {
        if ($f['numero'] === '__TEST_VIEJA__') {
            $viejaFinal = $f;
        }
    }
    verificar($viejaFinal !== null && !isset($viejaFinal['cuotas']),
        'actualizar_factura(): tampoco agrega "cuotas" a una factura vieja que no se estaba tocando');

    // Confirmar que leer_facturas() (la versión pública) SIGUE migrando en
    // memoria con normalidad para quien la use para mostrar datos.
    $migrada = null;
    foreach (leer_facturas() as $f) {
        if ($f['numero'] === '__TEST_VIEJA__') {
            $migrada = $f;
        }
    }
    verificar($migrada !== null && isset($migrada['cuotas']) && count($migrada['cuotas']) === 2,
        'leer_facturas(): sigue migrando en memoria para quien lee (cartera.php, factura.php, etc.)');
} finally {
    if ($respaldo === null) {
        @unlink($ruta);
    } else {
        file_put_contents($ruta, $respaldo, LOCK_EX);
    }
}

echo "\n" . ($fallos === 0 ? "TODO OK" : "$fallos prueba(s) fallaron") . "\n";
exit($fallos === 0 ? 0 : 1);
