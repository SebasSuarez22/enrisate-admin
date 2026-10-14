<?php
declare(strict_types=1);

require_once __DIR__ . '/formato.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cuotas.php';

/* =========================================================
   Clientes · almacén en MySQL (capa 3)
   =========================================================
   Misma lógica de negocio que la versión en JSON. Cada cambio guarda
   solo la fila de esa clienta (guardar_cliente); antes se reescribía la
   lista completa en cada cambio.
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

/** Valores de la fila `clientes` para una clienta (mismas reglas de siempre). */
function cliente_a_parametros(array $c): array
{
    return [
        ':id'          => (string) $c['id'],
        ':nombre'      => (string) $c['nombre'],
        ':telefono'    => (string) $c['telefono'],
        ':notas'       => (string) ($c['notas'] ?? ''),
        ':saldo_favor' => (int) ($c['saldo_favor'] ?? 0),
        ':creado'      => (string) ($c['creado'] ?? date('c')),
    ];
}

/**
 * Guarda UNA clienta: si es nueva la inserta (y falla si el id ya existiera, en
 * vez de pisar a otra); si ya existe, actualiza solo su fila. Las demás no se tocan.
 */
function guardar_cliente(array $c, bool $esNueva): void
{
    $sql = $esNueva
        ? 'INSERT INTO clientes (id, nombre, telefono, notas, saldo_favor, creado)
           VALUES (:id, :nombre, :telefono, :notas, :saldo_favor, :creado)'
        : 'UPDATE clientes SET nombre = :nombre, telefono = :telefono, notas = :notas,
                  saldo_favor = :saldo_favor, creado = :creado
           WHERE id = :id';
    db()->prepare($sql)->execute(cliente_a_parametros($c));
}

/** Agrega una clienta nueva a la lista. Devuelve '' si quedó bien, o un mensaje de error. */
function agregar_cliente(string $nombre, string $telefono, string $notas): string
{
    return con_bloqueo('clientes', function () use ($nombre, $telefono, $notas) {
        $nombre   = trim($nombre);
        $telefono = normalizar_telefono($telefono);
        $notas    = trim($notas);

        if ($nombre === '') {
            return 'Escribe el nombre completo de la clienta.';
        }

        guardar_cliente([
            'id'       => uniqid('cli_', true),
            'nombre'   => $nombre,
            'telefono' => $telefono,
            'notas'    => $notas,
            'creado'   => date('c'),
        ], true);

        return '';
    });
}

/** Actualiza nombre, teléfono, notas y saldo a favor de una clienta existente. Devuelve '' si quedó bien, o un mensaje de error. */
function actualizar_cliente(string $id, string $nombre, string $telefono, string $notas, int $saldoFavor = 0): string
{
    return con_bloqueo('clientes', function () use ($id, $nombre, $telefono, $notas, $saldoFavor) {
        $nombre   = trim($nombre);
        $telefono = normalizar_telefono($telefono);
        $notas    = trim($notas);

        if ($nombre === '') {
            return 'Escribe el nombre completo de la clienta.';
        }

        $c = cliente_por_id($id);
        if ($c === null) {
            return 'No encontré esa clienta.';
        }

        $c['nombre']      = $nombre;
        $c['telefono']    = $telefono;
        $c['notas']       = $notas;
        $c['saldo_favor'] = max(0, $saldoFavor);
        guardar_cliente($c, false);

        return '';
    });
}

/** Elimina varias clientas por id. Devuelve cuántas encontró y quitó. */
function eliminar_clientes(array $ids): int
{
    return con_bloqueo('clientes', function () use ($ids) {
        $ids = array_values(array_unique(array_filter($ids, fn($id) => $id !== '')));
        if (!$ids) {
            return 0;
        }

        $ids = array_map('strval', $ids);
        // Se buscan las filas pedidas y solo se borran las que coinciden EXACTO
        // (MySQL no distingue mayúsculas ni espacios al final; antes se comparaba exacto).
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT id FROM clientes WHERE id IN ($marcas)");
        $st->execute($ids);
        $exactas = array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN), fn($id) => in_array($id, $ids, true)));
        if (!$exactas) {
            return 0;
        }
        $marcas = implode(',', array_fill(0, count($exactas), '?'));
        db()->prepare("DELETE FROM clientes WHERE id IN ($marcas)")->execute($exactas);
        return count($exactas);
    });
}

/** Devuelve una clienta por su id, o null. */
function cliente_por_id(string $id): ?array
{
    $st = db()->prepare('SELECT id, nombre, telefono, notas, saldo_favor, creado FROM clientes WHERE id = ?');
    $st->execute([$id]);
    $fila = $st->fetch();
    // Mismo resultado que antes: solo el id idéntico (MySQL no distingue mayúsculas ni espacios).
    if (!$fila || (string) $fila['id'] !== $id) {
        return null;
    }
    return [
        'id'          => (string) $fila['id'],
        'nombre'      => (string) $fila['nombre'],
        'telefono'    => (string) $fila['telefono'],
        'notas'       => (string) $fila['notas'],
        'creado'      => (string) $fila['creado'],
        'saldo_favor' => (int) $fila['saldo_favor'],
    ];
}

/**
 * Descuenta un monto del saldo a favor de una clienta (al aplicarlo en una
 * factura). Nunca deja el saldo por debajo de 0. Devuelve lo que realmente
 * alcanzó a descontar.
 */
function restar_saldo_favor(string $id, int $monto): int
{
    return con_bloqueo('clientes', function () use ($id, $monto) {
        if ($monto <= 0) {
            return 0;
        }
        $c = cliente_por_id($id);
        if ($c === null) {
            return 0;
        }
        $disponible = (int) ($c['saldo_favor'] ?? 0);
        $usado      = max(0, min($disponible, $monto));
        if ($usado > 0) {
            $c['saldo_favor'] = $disponible - $usado;
            guardar_cliente($c, false);
        }
        return $usado;
    });
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
    con_bloqueo('clientes', function () use ($nombre, $telefono) {
        $nombre   = trim($nombre);
        $telefono = normalizar_telefono($telefono);

        if ($nombre === '') {
            return;
        }

        $clientes = leer_clientes();
        if (!cliente_ya_existe($clientes, $nombre, $telefono)) {
            agregar_cliente($nombre, $telefono, '');
        }
    });
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
    return estadisticas_de_facturas(facturas_propias_cliente($cliente, $facturas));
}

/**
 * Las facturas sin anular que son de la clienta: por teléfono, o por nombre
 * exacto, lo que primero coincida (el mismo criterio de siempre).
 */
function facturas_propias_cliente(array $cliente, array $facturas): array
{
    $telefono = (string) ($cliente['telefono'] ?? '');
    $nombre   = trim((string) ($cliente['nombre'] ?? ''));

    return array_values(array_filter($facturas, function (array $f) use ($telefono, $nombre): bool {
        if (!empty($f['anulada'])) {
            return false;
        }
        if ($telefono !== '' && normalizar_telefono((string) ($f['telefono'] ?? '')) === $telefono) {
            return true;
        }
        return strcasecmp(trim((string) ($f['cliente'] ?? '')), $nombre) === 0;
    }));
}

/**
 * El cálculo de estadisticas_compra_cliente() sobre facturas que YA son de la
 * clienta (sin anular). Aparte para que la API del bot, que identifica a la
 * clienta solo por teléfono, use exactamente la misma cuenta que el panel.
 * Devuelve null si no hay facturas.
 */
function estadisticas_de_facturas(array $propias): ?array
{
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
        'ultima_compra'   => date('Y-m-d', $fechas[$n - 1]),
    ];
}

/**
 * Lo que la clienta tiene por pagar en sus facturas a cuotas ($propias ya son
 * suyas y sin anular): el total que falta y si alguna cuota ya venció.
 *
 * @return array{total:int, vencida:bool}
 */
function cuotas_pendientes_cliente(array $propias): array
{
    $total   = 0;
    $vencida = false;
    foreach ($propias as $f) {
        if (($f['plan'] ?? '') !== 'cuotas') {
            continue;
        }
        foreach ($f['cuotas'] ?? [] as $cuota) {
            $falta = cuota_pendiente($cuota);
            if ($falta <= 0) {
                continue;
            }
            $total += $falta;
            if (esta_vencida((string) ($cuota['vence'] ?? ''))) {
                $vencida = true;
            }
        }
    }
    return ['total' => $total, 'vencida' => $vencida];
}

/** Segmentos en los que cae una clienta, en el orden en que se muestran. */
const SEGMENTOS_CLIENTE = [
    'rep' => ['Les toca reponer',     'Ya pasó el tiempo en que suelen volver a comprar'],
    'cuo' => ['Con cuota pendiente',  'Tienen cuotas por cobrar en Cartera'],
    'nue' => ['Nuevas',               'Una sola compra, o ninguna todavía'],
    'sal' => ['Con saldo a favor',    'Tienen saldo para descontar en su próxima factura'],
    'ina' => ['Inactivas',            'Más de 90 días sin comprar'],
    'sin' => ['Sin teléfono',         'No se les puede escribir por WhatsApp'],
];

/**
 * A qué segmentos pertenece una clienta. Sale de datos que el panel ya tiene
 * (nada nuevo que guardar). Una clienta puede estar en varios:
 *   rep  compra cada cierto tiempo y ya pasó ese tiempo desde su última compra
 *   cuo  le falta pagar alguna cuota
 *   nue  una sola compra, o ninguna
 *   sal  tiene saldo a favor
 *   ina  compró alguna vez, pero hace más de 90 días
 *   sin  no tiene teléfono
 * $hoy (Y-m-d) existe para poder probarlo con fechas fijas.
 *
 * @return list<string>
 */
function segmentos_cliente(array $cliente, ?array $stats, int $pendiente, ?string $hoy = null): array
{
    $hoy       = $hoy ?? date('Y-m-d');
    $compras   = (int) ($stats['compras'] ?? 0);
    $desde     = null;
    if ($stats && !empty($stats['ultima_compra'])) {
        $desde = (int) floor((strtotime($hoy) - strtotime((string) $stats['ultima_compra'])) / 86400);
    }
    $frecuencia = $stats['frecuencia_dias'] ?? null;

    $segmentos = [];
    if ($desde !== null && $frecuencia !== null && $desde >= max(1, (int) $frecuencia)) {
        $segmentos[] = 'rep';
    }
    if ($pendiente > 0) {
        $segmentos[] = 'cuo';
    }
    if ($compras <= 1) {
        $segmentos[] = 'nue';
    }
    if ((int) ($cliente['saldo_favor'] ?? 0) > 0) {
        $segmentos[] = 'sal';
    }
    if ($desde !== null && $desde > 90) {
        $segmentos[] = 'ina';
    }
    if (normalizar_telefono((string) ($cliente['telefono'] ?? '')) === '') {
        $segmentos[] = 'sin';
    }
    return $segmentos;
}

/** "Hoy", "Ayer", "Hace 12 días" o "Aún no compra", según los días desde la última compra. */
function texto_ultima_compra(?int $dias): string
{
    if ($dias === null) {
        return 'Aún no compra';
    }
    return $dias < 1 ? 'Hoy' : ($dias === 1 ? 'Ayer' : 'Hace ' . $dias . ' días');
}

/** Cómo va el pago de una factura: [clase, texto] para la etiqueta ("nue" verde, "rep" dorado, "cuo" rosa). */
function resumen_pago_factura(array $f): array
{
    if (($f['plan'] ?? '') !== 'cuotas') {
        return ['nue', 'Contado'];
    }
    $pendiente = false;
    $vencida   = false;
    foreach ($f['cuotas'] ?? [] as $cuota) {
        if (!cuota_esta_pagada($cuota)) {
            $pendiente = true;
            if (esta_vencida((string) ($cuota['vence'] ?? ''))) {
                $vencida = true;
            }
        }
    }
    if (!$pendiente) {
        return ['nue', 'Pagada'];
    }
    return $vencida ? ['cuo', 'Vencida'] : ['rep', 'Pendiente'];
}

/** Clave para ordenar nombres de la A a la Z sin que las tildes los desordenen (Ángela junto a Andrea). */
function clave_nombre_cliente(string $nombre): string
{
    $limpio = strtr(trim($nombre), [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
    ]);
    return mb_strtolower($limpio);
}

/** La letra bajo la que va una clienta en el directorio ("#" si no empieza por letra). */
function letra_cliente(string $nombre): string
{
    $primera = mb_strtoupper(mb_substr(clave_nombre_cliente($nombre), 0, 1));
    return preg_match('/^[A-Z]$/', $primera) === 1 ? $primera : '#';
}

/**
 * Todo lo que el directorio de Clientes (escritorio) necesita de cada clienta,
 * ya calculado: se manda al navegador como JSON y la ficha se arma con eso.
 * $clientes ya viene ordenada; $facturas es la lista completa (con anuladas).
 *
 * @return array<string, array<string, mixed>> por id de clienta
 */
function datos_directorio_clientes(array $clientes, array $facturas, ?string $hoy = null): array
{
    $hoy = $hoy ?? date('Y-m-d');
    $datos = [];
    foreach ($clientes as $c) {
        $propias = facturas_propias_cliente($c, $facturas);
        $stats   = estadisticas_de_facturas($propias);
        $debe    = cuotas_pendientes_cliente($propias);
        $saldo   = (int) ($c['saldo_favor'] ?? 0);
        $segs    = segmentos_cliente($c, $stats, $debe['total'], $hoy);
        $dias    = $stats ? (int) floor((strtotime($hoy) - strtotime((string) $stats['ultima_compra'])) / 86400) : null;
        $tiene   = normalizar_telefono((string) $c['telefono']) !== '';

        $ofertas = [];
        if ($stats && $tiene) {
            $oferta = function (string $tipo, string $texto) use (&$ofertas, $c, $stats, $hoy): void {
                $ofertas[] = ['t' => $texto, 'url' => enlace_whatsapp((string) $c['telefono'], mensaje_oferta_cliente($c, $stats, $tipo, $hoy))];
            };
            if ($stats['frecuencia_dias'] !== null) {
                $oferta('frecuencia', 'Ofrecerle reponer');
            } elseif ($stats['compras'] === 1) {
                $oferta('primera', 'Agradecer su primera compra');
            }
            if ($stats['favorito']) {
                $oferta('favorito', 'Ofrecerle su favorito');
            }
        }

        usort($propias, fn(array $a, array $b) => strcmp((string) $b['creada'], (string) $a['creada']));
        $recientes = array_map(function (array $f): array {
            [$clase, $texto] = resumen_pago_factura($f);
            return ['n' => (string) $f['numero'], 'f' => fecha_abreviada((string) ($f['fecha_pago'] ?? $f['creada'])), 't' => (int) $f['total'], 'c' => $clase, 'e' => $texto];
        }, array_slice($propias, 0, 3));

        if ($debe['total'] > 0) {
            $pista = 'Debe ' . pesos($debe['total']) . ($debe['vencida'] ? ' · vencida' : '');
        } elseif (in_array('rep', $segs, true)) {
            $pista = 'Le toca reponer';
        } elseif ($saldo > 0) {
            $pista = 'Saldo a favor ' . pesos($saldo);
        } elseif (!$tiene) {
            $pista = 'Sin teléfono';
        } else {
            $compras = (int) ($stats['compras'] ?? 0);
            $pista = $compras . ($compras === 1 ? ' compra' : ' compras');
        }

        $datos[(string) $c['id']] = [
            'id'        => (string) $c['id'],
            'nombre'    => (string) $c['nombre'],
            'telefono'  => (string) $c['telefono'],
            'notas'     => (string) $c['notas'],
            'saldo'     => $saldo,
            'compras'   => (int) ($stats['compras'] ?? 0),
            'frecuencia' => $stats['frecuencia_dias'] ?? null,
            'favorito'  => $stats['favorito'] ?? null,
            'ultima'    => texto_ultima_compra($dias),
            'debe'      => $debe['total'],
            'vencida'   => $debe['vencida'],
            'segs'      => $segs,
            'pista'     => $pista,
            'ofertas'   => $ofertas,
            'facturas'  => $recientes,
        ];
    }
    return $datos;
}

/**
 * Mensaje de WhatsApp con un ofrecimiento para una clienta, según el dato de
 * compras que se tocó en Clientes:
 *   'frecuencia'  → cada cuánto compra (y, con la fecha de su última compra,
 *                   si ya le toca reponer o todavía le falta)
 *   'favorito'    → su producto más comprado
 *   'primera'     → solo tiene una compra hasta ahora
 * No promete descuentos ni precios: solo ofrece apartar/enviar. Los textos
 * viven aquí para que se puedan cambiar en un solo lugar.
 * $hoy (Y-m-d) existe para poder probarlo con fechas fijas.
 */
function mensaje_oferta_cliente(array $cliente, array $stats, string $tipo, ?string $hoy = null): string
{
    $nombreCompleto = trim((string) ($cliente['nombre'] ?? ''));
    $primerNombre   = $nombreCompleto === '' ? '' : (string) strtok($nombreCompleto, ' ');
    $saludo   = 'Hola' . ($primerNombre !== '' ? ' ' . $primerNombre : '') . ' 💛';
    $favorito = (string) ($stats['favorito'] ?? '');
    $producto = $favorito !== '' ? $favorito : 'tus productos';

    if ($tipo === 'primera') {
        $msg  = $saludo . "\n";
        $msg .= 'Gracias por tu primera compra en Enrísate. ¿Cómo te fue'
              . ($favorito !== '' ? ' con tu ' . $favorito : '') . "?\n";
        $msg .= 'Cuando quieras repetirlo o probar algo nuevo, aquí estamos para ayudarte. ✨';
        return $msg;
    }

    if ($tipo === 'favorito') {
        $msg  = $saludo . "\n";
        $msg .= 'Sabemos que ' . $favorito . ' es de tus favoritos. ¿Quieres que te aparte uno?' . "\n";
        $msg .= 'Dime y te lo enviamos. ✨';
        return $msg;
    }

    // 'frecuencia'
    $frecuencia = $stats['frecuencia_dias'] ?? null;
    if ($frecuencia === null) {
        return mensaje_oferta_cliente($cliente, $stats, 'favorito', $hoy);
    }
    if ($frecuencia < 1) {
        $msg  = $saludo . "\n";
        $msg .= 'Gracias por ser de las clientas que más nos compran. ¿Qué te enviamos esta semana'
              . ($favorito !== '' ? '? ¿Repetimos tu ' . $favorito : '') . "?\n";
        $msg .= 'Escríbeme y te lo organizo. ✨';
        return $msg;
    }

    $hoyTs  = strtotime($hoy ?? date('Y-m-d'));
    $ultima = (string) ($stats['ultima_compra'] ?? '');
    $dias   = $ultima !== '' ? (int) floor(($hoyTs - strtotime($ultima)) / 86400) : null;

    $msg = $saludo . "\n";
    if ($dias !== null && $dias >= $frecuencia) {
        $msg .= 'Ya han pasado ' . $dias . ' días desde tu última compra y sueles reponer cada ~' . $frecuencia . ' días. ';
        $msg .= '¿Te preparo tu pedido de ' . $producto . "?\n";
    } else {
        $msg .= 'Sueles reponer cada ~' . $frecuencia . ' días, así que pronto te toca. ';
        $msg .= '¿Te aparto ' . $producto . ' para que no se agote?' . "\n";
    }
    $msg .= 'Escríbeme y te lo enviamos. ✨';
    return $msg;
}

/** Mete a Clientes las clientas de facturas viejas que todavía no estén ahí. Devuelve cuántas agregó. */
function sincronizar_clientes_desde_facturas(array $facturas): int
{
    return con_bloqueo('clientes', function () use ($facturas) {
        $clientes = leer_clientes();
        $agregadas = 0;
        $nuevas    = [];

        foreach ($facturas as $f) {
            $nombre   = trim((string) ($f['cliente'] ?? ''));
            $telefono = normalizar_telefono((string) ($f['telefono'] ?? ''));

            if ($nombre === '' || cliente_ya_existe($clientes, $nombre, $telefono)) {
                continue;
            }

            $nueva = [
                'id'       => uniqid('cli_', true),
                'nombre'   => $nombre,
                'telefono' => $telefono,
                'notas'    => '',
                'creado'   => $f['creada'] ?? date('c'),
            ];
            // Se agrega también a la lista en memoria, para que otra factura de la
            // misma clienta en este mismo lote no la duplique (igual que antes).
            $clientes[] = $nueva;
            $nuevas[]   = $nueva;
            $agregadas++;
        }

        // Solo se insertan las nuevas, todas juntas o ninguna.
        if ($nuevas) {
            en_transaccion(function () use ($nuevas): void {
                foreach ($nuevas as $c) {
                    guardar_cliente($c, true);
                }
            });
        }

        return $agregadas;
    });
}
