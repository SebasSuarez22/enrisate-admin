<?php
declare(strict_types=1);

/* =========================================================
   Ajuste de una sola vez: limpia el teléfono de las clientas, facturas
   y pedidos que ya estaban guardados ANTES de que normalizar_telefono()
   existiera (algunos quedaron con el "57" adelante). No borra ni cambia
   nada más — solo reescribe el campo "telefono" de cada registro con su
   versión normalizada (10 dígitos, sin indicativo).

   Cómo correrlo (desde la carpeta del proyecto):
       php tools/normalizar_telefonos_existentes.php

   Se puede correr varias veces sin problema: si un teléfono ya está
   normalizado, no cambia.
   ========================================================= */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script solo se puede correr por línea de comandos (CLI), no desde el navegador.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/clientes.php';
require_once __DIR__ . '/../includes/pedidos.php';
require_once __DIR__ . '/../includes/facturas_almacen.php';

function log_linea(string $s): void
{
    echo $s . PHP_EOL;
}

/* --- Clientes --- */
$clientes = leer_clientes();
$cambiados = 0;
foreach ($clientes as &$c) {
    $limpio = normalizar_telefono((string) $c['telefono']);
    if ($limpio !== $c['telefono']) {
        log_linea("Clienta \"{$c['nombre']}\": {$c['telefono']} → {$limpio}");
        $c['telefono'] = $limpio;
        $cambiados++;
    }
}
unset($c);
if ($cambiados > 0) {
    guardar_clientes($clientes);
}
log_linea("Clientas actualizadas: {$cambiados} de " . count($clientes));
log_linea('');

/* --- Facturas --- */
$facturas = leer_facturas_raw();
$cambiadas = 0;
foreach ($facturas as &$f) {
    $limpio = normalizar_telefono((string) ($f['telefono'] ?? ''));
    if ($limpio !== ($f['telefono'] ?? '')) {
        log_linea("Factura {$f['numero']} ({$f['cliente']}): {$f['telefono']} → {$limpio}");
        $f['telefono'] = $limpio;
        $cambiadas++;
    }
}
unset($f);
if ($cambiadas > 0) {
    guardar_facturas($facturas);
}
log_linea("Facturas actualizadas: {$cambiadas} de " . count($facturas));
log_linea('');

/* --- Pedidos --- */
$pedidos = leer_pedidos();
$cambiadosPed = 0;
foreach ($pedidos as &$p) {
    $limpio = normalizar_telefono((string) ($p['telefono'] ?? ''));
    if ($limpio !== ($p['telefono'] ?? '')) {
        log_linea("Pedido {$p['numero']} ({$p['cliente']}): {$p['telefono']} → {$limpio}");
        $p['telefono'] = $limpio;
        $cambiadosPed++;
    }
}
unset($p);
if ($cambiadosPed > 0) {
    guardar_pedidos($pedidos);
}
log_linea("Pedidos actualizados: {$cambiadosPed} de " . count($pedidos));

log_linea('');
log_linea('Listo.');
