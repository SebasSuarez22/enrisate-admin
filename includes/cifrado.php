<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

/* =========================================================
   Llave de la aplicación: cifra los secretos de la verificación
   =========================================================
   El secreto de la app autenticadora de cada persona se guarda CIFRADO en la
   base (si alguien se lleva una copia de la base, no puede generar códigos), y
   los códigos de respaldo se guardan como huella HMAC (no se pueden leer).

   La llave sale de la variable de entorno APP_SECRETO (32 bytes en Base64;
   se genera con `php tools/generar_secreto.php`). Vive fuera de la base y fuera
   del código, igual que ADMIN_HASH.
     · En Railway es obligatoria: sin ella el ingreso no arranca (se niega en
       vez de cifrar con una llave conocida).
     · En un XAMPP local, si no está, se crea una sola vez en
       storage/clave_local.key (carpeta que .htaccess no sirve).
   (OWASP ASVS V11 · V6 · NIST SP 800-63B §5.1.4.2)
   ========================================================= */

final class LlaveFaltante extends RuntimeException {}

/** true cuando la app corre en Railway (producción). */
function en_produccion(): bool
{
    return (string) getenv('RAILWAY_ENVIRONMENT') !== '' || (string) getenv('RAILWAY_PROJECT_ID') !== '';
}

function llave_app(): string
{
    static $llave = null;
    if ($llave !== null) {
        return $llave;
    }

    $env = (string) getenv('APP_SECRETO');
    if ($env !== '') {
        $bytes = base64_decode($env, true);
        if ($bytes === false || strlen($bytes) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new LlaveFaltante('APP_SECRETO no es válida: debe ser de 32 bytes en Base64 (php tools/generar_secreto.php).');
        }
        return $llave = $bytes;
    }

    if (en_produccion()) {
        throw new LlaveFaltante('Falta la variable de entorno APP_SECRETO (php tools/generar_secreto.php).');
    }

    $ruta = STORAGE_DIR . '/clave_local.key';
    if (!is_file($ruta)) {
        if (!is_dir(STORAGE_DIR)) {
            @mkdir(STORAGE_DIR, 0775, true);
        }
        file_put_contents($ruta, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), LOCK_EX);
        @chmod($ruta, 0600);
    }
    $bytes = base64_decode(trim((string) file_get_contents($ruta)), true);
    if ($bytes === false || strlen($bytes) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new LlaveFaltante('storage/clave_local.key está dañada: bórrala para crear una nueva.');
    }
    return $llave = $bytes;
}

/** Cifra (XSalsa20-Poly1305): el resultado lleva su nonce y detecta cualquier alteración. */
function cifrar(string $texto): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($texto, $nonce, llave_app()));
}

/** null si el texto fue alterado o se cifró con otra llave. */
function descifrar(string $cifrado): ?string
{
    if (!str_starts_with($cifrado, 'v1:')) {
        return null;
    }
    $bytes = base64_decode(substr($cifrado, 3), true);
    if ($bytes === false || strlen($bytes) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return null;
    }
    $nonce = substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $texto = sodium_crypto_secretbox_open(substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, llave_app());
    return $texto === false ? null : $texto;
}

/** Huella con la llave de la app (para guardar códigos de respaldo sin poder leerlos). */
function huella_app(string $texto): string
{
    return hash_hmac('sha256', $texto, llave_app());
}
