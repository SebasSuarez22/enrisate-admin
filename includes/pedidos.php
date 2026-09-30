<?php
declare(strict_types=1);

require_once __DIR__ . '/facturas.php';
require_once __DIR__ . '/red.php';

/* =========================================================
   Pedidos pendientes · lo que llega del catálogo público
   =========================================================
   Un "pedido" NUNCA es una factura. Es una propuesta que mandó la
   clienta desde catalogo-publico.php (sin login, sin CSRF de sesión):
   queda aquí guardada hasta que alguien del negocio la revisa en
   pedidos.php y decide "Confirmar y facturar" (que sí pasa por la
   validación completa de facturar.php) o "Descartar".

   Los pedidos y sus líneas ahora se guardan en MySQL (tablas pedidos
   y pedido_lineas) en vez de storage/pedidos.json. La lógica de
   negocio es la misma que antes.

   El límite de pedidos por IP (anti-spam) va en la tabla limite_pedidos,
   con la IP REAL del visitante (ver includes/red.php).
   ========================================================= */

function leer_pedidos(): array
{
    return leer_pedidos_donde(null, null);
}

/**
 * Pedidos (con sus líneas) filtrados por número exacto o por estado, o todos si
 * ambos son null. Solo lee las filas pedidas. Las dos consultas (pedidos y líneas)
 * van en una misma transacción para ver la base en un mismo instante.
 */
function leer_pedidos_donde(?string $numero, ?string $estado): array
{
    $pdo = db();

    $donde  = [];
    $params = [];
    if ($numero !== null) {
        $donde[]  = 'p.numero = ?';
        $params[] = $numero;
    }
    if ($estado !== null) {
        $donde[]  = 'p.estado = ?';
        $params[] = $estado;
    }
    $where = $donde ? ' WHERE ' . implode(' AND ', $donde) : '';

    $abrio = !$pdo->inTransaction();
    if ($abrio) {
        $pdo->beginTransaction();
    }
    try {
        $st = $pdo->prepare('SELECT p.* FROM pedidos p' . $where . ' ORDER BY p.numero');
        $st->execute($params);
        $pedidos = $st->fetchAll();
        if (!$pedidos) {
            return [];
        }

        $st = $pdo->prepare(
            'SELECT l.* FROM pedido_lineas l JOIN pedidos p ON p.numero = l.pedido_numero'
            . $where . ' ORDER BY l.pedido_numero, l.orden'
        );
        $st->execute($params);
        $lineasPorPedido = [];
        foreach ($st->fetchAll() as $l) {
            $lineasPorPedido[$l['pedido_numero']][] = [
                'sku'    => (string) $l['sku'],
                'nombre' => (string) $l['nombre'],
                'marca'  => (string) $l['marca'],
                'precio' => (int) $l['precio'],
                'cant'   => (int) $l['cant'],
            ];
        }
    } finally {
        if ($abrio && $pdo->inTransaction()) {
            $pdo->commit();
        }
    }

    $resultado = [];
    foreach ($pedidos as $p) {
        $numeroFila = (string) $p['numero'];

        $fila = [
            'cliente'  => (string) $p['cliente'],
            'telefono' => (string) $p['telefono'],
            'lineas'   => $lineasPorPedido[$numeroFila] ?? [],
            'subtotal' => (int) $p['subtotal'],
            'total'    => (int) $p['total'],
            'plan'     => (string) $p['plan'],
            'cuotas'   => [], // el pedido nunca trae cuotas calculadas, solo la preferencia
            'numero'   => $numeroFila,
            'creado'   => (string) $p['creado'],
            'estado'   => (string) $p['estado'],
        ];

        if ($p['num_cuotas_preferido'] !== null) {
            $fila['num_cuotas_preferido'] = (int) $p['num_cuotas_preferido'];
        }
        if ($p['factura_numero'] !== null) {
            $fila['factura_numero'] = (string) $p['factura_numero'];
        }
        if ($p['confirmado_el'] !== null) {
            $fila['confirmado_el'] = (string) $p['confirmado_el'];
        }
        if ($p['descartado_el'] !== null) {
            $fila['descartado_el'] = (string) $p['descartado_el'];
        }
        if ($p['motivo_descartado'] !== '') {
            $fila['motivo_descartado'] = (string) $p['motivo_descartado'];
        }

        $resultado[] = $fila;
    }

    return $resultado;
}

/** Valores de la fila `pedidos` para un pedido (mismas reglas de siempre). */
function pedido_a_parametros(array $p): array
{
    return [
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
    ];
}

/** Inserta las líneas de un pedido (las viejas de ese pedido ya deben estar borradas). */
function insertar_lineas_pedido(PDO $pdo, string $numero, array $lineas): void
{
    $st = $pdo->prepare(
        'INSERT INTO pedido_lineas (pedido_numero, orden, sku, nombre, marca, precio, cant)
         VALUES (:pedido_numero, :orden, :sku, :nombre, :marca, :precio, :cant)'
    );
    foreach (array_values($lineas) as $i => $l) {
        $st->execute([
            ':pedido_numero' => $numero,
            ':orden'         => $i,
            ':sku'           => (string) ($l['sku'] ?? ''),
            ':nombre'        => (string) ($l['nombre'] ?? ''),
            ':marca'         => (string) ($l['marca'] ?? ''),
            ':precio'        => (int) ($l['precio'] ?? 0),
            ':cant'          => (int) ($l['cant'] ?? 1),
        ]);
    }
}

/**
 * Guarda UN pedido: si es nuevo lo inserta (y falla si el número ya existiera,
 * en vez de pisar otro pedido); si ya existe, actualiza solo su fila y reescribe
 * solo sus líneas. Los demás pedidos no se tocan.
 */
function guardar_pedido(array $p, bool $esNuevo): void
{
    en_transaccion(function () use ($p, $esNuevo): void {
        $pdo    = db();
        $params = pedido_a_parametros($p);

        if ($esNuevo) {
            $pdo->prepare(
                'INSERT INTO pedidos
                    (numero, cliente, telefono, subtotal, total, plan, num_cuotas_preferido,
                     estado, factura_numero, creado, confirmado_el, descartado_el, motivo_descartado)
                 VALUES
                    (:numero, :cliente, :telefono, :subtotal, :total, :plan, :num_cuotas_preferido,
                     :estado, :factura_numero, :creado, :confirmado_el, :descartado_el, :motivo_descartado)'
            )->execute($params);
        } else {
            $pdo->prepare(
                'UPDATE pedidos SET
                    cliente = :cliente, telefono = :telefono, subtotal = :subtotal, total = :total,
                    plan = :plan, num_cuotas_preferido = :num_cuotas_preferido, estado = :estado,
                    factura_numero = :factura_numero, creado = :creado, confirmado_el = :confirmado_el,
                    descartado_el = :descartado_el, motivo_descartado = :motivo_descartado
                 WHERE numero = :numero'
            )->execute($params);
            $pdo->prepare('DELETE FROM pedido_lineas WHERE pedido_numero = ?')->execute([$params[':numero']]);
        }

        insertar_lineas_pedido($pdo, $params[':numero'], $p['lineas'] ?? []);
    });
}

/**
 * Reemplaza la lista COMPLETA de pedidos. La app ya no lo usa (guarda de a un pedido
 * con guardar_pedido()); queda para herramientas que reescriben todo a propósito
 * (tools/normalizar_telefonos_existentes.php).
 */
function guardar_pedidos(array $pedidos): void
{
    en_transaccion(function () use ($pedidos): void {
        $pdo = db();
        // pedido_lineas NO tiene borrado en cascada: se borran a mano primero.
        $pdo->exec('DELETE FROM pedido_lineas');
        $pdo->exec('DELETE FROM pedidos');

        $stPed = $pdo->prepare(
            'INSERT INTO pedidos
                (numero, cliente, telefono, subtotal, total, plan, num_cuotas_preferido,
                 estado, factura_numero, creado, confirmado_el, descartado_el, motivo_descartado)
             VALUES
                (:numero, :cliente, :telefono, :subtotal, :total, :plan, :num_cuotas_preferido,
                 :estado, :factura_numero, :creado, :confirmado_el, :descartado_el, :motivo_descartado)'
        );
        foreach ($pedidos as $p) {
            $stPed->execute(pedido_a_parametros($p));
            insertar_lineas_pedido($pdo, (string) $p['numero'], $p['lineas'] ?? []);
        }
    });
}

/** Numeración propia (P-2026-0001...), separada de las facturas para no confundirlas. */
function siguiente_numero_pedido(): string
{
    // Misma regla de siempre (el mayor de los 4 últimos dígitos, más uno), pero
    // calculada en MySQL en vez de cargar todos los pedidos.
    $max = (int) db()->query('SELECT COALESCE(MAX(CAST(RIGHT(numero, 4) AS UNSIGNED)), 0) FROM pedidos')->fetchColumn();
    return 'P-' . date('Y') . '-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
}

function pedido_por_numero(string $numero): ?array
{
    $uno = leer_pedidos_donde($numero, null);
    // MySQL compara sin distinguir mayúsculas ni espacios al final; se exige el
    // número exacto, como siempre.
    return ($uno && $uno[0]['numero'] === $numero) ? $uno[0] : null;
}

/** Guarda un pedido nuevo (estado 'pendiente') y devuelve su número. */
function crear_pedido(array $datos): string
{
    // El candado sigue para que dos pedidos simultáneos no reciban el mismo número.
    return con_bloqueo('pedidos', function () use ($datos) {
        $numero = siguiente_numero_pedido();

        $datos['numero'] = $numero;
        $datos['creado'] = date('c');
        $datos['estado'] = 'pendiente';

        guardar_pedido($datos, true);

        return $numero;
    });
}

function actualizar_pedido(string $numero, array $cambios): bool
{
    return con_bloqueo('pedidos', function () use ($numero, $cambios) {
        $p = pedido_por_numero($numero);
        if ($p === null) {
            return false;
        }
        // El número nunca cambia: es la llave de la fila.
        guardar_pedido(array_merge($p, $cambios, ['numero' => $numero]), false);
        return true;
    });
}

/** Marca un pedido pendiente como confirmado y lo enlaza con la factura ya creada. */
function marcar_pedido_confirmado(string $numeroPedido, string $numeroFactura): bool
{
    return actualizar_pedido($numeroPedido, [
        'estado'         => 'confirmado',
        'factura_numero' => $numeroFactura,
        'confirmado_el'  => date('c'),
    ]);
}

/** Descarta un pedido (la clienta no contestó, cambió de opinión, pedido de prueba, etc.). */
function descartar_pedido(string $numero, string $motivo = ''): bool
{
    return con_bloqueo('pedidos', function () use ($numero, $motivo) {
        $p = pedido_por_numero($numero);
        if (!$p || ($p['estado'] ?? '') !== 'pendiente') {
            return false;
        }
        return actualizar_pedido($numero, [
            'estado'            => 'descartado',
            'descartado_el'     => date('c'),
            'motivo_descartado' => trim($motivo),
        ]);
    });
}

function pedidos_pendientes(): array
{
    // MySQL compara sin distinguir mayúsculas ni espacios: se vuelve a exigir el
    // estado exacto, como siempre.
    $pendientes = array_values(array_filter(
        leer_pedidos_donde(null, 'pendiente'),
        fn($p) => ($p['estado'] ?? '') === 'pendiente'
    ));
    usort($pendientes, fn($a, $b) => ($b['creado'] ?? '') <=> ($a['creado'] ?? ''));
    return $pendientes;
}

function pedidos_pendientes_count(): int
{
    return count(pedidos_pendientes());
}

/* =========================================================
   Límite simple por IP (anti-spam del formulario público)
   ========================================================= */

/** true si la IP real de quien visita ya mandó demasiados pedidos en la última hora. */
function pedido_ip_bloqueada(): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM limite_pedidos WHERE ip_clave = ? AND momento > ?');
    $st->execute([clave_limite_ip(ip_visitante()), time() - 3600]);
    return (int) $st->fetchColumn() >= MAX_PEDIDOS_POR_HORA;
}

/** Registra un pedido aceptado de esta IP (para el límite por hora) y borra lo de hace más de una hora. */
function registrar_pedido_ip(): void
{
    $pdo = db();
    $pdo->prepare('INSERT INTO limite_pedidos (ip_clave, momento) VALUES (?, ?)')
        ->execute([clave_limite_ip(ip_visitante()), time()]);
    $pdo->prepare('DELETE FROM limite_pedidos WHERE momento <= ?')->execute([time() - 3600]);
}
