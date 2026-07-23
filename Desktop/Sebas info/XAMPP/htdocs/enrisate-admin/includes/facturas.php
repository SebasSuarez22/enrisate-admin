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

function ruta_facturas(): string
{
    if (!is_dir(STORAGE_DIR)) {
        @mkdir(STORAGE_DIR, 0775, true);
    }
    return STORAGE_DIR . '/facturas.json';
}

function leer_facturas(): array
{
    if (!is_file(ruta_facturas())) {
        return [];
    }
    $d = json_decode((string) file_get_contents(ruta_facturas()), true);
    return is_array($d) ? $d : [];
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

/** Guarda una factura nueva y devuelve su número. */
function crear_factura(array $datos): string
{
    $facturas = leer_facturas();
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
    $facturas = leer_facturas();
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

/** Marca una cuota (1 o 2) de una factura a plazos como pagada. */
function marcar_cuota_pagada(string $numero, int $cuota): bool
{
    $f = factura_por_numero($numero);
    if (!$f || $f['plan'] !== 'cuotas' || ($cuota !== 1 && $cuota !== 2)) {
        return false;
    }
    $campo = 'cuota' . $cuota . '_pagada';
    if (!empty($f[$campo])) {
        return false;
    }

    $cuota1Pagada = $cuota === 1 ? true : !empty($f['cuota1_pagada']);
    $cuota2Pagada = $cuota === 2 ? true : !empty($f['cuota2_pagada']);

    return actualizar_factura($numero, [
        $campo               => true,
        'cuota' . $cuota . '_pagada_el' => date('c'),
        'pagado'             => (int) ($f['abono'] ?? 0)
            + ($cuota1Pagada ? $f['cuota1'] : 0) + ($cuota2Pagada ? $f['cuota2'] : 0),
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

    $porCobrar        = 0; // suma de cuotas no pagadas
    $vencido          = 0; // de esas, las que ya se pasaron de fecha
    $cuotasPendientes = 0;
    $cuotasVencidas   = 0;
    $gananciaMes      = 0;
    $totalInvertido   = 0; // costo histórico de todo lo vendido

    foreach ($facturas as $f) {
        if (!empty($f['anulada'])) {
            continue; // una factura anulada no cuenta en ningún número
        }

        $totalInvertido += (int) ($f['costo'] ?? 0);

        if (substr(fecha_factura($f), 0, 7) === $mesHoy) {
            $gananciaMes += (int) ($f['margen'] ?? 0);
        }

        if (($f['plan'] ?? '') !== 'cuotas') {
            continue;
        }

        foreach ([1, 2] as $i) {
            if (!empty($f["cuota{$i}_pagada"])) {
                continue;
            }
            $monto = (int) ($f["cuota{$i}"] ?? 0);
            if ($monto <= 0) {
                continue;
            }
            $porCobrar += $monto;
            $cuotasPendientes++;
            if (esta_vencida((string) ($f["vence{$i}"] ?? ''))) {
                $vencido += $monto;
                $cuotasVencidas++;
            }
        }
    }

    return compact(
        'porCobrar', 'vencido', 'cuotasPendientes', 'cuotasVencidas', 'gananciaMes',
        'totalInvertido'
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
