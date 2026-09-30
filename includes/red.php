<?php
declare(strict_types=1);

/* =========================================================
   IP real del visitante detrás del proxy de Railway
   =========================================================
   En Railway nadie se conecta directo a la app: todo pasa por el proxy de su
   borde, y la app ve como dirección de origen una interna del proxy (rango
   100.64.0.0/10), DISTINTA en cada petición aunque sea la misma persona (se
   comprobó en los registros de Apache: un mismo navegador llegaba como
   100.64.0.1, .2, .13, .15, .22... en el mismo segundo). Con eso, un límite
   "por IP" no limitaba a nadie.

   El proxy sí informa la IP real en la cabecera X-Real-IP (documentado por
   Railway). Pero una cabecera la puede mandar cualquiera, así que solo se le
   cree cuando la conexión viene del proxy. En un XAMPP local o una conexión
   directa se ignora. (OWASP ASVS V4 · CWE-348)

   Nota: el login (includes/auth.php, ip_cliente()) todavía usa REMOTE_ADDR,
   a propósito: el login quedó fuera de esta revisión.
   ========================================================= */

/** Rangos desde los que se acepta X-Real-IP: el proxy de Railway. */
const PROXIES_DE_CONFIANZA = ['100.64.0.0/10'];

/** true si $ip (IPv4) está dentro de $rango en notación CIDR IPv4. */
function ip_en_rango(string $ip, string $rango): bool
{
    [$red, $bits] = explode('/', $rango) + [1 => '32'];
    $ipBin  = @inet_pton($ip);
    $redBin = @inet_pton($red);
    if ($ipBin === false || $redBin === false || strlen($ipBin) !== strlen($redBin)) {
        return false;
    }
    $bits  = (int) $bits;
    $bytes = intdiv($bits, 8);
    if (substr($ipBin, 0, $bytes) !== substr($redBin, 0, $bytes)) {
        return false;
    }
    $resto = $bits % 8;
    if ($resto === 0) {
        return true;
    }
    $mascara = chr((0xFF << (8 - $resto)) & 0xFF);
    return (($ipBin[$bytes] & $mascara) === ($redBin[$bytes] & $mascara));
}

/** IP real de quien visita: X-Real-IP solo si la conexión viene del proxy. */
function ip_visitante(): string
{
    $remota = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    foreach (PROXIES_DE_CONFIANZA as $rango) {
        if (ip_en_rango($remota, $rango)) {
            $real = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
            if (filter_var($real, FILTER_VALIDATE_IP) !== false) {
                return $real;
            }
            break;
        }
    }

    return filter_var($remota, FILTER_VALIDATE_IP) !== false ? $remota : '0.0.0.0';
}

/**
 * Clave para contar intentos de una misma persona. IPv4: la dirección tal cual.
 * IPv6: su bloque /64 (una conexión casera recibe millones de direcciones de un
 * mismo /64; contar cada una por separado dejaría saltarse el límite cambiando
 * de dirección).
 */
function clave_limite_ip(string $ip): string
{
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return $ip;
    }
    if (strlen($bin) === 16) {
        return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
    return inet_ntop($bin);
}
