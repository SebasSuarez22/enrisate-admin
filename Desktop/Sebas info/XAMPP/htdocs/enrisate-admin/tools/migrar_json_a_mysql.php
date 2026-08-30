<?php
declare(strict_types=1);

/* =========================================================
   Migración: storage/*.json  →  MySQL (enrisate_admin)
   =========================================================
   Cómo correrlo (desde la carpeta del proyecto):

       php tools/migrar_json_a_mysql.php

   Qué hace:
     1) Antes de tocar nada, guarda una copia de los JSON en
        storage/respaldo-json/<fecha-hora>/ (por si algo sale mal).
     2) Borra el contenido de las tablas (TRUNCATE) para que se pueda
        correr varias veces sin duplicar datos, y vuelve a insertar
        todo desde los archivos JSON actuales.
     3) Todo dentro de una transacción: si algo falla a mitad de camino,
        no queda la base a medias.
     4) Al final imprime cuántas filas quedaron en cada tabla, para que
        compares con lo que había en los JSON.

   Solo se corre por línea de comandos (no por navegador), como medida
   de seguridad simple.
   ========================================================= */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script solo se puede correr por línea de comandos (CLI), no desde el navegador.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

function leer_json(string $ruta): array
{
    if (!is_file($ruta)) {
        return [];
    }
    $d = json_decode((string) file_get_contents($ruta), true);
    return is_array($d) ? $d : [];
}

function log_linea(string $s): void
{
    echo $s . PHP_EOL;
}

/* ---------------------------------------------------------
   1) Respaldo de los JSON actuales antes de migrar nada
   --------------------------------------------------------- */
$sello       = date('Ymd-His');
$dirRespaldo = STORAGE_DIR . '/respaldo-json/' . $sello;

if (!is_dir($dirRespaldo) && !@mkdir($dirRespaldo, 0775, true)) {
    die("No pude crear la carpeta de respaldo en $dirRespaldo\n");
}

$archivosJson = [
    'catalogo.json',
    'clientes.json',
    'facturas.json',
    'pedidos.json',
    'intentos.json',
    'pedidos_intentos.json',
];

foreach ($archivosJson as $archivo) {
    $origen = STORAGE_DIR . '/' . $archivo;
    if (is_file($origen)) {
        copy($origen, $dirRespaldo . '/' . $archivo);
    }
}
log_linea("Respaldo de los JSON guardado en storage/respaldo-json/{$sello}/");
log_linea('');

/* ---------------------------------------------------------
   2) Leer los JSON de origen
   --------------------------------------------------------- */
$catalogoJson = leer_json(STORAGE_DIR . '/catalogo.json');
$clientesJson = leer_json(STORAGE_DIR . '/clientes.json');
$facturasJson = leer_json(STORAGE_DIR . '/facturas.json');
$pedidosJson  = leer_json(STORAGE_DIR . '/pedidos.json');

// Si nunca se generó storage/catalogo.json (instalación nueva), usa el
// catálogo maestro de datos/catalogo.php como semilla.
if (!$catalogoJson) {
    $catalogoJson = require BASE_DIR . '/datos/catalogo.php';
}

$pdo = db();

/* ---------------------------------------------------------
   3) Migración dentro de una transacción
   --------------------------------------------------------- */
$pdo->beginTransaction();

try {
    // Orden de borrado: primero las tablas hijas, luego las padres.
    foreach ([
        'factura_abonos', 'factura_cuotas', 'factura_lineas',
        'pedido_lineas', 'pedidos', 'facturas', 'clientes', 'productos',
    ] as $tabla) {
        $pdo->exec("DELETE FROM {$tabla}");
    }

    /* --- Productos --- */
    $stProd = $pdo->prepare(
        'INSERT INTO productos (sku, marca, nombre, precio, costo, stock)
         VALUES (:sku, :marca, :nombre, :precio, :costo, :stock)'
    );
    $nProd = 0;
    foreach ($catalogoJson as $p) {
        $stProd->execute([
            ':sku'    => (string) $p['sku'],
            ':marca'  => (string) ($p['marca'] ?? ''),
            ':nombre' => (string) ($p['nombre'] ?? ''),
            ':precio' => (int) ($p['precio'] ?? 0),
            ':costo'  => (int) ($p['costo'] ?? 0),
            ':stock'  => (int) ($p['stock'] ?? 0),
        ]);
        $nProd++;
    }
    log_linea("Productos migrados: {$nProd}");

    /* --- Clientes --- */
    $stCli = $pdo->prepare(
        'INSERT INTO clientes (id, nombre, telefono, notas, saldo_favor, creado)
         VALUES (:id, :nombre, :telefono, :notas, :saldo_favor, :creado)'
    );
    $nCli = 0;
    foreach ($clientesJson as $c) {
        $stCli->execute([
            ':id'          => (string) ($c['id'] ?? uniqid('cli_', true)),
            ':nombre'      => (string) ($c['nombre'] ?? ''),
            ':telefono'    => (string) ($c['telefono'] ?? ''),
            ':notas'       => (string) ($c['notas'] ?? ''),
            ':saldo_favor' => (int) ($c['saldo_favor'] ?? 0),
            ':creado'      => (string) ($c['creado'] ?? date('c')),
        ]);
        $nCli++;
    }
    log_linea("Clientas migradas: {$nCli}");

    /* --- Facturas + líneas + cuotas + abonos --- */
    $stFac = $pdo->prepare(
        'INSERT INTO facturas
            (numero, cliente, telefono, nota, subtotal, descuento, total, costo, margen,
             plan, abono, saldo_aplicado, pagado, fecha_pago, creada,
             anulada, anulada_el, motivo_anulacion)
         VALUES
            (:numero, :cliente, :telefono, :nota, :subtotal, :descuento, :total, :costo, :margen,
             :plan, :abono, :saldo_aplicado, :pagado, :fecha_pago, :creada,
             :anulada, :anulada_el, :motivo_anulacion)'
    );
    $stFacLinea = $pdo->prepare(
        'INSERT INTO factura_lineas (factura_numero, orden, sku, nombre, marca, precio, costo, cant)
         VALUES (:factura_numero, :orden, :sku, :nombre, :marca, :precio, :costo, :cant)'
    );
    $stFacCuota = $pdo->prepare(
        'INSERT INTO factura_cuotas (factura_numero, orden, monto, vence)
         VALUES (:factura_numero, :orden, :monto, :vence)'
    );
    $stFacAbono = $pdo->prepare(
        'INSERT INTO factura_abonos (cuota_id, orden, monto, fecha)
         VALUES (:cuota_id, :orden, :monto, :fecha)'
    );

    $nFac = 0;
    $nFacLineas = 0;
    $nFacCuotas = 0;
    $nFacAbonos = 0;

    foreach ($facturasJson as $f) {
        // Compatibilidad con el formato viejo de cuotas (cuota1/cuota2 sueltos),
        // igual que normalizar_cuotas_factura() en includes/facturas.php.
        $cuotas = $f['cuotas'] ?? null;
        if (!is_array($cuotas) && ($f['plan'] ?? '') === 'cuotas') {
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
        }
        $cuotas = $cuotas ?? [];

        $stFac->execute([
            ':numero'           => (string) $f['numero'],
            ':cliente'          => (string) ($f['cliente'] ?? ''),
            ':telefono'         => (string) ($f['telefono'] ?? ''),
            ':nota'             => (string) ($f['nota'] ?? ''),
            ':subtotal'         => (int) ($f['subtotal'] ?? 0),
            ':descuento'        => (int) ($f['descuento'] ?? 0),
            ':total'            => (int) ($f['total'] ?? 0),
            ':costo'            => (int) ($f['costo'] ?? 0),
            ':margen'           => (int) ($f['margen'] ?? 0),
            ':plan'             => ($f['plan'] ?? 'contado') === 'cuotas' ? 'cuotas' : 'contado',
            ':abono'            => (int) ($f['abono'] ?? 0),
            ':saldo_aplicado'   => (int) ($f['saldo_aplicado'] ?? 0),
            ':pagado'           => (int) ($f['pagado'] ?? 0),
            ':fecha_pago'       => !empty($f['fecha_pago']) ? substr((string) $f['fecha_pago'], 0, 10) : null,
            ':creada'           => (string) ($f['creada'] ?? date('c')),
            ':anulada'          => !empty($f['anulada']) ? 1 : 0,
            ':anulada_el'       => $f['anulada_el'] ?? null,
            ':motivo_anulacion' => (string) ($f['motivo_anulacion'] ?? ''),
        ]);
        $nFac++;

        foreach (($f['lineas'] ?? []) as $i => $l) {
            $stFacLinea->execute([
                ':factura_numero' => (string) $f['numero'],
                ':orden'          => $i,
                ':sku'            => (string) ($l['sku'] ?? ''),
                ':nombre'         => (string) ($l['nombre'] ?? ''),
                ':marca'          => (string) ($l['marca'] ?? ''),
                ':precio'         => (int) ($l['precio'] ?? 0),
                ':costo'          => (int) ($l['costo'] ?? 0),
                ':cant'           => (int) ($l['cant'] ?? 1),
            ]);
            $nFacLineas++;
        }

        foreach ($cuotas as $i => $c) {
            $stFacCuota->execute([
                ':factura_numero' => (string) $f['numero'],
                ':orden'          => $i,
                ':monto'          => (int) ($c['monto'] ?? 0),
                ':vence'          => substr((string) ($c['vence'] ?? date('Y-m-d')), 0, 10),
            ]);
            $cuotaId = (int) $pdo->lastInsertId();
            $nFacCuotas++;

            foreach (($c['abonos'] ?? []) as $j => $a) {
                $stFacAbono->execute([
                    ':cuota_id' => $cuotaId,
                    ':orden'    => $j,
                    ':monto'    => (int) ($a['monto'] ?? 0),
                    ':fecha'    => (string) ($a['fecha'] ?? date('c')),
                ]);
                $nFacAbonos++;
            }
        }
    }
    log_linea("Facturas migradas: {$nFac} (líneas: {$nFacLineas}, cuotas: {$nFacCuotas}, abonos: {$nFacAbonos})");

    /* --- Pedidos + líneas --- */
    $stPed = $pdo->prepare(
        'INSERT INTO pedidos
            (numero, cliente, telefono, subtotal, total, plan, num_cuotas_preferido,
             estado, factura_numero, creado, confirmado_el, descartado_el, motivo_descartado)
         VALUES
            (:numero, :cliente, :telefono, :subtotal, :total, :plan, :num_cuotas_preferido,
             :estado, :factura_numero, :creado, :confirmado_el, :descartado_el, :motivo_descartado)'
    );
    $stPedLinea = $pdo->prepare(
        'INSERT INTO pedido_lineas (pedido_numero, orden, sku, nombre, marca, precio, cant)
         VALUES (:pedido_numero, :orden, :sku, :nombre, :marca, :precio, :cant)'
    );

    $nPed = 0;
    $nPedLineas = 0;

    foreach ($pedidosJson as $p) {
        $stPed->execute([
            ':numero'               => (string) $p['numero'],
            ':cliente'              => (string) ($p['cliente'] ?? ''),
            ':telefono'             => (string) ($p['telefono'] ?? ''),
            ':subtotal'             => (int) ($p['subtotal'] ?? 0),
            ':total'                => (int) ($p['total'] ?? 0),
            ':plan'                 => ($p['plan'] ?? 'contado') === 'cuotas' ? 'cuotas' : 'contado',
            ':num_cuotas_preferido' => isset($p['num_cuotas_preferido']) ? (int) $p['num_cuotas_preferido'] : null,
            ':estado'               => in_array($p['estado'] ?? '', ['pendiente', 'confirmado', 'descartado'], true)
                                            ? $p['estado'] : 'pendiente',
            ':factura_numero'       => $p['factura_numero'] ?? null,
            ':creado'               => (string) ($p['creado'] ?? date('c')),
            ':confirmado_el'        => $p['confirmado_el'] ?? null,
            ':descartado_el'        => $p['descartado_el'] ?? null,
            ':motivo_descartado'    => (string) ($p['motivo_descartado'] ?? ''),
        ]);
        $nPed++;

        foreach (($p['lineas'] ?? []) as $i => $l) {
            $stPedLinea->execute([
                ':pedido_numero' => (string) $p['numero'],
                ':orden'         => $i,
                ':sku'           => (string) ($l['sku'] ?? ''),
                ':nombre'        => (string) ($l['nombre'] ?? ''),
                ':marca'         => (string) ($l['marca'] ?? ''),
                ':precio'        => (int) ($l['precio'] ?? 0),
                ':cant'          => (int) ($l['cant'] ?? 1),
            ]);
            $nPedLineas++;
        }
    }
    log_linea("Pedidos migrados: {$nPed} (líneas: {$nPedLineas})");

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    log_linea('');
    log_linea('ERROR — no se guardó nada, la base quedó como estaba: ' . $e->getMessage());
    exit(1);
}

log_linea('');
log_linea('Listo. Migración completada sin errores.');
