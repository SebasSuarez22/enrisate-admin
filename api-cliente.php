<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/api.php';
require_once __DIR__ . '/includes/api_clientes.php';
require_once __DIR__ . '/includes/facturas_almacen.php';
require_once __DIR__ . '/includes/pedidos.php';

/* =========================================================
   API de una clienta (solo lectura) · la consulta el bot de WhatsApp en n8n
   =========================================================
   GET api-cliente.php?telefono=whatsapp:+573001234567
   Cabecera obligatoria: X-Api-Key: <API_TOKEN>  (o Authorization: Bearer <API_TOKEN>)

   Devuelve lo que esa clienta compra y cada cuánto: historial, producto
   favorito, si ya le toca reponer, lo que debe en cuotas y su saldo a favor.
   El teléfono puede venir en cualquier formato (con +57, espacios o el
   prefijo "whatsapp:" que manda Twilio). Solo se consulta UNA clienta por
   su teléfono exacto: no hay forma de listar a todas.
   La lógica está en includes/api_clientes.php.
   ========================================================= */

api_proteger();

$crudo    = is_string($_GET['telefono'] ?? null) ? $_GET['telefono'] : '';
$telefono = api_telefono($crudo);
if ($telefono === '') {
    api_responder(400, ['ok' => false, 'error' => 'Falta el teléfono de la clienta o no es válido.']);
}

$clientes = leer_clientes();
$clienta  = api_cliente_de_telefono($clientes, $telefono);

$permiso = '';
if ($clienta !== null) {
    $st = db()->prepare('SELECT permiso FROM mensajes_permisos WHERE cliente_id = ?');
    $st->execute([$clienta['id']]);
    $permiso = (string) ($st->fetchColumn() ?: '');
}

api_responder(200, api_resumen_clienta(
    $telefono,
    leer_facturas(),
    $clientes,
    pedidos_pendientes(),
    $permiso,
    date('Y-m-d')
));
