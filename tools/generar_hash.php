<?php
declare(strict_types=1);

/* Genera el hash de una contraseña nueva.
   Uso:  php tools/generar_hash.php "MiClaveSegura2026"
   Pega el resultado como variable de entorno ADMIN_HASH en Railway: con ella se
   crea la primera cuenta de administración (ver config.php). Las demás personas
   se crean desde la pantalla Usuarios del panel. */

if (PHP_SAPI !== 'cli') {
    exit('Este script solo corre desde la terminal.');
}

$clave = $argv[1] ?? '';

if (strlen($clave) < 12) {
    exit("Escribe una contraseña de al menos 12 caracteres.\n"
       . "Ejemplo: php tools/generar_hash.php \"MiClaveSegura2026\"\n");
}

echo password_hash($clave, PASSWORD_BCRYPT, ['cost' => 12]) . PHP_EOL;
