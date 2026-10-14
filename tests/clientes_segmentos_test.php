<?php
declare(strict_types=1);

// Solo desde la terminal: si alguien la abre desde el navegador, no existe.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/clientes.php';

/* Segmentos del directorio de Clientes y los datos de cada ficha.
   Son funciones puras (sin base de datos), con fechas fijas. */

$fallos = 0;
function verificar(bool $cond, string $msg): void
{
    if ($cond) {
        echo "OK   - $msg\n";
    } else {
        echo "FAIL - $msg\n";
        $GLOBALS['fallos']++;
    }
}

$hoy = '2026-10-14';
$cli = fn(array $extra = []) => array_merge(['id' => 'c1', 'nombre' => 'Alina Torres', 'telefono' => '3001234567', 'notas' => '', 'saldo_favor' => 0], $extra);
$fact = fn(string $creada, array $extra = []) => array_merge([
    'numero' => 'F-1', 'cliente' => 'Alina Torres', 'telefono' => '3001234567', 'creada' => $creada, 'plan' => 'contado',
    'total' => 100000, 'lineas' => [['nombre' => 'Shampoo', 'cant' => 1]], 'cuotas' => [],
], $extra);

/* --- segmentos_cliente --- */

verificar(segmentos_cliente($cli(), null, 0, $hoy) === ['nue'], 'sin compras: es "nueva"');

$una = estadisticas_de_facturas([$fact('2026-10-01')]);
verificar(segmentos_cliente($cli(), $una, 0, $hoy) === ['nue'], 'una sola compra: sigue siendo "nueva"');

$varias = estadisticas_de_facturas([$fact('2026-07-01'), $fact('2026-08-01'), $fact('2026-09-01')]);   // cada ~31 días, última el 1 de sep (43 días)
verificar($varias['frecuencia_dias'] === 31, 'precondición: compra cada 31 días');
verificar(in_array('rep', segmentos_cliente($cli(), $varias, 0, $hoy), true), 'ya pasó su frecuencia: le toca reponer');

$alDia = estadisticas_de_facturas([$fact('2026-08-20'), $fact('2026-09-20'), $fact('2026-10-10')]);   // cada ~25 días, última hace 4
verificar(!in_array('rep', segmentos_cliente($cli(), $alDia, 0, $hoy), true), 'compró hace poco: no le toca reponer');

verificar(in_array('cuo', segmentos_cliente($cli(), $varias, 50000, $hoy), true), 'con cuotas por pagar: "con cuota pendiente"');
verificar(!in_array('cuo', segmentos_cliente($cli(), $varias, 0, $hoy), true), 'sin cuotas por pagar: no está en "con cuota pendiente"');

verificar(in_array('sal', segmentos_cliente($cli(['saldo_favor' => 5000]), $varias, 0, $hoy), true), 'saldo a favor > 0: "con saldo a favor"');
verificar(!in_array('sal', segmentos_cliente($cli(), $varias, 0, $hoy), true), 'sin saldo: no aparece en saldo a favor');

$vieja = estadisticas_de_facturas([$fact('2026-03-01'), $fact('2026-04-01')]);
verificar(in_array('ina', segmentos_cliente($cli(), $vieja, 0, $hoy), true), 'más de 90 días sin comprar: inactiva');
verificar(!in_array('ina', segmentos_cliente($cli(), null, 0, $hoy), true), 'quien nunca ha comprado no es "inactiva", es nueva');
verificar(!in_array('ina', segmentos_cliente($cli(), $alDia, 0, $hoy), true), 'compró hace 4 días: no es inactiva');

verificar(in_array('sin', segmentos_cliente($cli(['telefono' => '']), $varias, 0, $hoy), true), 'sin teléfono: segmento "sin teléfono"');
verificar(in_array('sin', segmentos_cliente($cli(['telefono' => 'abc']), $varias, 0, $hoy), true), 'un teléfono sin dígitos cuenta como sin teléfono');
verificar(!in_array('sin', segmentos_cliente($cli(), $varias, 0, $hoy), true), 'con teléfono: no está en "sin teléfono"');

$muy = estadisticas_de_facturas([$fact('2026-10-14'), $fact('2026-10-14')]);   // "Muy seguido" (frecuencia 0), última hoy
verificar(!in_array('rep', segmentos_cliente($cli(), $muy, 0, $hoy), true), 'compra muy seguido pero lo hizo hoy: aún no le toca');

/* --- cuotas_pendientes_cliente --- */

$conCuotas = $fact('2026-09-01', ['plan' => 'cuotas', 'cuotas' => [
    ['monto' => 60000, 'vence' => '2026-09-15', 'abonos' => [['monto' => 20000]]],   // falta 40.000, ya venció
    ['monto' => 60000, 'vence' => '2026-11-15', 'abonos' => []],                       // falta 60.000, no vence
    ['monto' => 10000, 'vence' => '2026-09-01', 'abonos' => [['monto' => 10000]]],   // pagada
]]);
$r = cuotas_pendientes_cliente([$conCuotas]);
verificar($r['total'] === 100000 && $r['vencida'] === true, 'suma lo que falta de las cuotas y avisa que hay una vencida');
$r = cuotas_pendientes_cliente([$fact('2026-09-01')]);
verificar($r['total'] === 0 && $r['vencida'] === false, 'una factura de contado no deja nada por pagar');
$r = cuotas_pendientes_cliente([]);
verificar($r['total'] === 0, 'sin facturas: nada por pagar');

/* --- facturas_propias_cliente --- */

$otra = $fact('2026-09-01', ['numero' => 'F-2', 'cliente' => 'Otra Persona', 'telefono' => '3109999999']);
$anulada = $fact('2026-09-02', ['numero' => 'F-3', 'anulada' => true]);
$porTel = $fact('2026-09-03', ['numero' => 'F-4', 'cliente' => 'Alina T.', 'telefono' => '+57 300 123 4567']);
$propias = facturas_propias_cliente($cli(), [$fact('2026-09-01'), $otra, $anulada, $porTel]);
verificar(count($propias) === 2, 'toma las suyas por nombre o por teléfono y deja fuera ajenas y anuladas');

/* --- resumen_pago_factura --- */

verificar(resumen_pago_factura($fact('2026-09-01')) === ['nue', 'Contado'], 'contado → "Contado"');
verificar(resumen_pago_factura($conCuotas) === ['cuo', 'Vencida'], 'con una cuota vencida → "Vencida"');
$pagada = $fact('2026-09-01', ['plan' => 'cuotas', 'cuotas' => [['monto' => 5000, 'vence' => '2026-09-15', 'abonos' => [['monto' => 5000]]]]]);
verificar(resumen_pago_factura($pagada) === ['nue', 'Pagada'], 'todas las cuotas pagadas → "Pagada"');
$futura = $fact('2026-09-01', ['plan' => 'cuotas', 'cuotas' => [['monto' => 5000, 'vence' => '2099-01-01', 'abonos' => []]]]);
verificar(resumen_pago_factura($futura) === ['rep', 'Pendiente'], 'cuotas por pagar sin vencer → "Pendiente"');

/* --- orden y letra --- */

verificar(clave_nombre_cliente('Ángela Muñoz') < clave_nombre_cliente('Beatriz'), 'Ángela va con la A, no después de la Z');
verificar(clave_nombre_cliente('Ángela') > clave_nombre_cliente('Andrea'), 'Andrea va antes que Ángela');
verificar(letra_cliente('Ángela Muñoz') === 'A', 'la letra de Ángela es A');
verificar(letra_cliente('Ñusta') === 'N', 'la letra de Ñusta es N');
verificar(letra_cliente('3 Marías') === '#', 'un nombre que empieza por número va en "#"');

/* --- datos_directorio_clientes --- */

$c1 = $cli(['id' => 'a', 'saldo_favor' => 0]);
$c2 = $cli(['id' => 'b', 'nombre' => 'Sin Teléfono', 'telefono' => '']);
$datos = datos_directorio_clientes([$c1, $c2], [$fact('2026-07-01'), $fact('2026-08-01', ['numero' => 'F-9']), $conCuotas], $hoy);
verificar(array_keys($datos) === ['a', 'b'], 'una entrada por clienta, con su id');
verificar($datos['a']['compras'] === 3 && $datos['a']['debe'] === 100000 && $datos['a']['vencida'] === true, 'cuenta las compras y lo que debe');
verificar($datos['a']['pista'] === 'Debe $100.000 · vencida', 'la pista de la lista dice lo más urgente: la deuda vencida');
verificar(count($datos['a']['facturas']) === 3 && $datos['a']['facturas'][0]['n'] === 'F-1', 'trae las 3 facturas más recientes, la última primero');
verificar(count($datos['a']['ofertas']) === 2 && str_contains($datos['a']['ofertas'][0]['url'], 'wa.me/573001234567'), 'ofertas de WhatsApp para quien tiene teléfono');
verificar($datos['b']['ofertas'] === [] && $datos['b']['pista'] === 'Sin teléfono' && in_array('sin', $datos['b']['segs'], true), 'sin teléfono: no hay ofertas y queda en "sin teléfono"');

echo $fallos === 0 ? "\nTodo bien.\n" : "\n$fallos fallo(s).\n";
exit($fallos === 0 ? 0 : 1);
