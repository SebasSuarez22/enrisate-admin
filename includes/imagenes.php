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
// Lado más largo que se guarda, en px. Cada foto se guarda en dos tamaños:
//   <SKU>.webp          hasta 1200 px: solo se descarga al abrir la ficha del
//                       producto, donde se ve grande (~400 px de alto en
//                       computador, el doble en pantallas de alta resolución).
//   <SKU>.tarjeta.webp  hasta 640 px: la que carga la rejilla del catálogo
//                       (tarjetas de ~280 px de ancho), liviana como antes.
//   <SKU>.mini.webp     hasta 360 px: la misma foto, más liviana, para celulares
//                       de pantalla 2× y computadores (el navegador elige con
//                       srcset; los celulares 3× siguen bajando la de tarjeta).
// Así la ficha se ve nítida sin que la página del catálogo pese más. Las fotos
// de 640 px o menos no llevan versión de tarjeta: la misma sirve para todo.
define('PRODUCTOS_IMG_MAX', 1200);
define('PRODUCTOS_IMG_TARJETA', 640);
define('PRODUCTOS_IMG_MINI', 360);
// Medidas de cada foto (ancho, alto y "pie", ver medidas_foto()), calculadas una
// vez en el servidor. Vive dentro del Volume, en una carpeta con "_" que el
// .htaccess no sirve.
define('PRODUCTOS_MEDIDAS', PRODUCTOS_IMG_DIR . '/_cache/medidas.json');
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

/**
 * Fondo claro de estudio (gris perla, crema, casi blanco, con o sin viñeta) → blanco puro.
 *
 * quitar_fondo_uniforme() no toca los fondos de 244 para arriba y deja transparentes los
 * más oscuros; pero un gris de estudio de 225-252 se veía como un recuadro gris dentro de
 * la tarjeta. Aquí ese fondo se vuelve blanco y el producto conserva sus colores:
 *   1. Tono del fondo = percentil 90 del borde (la viñeta oscurece solo las esquinas).
 *      Si no es un claro parejo (muy oscuro, muy de color, ya blanco), no se toca.
 *   2. Se recorre el fondo desde el borde: avanza solo si el color cambia muy poco de un
 *      píxel al vecino (≤ 4: viñeta, ruido) y no se aleja del tono. El contorno del
 *      producto, aunque sea sutil, lo detiene.
 *   3. Si buena parte de ese contorno es MÁS CLARO que el fondo (envase blanco sobre gris),
 *      blanquear borraría la silueta: no se toca (la revisión de fotos la marca para
 *      volver a subirla).
 *   4. El fondo recorrido queda en 255 y el resto recibe una ganancia suave (máx. +15 %)
 *      para que no se vea apagado al lado del blanco nuevo.
 * Devuelve true solo si cambió la foto.
 */
function blanquear_fondo_claro(\GdImage $img): bool
{
    $w = imagesx($img);
    $h = imagesy($img);
    if ($w < 40 || $h < 40) {
        return false;
    }

    $px = [];
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $px[] = imagecolorat($img, $x, $y);
        }
    }

    // Anillo de 2 px del borde.
    $anillo = [];
    $hr = $hg = $hb = array_fill(0, 256, 0);
    $opacos = 0;
    $transparentes = 0;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            if ($y >= 2 && $y < $h - 2 && $x >= 2 && $x < $w - 2) {
                continue;
            }
            $i = $y * $w + $x;
            $anillo[] = $i;
            $c = $px[$i];
            if ((($c >> 24) & 127) >= 16) {
                $transparentes++;
                continue;
            }
            $hr[($c >> 16) & 255]++;
            $hg[($c >> 8) & 255]++;
            $hb[$c & 255]++;
            $opacos++;
        }
    }
    if ($opacos === 0 || $transparentes > 0.05 * count($anillo)) {
        return false;
    }

    $percentil = function (array $hist, float $p) use ($opacos): int {
        $acum = 0;
        foreach ($hist as $valor => $cuantos) {
            $acum += $cuantos;
            if ($acum >= $opacos * $p) {
                return $valor;
            }
        }
        return 255;
    };
    $br = $percentil($hr, 0.9);
    $bg = $percentil($hg, 0.9);
    $bb = $percentil($hb, 0.9);
    $min = min($br, $bg, $bb);
    $max = max($br, $bg, $bb);
    // Ya blanco, demasiado oscuro o demasiado de color: no es un fondo claro de estudio.
    if ($min >= 253 || $max < 225 || $min < 208 || $max - $min > 25) {
        return false;
    }

    $lejos = fn(int $c): int => max(abs((($c >> 16) & 255) - $br), abs((($c >> 8) & 255) - $bg), abs(($c & 255) - $bb));
    $paso  = fn(int $a, int $b): int => max(
        abs((($a >> 16) & 255) - (($b >> 16) & 255)),
        abs((($a >> 8) & 255) - (($b >> 8) & 255)),
        abs(($a & 255) - ($b & 255))
    );

    $fondo = str_repeat("\0", $w * $h);
    $pila = [];
    foreach ($anillo as $i) {
        if ($lejos($px[$i]) <= 32) {
            $fondo[$i] = "\1";
            $pila[] = $i;
        }
    }
    $contorno = [];
    while ($pila) {
        $i = array_pop($pila);
        $c = $px[$i];
        $x = $i % $w;
        $vecinos = [];
        if ($x > 0)             { $vecinos[] = $i - 1; }
        if ($x < $w - 1)        { $vecinos[] = $i + 1; }
        if ($i >= $w)           { $vecinos[] = $i - $w; }
        if ($i < $w * ($h - 1)) { $vecinos[] = $i + $w; }
        foreach ($vecinos as $j) {
            if ($fondo[$j] !== "\0") {
                continue;
            }
            $cj = $px[$j];
            if ($paso($c, $cj) <= 4 && $lejos($cj) <= 32) {
                $fondo[$j] = "\1";
                $pila[] = $j;
            } else {
                $contorno[$j] = true;
            }
        }
    }

    $cantidad = substr_count($fondo, "\1");
    if ($cantidad < 0.15 * $w * $h || $cantidad > 0.95 * $w * $h) {
        return false; // mala detección: mejor dejar la foto como llegó
    }

    // ¿El producto es más claro que el fondo (envase blanco sobre gris)?
    $techo = $max + 5;
    $claros = 0;
    $total = 0;
    foreach ($contorno as $j => $_) {
        if ($fondo[$j] !== "\0") {
            continue;
        }
        $total++;
        $c = $px[$j];
        if ((($c >> 16) & 255) > $techo && (($c >> 8) & 255) > $techo && ($c & 255) > $techo) {
            $claros++;
        }
    }
    if ($total > 0 && $claros > 0.25 * $total) {
        return false;
    }

    $gr = min(1.15, 255 / max(1, $br));
    $gg = min(1.15, 255 / max(1, $bg));
    $gb = min(1.15, 255 / max(1, $bb));
    imagealphablending($img, false);
    imagesavealpha($img, true);
    for ($i = 0, $n = $w * $h; $i < $n; $i++) {
        $c = $px[$i];
        $a = ($c >> 24) & 127;
        if ($fondo[$i] === "\1") {
            $r = $g = $b = 255;
        } else {
            $r = (int) min(255, round((($c >> 16) & 255) * $gr));
            $g = (int) min(255, round((($c >> 8) & 255) * $gg));
            $b = (int) min(255, round(($c & 255) * $gb));
        }
        imagesetpixel($img, $i % $w, intdiv($i, $w), ($a << 24) | ($r << 16) | ($g << 8) | $b);
    }
    return true;
}

/**
 * Recorta los márgenes vacíos (transparentes o blancos) y deja un respiro parejo de
 * $margen (fracción del lado mayor del producto) a cada lado. Así cada foto llega al
 * catálogo con el producto ocupando el cuadro, y el tamaño en la tarjeta lo decide el
 * diseño (escala según la forma del envase), no cuánto aire dejó quien tomó la foto.
 * Devuelve la misma imagen si no hay nada que recortar.
 */
function recortar_margenes(\GdImage $img, float $margen = 0.04): \GdImage
{
    $w = imagesx($img);
    $h = imagesy($img);
    // Píxeles con producto por fila y por columna. Vacío = transparente o casi blanco (≥ 240:
    // las fotos de estudio traen ruido y viñeta suave de 240-254 en el fondo). Una fila o
    // columna cuenta como producto si tiene al menos 2 píxeles así, para que una mota suelta
    // en el fondo no impida el recorte.
    $porFila = array_fill(0, $h, 0);
    $porColumna = array_fill(0, $w, 0);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($img, $x, $y);
            if ((($c >> 24) & 127) < 110
                && ((($c >> 16) & 255) < 240 || (($c >> 8) & 255) < 240 || ($c & 255) < 240)) {
                $porFila[$y]++;
                $porColumna[$x]++;
            }
        }
    }
    $hay = fn(int $n): bool => $n >= 2;
    $filas = array_keys(array_filter($porFila, $hay));
    $columnas = array_keys(array_filter($porColumna, $hay));
    $x0 = $columnas ? min($columnas) : 0;
    $x1 = $columnas ? max($columnas) : -1;
    $y0 = $filas ? min($filas) : 0;
    $y1 = $filas ? max($filas) : -1;
    if ($x1 < 0) {
        return $img; // foto vacía
    }
    $pad = (int) round(max($x1 - $x0 + 1, $y1 - $y0 + 1) * $margen);
    $x0 = max(0, $x0 - $pad);
    $y0 = max(0, $y0 - $pad);
    $x1 = min($w - 1, $x1 + $pad);
    $y1 = min($h - 1, $y1 + $pad);
    $nw = $x1 - $x0 + 1;
    $nh = $y1 - $y0 + 1;
    // Casi nada que recortar (menos de un 5 % por lado, del orden del propio respiro): se deja
    // igual. Así revisar dos veces la misma foto no la vuelve a recortar ni a recomprimir.
    if ($w - $nw <= max(2, 0.05 * $w) && $h - $nh <= max(2, 0.05 * $h)) {
        return $img;
    }

    $out = imagecreatetruecolor($nw, $nh);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagecopy($out, $img, 0, 0, $x0, $y0, $nw, $nh);
    return $out;
}

/**
 * Todo lo que se le hace a una foto de producto antes de guardarla (al subirla y en
 * "Revisar fondos de fotos"): fondo claro → blanco, fondo de color → transparente,
 * márgenes recortados. Devuelve [imagen resultante, cambios] con cambios ⊆
 * ['blanqueada', 'transparente', 'recortada'].
 *
 * @return array{0: \GdImage, 1: list<string>}
 */
function procesar_foto_producto(\GdImage $img): array
{
    $cambios = [];
    if (blanquear_fondo_claro($img)) {
        $cambios[] = 'blanqueada';
    } elseif (quitar_fondo_uniforme($img)) {
        $cambios[] = 'transparente';
    }
    $recortada = recortar_margenes($img);
    if ($recortada !== $img) {
        imagedestroy($img);
        $img = $recortada;
        $cambios[] = 'recortada';
    }
    return [$img, $cambios];
}

/**
 * ¿El fondo de la foto ya se funde con el catálogo? Sí si el borde es blanco (≥ 244)
 * o transparente. Si no, es un fondo de color, de ambiente o un envase blanco sobre
 * gris que no se pudo arreglar solo: conviene volver a subirla con fondo blanco.
 */
function foto_fondo_ok(\GdImage $img): bool
{
    $w = imagesx($img);
    $h = imagesy($img);
    $buenos = 0;
    $total = 0;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            if ($y >= 2 && $y < $h - 2 && $x >= 2 && $x < $w - 2) {
                continue;
            }
            $c = imagecolorat($img, $x, $y);
            $total++;
            if ((($c >> 24) & 127) >= 100
                || ((($c >> 16) & 255) >= 244 && (($c >> 8) & 255) >= 244 && ($c & 255) >= 244)) {
                $buenos++;
            }
        }
    }
    return $total > 0 && $buenos >= 0.9 * $total;
}

/** Ruta de la versión liviana para la tarjeta (o la foto normal si no hace falta). */
function producto_ruta_foto_tarjeta(string $sku): ?string
{
    if (is_file(PRODUCTOS_IMG_DIR . '/' . $sku . '.tarjeta.webp')) {
        return PRODUCTOS_IMG_WEB . '/' . $sku . '.tarjeta.webp';
    }
    return producto_ruta_foto($sku);
}

/** Guarda (o borra, si la foto ya es pequeña) las versiones de tarjeta y mini de $img. */
function guardar_version_tarjeta(\GdImage $img, string $sku): void
{
    guardar_version_reducida($img, PRODUCTOS_IMG_DIR . '/' . $sku . '.tarjeta.webp', PRODUCTOS_IMG_TARJETA);
    guardar_version_reducida($img, PRODUCTOS_IMG_DIR . '/' . $sku . '.mini.webp', PRODUCTOS_IMG_MINI);
}

/** Guarda $img reducida a $lado px en $destino, o borra $destino si la foto ya es así de pequeña. */
function guardar_version_reducida(\GdImage $img, string $destino, int $ladoMax): void
{
    $w = imagesx($img);
    $h = imagesy($img);
    $lado = max($w, $h);
    if ($lado <= $ladoMax) {
        if (is_file($destino)) {
            unlink($destino);
        }
        return;
    }
    $factor = $ladoMax / $lado;
    $chica = imagecreatetruecolor(max(1, (int) round($w * $factor)), max(1, (int) round($h * $factor)));
    imagealphablending($chica, false);
    imagesavealpha($chica, true);
    imagecopyresampled($chica, $img, 0, 0, 0, 0, imagesx($chica), imagesy($chica), $w, $h);
    // Se escribe aparte y se renombra: quien pida la foto mientras tanto nunca recibe un archivo a medias.
    $temporal = $destino . '.tmp';
    if (imagewebp($chica, $temporal, 82)) {
        rename($temporal, $destino);
    } else {
        @unlink($temporal);
    }
    imagedestroy($chica);
}

/**
 * Ruta de la versión mini de un SKU, solo si existe y está al día con $rutaBase (la
 * foto que muestra la tarjeta). Si la foto se cambió y la mini todavía no se rehízo,
 * devuelve null y la tarjeta usa solo la foto normal: nunca se muestra una foto vieja.
 */
function producto_ruta_foto_mini(string $sku, string $rutaBase): ?string
{
    $mini = PRODUCTOS_IMG_DIR . '/' . $sku . '.mini.webp';
    $base = BASE_DIR . '/' . $rutaBase;
    if (!is_file($mini) || !is_file($base) || filemtime($mini) < filemtime($base)) {
        return null;
    }
    return PRODUCTOS_IMG_WEB . '/' . $sku . '.mini.webp';
}

/** Abre una foto con GD según su extensión (false si no se puede). */
function abrir_foto(string $archivo): \GdImage|false
{
    $img = match (strtolower(pathinfo($archivo, PATHINFO_EXTENSION))) {
        'webp'        => @imagecreatefromwebp($archivo),
        'png'         => @imagecreatefrompng($archivo),
        'jpg', 'jpeg' => @imagecreatefromjpeg($archivo),
        default       => false,
    };
    if ($img) {
        imagepalettetotruecolor($img);
        imagesavealpha($img, true);
    }
    return $img;
}

/**
 * Qué fracción del alto de la foto queda VACÍA bajo el producto (0 a ~0,06): la sombra
 * de apoyo de la tarjeta se coloca con esta medida. Se reduce la foto a 96 px de ancho
 * y se busca desde abajo la primera fila con algo que no sea blanco ni transparente.
 * Antes lo calculaba el celular con un canvas, foto por foto, mientras la clienta hacía
 * scroll (trababa el desplazamiento y la sombra se movía después de aparecer la foto).
 */
function medir_pie_foto(\GdImage $img): ?float
{
    $nw = imagesx($img);
    $nh = imagesy($img);
    $w = min($nw, 96);
    $h = max(1, (int) round($w * $nh / $nw));
    $chica = imagecreatetruecolor($w, $h);
    imagealphablending($chica, false);
    imagesavealpha($chica, true);
    imagecopyresampled($chica, $img, 0, 0, 0, 0, $w, $h, $nw, $nh);
    for ($y = $h - 1; $y >= 0; $y--) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($chica, $x, $y);
            $alfa = ($c >> 24) & 0x7F;             // 0 = opaco … 127 = transparente
            $minimo = min(($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF);
            if ($alfa <= 107 && $minimo < 240) {   // mismo umbral que tenía el canvas (alfa > 40 de 255)
                imagedestroy($chica);
                return ($h - 1 - $y) / $h;
            }
        }
    }
    imagedestroy($chica);
    return null;   // todo blanco o transparente: la tarjeta usa el valor por defecto del CSS
}

/**
 * Medidas de una foto del catálogo: ['w' => ancho, 'h' => alto, 'pie' => fracción].
 * Se guardan en PRODUCTOS_MEDIDAS por archivo y fecha de modificación: cambiar la foto
 * invalida su medida sola. null si el archivo no existe o no se puede leer.
 */
function medidas_foto(string $rutaRelativa): ?array
{
    static $cache = null;
    static $sucio = false;
    if ($cache === null) {
        $cache = [];
        if (is_file(PRODUCTOS_MEDIDAS)) {
            $leido = json_decode((string) file_get_contents(PRODUCTOS_MEDIDAS), true);
            $cache = is_array($leido) ? $leido : [];
        }
        register_shutdown_function(function () use (&$cache, &$sucio): void {
            if (!$sucio) {
                return;
            }
            $dir = dirname(PRODUCTOS_MEDIDAS);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return;
            }
            $temporal = PRODUCTOS_MEDIDAS . '.' . getmypid() . '.tmp';
            if (@file_put_contents($temporal, json_encode($cache, JSON_UNESCAPED_SLASHES)) !== false) {
                @rename($temporal, PRODUCTOS_MEDIDAS);
            } else {
                @unlink($temporal);
            }
        });
    }

    $archivo = BASE_DIR . '/' . $rutaRelativa;
    $mtime = @filemtime($archivo);
    if ($mtime === false) {
        return null;
    }
    $clave = basename($rutaRelativa);
    $guardado = $cache[$clave] ?? null;
    if (is_array($guardado) && ($guardado['t'] ?? null) === $mtime) {
        return $guardado;
    }

    $img = abrir_foto($archivo);
    if (!$img) {
        $dim = @getimagesize($archivo);
        return $dim ? ['t' => $mtime, 'w' => $dim[0], 'h' => $dim[1], 'pie' => null] : null;
    }
    $pie = medir_pie_foto($img);
    $medidas = ['t' => $mtime, 'w' => imagesx($img), 'h' => imagesy($img), 'pie' => $pie === null ? null : round($pie, 4)];
    imagedestroy($img);
    $cache[$clave] = $medidas;
    $sucio = true;
    return $medidas;
}

/** Rehace la versión de tarjeta a partir de la foto que está en disco. */
function actualizar_version_tarjeta(string $sku): void
{
    $ruta = producto_ruta_foto($sku);
    if ($ruta === null) {
        @unlink(PRODUCTOS_IMG_DIR . '/' . $sku . '.tarjeta.webp');
        @unlink(PRODUCTOS_IMG_DIR . '/' . $sku . '.mini.webp');
        return;
    }
    $img = abrir_foto(BASE_DIR . '/' . $ruta);
    if ($img) {
        guardar_version_tarjeta($img, $sku);
        imagedestroy($img);
    }
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

    // Fondo claro de estudio → blanco; gris oscuro, de color o negro → transparente;
    // márgenes vacíos recortados (ver procesar_foto_producto()).
    [$origen] = procesar_foto_producto($origen);

    $ok = imagewebp($origen, PRODUCTOS_IMG_DIR . '/' . $sku . '.webp', 82);
    if ($ok) {
        guardar_version_tarjeta($origen, $sku);
    }
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
    @unlink(PRODUCTOS_IMG_DIR . '/' . $sku . '.tarjeta.webp');
    @unlink(PRODUCTOS_IMG_DIR . '/' . $sku . '.mini.webp');
    foreach (PRODUCTOS_IMG_EXTS as $ext) {
        $ruta = PRODUCTOS_IMG_DIR . '/' . $sku . '.' . $ext;
        if (is_file($ruta)) {
            unlink($ruta);
            $borrado = true;
        }
    }
    return $borrado;
}

/* =========================================================
   Revisar fondos de fotos (Productos → "Revisar fondos de fotos")
   =========================================================
   Pasa las fotos que ya están subidas por el mismo arreglo que reciben las
   nuevas (procesar_foto_producto). Antes de reemplazar una foto se guarda la
   original en _respaldos/<fecha>/, dentro de la misma carpeta de fotos (el
   Volume de Railway), así sobrevive a los despliegues y la revisión se puede
   deshacer. Solo se respaldan y reescriben las fotos que cambian.
   ========================================================= */

define('PRODUCTOS_RESPALDOS_DIR', PRODUCTOS_IMG_DIR . '/_respaldos');

/** SKUs que tienen foto, en orden. */
function skus_con_foto(): array
{
    $skus = [];
    foreach (PRODUCTOS_IMG_EXTS as $ext) {
        foreach (glob(PRODUCTOS_IMG_DIR . '/*.' . $ext) ?: [] as $ruta) {
            $sku = pathinfo($ruta, PATHINFO_FILENAME);
            if (preg_match('/^[A-Z0-9\-_]+$/', $sku)) {
                $skus[$sku] = true;
            }
        }
    }
    $skus = array_keys($skus);
    sort($skus);
    return $skus;
}

function respaldo_valido(string $id): bool
{
    return (bool) preg_match('/^\d{8}-\d{6}$/', $id);
}

/** La revisión más reciente (id de su carpeta de respaldo) o null. */
function ultima_revision_fotos(): ?string
{
    $ids = array_filter(
        array_map('basename', glob(PRODUCTOS_RESPALDOS_DIR . '/*', GLOB_ONLYDIR) ?: []),
        'respaldo_valido'
    );
    rsort($ids);
    return $ids[0] ?? null;
}

/** Reporte de una revisión: sku => ['cambios' => [...], 'fondo_ok' => bool, 'original' => archivo]. */
function reporte_revision_fotos(string $id): array
{
    if (!respaldo_valido($id)) {
        return [];
    }
    $json = @file_get_contents(PRODUCTOS_RESPALDOS_DIR . '/' . $id . '/reporte.json');
    $datos = $json ? json_decode($json, true) : null;
    return is_array($datos) ? $datos : [];
}

/**
 * Revisa la foto de un SKU dentro de la revisión $id. Si cambia, respalda la
 * original y guarda la nueva como <SKU>.webp. Devuelve la fila del reporte.
 */
function revisar_foto_producto(string $sku, string $id): array
{
    $ruta = producto_ruta_foto($sku);
    if ($ruta === null || !respaldo_valido($id)) {
        return ['cambios' => [], 'fondo_ok' => true, 'error' => 'sin foto'];
    }
    $archivo = BASE_DIR . '/' . $ruta;
    $ext = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
    $img = match ($ext) {
        'webp'        => @imagecreatefromwebp($archivo),
        'png'         => @imagecreatefrompng($archivo),
        'jpg', 'jpeg' => @imagecreatefromjpeg($archivo),
        default       => false,
    };
    if (!$img) {
        return ['cambios' => [], 'fondo_ok' => false, 'error' => 'no se pudo abrir'];
    }
    imagepalettetotruecolor($img);
    imagealphablending($img, true);
    imagesavealpha($img, true);

    [$img, $cambios] = procesar_foto_producto($img);
    $fila = ['cambios' => $cambios, 'fondo_ok' => foto_fondo_ok($img)];

    if ($cambios) {
        $dir = PRODUCTOS_RESPALDOS_DIR . '/' . $id;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            imagedestroy($img);
            return ['cambios' => [], 'fondo_ok' => $fila['fondo_ok'], 'error' => 'no se pudo crear el respaldo'];
        }
        // Sin respaldo no se toca la original.
        if (!copy($archivo, $dir . '/' . basename($archivo))) {
            imagedestroy($img);
            return ['cambios' => [], 'fondo_ok' => $fila['fondo_ok'], 'error' => 'no se pudo respaldar'];
        }
        $nueva = PRODUCTOS_IMG_DIR . '/' . $sku . '.webp';
        $temporal = $nueva . '.tmp';
        if (!imagewebp($img, $temporal, 82) || !rename($temporal, $nueva)) {
            @unlink($temporal);
            imagedestroy($img);
            return ['cambios' => [], 'fondo_ok' => $fila['fondo_ok'], 'error' => 'no se pudo guardar'];
        }
        if ($ext !== 'webp') {
            unlink($archivo);
        }
        $fila['original'] = basename($archivo);
        clearstatcache(true, $nueva);
        $fila['guardada'] = filemtime($nueva);
        guardar_version_tarjeta($img, $sku);
    } elseif (!is_file(PRODUCTOS_IMG_DIR . '/' . $sku . '.tarjeta.webp') || !is_file(PRODUCTOS_IMG_DIR . '/' . $sku . '.mini.webp')) {
        guardar_version_tarjeta($img, $sku);
    }
    imagedestroy($img);
    return $fila;
}

/** Agrega filas al reporte de la revisión $id. */
function guardar_reporte_revision(string $id, array $filas): void
{
    $dir = PRODUCTOS_RESPALDOS_DIR . '/' . $id;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $reporte = reporte_revision_fotos($id);
    foreach ($filas as $sku => $fila) {
        $reporte[$sku] = $fila;
    }
    file_put_contents($dir . '/reporte.json', json_encode($reporte, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/** Devuelve las fotos originales de la revisión $id. Devuelve cuántas restauró. */
function deshacer_revision_fotos(string $id): int
{
    if (!respaldo_valido($id)) {
        return 0;
    }
    $dir = PRODUCTOS_RESPALDOS_DIR . '/' . $id;
    $restauradas = 0;
    foreach (reporte_revision_fotos($id) as $sku => $fila) {
        $original = (string) ($fila['original'] ?? '');
        if ($original === '' || !preg_match('/^[A-Z0-9\-_]+\.(webp|png|jpe?g)$/', $original) || !is_file($dir . '/' . $original)) {
            continue;
        }
        $nueva = PRODUCTOS_IMG_DIR . '/' . $sku . '.webp';
        clearstatcache(true, $nueva);
        // Si después de la revisión alguien subió otra foto de este producto, esa se respeta.
        if (is_file($nueva) && filemtime($nueva) !== ($fila['guardada'] ?? null)) {
            continue;
        }
        if ($original !== $sku . '.webp' && is_file($nueva)) {
            unlink($nueva);
        }
        if (copy($dir . '/' . $original, PRODUCTOS_IMG_DIR . '/' . $original)) {
            $restauradas++;
            actualizar_version_tarjeta($sku);
        }
    }
    // La revisión deshecha se archiva para que no vuelva a aparecer como la última.
    rename($dir, PRODUCTOS_RESPALDOS_DIR . '/deshecha-' . $id);
    return $restauradas;
}

/* =========================================================
   Ajustes puntuales de una foto (retoques hechos a mano)
   =========================================================
   El Volume de Railway no se pisa en los despliegues: la foto de la tienda es la
   que ya está ahí. Para cambiar UNA foto con un retoque aprobado se usa esto:
   reemplaza la foto solo si sigue siendo EXACTAMENTE la que se retocó (mismo
   sha256). Si alguien subió otra foto desde el panel, esa se respeta. Antes de
   reemplazar guarda la original en _respaldos/ajustes/. Correrlo de más no hace
   nada (tools/ajustar_fotos_catalogo.php). */

define('PRODUCTOS_AJUSTES_RESPALDO', PRODUCTOS_RESPALDOS_DIR . '/ajustes');

/**
 * @return string 'aplicado' | 'ya_aplicado' | 'distinta' | 'sin_foto' | 'error'
 */
function aplicar_ajuste_foto(string $sku, string $shaEsperada, string $archivoNuevo): string
{
    $actual = PRODUCTOS_IMG_DIR . '/' . $sku . '.webp';
    if (!preg_match('/^[A-Z0-9\-_]+$/', $sku) || !is_file($archivoNuevo)) {
        return 'error';
    }
    if (!is_file($actual)) {
        return 'sin_foto';
    }
    $shaActual = hash_file('sha256', $actual);
    if ($shaActual === hash_file('sha256', $archivoNuevo)) {
        return 'ya_aplicado';
    }
    if ($shaActual !== strtolower($shaEsperada)) {
        return 'distinta';
    }
    if (!is_dir(PRODUCTOS_AJUSTES_RESPALDO) && !mkdir(PRODUCTOS_AJUSTES_RESPALDO, 0775, true) && !is_dir(PRODUCTOS_AJUSTES_RESPALDO)) {
        return 'error';
    }
    // Sin respaldo no se toca la foto.
    if (!copy($actual, PRODUCTOS_AJUSTES_RESPALDO . '/' . $sku . '.' . date('Ymd-His') . '.webp')) {
        return 'error';
    }
    $temporal = $actual . '.tmp';
    if (!copy($archivoNuevo, $temporal) || !rename($temporal, $actual)) {
        @unlink($temporal);
        return 'error';
    }
    clearstatcache(true, $actual);
    actualizar_version_tarjeta($sku);
    return 'aplicado';
}
