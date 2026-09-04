<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/pedidos.php';

/* =========================================================
   Endpoint público: recibe el carrito armado en catalogo-publico.php
   y lo guarda como pedido pendiente. Sin login, así que:
   - NUNCA se confía en precios/nombres que mande el navegador: todo
     se recalcula aquí contra el catálogo real (mismo principio que
     facturar.php).
   - No crea una factura. Solo deja la propuesta guardada para que
     alguien del negocio la revise en pedidos.php.
   ========================================================= */

header('Content-Type: application/json; charset=utf-8');

function responder(int $codigo, array $cuerpo): void
{
    http_response_code($codigo);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(405, ['ok' => false, 'error' => 'Método no permitido.']);
}

if (pedido_ip_bloqueada()) {
    responder(429, ['ok' => false, 'error' => 'Demasiados pedidos seguidos desde aquí. Espera un rato e intenta de nuevo.']);
}

$crudo = (string) file_get_contents('php://input');
$datos = json_decode($crudo, true);
if (!is_array($datos)) {
    $datos = $_POST; // por si llega como formulario normal en vez de JSON
}

// Campo trampa: invisible para personas, un bot que llena todos los campos
// del formulario lo llena también. Si viene con algo, se ignora la
// solicitud sin dar pistas de por qué (no se guarda nada).
if (trim((string) ($datos['sitio_web'] ?? '')) !== '') {
    responder(200, ['ok' => true, 'numero' => '']);
}

$cliente  = trim((string) ($datos['cliente'] ?? ''));
$telefono = normalizar_telefono((string) ($datos['telefono'] ?? ''));
$items    = is_array($datos['items'] ?? null) ? $datos['items'] : [];

if ($cliente === '' || mb_strlen($cliente) > 120) {
    responder(400, ['ok' => false, 'error' => 'Escribe tu nombre completo.']);
}
if (strlen($telefono) < 7 || strlen($telefono) > 15) {
    responder(400, ['ok' => false, 'error' => 'Escribe un número de WhatsApp válido.']);
}
if (!$items || count($items) > 50) {
    responder(400, ['ok' => false, 'error' => 'Tu carrito está vacío.']);
}

// Se recalcula todo en el servidor: los precios del navegador no son de fiar.
$lineas   = [];
$subtotal = 0;
foreach ($items as $it) {
    $p = producto_por_sku((string) ($it['sku'] ?? ''));
    $c = max(1, min(99, (int) ($it['cant'] ?? 1)));
    if (!$p) {
        continue;
    }
    $lineas[] = [
        'sku'    => $p['sku'],
        'nombre' => $p['nombre'],
        'marca'  => $p['marca'],
        'precio' => $p['precio'],
        'cant'   => $c,
    ];
    $subtotal += $p['precio'] * $c;
}

if (!$lineas) {
    responder(400, ['ok' => false, 'error' => 'Ninguno de los productos existe ya en el catálogo. Recarga la página.']);
}

$plan = ($datos['plan'] ?? 'contado') === 'cuotas' ? 'cuotas' : 'contado';
if ($plan === 'cuotas' && $subtotal < UMBRAL_CUOTAS_PUBLICO) {
    $plan = 'contado'; // no cumple el mínimo: nunca se confía en lo que decida el navegador
}

// Solo se guarda la PREFERENCIA de la clienta (en cuántas cuotas le gustaría
// pagar) — nunca montos ni fechas. Esos se negocian de verdad por WhatsApp
// después del pedido, y Gina los define al confirmar en facturar.php.
$numCuotasPreferido = $plan === 'cuotas'
    ? max(2, min(4, (int) ($datos['num_cuotas'] ?? 2)))
    : null;

$numero = crear_pedido([
    'cliente'              => $cliente,
    'telefono'             => $telefono,
    'lineas'               => $lineas,
    'subtotal'             => $subtotal,
    'total'                => $subtotal,
    'plan'                 => $plan,
    'num_cuotas_preferido' => $numCuotasPreferido,
]);

registrar_pedido_ip();

responder(200, ['ok' => true, 'numero' => $numero, 'plan' => $plan]);
