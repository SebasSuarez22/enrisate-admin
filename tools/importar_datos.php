<?php
declare(strict_types=1);

/* Herramienta de un solo uso: sube un archivo .sql (exportado desde
   phpMyAdmin, con o sin la estructura) hacia cualquier servidor MySQL
   indicado por línea de comandos.

   Existe porque el "mysql.exe" que trae XAMPP es muy viejo y no sabe
   hablar el método de autenticación (caching_sha2_password) que usa
   MySQL en servidores como Railway — PHP sí lo sabe hablar, así que
   este script hace la conexión con PHP en vez de con ese programa.

   Ejecuta el archivo sentencia por sentencia (no con multi_query):
   mysqli detiene TODO el resto de un multi_query en cuanto una sola
   sentencia falla (por ejemplo un "CREATE TABLE" de una tabla que ya
   existe), aunque las siguientes sentencias no tengan nada que ver.
   Sentencia por sentencia, un error en una (como esa) no bota las
   demás.

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

// Si el archivo trae "CREATE DATABASE" / "USE nombre_viejo;" (típico
// de una exportación completa de phpMyAdmin), esas líneas cambiarían
// la base de datos a mitad de la importación y los datos terminarían
// en una base distinta a la que de verdad usa la app. Se quitan para
// que todo quede siempre en la base indicada por línea de comandos.
$sql = preg_replace('/^\s*(CREATE DATABASE|USE)\b.*?;\s*$/mi', '', $sql);

/** Divide un script SQL en sentencias individuales, respetando los
 *  punto y coma que puedan venir dentro de textos entre comillas. */
function dividir_sentencias(string $sql): array
{
    $sentencias = [];
    $actual = '';
    $enComilla = null;
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        $actual .= $c;
        if ($enComilla !== null) {
            if ($c === '\\') {
                if ($i + 1 < $len) {
                    $actual .= $sql[++$i];
                }
                continue;
            }
            if ($c === $enComilla) {
                $enComilla = null;
            }
            continue;
        }
        if ($c === "'" || $c === '"') {
            $enComilla = $c;
            continue;
        }
        if ($c === ';') {
            $sentencias[] = trim($actual, "; \t\r\n");
            $actual = '';
        }
    }
    $resto = trim($actual, "; \t\r\n");
    if ($resto !== '') {
        $sentencias[] = $resto;
    }
    return array_values(array_filter($sentencias, fn ($s) => $s !== ''));
}

/** true si, quitando las líneas "-- comentario", no queda nada real. */
function es_solo_comentarios(string $sentencia): bool
{
    return trim(preg_replace('/^--.*$/m', '', $sentencia)) === '';
}

mysqli_report(MYSQLI_REPORT_OFF);

$mysqli = mysqli_init();
if (!$mysqli->real_connect($host, $usuario, $clave, $baseDatos, (int) $puerto)) {
    fwrite(STDERR, "No se pudo conectar: " . mysqli_connect_error() . "\n");
    exit(1);
}

// Se apagan las validaciones de llave foránea mientras dura la
// importación: si una tabla trae datos que dependen de otra que
// todavía no se ha insertado (por el orden del archivo), no debe
// fallar por eso.
$mysqli->query('SET FOREIGN_KEY_CHECKS=0');

$correctas = 0;
$errores = 0;
foreach (dividir_sentencias($sql) as $sentencia) {
    if (es_solo_comentarios($sentencia)) {
        continue;
    }
    if ($mysqli->query($sentencia)) {
        $correctas++;
    } else {
        fwrite(STDERR, "Aviso (se sigue con lo siguiente): " . $mysqli->error . "\n");
        $errores++;
    }
}

$mysqli->query('SET FOREIGN_KEY_CHECKS=1');

echo "Listo: $correctas sentencia(s) aplicada(s), $errores aviso(s).\n";
if ($errores > 0) {
    echo "Los avisos de arriba suelen ser inofensivos (tablas o índices que ya existían). Revisa la página para confirmar que los datos sí quedaron.\n";
}
