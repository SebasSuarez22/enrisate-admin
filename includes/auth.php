<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
// e() y normalizar_telefono() vivían aquí; ahora están en formato.php. Se carga desde
// auth.php para que el inicio de sesión (index.php) y cualquier página que solo pedía
// auth.php sigan teniéndolas a mano, sin tocar esas páginas.
require_once __DIR__ . '/formato.php';

/* =========================================================
   Sesión endurecida
   ========================================================= */
function iniciar_sesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // En Railway (y casi cualquier servidor detrás de un proxy) la
    // conexión real del navegador sí es HTTPS, pero el proxy le habla a
    // esta aplicación por dentro en HTTP normal — $_SERVER['HTTPS'] no
    // se entera. El proxy sí manda X-Forwarded-Proto: https, avisando
    // cuál fue la conexión original.
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Strict',
    ]);
    session_name('ENRISATE_ADMIN');
    session_start();

    // Cierre por inactividad
    if (isset($_SESSION['ultima_actividad'])
        && (time() - (int) $_SESSION['ultima_actividad']) > SESION_INACTIVIDAD) {
        cerrar_sesion();
        header('Location: index.php?motivo=inactividad');
        exit;
    }
    $_SESSION['ultima_actividad'] = time();
}

function hay_sesion(): bool
{
    return !empty($_SESSION['autenticado']) && $_SESSION['autenticado'] === true;
}

function requerir_sesion(): void
{
    iniciar_sesion();
    if (!hay_sesion()) {
        header('Location: index.php?motivo=sesion');
        exit;
    }
}

function cerrar_sesion(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* =========================================================
   CSRF
   ========================================================= */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valido(?string $token): bool
{
    return is_string($token)
        && !empty($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $token);
}

/* =========================================================
   Control de intentos (bloqueo temporal por IP)
   ========================================================= */
function ip_cliente(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function ruta_intentos(): string
{
    if (!is_dir(STORAGE_DIR)) {
        @mkdir(STORAGE_DIR, 0775, true);
    }
    return STORAGE_DIR . '/intentos.json';
}

function leer_intentos(): array
{
    $ruta = ruta_intentos();
    if (!is_file($ruta)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($ruta), true);
    return is_array($data) ? $data : [];
}

function guardar_intentos(array $data): void
{
    // Limpia registros viejos para que el archivo no crezca sin control
    $limite = time() - (BLOQUEO_SEGUNDOS * 4);
    foreach ($data as $k => $v) {
        if (($v['ultimo'] ?? 0) < $limite) {
            unset($data[$k]);
        }
    }
    file_put_contents(ruta_intentos(), json_encode($data), LOCK_EX);
}

/** Segundos que faltan para poder volver a intentar. 0 = puede intentar. */
function segundos_bloqueo(): int
{
    $data = leer_intentos();
    $reg  = $data[ip_cliente()] ?? null;
    if (!$reg || ($reg['fallos'] ?? 0) < MAX_INTENTOS) {
        return 0;
    }
    $restante = BLOQUEO_SEGUNDOS - (time() - (int) $reg['ultimo']);
    return $restante > 0 ? $restante : 0;
}

function intentos_restantes(): int
{
    $data = leer_intentos();
    $reg  = $data[ip_cliente()] ?? null;
    $fallos = (int) ($reg['fallos'] ?? 0);
    return max(0, MAX_INTENTOS - $fallos);
}

function registrar_fallo(): void
{
    $data = leer_intentos();
    $ip   = ip_cliente();
    $reg  = $data[$ip] ?? ['fallos' => 0, 'ultimo' => 0];

    // Si el bloqueo ya venció, se reinicia el conteo
    if ((int) $reg['fallos'] >= MAX_INTENTOS
        && (time() - (int) $reg['ultimo']) >= BLOQUEO_SEGUNDOS) {
        $reg = ['fallos' => 0, 'ultimo' => 0];
    }

    $reg['fallos'] = (int) $reg['fallos'] + 1;
    $reg['ultimo'] = time();
    $data[$ip]     = $reg;
    guardar_intentos($data);
}

function limpiar_fallos(): void
{
    $data = leer_intentos();
    unset($data[ip_cliente()]);
    guardar_intentos($data);
}

/* =========================================================
   Login
   ========================================================= */
function intentar_login(string $usuario, string $clave): bool
{
    $usuario_ok = hash_equals(ADMIN_USUARIO, $usuario);
    $clave_ok   = password_verify($clave, ADMIN_HASH);

    if ($usuario_ok && $clave_ok) {
        session_regenerate_id(true);
        $_SESSION['autenticado']      = true;
        $_SESSION['usuario']          = ADMIN_USUARIO;
        $_SESSION['nombre']           = ADMIN_NOMBRE;
        $_SESSION['ingreso']          = time();
        $_SESSION['ultima_actividad'] = time();
        limpiar_fallos();
        return true;
    }

    registrar_fallo();
    return false;
}
