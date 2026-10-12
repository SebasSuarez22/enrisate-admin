<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/pedidos.php';

/* "1 cuota con plazo" del carrito público: la fecha que escoge la clienta (de mañana a
   PLAZO_MAX_DIAS_PUBLICO días), cómo se guarda y cómo se lee en el panel. */

function limpiar_pedidos_plazo(): void
{
    db()->exec('DELETE FROM pedido_lineas');
    db()->exec('DELETE FROM pedidos');
}
limpiar_pedidos_plazo();
register_shutdown_function('limpiar_pedidos_plazo');

/* --- Qué fecha vale (con "hoy" fijo: 2026-10-12) --- */
$hoy = '2026-10-12';
verificar(PLAZO_MAX_DIAS_PUBLICO === 30, 'el plazo máximo del catálogo es de 30 días');
verificar(fecha_pago_preferida_valida('2026-10-13', $hoy) === '2026-10-13', 'mañana vale');
verificar(fecha_pago_preferida_valida('2026-10-27', $hoy) === '2026-10-27', 'dentro de 15 días vale');
verificar(fecha_pago_preferida_valida('2026-11-11', $hoy) === '2026-11-11', 'el día 30 vale (el último)');
verificar(fecha_pago_preferida_valida('2026-11-12', $hoy) === null, 'el día 31 no vale');
verificar(fecha_pago_preferida_valida('2026-10-12', $hoy) === null, 'hoy no vale');
verificar(fecha_pago_preferida_valida('2026-10-01', $hoy) === null, 'una fecha pasada no vale');
verificar(fecha_pago_preferida_valida('2027-10-13', $hoy) === null, 'un año adelante no vale');
verificar(fecha_pago_preferida_valida('2026-02-31', '2026-02-01') === null, 'un día que no existe (31 de febrero) no vale');
verificar(fecha_pago_preferida_valida('13/10/2026', $hoy) === null && fecha_pago_preferida_valida('2026-10-13 10:00', $hoy) === null,
    'solo se acepta el formato AAAA-MM-DD');
verificar(fecha_pago_preferida_valida('', $hoy) === null && fecha_pago_preferida_valida(null, $hoy) === null
    && fecha_pago_preferida_valida(20261013, $hoy) === null && fecha_pago_preferida_valida(['2026-10-13'], $hoy) === null,
    'vacío, null, número o lista no valen');
verificar(fecha_pago_preferida_valida('2026-12-31', '2026-12-05') === '2026-12-31' && fecha_pago_preferida_valida('2027-01-04', '2026-12-05') === '2027-01-04',
    'el plazo cruza el fin de año sin problema');
verificar(fecha_pago_preferida_valida(date('Y-m-d', strtotime('+1 day'))) === date('Y-m-d', strtotime('+1 day')),
    'sin "hoy" explícito se usa el día de hoy de verdad');

/* --- Guardar y leer --- */
$base = ['cliente' => 'Laura', 'telefono' => '3001234567', 'lineas' => [], 'subtotal' => 90000, 'total' => 90000];

$n1 = crear_pedido($base + ['plan' => 'cuotas', 'num_cuotas_preferido' => 1, 'fecha_pago_preferida' => '2026-10-27']);
$p1 = pedido_por_numero($n1);
verificar($p1 !== null && $p1['num_cuotas_preferido'] === 1 && $p1['fecha_pago_preferida'] === '2026-10-27',
    'un pedido de 1 cuota con plazo guarda y devuelve su fecha de pago');

$n2 = crear_pedido($base + ['plan' => 'cuotas', 'num_cuotas_preferido' => 3, 'fecha_pago_preferida' => null]);
$p2 = pedido_por_numero($n2);
verificar($p2 !== null && $p2['num_cuotas_preferido'] === 3 && !array_key_exists('fecha_pago_preferida', $p2),
    'un pedido de 3 cuotas no trae fecha de pago');

$n3 = crear_pedido($base + ['plan' => 'contado', 'num_cuotas_preferido' => null]);
$p3 = pedido_por_numero($n3);
verificar($p3 !== null && $p3['plan'] === 'contado' && !array_key_exists('fecha_pago_preferida', $p3),
    'un pedido de contado tampoco');

verificar(descartar_pedido($n1, 'prueba') === true && pedido_por_numero($n1)['fecha_pago_preferida'] === '2026-10-27',
    'descartar o confirmar un pedido no borra su fecha de pago');

/* --- Cómo se lee en el panel --- */
verificar(texto_plan_pedido($p3) === 'De contado', 'panel: de contado');
verificar(texto_plan_pedido($p2) === 'Prefiere 3 cuotas', 'panel: varias cuotas');
verificar(texto_plan_pedido($p1) === 'Paga todo el 27 de octubre de 2026', 'panel: 1 cuota con plazo dice la fecha');
verificar(texto_plan_pedido(['plan' => 'cuotas', 'num_cuotas_preferido' => 1]) === 'Paga todo en 1 cuota',
    'panel: 1 cuota sin fecha (un pedido viejo) no se cae ni dice "1 cuotas"');
verificar(nota_plan_pedido($p1) !== nota_plan_pedido($p2) && nota_plan_pedido($p3) === '',
    'panel: la nota cambia según el plan y el pedido de contado no lleva nota');

/* --- Una base que todavía no tiene la columna (antes de aplicar el esquema nuevo) ---
   Se prueba en otro proceso, porque aquí asegurar_esquema_pedidos() ya recordó que existe. */
db()->exec('ALTER TABLE pedidos DROP COLUMN fecha_pago_preferida');
verificar(db()->query("SHOW COLUMNS FROM pedidos LIKE 'fecha_pago_preferida'")->fetch() === false, 'preparación: la columna se quitó');
$codigo = '$n = crear_pedido(["cliente" => "Ana", "telefono" => "3000000000", "lineas" => [], "subtotal" => 1, "total" => 1,'
    . ' "plan" => "cuotas", "num_cuotas_preferido" => 1, "fecha_pago_preferida" => "2026-10-20"]); echo $n;';
$numero4 = trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r '
    . escapeshellarg('require ' . var_export(__DIR__ . '/../includes/pedidos.php', true) . '; ' . $codigo) . ' 2>&1'));
$p4 = pedido_por_numero($numero4);
verificar($p4 !== null && $p4['fecha_pago_preferida'] === '2026-10-20',
    'si la base no tiene la columna, al guardar el pedido se agrega sola y la fecha queda guardada (salida: ' . substr($numero4, 0, 60) . ')');
verificar(asegurar_esquema_pedidos() === true, 'asegurar_esquema_pedidos() es repetible');

terminar_pruebas();
