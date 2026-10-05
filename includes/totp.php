<?php
declare(strict_types=1);

/* =========================================================
   Códigos de 6 dígitos de una app autenticadora (TOTP)
   =========================================================
   El estándar abierto que usan Google Authenticator, Microsoft Authenticator,
   2FAS y el gestor de contraseñas del iPhone (RFC 6238 sobre RFC 4226):
   HMAC-SHA1, código de 6 dígitos que cambia cada 30 segundos. No depende de
   ningún servicio externo: el panel y el celular comparten un secreto y cada
   uno calcula el código con la hora.

   El secreto se le entrega a la app en Base32 (RFC 4648), que es lo que lleva
   el QR y la "clave de configuración" que se puede escribir a mano.
   ========================================================= */

const TOTP_DIGITOS  = 6;
const TOTP_PERIODO  = 30;
const TOTP_EMISOR   = 'Enrísate';
/* Pasos de 30 s aceptados antes y después del actual: tolera un reloj de celular
   corrido hasta 30 s sin abrir la puerta más de la cuenta (RFC 6238 §5.2). */
const TOTP_VENTANA  = 1;

const BASE32_ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32_codificar(string $bytes): string
{
    $bits = '';
    foreach (str_split($bytes) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $salida = '';
    foreach (str_split($bits, 5) as $grupo) {
        $salida .= BASE32_ALFABETO[bindec(str_pad($grupo, 5, '0', STR_PAD_RIGHT))];
    }
    return $salida;
}

/** null si el texto no es Base32 válido. Ignora espacios, guiones, minúsculas y el relleno "=". */
function base32_decodificar(string $texto): ?string
{
    $texto = strtoupper(preg_replace('/[\s\-=]+/', '', $texto) ?? '');
    if ($texto === '' || strspn($texto, BASE32_ALFABETO) !== strlen($texto)) {
        return null;
    }
    $bits = '';
    foreach (str_split($texto) as $c) {
        $bits .= str_pad(decbin(strpos(BASE32_ALFABETO, $c)), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    foreach (str_split($bits, 8) as $grupo) {
        if (strlen($grupo) === 8) {
            $bytes .= chr(bindec($grupo));
        }
    }
    return $bytes;
}

/** Secreto nuevo de 160 bits (lo que recomienda RFC 4226 §4), en bytes. */
function totp_nuevo_secreto(): string
{
    return random_bytes(20);
}

/** Paso de tiempo actual (cuántos bloques de 30 s van desde 1970). */
function totp_paso(?int $momento = null): int
{
    return intdiv($momento ?? time(), TOTP_PERIODO);
}

/** El código de 6 dígitos (con ceros a la izquierda) de un paso de tiempo. */
function totp_codigo(string $secreto, int $paso, string $algoritmo = 'sha1', int $digitos = TOTP_DIGITOS): string
{
    $mensaje = pack('J', $paso);                       // contador de 64 bits, big-endian
    $hmac    = hash_hmac($algoritmo, $mensaje, $secreto, true);
    $desfase = ord($hmac[strlen($hmac) - 1]) & 0x0F;   // "dynamic truncation" (RFC 4226 §5.3)
    $numero  = ((ord($hmac[$desfase]) & 0x7F) << 24)
             | (ord($hmac[$desfase + 1]) << 16)
             | (ord($hmac[$desfase + 2]) << 8)
             |  ord($hmac[$desfase + 3]);
    return str_pad((string) ($numero % (10 ** $digitos)), $digitos, '0', STR_PAD_LEFT);
}

/** Solo los dígitos de lo que escribió la persona ("482 913" → "482913"). */
function totp_limpiar(string $codigo): string
{
    return preg_replace('/\D+/', '', $codigo) ?? '';
}

/**
 * Revisa un código. Devuelve el paso de tiempo que coincidió, o null.
 * $ultimoPaso es el último paso ya aceptado para esa persona: un código que ya se
 * usó (o uno anterior) no vuelve a servir aunque siga dentro de sus 30 s, así un
 * código visto por encima del hombro o capturado no se puede reutilizar.
 */
function totp_verificar(string $secreto, string $codigo, int $ultimoPaso = 0, ?int $momento = null): ?int
{
    $codigo = totp_limpiar($codigo);
    if (strlen($codigo) !== TOTP_DIGITOS) {
        return null;
    }
    $actual  = totp_paso($momento);
    $acierto = null;
    // Se revisan todos los pasos de la ventana (sin cortar al primer acierto) para
    // que el tiempo de respuesta no dependa de cuál coincidió.
    for ($d = -TOTP_VENTANA; $d <= TOTP_VENTANA; $d++) {
        $paso = $actual + $d;
        if (hash_equals(totp_codigo($secreto, $paso), $codigo) && $paso > $ultimoPaso && $acierto === null) {
            $acierto = $paso;
        }
    }
    return $acierto;
}

/** Enlace otpauth:// que entiende la app: va dentro del QR y en el botón "Agregar a Google Authenticator". */
function totp_uri(string $usuario, string $secretoBase32): string
{
    $etiqueta = rawurlencode(TOTP_EMISOR) . ':' . rawurlencode($usuario);
    return 'otpauth://totp/' . $etiqueta . '?' . http_build_query([
        'secret'    => $secretoBase32,
        'issuer'    => TOTP_EMISOR,
        'algorithm' => 'SHA1',
        'digits'    => TOTP_DIGITOS,
        'period'    => TOTP_PERIODO,
    ], '', '&', PHP_QUERY_RFC3986);
}

/** La clave en grupos de 4 para leerla o dictarla sin perderse: "JBSW Y3DP EHPK 3PXP". */
function totp_clave_legible(string $secretoBase32): string
{
    return trim(chunk_split($secretoBase32, 4, ' '));
}
