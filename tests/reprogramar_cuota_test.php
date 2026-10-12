<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/facturas.php';
require_once __DIR__ . '/../includes/mensajes.php';

/* Reprogramar una cuota en Cartera: cambiar su fecha de vencimiento y correr las siguientes.
   Primero la regla sola (sin base de datos) y luego guardada de verdad. */

$hoy = '2026-10-12';
function cuotas_ejemplo(): array
{
    return [
        ['monto' => 50000, 'vence' => '2026-09-10', 'abonos' => [['monto' => 50000, 'fecha' => '2026-09-10T10:00:00-05:00']]], // pagada
        ['monto' => 50000, 'vence' => '2026-10-10', 'abonos' => []],                                                       // vencida
        ['monto' => 50000, 'vence' => '2026-11-10', 'abonos' => []],
        ['monto' => 50000, 'vence' => '2026-12-10', 'abonos' => []],
    ];
}
function fechas(array $r): array
{
    return array_column($r['cuotas'], 'vence');
}

/* --- La regla --- */
$r = reprogramar_cuotas(cuotas_ejemplo(), 1, '2026-10-24', true, $hoy);
verificar($r['ok'] === true && $r['dias'] === 14, 'mover la segunda 14 días es válido');
verificar(fechas($r) === ['2026-09-10', '2026-10-24', '2026-11-24', '2026-12-24'], 'las siguientes se corren los mismos 14 días');
verificar(count($r['cambios']) === 3 && $r['cambios'][0]['orden'] === 1 && $r['cambios'][0]['antes'] === '2026-10-10', 'queda la lista de qué cambió, con la fecha de antes');
verificar($r['cuotas'][0]['abonos'] === cuotas_ejemplo()[0]['abonos'] && $r['cuotas'][2]['monto'] === 50000, 'no se toca ni un abono ni un monto');

$r = reprogramar_cuotas(cuotas_ejemplo(), 1, '2026-10-24', false, $hoy);
verificar($r['ok'] === true && fechas($r) === ['2026-09-10', '2026-10-24', '2026-11-10', '2026-12-10'] && count($r['cambios']) === 1,
    'solo esta: las demás quedan en su fecha');

$r = reprogramar_cuotas(cuotas_ejemplo(), 2, '2026-12-05', true, $hoy);
verificar($r['ok'] === true && fechas($r) === ['2026-09-10', '2026-10-10', '2026-12-05', '2027-01-04'], 'el corrimiento cruza el fin de año y respeta los meses');

$r = reprogramar_cuotas(cuotas_ejemplo(), 1, '2026-10-13', true, $hoy);
verificar($r['ok'] === true && $r['dias'] === 3, 'mañana es la fecha más pronto que se puede');
$r = reprogramar_cuotas(cuotas_ejemplo(), 1, '2026-10-12', true, $hoy);
verificar($r['ok'] === false && str_contains($r['error'], '13 de octubre de 2026'), 'hoy no vale: el aviso dice desde cuándo');
$r = reprogramar_cuotas(cuotas_ejemplo(), 1, '2026-10-01', true, $hoy);
verificar($r['ok'] === false, 'una fecha pasada no vale');

$c = cuotas_ejemplo();
$c[1]['vence'] = '2026-12-20'; $c[2]['vence'] = '2027-01-20'; $c[3]['vence'] = '2027-02-20';
$r = reprogramar_cuotas($c, 2, '2026-12-20', true, $hoy);
verificar($r['ok'] === false, 'no puede quedar en la misma fecha de la cuota anterior (20 dic)');
$r = reprogramar_cuotas($c, 2, '2026-12-19', true, $hoy);
verificar($r['ok'] === false, 'la cuota no puede pasar antes de la anterior');
$r = reprogramar_cuotas($c, 2, '2026-12-21', true, $hoy);
verificar($r['ok'] === true && $r['dias'] === -30, 'justo después de la cuota anterior sí vale, aunque sea antes que la fecha de ahora');

$r = reprogramar_cuotas(cuotas_ejemplo(), 1, '2026-11-11', false, $hoy);
verificar($r['ok'] === false && str_contains($r['error'], 'cuota siguiente'), 'solo esta: no puede pasar de la fecha de la siguiente');
$r = reprogramar_cuotas(cuotas_ejemplo(), 1, '2026-11-10', false, $hoy);
verificar($r['ok'] === true, 'solo esta: justo la fecha de la siguiente sí vale');
$r = reprogramar_cuotas(cuotas_ejemplo(), 1, '2026-11-11', true, $hoy);
verificar($r['ok'] === true && fechas($r) === ['2026-09-10', '2026-11-11', '2026-12-12', '2027-01-11'], 'corriendo las siguientes sí puede pasar de la fecha de la siguiente');

$r = reprogramar_cuotas(cuotas_ejemplo(), 2, '2026-11-10', true, $hoy);
verificar($r['ok'] === false && str_contains($r['error'], 'misma fecha'), 'la misma fecha que ya tiene no es un cambio');
$r = reprogramar_cuotas(cuotas_ejemplo(), 0, '2026-10-20', true, $hoy);
verificar($r['ok'] === false && str_contains($r['error'], 'pagada'), 'una cuota pagada no se reprograma');
$r = reprogramar_cuotas(cuotas_ejemplo(), 9, '2026-10-20', true, $hoy);
verificar($r['ok'] === false, 'una cuota que no existe tampoco');
foreach (['', 'mañana', '2026-02-31', '24/10/2026', '2026-10-24 10:00'] as $malo) {
    $r = reprogramar_cuotas(cuotas_ejemplo(), 1, $malo, true, $hoy);
    verificar($r['ok'] === false, 'fecha inválida rechazada: "' . $malo . '"');
}

/* Una cuota con abono a medias sí se puede reprogramar y conserva su abono. */
$c = cuotas_ejemplo();
$c[1]['abonos'] = [['monto' => 20000, 'fecha' => '2026-10-01T09:00:00-05:00', 'tipo' => 'abono', 'motivo' => '']];
$r = reprogramar_cuotas($c, 1, '2026-10-24', true, $hoy);
verificar($r['ok'] === true && $r['cuotas'][1]['abonos'] === $c[1]['abonos'], 'una cuota con abono a medias se reprograma y conserva su abono');

/* Una cuota siguiente ya pagada no se mueve. */
$c = cuotas_ejemplo();
$c[3]['abonos'] = [['monto' => 50000, 'fecha' => '2026-10-01T09:00:00-05:00']];
$r = reprogramar_cuotas($c, 1, '2026-10-24', true, $hoy);
verificar($r['ok'] === true && $r['cuotas'][3]['vence'] === '2026-12-10' && count($r['cambios']) === 2, 'una siguiente que ya está pagada no se corre');

/* --- Guardado de verdad --- */
db()->exec('DELETE FROM facturas');
$fechaPago = date('Y-m-d', strtotime('+5 days'));
$numero = crear_factura([
    'cliente' => 'Laura Martínez', 'telefono' => '3001234567', 'nota' => '',
    'lineas' => [['sku' => 'X-1', 'nombre' => 'Producto', 'marca' => 'M', 'precio' => 200000, 'costo' => 100000, 'cant' => 1]],
    'subtotal' => 200000, 'descuento' => 0, 'total' => 200000, 'costo' => 100000, 'margen' => 100000,
    'plan' => 'cuotas', 'abono' => 0, 'saldo_aplicado' => 0, 'pagado' => 50000, 'fecha_pago' => null,
    'anulada' => false,
    'cuotas' => [
        ['monto' => 50000, 'vence' => date('Y-m-d', strtotime('-30 days')), 'abonos' => [['monto' => 50000, 'fecha' => date('c'), 'tipo' => 'abono', 'motivo' => '']]],
        ['monto' => 50000, 'vence' => date('Y-m-d', strtotime('-2 days')), 'abonos' => []],
        ['monto' => 50000, 'vence' => date('Y-m-d', strtotime('+28 days')), 'abonos' => []],
        ['monto' => 50000, 'vence' => date('Y-m-d', strtotime('+58 days')), 'abonos' => []],
    ],
]);
$antes = factura_por_numero($numero);
$nueva = date('Y-m-d', strtotime('+12 days'));
$res = reprogramar_cuota($numero, 1, $nueva, true, 'Gina');
verificar($res['ok'] === true && $res['movidas'] === 2, 'se reprograma y se corren 2 cuotas más');

$despues = factura_por_numero($numero);
verificar($despues['cuotas'][1]['vence'] === $nueva, 'la fecha nueva quedó guardada');
verificar($despues['cuotas'][2]['vence'] === date('Y-m-d', strtotime($antes['cuotas'][2]['vence'] . ' +14 days')), 'las siguientes quedaron corridas 14 días');
verificar($despues['cuotas'][0]['vence'] === $antes['cuotas'][0]['vence'], 'la pagada no cambió');
verificar($despues['pagado'] === $antes['pagado'] && cuota_pagado($despues['cuotas'][0]) === 50000 && $despues['total'] === 200000, 'lo pagado y el total siguen igual');
verificar(count($despues['cuotas']) === 4, 'siguen siendo cuatro cuotas');

$h = reprogramaciones_por_cuota([$numero]);
verificar(isset($h[$numero][1]) && $h[$numero][1][0]['fecha_anterior'] === $antes['cuotas'][1]['vence'] && $h[$numero][1][0]['fecha_nueva'] === $nueva,
    'queda anotada la fecha que tenía antes');
verificar(count($h[$numero]) === 3, 'el historial anota las 3 cuotas que se movieron');
$u = db()->query("SELECT usuario, alcance FROM factura_reprogramaciones WHERE factura_numero = " . db()->quote($numero) . " LIMIT 1")->fetch();
verificar($u['usuario'] === 'Gina' && $u['alcance'] === 'siguientes', 'queda anotado quién y con qué alcance');

$res2 = reprogramar_cuota($numero, 1, $nueva, true, 'Gina');
verificar($res2['ok'] === false, 'repetir la misma fecha no hace nada');
$res3 = reprogramar_cuota($numero, 3, date('Y-m-d', strtotime('+70 days')), false, 'Gina');
verificar($res3['ok'] === true && $res3['movidas'] === 0, 'solo la última cuota: no hay siguientes');
verificar(reprogramar_cuota('NO-EXISTE', 0, $nueva, true)['ok'] === false, 'una factura que no existe se rechaza');

$f = factura_por_numero($numero);
anular_factura($numero, 'prueba');
verificar(reprogramar_cuota($numero, 2, date('Y-m-d', strtotime('+90 days')), true)['ok'] === false, 'una factura anulada no se reprograma');
reactivar_factura($numero);

/* Sin la tabla del historial, la fecha se cambia igual */
db()->exec('DROP TABLE factura_reprogramaciones');
$res4 = reprogramar_cuota($numero, 2, date('Y-m-d', strtotime('+80 days')), true, 'Gina');
verificar($res4['ok'] === true, 'si el historial no existe todavía, la fecha se cambia igual');
verificar(reprogramaciones_por_cuota([$numero]) === [], 'sin tabla, no hay historial y nada se cae');
preparar_base_de_pruebas();

/* --- El texto de WhatsApp --- */
$m = mensaje_reprogramacion('Laura Martínez', 'segunda cuota', '2026-10-24', [['rotulo' => 'Tercera cuota', 'vence' => '2026-11-24'], ['rotulo' => 'Cuarta cuota', 'vence' => '2026-12-24']]);
verificar(str_contains($m, 'Hola Laura 💛') && str_contains($m, 'segunda cuota') && str_contains($m, '24 de octubre'), 'WhatsApp: saluda con el primer nombre y dice la fecha nueva');
verificar(str_contains($m, 'la tercera el 24 de noviembre y la cuarta el 24 de diciembre'), 'WhatsApp: lista las siguientes');
verificar(!preg_match('/\$\d/', $m), 'WhatsApp: no lleva montos');
$m = mensaje_reprogramacion('Ana', 'segunda cuota', '2026-10-24');
verificar(!str_contains($m, 'siguientes') && str_ends_with($m, '¡Gracias!'), 'WhatsApp: solo esa cuota, sin lista');
$m = mensaje_reprogramacion('', 'primera cuota', '2030-01-05');
verificar(str_starts_with($m, 'Hola 💛') && str_contains($m, '5 de enero de 2030'), 'WhatsApp: sin nombre no queda un espacio de más, y un año distinto sale completo');

db()->exec('DELETE FROM facturas');
terminar_pruebas();
