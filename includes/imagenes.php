<?php
declare(strict_types=1);

/* =========================================================
   Fotos de producto · un archivo por SKU en assets/img/productos/
   =========================================================
   Antes la foto de un producto se controlaba con dos arreglos a mano en
   catalogo-publico.php ($fotosPorSku y $fotosActivas): cada foto nueva
   o cada reemplazo significaba editar código y subirlo. Ahora la foto
   de un producto ES el archivo que tiene su SKU de nombre — se sube
   desde Productos y aparece de inmediato en el catálogo público; no
   hay nada que editar ni desplegar.
   ========================================================= */

define('PRODUCTOS_IMG_DIR', BASE_DIR . '/assets/img/productos');
define('PRODUCTOS_IMG_WEB', 'assets/img/productos');
define('PRODUCTOS_IMG_MAX', 1200); // lado más largo que se guarda, en px
define('PRODUCTOS_IMG_EXTS', ['webp', 'jpg', 'jpeg', 'png']);

/** Ruta relativa (para <img src>) de la foto de un SKU, o null si no tiene. */
function producto_ruta_foto(string $sku): ?string
{
    foreach (PRODUCTOS_IMG_EXTS as $ext) {
        $archivo = $sku . '.' . $ext;
        if (is_file(PRODUCTOS_IMG_DIR . '/' . $archivo)) {
            return PRODUCTOS_IMG_WEB . '/' . $archivo;
        }
    }
    return null;
}

/**
 * Guarda la foto subida para un SKU: la redimensiona si hace falta y la
 * deja siempre como <SKU>.webp, sin importar el formato en que llegó.
 * Devuelve '' si quedó bien, o un mensaje de error para mostrar.
 */
function guardar_imagen_producto(string $sku, array $archivo): string
{
    if (!preg_match('/^[A-Z0-9\-_]+$/', $sku)) {
        return 'SKU inválido.';
    }
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return 'Elige una imagen para subir.';
    }
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        return 'No se pudo recibir el archivo. Inténtalo de nuevo.';
    }
    if ($archivo['size'] > 8 * 1024 * 1024) {
        return 'La imagen pesa más de 8 MB. Usa una más liviana.';
    }

    // El tipo real del archivo, no la extensión de su nombre: un .jpg
    // renombrado a .png no debe colarse como si fuera PNG.
    $info = @getimagesize($archivo['tmp_name']);
    if ($info === false) {
        return 'Ese archivo no es una imagen válida.';
    }

    $origen = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($archivo['tmp_name']),
        IMAGETYPE_PNG  => @imagecreatefrompng($archivo['tmp_name']),
        IMAGETYPE_WEBP => @imagecreatefromwebp($archivo['tmp_name']),
        default        => null,
    };
    if (!$origen) {
        return 'Formato no soportado. Usa una foto en JPG, PNG o WebP.';
    }

    // Preserva la transparencia (si la hay) en vez de aplanarla a negro:
    // el destino es WebP, que sí la soporta.
    imagepalettetotruecolor($origen);
    imagealphablending($origen, true);
    imagesavealpha($origen, true);

    $anchoOrig = imagesx($origen);
    $altoOrig  = imagesy($origen);
    $lado      = max($anchoOrig, $altoOrig);

    if ($lado > PRODUCTOS_IMG_MAX) {
        $factor = PRODUCTOS_IMG_MAX / $lado;
        $ancho  = max(1, (int) round($anchoOrig * $factor));
        $alto   = max(1, (int) round($altoOrig * $factor));

        $destino = imagecreatetruecolor($ancho, $alto);
        imagealphablending($destino, false);
        imagesavealpha($destino, true);
        imagecopyresampled($destino, $origen, 0, 0, 0, 0, $ancho, $alto, $anchoOrig, $altoOrig);
        imagedestroy($origen);
        $origen = $destino;
    }

    if (!is_dir(PRODUCTOS_IMG_DIR) && !mkdir(PRODUCTOS_IMG_DIR, 0775, true) && !is_dir(PRODUCTOS_IMG_DIR)) {
        imagedestroy($origen);
        return 'No existe la carpeta de imágenes y no se pudo crear.';
    }

    $ok = imagewebp($origen, PRODUCTOS_IMG_DIR . '/' . $sku . '.webp', 82);
    imagedestroy($origen);

    if (!$ok) {
        return 'No se pudo guardar la imagen. Inténtalo de nuevo.';
    }

    // Si antes tenía foto en otro formato (jpg/png), se borra: si no, la
    // vieja queda huérfana en el disco compitiendo en silencio con la
    // nueva (producto_ruta_foto() prefiere .webp, pero no hay razón para
    // dejar la copia vieja ahí).
    foreach (['jpg', 'jpeg', 'png'] as $ext) {
        $vieja = PRODUCTOS_IMG_DIR . '/' . $sku . '.' . $ext;
        if (is_file($vieja)) {
            unlink($vieja);
        }
    }

    return '';
}

/** Quita la foto de un SKU (cualquier extensión). Devuelve true si borró algo. */
function quitar_imagen_producto(string $sku): bool
{
    if (!preg_match('/^[A-Z0-9\-_]+$/', $sku)) {
        return false;
    }
    $borrado = false;
    foreach (PRODUCTOS_IMG_EXTS as $ext) {
        $ruta = PRODUCTOS_IMG_DIR . '/' . $sku . '.' . $ext;
        if (is_file($ruta)) {
            unlink($ruta);
            $borrado = true;
        }
    }
    return $borrado;
}
