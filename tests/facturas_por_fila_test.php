<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ayudas_guardado_masivo.php';

/* Facturas: guardar por fila da EXACTAMENTE lo mismo que reescribir todo.

   Prueba diferencial: el mismo guion se corre con la implementación anterior
   (leer todas -> cambiar una -> borrar todas -> reinsertar todas) y con la nueva
   (solo la factura que cambia, con sus líneas, cuotas y abonos). Los cambios del
   guion tienen la misma forma que mandan registrar_abono, aplicar_descuento_cuota,
   agregar_lineas_factura, anular y reactivar. Se comparan las respuestas, el
   número siguiente después de cada paso y todas las facturas al final. */

function limpiar_facturas_prueba(): void
{
    foreach (['facturas', 'factura_lineas', 'factura_cuotas', 'factura_abonos'] as $t) {
        foreach (['borrado' => 'DELETE', 'insertado' => 'INSERT', 'editado' => 'UPDATE'] as $tipo => $evento) {
            db()->exec("DROP TRIGGER IF EXISTS mov_{$tipo}_{$t}");
        }
    }
    db()->exec('DROP TABLE IF EXISTS prueba_movimientos');
    db()->exec('DELETE FROM facturas');
}
limpiar_facturas_prueba();
register_shutdown_function('limpiar_facturas_prueba');

/* --- La implementación anterior, tal cual era (sobre guardar_facturas masivo) --- */
function ant_siguiente_numero(): string
{
    $max = 0;
    foreach (leer_facturas() as $fac) {
        $n = (int) substr((string) $fac['numero'], -4);
        if ($n > $max) { $max = $n; }
    }
    return date('Y') . '-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
}
function ant_crear(array $datos): string
{
    $facturas = leer_facturas_raw();
    $numero = ant_siguiente_numero();
    $datos['numero'] = $numero;
    $datos['creada'] = date('c');
    $facturas[] = $datos;
    guardar_facturas($facturas);
    return $numero;
}
function ant_actualizar(string $numero, array $cambios): bool
{
    $facturas = leer_facturas_raw(); $hallada = false;
    foreach ($facturas as &$f) {
        if ($f['numero'] === $numero) { $f = array_merge($f, $cambios); $hallada = true; break; }
    }
    unset($f);
    if ($hallada) { guardar_facturas($facturas); }
    return $hallada;
}

/* --- Facturas "importadas" con números raros, iguales para las dos corridas --- */
function sembrar_importadas(): void
{
    guardar_facturas([
        ['numero' => '2025-0042', 'cliente' => 'Importada Contado', 'telefono' => '3001112222', 'nota' => '',
         'lineas' => [['sku' => 'ACC-01', 'nombre' => 'Cepillo', 'marca' => 'Accesorios', 'precio' => 25000, 'costo' => 12000, 'cant' => 2]],
         'subtotal' => 50000, 'total' => 50000, 'costo' => 24000, 'margen' => 26000, 'plan' => 'contado',
         'pagado' => 50000, 'fecha_pago' => '2025-03-01T10:00:00-05:00', 'creada' => '2025-03-01T10:00:00-05:00'],
        ['numero' => 'RARO', 'cliente' => 'Importada Cuotas', 'telefono' => '', 'nota' => 'vieja',
         'lineas' => [], 'subtotal' => 90000, 'total' => 90000, 'plan' => 'cuotas', 'abono' => 10000, 'pagado' => 30000,
         'cuotas' => [
             ['monto' => 40000, 'vence' => '2025-04-01', 'abonos' => [['monto' => 20000, 'fecha' => '2025-04-01T09:00:00-05:00']]],
             ['monto' => 40000, 'vence' => '2025-05-01', 'abonos' => []],
         ],
         'creada' => '2025-03-15T10:00:00-05:00', 'anulada' => false],
        ['numero' => 'P-0099', 'cliente' => 'Número con letras', 'plan' => 'contado', 'total' => 1000, 'pagado' => 1000,
         'creada' => '2025-06-01T10:00:00-05:00'],
        ['numero' => '2024-1234', 'cliente' => 'Consecutivo alto', 'plan' => 'contado', 'total' => 1000, 'pagado' => 1000,
         'creada' => '2024-12-01T10:00:00-05:00'],
    ]);
}

/* --- Un mismo guion de uso, parametrizado por implementación --- */
function guion(callable $crear, callable $actualizar, callable $siguiente): array
{
    $r = [$siguiente()];

    $contado = $crear([
        'cliente' => '  María José Peñaloza 🌸', 'telefono' => '3001234567', 'nota' => "Entregar\ntarde",
        'lineas' => [
            ['sku' => 'ET-03', 'nombre' => 'Crema de peinar', 'marca' => 'Etniker', 'precio' => 50000, 'costo' => 43000, 'cant' => 1],
            ['sku' => 'ET-02', 'nombre' => 'Espuma', 'marca' => 'Etniker', 'precio' => 48000, 'costo' => 44000, 'cant' => 3],
        ],
        'subtotal' => 194000, 'descuento' => 4000, 'total' => 190000, 'costo' => 175000, 'margen' => 15000,
        'plan' => 'contado', 'saldo_aplicado' => 5000, 'pagado' => 190000, 'fecha_pago' => date('c'),
    ]);
    $r[] = $contado;
    $r[] = $siguiente();

    $cuotas = $crear([
        'cliente' => 'Beatriz', 'telefono' => '', 'nota' => '',
        'lineas' => [['sku' => 'ACC-07', 'nombre' => 'Karsell', 'marca' => 'Accesorios', 'precio' => 110000, 'costo' => 85000, 'cant' => 1]],
        'subtotal' => 110000, 'total' => 110000, 'costo' => 85000, 'margen' => 25000, 'plan' => 'cuotas', 'abono' => 20000,
        'cuotas' => [
            ['monto' => 30000, 'vence' => '2026-11-01', 'abonos' => [['monto' => 5000, 'fecha' => '2026-10-01T08:00:00-05:00', 'tipo' => 'abono', 'motivo' => '']]],
            ['monto' => 30000, 'vence' => '2026-12-01', 'abonos' => []],
            ['monto' => 30000, 'vence' => '2027-01-01T00:00:00', 'abonos' => []],   // vence con hora: se recorta
        ],
        'pagado' => 25000,
    ]);
    $r[] = $cuotas;

    $minima = $crear(['cliente' => 'Sin casi nada']);   // faltan casi todas las claves
    $r[] = $minima;
    $r[] = $siguiente();

    // Como registrar_abono: un abono nuevo en la cuota 1
    $f = factura_por_numero($cuotas);
    $f['cuotas'][1]['abonos'][] = ['monto' => 12000, 'fecha' => '2026-10-02T09:00:00-05:00', 'tipo' => 'abono', 'motivo' => ''];
    $r[] = $actualizar($cuotas, ['cuotas' => $f['cuotas'], 'pagado' => total_pagado_cuotas($f)]);

    // Como aplicar_descuento_cuota: un descuento con motivo en la cuota 2
    $f = factura_por_numero($cuotas);
    $f['cuotas'][2]['abonos'][] = ['monto' => 3000, 'fecha' => '2026-10-03T09:00:00-05:00', 'tipo' => 'descuento', 'motivo' => 'Cliente fiel ✨'];
    $r[] = $actualizar($cuotas, ['cuotas' => $f['cuotas'], 'pagado' => total_pagado_cuotas($f)]);

    // Como agregar_lineas_factura sobre una de contado: pasa a cuotas
    $f = factura_por_numero($contado);
    $nuevas = [['sku' => 'DES01', 'nombre' => 'Desodorante', 'marca' => 'Desodorante', 'precio' => 38000, 'costo' => 26000, 'cant' => 2]];
    $cambios = [
        'lineas'   => array_merge($f['lineas'], $nuevas),
        'subtotal' => $f['subtotal'] + 76000, 'total' => $f['total'] + 76000,
        'costo'    => $f['costo'] + 52000, 'margen' => $f['margen'] + 24000, 'plan' => 'cuotas',
        'cuotas'   => [
            ['monto' => 190000, 'vence' => '2026-10-01', 'abonos' => [['monto' => 190000, 'fecha' => '2026-10-01', 'tipo' => 'abono', 'motivo' => '']]],
            ['monto' => 76000, 'vence' => '2026-11-15', 'abonos' => []],
        ],
    ];
    $cambios['pagado'] = total_pagado_cuotas(array_merge($f, $cambios));
    $r[] = $actualizar($contado, $cambios);

    // Anular y reactivar
    $r[] = $actualizar($minima, ['anulada' => true, 'anulada_el' => '2026-10-04T10:00:00-05:00', 'motivo_anulacion' => 'Duplicada']);
    $r[] = $actualizar($minima, ['anulada' => false, 'anulada_el' => null]);
    $r[] = $actualizar($cuotas, ['anulada' => true, 'anulada_el' => '2026-10-05T10:00:00-05:00', 'motivo_anulacion' => '']);

    // Una importada que nadie había tocado
    $r[] = $actualizar('RARO', ['nota' => 'nota nueva']);

    // Números que no existen o que MySQL confundiría: no se encuentran
    $r[] = $actualizar('2099-9999', ['nota' => 'x']);
    $r[] = $actualizar('raro', ['nota' => 'x']);
    $r[] = $actualizar('RARO ', ['nota' => 'x']);
    $r[] = $actualizar('', ['nota' => 'x']);

    $r[] = foto();   // todas las facturas con sus abonos, antes de vaciar una

    // Cambiar las líneas por menos líneas y dejar una factura sin cuotas
    $r[] = $actualizar($cuotas, ['lineas' => [], 'cuotas' => []]);

    $r[] = $siguiente();
    $r[] = $crear(['cliente' => 'Última', 'plan' => 'contado', 'total' => 5000, 'pagado' => 5000]);
    $r[] = $siguiente();
    return $r;
}

// 'creada' lleva la hora de cada corrida: se compara todo lo demás.
function foto(): array
{
    $hoy = date('Y-m-d') . 'T';
    return array_map(function ($f) use ($hoy) {
        if (str_starts_with($f['creada'], $hoy)) { $f['creada'] = '(hora)'; }
        if (str_starts_with((string) $f['fecha_pago'], date('Y-m-d'))) { $f['fecha_pago'] = '(hoy)'; }
        return $f;
    }, leer_facturas_raw());
}

sembrar_importadas();
$ant = guion('ant_crear', 'ant_actualizar', 'ant_siguiente_numero');
$fotoAnt = foto();

limpiar_facturas_prueba();
sembrar_importadas();
$nue = guion('crear_factura', 'actualizar_factura', 'siguiente_numero');
$fotoNue = foto();

verificar($nue === $ant, 'cada operación responde lo mismo que antes, y el número siguiente es el mismo en cada paso (' . count($ant) . ' respuestas: ' . implode(', ', array_map('json_encode', array_slice($ant, 0, 4))) . '…)');
verificar($fotoNue === $fotoAnt, 'las facturas quedan EXACTAMENTE igual que con la implementación anterior (' . count($fotoAnt) . ' facturas: cada campo, línea, cuota y abono, en el mismo orden)');
$fotoMedia = array_values(array_filter($nue, 'is_array'))[0];
$abonos = 0;
foreach ($fotoMedia as $f) { foreach ($f['cuotas'] as $c) { $abonos += count($c['abonos']); } }
verificar(count($fotoMedia) === 7 && $abonos === 5 && count($fotoNue) === 8,
    "(comprobación) el guion sí comparó facturas con cuotas y abonos (a mitad: " . count($fotoMedia) . " facturas y $abonos abonos, incluido un descuento; al final: " . count($fotoNue) . ' facturas)');

/* --- Lo que cambia: tocar una factura no reescribe las demás --- */
// Ids de las filas de las OTRAS facturas: si se reescribieran, cambiarían.
$idsAjenos = function (string $numero): array {
    $st = db()->prepare(
        "SELECT 'l', id FROM factura_lineas WHERE factura_numero <> :n1
         UNION ALL SELECT 'c', id FROM factura_cuotas WHERE factura_numero <> :n2
         UNION ALL SELECT 'a', a.id FROM factura_abonos a JOIN factura_cuotas c ON c.id = a.cuota_id WHERE c.factura_numero <> :n3
         ORDER BY 1, 2"
    );
    $st->execute([':n1' => $numero, ':n2' => $numero, ':n3' => $numero]);
    return $st->fetchAll(PDO::FETCH_NUM);
};
db()->exec('CREATE TABLE prueba_movimientos (tabla varchar(20), tipo varchar(10))');
foreach (['facturas', 'factura_lineas', 'factura_cuotas', 'factura_abonos'] as $t) {
    foreach (['borrado' => 'DELETE', 'insertado' => 'INSERT', 'editado' => 'UPDATE'] as $tipo => $evento) {
        db()->exec("CREATE TRIGGER mov_{$tipo}_{$t} AFTER $evento ON $t FOR EACH ROW INSERT INTO prueba_movimientos VALUES ('$t', '$tipo')");
    }
}
$movFacturas = function (): array {
    $filas = db()->query("SELECT tipo, COUNT(*) FROM prueba_movimientos WHERE tabla = 'facturas' GROUP BY tipo")->fetchAll(PDO::FETCH_KEY_PAIR);
    return ['borrado' => (int) ($filas['borrado'] ?? 0), 'insertado' => (int) ($filas['insertado'] ?? 0), 'editado' => (int) ($filas['editado'] ?? 0)];
};

$conCuotas = crear_factura([
    'cliente' => 'Con cuotas', 'plan' => 'cuotas', 'total' => 60000,
    'lineas' => [['sku' => 'X', 'nombre' => 'X', 'marca' => 'X', 'precio' => 60000, 'costo' => 1, 'cant' => 1]],
    'cuotas' => [['monto' => 30000, 'vence' => '2026-11-01', 'abonos' => []], ['monto' => 30000, 'vence' => '2026-12-01', 'abonos' => []]],
]);
$total = count(leer_facturas_raw());

$antes = $idsAjenos($conCuotas);
db()->exec('DELETE FROM prueba_movimientos');
registrar_abono($conCuotas, 0, 10000);
verificar($movFacturas() === ['borrado' => 0, 'insertado' => 0, 'editado' => 1], "registrar un abono edita solo la fila de esa factura (antes: las $total facturas borradas y reinsertadas)");
verificar($idsAjenos($conCuotas) === $antes, 'registrar un abono no toca las líneas, cuotas ni abonos de las otras facturas (' . count($antes) . ' filas ajenas intactas)');

foreach ([
    'aplicar un descuento' => fn() => aplicar_descuento_cuota($conCuotas, 1, 5000, 'Promoción'),
    'agregar productos'    => fn() => agregar_lineas_factura($conCuotas, [['sku' => 'ACC-04', 'cant' => 1]], '2027-01-01'),
    'anular'               => fn() => anular_factura($conCuotas, 'prueba'),
    'reactivar'            => fn() => reactivar_factura($conCuotas),
] as $accion => $hacer) {
    $antes = $idsAjenos($conCuotas);
    db()->exec('DELETE FROM prueba_movimientos');
    $hacer();
    verificar($idsAjenos($conCuotas) === $antes && $movFacturas()['borrado'] === 0, "$accion no borra ni reescribe las otras facturas");
}

$antes = $idsAjenos('(ninguna)');
db()->exec('DELETE FROM prueba_movimientos');
$nueva = crear_factura(['cliente' => 'Nueva', 'plan' => 'contado', 'total' => 1, 'pagado' => 1,
    'lineas' => [['sku' => 'Y', 'nombre' => 'Y', 'marca' => 'Y', 'precio' => 1, 'costo' => 1, 'cant' => 1]]]);
$despues = array_values(array_filter($idsAjenos('(ninguna)'), fn($fila) => !in_array($fila, $antes, true)));
verificar($movFacturas() === ['borrado' => 0, 'insertado' => 1, 'editado' => 0] && count($despues) === 1,
    'crear una factura inserta solo esa factura y su línea; las existentes conservan sus filas');

db()->exec('DELETE FROM prueba_movimientos');
ant_actualizar($conCuotas, ['nota' => 'x']);
verificar($movFacturas()['borrado'] === $total + 1, '(comprobación) la implementación anterior sí borraba y reinsertaba las ' . ($total + 1) . ' facturas por un solo cambio');

/* --- Seguridad: un número repetido falla en vez de pisar otra factura --- */
$original = factura_por_numero($nueva);
$fallo = false;
try {
    guardar_factura(['numero' => $nueva, 'cliente' => 'Intrusa', 'plan' => 'contado'], true);
} catch (PDOException $e) {
    $fallo = true;
}
verificar($fallo && factura_por_numero($nueva) === $original, 'guardar una factura "nueva" con un número que ya existe falla y no pisa a la original');

/* --- Todo o nada: si falla a mitad, la factura queda como estaba --- */
$original = factura_por_numero($conCuotas);
db()->exec("CREATE TRIGGER falla_abono BEFORE INSERT ON factura_abonos FOR EACH ROW
            BEGIN IF NEW.monto = 777 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'falla simulada'; END IF; END");
$fallo = false;
try {
    registrar_abono($conCuotas, 1, 777);
} catch (PDOException $e) {
    $fallo = true;
}
db()->exec('DROP TRIGGER IF EXISTS falla_abono');
verificar($fallo && factura_por_numero($conCuotas) === $original, 'si guardar un abono falla a mitad, la factura queda intacta: no pierde sus líneas ni sus cuotas');

/* --- Un cambio no puede renombrar la factura --- */
actualizar_factura($nueva, ['numero' => 'OTRA', 'nota' => 'renombrar?']);
verificar(factura_por_numero($nueva)['nota'] === 'renombrar?' && factura_por_numero('OTRA') === null,
    'actualizar con un "numero" en los cambios guarda la nota pero no mueve la factura a otro número');

terminar_pruebas();
