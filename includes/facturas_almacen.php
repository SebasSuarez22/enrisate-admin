<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cuotas.php';

/* =========================================================
   Facturas · lectura y escritura en MySQL (capa 3)
   =========================================================
   Cómo se leen y se guardan las facturas con sus líneas, cuotas y abonos:
   una lectura consistente (todas las tablas en una misma foto de la base),
   el guardado de UNA factura todo o nada, el candado que serializa las
   escrituras y el número que sigue. Las operaciones de negocio (abonar,
   anular, agregar productos...) están en facturas.php y usan esto.
   ========================================================= */

/**
 * Lee las facturas tal cual están guardadas, SIN migrar el formato viejo.
 * Las funciones que escriben usan leer_factura_raw() (solo la factura que tocan);
 * esta queda para herramientas que reescriben todo (guardar_facturas).
 */
function leer_facturas_raw(): array
{
    return leer_facturas_consistente(null);
}

/**
 * Una sola factura, tal cual está guardada (sin migrar formato viejo), o null.
 * Lee solo sus filas en vez de cargar todas las facturas: la usan factura_por_numero()
 * y, a través de ella, el PDF público, que antes cargaba la base entera en cada visita.
 */
function leer_factura_raw(string $numero): ?array
{
    $una = leer_facturas_consistente($numero);
    // MySQL compara sin distinguir mayúsculas ni espacios al final ('2026-0001 '
    // encontraría '2026-0001'); PHP siempre exigió el número exacto y se mantiene así.
    return ($una && $una[0]['numero'] === $numero) ? $una[0] : null;
}

/** Todas las facturas ($numero = null) o solo una, leídas en una misma foto de la base. */
function leer_facturas_consistente(?string $numero): array
{
    $pdo = db();

    // Las 4 consultas de abajo (facturas, líneas, abonos, cuotas) deben ver la
    // base en un mismo instante: sin esto, si otra persona guarda justo en
    // medio, se mezclan datos de antes y de después (una factura sin sus
    // abonos, por ejemplo). Dentro de una transacción todas ven la misma foto.
    $abrioTransaccion = !$pdo->inTransaction();
    if ($abrioTransaccion) {
        $pdo->beginTransaction();
    }
    try {
        return leer_facturas_en_transaccion($pdo, $numero);
    } finally {
        if ($abrioTransaccion && $pdo->inTransaction()) {
            $pdo->commit();
        }
    }
}

function leer_facturas_en_transaccion(PDO $pdo, ?string $numero = null): array
{
    // Con $numero, cada consulta se limita a esa factura; sin él, trae todo.
    // Las consultas son siempre preparadas: el número llega de la URL del PDF.
    $consulta = static function (string $sqlTodas, string $sqlUna) use ($pdo, $numero): array {
        if ($numero === null) {
            return $pdo->query($sqlTodas)->fetchAll();
        }
        $st = $pdo->prepare($sqlUna);
        $st->execute([$numero]);
        return $st->fetchAll();
    };

    $facturas = $consulta(
        'SELECT * FROM facturas ORDER BY numero',
        'SELECT * FROM facturas WHERE numero = ? ORDER BY numero'
    );

    if (!$facturas) {
        return [];
    }

    $lineasPorFactura = [];
    foreach ($consulta(
        'SELECT * FROM factura_lineas ORDER BY factura_numero, orden',
        'SELECT * FROM factura_lineas WHERE factura_numero = ? ORDER BY factura_numero, orden'
    ) as $l) {
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
    foreach ($consulta(
        'SELECT * FROM factura_abonos ORDER BY cuota_id, orden',
        'SELECT a.* FROM factura_abonos a JOIN factura_cuotas c ON c.id = a.cuota_id
         WHERE c.factura_numero = ? ORDER BY a.cuota_id, a.orden'
    ) as $a) {
        $abonosPorCuota[(int) $a['cuota_id']][] = [
            'monto'  => (int) $a['monto'],
            'fecha'  => (string) $a['fecha'],
            'tipo'   => (string) ($a['tipo'] ?? 'abono'),
            'motivo' => (string) ($a['motivo'] ?? ''),
        ];
    }

    $cuotasPorFactura = [];
    foreach ($consulta(
        'SELECT * FROM factura_cuotas ORDER BY factura_numero, orden',
        'SELECT * FROM factura_cuotas WHERE factura_numero = ? ORDER BY factura_numero, orden'
    ) as $c) {
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

/**
 * Ejecuta $fn con las facturas "reservadas": mientras corre, ninguna otra
 * petición puede escribir facturas. Cada escritura lee la factura, decide con
 * ella (¿cuánto falta de esta cuota?, ¿qué número sigue?) y la guarda con
 * guardar_factura(); si dos corren a la vez, la segunda decidiría con una copia
 * vieja y borraría lo que hizo la primera (un abono perdido, dos facturas con el
 * mismo número). Esperar turno lo evita.
 *
 * Usa el candado de MySQL (GET_LOCK): se libera solo si la petición termina o
 * se cae. Se puede anidar (registrar_abono llama a actualizar_factura), y solo
 * el nivel de afuera toma y suelta el candado. Si no consigue turno en 15 s
 * lanza un error en vez de guardar a ciegas.
 *
 * Toda función que LEA facturas para luego ESCRIBIRLAS debe hacer las dos
 * cosas dentro del mismo bloqueo. La mecánica está en con_bloqueo() (includes/db.php).
 */
function con_bloqueo_facturas(callable $fn): mixed
{
    return con_bloqueo('facturas', $fn);
}

/** Los valores de la fila de facturas, listos para el INSERT o el UPDATE. */
function factura_a_parametros(array $factura): array
{
    return [
        ':numero'           => (string) $factura['numero'],
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
    ];
}

/** Inserta la fila de UNA factura (falla si el número ya existe, en vez de pisar a otra). */
function insertar_fila_factura(PDO $pdo, array $factura): void
{
    $pdo->prepare(
        'INSERT INTO facturas
            (numero, cliente, telefono, nota, subtotal, descuento, total, costo, margen,
             plan, abono, saldo_aplicado, pagado, fecha_pago, creada,
             anulada, anulada_el, motivo_anulacion)
         VALUES
            (:numero, :cliente, :telefono, :nota, :subtotal, :descuento, :total, :costo, :margen,
             :plan, :abono, :saldo_aplicado, :pagado, :fecha_pago, :creada,
             :anulada, :anulada_el, :motivo_anulacion)'
    )->execute(factura_a_parametros($factura));
}

/**
 * Inserta las líneas, cuotas y abonos de UNA factura. Quien llama se asegura de
 * que no tenga ninguno guardado todavía (factura nueva, o los acaba de borrar).
 */
function insertar_detalle_factura(PDO $pdo, array $factura): void
{
    $numero  = (string) $factura['numero'];
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

/**
 * Guarda UNA factura con sus líneas, cuotas y abonos, todo o nada. Si es nueva la
 * inserta (y falla si el número ya existiera); si ya existe, actualiza su fila y
 * reescribe solo SU detalle (sus cuotas se borran y sus abonos caen con ellas por
 * ON DELETE CASCADE). Las demás facturas no se tocan.
 */
function guardar_factura(array $factura, bool $esNueva): void
{
    en_transaccion(function () use ($factura, $esNueva): void {
        $pdo = db();
        if ($esNueva) {
            insertar_fila_factura($pdo, $factura);
        } else {
            $pdo->prepare(
                'UPDATE facturas SET
                    cliente = :cliente, telefono = :telefono, nota = :nota, subtotal = :subtotal,
                    descuento = :descuento, total = :total, costo = :costo, margen = :margen,
                    plan = :plan, abono = :abono, saldo_aplicado = :saldo_aplicado, pagado = :pagado,
                    fecha_pago = :fecha_pago, creada = :creada, anulada = :anulada,
                    anulada_el = :anulada_el, motivo_anulacion = :motivo_anulacion
                 WHERE numero = :numero'
            )->execute(factura_a_parametros($factura));
            foreach (['factura_lineas', 'factura_cuotas'] as $tabla) {
                $pdo->prepare("DELETE FROM $tabla WHERE factura_numero = ?")->execute([(string) $factura['numero']]);
            }
        }
        insertar_detalle_factura($pdo, $factura);
    });
}

/**
 * Reemplaza TODAS las facturas en la base de datos. La app ya no lo usa (guarda de a
 * una con guardar_factura()); queda para herramientas que reescriben todo a propósito
 * (tools/normalizar_telefonos_existentes.php).
 */
function guardar_facturas(array $f): void
{
    en_transaccion(function () use ($f): void {
        $pdo = db();
        // Los ON DELETE CASCADE de factura_lineas/factura_cuotas/factura_abonos
        // se encargan de limpiar lo dependiente.
        $pdo->exec('DELETE FROM facturas');
        foreach ($f as $factura) {
            insertar_fila_factura($pdo, $factura);
            insertar_detalle_factura($pdo, $factura);
        }
    });
}

function siguiente_numero(): string
{
    // Solo los números, no las facturas completas con sus líneas, cuotas y abonos.
    // La regla es la de siempre: los 4 últimos caracteres, leídos por PHP.
    $max = 0;
    foreach (db()->query('SELECT numero FROM facturas')->fetchAll(PDO::FETCH_COLUMN) as $numero) {
        $n = (int) substr((string) $numero, -4);
        if ($n > $max) {
            $max = $n;
        }
    }
    return date('Y') . '-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
}

function factura_por_numero(string $numero): ?array
{
    $f = leer_factura_raw($numero);
    return $f === null ? null : normalizar_cuotas_factura($f);
}
