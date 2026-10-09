<?php
declare(strict_types=1);

/* Genera la llave API_TOKEN de la API del catálogo (ver includes/api.php):
   32 bytes al azar, en hexadecimal (64 caracteres, sin símbolos que haya que
   escapar al pegarla en n8n o en Railway).

       php tools/generar_token_api.php

   Pégala como variable de entorno API_TOKEN en Railway (pestaña Variables del
   servicio de la app) y, la misma, en n8n como credencial "Header Auth" con
   nombre X-Api-Key. Si se filtra, genera otra y cámbiala en los dos lados. */

if (PHP_SAPI !== 'cli') {
    exit('Este script solo corre desde la terminal.');
}

echo bin2hex(random_bytes(32)), PHP_EOL;
