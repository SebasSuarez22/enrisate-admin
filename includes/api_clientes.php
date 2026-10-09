<?php
declare(strict_types=1);

require_once __DIR__ . '/formato.php';
require_once __DIR__ . '/categorias.php';
require_once __DIR__ . '/cuotas.php';
require_once __DIR__ . '/clientes.php';

/* =========================================================
   API de consumo y frecuencia de las clientas · para el bot de WhatsApp
   =========================================================
   Dos consultas, las dos de solo lectura y detrás de la misma llave que la
   del catálogo (ver includes/api.php):

     · api-cliente.php?telefono=…  lo que UNA clienta compra y cada cuánto
       (su historial, su producto favorito, si ya le toca reponer, su saldo).
       El bot la usa con el número de quien le está escribiendo.
     · api-consumo.php             lo que consumen las clientas EN CONJUNTO
       (productos y categorías más comprados, cuántas repiten, cada cuánto).
       Sin nombres ni teléfonos.

   Lo que NUNCA sale por aquí: el costo, el margen ni la ganancia; las notas
   internas de cada clienta; ni una lista de clientas (solo se consulta una,
   por su teléfono exacto, para que nadie pueda sacar la agenda completa).

   Todo el cálculo vive en funciones puras (reciben las facturas ya leídas y
   la fecha de hoy), así tests/api_clientes_test.php lo prueba sin MySQL. La
   cuenta de frecuencia es la misma del panel Clientes (estadisticas_de_facturas).
   ========================================================= */

/** Cuántos productos y cuántas compras recientes entrega la consulta de una clienta. */
const API_CLIENTE_PRODUCTOS = 10;
const API_CLIENTE_COMPRAS   = 5;

/** Período de api-consumo.php: de cuántos días mira atrás (30 si no se pide). */
const API_CONSUMO_DIAS_DEFECTO = 30;
const API_CONSUMO_DIAS_MAX     = 730;
const API_CONSUMO_TOP_DEFECTO  = 10;
const API_CONSUMO_TOP_MAX      = 50;

/** Teléfono dejado en el formato único del panel (solo dígitos, sin el 57), o '' si no sirve. */
function api_telefono(string $crudo): string
{
    $t = normalizar_telefono($crudo);
    return strlen($t) >= 7 && strlen($t) <= 15 ? $t : '';
}

/**
 * El teléfono que manda quien consulta: cabecera X-Cliente-Telefono (la
 * recomendada) o ?telefono= en la dirección. La cabecera es mejor porque la
 * dirección completa queda en el registro de visitas de Railway, y ahí no
 * deberían quedar los celulares de las clientas.
 */
function api_telefono_de_peticion(array $server, array $get): string
{
    $cabecera = trim((string) ($server['HTTP_X_CLIENTE_TELEFONO'] ?? ''));
    if ($cabecera !== '') {
        return $cabecera;
    }
    return is_string($get['telefono'] ?? null) ? $get['telefono'] : '';
}

/** Fecha (Y-m-d) de una factura o pedido a partir de su marca de tiempo guardada. */
function api_fecha(string $marca): string
{
    $ts = strtotime($marca);
    return $ts === false ? '' : date('Y-m-d', $ts);
}

/**
 * Las facturas sin anular de ese teléfono. Solo por teléfono: el panel también
 * cruza por nombre, pero aquí un nombre repetido (o una factura sin nombre)
 * mezclaría las compras de otra persona con las de quien consulta.
 */
function api_facturas_de_telefono(array $facturas, string $telefono): array
{
    if ($telefono === '') {
        return [];
    }
    return array_values(array_filter($facturas, static fn(array $f): bool =>
        empty($f['anulada']) && normalizar_telefono((string) ($f['telefono'] ?? '')) === $telefono
    ));
}

/** La clienta guardada con ese teléfono (la primera por nombre), o null. */
function api_cliente_de_telefono(array $clientes, string $telefono): ?array
{
    if ($telefono === '') {
        return null;
    }
    foreach ($clientes as $c) {
        if (normalizar_telefono((string) ($c['telefono'] ?? '')) === $telefono) {
            return $c;
        }
    }
    return null;
}

/** Los pedidos del catálogo público que esa clienta tiene por revisar. */
function api_pedidos_pendientes_de(array $pedidos, string $telefono): array
{
    $propios = [];
    foreach ($pedidos as $p) {
        if (($p['estado'] ?? '') !== 'pendiente' || $telefono === ''
            || normalizar_telefono((string) ($p['telefono'] ?? '')) !== $telefono) {
            continue;
        }
        $propios[] = [
            'numero'    => (string) $p['numero'],
            'fecha'     => api_fecha((string) ($p['creado'] ?? '')),
            'total'     => (int) $p['total'],
            'unidades'  => array_sum(array_map(fn($l) => (int) $l['cant'], $p['lineas'] ?? [])),
            'productos' => array_map(fn($l) => ['nombre' => (string) $l['nombre'], 'cant' => (int) $l['cant']], $p['lineas'] ?? []),
        ];
    }
    usort($propios, fn($a, $b) => strcmp($b['fecha'], $a['fecha']) ?: strcmp($b['numero'], $a['numero']));
    return $propios;
}

/** Lo que la clienta debe en cuotas: total por pagar, lo ya vencido y la próxima cuota. */
function api_cartera(array $facturas, string $hoy): array
{
    $porPagar = 0;
    $vencido  = 0;
    $cuotas   = 0;
    $proxima  = null;

    foreach ($facturas as $f) {
        if (($f['plan'] ?? '') !== 'cuotas') {
            continue;
        }
        foreach ($f['cuotas'] ?? [] as $c) {
            $falta = cuota_pendiente($c);
            if ($falta <= 0) {
                continue;
            }
            $vence = (string) ($c['vence'] ?? '');
            $porPagar += $falta;
            $cuotas++;
            if ($vence !== '' && strtotime($vence) < strtotime($hoy)) {
                $vencido += $falta;
            }
            if ($vence !== '' && ($proxima === null || strcmp($vence, $proxima['vence']) < 0)) {
                $proxima = ['factura' => (string) $f['numero'], 'vence' => $vence, 'monto' => $falta];
            }
        }
    }

    return [
        'por_pagar'         => $porPagar,
        'vencido'           => $vencido,
        'cuotas_pendientes' => $cuotas,
        'proxima_cuota'     => $proxima,
    ];
}

/**
 * Los productos que más ha comprado, de más a menos unidades (a igual
 * cantidad, el que compró más recientemente primero).
 */
function api_productos_de_clienta(array $facturas, int $limite): array
{
    $por = [];
    foreach ($facturas as $f) {
        $fecha = api_fecha((string) $f['creada']);
        foreach ($f['lineas'] as $l) {
            $clave = (string) $l['sku'] !== '' ? (string) $l['sku'] : 'n:' . mb_strtolower((string) $l['nombre']);
            $por[$clave] ??= [
                'sku' => (string) $l['sku'], 'nombre' => (string) $l['nombre'], 'marca' => (string) $l['marca'],
                'unidades' => 0, 'veces' => 0, 'ultima_vez' => '', '_facturas' => [],
            ];
            $por[$clave]['unidades'] += (int) $l['cant'];
            // "veces" = en cuántas compras lo llevó: el mismo producto en dos líneas de una factura cuenta una.
            $por[$clave]['_facturas'][(string) $f['numero']] = true;
            $por[$clave]['veces'] = count($por[$clave]['_facturas']);
            if (strcmp($fecha, $por[$clave]['ultima_vez']) > 0) {
                $por[$clave]['ultima_vez'] = $fecha;
            }
        }
    }
    $lista = array_map(function (array $r): array {
        unset($r['_facturas']);
        return $r;
    }, array_values($por));
    usort($lista, fn($a, $b) => $b['unidades'] <=> $a['unidades']
        ?: strcmp($b['ultima_vez'], $a['ultima_vez'])
        ?: strcmp($a['nombre'], $b['nombre']));
    return array_slice($lista, 0, max(1, $limite));
}

/** Sus compras más recientes, de la más nueva a la más vieja. */
function api_compras_recientes(array $facturas, int $limite): array
{
    $ordenadas = $facturas;
    usort($ordenadas, fn($a, $b) => strtotime((string) $b['creada']) <=> strtotime((string) $a['creada'])
        ?: strcmp((string) $b['numero'], (string) $a['numero']));

    return array_map(fn(array $f): array => [
        'numero'    => (string) $f['numero'],
        'fecha'     => api_fecha((string) $f['creada']),
        'total'     => (int) $f['total'],
        'productos' => array_map(fn($l) => ['nombre' => (string) $l['nombre'], 'cant' => (int) $l['cant']], $f['lineas']),
    ], array_slice($ordenadas, 0, max(1, $limite)));
}

/**
 * Todo lo de UNA clienta, para que el bot le hable con sus datos.
 *   $telefono  ya normalizado (api_telefono)
 *   $facturas  todas las facturas del negocio (leer_facturas())
 *   $clientes  todas las clientas (leer_clientes())
 *   $pedidos   los pedidos por revisar (pedidos_pendientes())
 *   $permiso   'si' | 'no' | '' según mensajes_permisos
 *   $hoy       Y-m-d (parámetro para poder probarlo con fechas fijas)
 */
function api_resumen_clienta(string $telefono, array $facturas, array $clientes, array $pedidos, string $permiso, string $hoy): array
{
    $clienta = api_cliente_de_telefono($clientes, $telefono);
    $propias = api_facturas_de_telefono($facturas, $telefono);
    $stats   = estadisticas_de_facturas($propias);

    // Nombre: el de la ficha de la clienta; si no la hay, el de su factura más reciente.
    $nombre = $clienta ? trim((string) $clienta['nombre']) : '';
    if ($nombre === '' && $propias) {
        $ultima = $propias;
        usort($ultima, fn($a, $b) => strtotime((string) $b['creada']) <=> strtotime((string) $a['creada']));
        $nombre = trim((string) $ultima[0]['cliente']);
    }

    $compras = null;
    $favorito = null;
    if ($stats !== null) {
        $fechas = array_map(fn($f) => api_fecha((string) $f['creada']), $propias);
        sort($fechas);
        $dias = max(0, (int) floor((strtotime($hoy) - strtotime($stats['ultima_compra'])) / 86400));
        $frecuencia = $stats['frecuencia_dias'];
        $compras = [
            'total'            => $stats['compras'],
            'unidades'         => array_sum(array_map(fn($f) => array_sum(array_map(fn($l) => (int) $l['cant'], $f['lineas'])), $propias)),
            'primera_compra'   => $fechas[0],
            'ultima_compra'    => $stats['ultima_compra'],
            'dias_desde_ultima' => $dias,
            // Cada cuántos días compra en promedio; null con una sola compra o si todas fueron el mismo día.
            'frecuencia_dias'  => $frecuencia !== null && $frecuencia >= 1 ? $frecuencia : null,
            'le_toca_reponer'  => $frecuencia !== null && $frecuencia >= 1 ? $dias >= $frecuencia : null,
        ];
        // (string): el favorito es una clave de arreglo, y un nombre hecho solo de números llegaría como número.
        $favorito = $stats['favorito'] === null ? null : (string) $stats['favorito'];
    }

    $nombre = $nombre === '' ? null : $nombre;

    return [
        'ok'                   => true,
        'conocida'             => $clienta !== null || $propias !== [],
        'nombre'               => $nombre,
        'primer_nombre'        => $nombre === null ? null : (string) strtok($nombre, ' '),
        'compras'              => $compras,
        'favorito'             => $favorito,
        'productos'            => api_productos_de_clienta($propias, API_CLIENTE_PRODUCTOS),
        'ultimas_compras'      => api_compras_recientes($propias, API_CLIENTE_COMPRAS),
        'cartera'              => api_cartera($propias, $hoy),
        'saldo_a_favor'        => $clienta ? (int) $clienta['saldo_favor'] : 0,
        'pedidos_pendientes'   => api_pedidos_pendientes_de($pedidos, $telefono),
        // 'si' | 'no' | 'sin_respuesta': si aceptó recibir promociones por WhatsApp.
        'acepta_promociones'   => $permiso === 'si' || $permiso === 'no' ? $permiso : 'sin_respuesta',
    ];
}

/** Días pedidos para el consumo, dentro de [1, API_CONSUMO_DIAS_MAX]; 30 si no es un número. */
function api_consumo_dias(mixed $pedido): int
{
    return is_numeric($pedido) ? max(1, min(API_CONSUMO_DIAS_MAX, (int) $pedido)) : API_CONSUMO_DIAS_DEFECTO;
}

/** Cuántos productos mostrar en el ranking, dentro de [1, API_CONSUMO_TOP_MAX]; 10 si no es un número. */
function api_consumo_top(mixed $pedido): int
{
    return is_numeric($pedido) ? max(1, min(API_CONSUMO_TOP_MAX, (int) $pedido)) : API_CONSUMO_TOP_DEFECTO;
}

/**
 * Quién es quién en las facturas: el teléfono, o el nombre si la factura no
 * tiene teléfono. '' si no tiene ninguno de los dos: esa factura cuenta para
 * los productos, pero no como clienta (si no, todas las facturas sin datos se
 * juntarían en una "clienta" inventada que compra seguido).
 */
function api_clave_clienta(array $f): string
{
    $t = normalizar_telefono((string) ($f['telefono'] ?? ''));
    if ($t !== '') {
        return 't:' . $t;
    }
    $nombre = mb_strtolower(trim((string) ($f['cliente'] ?? '')));
    return $nombre !== '' ? 'n:' . $nombre : '';
}

/**
 * Lo que consumen las clientas en conjunto. Sin nombres ni teléfonos ni dinero:
 *   · productos y categorías más comprados en los últimos $dias días
 *   · cada cuánto repiten (con TODO el historial, no solo el período)
 *   $productos  el catálogo, para saber la categoría de cada producto vendido
 */
function api_consumo(array $facturas, array $productos, int $dias, int $top, string $hoy): array
{
    $vivas  = array_values(array_filter($facturas, fn($f) => empty($f['anulada'])));
    $hoyTs  = strtotime($hoy);
    $desde  = date('Y-m-d', $hoyTs - ($dias - 1) * 86400);

    $categoriaDe = [];
    foreach ($productos as $p) {
        $categoriaDe[(string) $p['sku']] = categoria_de($p);
    }

    // --- Lo que se compró en el período ---
    $porProducto = [];
    $porCategoria = [];
    $compradoras = [];
    $facturasPeriodo = 0;
    $unidades = 0;
    foreach ($vivas as $f) {
        $fecha = api_fecha((string) $f['creada']);
        if ($fecha < $desde || $fecha > $hoy) {
            continue;
        }
        $facturasPeriodo++;
        $quien = api_clave_clienta($f);
        if ($quien !== '') {
            $compradoras[$quien] = true;
        }
        foreach ($f['lineas'] as $l) {
            $cant = (int) $l['cant'];
            $sku  = (string) $l['sku'];
            $clave = $sku !== '' ? $sku : 'n:' . mb_strtolower((string) $l['nombre']);
            $porProducto[$clave] ??= [
                'sku' => $sku, 'nombre' => (string) $l['nombre'], 'marca' => (string) $l['marca'],
                'categoria' => $categoriaDe[$sku] ?? categoria_deducida((string) $l['nombre']),
                'unidades' => 0, '_quienes' => [],
            ];
            $porProducto[$clave]['unidades'] += $cant;
            if ($quien !== '') {
                $porProducto[$clave]['_quienes'][$quien] = true;
            }
            $unidades += $cant;

            $cat = $porProducto[$clave]['categoria'];
            $porCategoria[$cat] = ($porCategoria[$cat] ?? 0) + $cant;
        }
    }

    $ranking = array_values($porProducto);
    foreach ($ranking as &$r) {
        $r['compradoras'] = count($r['_quienes']);
        unset($r['_quienes']);
    }
    unset($r);
    usort($ranking, fn($a, $b) => $b['unidades'] <=> $a['unidades'] ?: $b['compradoras'] <=> $a['compradoras'] ?: strcmp($a['nombre'], $b['nombre']));

    $categorias = [];
    foreach ($porCategoria as $nombre => $u) {
        $categorias[] = ['categoria' => $nombre === '' ? 'Sin clasificar' : $nombre, 'unidades' => $u];
    }
    usort($categorias, fn($a, $b) => $b['unidades'] <=> $a['unidades'] ?: strcmp($a['categoria'], $b['categoria']));

    // --- Frecuencia: todo el historial, una cuenta por clienta ---
    $grupos = [];
    foreach ($vivas as $f) {
        $quien = api_clave_clienta($f);
        if ($quien !== '') {
            $grupos[$quien][] = $f;
        }
    }
    $conHistorial = count($grupos);
    $repiten = 0;
    $intervalos = [];
    $rangos = ['hasta_30_dias' => 0, 'de_31_a_60_dias' => 0, 'de_61_a_90_dias' => 0, 'mas_de_90_dias' => 0];
    $lesToca = 0;
    foreach ($grupos as $propias) {
        $s = estadisticas_de_facturas($propias);
        $frec = $s['frecuencia_dias'] ?? null;
        if ($frec === null || $frec < 1) {
            continue; // una sola compra (o todas el mismo día): no hay con qué promediar
        }
        $repiten++;
        $intervalos[] = $frec;
        $rangos[match (true) {
            $frec <= 30 => 'hasta_30_dias',
            $frec <= 60 => 'de_31_a_60_dias',
            $frec <= 90 => 'de_61_a_90_dias',
            default     => 'mas_de_90_dias',
        }]++;
        $desdeUltima = (int) floor(($hoyTs - strtotime($s['ultima_compra'])) / 86400);
        if ($desdeUltima >= $frec) {
            $lesToca++;
        }
    }
    sort($intervalos);
    $n = count($intervalos);
    $mediana = $n === 0 ? null : ($n % 2 ? $intervalos[intdiv($n, 2)] : (int) round(($intervalos[$n / 2 - 1] + $intervalos[$n / 2]) / 2));

    return [
        'ok'       => true,
        'periodo'  => ['dias' => $dias, 'desde' => $desde, 'hasta' => $hoy],
        'compras'  => [
            'facturas'          => $facturasPeriodo,
            'unidades'          => $unidades,
            'clientas_distintas' => count($compradoras),
        ],
        'productos_mas_comprados' => array_slice($ranking, 0, $top),
        'categorias_mas_compradas' => array_slice($categorias, 0, $top),
        'frecuencia' => [
            'clientas_con_compras'  => $conHistorial,
            'repiten'               => $repiten,
            // Incluye a quien compró varias veces pero todas el mismo día: sin días entre compras no hay frecuencia.
            'una_sola_compra'       => $conHistorial - $repiten,
            'promedio_dias'         => $n === 0 ? null : (int) round(array_sum($intervalos) / $n),
            'mediana_dias'          => $mediana,
            'cada'                  => $rangos,
            'les_toca_reponer'      => $lesToca,
        ],
    ];
}
