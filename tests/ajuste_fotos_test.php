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

/* Ajuste puntual de una foto (aplicar_ajuste_foto): se reemplaza solo si la foto de la
   tienda sigue siendo la que se retocó, con respaldo, una sola vez y sin pisar una
   foto que alguien subió después. Usa un SKU de prueba y lo borra al terminar. */

const SKU = 'ZZPRUEBA-02';
$tmp = sys_get_temp_dir() . '/ajuste_' . getmypid();
mkdir($tmp);

function limpiar(): void
{
    global $tmp;
    foreach (glob(PRODUCTOS_IMG_DIR . '/' . SKU . '.*') ?: [] as $f) {
        unlink($f);
    }
    foreach (glob(PRODUCTOS_AJUSTES_RESPALDO . '/' . SKU . '.*') ?: [] as $f) {
        unlink($f);
    }
    foreach (glob($tmp . '/*') ?: [] as $f) {
        unlink($f);
    }
    @rmdir($tmp);
}
limpiar();
mkdir($tmp);
register_shutdown_function('limpiar');

function foto(string $archivo, int $gris): void
{
    $img = imagecreatetruecolor(500, 500);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    imagefilledrectangle($img, 150, 50, 350, 450, imagecolorallocate($img, $gris, 40, 20));
    imagewebp($img, $archivo, 90);
    imagedestroy($img);
}

$tienda = PRODUCTOS_IMG_DIR . '/' . SKU . '.webp';
$nueva  = $tmp . '/nueva.webp';
foto($tienda, 90);
foto($nueva, 200);
$shaVieja = hash_file('sha256', $tienda);

verificar(aplicar_ajuste_foto('ZZNOEXISTE-01', $shaVieja, $nueva) === 'sin_foto', 'sin foto en la tienda: no hace nada');
verificar(aplicar_ajuste_foto(SKU, $shaVieja, $tmp . '/no-hay.webp') === 'error', 'sin archivo retocado: error, la foto no se toca');
verificar(hash_file('sha256', $tienda) === $shaVieja, 'tras el error la foto sigue igual');

verificar(aplicar_ajuste_foto(SKU, str_repeat('0', 64), $nueva) === 'distinta', 'si la foto no es la retocada, no se toca');
verificar(hash_file('sha256', $tienda) === $shaVieja, 'foto distinta: queda igual');

verificar(aplicar_ajuste_foto(SKU, $shaVieja, $nueva) === 'aplicado', 'foto igual a la retocada: se reemplaza');
verificar(hash_file('sha256', $tienda) === hash_file('sha256', $nueva), 'la foto de la tienda quedó como la retocada');
$respaldos = glob(PRODUCTOS_AJUSTES_RESPALDO . '/' . SKU . '.*.webp') ?: [];
verificar(count($respaldos) === 1 && hash_file('sha256', $respaldos[0]) === $shaVieja, 'la original quedó respaldada, idéntica');
verificar(is_file(PRODUCTOS_IMG_DIR . '/' . SKU . '.mini.webp'), 'se rehízo la versión mini');
verificar(!is_file($tienda . '.tmp'), 'no quedan archivos temporales');

verificar(aplicar_ajuste_foto(SKU, $shaVieja, $nueva) === 'ya_aplicado', 'segunda vez: ya estaba ajustada');
verificar(count(glob(PRODUCTOS_AJUSTES_RESPALDO . '/' . SKU . '.*.webp') ?: []) === 1, 'segunda vez: no hay otro respaldo');

foto($tienda, 20);
$shaOtra = hash_file('sha256', $tienda);
verificar(aplicar_ajuste_foto(SKU, $shaVieja, $nueva) === 'distinta' && hash_file('sha256', $tienda) === $shaOtra, 'una foto subida después se respeta');

verificar(aplicar_ajuste_foto('../ZZ', $shaVieja, $nueva) === 'error', 'un SKU con ruta no se acepta');

echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos FALLO(S)\n";
exit($fallos === 0 ? 0 : 1);
