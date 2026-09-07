<?php
declare(strict_types=1);

/* Herramienta de un solo uso: cuenta cuántas filas hay en las tablas
   principales de una base de datos remota, para confirmar si una
   importación de datos sí quedó guardada, sin depender de abrir la
   página en el navegador.

   Uso:
     php tools/contar_filas.php <host> <puerto> <usuario> <clave> <base_de_datos>
*/

if (PHP_SAPI !== 'cli') {
    exit('Este script solo corre desde la terminal.');
}

[, $host, $puerto, $usuario, $clave, $baseDatos] = $argv + [null, null, null, null, null, null];

if (!$host || !$puerto || !$usuario || $clave === null || !$baseDatos) {
    fwrite(STDERR, "Uso: php tools/contar_filas.php <host> <puerto> <usuario> <clave> <base_de_datos>\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = mysqli_init();
if (!$mysqli->real_connect($host, $usuario, $clave, $baseDatos, (int) $puerto)) {
    fwrite(STDERR, "No se pudo conectar: " . mysqli_connect_error() . "\n");
    exit(1);
}

echo "Conectado a la base '$baseDatos' en $host:$puerto\n\n";

foreach (['clientes', 'productos', 'facturas', 'pedidos'] as $tabla) {
    $resultado = $mysqli->query("SELECT COUNT(*) AS total FROM `$tabla`");
    if (!$resultado) {
        echo str_pad($tabla, 15) . ": error (" . $mysqli->error . ")\n";
        continue;
    }
    $fila = $resultado->fetch_assoc();
    echo str_pad($tabla, 15) . ": " . $fila['total'] . " fila(s)\n";
}
