<?php
declare(strict_types=1);

/* Deja listas las fotos del catálogo público:
     · crea la versión mini (360 px) de cada foto que no la tenga o la tenga vieja;
     · calcula y guarda las medidas de cada foto (ver medidas_foto()).
   No toca la foto original. Correrlo de más no hace daño: lo que ya está al día
   se salta. Lo corre el contenedor en cada arranque (docker/entrypoint.sh), así
   las fotos que ya estaban en el Volume quedan listas sin que nadie haga nada.

       php tools/preparar_fotos_catalogo.php */

if (PHP_SAPI !== 'cli') {
    exit('Este script solo corre desde la terminal.');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/imagenes.php';

$minis = 0;
$medidas = 0;
$errores = 0;
$inicio = microtime(true);

foreach (skus_con_foto() as $sku) {
    $original = producto_ruta_foto($sku);
    if ($original === null) {
        continue;
    }
    $base = producto_ruta_foto_tarjeta($sku);
    $dim = @getimagesize(BASE_DIR . '/' . $original);
    $necesitaMini = $dim && max($dim[0], $dim[1]) > PRODUCTOS_IMG_MINI && producto_ruta_foto_mini($sku, $base) === null;
    if ($necesitaMini) {
        actualizar_version_tarjeta($sku);
        clearstatcache();
        $base = producto_ruta_foto_tarjeta($sku);
        if (producto_ruta_foto_mini($sku, $base) !== null) {
            $minis++;
        } else {
            $errores++;
            fwrite(STDERR, "No se pudo crear la versión mini de $sku\n");
        }
    }
    if (medidas_foto($base) !== null) {
        $medidas++;
    }
}

printf("Fotos del catálogo listas: %d minis nuevas, %d con medidas, %d errores (%.1f s).\n",
    $minis, $medidas, $errores, microtime(true) - $inicio);
