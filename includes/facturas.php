<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/categorias.php';

/* =========================================================
   Facturas y catálogo · almacén en MySQL (capa 3)
   =========================================================
   Toda la lógica de negocio de este archivo es la misma que tenía la
   versión en JSON. Lo único que cambió son las funciones que leen y
   escriben el almacenamiento (catalogo_cache/guardar_catalogo y
   leer_facturas_raw/guardar_facturas): antes tocaban storage/*.json,
   ahora hablan con MySQL a través de includes/db.php.
   ========================================================= */

/**
 * ¿La tabla productos ya tiene la columna `categoria`?
 *
 * La agrega storage/actualizar-2026-08-23-categorias.sql. Mientras no se
 * haya importado, todo sigue funcionando: el catálogo deduce la categoría
 * del nombre del producto. Sin esta comprobación, la página se caía con
 * "Unknown column 'categoria'" hasta importar el .sql.
 */
function productos_tiene_categoria(): bool
{
    static $tiene = null;

    if ($tiene === null) {
        try {
            $tiene = (bool) db()->query("SHOW COLUMNS FROM productos LIKE 'categoria'")->fetch();
        } catch (Throwable $e) {
            $tiene = false;
        }
    }

    return $tiene;
}

/**
 * Devuelve una referencia a la caché en memoria del catálogo, para que
 * catalogo() y guardar_catalogo() siempre vean el mismo estado dentro
 * de una misma petición.
 */
function &catalogo_cache(): array
{
    static $cat = null;

    if ($cat === null) {
        $columnaCategoria = productos_tiene_categoria() ? 'categoria, ' : '';
        $filas = db()->query(
            'SELECT sku, marca, nombre, ' . $columnaCategoria . 'precio, costo, stock
             FROM productos ORDER BY marca, nombre'
        )->fetchAll();

        if ($filas) {
            $cat = array_map(static fn(array $p): array => [
                'sku'    => (string) $p['sku'],
                'marca'  => (string) $p['marca'],
                'nombre' => (string) $p['nombre'],
                'categoria' => (string) ($p['categoria'] ?? ''),
                'precio' => (int) $p['precio'],
                'costo'  => (int) $p['costo'],
                'stock'  => (int) $p['stock'],
            ], $filas);
        } else {
            // Primera vez (tabla vacía): siembra el catálogo editable desde el archivo base.
            $semilla = require BASE_DIR . '/datos/catalogo.php';
            foreach ($semilla as &$p) {
                $p['stock'] = $p['stock'] ?? 0;
                $p['categoria'] = $p['categoria'] ?? categoria_deducida((string) $p['nombre']);
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

/** Reemplaza el catálogo completo en la base de datos (igual que antes reemplazaba el archivo entero). */
function guardar_catalogo(array $productos): void
{
    $productos = array_values($productos);
    $pdo       = db();

    $pdo->beginTransaction();
    try {
        $conCategoria = productos_tiene_categoria();

        $pdo->exec('DELETE FROM productos');
        $st = $pdo->prepare($conCategoria
            ? 'INSERT INTO productos (sku, marca, nombre, categoria, precio, costo, stock)
               VALUES (:sku, :marca, :nombre, :categoria, :precio, :costo, :stock)'
            : 'INSERT INTO productos (sku, marca, nombre, precio, costo, stock)
               VALUES (:sku, :marca, :nombre, :precio, :costo, :stock)'
        );
        foreach ($productos as $p) {
            $fila = [
                ':sku'    => (string) $p['sku'],
                ':marca'  => (string) $p['marca'],
                ':nombre' => (string) $p['nombre'],
                ':precio' => (int) $p['precio'],
                ':costo'  => (int) $p['costo'],
                ':stock'  => (int) ($p['stock'] ?? 0),
            ];
            if ($conCategoria) {
                $fila[':categoria'] = (string) ($p['categoria'] ?? '');
            }
            $st->execute($fila);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

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
function agregar_producto(string $sku, string $marca, string $nombre, int $precio, int $costo, string $categoria = ''): string
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
    $categoria = trim($categoria);
    if (!categoria_valida($categoria)) {
        return 'Esa categoría no existe.';
    }
    if ($categoria === '') {
        $categoria = categoria_deducida($nombre);
    }

    $productos   = catalogo();
    $productos[] = [
        'sku'       => $sku,
        'marca'     => $marca,
        'nombre'    => $nombre,
        'categoria' => $categoria,
        'precio'    => $precio,
        'costo'     => $costo,
        'stock'     => 0,
    ];
    // El SKU, la marca y el nombre no tienen límite de largo validado
    // arriba (el esquema real vive fuera del repo, en storage/esquema.sql,
    // que no se versiona). Si algo llega más largo de lo que la columna
    // acepta, MySQL rechaza el INSERT con una excepción — sin este catch
    // eso tumbaba la página entera con la traza de PHP a la vista, en vez
    // de un aviso normal.
    try {
        guardar_catalogo($productos);
    } catch (PDOException $e) {
        return 'No se pudo guardar: revisa que el SKU, la marca y el nombre no sean demasiado largos.';
    }

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
function actualizar_producto(string $sku, string $marca, string $nombre, ?string $categoria = null): bool
{
    $marca  = trim($marca);
    $nombre = trim($nombre);
    if ($marca === '' || $nombre === '') {
        return false;
    }
    if ($categoria !== null) {
        $categoria = trim($categoria);
        if (!categoria_valida($categoria)) {
            return false;
        }
    }

    $productos = catalogo();
    $hallado   = false;
    foreach ($productos as &$p) {
        if ($p['sku'] === $sku) {
            $p['marca']  = $marca;
            $p['nombre'] = $nombre;
            if ($categoria !== null) {
                $p['categoria'] = $categoria;
            }
            $hallado     = true;
            break;
        }
    }
    unset($p);
    if ($hallado) {
        // Mismo caso que en agregar_producto(): un nombre o marca más
        // largo de lo que la columna acepta tumbaba la página con la
        // traza de PHP en vez de un aviso. Al editar no hay un mensaje
        // específico que devolver (la función solo entrega bool), así
        // que se trata igual que "no se pudo": el llamador ya muestra un
        // aviso genérico para ese caso.
        try {
            guardar_catalogo($productos);
        } catch (PDOException $e) {
            return false;
        }
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

/**
 * Lee las facturas tal cual están guardadas, SIN migrar el formato viejo.
 * Uso interno de las funciones que escriben (crear_factura, actualizar_factura),
 * para que nunca se reescriba con datos migrados el registro de una factura
 * que no se está tocando.
 */
function leer_facturas_raw(): array
{
    $pdo = db();

    $facturas = $pdo->query(
        'SELECT * FROM facturas ORDER BY numero'
    )->fetchAll();

    if (!$facturas) {
        return [];
    }

    $lineasPorFactura = [];
    foreach ($pdo->query('SELECT * FROM factura_lineas ORDER BY factura_numero, orden')->fetchAll() as $l) {
        $lineasPorFactura[$l['factura_numero']][] = [
            'sku'    => (string) $l['sku'],
            'nombre' => (string) $l['nombre'],
            'marca'  => (string) $l['marca'],
            'precio' => (int) $l['precio'],
            'costo'  => (int) $l['costo'],
            'cant'   => (int) $l['cant'],
        ];
    }

    $abonosPorCuota = [];
    foreach ($pdo->query('SELECT * FROM factura_abonos ORDER BY cuota_id, orden')->fetchAll() as $a) {
        $abonosPorCuota[(int) $a['cuota_id']][] = [
            'monto'  => (int) $a['monto'],
            'fecha'  => (string) $a['fecha'],
            'tipo'   => (string) ($a['tipo'] ?? 'abono'),
            'motivo' => (string) ($a['motivo'] ?? ''),
        ];
    }

    $cuotasPorFactura = [];
    foreach ($pdo->query('SELECT * FROM factura_cuotas ORDER BY factura_numero, orden')->fetchAll() as $c) {
        $cuotasPorFactura[$c['factura_numero']][] = [
            'monto'  => (int) $c['monto'],
            'vence'  => (string) $c['vence'],
            'abonos' => $abonosPorCuota[(int) $c['id']] ?? [],
        ];
    }

    $resultado = [];
    foreach ($facturas as $f) {
        $numero = (string) $f['numero'];
        $resultado[] = [
            'cliente'          => (string) $f['cliente'],
            'telefono'         => (string) $f['telefono'],
            'nota'             => (string) $f['nota'],
            'lineas'           => $lineasPorFactura[$numero] ?? [],
            'subtotal'         => (int) $f['subtotal'],
            'descuento'        => (int) $f['descuento'],
            'total'            => (int) $f['total'],
            'costo'            => (int) $f['costo'],
            'margen'           => (int) $f['margen'],
            'plan'             => (string) $f['plan'],
            'abono'            => (int) $f['abono'],
            'saldo_aplicado'   => (int) $f['saldo_aplicado'],
            'cuotas'           => $cuotasPorFactura[$numero] ?? [],
            'pagado'           => (int) $f['pagado'],
            'fecha_pago'       => $f['fecha_pago'],
            'numero'           => $numero,
            'creada'           => (string) $f['creada'],
            'anulada'          => (bool) $f['anulada'],
            'anulada_el'       => $f['anulada_el'],
            'motivo_anulacion' => (string) $f['motivo_anulacion'],
        ];
    }

    return $resultado;
}

function leer_facturas(): array
{
    return array_map('normalizar_cuotas_factura', leer_facturas_raw());
}

/** Reemplaza todas las facturas en la base de datos (igual que antes reemplazaba el archivo entero). */
function guardar_facturas(array $f): void
{
    $pdo = db();

    $pdo->beginTransaction();
    try {
        // Igual que antes con el archivo completo: se reconstruye todo desde cero
        // a partir del array en memoria. Los ON DELETE CASCADE de factura_lineas/
        // factura_cuotas/factura_abonos se encargan de limpiar lo dependiente.
        $pdo->exec('DELETE FROM facturas');

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
        $stLinea = $pdo->prepare(
            'INSERT INTO factura_lineas (factura_numero, orden, sku, nombre, marca, precio, costo, cant)
             VALUES (:factura_numero, :orden, :sku, :nombre, :marca, :precio, :costo, :cant)'
        );
        $stCuota = $pdo->prepare(
            'INSERT INTO factura_cuotas (factura_numero, orden, monto, vence)
             VALUES (:factura_numero, :orden, :monto, :vence)'
        );
        $stAbono = $pdo->prepare(
            'INSERT INTO factura_abonos (cuota_id, orden, monto, fecha, tipo, motivo)
             VALUES (:cuota_id, :orden, :monto, :fecha, :tipo, :motivo)'
        );

        foreach ($f as $factura) {
            $numero = (string) $factura['numero'];

            $stFac->execute([
                ':numero'           => $numero,
                ':cliente'          => (string) ($factura['cliente'] ?? ''),
                ':telefono'         => (string) ($factura['telefono'] ?? ''),
                ':nota'             => (string) ($factura['nota'] ?? ''),
                ':subtotal'         => (int) ($factura['subtotal'] ?? 0),
                ':descuento'        => (int) ($factura['descuento'] ?? 0),
                ':total'            => (int) ($factura['total'] ?? 0),
                ':costo'            => (int) ($factura['costo'] ?? 0),
                ':margen'           => (int) ($factura['margen'] ?? 0),
                ':plan'             => ($factura['plan'] ?? 'contado') === 'cuotas' ? 'cuotas' : 'contado',
                ':abono'            => (int) ($factura['abono'] ?? 0),
                ':saldo_aplicado'   => (int) ($factura['saldo_aplicado'] ?? 0),
                ':pagado'           => (int) ($factura['pagado'] ?? 0),
                ':fecha_pago'       => !empty($factura['fecha_pago']) ? substr((string) $factura['fecha_pago'], 0, 10) : null,
                ':creada'           => (string) ($factura['creada'] ?? date('c')),
                ':anulada'          => !empty($factura['anulada']) ? 1 : 0,
                ':anulada_el'       => $factura['anulada_el'] ?? null,
                ':motivo_anulacion' => (string) ($factura['motivo_anulacion'] ?? ''),
            ]);

            foreach (($factura['lineas'] ?? []) as $i => $l) {
                $stLinea->execute([
                    ':factura_numero' => $numero,
                    ':orden'          => $i,
                    ':sku'            => (string) ($l['sku'] ?? ''),
                    ':nombre'         => (string) ($l['nombre'] ?? ''),
                    ':marca'          => (string) ($l['marca'] ?? ''),
                    ':precio'         => (int) ($l['precio'] ?? 0),
                    ':costo'          => (int) ($l['costo'] ?? 0),
                    ':cant'           => (int) ($l['cant'] ?? 1),
                ]);
            }

            foreach (($factura['cuotas'] ?? []) as $i => $c) {
                $stCuota->execute([
                    ':factura_numero' => $numero,
                    ':orden'          => $i,
                    ':monto'          => (int) ($c['monto'] ?? 0),
                    ':vence'          => substr((string) ($c['vence'] ?? date('Y-m-d')), 0, 10),
                ]);
                $cuotaId = (int) $pdo->lastInsertId();

                foreach (($c['abonos'] ?? []) as $j => $a) {
                    $stAbono->execute([
                        ':cuota_id' => $cuotaId,
                        ':orden'    => $j,
                        ':monto'    => (int) ($a['monto'] ?? 0),
                        ':fecha'    => (string) ($a['fecha'] ?? date('c')),
                        ':tipo'     => ($a['tipo'] ?? 'abono') === 'descuento' ? 'descuento' : 'abono',
                        ':motivo'   => (string) ($a['motivo'] ?? ''),
                    ]);
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
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
        'monto'  => $monto,
        'fecha'  => date('c'),
        'tipo'   => 'abono',
        'motivo' => '',
    ];

    return actualizar_factura($numero, [
        'cuotas' => $f['cuotas'],
        'pagado' => total_pagado_cuotas($f),
    ]);
}

/**
 * Perdona (descuenta) un monto de lo pendiente de una cuota específica.
 * Es igual a registrar_abono() en las validaciones — misma factura activa
 * a plazos, mismo tope del monto — pero NO es plata recibida: se marca
 * como 'descuento' para no mezclarse con lo realmente cobrado en efectivo
 * si en algún momento se arma un reporte de caja. Por eso exige un motivo.
 */
function aplicar_descuento_cuota(string $numero, int $indiceCuota, int $monto, string $motivo): bool
{
    $motivo = trim($motivo);
    if ($motivo === '') {
        return false;
    }

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
        'monto'  => $monto,
        'fecha'  => date('c'),
        'tipo'   => 'descuento',
        'motivo' => $motivo,
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

/**
 * Arma el link de WhatsApp (wa.me) para un teléfono y un mensaje ya
 * redactado. Si el teléfono está vacío, devuelve un link sin número
 * (comportamiento existente). Se normaliza primero (quita el 57 si ya lo
 * traía) para no duplicar el indicativo — antes, un teléfono guardado con
 * "57" adelante producía un link roto.
 */
function enlace_whatsapp(string $telefono, string $mensaje): string
{
    $telefono = normalizar_telefono($telefono);
    return 'https://wa.me/' . ($telefono !== '' ? '57' . $telefono : '')
        . '?text=' . rawurlencode($mensaje);
}
