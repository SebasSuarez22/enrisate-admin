<?php
declare(strict_types=1);

/* Corre todas las pruebas de esta carpeta y resume el resultado.

       MYSQLDATABASE=enrisate_test php tests/correr_todas.php

   La base debe terminar en _test (ver tests/bootstrap.php): las pruebas
   que tocan MySQL borran facturas y se niegan a correr contra otra. */

if (PHP_SAPI !== 'cli') {
    exit('Las pruebas solo corren desde la terminal.');
}

$archivos = glob(__DIR__ . '/*_test.php') ?: [];
sort($archivos);

$fallidas = [];
foreach ($archivos as $archivo) {
    $nombre = basename($archivo);
    echo "=== $nombre ===\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($archivo), $codigo);
    echo "\n";
    if ($codigo !== 0) {
        $fallidas[] = $nombre;
        if ($codigo === 2) {
            // La protección de bootstrap.php: ya explicó cómo arreglarlo, no tiene
            // sentido repetir el mismo aviso con cada archivo.
            echo "Se detiene aquí: sin una base _test no se puede seguir.\n";
            break;
        }
    }
}

echo str_repeat('-', 50) . "\n";
if (!$fallidas) {
    echo count($archivos) . " archivos de prueba: TODOS OK\n";
    exit(0);
}
echo count($fallidas) . " de " . count($archivos) . " archivos con problemas: " . implode(', ', $fallidas) . "\n";
exit(1);
