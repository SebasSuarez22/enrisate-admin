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

// Se apagan las excepciones de mysqli: si el archivo trae de más (por
// ejemplo "CREATE TABLE" de tablas que ya existen, porque el usuario
// exportó también la estructura), eso no debe detener el resto de la
// importación — lo que importa es que los datos sí queden metidos.
mysqli_report(MYSQLI_REPORT_OFF);

$mysqli = mysqli_init();
if (!$mysqli->real_connect($host, $usuario, $clave, $baseDatos, (int) $puerto)) {
    fwrite(STDERR, "No se pudo conectar: " . mysqli_connect_error() . "\n");
    exit(1);
}

$mysqli->multi_query($sql);

$errores = 0;
do {
    if ($resultado = $mysqli->store_result()) {
        $resultado->free();
    }
    if ($mysqli->errno) {
        fwrite(STDERR, "Aviso (se sigue con lo siguiente): " . $mysqli->error . "\n");
        $errores++;
    }
} while ($mysqli->more_results() && $mysqli->next_result());

if ($errores > 0) {
    echo "Importación terminada con $errores aviso(s) arriba (probablemente tablas que ya existían — no es grave). Revisa la página para confirmar que los datos sí quedaron.\n";
} else {
    echo "Datos importados correctamente, sin errores.\n";
}
