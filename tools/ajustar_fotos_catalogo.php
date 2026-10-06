<?php
declare(strict_types=1);

/* Aplica los retoques aprobados de fotos de producto (ver aplicar_ajuste_foto()).
   Lo corre el contenedor en cada arranque (docker/entrypoint.sh), antes de preparar
   las versiones livianas. Cada retoque se aplica una sola vez y solo si la foto de
   la tienda sigue siendo la que se retocó.

       php tools/ajustar_fotos_catalogo.php

   Para agregar uno: dejar la foto retocada en tools/fotos_ajustadas/<SKU>.webp y
   sumar una línea abajo con el sha256 de la foto que había ANTES del retoque. */

if (PHP_SAPI !== 'cli') {
    exit('Este script solo corre desde la terminal.');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/imagenes.php';

const AJUSTES = [
    // Sobres de Karsell: se suaviza el borde gris punteado del plástico (opción A aprobada).
    'ACC-08' => 'bcda07de1d1d751b71a626f26f9b5b481ecc337c0da46c0f4e18fcdc9ebddef9',
];

$textos = [
    'aplicado'    => 'foto ajustada (la original quedó en _respaldos/ajustes/)',
    'ya_aplicado' => 'ya estaba ajustada',
    'distinta'    => 'la foto de la tienda cambió desde el retoque: no se toca',
    'sin_foto'    => 'no tiene foto en la tienda',
    'error'       => 'no se pudo ajustar',
];

$errores = 0;
foreach (AJUSTES as $sku => $shaEsperada) {
    $estado = aplicar_ajuste_foto($sku, $shaEsperada, __DIR__ . '/fotos_ajustadas/' . $sku . '.webp');
    printf("Ajuste de foto %s: %s\n", $sku, $textos[$estado]);
    if ($estado === 'error') {
        $errores++;
    }
}
exit($errores > 0 ? 1 : 0);
