<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/* =========================================================
   Facturas · almacén en archivo (puente hacia la base de datos)
   ========================================================= */

function ruta_catalogo(): string
{
    if (!is_dir(STORAGE_DIR)) {
        @mkdir(STORAGE_DIR, 0775, true);
    }
    return STORAGE_DIR . '/catalogo.json';
}

/**
 * Devuelve una referencia a la caché en memoria del catálogo, para que
 * catalogo() y guardar_catalogo() siempre vean el mismo estado dentro
 * de una misma petición (si no, guardar dos veces seguidas —p. ej.
 * agregar un producto y luego revisar duplicados— pisaría el primer
 * cambio con datos viejos).
 */
function &catalogo_cache(): array
{
    static $cat = null;
    if ($cat === null) {
        $ruta = ruta_catalogo();
        $d    = is_file($ruta) ? json_decode((string) file_get_contents($ruta), true) : null;

        if (is_array($d)) {
            $cat = $d;
        } else {
            // Primera vez: siembra el catálogo editable desde el archivo base.
            $semilla = require BASE_DIR . '/datos/catalogo.php';
            foreach ($semilla as &$p) {
                $p['stock'] = $p['stock'] ?? 0;
            }
            unset($p);
            $cat = $semilla;
            guardar_catalogo($cat);
        }
    }
    return $cat;
}

function catalogo(): array
{
    return catalogo_cache();
}

function guardar_catalogo(array $productos): void
{
    $productos = array_values($productos);

    file_put_contents(
        ruta_catalogo(),
        json_encode($productos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );

    $cache = &catalogo_cache();
    $cache = $productos;
}

function producto_por_sku(string $sku): ?array
{
    foreach (catalogo() as $p) {
        if ($p['sku'] === $sku) {
            return $p;
        }
    }
    return null;
}

/** Número final de un SKU, para ordenar por número (p. ej. "MIL-03" → 3, "DES01" → 1). */
function sku_orden(string $sku): int
{
    return preg_match('/(\d+)$/', $sku, $m) ? (int) $m[1] : 0;
}

/** Agrega un producto nuevo al catálogo. Devuelve '' si quedó bien, o un mensaje de error. */
function agregar_producto(string $sku, string $marca, string $nombre, int $precio, int $costo): string
{
    $sku    = strtoupper(trim($sku));
    $marca  = trim($marca);
    $nombre = trim($nombre);

    if ($sku === '' || !preg_match('/^[A-Z0-9\-_]+$/', $sku)) {
        return 'El SKU es obligatorio y solo puede tener letras, números, guiones y guion bajo.';
    }
    if ($marca === '' || $nombre === '') {
        return 'Escribe la marca y el nombre del producto.';
    }
    if ($precio < 0 || $costo < 0) {
        return 'Precio y costo no pueden ser negativos.';
    }
    if (producto_por_sku($sku)) {
        return 'Ya existe un producto con el SKU ' . $sku . '.';
    }

    $productos   = catalogo();
    $productos[] = [
        'sku'    => $sku,
        'marca'  => $marca,
        'nombre' => $nombre,
        'precio' => $precio,
        'costo'  => $costo,
    ];
    guardar_catalogo($productos);

    return '';
}

/** Fija el precio y el costo de un SKU existente. Devuelve true si lo encontró. */
function actualizar_precio_costo(string $sku, int $precio, int $costo): bool
{
    $productos = catalogo();
    $hallado   = false;
    foreach ($productos as &$p) {
        if ($p['sku'] === $sku) {
            $p['precio'] = max(0, $precio);
            $p['costo']  = max(0, $costo);
            $hallado     = true;
            break;
        }
    }
    unset($p);
    if ($hallado) {
        guardar_catalogo($productos);
    }
    return $hallado;
}

/** Actualiza marca y nombre de un SKU existente. Devuelve true si lo encontró. */
function actualizar_producto(string $sku, string $marca, string $nombre): bool
{
    $marca  = trim($marca);
    $nombre = trim($nombre);
    if ($marca === '' || $nombre === '') {
        return false;
    }

    $productos = catalogo();
    $hallado   = false;
    foreach ($productos as &$p) {
        if ($p['sku'] === $sku) {
            $p['marca']  = $marca;
            $p['nombre'] = $nombre;
            $hallado     = true;
            break;
        }
    }
    unset($p);
    if ($hallado) {
        guardar_catalogo($productos);
    }
    return $hallado;
}

/** Elimina varios productos del catálogo por SKU. Devuelve cuántos encontró y quitó. */
function eliminar_productos(array $skus): int
{
    $skus = array_values(array_unique(array_filter($skus, fn($sku) => $sku !== '')));
    if (!$skus) {
        return 0;
    }

    $productos = catalogo();
    $filtrados = array_values(array_filter($productos, fn($p) => !in_array($p['sku'], $skus, true)));
    $borrados  = count($productos) - count($filtrados);
    if ($borrados > 0) {
        guardar_catalogo($filtrados);
    }
    return $borrados;
}

function ruta_facturas(): string
{
    if (!is_dir(STORAGE_DIR)) {
        @mkdir(STORAGE_DIR, 0775, true);
    }
    return STORAGE_DIR . '/facturas.json';
}

/**
 * Lee las facturas del archivo tal cual están guardadas, SIN migrar el
 * formato viejo. Uso interno de las funciones que escriben (crear_factura,
 * actualizar_factura), para que nunca se reescriba con datos migrados el
 * registro de una factura que no se está tocando.
 */
function leer_facturas_raw(): array
{
    if (!is_file(ruta_facturas())) {
        return [];
    }
    $d = json_decode((string) file_get_contents(ruta_facturas()), true);
    return is_array($d) ? $d : [];
}

function leer_facturas(): array
{
    return array_map('normalizar_cuotas_factura', leer_facturas_raw());
}

function guardar_facturas(array $f): void
{
    file_put_contents(
        ruta_facturas(),
        json_encode($f, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

function siguiente_numero(): string
{
    $f   = leer_facturas();
    $max = 0;
    foreach ($f as $fac) {
        $n = (int) substr((string) $fac['numero'], -4);
        if ($n > $max) {
            $max = $n;
        }
    }
    return date('Y') . '-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
}

function factura_por_numero(string $numero): ?array
{
    foreach (leer_facturas() as $f) {
        if ($f['numero'] === $numero) {
            return $f;
        }
    }
    return null;
}

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

/** Guarda una factura nueva y devuelve su número. */
function crear_factura(array $datos): string
{
    $facturas = leer_facturas_raw();
    $numero   = siguiente_numero();

    $datos['numero'] = $numero;
    $datos['creada'] = date('c');

    $facturas[] = $datos;
    guardar_facturas($facturas);

    return $numero;
}

/** Aplica cambios a una factura existente. Devuelve true si la encontró. */
function actualizar_factura(string $numero, array $cambios): bool
{
    $facturas = leer_facturas_raw();
    $hallada  = false;
    foreach ($facturas as &$f) {
        if ($f['numero'] === $numero) {
            $f       = array_merge($f, $cambios);
            $hallada = true;
            break;
        }
    }
    unset($f);
    if ($hallada) {
        guardar_facturas($facturas);
    }
    return $hallada;
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
 * Registra un abono de monto libre contra una cuota específica de una
 * factura a plazos. Rechaza (no recorta) montos mayores al pendiente,
 * índices de cuota inexistentes, montos de cero o facturas anuladas.
 * Devuelve true si quedó registrado.
 */
function registrar_abono(string $numero, int $indiceCuota, int $monto): bool
{
    $f = factura_por_numero($numero);
    if (!$f || !empty($f['anulada']) || ($f['plan'] ?? '') !== 'cuotas') {
        return false;
    }
    if (!isset($f['cuotas'][$indiceCuota])) {
        return false;
    }
    if ($monto <= 0) {
        return false;
    }

    $pendiente = cuota_pendiente($f['cuotas'][$indiceCuota]);
    if ($monto > $pendiente) {
        return false;
    }

    $f['cuotas'][$indiceCuota]['abonos'][] = [
        'monto' => $monto,
        'fecha' => date('c'),
    ];

    return actualizar_factura($numero, [
        'cuotas' => $f['cuotas'],
        'pagado' => total_pagado_cuotas($f),
    ]);
}

/**
 * Anula una factura: no se borra, queda marcada como anulada (deja rastro
 * contable y conserva el consecutivo). Sale de todos los cálculos y de la
 * cartera, pero sigue viéndose en el historial. Devuelve true si la halló.
 */
function anular_factura(string $numero, string $motivo = ''): bool
{
    $f = factura_por_numero($numero);
    if (!$f || !empty($f['anulada'])) {
        return false;
    }
    return actualizar_factura($numero, [
        'anulada'          => true,
        'anulada_el'       => date('c'),
        'motivo_anulacion' => trim($motivo),
    ]);
}

/** Revierte la anulación de una factura (por si se anuló por error). */
function reactivar_factura(string $numero): bool
{
    $f = factura_por_numero($numero);
    if (!$f || empty($f['anulada'])) {
        return false;
    }
    return actualizar_factura($numero, [
        'anulada'    => false,
        'anulada_el' => null,
    ]);
}

/** Fecha "de la venta" a mostrar: la de pago si es de contado, o la de creación. */
function fecha_factura(array $f): string
{
    return $f['fecha_pago'] ?? $f['creada'];
}

/**
 * Números del resumen del panel: cuánto hay por cobrar, cuánto está vencido
 * y la ganancia de este mes. Es la única fuente de estos cálculos para el
 * dashboard (así no se repiten en varias páginas).
 */
function metricas_panel(): array
{
    $facturas = leer_facturas();
    $mesHoy   = date('Y-m');
    $anioHoy  = date('Y');

    $porCobrar        = 0; // suma de cuotas no pagadas
    $vencido          = 0; // de esas, las que ya se pasaron de fecha
    $cuotasPendientes = 0;
    $cuotasVencidas   = 0;
    $gananciaMes      = 0;
    $gananciaAnio     = 0;
    $ventaAnio        = 0; // total vendido (facturado) este año
    $totalInvertido   = 0; // costo histórico de todo lo vendido

    foreach ($facturas as $f) {
        if (!empty($f['anulada'])) {
            continue; // una factura anulada no cuenta en ningún número
        }

        $totalInvertido += (int) ($f['costo'] ?? 0);

        $fecha = fecha_factura($f);
        if (substr($fecha, 0, 7) === $mesHoy) {
            $gananciaMes += (int) ($f['margen'] ?? 0);
        }
        if (substr($fecha, 0, 4) === $anioHoy) {
            $gananciaAnio += (int) ($f['margen'] ?? 0);
            $ventaAnio    += (int) ($f['total'] ?? 0);
        }

        if (($f['plan'] ?? '') !== 'cuotas') {
            continue;
        }

        foreach ($f['cuotas'] ?? [] as $cuota) {
            $pendiente = cuota_pendiente($cuota);
            if ($pendiente <= 0) {
                continue;
            }
            $porCobrar += $pendiente;
            $cuotasPendientes++;
            if (esta_vencida((string) ($cuota['vence'] ?? ''))) {
                $vencido += $pendiente;
                $cuotasVencidas++;
            }
        }
    }

    return compact(
        'porCobrar', 'vencido', 'cuotasPendientes', 'cuotasVencidas', 'gananciaMes',
        'gananciaAnio', 'ventaAnio', 'totalInvertido'
    );
}

function esta_vencida(string $vence): bool
{
    return strtotime($vence) < strtotime('today');
}

/* --- Formato --- */
function pesos(float|int $n): string
{
    return '$' . number_format((float) $n, 0, ',', '.');
}

function numero(float|int $n): string
{
    return number_format((float) $n, 0, ',', '.');
}

function nombres_meses(): array
{
    return ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
            'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
}

function fecha_larga(string $iso): string
{
    $meses = nombres_meses();
    $t = strtotime($iso);
    return date('j', $t) . ' de ' . $meses[(int) date('n', $t)] . ' de ' . date('Y', $t);
}

/** "2026-07" → "julio de 2026" */
function mes_largo(string $ym): string
{
    $meses = nombres_meses();
    [$anio, $mes] = explode('-', $ym);
    return $meses[(int) $mes] . ' de ' . $anio;
}

/**
 * Rótulo en español de una cuota por su índice (0-based): "Primera cuota",
 * "Segunda cuota", ..., o "Cuota N" más allá de la cuarta. Si $totalCuotas
 * es 1 (un pago único a plazo, sin dividir), devuelve "Pago único" en vez
 * de "Primera cuota", que sonaría raro si no hay una segunda.
 */
function rotulo_cuota(int $idx, int $totalCuotas = 0): string
{
    if ($totalCuotas === 1) {
        return 'Pago único';
    }
    $rotulos = ['Primera cuota', 'Segunda cuota', 'Tercera cuota', 'Cuarta cuota'];
    return $rotulos[$idx] ?? ('Cuota ' . ($idx + 1));
}

/** Arma el link de WhatsApp (wa.me) para un teléfono y un mensaje ya redactado. Si el teléfono está vacío, devuelve un link sin número (comportamiento existente). */
function enlace_whatsapp(string $telefono, string $mensaje): string
{
    return 'https://wa.me/' . ($telefono !== '' ? '57' . ltrim($telefono, '5') : '')
        . '?text=' . rawurlencode($mensaje);
}
