<?php
declare(strict_types=1);

/* Herramienta de un solo uso: deja una base de datos remota lista
   para volver a correr tools/importar_datos.php desde cero.

   Hace 2 cosas:
   1) Vacía las 8 tablas (por si un intento anterior dejó datos a
      medias o repetidos).
   2) Le agrega a `productos` y `pedido_lineas` un par de columnas que
      el archivo esquema.sql no tenía la primera vez que se armó, pero
      que sí existen en instalaciones reales de la app (creado_en /
      actualizado_en en productos, id autoincremental en
      pedido_lineas) — sin esto, importar esas dos tablas fallaba.

   Uso:
     php tools/preparar_reimportacion.php <host> <puerto> <usuario> <clave> <base_de_datos>
*/

if (PHP_SAPI !== 'cli') {
    exit('Este script solo corre desde la terminal.');
}

[, $host, $puerto, $usuario, $clave, $baseDatos] = $argv + [null, null, null, null, null, null];

if (!$host || !$puerto || !$usuario || $clave === null || !$baseDatos) {
    fwrite(STDERR, "Uso: php tools/preparar_reimportacion.php <host> <puerto> <usuario> <clave> <base_de_datos>\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = mysqli_init();
if (!$mysqli->real_connect($host, $usuario, $clave, $baseDatos, (int) $puerto)) {
    fwrite(STDERR, "No se pudo conectar: " . mysqli_connect_error() . "\n");
    exit(1);
}

$mysqli->query('SET FOREIGN_KEY_CHECKS=0');

foreach (['factura_abonos', 'factura_cuotas', 'factura_lineas', 'facturas', 'pedido_lineas', 'pedidos', 'clientes', 'productos'] as $tabla) {
    if ($mysqli->query("TRUNCATE TABLE `$tabla`")) {
        echo "Vaciada: $tabla\n";
    } else {
        echo "Aviso al vaciar $tabla: " . $mysqli->error . "\n";
    }
}

$columnas = $mysqli->query("SHOW COLUMNS FROM `productos` LIKE 'creado_en'");
if ($columnas && $columnas->num_rows === 0) {
    if ($mysqli->query("ALTER TABLE `productos`
        ADD COLUMN `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
        ADD COLUMN `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()")) {
        echo "productos: columnas creado_en/actualizado_en agregadas.\n";
    } else {
        echo "Aviso al alterar productos: " . $mysqli->error . "\n";
    }
} else {
    echo "productos: ya tenía las columnas creado_en/actualizado_en.\n";
}

$columnas = $mysqli->query("SHOW COLUMNS FROM `pedido_lineas` LIKE 'id'");
if ($columnas && $columnas->num_rows === 0) {
    if ($mysqli->query("ALTER TABLE `pedido_lineas`
        DROP PRIMARY KEY,
        ADD COLUMN `id` int(10) unsigned NOT NULL AUTO_INCREMENT FIRST,
        ADD PRIMARY KEY (`id`),
        ADD UNIQUE KEY `pedido_numero` (`pedido_numero`,`orden`)")) {
        echo "pedido_lineas: columna id agregada.\n";
    } else {
        echo "Aviso al alterar pedido_lineas: " . $mysqli->error . "\n";
    }
} else {
    echo "pedido_lineas: ya tenía la columna id.\n";
}

$mysqli->query('SET FOREIGN_KEY_CHECKS=1');

echo "Listo. Ya puedes correr tools/importar_datos.php.\n";
