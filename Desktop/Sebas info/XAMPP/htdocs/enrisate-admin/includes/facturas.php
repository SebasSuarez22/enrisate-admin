<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/* =========================================================
   Facturas · almacén en archivo (puente hacia la base de datos)
   ========================================================= */

function catalogo(): array
{
    static $cat = null;
    if ($cat === null) {
        $cat = require BASE_DIR . '/datos/catalogo.php';
    }
    return $cat;
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

/* --- Formato --- */
function pesos(float|int $n): string
{
    return '$' . number_format((float) $n, 0, ',', '.');
}

function fecha_larga(string $iso): string
{
    $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
              'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $t = strtotime($iso);
    return date('j', $t) . ' de ' . $meses[(int) date('n', $t)] . ' de ' . date('Y', $t);
}
