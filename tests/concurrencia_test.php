<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/* Varias personas (o pedidos) guardando a la vez no deben perder datos.

   Lanza procesos PHP reales que escriben al mismo tiempo:
     - 2 registran abonos en la MISMA factura   (antes: se perdían abonos)
     - 2 crean facturas nuevas                  (antes: se perdían facturas o se repetía el número)
     - 1 lee sin parar                          (antes: podía ver facturas a medias)
   y luego comprueba que no falta nada. */

const ABONOS_POR_PROCESO   = 25;
const FACTURAS_POR_PROCESO = 12;
const SEGUNDOS_LECTOR      = 6;

$base = crear_factura([
    'cliente' => 'Factura de abonos', 'telefono' => '', 'nota' => '', 'lineas' => [],
    'subtotal' => 200000, 'descuento' => 0, 'total' => 200000, 'costo' => 100000, 'margen' => 100000,
    'plan' => 'cuotas', 'abono' => 0, 'saldo_aplicado' => 0,
    'cuotas' => [['monto' => 200000, 'vence' => '2030-01-01', 'abonos' => []]],
    'pagado' => 0, 'fecha_pago' => null,
]);

$inicio  = microtime(true) + 1.5;
$worker  = __DIR__ . '/_worker_concurrencia.php';
$lanzar  = function (string $rol, int $cant, string $numero = '') use ($worker, $inicio) {
    $cmd = [PHP_BINARY, $worker, $rol, (string) $cant, sprintf('%.6F', $inicio), $numero];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    return [$p, $pipes];
};

$procesos = [
    $lanzar('abonos', ABONOS_POR_PROCESO, $base),
    $lanzar('abonos', ABONOS_POR_PROCESO, $base),
    $lanzar('crear', FACTURAS_POR_PROCESO),
    $lanzar('crear', FACTURAS_POR_PROCESO),
    $lanzar('lector', SEGUNDOS_LECTOR),
];

$res = [];
foreach ($procesos as [$p, $pipes]) {
    $salida = stream_get_contents($pipes[1]);
    $err    = stream_get_contents($pipes[2]);
    proc_close($p);
    $j = json_decode(trim($salida), true);
    $res[] = is_array($j) ? $j : ['rol' => '?', 'errores' => 1, 'ok' => 0, 'incoherentes' => 0, 'lecturas' => 0, 'primer_error' => trim($err ?: $salida)];
    echo '     ' . json_encode($res[array_key_last($res)], JSON_UNESCAPED_UNICODE) . "\n";
}

$porRol = function (string $rol, string $campo) use ($res): int {
    return array_sum(array_map(fn($x) => $x['rol'] === $rol ? (int) $x[$campo] : 0, $res));
};

$facturas = leer_facturas_raw();
$numeros  = array_column($facturas, 'numero');
$f        = null;
foreach ($facturas as $x) { if ($x['numero'] === $base) { $f = $x; } }

$esperadosAbonos = 2 * ABONOS_POR_PROCESO;
$esperadosNuevas = 2 * FACTURAS_POR_PROCESO;

verificar($porRol('abonos', 'errores') === 0 && $porRol('crear', 'errores') === 0,
    'ningún proceso terminó con error (sin choques de número ni bloqueos vencidos)');
verificar($f !== null && count($f['cuotas'][0]['abonos']) === $esperadosAbonos,
    "los $esperadosAbonos abonos hechos a la vez en la misma factura quedaron todos guardados (hay "
    . ($f ? count($f['cuotas'][0]['abonos']) : 0) . ')');
verificar($f !== null && $f['pagado'] === $esperadosAbonos * 1000 && $f['pagado'] === total_pagado_cuotas($f),
    'el total pagado de esa factura coincide con la suma de sus abonos');
verificar(count($numeros) === 1 + $esperadosNuevas,
    "las $esperadosNuevas facturas creadas a la vez quedaron todas guardadas, más la original (hay " . count($numeros) . ')');
verificar(count($numeros) === count(array_unique($numeros)),
    'no se repitió ningún número de factura');
verificar($porRol('lector', 'lecturas') > 0 && $porRol('lector', 'incoherentes') === 0,
    'leer mientras otros guardan nunca muestra una factura a medias (' . $porRol('lector', 'lecturas') . ' lecturas)');

terminar_pruebas();
