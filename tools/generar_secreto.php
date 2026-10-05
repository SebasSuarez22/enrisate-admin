<?php
declare(strict_types=1);

/* Genera la llave APP_SECRETO (32 bytes al azar, en Base64) que cifra los secretos
   de la verificación en dos pasos (ver includes/cifrado.php).

       php tools/generar_secreto.php

   Pega el resultado como variable de entorno APP_SECRETO en Railway (pestaña
   Variables del servicio). Guárdala también en un lugar seguro: si se pierde,
   todas las personas tienen que volver a conectar su app autenticadora
   (administración restablece la verificación de cada una). */

if (PHP_SAPI !== 'cli') {
    exit('Este script solo corre desde la terminal.');
}

echo base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), PHP_EOL;
