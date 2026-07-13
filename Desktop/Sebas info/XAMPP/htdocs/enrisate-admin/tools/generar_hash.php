<?php
declare(strict_types=1);

/* Genera el hash de una contraseña nueva.
   Uso:  php tools/generar_hash.php "MiClaveSegura2026"
   Pega el resultado en ADMIN_HASH dentro de config.php  */

if (PHP_SAPI !== 'cli') {
    exit('Este script solo corre desde la terminal.');
}

$clave = $argv[1] ?? '';

if (strlen($clave) < 10) {
    exit("Escribe una contraseña de al menos 10 caracteres.\n"
       . "Ejemplo: php tools/generar_hash.php \"MiClaveSegura2026\"\n");
}

echo password_hash($clave, PASSWORD_BCRYPT, ['cost' => 12]) . PHP_EOL;
