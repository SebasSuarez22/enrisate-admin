<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/red.php';
require_once __DIR__ . '/cifrado.php';
require_once __DIR__ . '/totp.php';

/* =========================================================
   Usuarios del panel, códigos de respaldo, límite de intentos y registro
   =========================================================
   Cada persona entra con su usuario, su contraseña y el código de 6 dígitos de
   su app autenticadora (ver includes/totp.php). Roles:
     · admin  → todo el panel, más la pantalla Usuarios.
     · ventas → todo el panel menos Usuarios.
   Regla: siempre deben quedar al menos ADMINS_MINIMOS administradores activos
   (si uno pierde el celular, otro le restablece la verificación).
   (OWASP ASVS V6 Autenticación · V7 Sesiones · V8 Autorización · V16 Registro)
   ========================================================= */

const ROLES            = ['admin' => 'Administración', 'ventas' => 'Ventas'];
const ADMINS_MINIMOS   = 2;
const CLAVE_MINIMA     = 12;   // NIST SP 800-63B: mínimo 8; ASVS 5.0 L2: 12 o más
const CLAVE_MAXIMA     = 128;
const CODIGOS_RESPALDO = 10;
/* Sin letras ni números que se confundan al copiarlos a mano (0/O, 1/I/L). */
const RESPALDO_ALFABETO = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

/* Límite de intentos (fallos de contraseña, de código o de código de respaldo).
   Por cuenta: desde el 5.º fallo seguido, espera de 1 min que se duplica en cada
   fallo nuevo, hasta 15 min. Nunca un bloqueo permanente: nadie puede dejar
   fuera a otra persona para siempre solo con equivocarse a propósito.
   Por IP (la real, ver includes/red.php): 30 fallos → 15 min. */
const INTENTOS_LIBRES_CUENTA = 5;
const ESPERA_MINIMA          = 60;
const ESPERA_MAXIMA          = 900;
const INTENTOS_LIBRES_IP     = 30;
const ESPERA_IP              = 900;

/* ---------------------------------------------------------
   Lectura
   --------------------------------------------------------- */

/** El nombre de usuario en su forma única: minúsculas, sin espacios a los lados. */
function normalizar_usuario(string $usuario): string
{
    return mb_strtolower(trim($usuario));
}

function usuario_valido(string $usuario): bool
{
    return (bool) preg_match('/^[a-z0-9._-]{3,40}$/', $usuario);
}

function leer_usuario(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM usuarios WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function leer_usuario_por_nombre(string $usuario): ?array
{
    $st = db()->prepare('SELECT * FROM usuarios WHERE usuario = ?');
    $st->execute([normalizar_usuario($usuario)]);
    return $st->fetch() ?: null;
}

function listar_usuarios(): array
{
    return db()->query(
        "SELECT u.*, (SELECT COUNT(*) FROM usuario_codigos_respaldo c WHERE c.usuario_id = u.id AND c.usado_el IS NULL) AS respaldos
           FROM usuarios u
          ORDER BY u.activo DESC, u.rol = 'admin' DESC, u.nombre"
    )->fetchAll();
}

function hay_usuarios(): bool
{
    return (int) db()->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0;
}

/** Administradores activos (con o sin verificación configurada). */
function contar_admins_activos(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1")->fetchColumn();
}

/* ---------------------------------------------------------
   Contraseñas
   --------------------------------------------------------- */

function hash_clave(string $clave): string
{
    return password_hash($clave, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
}

/**
 * Revisa una contraseña nueva. Devuelve el problema en palabras, o '' si está bien.
 * Sigue NIST SP 800-63B: largo mínimo, sin reglas de "una mayúscula y un símbolo",
 * se admiten espacios y cualquier carácter, y se rechazan las más usadas.
 */
function problema_clave(string $clave, string $usuario = '', string $nombre = ''): string
{
    $largo = mb_strlen($clave);
    if ($largo < CLAVE_MINIMA) {
        return 'La contraseña debe tener al menos ' . CLAVE_MINIMA . ' caracteres. Una frase corta sirve, por ejemplo “mi perro come mango”.';
    }
    if ($largo > CLAVE_MAXIMA) {
        return 'La contraseña es demasiado larga (máximo ' . CLAVE_MAXIMA . ' caracteres).';
    }
    $baja = mb_strtolower($clave);
    $sin  = preg_replace('/[^a-z0-9ñ]/u', '', $baja) ?? $baja;
    foreach (array_filter([normalizar_usuario($usuario), mb_strtolower(trim($nombre)), 'enrisate', 'enrísate']) as $prohibida) {
        if ($prohibida !== '' && str_contains($baja, $prohibida) && mb_strlen($prohibida) >= 4) {
            return 'La contraseña no puede contener tu usuario, tu nombre ni el nombre del negocio.';
        }
    }
    if (count(array_unique(mb_str_split($baja))) < 4) {
        return 'La contraseña repite casi siempre lo mismo. Usa una frase o una combinación más variada.';
    }
    if (in_array($sin, claves_comunes(), true)) {
        return 'Esa contraseña está entre las más usadas y se adivina rápido. Escoge otra.';
    }
    return '';
}

/** Contraseñas más usadas (y sus variantes de 12+ letras), en minúsculas y sin símbolos. */
function claves_comunes(): array
{
    return [
        '123456789012', '1234567890123', '12345678901234', '123456123456', '111111111111', '000000000000',
        'qwertyuiopas', 'qwerty123456', 'qwertyuiop123', 'asdfghjklñ12', 'password1234', 'passwordpassword',
        'contraseña123', 'contrasena123', 'contraseña1234', 'contrasena1234', 'iloveyou1234', 'teamo123456',
        'teamomucho12', 'colombia1234', 'colombia2024', 'colombia2025', 'colombia2026', 'barranquilla',
        'barranquilla1', 'barranquilla123', 'administrador', 'administrador1', 'administrador123',
        'admin1234567', 'adminadmin12', 'bienvenido123', 'bienvenida123', 'abcdefghijkl', 'abc123abc123',
        'superman1234', 'princesa1234', 'futbol123456', 'junior123456', 'america12345', 'mariposa1234',
        'estrellita12', 'dragon123456', 'monkey123456', 'letmein12345', 'welcome12345', 'sunshine1234',
        'football1234', 'baseball1234', 'qazwsxedcrfv', '1q2w3e4r5t6y', 'zaq12wsxcde3', 'aaaaaaaaaaaa',
    ];
}

/* ---------------------------------------------------------
   Escritura (cada cambio queda en el registro)
   --------------------------------------------------------- */

/** Crea un usuario. Lanza InvalidArgumentException con el problema en palabras. */
function crear_usuario(string $usuario, string $nombre, string $rol, string $clave, bool $cambiarClave, ?array $quien): int
{
    $usuario = normalizar_usuario($usuario);
    $nombre  = trim($nombre);
    if (!usuario_valido($usuario)) {
        throw new InvalidArgumentException('El usuario debe tener de 3 a 40 letras sin tildes, números, punto, guion o guion bajo (por ejemplo: gina).');
    }
    if ($nombre === '' || mb_strlen($nombre) > 80) {
        throw new InvalidArgumentException('Escribe el nombre de la persona (máximo 80 caracteres).');
    }
    if (!isset(ROLES[$rol])) {
        throw new InvalidArgumentException('Rol no válido.');
    }
    $problema = problema_clave($clave, $usuario, $nombre);
    if ($problema !== '') {
        throw new InvalidArgumentException($problema);
    }
    if (leer_usuario_por_nombre($usuario)) {
        throw new InvalidArgumentException('Ya existe alguien con el usuario “' . $usuario . '”.');
    }
    $st = db()->prepare('INSERT INTO usuarios (usuario, nombre, rol, clave_hash, cambiar_clave) VALUES (?, ?, ?, ?, ?)');
    $st->execute([$usuario, $nombre, $rol, hash_clave($clave), $cambiarClave ? 1 : 0]);
    $id = (int) db()->lastInsertId();
    registrar_evento('usuario_creado', $quien, 'Creó a ' . $usuario . ' con rol ' . ROLES[$rol]);
    return $id;
}

function cambiar_clave_usuario(array $u, string $actual, string $nueva, bool $exigirActual = true): void
{
    if ($exigirActual && !password_verify($actual, (string) $u['clave_hash'])) {
        throw new InvalidArgumentException('La contraseña actual no coincide.');
    }
    if ($exigirActual && hash_equals($actual, $nueva)) {
        throw new InvalidArgumentException('La contraseña nueva debe ser distinta de la actual.');
    }
    $problema = problema_clave($nueva, (string) $u['usuario'], (string) $u['nombre']);
    if ($problema !== '') {
        throw new InvalidArgumentException($problema);
    }
    // Sube la versión de sesión: las demás sesiones abiertas de esta persona se cierran.
    db()->prepare('UPDATE usuarios SET clave_hash = ?, cambiar_clave = 0, sesion_version = sesion_version + 1 WHERE id = ?')
        ->execute([hash_clave($nueva), (int) $u['id']]);
    registrar_evento('clave_cambiada', $u);
}

/** Contraseña temporal puesta por administración: la persona la cambia al entrar. */
function poner_clave_temporal(array $u, string $temporal, array $quien): void
{
    $problema = problema_clave($temporal, (string) $u['usuario'], (string) $u['nombre']);
    if ($problema !== '') {
        throw new InvalidArgumentException($problema);
    }
    db()->prepare('UPDATE usuarios SET clave_hash = ?, cambiar_clave = 1, sesion_version = sesion_version + 1 WHERE id = ?')
        ->execute([hash_clave($temporal), (int) $u['id']]);
    registrar_evento('clave_restablecida', $quien, 'Puso una contraseña temporal a ' . $u['usuario']);
}

/** Activa o desactiva. No deja bajar de ADMINS_MINIMOS administradores activos. */
function cambiar_activo(array $u, bool $activo, array $quien): void
{
    if (!$activo) {
        if ((int) $u['id'] === (int) $quien['id']) {
            throw new InvalidArgumentException('No puedes desactivar tu propia cuenta.');
        }
        if ($u['rol'] === 'admin' && (int) $u['activo'] === 1 && contar_admins_activos() <= ADMINS_MINIMOS) {
            throw new InvalidArgumentException('Deben quedar al menos ' . ADMINS_MINIMOS . ' administradores activos. Agrega otro antes de desactivar a ' . $u['nombre'] . '.');
        }
    }
    db()->prepare('UPDATE usuarios SET activo = ?, sesion_version = sesion_version + 1 WHERE id = ?')
        ->execute([$activo ? 1 : 0, (int) $u['id']]);
    registrar_evento($activo ? 'usuario_activado' : 'usuario_desactivado', $quien, ($activo ? 'Activó a ' : 'Desactivó a ') . $u['usuario']);
}

function cambiar_rol(array $u, string $rol, array $quien): void
{
    if (!isset(ROLES[$rol]) || $rol === $u['rol']) {
        return;
    }
    if ((int) $u['id'] === (int) $quien['id']) {
        throw new InvalidArgumentException('No puedes cambiar tu propio rol.');
    }
    if ($u['rol'] === 'admin' && (int) $u['activo'] === 1 && contar_admins_activos() <= ADMINS_MINIMOS) {
        throw new InvalidArgumentException('Deben quedar al menos ' . ADMINS_MINIMOS . ' administradores activos.');
    }
    db()->prepare('UPDATE usuarios SET rol = ?, sesion_version = sesion_version + 1 WHERE id = ?')
        ->execute([$rol, (int) $u['id']]);
    registrar_evento('rol_cambiado', $quien, 'Cambió el rol de ' . $u['usuario'] . ' a ' . ROLES[$rol]);
}

/** Borra el autenticador y los códigos de respaldo; en su próximo ingreso lo vuelve a configurar. */
function restablecer_verificacion(array $u, array $quien): void
{
    en_transaccion(function () use ($u) {
        db()->prepare('UPDATE usuarios SET totp_secreto = NULL, totp_activo = 0, totp_ultimo_paso = 0, sesion_version = sesion_version + 1 WHERE id = ?')
            ->execute([(int) $u['id']]);
        db()->prepare('DELETE FROM usuario_codigos_respaldo WHERE usuario_id = ?')->execute([(int) $u['id']]);
    });
    registrar_evento('verificacion_restablecida', $quien, 'Restableció la verificación de ' . $u['usuario']);
}

/* ---------------------------------------------------------
   Verificación en dos pasos
   --------------------------------------------------------- */

/** El secreto de la app de esta persona (en bytes), o null si no tiene o no se puede leer. */
function secreto_totp(array $u): ?string
{
    if (empty($u['totp_secreto'])) {
        return null;
    }
    return descifrar((string) $u['totp_secreto']);
}

/** true si el código es correcto pero ya se usó (para decir "espera el siguiente" en vez de "no coincide"). */
function codigo_ya_usado(array $u, string $codigo): bool
{
    $secreto = secreto_totp($u);
    return $secreto !== null && totp_verificar($secreto, $codigo, 0) !== null;
}

/** Mensaje para un código que no sirvió. */
function mensaje_codigo_fallido(array $u, string $codigo): string
{
    return codigo_ya_usado($u, $codigo)
        ? 'Ese código ya se usó. Espera unos segundos a que la app muestre uno nuevo y escríbelo.'
        : 'Ese código no coincide. Escribe el que ves ahora en tu app (cambia cada 30 segundos).';
}

/** Revisa el código y, si sirve, lo marca como usado (no se puede volver a usar). */
function verificar_codigo_usuario(array $u, string $codigo): bool
{
    $secreto = secreto_totp($u);
    if ($secreto === null) {
        return false;
    }
    $paso = totp_verificar($secreto, $codigo, (int) $u['totp_ultimo_paso']);
    if ($paso === null) {
        return false;
    }
    // Condición en el UPDATE: si dos peticiones llegan a la vez con el mismo código,
    // solo una lo consigue.
    $st = db()->prepare('UPDATE usuarios SET totp_ultimo_paso = ? WHERE id = ? AND totp_ultimo_paso < ?');
    $st->execute([$paso, (int) $u['id'], $paso]);
    return $st->rowCount() === 1;
}

/** Guarda el secreto confirmado (el que la persona ya escaneó y probó) y activa la verificación. */
function activar_verificacion(array $u, string $secreto, int $paso): void
{
    db()->prepare('UPDATE usuarios SET totp_secreto = ?, totp_activo = 1, totp_ultimo_paso = ? WHERE id = ?')
        ->execute([cifrar($secreto), $paso, (int) $u['id']]);
    registrar_evento('verificacion_activada', $u);
}

/** "k7pq 9mz2" → "K7PQ9MZ2". */
function normalizar_respaldo(string $codigo): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codigo) ?? '');
}

/** Genera 10 códigos nuevos (borra los anteriores) y los devuelve para mostrarlos UNA vez. */
function generar_codigos_respaldo(array $u): array
{
    $codigos = [];
    $max = strlen(RESPALDO_ALFABETO) - 1;
    while (count($codigos) < CODIGOS_RESPALDO) {
        $c = '';
        for ($i = 0; $i < 8; $i++) {
            $c .= RESPALDO_ALFABETO[random_int(0, $max)];
        }
        $codigos[$c] = substr($c, 0, 4) . '-' . substr($c, 4);
    }
    en_transaccion(function () use ($u, $codigos) {
        db()->prepare('DELETE FROM usuario_codigos_respaldo WHERE usuario_id = ?')->execute([(int) $u['id']]);
        $st = db()->prepare('INSERT INTO usuario_codigos_respaldo (usuario_id, huella) VALUES (?, ?)');
        foreach (array_keys($codigos) as $c) {
            $st->execute([(int) $u['id'], huella_app($c)]);
        }
    });
    return array_values($codigos);
}

/** Usa (quema) un código de respaldo. true si servía. */
function usar_codigo_respaldo(array $u, string $codigo): bool
{
    $limpio = normalizar_respaldo($codigo);
    if (strlen($limpio) !== 8) {
        return false;
    }
    $st = db()->prepare('UPDATE usuario_codigos_respaldo SET usado_el = NOW() WHERE usuario_id = ? AND huella = ? AND usado_el IS NULL LIMIT 1');
    $st->execute([(int) $u['id'], huella_app($limpio)]);
    return $st->rowCount() === 1;
}

function respaldos_restantes(int $usuarioId): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM usuario_codigos_respaldo WHERE usuario_id = ? AND usado_el IS NULL');
    $st->execute([$usuarioId]);
    return (int) $st->fetchColumn();
}

/* ---------------------------------------------------------
   Límite de intentos (en MySQL: sobrevive a los despliegues)
   --------------------------------------------------------- */

/** Claves que cuentan los fallos de este intento: la cuenta escrita y la IP real. */
function claves_limite(string $usuario): array
{
    return [
        'u:' . substr(normalizar_usuario($usuario), 0, 60),
        'ip:' . clave_limite_ip(ip_visitante()),
    ];
}

/** Segundos que faltan para poder volver a intentar (0 = puede). */
function espera_ingreso(array $claves): int
{
    if (!$claves) {
        return 0;
    }
    $marcas = implode(',', array_fill(0, count($claves), '?'));
    $st = db()->prepare("SELECT MAX(bloqueado_hasta) FROM intentos_ingreso WHERE clave IN ($marcas)");
    $st->execute($claves);
    return max(0, (int) $st->fetchColumn() - time());
}

function registrar_fallo_ingreso(array $claves): void
{
    $ahora = time();
    foreach ($claves as $clave) {
        $esIp   = str_starts_with($clave, 'ip:');
        $libres = $esIp ? INTENTOS_LIBRES_IP : INTENTOS_LIBRES_CUENTA;
        db()->prepare(
            'INSERT INTO intentos_ingreso (clave, fallos, bloqueado_hasta, ultimo) VALUES (?, 1, 0, ?)
             ON DUPLICATE KEY UPDATE fallos = IF(ultimo < ?, 1, fallos + 1), ultimo = VALUES(ultimo)'
        )->execute([$clave, $ahora, $ahora - 86400]);   // un día sin fallos: el conteo vuelve a cero
        $st = db()->prepare('SELECT fallos FROM intentos_ingreso WHERE clave = ?');
        $st->execute([$clave]);
        $fallos = (int) $st->fetchColumn();
        if ($fallos >= $libres) {
            $espera = $esIp ? ESPERA_IP : min(ESPERA_MAXIMA, ESPERA_MINIMA * (2 ** ($fallos - $libres)));
            db()->prepare('UPDATE intentos_ingreso SET bloqueado_hasta = ? WHERE clave = ?')->execute([$ahora + $espera, $clave]);
        }
    }
    // Limpieza: lo que no tiene fallos hace más de un día no sirve para nada.
    if (random_int(1, 20) === 1) {
        db()->prepare('DELETE FROM intentos_ingreso WHERE ultimo < ? AND bloqueado_hasta < ?')->execute([$ahora - 86400, $ahora]);
    }
}

/** Ingreso correcto: la cuenta vuelve a cero (la IP no, para no premiar a quien prueba muchas cuentas). */
function limpiar_fallos_cuenta(string $usuario): void
{
    db()->prepare('DELETE FROM intentos_ingreso WHERE clave = ?')->execute(['u:' . substr(normalizar_usuario($usuario), 0, 60)]);
}

/* ---------------------------------------------------------
   Registro de seguridad
   --------------------------------------------------------- */

const EVENTOS = [
    'ingreso'                   => 'entró con su código',
    'ingreso_respaldo'          => 'entró con un código de respaldo',
    'fallo_clave'               => 'Contraseña incorrecta',
    'fallo_codigo'              => 'Código incorrecto',
    'fallo_respaldo'            => 'Código de respaldo incorrecto',
    'bloqueo'                   => 'Ingreso en espera por demasiados intentos',
    'salida'                    => 'cerró sesión',
    'usuario_creado'            => 'creó un usuario',
    'usuario_activado'          => 'activó un usuario',
    'usuario_desactivado'       => 'desactivó un usuario',
    'rol_cambiado'              => 'cambió un rol',
    'verificacion_activada'     => 'conectó su app autenticadora',
    'verificacion_restablecida' => 'restableció una verificación',
    'codigos_generados'         => 'generó códigos de respaldo nuevos',
    'clave_cambiada'            => 'cambió su contraseña',
    'clave_restablecida'        => 'puso una contraseña temporal',
];

/** $quien: la fila del usuario que hizo la acción (o null si nadie entró, p. ej. un usuario inexistente). */
function registrar_evento(string $evento, ?array $quien, string $detalle = '', string $usuarioEscrito = ''): void
{
    try {
        db()->prepare('INSERT INTO registro_seguridad (usuario_id, usuario, evento, detalle, ip) VALUES (?, ?, ?, ?, ?)')
            ->execute([
                $quien ? (int) $quien['id'] : null,
                mb_substr($quien ? (string) $quien['usuario'] : normalizar_usuario($usuarioEscrito), 0, 60),
                $evento,
                mb_substr($detalle, 0, 255),
                ip_visitante(),
            ]);
    } catch (Throwable $e) {
        // El registro nunca debe impedir entrar ni guardar: se avisa al log del servidor.
        error_log('[enrisate] registro_seguridad: ' . $e->getMessage());
    }
}

function leer_registro(int $limite = 30): array
{
    $st = db()->prepare('SELECT r.*, u.nombre FROM registro_seguridad r LEFT JOIN usuarios u ON u.id = r.usuario_id ORDER BY r.id DESC LIMIT ?');
    $st->bindValue(1, $limite, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}
