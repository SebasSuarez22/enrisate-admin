<?php
declare(strict_types=1);

require_once __DIR__ . '/facturas.php';

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

   El límite de pedidos por IP (anti-spam) sigue en storage/pedidos_intentos.json:
   es una caché de seguridad efímera, no un dato del negocio, así que no
   hacía falta llevarla a la base de datos.
   ========================================================= */

function leer_pedidos(): array
{
    $pdo = db();

    $pedidos = $pdo->query('SELECT * FROM pedidos ORDER BY numero')->fetchAll();
    if (!$pedidos) {
        return [];
    }

    $lineasPorPedido = [];
    foreach ($pdo->query('SELECT * FROM pedido_lineas ORDER BY pedido_numero, orden')->fetchAll() as $l) {
        $lineasPorPedido[$l['pedido_numero']][] = [
            'sku'    => (string) $l['sku'],
            'nombre' => (string) $l['nombre'],
            'marca'  => (string) $l['marca'],
            'precio' => (int) $l['precio'],
            'cant'   => (int) $l['cant'],
        ];
    }

    $resultado = [];
    foreach ($pedidos as $p) {
        $numero = (string) $p['numero'];

        $fila = [
            'cliente'  => (string) $p['cliente'],
            'telefono' => (string) $p['telefono'],
            'lineas'   => $lineasPorPedido[$numero] ?? [],
            'subtotal' => (int) $p['subtotal'],
            'total'    => (int) $p['total'],
            'plan'     => (string) $p['plan'],
            'cuotas'   => [], // el pedido nunca trae cuotas calculadas, solo la preferencia
            'numero'   => $numero,
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

/** Reemplaza la lista completa de pedidos en la base de datos (igual que antes reemplazaba el archivo entero). */
function guardar_pedidos(array $pedidos): void
{
    $pdo = db();

    $propia = !$pdo->inTransaction(); // dentro de en_transaccion() se une a la de afuera
    if ($propia) {
        $pdo->beginTransaction();
    }
    try {
        // pedido_lineas NO tiene borrado en cascada (a diferencia de las tablas de
        // facturas): sin borrarlas a mano primero, al reinsertar las líneas de un
        // pedido que ya existía chocan con las viejas (clave única pedido_numero+orden)
        // y desde el segundo pedido con productos ya no se podía guardar nada.
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
        $stLinea = $pdo->prepare(
            'INSERT INTO pedido_lineas (pedido_numero, orden, sku, nombre, marca, precio, cant)
             VALUES (:pedido_numero, :orden, :sku, :nombre, :marca, :precio, :cant)'
        );

        foreach ($pedidos as $p) {
            $numero = (string) $p['numero'];

            $stPed->execute([
                ':numero'               => $numero,
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

            foreach (($p['lineas'] ?? []) as $i => $l) {
                $stLinea->execute([
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

        if ($propia) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($propia && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Numeración propia (P-2026-0001...), separada de las facturas para no confundirlas. */
function siguiente_numero_pedido(): string
{
    $pedidos = leer_pedidos();
    $max     = 0;
    foreach ($pedidos as $p) {
        $n = (int) substr((string) $p['numero'], -4);
        if ($n > $max) {
            $max = $n;
        }
    }
    return 'P-' . date('Y') . '-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
}

function pedido_por_numero(string $numero): ?array
{
    foreach (leer_pedidos() as $p) {
        if ($p['numero'] === $numero) {
            return $p;
        }
    }
    return null;
}

/** Guarda un pedido nuevo (estado 'pendiente') y devuelve su número. */
function crear_pedido(array $datos): string
{
    return con_bloqueo('pedidos', function () use ($datos) {
        $pedidos = leer_pedidos();
        $numero  = siguiente_numero_pedido();

        $datos['numero'] = $numero;
        $datos['creado'] = date('c');
        $datos['estado'] = 'pendiente';

        $pedidos[] = $datos;
        guardar_pedidos($pedidos);

        return $numero;
    });
}

function actualizar_pedido(string $numero, array $cambios): bool
{
    return con_bloqueo('pedidos', function () use ($numero, $cambios) {
        $pedidos = leer_pedidos();
        $hallado = false;
        foreach ($pedidos as &$p) {
            if ($p['numero'] === $numero) {
                $p       = array_merge($p, $cambios);
                $hallado = true;
                break;
            }
        }
        unset($p);
        if ($hallado) {
            guardar_pedidos($pedidos);
        }
        return $hallado;
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
    $pendientes = array_values(array_filter(
        leer_pedidos(),
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

function ruta_pedidos_intentos(): string
{
    if (!is_dir(STORAGE_DIR)) {
        @mkdir(STORAGE_DIR, 0775, true);
    }
    return STORAGE_DIR . '/pedidos_intentos.json';
}

/** true si esta IP ya mandó demasiados pedidos en la última hora. */
function pedido_ip_bloqueada(): bool
{
    $ip     = ip_cliente();
    $data   = leer_pedidos_intentos();
    $recientes = array_filter($data[$ip] ?? [], fn($t) => $t > time() - 3600);
    return count($recientes) >= MAX_PEDIDOS_POR_HORA;
}

function leer_pedidos_intentos(): array
{
    $ruta = ruta_pedidos_intentos();
    if (!is_file($ruta)) {
        return [];
    }
    $d = json_decode((string) file_get_contents($ruta), true);
    return is_array($d) ? $d : [];
}

/** Registra un pedido nuevo de esta IP (para el límite por hora) y limpia lo viejo. */
function registrar_pedido_ip(): void
{
    $ip   = ip_cliente();
    $data = leer_pedidos_intentos();

    $recientes  = array_filter($data[$ip] ?? [], fn($t) => $t > time() - 3600);
    $recientes[] = time();
    $data[$ip]  = array_values($recientes);

    // Poda general: quita IPs sin actividad en la última hora para que el
    // archivo no crezca sin control.
    foreach ($data as $k => $v) {
        if (!array_filter($v, fn($t) => $t > time() - 3600)) {
            unset($data[$k]);
        }
    }

    file_put_contents(ruta_pedidos_intentos(), json_encode($data), LOCK_EX);
}
