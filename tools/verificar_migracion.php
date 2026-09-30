<?php
declare(strict_types=1);

// Solo desde la terminal: si alguien la abre desde el navegador, no existe.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/* Script de verificación TEMPORAL (no forma parte de la app):
   compara lo que devuelven las funciones nuevas (leídas de MySQL) contra
   los archivos JSON originales, campo por campo. Se borra al final del
   proceso de migración: no hace falta dejarlo en el proyecto. */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/facturas.php';
require_once __DIR__ . '/../includes/clientes.php';
require_once __DIR__ . '/../includes/pedidos.php';

function j(string $ruta): array
{
    $d = json_decode((string) file_get_contents($ruta), true);
    return is_array($d) ? $d : [];
}

$errores = 0;

function chk(bool $cond, string $msg): void
{
    global $errores;
    if (!$cond) {
        echo "FALLO: $msg\n";
        $errores++;
    } else {
        echo "ok: $msg\n";
    }
}

/* --- Catálogo --- */
$catJson = j(STORAGE_DIR . '/catalogo.json');
$catDb   = catalogo();
chk(count($catJson) === count($catDb), 'catalogo: mismo número de productos (' . count($catJson) . ' vs ' . count($catDb) . ')');

$catJsonPorSku = [];
foreach ($catJson as $p) {
    $catJsonPorSku[$p['sku']] = $p;
}
$difProd = 0;
foreach ($catDb as $p) {
    $orig = $catJsonPorSku[$p['sku']] ?? null;
    if (!$orig || (int)$orig['precio'] !== $p['precio'] || (int)$orig['costo'] !== $p['costo']
        || $orig['marca'] !== $p['marca'] || $orig['nombre'] !== $p['nombre']
        || (int)($orig['stock'] ?? 0) !== $p['stock']) {
        $difProd++;
    }
}
chk($difProd === 0, "catalogo: todos los productos coinciden en precio/costo/marca/nombre/stock ($difProd diferencias)");

/* --- Clientes --- */
$cliJson = j(STORAGE_DIR . '/clientes.json');
$cliDb   = leer_clientes();
chk(count($cliJson) === count($cliDb), 'clientes: mismo número (' . count($cliJson) . ' vs ' . count($cliDb) . ')');

$cliJsonPorId = [];
foreach ($cliJson as $c) {
    $cliJsonPorId[$c['id']] = $c;
}
$difCli = 0;
foreach ($cliDb as $c) {
    $orig = $cliJsonPorId[$c['id']] ?? null;
    if (!$orig || $orig['nombre'] !== $c['nombre'] || $orig['telefono'] !== $c['telefono']
        || (int)($orig['saldo_favor'] ?? 0) !== $c['saldo_favor']) {
        $difCli++;
    }
}
chk($difCli === 0, "clientes: todos coinciden en nombre/telefono/saldo_favor ($difCli diferencias)");

/* --- Facturas --- */
$facJson = j(STORAGE_DIR . '/facturas.json');
$facDb   = leer_facturas();
chk(count($facJson) === count($facDb), 'facturas: mismo número (' . count($facJson) . ' vs ' . count($facDb) . ')');

chk(
    array_sum(array_column($facJson, 'total')) === array_sum(array_column($facDb, 'total')),
    'facturas: suma de "total" coincide (' . array_sum(array_column($facJson, 'total')) . ')'
);
chk(
    array_sum(array_column($facJson, 'pagado')) === array_sum(array_column($facDb, 'pagado')),
    'facturas: suma de "pagado" coincide (' . array_sum(array_column($facJson, 'pagado')) . ')'
);
chk(
    array_sum(array_column($facJson, 'margen')) === array_sum(array_column($facDb, 'margen')),
    'facturas: suma de "margen" coincide (' . array_sum(array_column($facJson, 'margen')) . ')'
);

$facJsonPorNumero = [];
foreach ($facJson as $f) {
    $facJsonPorNumero[$f['numero']] = $f;
}
$difFac = 0;
$difLineas = 0;
$difCuotas = 0;
foreach ($facDb as $f) {
    $orig = $facJsonPorNumero[$f['numero']] ?? null;
    if (!$orig) {
        $difFac++;
        continue;
    }
    if ((int)$orig['total'] !== $f['total'] || (int)$orig['pagado'] !== $f['pagado']
        || (bool)($orig['anulada'] ?? false) !== $f['anulada']
        || $orig['cliente'] !== $f['cliente'] || ($orig['plan'] ?? 'contado') !== $f['plan']) {
        $difFac++;
    }
    if (count($orig['lineas'] ?? []) !== count($f['lineas'])) {
        $difLineas++;
    }
    if (count($orig['cuotas'] ?? []) !== count($f['cuotas'])) {
        $difCuotas++;
    } else {
        foreach ($f['cuotas'] as $i => $c) {
            $co = $orig['cuotas'][$i];
            if ((int)$co['monto'] !== $c['monto'] || count($co['abonos'] ?? []) !== count($c['abonos'])) {
                $difCuotas++;
            }
        }
    }
}
chk($difFac === 0, "facturas: todas coinciden en campos clave ($difFac diferencias)");
chk($difLineas === 0, "facturas: todas coinciden en número de líneas ($difLineas diferencias)");
chk($difCuotas === 0, "facturas: todas coinciden en cuotas/abonos ($difCuotas diferencias)");

/* --- Pedidos --- */
$pedJson = j(STORAGE_DIR . '/pedidos.json');
$pedDb   = leer_pedidos();
chk(count($pedJson) === count($pedDb), 'pedidos: mismo número (' . count($pedJson) . ' vs ' . count($pedDb) . ')');

$pedJsonPorNumero = [];
foreach ($pedJson as $p) {
    $pedJsonPorNumero[$p['numero']] = $p;
}
$difPed = 0;
foreach ($pedDb as $p) {
    $orig = $pedJsonPorNumero[$p['numero']] ?? null;
    if (!$orig || $orig['estado'] !== $p['estado'] || (int)$orig['total'] !== $p['total']
        || count($orig['lineas'] ?? []) !== count($p['lineas'])) {
        $difPed++;
    }
}
chk($difPed === 0, "pedidos: todos coinciden en estado/total/lineas ($difPed diferencias)");

/* --- Métricas del panel (cálculo agregado que usa dashboard.php) --- */
$metricas = metricas_panel();
echo "\nmetricas_panel(): " . json_encode($metricas) . "\n";

echo "\n" . ($errores === 0 ? "TODO OK — $errores fallos." : "HAY $errores FALLOS.") . "\n";
exit($errores === 0 ? 0 : 1);
