<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
// e() y normalizar_telefono() vivían aquí; ahora están en formato.php. Se carga desde
// auth.php para que el inicio de sesión (index.php) y cualquier página que solo pedía
// auth.php sigan teniéndolas a mano, sin tocar esas páginas.
require_once __DIR__ . '/formato.php';
require_once __DIR__ . '/usuarios.php';

/* =========================================================
   Ingreso en dos pasos y sesión
   =========================================================
   1. Usuario y contraseña (index.php).
   2. Código de 6 dígitos de la app autenticadora o un código de respaldo
      (verificar.php). La primera vez, en vez del código, se conecta el celular
      (configurar-verificacion.php) y, si administración puso una contraseña
      temporal, antes se crea una propia (nueva-clave.php).
   Entre el paso 1 y el 2 la persona está en "pre-ingreso": la sesión solo
   recuerda quién es y desde cuándo; ninguna página del panel la deja pasar.
   (OWASP ASVS V6 · V7 · NIST SP 800-63B AAL2)
   ========================================================= */

const SESION_MAXIMA   = 8 * 3600;  // 8 h desde el ingreso, aunque se use todo el tiempo
const PREINGRESO_MAX  = 600;       // 10 min para terminar el paso 2
/* Hash de una contraseña al azar que nadie conoce: se compara contra él cuando el
   usuario escrito no existe, para que esa respuesta tarde lo mismo que una real. */
const HASH_SIN_USUARIO = '$argon2id$v=19$m=65536,t=4,p=1$UFlnRjhPN2RXdTYzSTVFMA$6x+zqRt8yljI5ebEcXX1qrxvuvkSjy+0rXAtt99ejEE';

/* ---------------------------------------------------------
   Sesión endurecida
   --------------------------------------------------------- */
function conexion_https(): bool
{
    // En Railway la conexión del navegador es HTTPS pero el proxy le habla a la app
    // en HTTP; el proxy avisa la original en X-Forwarded-Proto.
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function iniciar_sesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = conexion_https();
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Strict',
    ]);
    // Con HTTPS, el prefijo __Host- obliga al navegador a aceptar la cookie solo si
    // es Secure, de este mismo dominio y para todo el sitio (no la puede plantar un
    // subdominio). En http local no se puede usar.
    session_name($https ? '__Host-enrisate' : 'ENRISATE_ADMIN');
    ini_set('session.use_strict_mode', '1');
    session_start();

    $ahora = time();
    if (isset($_SESSION['ultima_actividad']) && ($ahora - (int) $_SESSION['ultima_actividad']) > SESION_INACTIVIDAD) {
        cerrar_sesion();
        header('Location: index.php?motivo=inactividad');
        exit;
    }
    if (isset($_SESSION['ingreso']) && ($ahora - (int) $_SESSION['ingreso']) > SESION_MAXIMA) {
        cerrar_sesion();
        header('Location: index.php?motivo=vencida');
        exit;
    }
    $_SESSION['ultima_actividad'] = $ahora;
}

function hay_sesion(): bool
{
    return !empty($_SESSION['autenticado']) && $_SESSION['autenticado'] === true;
}

/**
 * La persona que tiene la sesión, leída de la base en cada petición: si la
 * desactivaron, le restablecieron la verificación o cambió su contraseña en otro
 * equipo (sube sesion_version), esta sesión deja de servir de inmediato.
 */
function usuario_actual(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    if (!hay_sesion()) {
        return $cache = null;
    }
    $u = leer_usuario((int) ($_SESSION['usuario_id'] ?? 0));
    if (!$u || (int) $u['activo'] !== 1 || (int) $u['sesion_version'] !== (int) ($_SESSION['version'] ?? -1)) {
        return $cache = null;
    }
    $_SESSION['nombre'] = $u['nombre'];
    $_SESSION['rol']    = $u['rol'];
    return $cache = $u;
}

function requerir_sesion(): void
{
    iniciar_sesion();
    if (!hay_sesion()) {
        // A mitad del ingreso (contraseña ya revisada): vuelve al paso que le falta.
        $pre = usuario_preingreso();
        if ($pre) {
            $sigue = siguiente_paso_ingreso($pre);
            header('Location: ' . ($sigue === 'panel' ? 'index.php' : $sigue));
            exit;
        }
        header('Location: index.php?motivo=sesion');
        exit;
    }
    if (usuario_actual() === null) {
        cerrar_sesion();
        header('Location: index.php?motivo=sesion');
        exit;
    }
}

function es_admin(): bool
{
    $u = usuario_actual();
    return $u !== null && $u['rol'] === 'admin';
}

function requerir_admin(): void
{
    requerir_sesion();
    if (!es_admin()) {
        responder_error_generico(403, 'Esta sección es solo para administración.');
    }
}

function cerrar_sesion(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Strict',
        ]);
    }
    session_destroy();
}

/* ---------------------------------------------------------
   CSRF
   --------------------------------------------------------- */
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

/* ---------------------------------------------------------
   Primera cuenta
   ---------------------------------------------------------
   Cuando la tabla de usuarios está vacía (la primera vez después de esta
   versión), la cuenta de siempre (ADMIN_USUARIO + ADMIN_HASH) se convierte en
   el primer usuario administrador. En Railway solo si ADMIN_HASH viene de la
   variable de entorno: la clave de pruebas escrita en el código nunca abre el
   panel en producción. En local sí (y obliga a cambiarla al entrar).
   --------------------------------------------------------- */
function asegurar_usuario_inicial(): void
{
    if (hay_usuarios()) {
        return;
    }
    $deEntorno = (string) getenv('ADMIN_HASH') !== '';
    if (!$deEntorno && en_produccion()) {
        error_log('[enrisate] No hay usuarios y falta ADMIN_HASH en las variables de entorno: el ingreso queda cerrado.');
        return;
    }
    try {
        db()->prepare("INSERT INTO usuarios (usuario, nombre, rol, clave_hash, cambiar_clave) VALUES (?, ?, 'admin', ?, ?)")
            ->execute([ADMIN_USUARIO, ADMIN_NOMBRE, ADMIN_HASH, $deEntorno ? 0 : 1]);
    } catch (PDOException $e) {
        // Otra petición la creó al mismo tiempo (usuario único): no pasa nada.
    }
}

/* ---------------------------------------------------------
   Paso 1: usuario y contraseña
   --------------------------------------------------------- */

/**
 * Revisa usuario y contraseña. Devuelve:
 *   ['estado' => 'ok', 'usuario' => fila]   → sigue al paso 2
 *   ['estado' => 'espera', 'segundos' => n] → demasiados intentos
 *   ['estado' => 'mal']                      → no coincide (sin decir qué)
 */
function revisar_contrasena(string $usuario, string $clave): array
{
    asegurar_usuario_inicial();
    $claves = claves_limite($usuario);
    $espera = espera_ingreso($claves);
    if ($espera > 0) {
        return ['estado' => 'espera', 'segundos' => $espera];
    }

    $u = leer_usuario_por_nombre($usuario);
    // Con un usuario que no existe se calcula igual un hash, para que el tiempo de
    // respuesta no delate qué usuarios existen.
    $hash = $u ? (string) $u['clave_hash'] : HASH_SIN_USUARIO;
    $bien = password_verify($clave, $hash) && $u && (int) $u['activo'] === 1;

    if (!$bien) {
        registrar_fallo_ingreso($claves);
        registrar_evento('fallo_clave', $u ?: null, $u && (int) $u['activo'] !== 1 ? 'Cuenta desactivada' : '', $usuario);
        $espera = espera_ingreso($claves);
        if ($espera > 0) {
            registrar_evento('bloqueo', $u ?: null, 'Espera de ' . ceil($espera / 60) . ' min', $usuario);
            return ['estado' => 'espera', 'segundos' => $espera];
        }
        return ['estado' => 'mal'];
    }

    if (password_needs_rehash($hash, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE usuarios SET clave_hash = ? WHERE id = ?')->execute([hash_clave($clave), (int) $u['id']]);
    }
    return ['estado' => 'ok', 'usuario' => $u];
}

/* ---------------------------------------------------------
   Pre-ingreso (entre el paso 1 y el panel)
   --------------------------------------------------------- */
function iniciar_preingreso(array $u): void
{
    session_regenerate_id(true);
    unset($_SESSION['autenticado'], $_SESSION['usuario_id'], $_SESSION['codigos_nuevos']);
    $_SESSION['pre'] = [
        'id'        => (int) $u['id'],
        'version'   => (int) $u['sesion_version'],
        'desde'     => time(),
        'codigo_ok' => false,
    ];
}

/** La persona en pre-ingreso (fila fresca de la base), o null si no hay o venció. */
function usuario_preingreso(): ?array
{
    $pre = $_SESSION['pre'] ?? null;
    if (!is_array($pre) || (time() - (int) $pre['desde']) > PREINGRESO_MAX) {
        unset($_SESSION['pre']);
        return null;
    }
    $u = leer_usuario((int) $pre['id']);
    if (!$u || (int) $u['activo'] !== 1 || (int) $u['sesion_version'] !== (int) $pre['version']) {
        unset($_SESSION['pre']);
        return null;
    }
    return $u;
}

/** Marca que la persona ya probó tener su celular (código, respaldo o recién lo configuró). */
function marcar_codigo_ok(string $via = 'ingreso', string $detalle = ''): void
{
    if (isset($_SESSION['pre'])) {
        $_SESSION['pre']['codigo_ok'] = true;
        $_SESSION['pre']['via']       = $via;
        $_SESSION['pre']['detalle']   = $detalle;
    }
}

/** La página que sigue para quien está en pre-ingreso; 'panel' si ya puede entrar. */
function siguiente_paso_ingreso(array $u): string
{
    $codigoOk = !empty($_SESSION['pre']['codigo_ok']);
    if ((int) $u['totp_activo'] !== 1) {
        return (int) $u['cambiar_clave'] === 1 ? 'nueva-clave.php' : 'configurar-verificacion.php';
    }
    if (!$codigoOk) {
        return 'verificar.php';
    }
    if ((int) $u['cambiar_clave'] === 1) {
        return 'nueva-clave.php';
    }
    return 'panel';
}

/** Para las páginas del paso 2: exige pre-ingreso y que esta sea la página que toca. */
function requerir_preingreso(string $pagina): array
{
    iniciar_sesion();
    if (hay_sesion() && usuario_actual()) {
        header('Location: dashboard.php');
        exit;
    }
    $u = usuario_preingreso();
    if (!$u) {
        header('Location: index.php?motivo=pasos');
        exit;
    }
    $sigue = siguiente_paso_ingreso($u);
    if ($sigue !== $pagina && !($pagina === 'configurar-verificacion.php' && !empty($_SESSION['codigos_nuevos']))) {
        header('Location: ' . ($sigue === 'panel' ? 'dashboard.php' : $sigue));
        exit;
    }
    return $u;
}

/** Fin del ingreso: sesión nueva y completa. */
function completar_ingreso(array $u, string $evento = 'ingreso', string $detalle = ''): void
{
    session_regenerate_id(true);
    unset($_SESSION['pre'], $_SESSION['codigos_nuevos']);
    $ahora = time();
    $_SESSION['autenticado']      = true;
    $_SESSION['usuario_id']       = (int) $u['id'];
    $_SESSION['version']          = (int) $u['sesion_version'];
    $_SESSION['usuario']          = $u['usuario'];
    $_SESSION['nombre']           = $u['nombre'];
    $_SESSION['rol']              = $u['rol'];
    $_SESSION['ingreso']          = $ahora;
    $_SESSION['ultima_actividad'] = $ahora;
    db()->prepare('UPDATE usuarios SET ultimo_ingreso = NOW() WHERE id = ?')->execute([(int) $u['id']]);
    limpiar_fallos_cuenta((string) $u['usuario']);
    registrar_evento($evento, $u, $detalle);
}

/** Lleva a quien está en pre-ingreso a la página que sigue (o al panel). */
function avanzar_ingreso(array $u): never
{
    $u = leer_usuario((int) $u['id']) ?? $u;
    $sigue = siguiente_paso_ingreso($u);
    if ($sigue === 'panel') {
        completar_ingreso($u, (string) ($_SESSION['pre']['via'] ?? 'ingreso'), (string) ($_SESSION['pre']['detalle'] ?? ''));
        header('Location: dashboard.php');
    } else {
        header('Location: ' . $sigue);
    }
    exit;
}
