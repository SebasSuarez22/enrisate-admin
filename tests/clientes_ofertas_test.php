<?php
declare(strict_types=1);

// Solo desde la terminal: si alguien la abre desde el navegador, no existe.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/clientes.php';

$fallos = 0;
function verificar(bool $cond, string $msg): void
{
    global $fallos;
    echo ($cond ? "OK   - " : "FAIL - ") . $msg . "\n";
    if (!$cond) { $fallos++; }
}

/* Mensajes de WhatsApp con ofrecimiento desde Clientes (mensaje_oferta_cliente):
   cambian según el dato de compras que se toca, sin prometer descuentos ni
   precios, y usan el primer nombre de la clienta. */

$ana = ['nombre' => 'Ana María Torres', 'telefono' => '3001234567'];

// Cada ~30 días, última compra hace 45: ya le toca reponer.
$tarde = ['compras' => 4, 'frecuencia_dias' => 30, 'favorito' => 'Rapunzel', 'ultima_compra' => '2026-08-18'];
$m = mensaje_oferta_cliente($ana, $tarde, 'frecuencia', '2026-10-02');
verificar(str_starts_with($m, 'Hola Ana 💛'), 'saluda con el primer nombre');
verificar(str_contains($m, '45 días') && str_contains($m, '~30 días') && str_contains($m, 'Rapunzel'),
    'si ya pasó su frecuencia: dice cuántos días van, cada cuánto repone y ofrece su favorito');

// Cada ~30 días, última compra hace 10: todavía no le toca.
$pronto = ['compras' => 4, 'frecuencia_dias' => 30, 'favorito' => 'Rapunzel', 'ultima_compra' => '2026-09-22'];
$m = mensaje_oferta_cliente($ana, $pronto, 'frecuencia', '2026-10-02');
verificar(str_contains($m, 'pronto te toca') && !str_contains($m, 'han pasado'),
    'si todavía no le toca: lo dice suave, sin reclamar días');

// Justo el día que le toca (frecuencia = días desde la última): cuenta como "ya le toca".
$justo = ['compras' => 3, 'frecuencia_dias' => 10, 'favorito' => null, 'ultima_compra' => '2026-09-22'];
$m = mensaje_oferta_cliente($ana, $justo, 'frecuencia', '2026-10-02');
verificar(str_contains($m, '10 días desde tu última compra') && str_contains($m, 'tus productos'),
    'el día exacto cuenta como "ya le toca"; sin favorito dice "tus productos"');

// Compra muy seguido (frecuencia 0).
$seguido = ['compras' => 5, 'frecuencia_dias' => 0, 'favorito' => 'Anti-Friction', 'ultima_compra' => '2026-10-01'];
$m = mensaje_oferta_cliente($ana, $seguido, 'frecuencia', '2026-10-02');
verificar(str_contains($m, 'más nos compran') && str_contains($m, 'Anti-Friction'),
    'si compra muy seguido: la agradece y ofrece repetir su favorito');

// Favorito.
$m = mensaje_oferta_cliente($ana, $tarde, 'favorito', '2026-10-02');
verificar(str_contains($m, 'Rapunzel es de tus favoritos') && str_contains($m, 'aparte'),
    'favorito: ofrece apartar su producto favorito');

// Primera compra (con y sin favorito).
$una = ['compras' => 1, 'frecuencia_dias' => null, 'favorito' => 'Shampoo Cebolla', 'ultima_compra' => '2026-09-30'];
$m = mensaje_oferta_cliente($ana, $una, 'primera', '2026-10-02');
verificar(str_contains($m, 'primera compra') && str_contains($m, 'tu Shampoo Cebolla'),
    'primera compra: la agradece y pregunta por su producto');
$unaSin = ['compras' => 1, 'frecuencia_dias' => null, 'favorito' => null, 'ultima_compra' => '2026-09-30'];
verificar(!str_contains(mensaje_oferta_cliente($ana, $unaSin, 'primera', '2026-10-02'), ' con tu '),
    'primera compra sin favorito: no inventa un producto');

// Frecuencia pedida pero sin dato (1 sola compra): cae al favorito, no se rompe.
$m = mensaje_oferta_cliente($ana, $una, 'frecuencia', '2026-10-02');
verificar(str_contains($m, 'Shampoo Cebolla es de tus favoritos'),
    'frecuencia sin dato de frecuencia: usa el mensaje del favorito');

// Nunca promete descuento ni precio.
foreach ([$tarde, $pronto, $justo, $seguido, $una] as $st) {
    foreach (['frecuencia', 'favorito', 'primera'] as $t) {
        $txt = mb_strtolower(mensaje_oferta_cliente($ana, $st, $t, '2026-10-02'));
        if (str_contains($txt, 'descuento') || str_contains($txt, '%') || str_contains($txt, '$')) {
            verificar(false, "el mensaje '$t' no debe prometer descuentos ni precios");
        }
    }
}
verificar(true, 'ningún mensaje promete descuentos ni precios');

// Sin nombre: saluda sin nombre, sin romperse.
$m = mensaje_oferta_cliente(['nombre' => '', 'telefono' => ''], $tarde, 'favorito', '2026-10-02');
verificar(str_starts_with($m, 'Hola 💛'), 'sin nombre: saluda sin nombre');

// Estadísticas: ahora también devuelven la fecha de la última compra.
$facturas = [
    ['cliente' => 'Ana María Torres', 'telefono' => '3001234567', 'creada' => '2026-07-01T10:00:00-05:00', 'lineas' => [['nombre' => 'Rapunzel', 'cant' => 1]]],
    ['cliente' => 'Ana María Torres', 'telefono' => '3001234567', 'creada' => '2026-08-18T10:00:00-05:00', 'lineas' => [['nombre' => 'Rapunzel', 'cant' => 2]]],
    ['cliente' => 'Ana María Torres', 'telefono' => '3001234567', 'creada' => '2026-09-01T10:00:00-05:00', 'anulada' => true, 'lineas' => []],
];
$est = estadisticas_compra_cliente($ana, $facturas);
verificar($est !== null && $est['ultima_compra'] === '2026-08-18' && $est['compras'] === 2 && $est['favorito'] === 'Rapunzel',
    'estadísticas: última compra = la más reciente que no está anulada');

echo "\n" . ($fallos === 0 ? "TODO OK" : "$fallos prueba(s) fallaron") . "\n";
exit($fallos === 0 ? 0 : 1);
