<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/* =========================================================
   Clientes · almacén en archivo (puente hacia la base de datos)
   ========================================================= */

function ruta_clientes(): string
{
    if (!is_dir(STORAGE_DIR)) {
        @mkdir(STORAGE_DIR, 0775, true);
    }
    return STORAGE_DIR . '/clientes.json';
}

function leer_clientes(): array
{
    if (!is_file(ruta_clientes())) {
        return [];
    }
    $d = json_decode((string) file_get_contents(ruta_clientes()), true);
    return is_array($d) ? $d : [];
}

function guardar_clientes(array $clientes): void
{
    file_put_contents(
        ruta_clientes(),
        json_encode($clientes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

/** Agrega una clienta nueva a la lista. Devuelve '' si quedó bien, o un mensaje de error. */
function agregar_cliente(string $nombre, string $telefono, string $notas): string
{
    $nombre   = trim($nombre);
    $telefono = (string) preg_replace('/\D+/', '', $telefono);
    $notas    = trim($notas);

    if ($nombre === '') {
        return 'Escribe el nombre completo de la clienta.';
    }

    $clientes   = leer_clientes();
    $clientes[] = [
        'id'       => uniqid('cli_', true),
        'nombre'   => $nombre,
        'telefono' => $telefono,
        'notas'    => $notas,
        'creado'   => date('c'),
    ];
    guardar_clientes($clientes);

    return '';
}

/** Actualiza nombre, teléfono, notas y saldo a favor de una clienta existente. Devuelve '' si quedó bien, o un mensaje de error. */
function actualizar_cliente(string $id, string $nombre, string $telefono, string $notas, int $saldoFavor = 0): string
{
    $nombre   = trim($nombre);
    $telefono = (string) preg_replace('/\D+/', '', $telefono);
    $notas    = trim($notas);

    if ($nombre === '') {
        return 'Escribe el nombre completo de la clienta.';
    }

    $clientes = leer_clientes();
    $hallada  = false;
    foreach ($clientes as &$c) {
        if ($c['id'] === $id) {
            $c['nombre']      = $nombre;
            $c['telefono']    = $telefono;
            $c['notas']       = $notas;
            $c['saldo_favor'] = max(0, $saldoFavor);
            $hallada          = true;
            break;
        }
    }
    unset($c);

    if (!$hallada) {
        return 'No encontré esa clienta.';
    }

    guardar_clientes($clientes);

    return '';
}

/** Elimina varias clientas por id. Devuelve cuántas encontró y quitó. */
function eliminar_clientes(array $ids): int
{
    $ids = array_values(array_unique(array_filter($ids, fn($id) => $id !== '')));
    if (!$ids) {
        return 0;
    }

    $clientes  = leer_clientes();
    $filtrados = array_values(array_filter($clientes, fn($c) => !in_array($c['id'], $ids, true)));
    $borradas  = count($clientes) - count($filtrados);
    if ($borradas > 0) {
        guardar_clientes($filtrados);
    }
    return $borradas;
}

/** Devuelve una clienta por su id, o null. */
function cliente_por_id(string $id): ?array
{
    foreach (leer_clientes() as $c) {
        if ($c['id'] === $id) {
            return $c;
        }
    }
    return null;
}

/**
 * Descuenta un monto del saldo a favor de una clienta (al aplicarlo en una
 * factura). Nunca deja el saldo por debajo de 0. Devuelve lo que realmente
 * alcanzó a descontar.
 */
function restar_saldo_favor(string $id, int $monto): int
{
    if ($monto <= 0) {
        return 0;
    }
    $clientes = leer_clientes();
    $usado    = 0;
    foreach ($clientes as &$c) {
        if ($c['id'] === $id) {
            $disponible = (int) ($c['saldo_favor'] ?? 0);
            $usado      = max(0, min($disponible, $monto));
            $c['saldo_favor'] = $disponible - $usado;
            break;
        }
    }
    unset($c);
    if ($usado > 0) {
        guardar_clientes($clientes);
    }
    return $usado;
}

/** true si ya hay una clienta con ese teléfono, o (sin teléfono) ese nombre exacto. */
function cliente_ya_existe(array $clientes, string $nombre, string $telefono): bool
{
    foreach ($clientes as $c) {
        if ($telefono !== '' && $c['telefono'] === $telefono) {
            return true;
        }
        if (strcasecmp(trim($c['nombre']), $nombre) === 0) {
            return true;
        }
    }
    return false;
}

/** Agrega la clienta de una factura a la lista, si todavía no está ahí. */
function agregar_cliente_desde_factura(string $nombre, string $telefono): void
{
    $nombre   = trim($nombre);
    $telefono = (string) preg_replace('/\D+/', '', $telefono);

    if ($nombre === '') {
        return;
    }

    $clientes = leer_clientes();
    if (!cliente_ya_existe($clientes, $nombre, $telefono)) {
        agregar_cliente($nombre, $telefono, '');
    }
}

/** Mete a Clientes las clientas de facturas viejas que todavía no estén ahí. Devuelve cuántas agregó. */
function sincronizar_clientes_desde_facturas(array $facturas): int
{
    $clientes = leer_clientes();
    $agregadas = 0;

    foreach ($facturas as $f) {
        $nombre   = trim((string) ($f['cliente'] ?? ''));
        $telefono = (string) preg_replace('/\D+/', '', (string) ($f['telefono'] ?? ''));

        if ($nombre === '' || cliente_ya_existe($clientes, $nombre, $telefono)) {
            continue;
        }

        $clientes[] = [
            'id'       => uniqid('cli_', true),
            'nombre'   => $nombre,
            'telefono' => $telefono,
            'notas'    => '',
            'creado'   => $f['creada'] ?? date('c'),
        ];
        $agregadas++;
    }

    if ($agregadas > 0) {
        guardar_clientes($clientes);
    }

    return $agregadas;
}
