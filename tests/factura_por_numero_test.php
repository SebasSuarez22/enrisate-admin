<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/* factura_por_numero() lee solo la factura pedida (antes cargaba todas).

   El resultado tiene que ser EXACTAMENTE el mismo que antes para cualquier
   factura, y null para cualquier número que no sea idéntico a uno guardado:
   esta función la usa el PDF público, que recibe el número desde la URL. */

// La implementación anterior, tal cual: cargar todas y buscar la idéntica.
function factura_por_numero_anterior(string $numero): ?array
{
    foreach (leer_facturas() as $f) {
        if ($f['numero'] === $numero) {
            return $f;
        }
    }
    return null;
}

$base = ['cliente' => 'Clienta', 'telefono' => '3001112222', 'nota' => '', 'subtotal' => 90000, 'descuento' => 0,
         'total' => 90000, 'costo' => 40000, 'margen' => 50000, 'abono' => 0, 'saldo_aplicado' => 0];
$linea = fn(string $sku, int $cant) => ['sku' => $sku, 'nombre' => "Producto $sku", 'marca' => 'M', 'precio' => 30000, 'costo' => 15000, 'cant' => $cant];

// A: a cuotas, con abonos y un descuento repartidos en varias cuotas
$a = crear_factura($base + ['lineas' => [$linea('A1', 1), $linea('A2', 2)], 'plan' => 'cuotas', 'pagado' => 0, 'fecha_pago' => null,
    'cuotas' => [['monto' => 30000, 'vence' => '2026-10-01', 'abonos' => []], ['monto' => 30000, 'vence' => '2026-11-01', 'abonos' => []],
                 ['monto' => 30000, 'vence' => '2026-12-01', 'abonos' => []]]]);
registrar_abono($a, 0, 30000);
registrar_abono($a, 1, 10000);
aplicar_descuento_cuota($a, 2, 5000, 'Cliente frecuente');
// B: de contado, sin cuotas
$b = crear_factura($base + ['lineas' => [$linea('B1', 3)], 'plan' => 'contado', 'cuotas' => [], 'pagado' => 90000, 'fecha_pago' => '2026-09-01']);
// C: a cuotas con abonos propios (para comprobar que los de A no se le mezclan), luego anulada
$c = crear_factura($base + ['lineas' => [], 'plan' => 'cuotas', 'pagado' => 0, 'fecha_pago' => null,
    'cuotas' => [['monto' => 90000, 'vence' => '2026-10-15', 'abonos' => []]]]);
registrar_abono($c, 0, 20000);
anular_factura($c, 'Prueba');
// D: sin líneas ni cuotas
$d = crear_factura($base + ['lineas' => [], 'plan' => 'contado', 'cuotas' => [], 'pagado' => 90000, 'fecha_pago' => '2026-09-02']);

foreach (['A (cuotas, abonos y descuento)' => $a, 'B (contado)' => $b, 'C (anulada, con abonos propios)' => $c, 'D (sin líneas)' => $d] as $nombre => $n) {
    $nueva = factura_por_numero($n);
    verificar($nueva !== null && $nueva === factura_por_numero_anterior($n),
        "factura $nombre: la lectura nueva devuelve exactamente lo mismo que la anterior");
}

$fa = factura_por_numero($a);
verificar(count($fa['cuotas'][0]['abonos']) === 1 && count($fa['cuotas'][1]['abonos']) === 1
    && $fa['cuotas'][2]['abonos'][0]['tipo'] === 'descuento' && count($fa['lineas']) === 2,
    'la factura A trae solo sus propias líneas, cuotas y abonos');
verificar(count(factura_por_numero($c)['cuotas'][0]['abonos']) === 1,
    'los abonos de otra factura no se mezclan en la anulada');

foreach (['' => 'vacío', 'no-existe' => 'inexistente', $a . ' ' => 'con espacio al final', ' ' . $a => 'con espacio al inicio',
          strtolower($a) . 'x' => 'parecido', '9999-0001' => 'con formato válido pero sin factura',
          "$a' OR '1'='1" => 'con intento de inyección SQL'] as $numero => $caso) {
    verificar(factura_por_numero((string) $numero) === null && factura_por_numero_anterior((string) $numero) === null,
        "un número $caso no encuentra ninguna factura (igual que antes)");
}

terminar_pruebas();
