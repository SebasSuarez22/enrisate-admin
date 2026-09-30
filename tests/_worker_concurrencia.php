<?php
declare(strict_types=1);

/* Proceso auxiliar de tests/concurrencia_test.php (el guion bajo del nombre
   hace que correr_todas.php no lo tome como una prueba). No se corre solo.

       php _worker_concurrencia.php <rol> <cantidad> <inicio_unix_microtime> [numero_factura]

   Roles:
     abonos   registra <cantidad> abonos de 1.000 en la cuota 0 de <numero_factura>
     crear    crea <cantidad> facturas de contado
     lector   lee todas las facturas durante <cantidad> segundos y cuenta las
              lecturas incoherentes (factura a cuotas cuyo "pagado" no cuadra con sus abonos)

   Imprime una línea JSON con lo que pasó. */

if (PHP_SAPI !== 'cli') { exit; }
require_once __DIR__ . '/../includes/facturas.php';
if (!str_ends_with(DB_NOMBRE, '_test')) { fwrite(STDERR, "solo en bases _test\n"); exit(2); }

[, $rol, $cantidad, $inicio] = $argv;
$numeroFactura = $argv[4] ?? '';
$cantidad = (int) $cantidad;

// Todos arrancan a la vez: se espera al instante acordado.
while (microtime(true) < (float) $inicio) { usleep(200); }

$r = ['rol' => $rol, 'ok' => 0, 'rechazadas' => 0, 'errores' => 0, 'incoherentes' => 0, 'lecturas' => 0, 'primer_error' => ''];

try {
    if ($rol === 'abonos') {
        for ($i = 0; $i < $cantidad; $i++) {
            try {
                registrar_abono($numeroFactura, 0, 1000) ? $r['ok']++ : $r['rechazadas']++;
            } catch (Throwable $e) {
                $r['errores']++;
                $r['primer_error'] = $r['primer_error'] ?: substr($e->getMessage(), 0, 120);
            }
        }
    } elseif ($rol === 'crear') {
        for ($i = 0; $i < $cantidad; $i++) {
            try {
                crear_factura([
                    'cliente' => 'Concurrente ' . getmypid(), 'telefono' => '', 'nota' => '', 'lineas' => [],
                    'subtotal' => 1000, 'descuento' => 0, 'total' => 1000, 'costo' => 500, 'margen' => 500,
                    'plan' => 'contado', 'abono' => 0, 'saldo_aplicado' => 0, 'cuotas' => [],
                    'pagado' => 1000, 'fecha_pago' => '2026-09-01',
                ]);
                $r['ok']++;
            } catch (Throwable $e) {
                $r['errores']++;
                $r['primer_error'] = $r['primer_error'] ?: substr($e->getMessage(), 0, 120);
            }
        }
    } elseif ($rol === 'lector') {
        $fin = microtime(true) + $cantidad;
        while (microtime(true) < $fin) {
            foreach (leer_facturas_raw() as $f) {
                if (($f['plan'] ?? '') === 'cuotas' && $f['pagado'] !== total_pagado_cuotas($f)) {
                    $r['incoherentes']++;
                }
            }
            $r['lecturas']++;
        }
    }
} catch (Throwable $e) {
    $r['errores']++;
    $r['primer_error'] = substr($e->getMessage(), 0, 120);
}

echo json_encode($r, JSON_UNESCAPED_UNICODE), "\n";
