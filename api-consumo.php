<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/api.php';
require_once __DIR__ . '/includes/api_clientes.php';
require_once __DIR__ . '/includes/catalogo.php';
require_once __DIR__ . '/includes/facturas_almacen.php';

/* =========================================================
   API de consumo (solo lectura) · la consulta el bot de WhatsApp en n8n
   =========================================================
   GET api-consumo.php?dias=30&limite=10
   Cabecera obligatoria: X-Api-Key: <API_TOKEN>  (o Authorization: Bearer <API_TOKEN>)

   Lo que consumen las clientas en conjunto: productos y categorías más
   comprados en los últimos `dias` días (1 a 730, 30 si no se pide) y cada
   cuánto repiten la compra. Sin nombres, teléfonos ni dinero.
   `limite` (1 a 50, 10 si no se pide) recorta los dos rankings.
   La lógica está en includes/api_clientes.php.
   ========================================================= */

api_proteger();

api_responder(200, api_consumo(
    leer_facturas(),
    catalogo(),
    api_consumo_dias($_GET['dias'] ?? null),
    api_consumo_top($_GET['limite'] ?? null),
    date('Y-m-d')
));
