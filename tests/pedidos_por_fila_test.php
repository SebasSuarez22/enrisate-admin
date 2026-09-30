<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/pedidos.php';

/* Pedidos: guardar por fila da EXACTAMENTE lo mismo que reescribir todo.

   Prueba diferencial: la misma secuencia de operaciones se corre con la
   implementación anterior (leer todos -> cambiar uno -> reescribir todos) y con
   la nueva (solo la fila que cambia), y el resultado tiene que ser idéntico.
   Además comprueba lo que motivó el cambio: tocar un pedido no reescribe los demás. */

function limpiar_pedidos_prueba(): void
{
    db()->exec('DELETE FROM pedido_lineas');
    db()->exec('DELETE FROM pedidos');
}
limpiar_pedidos_prueba();
register_shutdown_function('limpiar_pedidos_prueba');

/* --- La implementación anterior, tal cual era (sobre guardar_pedidos masivo) --- */
function anterior_siguiente_numero(): string
{
    $max = 0;
    foreach (leer_pedidos() as $p) {
        $n = (int) substr((string) $p['numero'], -4);
        if ($n > $max) {
            $max = $n;
        }
    }
    return 'P-' . date('Y') . '-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
}
function anterior_crear(array $datos): string
{
    $pedidos = leer_pedidos();
    $numero  = anterior_siguiente_numero();
    $datos['numero'] = $numero;
    $datos['creado'] = date('c');
    $datos['estado'] = 'pendiente';
    $pedidos[] = $datos;
    guardar_pedidos($pedidos);
    return $numero;
}
function anterior_actualizar(string $numero, array $cambios): bool
{
    $pedidos = leer_pedidos();
    $hallado = false;
    foreach ($pedidos as &$p) {
        if ($p['numero'] === $numero) {
            $p = array_merge($p, $cambios);
            $hallado = true;
            break;
        }
    }
    unset($p);
    if ($hallado) {
        guardar_pedidos($pedidos);
    }
    return $hallado;
}
function anterior_por_numero(string $numero): ?array
{
    foreach (leer_pedidos() as $p) {
        if ($p['numero'] === $numero) {
            return $p;
        }
    }
    return null;
}
function anterior_descartar(string $numero, string $motivo = ''): bool
{
    $p = anterior_por_numero($numero);
    if (!$p || ($p['estado'] ?? '') !== 'pendiente') {
        return false;
    }
    return anterior_actualizar($numero, ['estado' => 'descartado', 'descartado_el' => date('c'), 'motivo_descartado' => trim($motivo)]);
}
function anterior_pendientes(): array
{
    $pend = array_values(array_filter(leer_pedidos(), fn($p) => ($p['estado'] ?? '') === 'pendiente'));
    usort($pend, fn($a, $b) => ($b['creado'] ?? '') <=> ($a['creado'] ?? ''));
    return $pend;
}

/* --- Un mismo guion de uso, parametrizado por implementación --- */
function lineas(int $n): array
{
    $out = [];
    for ($i = 1; $i <= $n; $i++) {
        $out[] = ['sku' => "SKU-$i", 'nombre' => "Crema ñandú $i 🌸", 'marca' => 'Marca', 'precio' => 1000 * $i, 'cant' => $i];
    }
    return $out;
}
function guion(array $f): array
{
    // Pedidos "importados" con números raros, como los que puede haber en producción
    db()->exec("INSERT INTO pedidos (numero, cliente, telefono, subtotal, total, plan, estado, creado)
                VALUES ('P-2025-0042', 'Vieja', '', 1, 1, 'contado', 'confirmado', '2025-01-01T00:00:00-05:00'),
                       ('RARO', 'Rara', '', 1, 1, 'contado', 'Pendiente', '2025-01-02T00:00:00-05:00')");
    $n = [];
    $n[] = $f['crear'](['cliente' => 'Ana María Peñaloza', 'telefono' => '3001112222', 'lineas' => lineas(3), 'subtotal' => 14000, 'total' => 14000, 'plan' => 'cuotas', 'num_cuotas_preferido' => 3]);
    $n[] = $f['crear'](['cliente' => 'Beatriz', 'telefono' => '3002223333', 'lineas' => lineas(1), 'subtotal' => 1000, 'total' => 1000, 'plan' => 'contado', 'num_cuotas_preferido' => null]);
    $n[] = $f['crear'](['cliente' => 'Carla', 'telefono' => '', 'lineas' => [], 'subtotal' => 0, 'total' => 0, 'plan' => 'contado']);
    $n[] = $f['crear'](['cliente' => 'Diana', 'telefono' => '3004445555', 'lineas' => lineas(2), 'subtotal' => 5000, 'total' => 5000, 'plan' => 'cuotas', 'num_cuotas_preferido' => 2]);
    $n[] = $f['crear'](['cliente' => 'Elena', 'telefono' => '3005556666', 'lineas' => lineas(4), 'subtotal' => 30000, 'total' => 30000, 'plan' => 'contado']);
    $r = [];
    $r[] = $f['actualizar']($n[0], ['estado' => 'confirmado', 'factura_numero' => '2026-0001', 'confirmado_el' => date('c')]);
    $r[] = $f['descartar']($n[1], '  no contestó  ');
    $r[] = $f['descartar']($n[2]);
    $r[] = $f['descartar']($n[0]);                            // ya confirmado: debe negarse
    $r[] = $f['actualizar']($n[4], ['telefono' => '3009998888']);
    $r[] = $f['actualizar']('P-NO-EXISTE', ['estado' => 'confirmado']);
    $r[] = $f['actualizar']($n[3], ['lineas' => lineas(1)]);  // cambia las líneas de un pedido
    return ['numeros' => $n, 'resultados' => $r, 'siguiente' => $f['siguiente']()];
}

// Las horas (date('c')) pueden caer en segundos distintos entre corridas: se igualan.
function sin_horas(array $pedidos): array
{
    return array_map(function ($p) {
        foreach (['creado', 'confirmado_el', 'descartado_el'] as $k) {
            if (isset($p[$k]) && !str_starts_with($p[$k], '2025-')) {
                $p[$k] = '(hora)';
            }
        }
        return $p;
    }, $pedidos);
}

$anterior = guion(['crear' => 'anterior_crear', 'actualizar' => 'anterior_actualizar', 'descartar' => 'anterior_descartar', 'siguiente' => 'anterior_siguiente_numero']);
$fotoAnterior = sin_horas(leer_pedidos());
$pendAnterior = array_column(anterior_pendientes(), 'numero');
$unoAnterior  = array_map(fn($n) => anterior_por_numero($n), [...$anterior['numeros'], 'P-2025-0042', 'RARO', 'raro', $anterior['numeros'][0] . ' ']);

limpiar_pedidos_prueba();
$nueva = guion(['crear' => 'crear_pedido', 'actualizar' => 'actualizar_pedido', 'descartar' => 'descartar_pedido', 'siguiente' => 'siguiente_numero_pedido']);
$fotoNueva = sin_horas(leer_pedidos());
$pendNueva = array_column(pedidos_pendientes(), 'numero');
$unoNuevo  = array_map(fn($n) => pedido_por_numero($n), [...$nueva['numeros'], 'P-2025-0042', 'RARO', 'raro', $nueva['numeros'][0] . ' ']);

verificar($nueva['numeros'] === $anterior['numeros'], 'los pedidos reciben los mismos números que antes (' . implode(', ', $nueva['numeros']) . ')');
verificar($nueva['resultados'] === $anterior['resultados'], 'cada operación responde lo mismo que antes (confirmar, descartar, negar, no encontrado)');
verificar($nueva['siguiente'] === $anterior['siguiente'], 'el siguiente número también coincide (' . $nueva['siguiente'] . ')');
// Única diferencia, y es la buscada: el pedido importado 'RARO' tiene un estado mal
// escrito ('Pendiente'). La implementación anterior, al guardar CUALQUIER pedido,
// reescribía todos y de paso le cambiaba el estado a ese pedido que nadie tocó.
// La nueva no toca lo que no se está guardando. Todo lo demás debe ser idéntico.
$sinRaro = fn(array $lista) => array_values(array_filter($lista, fn($p) => ($p['numero'] ?? $p) !== 'RARO'));
$raroAnterior = array_values(array_filter($fotoAnterior, fn($p) => $p['numero'] === 'RARO'))[0];
$raroNuevo    = array_values(array_filter($fotoNueva, fn($p) => $p['numero'] === 'RARO'))[0];
verificar($sinRaro($fotoNueva) === $sinRaro($fotoAnterior), 'la base queda EXACTAMENTE igual que con la implementación anterior (todos los campos y todas las líneas de los pedidos que se usaron)');
verificar($raroAnterior['estado'] === 'pendiente' && $raroNuevo['estado'] === 'Pendiente',
    'la implementación anterior le cambiaba el estado a un pedido que nadie tocó; la nueva lo deja intacto');
verificar($sinRaro($pendNueva) === $sinRaro($pendAnterior), 'la lista de pendientes es la misma y en el mismo orden (' . implode(', ', $sinRaro($pendNueva)) . ')');
$quitarRaro = fn(array $l) => array_values(array_filter($l, fn($p) => $p === null || $p['numero'] !== 'RARO'));
verificar(sin_horas(array_filter($quitarRaro($unoNuevo))) === sin_horas(array_filter($quitarRaro($unoAnterior)))
    && count(array_filter($quitarRaro($unoNuevo))) === count(array_filter($quitarRaro($unoAnterior))),
    'buscar un pedido por número da lo mismo, incluidos los que no existen, con otra mayúscula o con espacio');

/* --- Lo que cambia: tocar un pedido no reescribe los demás --- */
$ids = fn() => db()->query('SELECT pedido_numero, GROUP_CONCAT(id ORDER BY id) FROM pedido_lineas GROUP BY pedido_numero')->fetchAll(PDO::FETCH_KEY_PAIR);
$antes = $ids();
actualizar_pedido($nueva['numeros'][4], ['telefono' => '3000000000']);
$despues = $ids();
unset($antes[$nueva['numeros'][4]], $despues[$nueva['numeros'][4]]);
verificar($antes === $despues && count($antes) > 0, 'actualizar un pedido deja intactas las filas de los demás pedidos (antes se borraban y reinsertaban todas)');
$antes = $ids();
crear_pedido(['cliente' => 'Nueva', 'telefono' => '3001231234', 'lineas' => lineas(2), 'subtotal' => 5000, 'total' => 5000, 'plan' => 'contado']);
$despues = $ids();
verificar(array_intersect_key($despues, $antes) === $antes, 'crear un pedido no toca las filas de los que ya existían');

/* --- Seguridad del INSERT: un número repetido falla en vez de pisar otro pedido --- */
$existente = pedido_por_numero($nueva['numeros'][0]);
$fallo = false;
try {
    guardar_pedido(['numero' => $existente['numero'], 'cliente' => 'Intrusa', 'lineas' => []], true);
} catch (PDOException $e) {
    $fallo = true;
}
verificar($fallo && pedido_por_numero($existente['numero']) == $existente, 'guardar un pedido "nuevo" con un número que ya existe falla y no pisa al original');

terminar_pruebas();
