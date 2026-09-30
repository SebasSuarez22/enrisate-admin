<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/cuentas.php';

/* Crear una cuenta de cobro desde el formulario: o se guarda todo, o nada.

   Una cuenta toca cuatro cosas —la factura, la clienta, su saldo a favor y el
   pedido del catálogo de donde vino—. Antes cada una se guardaba por su lado, y
   si algo fallaba a la mitad quedaba la factura con el saldo ya gastado y el
   pedido todavía "pendiente" (se podía facturar dos veces). */

function limpiar_cuentas(): void
{
    db()->exec('DROP TRIGGER IF EXISTS falla_al_confirmar');
    foreach (['pedido_lineas', 'pedidos', 'clientes', 'productos'] as $t) {
        db()->exec("DELETE FROM $t");
    }
}
limpiar_cuentas();
register_shutdown_function('limpiar_cuentas');

$sku    = catalogo()[0]['sku'];
$precio = catalogo()[0]['precio'];   // siembra el catálogo de prueba

function n(string $sql): int { return (int) db()->query($sql)->fetchColumn(); }
function post(array $extra = []): array
{
    global $sku;
    return $extra + ['cliente' => 'Clienta con saldo', 'telefono' => '300 111 2222', 'plan' => 'contado', 'descuento' => 0,
        'items' => json_encode([['sku' => $sku, 'cant' => 1]])];
}
function clienta_con_saldo(int $saldo): string
{
    db()->exec('DELETE FROM clientes');
    agregar_cliente('Clienta con saldo', '3001112222', '');
    $id = leer_clientes()[0]['id'];
    actualizar_cliente($id, 'Clienta con saldo', '3001112222', '', $saldo);
    return $id;
}
function saldo_de(string $id): int { return n('SELECT saldo_favor FROM clientes WHERE id = ' . db()->quote($id)); }
function estado_pedido(string $num): string
{
    return (string) db()->query('SELECT estado FROM pedidos WHERE numero = ' . db()->quote($num))->fetchColumn();
}
function pedido_nuevo(string $cliente = 'Clienta con saldo'): string
{
    return crear_pedido(['cliente' => $cliente, 'telefono' => '3001112222', 'lineas' => [], 'subtotal' => 1, 'total' => 1, 'plan' => 'contado']);
}
function rechaza(callable $f): string
{
    try { $f(); } catch (DomainException $e) { return $e->getMessage(); }
    return '';
}

/* --- 1. Cuenta de contado, clienta nueva --- */
$num = crear_cuenta_desde_formulario(post(['fecha_pago' => '2026-09-01']));
$fac = factura_por_numero($num);
verificar($fac !== null && $fac['total'] === $precio && $fac['pagado'] === $precio && $fac['plan'] === 'contado',
    'una cuenta de contado se crea completa y pagada');
verificar(n('SELECT COUNT(*) FROM clientes') === 1, 'la clienta nueva queda guardada en Clientes');
crear_cuenta_desde_formulario(post());
verificar(n('SELECT COUNT(*) FROM clientes') === 1, 'una segunda cuenta de la misma clienta no la duplica');

/* --- 2. Cuotas + saldo a favor + pedido de origen, todo junto --- */
db()->exec('DELETE FROM pedidos');
$id  = clienta_con_saldo(20000);
$ped = pedido_nuevo();
$num = crear_cuenta_desde_formulario(post(['plan' => 'cuotas', 'cliente_id' => $id, 'saldo_aplicado' => 20000,
    'abono' => 10000, 'num_cuotas' => 2, 'desde_pedido' => $ped]));
$fac = factura_por_numero($num);
$suma = array_sum(array_column($fac['cuotas'], 'monto'));
verificar($fac['saldo_aplicado'] === 20000 && $fac['abono'] === 10000 && $suma === $precio - 30000 && count($fac['cuotas']) === 2,
    'a cuotas: el saldo y el abono restan del total y las cuotas suman lo que queda');
verificar(saldo_de($id) === 0, 'el saldo a favor usado se descuenta de la clienta');
verificar(estado_pedido($ped) === 'confirmado' && pedido_por_numero($ped)['factura_numero'] === $num,
    'el pedido queda confirmado y enlazado a la factura');

/* --- 3. Todo o nada: si falla el último paso no queda NADA a medias --- */
db()->exec('DELETE FROM facturas');
$id  = clienta_con_saldo(20000);
db()->exec('DELETE FROM pedidos');
$ped = pedido_nuevo();
$antes = n('SELECT COUNT(*) FROM facturas');
db()->exec("CREATE TRIGGER falla_al_confirmar BEFORE INSERT ON pedidos FOR EACH ROW
    BEGIN IF NEW.estado = 'confirmado' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fallo simulado'; END IF; END");
$cayo = false;
try {
    crear_cuenta_desde_formulario(post(['cliente_id' => $id, 'saldo_aplicado' => 20000, 'desde_pedido' => $ped]));
} catch (Throwable $e) {
    $cayo = true;
}
db()->exec('DROP TRIGGER falla_al_confirmar');
verificar($cayo, 'setup: el último paso (confirmar el pedido) falla a propósito');
verificar(n('SELECT COUNT(*) FROM facturas') === $antes, 'no queda ninguna factura creada');
verificar(saldo_de($id) === 20000, 'el saldo a favor de la clienta sigue intacto (no se gastó)');
verificar(estado_pedido($ped) === 'pendiente', 'el pedido sigue pendiente, listo para reintentar');
$num = crear_cuenta_desde_formulario(post(['cliente_id' => $id, 'saldo_aplicado' => 20000, 'desde_pedido' => $ped]));
verificar(saldo_de($id) === 0 && estado_pedido($ped) === 'confirmado' && n('SELECT COUNT(*) FROM facturas') === $antes + 1,
    'reintentar después del fallo funciona y ahora sí queda todo');

/* --- 4. No se factura dos veces el mismo pedido --- */
$msg = rechaza(fn() => crear_cuenta_desde_formulario(post(['desde_pedido' => $ped])));
verificar(str_contains($msg, 'ya fue confirmado') && n('SELECT COUNT(*) FROM facturas') === $antes + 1,
    'facturar de nuevo un pedido ya confirmado se rechaza con aviso y no crea otra factura');
db()->exec('DELETE FROM pedidos');
$ped2 = pedido_nuevo();
descartar_pedido($ped2, 'no contestó');
$msg = rechaza(fn() => crear_cuenta_desde_formulario(post(['desde_pedido' => $ped2])));
verificar(str_contains($msg, 'descartado'), 'un pedido descartado tampoco se puede facturar');

/* --- 5. Validaciones del formulario (mismos mensajes de siempre) --- */
verificar(rechaza(fn() => crear_cuenta_desde_formulario(post(['cliente' => '  ']))) === 'Escribe el nombre del cliente.', 'sin nombre se rechaza');
verificar(rechaza(fn() => crear_cuenta_desde_formulario(post(['items' => '[]']))) === 'Agrega al menos un producto a la cuenta.', 'sin productos se rechaza');
verificar(rechaza(fn() => crear_cuenta_desde_formulario(post(['items' => json_encode([['sku' => 'NO-EXISTE', 'cant' => 1]])])))
    === 'Ninguno de los productos existe en el catálogo.', 'con productos que no existen se rechaza');

/* --- 6. Dos personas a la vez: el mismo saldo y el mismo pedido no se usan dos veces --- */
db()->exec('DELETE FROM facturas');
db()->exec('DELETE FROM pedidos');
$id   = clienta_con_saldo(10000);
$ped3 = pedido_nuevo();
$inicio = microtime(true) + 1.5;
$worker = __DIR__ . '/_worker_cuentas.php';
$lanzar = function (string $rol, string $d1) use ($worker, $inicio, $sku) {
    $p = proc_open([PHP_BINARY, $worker, $rol, sprintf('%.6F', $inicio), $d1, $sku], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    return [$p, $pipes];
};
// Más procesos que dos: la ventana de la carrera es de milisegundos y con pocos a veces
// no se cruzan; con muchos a la vez el fallo aparece siempre que falta el candado.
$procesos = [];
for ($i = 0; $i < 6; $i++) { $procesos[] = $lanzar('saldo', $id); }
for ($i = 0; $i < 4; $i++) { $procesos[] = $lanzar('pedido', $ped3); }
$res = [];
foreach ($procesos as [$p, $pipes]) {
    $salida = stream_get_contents($pipes[1]);
    $err    = stream_get_contents($pipes[2]);
    proc_close($p);
    $res[] = json_decode(trim($salida), true) ?: ['rol' => '?', 'numero' => null, 'rechazada' => '', 'error' => trim($err ?: $salida)];
    echo '     ' . json_encode(end($res), JSON_UNESCAPED_UNICODE) . "\n";
}
$conError    = array_filter($res, fn($r) => $r['error'] !== '');
$saldoAplic  = (int) db()->query('SELECT COALESCE(SUM(saldo_aplicado),0) FROM facturas WHERE saldo_aplicado > 0')->fetchColumn();
$delPedido   = n('SELECT COUNT(*) FROM pedidos WHERE numero = ' . db()->quote($ped3) . " AND estado = 'confirmado'");
$facturasPed = array_filter($res, fn($r) => $r['rol'] === 'pedido' && $r['numero'] !== null);
verificar(!$conError, 'ningún proceso terminó con error');
verificar($saldoAplic === 10000 && saldo_de($id) === 0,
    'seis cuentas a la vez con el mismo saldo de $10.000: solo se aplica una vez (aplicado ' . $saldoAplic . ', queda ' . saldo_de($id) . ')');
verificar(count($facturasPed) === 1 && $delPedido === 1,
    'cuatro personas facturando el mismo pedido a la vez: solo se crea una factura (' . count($facturasPed) . ')');

terminar_pruebas();
