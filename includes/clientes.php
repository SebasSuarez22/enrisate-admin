<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

/* =========================================================
   Clientes · almacén en MySQL (capa 3)
   =========================================================
   Misma lógica de negocio que la versión en JSON. Solo cambiaron
   leer_clientes() y guardar_clientes(), que ahora hablan con MySQL
   en vez de storage/clientes.json.
   ========================================================= */

function leer_clientes(): array
{
    $filas = db()->query(
        'SELECT id, nombre, telefono, notas, saldo_favor, creado FROM clientes ORDER BY nombre'
    )->fetchAll();

    return array_map(static fn(array $c): array => [
        'id'          => (string) $c['id'],
        'nombre'      => (string) $c['nombre'],
        'telefono'    => (string) $c['telefono'],
        'notas'       => (string) $c['notas'],
        'creado'      => (string) $c['creado'],
        'saldo_favor' => (int) $c['saldo_favor'],
    ], $filas);
}

/** Reemplaza la lista completa de clientas en la base de datos (igual que antes reemplazaba el archivo entero). */
function guardar_clientes(array $clientes): void
{
    $pdo = db();

    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM clientes');
        $st = $pdo->prepare(
            'INSERT INTO clientes (id, nombre, telefono, notas, saldo_favor, creado)
             VALUES (:id, :nombre, :telefono, :notas, :saldo_favor, :creado)'
        );
        foreach ($clientes as $c) {
            $st->execute([
                ':id'          => (string) $c['id'],
                ':nombre'      => (string) $c['nombre'],
                ':telefono'    => (string) $c['telefono'],
                ':notas'       => (string) ($c['notas'] ?? ''),
                ':saldo_favor' => (int) ($c['saldo_favor'] ?? 0),
                ':creado'      => (string) ($c['creado'] ?? date('c')),
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Agrega una clienta nueva a la lista. Devuelve '' si quedó bien, o un mensaje de error. */
function agregar_cliente(string $nombre, string $telefono, string $notas): string
{
    $nombre   = trim($nombre);
    $telefono = normalizar_telefono($telefono);
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
    $telefono = normalizar_telefono($telefono);
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

/**
 * Igual que cliente_ya_existe(), pero devuelve la clienta encontrada (o null)
 * en vez de solo true/false. Se usa para precargar "Clienta existente" al
 * confirmar un pedido del catálogo público, en vez de asumir que es nueva.
 */
function buscar_cliente_coincidente(array $clientes, string $nombre, string $telefono): ?array
{
    foreach ($clientes as $c) {
        if ($telefono !== '' && $c['telefono'] === $telefono) {
            return $c;
        }
        if (strcasecmp(trim($c['nombre']), $nombre) === 0) {
            return $c;
        }
    }
    return null;
}

/** Agrega la clienta de una factura a la lista, si todavía no está ahí. */
function agregar_cliente_desde_factura(string $nombre, string $telefono): void
{
    $nombre   = trim($nombre);
    $telefono = normalizar_telefono($telefono);

    if ($nombre === '') {
        return;
    }

    $clientes = leer_clientes();
    if (!cliente_ya_existe($clientes, $nombre, $telefono)) {
        agregar_cliente($nombre, $telefono, '');
    }
}

/**
 * Cruza una clienta con su historial de facturación (mismo criterio de
 * coincidencia que cliente_ya_existe()/buscar_cliente_coincidente(): por
 * teléfono, o por nombre exacto, lo que primero coincida) y devuelve, si
 * tiene alguna factura sin anular:
 *   - compras:          cuántas facturas tiene
 *   - frecuencia_dias:  cada cuántos días compra en promedio (null con
 *                        una sola factura, no hay con qué promediar)
 *   - favorito:         el nombre del producto que más unidades suma
 *                        entre todas sus facturas
 * Devuelve null si la clienta no tiene ninguna factura (todavía no ha
 * comprado, o las que tiene están todas anuladas).
 */
function estadisticas_compra_cliente(array $cliente, array $facturas): ?array
{
    $telefono = (string) ($cliente['telefono'] ?? '');
    $nombre   = trim((string) ($cliente['nombre'] ?? ''));

    $propias = array_values(array_filter($facturas, function (array $f) use ($telefono, $nombre): bool {
        if (!empty($f['anulada'])) {
            return false;
        }
        if ($telefono !== '' && normalizar_telefono((string) ($f['telefono'] ?? '')) === $telefono) {
            return true;
        }
        return strcasecmp(trim((string) ($f['cliente'] ?? '')), $nombre) === 0;
    }));

    if (!$propias) {
        return null;
    }

    $fechas = array_map(fn(array $f) => strtotime((string) $f['creada']), $propias);
    sort($fechas);
    $n = count($fechas);

    $frecuenciaDias = null;
    if ($n > 1) {
        $rangoDias = ($fechas[$n - 1] - $fechas[0]) / 86400;
        $frecuenciaDias = $rangoDias > 0 ? (int) round($rangoDias / ($n - 1)) : 0;
    }

    $unidadesPorProducto = [];
    foreach ($propias as $f) {
        foreach ($f['lineas'] as $l) {
            $unidadesPorProducto[$l['nombre']] = ($unidadesPorProducto[$l['nombre']] ?? 0) + (int) $l['cant'];
        }
    }
    arsort($unidadesPorProducto);
    $favorito = $unidadesPorProducto ? array_key_first($unidadesPorProducto) : null;

    return [
        'compras'         => $n,
        'frecuencia_dias' => $frecuenciaDias,
        'favorito'        => $favorito,
    ];
}

/** Mete a Clientes las clientas de facturas viejas que todavía no estén ahí. Devuelve cuántas agregó. */
function sincronizar_clientes_desde_facturas(array $facturas): int
{
    $clientes = leer_clientes();
    $agregadas = 0;

    foreach ($facturas as $f) {
        $nombre   = trim((string) ($f['cliente'] ?? ''));
        $telefono = normalizar_telefono((string) ($f['telefono'] ?? ''));

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
