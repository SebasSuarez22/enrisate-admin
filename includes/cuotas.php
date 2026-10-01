<?php
declare(strict_types=1);

/* =========================================================
   Cuotas, abonos y pagos de una factura · funciones puras
   =========================================================
   Sin base de datos ni sesión: reciben una factura (o una cuota) como
   arreglo y calculan cuánto se ha pagado, cuánto falta y si ya quedó
   saldada. Por eso tests/cuotas_puras_test.php las prueba sin MySQL.
   ========================================================= */

/** Suma de los abonos registrados contra una cuota. */
function cuota_pagado(array $cuota): int
{
    $total = 0;
    foreach ($cuota['abonos'] ?? [] as $a) {
        $total += (int) ($a['monto'] ?? 0);
    }
    return $total;
}

/** Lo que falta por pagar de una cuota (nunca negativo). */
function cuota_pendiente(array $cuota): int
{
    return max(0, (int) ($cuota['monto'] ?? 0) - cuota_pagado($cuota));
}

function cuota_esta_pagada(array $cuota): bool
{
    return cuota_pendiente($cuota) <= 0;
}

/**
 * true si la factura ya quedó completamente pagada: de contado (lo
 * pagado cubre el total) o a cuotas con todas las cuotas al día. Se usa
 * para saber cuándo el mensaje de WhatsApp debe agradecer la compra
 * completa en vez de solo confirmar un abono o un pedido pendiente.
 */
function factura_totalmente_pagada(array $f): bool
{
    if (($f['plan'] ?? 'contado') === 'cuotas') {
        if (!$f['cuotas']) {
            return false;
        }
        foreach ($f['cuotas'] as $c) {
            if (!cuota_esta_pagada($c)) {
                return false;
            }
        }
        return true;
    }
    return (int) ($f['pagado'] ?? 0) >= (int) ($f['total'] ?? 0);
}

/**
 * Garantiza que una factura a cuotas tenga el array `cuotas` (formato
 * nuevo). Si viene en el formato viejo (cuota1/cuota2 sueltos), lo
 * traduce en memoria; no reescribe el archivo. Facturas de contado, o que
 * ya tienen `cuotas`, se devuelven sin tocar.
 */
function normalizar_cuotas_factura(array $f): array
{
    if (($f['plan'] ?? '') !== 'cuotas' || isset($f['cuotas'])) {
        return $f;
    }

    $cuotas = [];
    foreach ([1, 2] as $i) {
        $monto = (int) ($f["cuota{$i}"] ?? 0);
        if ($monto <= 0) {
            continue;
        }
        $abonos = [];
        if (!empty($f["cuota{$i}_pagada"])) {
            $abonos[] = [
                'monto' => $monto,
                'fecha' => $f["cuota{$i}_pagada_el"] ?? ($f['creada'] ?? date('c')),
            ];
        }
        $cuotas[] = [
            'monto'  => $monto,
            'vence'  => $f["vence{$i}"] ?? date('Y-m-d'),
            'abonos' => $abonos,
        ];
    }
    $f['cuotas'] = $cuotas;

    return $f;
}

/** Total realmente cobrado de una factura a cuotas: saldo a favor aplicado + abono inicial + todos los abonos de todas las cuotas. */
function total_pagado_cuotas(array $f): int
{
    $total = (int) ($f['saldo_aplicado'] ?? 0) + (int) ($f['abono'] ?? 0);
    foreach ($f['cuotas'] ?? [] as $c) {
        $total += cuota_pagado($c);
    }
    return $total;
}
