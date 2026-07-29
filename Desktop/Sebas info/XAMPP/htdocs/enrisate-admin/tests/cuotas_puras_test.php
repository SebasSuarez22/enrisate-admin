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

/* --- cuota_pagado / cuota_pendiente / cuota_esta_pagada --- */

verificar(cuota_pagado(['monto' => 100, 'abonos' => []]) === 0,
    'cuota sin abonos: pagado = 0');

verificar(cuota_pagado(['monto' => 100, 'abonos' => [
    ['monto' => 30, 'fecha' => 'x'], ['monto' => 20, 'fecha' => 'y'],
]]) === 50, 'cuota con 2 abonos: pagado = suma de los abonos');

verificar(cuota_pendiente(['monto' => 100, 'abonos' => [
    ['monto' => 30, 'fecha' => 'x'],
]]) === 70, 'cuota con abono parcial: pendiente = monto - pagado');

verificar(cuota_pendiente(['monto' => 100, 'abonos' => [
    ['monto' => 150, 'fecha' => 'x'],
]]) === 0, 'pendiente nunca es negativo');

verificar(cuota_esta_pagada(['monto' => 100, 'abonos' => [
    ['monto' => 100, 'fecha' => 'x'],
]]) === true, 'cuota cubierta por completo: pagada');

verificar(cuota_esta_pagada(['monto' => 100, 'abonos' => [
    ['monto' => 60, 'fecha' => 'x'],
]]) === false, 'cuota con saldo pendiente: no pagada');

/* --- normalizar_cuotas_factura --- */

$vieja = [
    'plan'             => 'cuotas',
    'creada'           => '2024-01-01T00:00:00-05:00',
    'cuota1'           => 100000,
    'cuota2'           => 50000,
    'vence1'           => '2024-01-15',
    'vence2'           => '2024-02-15',
    'cuota1_pagada'    => true,
    'cuota1_pagada_el' => '2024-01-16T00:00:00-05:00',
    'cuota2_pagada'    => false,
];
$migrada = normalizar_cuotas_factura($vieja);

verificar(count($migrada['cuotas']) === 2,
    'migración: crea 2 cuotas desde el formato viejo');
verificar($migrada['cuotas'][0]['monto'] === 100000,
    'migración: monto de la cuota 1 correcto');
verificar($migrada['cuotas'][0]['vence'] === '2024-01-15',
    'migración: vencimiento de la cuota 1 correcto');
verificar(cuota_esta_pagada($migrada['cuotas'][0]) === true,
    'migración: cuota 1 pagada se traduce en un abono que la cubre');
verificar(cuota_esta_pagada($migrada['cuotas'][1]) === false,
    'migración: cuota 2 no pagada queda sin abonos');

$contado = ['plan' => 'contado'];
verificar(normalizar_cuotas_factura($contado) === $contado,
    'migración: una factura de contado no se toca');

$yaNueva = ['plan' => 'cuotas', 'cuotas' => [['monto' => 1, 'vence' => 'x', 'abonos' => []]]];
verificar(normalizar_cuotas_factura($yaNueva) === $yaNueva,
    'migración: una factura que ya tiene "cuotas" no se toca');

echo "\n" . ($fallos === 0 ? "TODO OK" : "$fallos prueba(s) fallaron") . "\n";
exit($fallos === 0 ? 0 : 1);
