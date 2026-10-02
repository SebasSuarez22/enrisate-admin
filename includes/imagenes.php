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
// Lado más largo que se guarda, en px. La tarjeta del catálogo público
// nunca la muestra a más de ~210px de ancho (7 columnas en el punto más
// ancho), así que 1200px era puro peso sin ganancia de nitidez ni
// siquiera en pantallas de 3x: una foto de celular normal salía en
// ~400-500 KB. 640px es lo que ya usan las fotos reales que había antes
// de esta función (20-30 KB cada una) y se ve igual de nítida.
define('PRODUCTOS_IMG_MAX', 640);
define('PRODUCTOS_IMG_EXTS', ['webp', 'jpg', 'jpeg', 'png']);

/**
 * Quita el fondo liso de una foto de producto para que se funda con el fondo
 * de la ficha del catálogo (que usa mix-blend-mode: multiply: eso solo borra el
 * BLANCO; un fondo gris, de color o negro se vería como un recuadro).
 *
 * Qué hace (solo si la foto trae un fondo liso que no es blanco):
 *   1. Mide el color del borde. Si el borde es casi blanco, no toca nada
 *      (multiply ya lo resuelve, igual que las fotos que ya están subidas),
 *      si ya trae transparencia, tampoco, y si el borde no es parejo
 *      (foto de ambiente, collage) tampoco.
 *   2. Desde el borde "inunda" hacia adentro mientras el color cambie suave
 *      (degradados de estudio, viñeta) y se detiene en el contorno del producto.
 *   3. Lo inundado pasa a transparente; lo más oscuro que el fondo (la sombra
 *      que el producto proyecta) queda como una sombra negra semitransparente,
 *      que sobre cualquier fondo se ve como sombra.
 *   4. Suaviza 2 px del contorno para que no quede un halo del color viejo.
 * Si lo que se quitaría es casi todo o casi nada (mala detección), deja la
 * foto como llegó. Devuelve true solo si la modificó.
 */
function quitar_fondo_uniforme(\GdImage $img): bool
{
    $w = imagesx($img);
    $h = imagesy($img);
    if ($w < 40 || $h < 40) {
        return false;
    }

    // Todos los píxeles en un arreglo plano (idx = y * $w + x).
    $px = [];
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $px[] = imagecolorat($img, $x, $y);
        }
    }

    // Anillo de 2 px del borde.
    $anillo = [];
    for ($y = 0; $y < $h; $y++) {
        $borde = ($y < 2 || $y >= $h - 2);
        for ($x = 0; $x < $w; $x++) {
            if ($borde || $x < 2 || $x >= $w - 2) {
                $anillo[] = $y * $w + $x;
            }
        }
    }

    // ¿Ya trae transparencia en el borde? Entonces no hay nada que quitar.
    $transparentes = 0;
    $hr = $hg = $hb = array_fill(0, 256, 0);
    foreach ($anillo as $i) {
        $c = $px[$i];
        if ((($c >> 24) & 127) >= 16) {
            $transparentes++;
            continue;
        }
        $hr[($c >> 16) & 255]++;
        $hg[($c >> 8) & 255]++;
        $hb[$c & 255]++;
    }
    $n = count($anillo);
    if ($transparentes > 0.05 * $n) {
        return false;
    }

    // Color del fondo = mediana de cada canal en el borde.
    $mediana = function (array $hist, int $total): int {
        $acum = 0;
        foreach ($hist as $valor => $cuantos) {
            $acum += $cuantos;
            if ($acum >= $total / 2) {
                return $valor;
            }
        }
        return 255;
    };
    $total = $n - $transparentes;
    $br = $mediana($hr, $total);
    $bg = $mediana($hg, $total);
    $bb = $mediana($hb, $total);

    // Casi blanco: multiply ya lo funde. No se toca (comportamiento de siempre).
    if (min($br, $bg, $bb) >= 244) {
        return false;
    }

    $dist = function (int $c) use ($br, $bg, $bb): int {
        return max(abs((($c >> 16) & 255) - $br), abs((($c >> 8) & 255) - $bg), abs(($c & 255) - $bb));
    };

    // ¿El borde es parejo? (si no, es una foto de ambiente: no se toca).
    $parejos = 0;
    foreach ($anillo as $i) {
        if ($dist($px[$i]) <= 45) {
            $parejos++;
        }
    }
    if ($parejos < 0.9 * $n) {
        return false;
    }

    $lumB = ($br + $bg + $bb) / 3;

    // ¿Este píxel puede ser fondo? Cerca del color del fondo, o una versión
    // más oscura del mismo tono (la sombra del producto).
    $esFondo = function (int $c) use ($br, $bg, $bb, $lumB, $dist): bool {
        if ((($c >> 24) & 127) >= 16) {
            return false;
        }
        if ($dist($c) <= 45) {
            return true;
        }
        if ($lumB < 30) {
            return false;
        }
        $r = (($c >> 16) & 255) / max(1, $br);
        $g = (($c >> 8) & 255) / max(1, $bg);
        $b = ($c & 255) / max(1, $bb);
        $mayor = max($r, $g, $b);
        $menor = min($r, $g, $b);
        return $mayor <= 1.02 && $menor >= 0.35 && $mayor <= $menor * 1.12;
    };

    // Inundación desde el borde: sigue mientras el color cambie suave
    // (≤ 12 de un píxel al vecino) y se detiene en el contorno del producto.
    $fondo  = str_repeat("\0", $w * $h);
    $pila   = [];
    foreach ($anillo as $i) {
        if ($fondo[$i] === "\0" && $esFondo($px[$i]) && $dist($px[$i]) <= 45) {
            $fondo[$i] = "\1";
            $pila[] = $i;
        }
    }
    while ($pila) {
        $i = array_pop($pila);
        $c = $px[$i];
        $x = $i % $w;
        $vecinos = [];
        if ($x > 0)          { $vecinos[] = $i - 1; }
        if ($x < $w - 1)     { $vecinos[] = $i + 1; }
        if ($i >= $w)        { $vecinos[] = $i - $w; }
        if ($i < $w * ($h - 1)) { $vecinos[] = $i + $w; }
        foreach ($vecinos as $j) {
            if ($fondo[$j] !== "\0") {
                continue;
            }
            $cj = $px[$j];
            if (!$esFondo($cj)) {
                continue;
            }
            $paso = max(abs((($cj >> 16) & 255) - (($c >> 16) & 255)),
                        abs((($cj >> 8) & 255) - (($c >> 8) & 255)),
                        abs(($cj & 255) - ($c & 255)));
            if ($paso <= 12) {
                $fondo[$j] = "\1";
                $pila[] = $j;
            }
        }
    }

    $cantidad = substr_count($fondo, "\1");
    $fraccion = $cantidad / ($w * $h);
    if ($fraccion < 0.15 || $fraccion > 0.92) {
        return false; // mala detección: mejor dejar la foto como llegó
    }

    imagealphablending($img, false);
    imagesavealpha($img, true);

    // Fondo → transparente; más oscuro que el fondo → sombra negra semitransparente.
    for ($i = 0; $i < $w * $h; $i++) {
        if ($fondo[$i] !== "\1") {
            continue;
        }
        $c   = $px[$i];
        $lum = ((($c >> 16) & 255) + (($c >> 8) & 255) + ($c & 255)) / 3;
        $oscuro = $lumB > 0 ? max(0.0, 1 - $lum / $lumB) : 0.0;
        // Menos de un 8 % más oscuro es la viñeta o el ruido del fondo, no una sombra.
        $sombra = $oscuro < 0.08 ? 0.0 : min(1.0, ($oscuro - 0.08) / 0.92);
        $alfa7  = (int) round((1 - $sombra) * 127);
        imagesetpixel($img, $i % $w, intdiv($i, $w), ($alfa7 << 24));
    }

    // Contorno: 2 capas de píxeles pegados al fondo se vuelven semitransparentes
    // y se les quita el color del fondo que traen mezclado (evita el halo).
    $capa = $fondo;
    for ($ronda = 0; $ronda < 2; $ronda++) {
        $nuevos = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $i = $y * $w + $x;
                if ($capa[$i] !== "\0") {
                    continue;
                }
                $toca = false;
                for ($dy = -1; $dy <= 1 && !$toca; $dy++) {
                    for ($dx = -1; $dx <= 1; $dx++) {
                        $xx = $x + $dx;
                        $yy = $y + $dy;
                        if ($xx >= 0 && $xx < $w && $yy >= 0 && $yy < $h && $capa[$yy * $w + $xx] === "\1") {
                            $toca = true;
                            break;
                        }
                    }
                }
                if ($toca) {
                    $nuevos[] = $i;
                }
            }
        }
        foreach ($nuevos as $i) {
            $c = $px[$i];
            $t = max(0.0, min(1.0, ($dist($c) - 10) / 50));
            if ($t >= 1.0) {
                $capa[$i] = "\2";
                continue;
            }
            $r = (($c >> 16) & 255);
            $g = (($c >> 8) & 255);
            $b = ($c & 255);
            if ($t <= 0.02) {
                imagesetpixel($img, $i % $w, intdiv($i, $w), (127 << 24));
            } else {
                $r = (int) max(0, min(255, round(($r - (1 - $t) * $br) / $t)));
                $g = (int) max(0, min(255, round(($g - (1 - $t) * $bg) / $t)));
                $b = (int) max(0, min(255, round(($b - (1 - $t) * $bb) / $t)));
                imagesetpixel($img, $i % $w, intdiv($i, $w), ((int) round((1 - $t) * 127) << 24) | ($r << 16) | ($g << 8) | $b);
            }
            $capa[$i] = "\2";
        }
        // La segunda ronda parte de lo que acaba de ablandarse.
        $capa = strtr($capa, "\2", "\1");
    }

    return true;
}

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
    // Sin esto, un XAMPP con la extensión GD apagada en php.ini tumbaba la
    // página con un error fatal en pantalla en vez de un aviso normal.
    if (!extension_loaded('gd')) {
        return 'El servidor no tiene activada la extensión GD de PHP (necesaria para procesar imágenes). Actívala en php.ini y reinicia Apache.';
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

    // Fondo gris, de color o negro → transparente, para que se funda con la
    // ficha igual que las fotos con fondo blanco (ver quitar_fondo_uniforme()).
    quitar_fondo_uniforme($origen);

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
