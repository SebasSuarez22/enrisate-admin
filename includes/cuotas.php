<?php
declare(strict_types=1);

/* =========================================================
   Cuotas, abonos y pagos de una factura · funciones puras
   =========================================================
   Sin base de datos ni sesión: reciben una factura (o una cuota) como
   arreglo y calculan cuánto se ha pagado, cuánto falta y si ya quedó
   saldada. Por eso tests/cuotas_puras_test.php las prueba sin MySQL.
   ========================================================= */

require_once __DIR__ . '/formato.php';

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

/**
 * Cambia la fecha de vencimiento de una cuota y, si $correr, corre las siguientes los mismos
 * días (así se mantiene el tiempo entre una y otra). Es la regla que usa Cartera al reprogramar,
 * y la misma que repite la ventana del navegador para mostrar la vista previa:
 *  - una cuota pagada no se mueve (ni se puede reprogramar), y las anteriores tampoco;
 *  - la fecha nueva va desde mañana y después de la cuota anterior;
 *  - sin $correr, no puede pasar de la fecha de la siguiente cuota (se perdería el orden);
 *  - no vale la misma fecha que ya tiene.
 * No toca ninguna base: devuelve ['ok' => true, 'cuotas' => lo nuevo, 'cambios' => [...], 'dias' => n]
 * o ['ok' => false, 'error' => texto]. $hoy ("AAAA-MM-DD") solo se pasa en las pruebas.
 */
function reprogramar_cuotas(array $cuotas, int $idx, string $nuevaFecha, bool $correr, ?string $hoy = null): array
{
    $fallo = fn(string $texto): array => ['ok' => false, 'error' => $texto];
    $aFecha = fn(string $iso): ?DateTimeImmutable => preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)
        ? (DateTimeImmutable::createFromFormat('!Y-m-d', $iso) ?: null) : null;

    if (!isset($cuotas[$idx])) {
        return $fallo('Esa cuota no existe.');
    }
    if (cuota_esta_pagada($cuotas[$idx])) {
        return $fallo('Esa cuota ya está pagada: su fecha no se cambia.');
    }
    $nueva = $aFecha($nuevaFecha);
    if (!$nueva || $nueva->format('Y-m-d') !== $nuevaFecha) {
        return $fallo('Escoge una fecha.');
    }
    $actual = $aFecha(substr((string) $cuotas[$idx]['vence'], 0, 10));
    if (!$actual) {
        return $fallo('Esa cuota no tiene una fecha válida.');
    }

    $minimo = ($aFecha($hoy ?? date('Y-m-d')) ?: new DateTimeImmutable('today'))->modify('+1 day');
    if ($idx > 0 && ($anterior = $aFecha(substr((string) $cuotas[$idx - 1]['vence'], 0, 10)))) {
        $despuesDeLaAnterior = $anterior->modify('+1 day');
        if ($despuesDeLaAnterior > $minimo) {
            $minimo = $despuesDeLaAnterior;
        }
    }
    if ($nueva < $minimo) {
        return $fallo('La fecha debe ser desde el ' . fecha_larga($minimo->format('Y-m-d')) . ' y después de la cuota anterior.');
    }
    if (!$correr && isset($cuotas[$idx + 1]) && ($siguiente = $aFecha(substr((string) $cuotas[$idx + 1]['vence'], 0, 10))) && $nueva > $siguiente) {
        return $fallo('Pasa de la fecha de la cuota siguiente (' . fecha_larga($siguiente->format('Y-m-d')) . '). Corre también las siguientes o elige una fecha anterior.');
    }
    $dias = (int) $actual->diff($nueva)->format('%r%a');
    if ($dias === 0) {
        return $fallo('Es la misma fecha que ya tiene. Elige otra.');
    }

    $cambios = [];
    foreach ($cuotas as $i => $c) {
        if ($i < $idx || cuota_esta_pagada($c) || (!$correr && $i > $idx)) {
            continue;
        }
        $antes = substr((string) $c['vence'], 0, 10);
        $despues = $i === $idx ? $nuevaFecha : (($d = $aFecha($antes)) ? $d->modify(($dias > 0 ? '+' : '') . $dias . ' days')->format('Y-m-d') : $antes);
        if ($despues !== $antes) {
            $cuotas[$i]['vence'] = $despues;
            $cambios[] = ['orden' => $i, 'antes' => $antes, 'despues' => $despues];
        }
    }

    return ['ok' => true, 'cuotas' => $cuotas, 'cambios' => $cambios, 'dias' => $dias];
}
