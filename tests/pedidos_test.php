<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/pedidos.php';

/* Pedidos del catálogo público: guardar varios, confirmar y descartar.

   Cada guardado reescribe TODOS los pedidos con sus líneas, y la tabla de
   líneas no tiene borrado en cascada: antes de arreglarlo, solo se podía
   guardar el primer pedido con productos. Estas pruebas cubren el uso normal
   (varios pedidos, cada uno con líneas) y que las líneas sobrevivan a cada cambio. */

function limpiar_pedidos(): void
{
    db()->exec('DELETE FROM pedido_lineas');
    db()->exec('DELETE FROM pedidos');
}
limpiar_pedidos();
register_shutdown_function('limpiar_pedidos');

function pedido_con_lineas(string $cliente, int $cuantas): array
{
    $lineas = [];
    for ($i = 1; $i <= $cuantas; $i++) {
        $lineas[] = ['sku' => "SKU-$i", 'nombre' => "Producto $i", 'marca' => 'Marca', 'precio' => 1000 * $i, 'cant' => $i];
    }
    return ['cliente' => $cliente, 'telefono' => '3001234567', 'lineas' => $lineas,
            'subtotal' => 6000, 'total' => 6000, 'plan' => 'cuotas', 'num_cuotas_preferido' => 3];
}

function lineas_en_base(string $numero): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM pedido_lineas WHERE pedido_numero = ?');
    $st->execute([$numero]);
    return (int) $st->fetchColumn();
}

$errores = [];
$numeros = [];
foreach ([['Ana', 3], ['Beatriz', 2], ['Carla', 1]] as [$cliente, $n]) {
    try {
        $numeros[$cliente] = crear_pedido(pedido_con_lineas($cliente, $n));
    } catch (Throwable $e) {
        $errores[] = "$cliente: " . substr($e->getMessage(), 0, 100);
    }
}
verificar(!$errores, 'se pueden crear 3 pedidos seguidos, cada uno con productos'
    . ($errores ? ' (falló: ' . implode(' | ', $errores) . ')' : ''));
verificar(count($numeros) === 3 && count(array_unique($numeros)) === 3, 'cada pedido recibe un número distinto');
verificar(lineas_en_base($numeros['Ana'] ?? '') === 3 && lineas_en_base($numeros['Beatriz'] ?? '') === 2 && lineas_en_base($numeros['Carla'] ?? '') === 1,
    'cada pedido conserva exactamente sus líneas en la base (3, 2 y 1), sin duplicar');

$ana = pedido_por_numero($numeros['Ana'] ?? '');
verificar($ana !== null && count($ana['lineas']) === 3 && $ana['lineas'][2]['cant'] === 3 && $ana['lineas'][2]['precio'] === 3000,
    'al leerlo, un pedido devuelve sus líneas completas y en orden');
verificar($ana !== null && $ana['plan'] === 'cuotas' && $ana['num_cuotas_preferido'] === 3 && $ana['estado'] === 'pendiente',
    'plan, cuotas preferidas y estado pendiente se guardan bien');

verificar(marcar_pedido_confirmado($numeros['Ana'], '2026-0001') === true, 'confirmar un pedido con productos funciona');
verificar(descartar_pedido($numeros['Beatriz'], 'no contestó') === true, 'descartar un pedido con productos funciona');
verificar(lineas_en_base($numeros['Ana']) === 3 && lineas_en_base($numeros['Beatriz']) === 2 && lineas_en_base($numeros['Carla']) === 1,
    'confirmar y descartar no pierden ni duplican las líneas de ningún pedido');

$ana = pedido_por_numero($numeros['Ana']);
verificar($ana['estado'] === 'confirmado' && $ana['factura_numero'] === '2026-0001', 'el pedido confirmado queda enlazado a su factura');
verificar(pedido_por_numero($numeros['Beatriz'])['estado'] === 'descartado', 'el pedido descartado queda como descartado');
verificar(count(pedidos_pendientes()) === 1 && pedidos_pendientes()[0]['cliente'] === 'Carla', 'solo queda pendiente el pedido que nadie tocó');
verificar(descartar_pedido($numeros['Ana']) === false && descartar_pedido($numeros['Beatriz']) === false,
    'un pedido ya confirmado o descartado no se puede descartar de nuevo');

terminar_pruebas();
