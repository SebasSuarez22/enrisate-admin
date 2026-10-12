<?php
declare(strict_types=1);

/* Aplica storage/esquema.sql contra la base de datos configurada.
   Usa CREATE TABLE IF NOT EXISTS, así que correrlo de más no hace daño:
   si las tablas ya existen, no toca nada.

   En XAMPP esto se hace a mano desde phpMyAdmin (ver README.md). En un
   servidor como Railway lo corre solo el contenedor cada vez que
   arranca (ver docker/entrypoint.sh), para que la base quede lista sin
   que nadie tenga que entrar a phpMyAdmin. */

if (PHP_SAPI !== 'cli') {
    exit('Este script solo corre desde la terminal.');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/pedidos.php';

$ruta = __DIR__ . '/../storage/esquema.sql';
$sql  = file_get_contents($ruta);
if ($sql === false) {
    fwrite(STDERR, "No pude leer $ruta\n");
    exit(1);
}

// CREATE DATABASE + USE quedan afuera: la conexión ya apunta a la base
// correcta (DB_NOMBRE) y crearla no es cosa de esta app sino de Railway
// (o de quien haya preparado el servidor de MySQL).
$sql = preg_replace('/^\s*(CREATE DATABASE|USE)\b.*?;\s*$/mi', '', $sql);

// Un statement por sentencia ";" al final de línea — el esquema no
// tiene punto y coma dentro de valores de texto, así que alcanza.
$sentencias = array_filter(array_map('trim', explode(";\n", $sql)));

$pdo = db();
foreach ($sentencias as $sentencia) {
    if ($sentencia === '') {
        continue;
    }
    $pdo->exec($sentencia);
}

// Columnas nuevas en tablas que ya existían (CREATE TABLE IF NOT EXISTS no las agrega).
if (!asegurar_esquema_pedidos()) {
    fwrite(STDERR, "Aviso: no se pudo agregar pedidos.fecha_pago_preferida; los pedidos se guardan sin esa fecha.\n");
}

echo "Esquema aplicado (" . count($sentencias) . " sentencias).\n";
