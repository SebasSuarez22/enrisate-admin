<?php
declare(strict_types=1);

/* Herramienta de un solo uso: sube un archivo .sql (exportado desde
   phpMyAdmin, normalmente solo con "Datos", sin "Estructura") hacia
   cualquier servidor MySQL indicado por línea de comandos.

   Existe porque el "mysql.exe" que trae XAMPP es muy viejo y no sabe
   hablar el método de autenticación (caching_sha2_password) que usa
   MySQL en servidores como Railway — PHP sí lo sabe hablar, así que
   este script hace la conexión con PHP en vez de con ese programa.

   Uso:
     php tools/importar_datos.php <archivo.sql> <host> <puerto> <usuario> <clave> <base_de_datos>
*/

if (PHP_SAPI !== 'cli') {
    exit('Este script solo corre desde la terminal.');
}

[, $archivo, $host, $puerto, $usuario, $clave, $baseDatos] = $argv + [null, null, null, null, null, null, null];

if (!$archivo || !$host || !$puerto || !$usuario || $clave === null || !$baseDatos) {
    fwrite(STDERR, "Uso: php tools/importar_datos.php <archivo.sql> <host> <puerto> <usuario> <clave> <base_de_datos>\n");
    exit(1);
}

$sql = file_get_contents($archivo);
if ($sql === false) {
    fwrite(STDERR, "No pude leer el archivo: $archivo\n");
    exit(1);
}

$mysqli = mysqli_init();
if (!$mysqli->real_connect($host, $usuario, $clave, $baseDatos, (int) $puerto)) {
    fwrite(STDERR, "No se pudo conectar: " . mysqli_connect_error() . "\n");
    exit(1);
}

if (!$mysqli->multi_query($sql)) {
    fwrite(STDERR, "Error al importar: " . $mysqli->error . "\n");
    exit(1);
}

// multi_query ejecuta las sentencias una por una; hay que ir pasando
// por cada resultado para que se ejecuten todas y para enterarse si
// alguna falló a mitad de camino.
do {
    if ($resultado = $mysqli->store_result()) {
        $resultado->free();
    }
    if ($mysqli->errno) {
        fwrite(STDERR, "Error a mitad de la importación: " . $mysqli->error . "\n");
        exit(1);
    }
} while ($mysqli->more_results() && $mysqli->next_result());

echo "Datos importados correctamente.\n";
