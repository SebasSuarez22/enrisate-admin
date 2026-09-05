<?php
declare(strict_types=1);

/* =========================================================
   Ajuste de una sola vez: la función de subir fotos de producto guardó
   al principio hasta 1200px de lado (una foto de celular normal salía en
   ~400-500 KB), lo que hacía lenta la carga del catálogo público. Ya se
   bajó el límite a 640px para las fotos nuevas (includes/imagenes.php),
   pero las que se hayan subido ANTES de ese cambio se quedaron pesadas
   en el disco. Este script las vuelve a comprimir con el límite nuevo.

   Cómo correrlo (desde la carpeta del proyecto):
       php tools/reoptimizar_fotos_productos.php

   Se puede correr varias veces sin problema: una foto que ya está dentro
   del límite no se toca (se detecta y se salta).
   ========================================================= */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script solo se puede correr por línea de comandos (CLI), no desde el navegador.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/imagenes.php';

$archivos = glob(PRODUCTOS_IMG_DIR . '/*.{webp,jpg,jpeg,png}', GLOB_BRACE);
if (!$archivos) {
    echo "No hay fotos en assets/img/productos/.\n";
    exit;
}

$reducidas = 0;
$sinCambio = 0;
$pesoAntes = 0;
$pesoDespues = 0;

foreach ($archivos as $ruta) {
    $dim = @getimagesize($ruta);
    if ($dim === false) {
        continue; // no es una imagen (p. ej. quedó algo raro en la carpeta)
    }

    $lado = max($dim[0], $dim[1]);
    if ($lado <= PRODUCTOS_IMG_MAX) {
        $sinCambio++;
        continue;
    }

    $pesoOriginal = filesize($ruta);
    $sku = pathinfo($ruta, PATHINFO_FILENAME);

    // Reutiliza el mismo procesamiento que usa la subida normal, a partir
    // del archivo que ya está en disco: mismo redimensionado, mismo WebP
    // calidad 82, mismo manejo de transparencia.
    $mensaje = guardar_imagen_producto($sku, [
        'error'    => UPLOAD_ERR_OK,
        'size'     => $pesoOriginal,
        'tmp_name' => $ruta,
    ]);

    if ($mensaje !== '') {
        echo "  {$sku}: no se pudo reprocesar ({$mensaje})\n";
        continue;
    }

    $pesoNuevo = filesize(PRODUCTOS_IMG_DIR . '/' . $sku . '.webp');
    $pesoAntes += $pesoOriginal;
    $pesoDespues += $pesoNuevo;
    $reducidas++;
    printf(
        "  %-14s %dx%d (%.0f KB) -> %d KB\n",
        $sku, $dim[0], $dim[1], $pesoOriginal / 1024, round($pesoNuevo / 1024)
    );
}

echo "\n";
echo "Fotos reprocesadas: {$reducidas}\n";
echo "Ya estaban dentro del límite: {$sinCambio}\n";
if ($reducidas > 0) {
    printf("Peso total: %.1f MB -> %.1f MB\n", $pesoAntes / 1024 / 1024, $pesoDespues / 1024 / 1024);
}
echo "Listo.\n";
