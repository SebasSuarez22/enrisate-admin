<?php
declare(strict_types=1);

/* =========================================================
   Arranque común de las pruebas que tocan MySQL
   =========================================================
   Estas pruebas crean, modifican y borran facturas de verdad, así que
   NUNCA deben correr contra la base real (ni la de XAMPP con datos de
   Gina, ni la de Railway). Por eso este archivo se niega a seguir si
   la base configurada no termina en "_test".

   Cómo correrlas (una base aparte, vacía; el esquema se aplica solo):

       MYSQLDATABASE=enrisate_test php tests/correr_todas.php

   Si tu MySQL no es el de XAMPP por defecto, agrega también
   MYSQLHOST / MYSQLPORT / MYSQLUSER / MYSQLPASSWORD (las mismas
   variables que ya lee config.php).
   ========================================================= */

if (PHP_SAPI !== 'cli') {
    exit('Las pruebas solo corren desde la terminal.');
}

require_once __DIR__ . '/../includes/facturas.php';

if (!str_ends_with(DB_NOMBRE, '_test')) {
    fwrite(STDERR,
        "PRUEBAS CANCELADAS: la base configurada es \"" . DB_NOMBRE . "\", y estas pruebas\n"
        . "borran facturas. Usa una base aparte cuyo nombre termine en _test, por ejemplo:\n"
        . "    MYSQLDATABASE=enrisate_test php tests/correr_todas.php\n");
    exit(2);
}

/* Aplica storage/esquema.sql (CREATE TABLE IF NOT EXISTS, no pisa nada). */
function preparar_base_de_pruebas(): void
{
    $sql = file_get_contents(__DIR__ . '/../storage/esquema.sql');
    $sql = preg_replace('/^\s*(CREATE DATABASE|USE)\b.*?;\s*$/mi', '', (string) $sql);
    foreach (array_filter(array_map('trim', explode(';', (string) $sql))) as $sentencia) {
        db()->exec($sentencia);
    }
}

/* Vacía las facturas (y, por ON DELETE CASCADE, sus líneas, cuotas y abonos). */
function limpiar_facturas(): void
{
    db()->exec('DELETE FROM facturas');
}

preparar_base_de_pruebas();
limpiar_facturas();
// Pase lo que pase (incluso un error fatal a medias), la base de pruebas
// queda vacía para la siguiente prueba.
register_shutdown_function('limpiar_facturas');

$fallos = 0;
function verificar(bool $cond, string $msg): void
{
    if ($cond) {
        echo "OK   - $msg\n";
    } else {
        echo "FAIL - $msg\n";
        $GLOBALS['fallos']++;
    }
}

function terminar_pruebas(): never
{
    $fallos = $GLOBALS['fallos'];
    echo "\n" . ($fallos === 0 ? "TODO OK" : "$fallos prueba(s) fallaron") . "\n";
    exit($fallos === 0 ? 0 : 1);
}
