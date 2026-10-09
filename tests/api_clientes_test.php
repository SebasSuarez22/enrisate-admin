<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/api_clientes.php';

/* API de consumo y frecuencia para el bot: lo de UNA clienta (api_resumen_clienta)
   y lo de todas en conjunto (api_consumo). Todo con facturas de mentira en
   memoria y la fecha de "hoy" fija: no toca las tablas. */

function fa(string $numero, string $fecha, string $cliente, string $tel, array $lineas, int $total, array $extra = []): array
{
    return array_merge([
        'numero' => $numero, 'creada' => $fecha . 'T10:00:00-05:00', 'cliente' => $cliente, 'telefono' => $tel,
        'lineas' => array_map(fn($l) => ['sku' => $l[0], 'nombre' => $l[1], 'marca' => 'M', 'precio' => 1000, 'costo' => 600, 'cant' => $l[2]], $lineas),
        'total' => $total, 'costo' => 99999, 'margen' => 88888, 'plan' => 'contado', 'cuotas' => [], 'anulada' => false,
    ], $extra);
}

$HOY = '2026-10-09';
$facturas = [
    // Ana: 3 compras, cada ~30 días (1 jul, 31 jul, 30 ago), la última hace 40 días
    fa('A1', '2026-07-01', 'Ana Pérez', '300 111 2233', [['SH-1', 'Shampoo Rizos', 2], ['AC-1', 'Acondicionador', 1]], 90000),
    fa('A2', '2026-07-31', 'Ana Pérez', '+57 3001112233', [['SH-1', 'Shampoo Rizos', 1]], 40000),
    fa('A3', '2026-08-30', 'Ana Pérez', '3001112233', [['SH-1', 'Shampoo Rizos', 1], ['CR-1', 'Crema peinar', 2]], 85000,
        ['plan' => 'cuotas', 'cuotas' => [
            ['monto' => 40000, 'vence' => '2026-09-30', 'abonos' => [['monto' => 10000, 'fecha' => '2026-09-01']]],
            ['monto' => 45000, 'vence' => '2026-10-30', 'abonos' => []],
        ]]),
    // Anulada de Ana: no cuenta para nada
    fa('A4', '2026-10-01', 'Ana Pérez', '3001112233', [['CR-1', 'Crema peinar', 9]], 500000, ['anulada' => true]),
    // Beto: una sola compra
    fa('B1', '2026-10-05', 'Beto Ruiz', '3109998877', [['AC-1', 'Acondicionador', 1]], 41000),
    // Otra "Ana Pérez" con OTRO teléfono: no debe mezclarse con la primera
    fa('X1', '2026-09-15', 'Ana Pérez', '3205556677', [['SH-2', 'Shampoo Anti', 1]], 50000),
    // Factura sin nombre ni teléfono: tampoco debe colarse en nadie
    fa('S1', '2026-09-20', '', '', [['SH-2', 'Shampoo Anti', 3]], 150000),
    // Cira: dos compras el mismo día (sin frecuencia)
    fa('C1', '2026-09-10', 'Cira Gil', '3154443322', [['SH-2', 'Shampoo Anti', 1]], 50000),
    fa('C2', '2026-09-10', 'Cira Gil', '3154443322', [['CR-1', 'Crema peinar', 1]], 45000),
    // Dani: compra cada ~10 días, última hace 3 días
    fa('D1', '2026-09-16', 'Dani Soto', '3001230000', [['SH-1', 'Shampoo Rizos', 1]], 40000),
    fa('D2', '2026-09-26', 'Dani Soto', '3001230000', [['SH-1', 'Shampoo Rizos', 1]], 40000),
    fa('D3', '2026-10-06', 'Dani Soto', '3001230000', [['SH-1', 'Shampoo Rizos', 1]], 40000),
];
$clientes = [
    ['id' => 'c1', 'nombre' => 'Ana Pérez', 'telefono' => '3001112233', 'notas' => 'NOTA INTERNA: no fiarle más', 'saldo_favor' => 5000, 'creado' => ''],
    ['id' => 'c2', 'nombre' => 'Nueva Sin Compras', 'telefono' => '3007778899', 'notas' => '', 'saldo_favor' => 0, 'creado' => ''],
];
$pedidos = [
    ['numero' => 'P1', 'estado' => 'pendiente', 'telefono' => '3001112233', 'creado' => '2026-10-08T09:00:00-05:00', 'total' => 40000,
     'lineas' => [['sku' => 'SH-1', 'nombre' => 'Shampoo Rizos', 'cant' => 1]]],
    ['numero' => 'P2', 'estado' => 'confirmado', 'telefono' => '3001112233', 'creado' => '2026-10-01T09:00:00-05:00', 'total' => 1, 'lineas' => []],
    ['numero' => 'P3', 'estado' => 'pendiente', 'telefono' => '3109998877', 'creado' => '2026-10-08T09:00:00-05:00', 'total' => 2, 'lineas' => []],
];
$productos = [
    ['sku' => 'SH-1', 'marca' => 'M', 'nombre' => 'Shampoo Rizos', 'categoria' => 'Shampoo', 'precio' => 40000, 'costo' => 20000, 'stock' => 0],
    ['sku' => 'SH-2', 'marca' => 'M', 'nombre' => 'Shampoo Anti', 'categoria' => 'Shampoo', 'precio' => 50000, 'costo' => 25000, 'stock' => 0],
    ['sku' => 'AC-1', 'marca' => 'M', 'nombre' => 'Acondicionador', 'categoria' => 'Acondicionador', 'precio' => 41000, 'costo' => 20000, 'stock' => 0],
    ['sku' => 'CR-1', 'marca' => 'M', 'nombre' => 'Crema peinar', 'categoria' => '', 'precio' => 45000, 'costo' => 20000, 'stock' => 0],
];

/* --- Teléfono --- */
verificar(api_telefono('whatsapp:+573001112233') === '3001112233', 'el prefijo whatsapp:, el + y el 57 se quitan');
verificar(api_telefono(' 300 111 2233 ') === '3001112233', 'los espacios se quitan');
verificar(api_telefono('') === '' && api_telefono('abc') === '' && api_telefono('123') === '', 'vacío, letras o muy corto: no sirve');
verificar(api_telefono(str_repeat('9', 20)) === '', 'demasiado largo: no sirve');

/* --- Una clienta: Ana --- */
$r = api_resumen_clienta('3001112233', $facturas, $clientes, $pedidos, 'si', $HOY);
verificar($r['ok'] === true && $r['conocida'] === true, 'Ana es conocida');
verificar($r['nombre'] === 'Ana Pérez' && $r['primer_nombre'] === 'Ana', 'nombre y primer nombre');
verificar($r['compras']['total'] === 3, 'cuenta 3 compras: ignora la anulada y la otra Ana (otro teléfono)');
verificar($r['compras']['unidades'] === 7, 'suma sus unidades (3 + 1 + 3), sin la anulada');
verificar($r['compras']['primera_compra'] === '2026-07-01' && $r['compras']['ultima_compra'] === '2026-08-30', 'primera y última compra');
verificar($r['compras']['dias_desde_ultima'] === 40, 'días desde la última compra');
verificar($r['compras']['frecuencia_dias'] === 30, 'compra cada ~30 días (59 días entre 3 compras → 29,5 ≈ 30)');
verificar($r['compras']['le_toca_reponer'] === true, 'han pasado 40 días y repone cada 30: ya le toca');
verificar($r['favorito'] === 'Shampoo Rizos', 'su favorito es el que más unidades suma');
verificar($r['productos'][0]['sku'] === 'SH-1' && $r['productos'][0]['unidades'] === 4 && $r['productos'][0]['veces'] === 3
    && $r['productos'][0]['ultima_vez'] === '2026-08-30', 'producto más comprado: unidades, veces y última vez');
verificar(count($r['productos']) === 3, 'tres productos distintos');
verificar(array_column($r['ultimas_compras'], 'numero') === ['A3', 'A2', 'A1'], 'últimas compras de la más nueva a la más vieja');
verificar($r['ultimas_compras'][0]['total'] === 85000 && $r['ultimas_compras'][0]['fecha'] === '2026-08-30', 'total y fecha de una compra');
verificar($r['cartera']['por_pagar'] === 75000 && $r['cartera']['cuotas_pendientes'] === 2, 'debe 30.000 + 45.000 en 2 cuotas');
verificar($r['cartera']['vencido'] === 30000, 'la cuota del 30 de septiembre ya venció');
verificar($r['cartera']['proxima_cuota']['vence'] === '2026-09-30' && $r['cartera']['proxima_cuota']['monto'] === 30000, 'la próxima cuota es la más antigua sin pagar');
verificar($r['saldo_a_favor'] === 5000, 'saldo a favor');
verificar(count($r['pedidos_pendientes']) === 1 && $r['pedidos_pendientes'][0]['numero'] === 'P1', 'solo su pedido pendiente (no el confirmado ni el de otra)');
verificar($r['acepta_promociones'] === 'si', 'permiso de promociones');

$json = json_encode($r);
verificar(!str_contains($json, '99999') && !str_contains($json, '88888'), 'nunca sale el costo ni el margen');
verificar(!str_contains($json, 'NOTA INTERNA') && !str_contains($json, 'notas'), 'nunca salen las notas internas');
verificar(!str_contains($json, '3205556677') && !str_contains($json, '3109998877'), 'ningún teléfono de otra persona');

/* --- Beto: una sola compra --- */
$b = api_resumen_clienta('3109998877', $facturas, $clientes, $pedidos, '', $HOY);
verificar($b['conocida'] === true && $b['nombre'] === 'Beto Ruiz', 'Beto no está en Clientes pero sí en facturas: el nombre sale de su factura');
verificar($b['compras']['total'] === 1 && $b['compras']['frecuencia_dias'] === null && $b['compras']['le_toca_reponer'] === null,
    'una sola compra: sin frecuencia ni "le toca"');
verificar($b['compras']['dias_desde_ultima'] === 4, 'Beto compró hace 4 días');
verificar($b['acepta_promociones'] === 'sin_respuesta', 'sin permiso guardado: sin_respuesta');
verificar($b['saldo_a_favor'] === 0 && $b['cartera']['por_pagar'] === 0 && $b['cartera']['proxima_cuota'] === null, 'sin saldo ni deuda');

/* --- Cira: dos compras el mismo día --- */
$c = api_resumen_clienta('3154443322', $facturas, $clientes, $pedidos, 'no', $HOY);
verificar($c['compras']['total'] === 2 && $c['compras']['frecuencia_dias'] === null && $c['compras']['le_toca_reponer'] === null,
    'dos compras el mismo día: no hay días entre compras, así que sin frecuencia');
verificar($c['acepta_promociones'] === 'no', 'dijo que no a las promociones');

/* --- Dani: compra seguido y aún no le toca --- */
$d = api_resumen_clienta('3001230000', $facturas, $clientes, $pedidos, '', $HOY);
verificar($d['compras']['frecuencia_dias'] === 10 && $d['compras']['dias_desde_ultima'] === 3 && $d['compras']['le_toca_reponer'] === false,
    'Dani repone cada 10 días y compró hace 3: todavía no le toca');

/* --- Una clienta guardada que nunca ha comprado --- */
$n = api_resumen_clienta('3007778899', $facturas, $clientes, $pedidos, '', $HOY);
verificar($n['conocida'] === true && $n['nombre'] === 'Nueva Sin Compras' && $n['compras'] === null && $n['favorito'] === null,
    'guardada sin compras: conocida, sin estadísticas');
verificar($n['productos'] === [] && $n['ultimas_compras'] === [], 'sin compras: listas vacías');

/* --- Un número que no conocemos --- */
$x = api_resumen_clienta('3999999999', $facturas, $clientes, $pedidos, '', $HOY);
verificar($x['ok'] === true && $x['conocida'] === false && $x['nombre'] === null && $x['compras'] === null,
    'número desconocido: ok pero conocida = false');

/* --- Una factura sin teléfono no se le pega a nadie por nombre ni por vacío --- */
verificar(api_facturas_de_telefono($facturas, '') === [], 'teléfono vacío: no trae las facturas sin teléfono');
verificar(array_column(api_facturas_de_telefono($facturas, '3001112233'), 'numero') === ['A1', 'A2', 'A3'], 'por teléfono: los tres formatos distintos de Ana');

/* --- Consumo general --- */
$g = api_consumo($facturas, $productos, 30, 10, $HOY);
verificar($g['ok'] === true && $g['periodo'] === ['dias' => 30, 'desde' => '2026-09-10', 'hasta' => '2026-10-09'], 'período de 30 días terminando hoy');
verificar($g['compras']['facturas'] === 8, 'facturas del período: 8 (sin la anulada ni las anteriores al 10 de septiembre)');
verificar($g['compras']['clientas_distintas'] === 5, 'clientas distintas del período: Ana(X1 es otra), Beto, Cira, Dani y la sin datos');

$top = $g['productos_mas_comprados'];
verificar($top[0]['sku'] === 'SH-2' && $top[0]['unidades'] === 5, 'el más comprado del período: Shampoo Anti (1 + 3 + 1)');
verificar($top[0]['compradoras'] === 3 && $top[0]['categoria'] === 'Shampoo', 'cuántas clientas lo compraron y su categoría');
verificar(!isset($top[0]['_quienes']), 'no se escapa el dato interno');
verificar($g['categorias_mas_compradas'][0]['categoria'] === 'Shampoo', 'la categoría más comprada');
verificar(in_array('Crema', array_column($g['categorias_mas_compradas'], 'categoria'), true), 'la categoría vacía se deduce del nombre ("Crema")');

$topUno = api_consumo($facturas, $productos, 30, 1, $HOY);
verificar(count($topUno['productos_mas_comprados']) === 1 && count($topUno['categorias_mas_compradas']) === 1, 'el límite recorta los dos rankings');

$f = $g['frecuencia'];
verificar($f['clientas_con_compras'] === 6, 'con historial: Ana, la otra Ana, Beto, Cira, Dani y la sin datos');
verificar($f['repiten'] === 2 && $f['una_sola_compra'] === 4, 'repiten Ana y Dani; las otras 4 no tienen frecuencia');
verificar($f['promedio_dias'] === 20 && $f['mediana_dias'] === 20, 'promedio y mediana de (30 + 10) / 2 = 20 días');
verificar($f['cada']['hasta_30_dias'] === 2 && $f['cada']['de_31_a_60_dias'] === 0, 'Ana (30) y Dani (10) caen en "hasta 30 días"');
verificar($f['les_toca_reponer'] === 1, 'solo a Ana ya le toca reponer');

$largo = api_consumo($facturas, $productos, 365, 10, $HOY);
verificar($largo['compras']['facturas'] === 11, 'a 365 días entran también las compras de julio y agosto (11 facturas, sin la anulada)');

$json = json_encode($g);
verificar(!str_contains($json, '3001112233') && !str_contains($json, 'Ana') && !str_contains($json, 'Beto') && !str_contains($json, 'Dani'),
    'el consumo general no trae nombres ni teléfonos');
verificar(!str_contains($json, '99999') && !str_contains($json, '88888') && !str_contains($json, 'total'),
    'ni costos, ni márgenes, ni dinero');

$vacio = api_consumo([], $productos, 30, 10, $HOY);
verificar($vacio['compras']['facturas'] === 0 && $vacio['productos_mas_comprados'] === [] && $vacio['frecuencia']['promedio_dias'] === null,
    'sin facturas: todo en cero, sin promedios inventados');

/* --- Parámetros --- */
verificar(api_consumo_dias(null) === 30 && api_consumo_dias('x') === 30, 'días por defecto: 30');
verificar(api_consumo_dias('0') === 1 && api_consumo_dias(-4) === 1 && api_consumo_dias('99999') === API_CONSUMO_DIAS_MAX, 'días entre 1 y ' . API_CONSUMO_DIAS_MAX);
verificar(api_consumo_top(null) === 10 && api_consumo_top('0') === 1 && api_consumo_top('999') === API_CONSUMO_TOP_MAX, 'límite entre 1 y ' . API_CONSUMO_TOP_MAX);

echo "\n" . ($fallos === 0 ? "TODO OK" : "$fallos prueba(s) fallaron") . "\n";
exit($fallos === 0 ? 0 : 1);
