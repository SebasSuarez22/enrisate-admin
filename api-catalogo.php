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

api_proteger();

$filtros = [
    'q'         => is_string($_GET['q'] ?? null) ? $_GET['q'] : '',
    'sku'       => is_string($_GET['sku'] ?? null) ? $_GET['sku'] : '',
    'marca'     => is_string($_GET['marca'] ?? null) ? $_GET['marca'] : '',
    'categoria' => is_string($_GET['categoria'] ?? null) ? $_GET['categoria'] : '',
    'limite'    => $_GET['limite'] ?? null,
];

api_responder(200, api_respuesta_catalogo(catalogo(), $filtros, api_url_base()));
