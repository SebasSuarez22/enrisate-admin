<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/api.php';

/* =========================================================
   API del catálogo (solo lectura) · la consulta el bot de WhatsApp en n8n
   =========================================================
   GET api-catalogo.php?q=shampoo rizos&limite=5
   Cabecera obligatoria: X-Api-Key: <API_TOKEN>  (o Authorization: Bearer <API_TOKEN>)

   Parámetros opcionales: q, sku, marca, categoria, limite (1-100).
   La lógica y lo que se muestra de cada producto están en includes/api.php.
   ========================================================= */

header('Content-Type: application/json; charset=utf-8');
// Los precios cambian: ni el navegador ni un proxy guardan copia de la respuesta.
header('Cache-Control: no-store');

function api_responder(int $codigo, array $cuerpo): never
{
    http_response_code($codigo);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    api_responder(405, ['ok' => false, 'error' => 'Método no permitido. Usa GET.']);
}

$esperado = api_token_configurado();
if ($esperado === '') {
    api_responder(503, ['ok' => false, 'error' => 'La API no está activada en este servidor.']);
}
if (!api_autorizado(api_token_de_peticion($_SERVER), $esperado)) {
    api_responder(401, ['ok' => false, 'error' => 'Falta la llave de la API o no es válida.']);
}

$filtros = [
    'q'         => is_string($_GET['q'] ?? null) ? $_GET['q'] : '',
    'sku'       => is_string($_GET['sku'] ?? null) ? $_GET['sku'] : '',
    'marca'     => is_string($_GET['marca'] ?? null) ? $_GET['marca'] : '',
    'categoria' => is_string($_GET['categoria'] ?? null) ? $_GET['categoria'] : '',
    'limite'    => $_GET['limite'] ?? null,
];

api_responder(200, api_respuesta_catalogo(catalogo(), $filtros, api_url_base()));
