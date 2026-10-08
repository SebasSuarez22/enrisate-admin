<?php
declare(strict_types=1);

require_once __DIR__ . '/formato.php';
require_once __DIR__ . '/db.php';

/* =========================================================
   Mensajes a las clientas (módulo "Mensajes")
   =========================================================
   Por ahora el envío es manual y asistido: la página abre WhatsApp con el
   mensaje ya escrito para cada clienta y Gina toca "Enviar". Cuando Meta
   deje conectar la API, el mismo texto y la misma lista sirven para el envío
   automático desde n8n.

   Reglas que protegen a la clienta (Ley 1581 de 2012):
     · Solo se escribe a números de celular colombianos válidos.
     · Nunca se le escribe a quien dijo NO.
     · El mensaje de bienvenida pregunta si quiere recibir promociones y
       novedades, y la respuesta (SÍ / NO) queda guardada por clienta.
     · Cada clienta recibe cada campaña una sola vez.
   ========================================================= */

const MENSAJES_CAMPANAS = ['bienvenida' => 'Bienvenida'];

/* Modo "de 5 en 5": después de enviar este número de mensajes la pantalla hace una
   pausa y deja elegir entre seguir con otro grupo o terminar por hoy. Ir de a pocas
   evita que WhatsApp tome por spam los primeros envíos de un número nuevo. */
const MENSAJES_TAMANO_GRUPO = 5;

/** Primer nombre para el saludo ("Valentina Gómez" → "Valentina"). */
function mensajes_primer_nombre(string $nombre): string
{
    $nombre = trim($nombre);
    return $nombre === '' ? '' : (string) strtok($nombre, ' ');
}

/**
 * Texto de la bienvenida. El enlace y el número salen de config.php, así el
 * texto nunca queda con datos viejos. Incluye la pregunta del permiso.
 */
function mensaje_bienvenida(string $nombreCompleto): string
{
    $nombre = mensajes_primer_nombre($nombreCompleto);
    $numero = '+57 ' . substr(APP_WHATSAPP, 2, 3) . ' ' . substr(APP_WHATSAPP, 5, 3) . ' ' . substr(APP_WHATSAPP, 8);

    return 'Hola' . ($nombre !== '' ? ' ' . $nombre : '') . " 🌿\n"
        . 'Soy Gina, de *' . APP_NOMBRE . '*. Este es nuestro nuevo WhatsApp: *' . $numero . "*.\n\n"
        . "Aquí puedes pedir tus productos para el cabello, preguntar por precios y ver cómo va tu pedido.\n"
        . 'Mira el catálogo cuando quieras: ' . APP_URL_CATALOGO . "\n\n"
        . '¿Quieres que te enviemos mensajes automáticos con promociones y otras novedades? '
        . "Responde *SÍ* o *NO*. Puedes cambiar de opinión cuando quieras.";
}

/** true si es un celular colombiano de 10 dígitos (empieza en 3), con o sin +57. */
function telefono_whatsapp_valido(string $telefono): bool
{
    return preg_match('/^3\d{9}$/', normalizar_telefono($telefono)) === 1;
}

function mensajes_campana_valida(string $campana): bool
{
    return isset(MENSAJES_CAMPANAS[$campana]);
}

/**
 * Todas las clientas con su situación en una campaña:
 *   valido  → tiene celular al que se le puede escribir
 *   permiso → 'si' | 'no' | '' (aún no responde)
 *   envio   → 'enviado' | 'saltado' | '' (todavía no atendida)
 */
function mensajes_estado_clientas(string $campana): array
{
    $st = db()->prepare(
        'SELECT c.id, c.nombre, c.telefono, p.permiso, e.estado, e.momento
           FROM clientes c
           LEFT JOIN mensajes_permisos p ON p.cliente_id = c.id
           LEFT JOIN mensajes_envios   e ON e.cliente_id = c.id AND e.campana = ?
          ORDER BY c.nombre'
    );
    $st->execute([$campana]);

    return array_map(static fn(array $f): array => [
        'id'       => (string) $f['id'],
        'nombre'   => (string) $f['nombre'],
        'telefono' => normalizar_telefono((string) $f['telefono']),
        'valido'   => telefono_whatsapp_valido((string) $f['telefono']),
        'permiso'  => (string) ($f['permiso'] ?? ''),
        'envio'    => (string) ($f['estado'] ?? ''),
        'momento'  => (string) ($f['momento'] ?? ''),
    ], $st->fetchAll());
}

/** Clientas a las que todavía se les puede escribir en esta campaña. */
function mensajes_cola(array $estado): array
{
    return array_values(array_filter(
        $estado,
        static fn(array $c): bool => $c['valido'] && $c['permiso'] !== 'no' && $c['envio'] === ''
    ));
}

/** Conteos para las tarjetas: elegibles, atendidas, pendientes, sin celular, que dijeron no… */
function mensajes_resumen(array $estado): array
{
    $r = ['total' => count($estado), 'sin_celular' => 0, 'dijeron_no' => 0, 'dijeron_si' => 0,
          'elegibles' => 0, 'atendidas' => 0, 'enviadas' => 0, 'pendientes' => 0];
    foreach ($estado as $c) {
        if (!$c['valido']) { $r['sin_celular']++; }
        if ($c['permiso'] === 'no') { $r['dijeron_no']++; }
        if ($c['permiso'] === 'si') { $r['dijeron_si']++; }
        if ($c['valido'] && $c['permiso'] !== 'no') {
            $r['elegibles']++;
            if ($c['envio'] !== '') { $r['atendidas']++; } else { $r['pendientes']++; }
        }
        if ($c['envio'] === 'enviado') { $r['enviadas']++; }
    }
    return $r;
}

/** Marca a una clienta como 'enviado' o 'saltado'. Devuelve false si la clienta no existe o el dato es inválido. */
function mensajes_marcar(string $campana, string $clienteId, string $estado, string $por = ''): bool
{
    if (!mensajes_campana_valida($campana) || !in_array($estado, ['enviado', 'saltado'], true)) {
        return false;
    }
    $existe = db()->prepare('SELECT 1 FROM clientes WHERE id = ?');
    $existe->execute([$clienteId]);
    if (!$existe->fetchColumn()) {
        return false;
    }
    db()->prepare(
        'INSERT INTO mensajes_envios (campana, cliente_id, estado, momento, por)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE estado = VALUES(estado), momento = VALUES(momento), por = VALUES(por)'
    )->execute([$campana, $clienteId, $estado, date('c'), mb_substr($por, 0, 150)]);
    return true;
}

/** Devuelve a la clienta a la cola (se borra su marca de enviada o saltada). */
function mensajes_deshacer(string $campana, string $clienteId): bool
{
    if (!mensajes_campana_valida($campana)) {
        return false;
    }
    $st = db()->prepare('DELETE FROM mensajes_envios WHERE campana = ? AND cliente_id = ?');
    $st->execute([$campana, $clienteId]);
    return $st->rowCount() > 0;
}

/**
 * Guarda lo que respondió la clienta: 'si', 'no' o '' (borra la respuesta).
 * Devuelve false si la clienta no existe o el valor no es válido.
 */
function mensajes_poner_permiso(string $clienteId, string $permiso, string $por = ''): bool
{
    if (!in_array($permiso, ['si', 'no', ''], true)) {
        return false;
    }
    $existe = db()->prepare('SELECT 1 FROM clientes WHERE id = ?');
    $existe->execute([$clienteId]);
    if (!$existe->fetchColumn()) {
        return false;
    }
    if ($permiso === '') {
        db()->prepare('DELETE FROM mensajes_permisos WHERE cliente_id = ?')->execute([$clienteId]);
        return true;
    }
    db()->prepare(
        'INSERT INTO mensajes_permisos (cliente_id, permiso, actualizado, por)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE permiso = VALUES(permiso), actualizado = VALUES(actualizado), por = VALUES(por)'
    )->execute([$clienteId, $permiso, date('c'), mb_substr($por, 0, 150)]);
    return true;
}

/* ---------------------------------------------------------
   Grupos de 5 (por sesión de ingreso)
   ---------------------------------------------------------
   El grupo actual empieza en el momento guardado en la sesión y cuenta los mensajes
   enviados desde entonces. Al cerrar sesión o volver otro día, empieza un grupo nuevo.
   --------------------------------------------------------- */

/** Momento (ISO) en que empezó el grupo actual; si no hay, empieza ahora. */
function mensajes_grupo_desde(string $campana): string
{
    if (empty($_SESSION['mensajes_grupo'][$campana])) {
        $_SESSION['mensajes_grupo'][$campana] = date('c');
    }
    return (string) $_SESSION['mensajes_grupo'][$campana];
}

/** Empieza un grupo nuevo (ahora). */
function mensajes_nuevo_grupo(string $campana): bool
{
    if (!mensajes_campana_valida($campana)) {
        return false;
    }
    $_SESSION['mensajes_grupo'][$campana] = date('c');
    return true;
}

/** Cuántos mensajes se enviaron en esta campaña desde el momento $desde. */
function mensajes_enviadas_desde(string $campana, string $desde): int
{
    $st = db()->prepare("SELECT COUNT(*) FROM mensajes_envios WHERE campana = ? AND estado = 'enviado' AND momento >= ?");
    $st->execute([$campana, $desde]);
    return (int) $st->fetchColumn();
}
