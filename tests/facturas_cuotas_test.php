<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

{
    $numero = crear_factura([
        'cliente' => '__TEST__', 'telefono' => '', 'nota' => '', 'lineas' => [],
        'subtotal' => 150000, 'descuento' => 0, 'total' => 150000, 'costo' => 0, 'margen' => 150000,
        'plan' => 'cuotas', 'abono' => 0, 'saldo_aplicado' => 0,
        'cuotas' => [
            ['monto' => 100000, 'vence' => '2026-01-01', 'abonos' => []],
            ['monto' => 50000,  'vence' => '2026-02-01', 'abonos' => []],
        ],
        'pagado' => 0, 'fecha_pago' => null,
    ]);

    verificar(registrar_abono($numero, 0, 40000) === true,
        'registrar_abono: abono parcial válido se acepta');

    $f = factura_por_numero($numero);
    verificar(cuota_pendiente($f['cuotas'][0]) === 60000,
        'registrar_abono: el pendiente baja con el abono');
    verificar(cuota_esta_pagada($f['cuotas'][0]) === false,
        'registrar_abono: cuota con abono parcial sigue pendiente');
    verificar($f['pagado'] === 40000,
        'registrar_abono: el campo "pagado" de la factura se actualiza');

    verificar(registrar_abono($numero, 0, 999999) === false,
        'registrar_abono: rechaza un monto mayor al pendiente (no lo recorta)');
    verificar(registrar_abono($numero, 5, 1000) === false,
        'registrar_abono: rechaza un índice de cuota que no existe');
    verificar(registrar_abono($numero, 0, 0) === false,
        'registrar_abono: rechaza un monto de cero');

    verificar(registrar_abono($numero, 0, 60000) === true,
        'registrar_abono: completa el pendiente de la cuota');
    $f = factura_por_numero($numero);
    verificar(cuota_esta_pagada($f['cuotas'][0]) === true,
        'registrar_abono: la cuota queda pagada al completar el pendiente');

    verificar(anular_factura($numero) === true, 'setup: se anula la factura de prueba');
    verificar(registrar_abono($numero, 1, 1000) === false,
        'registrar_abono: rechaza abonos sobre facturas anuladas');

    $metricas = metricas_panel();
    verificar(is_int($metricas['porCobrar']),
        'metricas_panel: sigue devolviendo porCobrar como entero, sin fatal error');
}

terminar_pruebas();
