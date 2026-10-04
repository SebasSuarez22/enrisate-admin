<?php
declare(strict_types=1);

// Solo desde la terminal: si alguien la abre desde el navegador, no existe.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/imagenes.php';

$fallos = 0;
function verificar(bool $cond, string $msg): void
{
    global $fallos;
    echo ($cond ? "OK   - " : "FAIL - ") . $msg . "\n";
    if (!$cond) { $fallos++; }
}

/* Fondo de las fotos de producto (quitar_fondo_uniforme): al subir una foto con
   un fondo liso que no es blanco, el fondo pasa a transparente y el producto
   queda intacto, para que se funda con la ficha del catálogo público. */

const T = 200; // lado de las imágenes de prueba

/** Imagen T×T con el fondo dado por $fondo($x,$y) → [r,g,b] o [r,g,b,alfa7] y un "producto" rosado al centro. */
function foto_prueba(callable $fondo, bool $conProducto = true, bool $conSombra = false): GdImage
{
    $im = imagecreatetruecolor(T, T);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    for ($y = 0; $y < T; $y++) {
        for ($x = 0; $x < T; $x++) {
            $c = $fondo($x, $y);
            imagesetpixel($im, $x, $y, ((($c[3] ?? 0) << 24) | ($c[0] << 16) | ($c[1] << 8) | $c[2]));
        }
    }
    if ($conSombra) {
        // Sombra de apoyo: elipse suave más oscura que el fondo, bajo el producto.
        for ($y = 165; $y < 185; $y++) {
            for ($x = 60; $x < 140; $x++) {
                $dx = ($x - 100) / 40; $dy = ($y - 175) / 10;
                $k = 1 - min(1, sqrt($dx * $dx + $dy * $dy));
                if ($k > 0) {
                    $c = imagecolorat($im, $x, $y);
                    $r = (int) round((($c >> 16) & 255) * (1 - 0.35 * $k));
                    $g = (int) round((($c >> 8) & 255) * (1 - 0.35 * $k));
                    $b = (int) round(($c & 255) * (1 - 0.35 * $k));
                    imagesetpixel($im, $x, $y, ($r << 16) | ($g << 8) | $b);
                }
            }
        }
    }
    if ($conProducto) {
        imagefilledrectangle($im, 70, 40, 130, 165, (196 << 16) | (92 << 8) | 120);
    }
    return $im;
}
function alfa(GdImage $im, int $x, int $y): int { return (imagecolorat($im, $x, $y) >> 24) & 127; }
function rgb(GdImage $im, int $x, int $y): array { $c = imagecolorat($im, $x, $y); return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255]; }
function fondo_liso(array $c): callable { return fn($x, $y) => $c; }
function fondo_se_va(GdImage $im): bool
{
    // las 4 esquinas y los 4 puntos medios de los bordes quedaron transparentes
    foreach ([[3, 3], [T - 4, 3], [3, T - 4], [T - 4, T - 4], [100, 3], [100, T - 4], [3, 100], [T - 4, 100]] as [$x, $y]) {
        if (alfa($im, $x, $y) < 120) { return false; }
    }
    return true;
}
function producto_intacto(GdImage $im): bool
{
    foreach ([[100, 100], [75, 60], [125, 150], [100, 45]] as [$x, $y]) {
        if (alfa($im, $x, $y) !== 0 || rgb($im, $x, $y) !== [196, 92, 120]) { return false; }
    }
    return true;
}

// 1) Fondos lisos que NO son blancos: se quitan y el producto no cambia.
$casos = [
    'gris claro de estudio (235)' => [235, 235, 235],
    'gris medio (200)'            => [200, 200, 200],
    'rosado'                      => [240, 222, 228],
    'beige'                       => [225, 210, 190],
    'negro'                       => [20, 20, 20],
];
foreach ($casos as $nombre => $c) {
    $im = foto_prueba(fondo_liso($c));
    $cambio = quitar_fondo_uniforme($im);
    verificar($cambio && fondo_se_va($im) && producto_intacto($im), "fondo $nombre: el fondo queda transparente y el producto intacto");
}

// 2) Degradado de estudio (viñeta 244 → 212): también se va.
$degradado = fn($x, $y) => [($v = (int) round(244 - 32 * min(1, hypot($x - 100, $y - 100) / 140))), $v, $v];
$im = foto_prueba($degradado);
verificar(quitar_fondo_uniforme($im) && fondo_se_va($im) && producto_intacto($im), 'degradado de estudio: el fondo queda transparente y el producto intacto');

// 3) Fondo con ruido (como un JPG): se va igual.
mt_srand(7);
$ruido = function ($x, $y) { $v = 232 + mt_rand(-4, 4); return [$v, $v, $v]; };
$im = foto_prueba($ruido);
verificar(quitar_fondo_uniforme($im) && fondo_se_va($im) && producto_intacto($im), 'fondo con ruido de JPG: el fondo queda transparente y el producto intacto');

// 4) Blanco o casi blanco: NO se toca (multiply ya lo funde; igual que las fotos ya subidas).
foreach (['blanco 255' => [255, 255, 255], 'casi blanco 250' => [250, 250, 250], 'blanco 254' => [254, 254, 253]] as $nombre => $c) {
    $im = foto_prueba(fondo_liso($c));
    $antes = imagecolorat($im, 5, 5);
    verificar(!quitar_fondo_uniforme($im) && imagecolorat($im, 5, 5) === $antes && alfa($im, 5, 5) === 0, "fondo $nombre: no se toca");
}

// 5) Ya transparente: no se toca.
$im = foto_prueba(fondo_liso([0, 0, 0, 127]));
verificar(!quitar_fondo_uniforme($im), 'foto que ya trae fondo transparente: no se toca');

// 6) Foto de ambiente (borde que no es parejo): no se toca.
mt_srand(3);
$ambiente = fn($x, $y) => [mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)];
$im = foto_prueba($ambiente);
verificar(!quitar_fondo_uniforme($im) && alfa($im, 3, 3) === 0, 'foto de ambiente (borde sin color parejo): no se toca');

// 7) Mala detección: el producto ocupa casi todo el cuadro → se deja como llegó.
$im = imagecreatetruecolor(T, T);
imagefilledrectangle($im, 0, 0, T, T, (196 << 16) | (92 << 8) | 120);
imagefilledrectangle($im, 0, 0, 4, T, (235 << 16) | (235 << 8) | 235); // solo una franja gris de borde
verificar(!quitar_fondo_uniforme($im), 'si lo que se quitaría es casi nada o casi todo: se deja como llegó');

// 8) La sombra de apoyo se conserva como sombra semitransparente (no un recuadro gris).
$im = foto_prueba(fondo_liso([225, 225, 225]), true, true);
$quitado = quitar_fondo_uniforme($im);
$a = alfa($im, 100, 175);
verificar($quitado && $a > 5 && $a < 120 && rgb($im, 100, 175) === [0, 0, 0], 'la sombra de apoyo queda como sombra negra semitransparente (alfa ' . $a . ')');
verificar(alfa($im, 5, 175) >= 120, 'lejos del producto el fondo sigue transparente aunque haya sombra');

// 9) El contorno se suaviza sin dejar halo del color viejo: ningún píxel opaco pegado al producto es gris de fondo.
$im = foto_prueba(fondo_liso([200, 200, 200]));
quitar_fondo_uniforme($im);
$halo = 0;
for ($y = 30; $y < 175; $y++) {
    foreach ([66, 67, 68, 132, 133, 134] as $x) {
        [$r, $g, $b] = rgb($im, $x, $y);
        if (alfa($im, $x, $y) < 30 && abs($r - 200) < 6 && abs($g - 200) < 6 && abs($b - 200) < 6) { $halo++; }
    }
}
verificar($halo === 0, 'sin halo: no quedan píxeles casi opacos del gris del fondo junto al producto');

// 10) Con imagen real de tamaño de catálogo (640×640) tarda poco.
$im = imagecreatetruecolor(640, 640);
imagefilledrectangle($im, 0, 0, 640, 640, (232 << 16) | (232 << 8) | 232);
imagefilledrectangle($im, 220, 100, 420, 560, (196 << 16) | (92 << 8) | 120);
$t0 = microtime(true);
$ok = quitar_fondo_uniforme($im);
$ms = (int) round((microtime(true) - $t0) * 1000);
verificar($ok && $ms < 3000, "una foto de 640×640 se procesa en menos de 3 s ($ms ms)");

/* procesar_foto_producto(): lo que se hace al subir y en "Revisar fondos de fotos". */

// 11) Gris claro de estudio → fondo blanco puro (no transparente) y el producto conserva su color,
//     apenas aclarado en la misma proporción que el fondo.
$im = foto_prueba(fondo_liso([235, 235, 235]));
[$im, $cambios] = procesar_foto_producto($im);
$w = imagesx($im); $h = imagesy($im);
[$r, $g, $b] = rgb($im, intdiv($w, 2), intdiv($h, 2));
$gan = 255 / 235;
verificar(in_array('blanqueada', $cambios, true) && rgb($im, 1, 1) === [255, 255, 255] && alfa($im, 1, 1) === 0,
    'gris claro de estudio: el fondo queda blanco puro');
verificar(abs($r - round(196 * $gan)) <= 1 && abs($g - round(92 * $gan)) <= 1 && abs($b - round(120 * $gan)) <= 1,
    "gris claro de estudio: el producto conserva su color (rgb $r,$g,$b)");

// 12) Con viñeta (esquinas más oscuras) también queda blanco hasta las esquinas.
$vineta = fn($x, $y) => [($v = (int) round(240 - 14 * min(1, hypot($x - 100, $y - 100) / 140))), $v, $v];
$im = foto_prueba($vineta);
[$im, $cambios] = procesar_foto_producto($im);
verificar(in_array('blanqueada', $cambios, true) && rgb($im, 0, 0) === [255, 255, 255], 'gris con viñeta: blanco hasta las esquinas');

// 13) Envase blanco sobre gris: blanquear borraría la silueta → no se blanquea y queda marcado para volver a subir.
$im = imagecreatetruecolor(T, T);
imagefilledrectangle($im, 0, 0, T, T, (232 << 16) | (232 << 8) | 232);
imagefilledrectangle($im, 70, 40, 130, 165, (250 << 16) | (250 << 8) | 250);
verificar(!blanquear_fondo_claro($im) && rgb($im, 100, 100) === [250, 250, 250], 'envase blanco sobre gris: no se blanquea (se perdería la silueta)');

// 14) Beige de color (no es un gris de estudio): no se blanquea, se vuelve transparente como antes.
$im = foto_prueba(fondo_liso([225, 210, 190]));
[$im, $cambios] = procesar_foto_producto($im);
verificar($cambios[0] === 'transparente' && alfa($im, 1, 1) >= 120, 'fondo beige: transparente, no blanqueado');

// 15) Márgenes: se recortan dejando un respiro de 4 % del lado mayor del producto.
$im = foto_prueba(fondo_liso([255, 255, 255]));
[$im, $cambios] = procesar_foto_producto($im);
$pad = (int) round(126 * 0.04);
verificar($cambios === ['recortada'] && imagesx($im) === 61 + 2 * $pad && imagesy($im) === 126 + 2 * $pad,
    'márgenes blancos recortados: ' . imagesx($im) . '×' . imagesy($im) . ' (producto 61×126 + respiro)');
verificar(rgb($im, $pad + 30, $pad + 60) === [196, 92, 120], 'el recorte no mueve ni cambia el producto');

// 16) Revisar dos veces no vuelve a cambiar nada.
$copia = imagecreatetruecolor(imagesx($im), imagesy($im));
imagecopy($copia, $im, 0, 0, 0, 0, imagesx($im), imagesy($im));
[$otra, $cambios2] = procesar_foto_producto($copia);
verificar($cambios2 === [] && imagesx($otra) === imagesx($im), 'procesar una foto ya procesada no cambia nada');

// 17) foto_fondo_ok(): blanco y transparente sí; gris o de color, no (se marca para volver a subir).
verificar(foto_fondo_ok(foto_prueba(fondo_liso([255, 255, 255]))), 'fondo blanco: se funde con el catálogo');
verificar(foto_fondo_ok(foto_prueba(fondo_liso([0, 0, 0, 127]))), 'fondo transparente: se funde con el catálogo');
verificar(!foto_fondo_ok(foto_prueba(fondo_liso([232, 232, 232]))), 'fondo gris: conviene volver a subirla');

echo "\n" . ($fallos === 0 ? "TODO OK" : "$fallos prueba(s) fallaron") . "\n";
exit($fallos === 0 ? 0 : 1);
