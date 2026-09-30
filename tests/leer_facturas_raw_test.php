<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/* Persistencia en MySQL: lo que se guarda es lo que se lee.

   guardar_facturas() reescribe TODAS las facturas en cada cambio (DELETE +
   INSERT de todo), así que estas pruebas cuidan que ese ciclo no pierda ni
   cambie nada: ni de la factura que se toca ni, sobre todo, de las demás. */

function factura_cruda(string $numero): ?array
{
    foreach (leer_facturas_raw() as $f) {
        if ($f['numero'] === $numero) {
            return $f;
        }
    }
    return null;
}

/* --- Una factura completa: líneas, cuotas, abonos, acentos y emoji --- */

$cliente = 'María José Peñaloza Ñañez';
$nota    = "Entregar en la portería 🌸\nSegundo piso — ¡gracias!";

$numeroA = crear_factura([
    'cliente' => $cliente, 'telefono' => '3011234567', 'nota' => $nota,
    'lineas' => [
        ['sku' => 'ACC-04', 'nombre' => 'Shampoo reparador de puntas', 'marca' => 'Skala',
         'precio' => 45000, 'costo' => 22000, 'cant' => 2],
        ['sku' => 'ACC-09', 'nombre' => 'Gel fijador 250ml', 'marca' => 'Skala',
         'precio' => 18000, 'costo' => 9000, 'cant' => 1],
    ],
    'subtotal' => 108000, 'descuento' => 5000, 'total' => 103000, 'costo' => 53000, 'margen' => 50000,
    'plan' => 'cuotas', 'abono' => 20000, 'saldo_aplicado' => 3000,
    'cuotas' => [
        ['monto' => 40000, 'vence' => '2026-09-14', 'abonos' => []],
        ['monto' => 40000, 'vence' => '2026-10-14', 'abonos' => []],
    ],
    'pagado' => 23000, 'fecha_pago' => null,
]);

verificar(registrar_abono($numeroA, 0, 15000) === true,
    'setup: se registra un abono en la primera cuota');
verificar(aplicar_descuento_cuota($numeroA, 1, 5000, 'Cliente frecuente 🎁') === true,
    'setup: se aplica un descuento en la segunda cuota');

$a = factura_cruda($numeroA);
verificar($a !== null, 'la factura guardada aparece al leer de MySQL');
verificar($a['cliente'] === $cliente,
    'acentos y ñ en el nombre del cliente sobreviven al viaje por MySQL (utf8mb4)');
verificar($a['nota'] === $nota,
    'emoji, saltos de línea y guiones largos en la nota sobreviven al viaje por MySQL');
// Leer bien no basta: con un charset de conexión equivocado el texto regresa
// idéntico "por casualidad" pero queda guardado corrupto en la base (se ve
// mal en phpMyAdmin, en exportaciones y en búsquedas). Se cuenta cómo quedó
// guardado, en caracteres, directamente en MySQL.
$enBase = db()->prepare('SELECT CHAR_LENGTH(cliente) AS c, CHAR_LENGTH(nota) AS n FROM facturas WHERE numero = ?');
$enBase->execute([$numeroA]);
$enBase = $enBase->fetch();
verificar((int) $enBase['c'] === mb_strlen($cliente) && (int) $enBase['n'] === mb_strlen($nota),
    'en la base, el texto con acentos y emoji queda guardado con su largo real (sin corromperse)');
verificar(count($a['lineas']) === 2 && $a['lineas'][0]['sku'] === 'ACC-04' && $a['lineas'][1]['sku'] === 'ACC-09',
    'las líneas se leen completas y en el mismo orden en que se guardaron');
verificar($a['lineas'][0]['cant'] === 2 && $a['lineas'][0]['precio'] === 45000 && $a['lineas'][0]['costo'] === 22000,
    'cantidad, precio y costo de una línea vuelven como enteros, sin cambios');
verificar(count($a['cuotas']) === 2 && $a['cuotas'][0]['vence'] === '2026-09-14' && $a['cuotas'][1]['vence'] === '2026-10-14',
    'las cuotas se leen completas y en orden, con su fecha de vencimiento');
verificar(count($a['cuotas'][0]['abonos']) === 1 && $a['cuotas'][0]['abonos'][0]['monto'] === 15000
    && $a['cuotas'][0]['abonos'][0]['tipo'] === 'abono',
    'un abono queda en su cuota, con su monto y tipo "abono"');
verificar(count($a['cuotas'][1]['abonos']) === 1 && $a['cuotas'][1]['abonos'][0]['tipo'] === 'descuento'
    && $a['cuotas'][1]['abonos'][0]['motivo'] === 'Cliente frecuente 🎁',
    'un descuento conserva su tipo "descuento" y su motivo (con emoji)');
verificar($a['fecha_pago'] === null,
    'fecha_pago vacía vuelve como null (no como cadena vacía)');
verificar($a['anulada'] === false && $a['anulada_el'] === null,
    'una factura sin anular vuelve con anulada=false y sin fecha de anulación');
verificar($a['abono'] === 20000 && $a['saldo_aplicado'] === 3000 && $a['descuento'] === 5000,
    'abono inicial, saldo aplicado y descuento vuelven como enteros, sin cambios');
verificar($a['pagado'] === 20000 + 3000 + 15000 + 5000,
    'pagado suma saldo + abono inicial + abonos + descuentos de las cuotas');

/* --- Una factura de contado: sin cuotas, pero la clave "cuotas" siempre existe --- */

$numeroB = crear_factura([
    'cliente' => 'Cliente de contado', 'telefono' => '', 'nota' => '', 'lineas' => [],
    'subtotal' => 50000, 'descuento' => 0, 'total' => 50000, 'costo' => 30000, 'margen' => 20000,
    'plan' => 'contado', 'abono' => 0, 'saldo_aplicado' => 0, 'cuotas' => [],
    'pagado' => 50000, 'fecha_pago' => '2026-09-01',
]);

$b = factura_cruda($numeroB);
verificar(isset($b['cuotas']) && $b['cuotas'] === [],
    'una factura de contado se lee con "cuotas" como lista vacía (la clave siempre existe)');
verificar($b['fecha_pago'] === '2026-09-01',
    'una fecha de pago real se lee tal cual');
verificar($numeroB !== $numeroA && (int) substr($numeroB, -4) === (int) substr($numeroA, -4) + 1,
    'la numeración es consecutiva: la segunda factura es el número siguiente a la primera');

/* --- Tocar una factura no debe cambiar las demás --- */

$antesA = factura_cruda($numeroA);
verificar(anular_factura($numeroB, 'Se equivocaron de cliente') === true,
    'setup: se anula la factura de contado');
$despuesA = factura_cruda($numeroA);
verificar($despuesA == $antesA,
    'anular la factura B deja la factura A idéntica, hasta el último abono (el "reescribir todo" no la daña)');

$b = factura_cruda($numeroB);
verificar($b['anulada'] === true && $b['motivo_anulacion'] === 'Se equivocaron de cliente' && $b['anulada_el'] !== null,
    'la factura anulada guarda que está anulada, cuándo y por qué');

verificar(reactivar_factura($numeroB) === true, 'setup: se reactiva la factura anulada');
$b = factura_cruda($numeroB);
verificar($b['anulada'] === false, 'reactivar la deja otra vez como no anulada');

verificar(registrar_abono($numeroA, 0, 25000) === true,
    'setup: se completa la primera cuota de la factura A');
$bDespues = factura_cruda($numeroB);
verificar($bDespues['total'] === 50000 && $bDespues['pagado'] === 50000 && $bDespues['cuotas'] === [],
    'abonar en la factura A tampoco altera la factura B');

/* --- El listado completo devuelve todas, sin duplicar ni perder --- */

$numeros = array_column(leer_facturas(), 'numero');
verificar(count($numeros) === 2 && in_array($numeroA, $numeros, true) && in_array($numeroB, $numeros, true),
    'leer_facturas() devuelve exactamente las 2 facturas creadas, sin duplicadas ni perdidas');

terminar_pruebas();
