<?php
declare(strict_types=1);

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

/* Fotos del catálogo público: versión mini (360 px) para el srcset, que nunca se
   use si quedó vieja, y las medidas (ancho, alto y "pie") que calcula el servidor
   en vez del celular. Usa un SKU de prueba y lo borra al terminar. */

const SKU = 'ZZPRUEBA-01';

function imagen_prueba(int $w, int $h, int $vacioAbajo): \GdImage
{
    $img = imagecreatetruecolor($w, $h);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha($img, 255, 255, 255, 127));
    imagefilledrectangle($img, (int) ($w * .3), 0, (int) ($w * .7), $h - 1 - $vacioAbajo, imagecolorallocatealpha($img, 90, 40, 20, 0));
    return $img;
}

function limpiar(): void
{
    foreach (glob(PRODUCTOS_IMG_DIR . '/' . SKU . '.*') ?: [] as $f) {
        unlink($f);
    }
}
limpiar();
register_shutdown_function('limpiar');

/* --- Pie: aire vacío bajo el producto --- */
$img = imagen_prueba(200, 400, 20);                      // 20 de 400 filas vacías = 5 %
$pie = medir_pie_foto($img);
verificar($pie !== null && abs($pie - 0.05) < 0.012, 'mide el aire bajo el producto (≈5 %): ' . var_export($pie, true));
verificar(abs((float) medir_pie_foto(imagen_prueba(200, 400, 0))) < 0.012, 'producto apoyado en el borde: pie ≈ 0');
$blanca = imagecreatetruecolor(100, 100);
imagefill($blanca, 0, 0, imagecolorallocate($blanca, 255, 255, 255));
verificar(medir_pie_foto($blanca) === null, 'foto toda blanca: sin medida (la tarjeta usa el valor del CSS)');

/* --- Versiones tarjeta y mini --- */
if (!is_dir(PRODUCTOS_IMG_DIR)) {
    mkdir(PRODUCTOS_IMG_DIR, 0775, true);
}
$grande = imagen_prueba(800, 1000, 30);
imagewebp($grande, PRODUCTOS_IMG_DIR . '/' . SKU . '.webp', 82);
guardar_version_tarjeta($grande, SKU);
verificar(is_file(PRODUCTOS_IMG_DIR . '/' . SKU . '.tarjeta.webp'), 'foto de 1000 px: crea la versión de tarjeta');
$dimMini = @getimagesize(PRODUCTOS_IMG_DIR . '/' . SKU . '.mini.webp');
verificar($dimMini && max($dimMini[0], $dimMini[1]) === PRODUCTOS_IMG_MINI, 'crea la versión mini de 360 px');
$base = producto_ruta_foto_tarjeta(SKU);
verificar($base === PRODUCTOS_IMG_WEB . '/' . SKU . '.tarjeta.webp', 'la tarjeta usa la versión de tarjeta');
verificar(producto_ruta_foto_mini(SKU, $base) === PRODUCTOS_IMG_WEB . '/' . SKU . '.mini.webp', 'la mini está al día y se ofrece');

// Si la foto cambia y la mini todavía no se rehízo, la mini NO se usa (nunca una foto vieja).
touch(PRODUCTOS_IMG_DIR . '/' . SKU . '.tarjeta.webp', time() + 60);
clearstatcache();
verificar(producto_ruta_foto_mini(SKU, $base) === null, 'mini más vieja que la foto: no se usa');
actualizar_version_tarjeta(SKU);
clearstatcache();
touch(PRODUCTOS_IMG_DIR . '/' . SKU . '.mini.webp', time() + 120);
clearstatcache();
verificar(producto_ruta_foto_mini(SKU, producto_ruta_foto_tarjeta(SKU)) !== null, 'al rehacerla vuelve a ofrecerse');

/* --- Medidas guardadas --- */
$m = medidas_foto($base);
verificar($m !== null && $m['w'] === 512 && $m['h'] === 640, 'medidas: ancho y alto de la foto que muestra la tarjeta');
verificar($m !== null && $m['pie'] !== null && abs($m['pie'] - 0.03) < 0.012, 'medidas: pie ≈ 3 %');
verificar(medidas_foto($base) === $m, 'la segunda vez sale igual (de la caché)');
verificar(medidas_foto(PRODUCTOS_IMG_WEB . '/NO-EXISTE.webp') === null, 'foto que no existe: sin medidas');

/* --- Foto pequeña: sin versiones extra --- */
limpiar();
$chica = imagen_prueba(300, 300, 10);
imagewebp($chica, PRODUCTOS_IMG_DIR . '/' . SKU . '.webp', 82);
guardar_version_tarjeta($chica, SKU);
verificar(!is_file(PRODUCTOS_IMG_DIR . '/' . SKU . '.mini.webp') && !is_file(PRODUCTOS_IMG_DIR . '/' . SKU . '.tarjeta.webp'), 'foto de 300 px: no crea mini ni tarjeta');
verificar(producto_ruta_foto_mini(SKU, producto_ruta_foto_tarjeta(SKU)) === null, 'foto de 300 px: la tarjeta usa la misma foto');

/* --- Quitar la foto borra también la mini --- */
imagewebp($grande, PRODUCTOS_IMG_DIR . '/' . SKU . '.webp', 82);
guardar_version_tarjeta($grande, SKU);
quitar_imagen_producto(SKU);
verificar(glob(PRODUCTOS_IMG_DIR . '/' . SKU . '.*') === [], 'quitar la foto borra foto, tarjeta y mini');

echo "\n" . ($fallos === 0 ? "TODO OK" : "$fallos prueba(s) fallaron") . "\n";
exit($fallos === 0 ? 0 : 1);
