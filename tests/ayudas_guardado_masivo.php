<?php
declare(strict_types=1);

/* =========================================================
   Guardado MASIVO (borrar todo y reinsertar todo) · solo para pruebas
   =========================================================
   Así guardaban antes la app las clientas, los pedidos y las facturas: cada
   cambio borraba la tabla entera y la volvía a escribir. La app ya guarda de a
   una fila (guardar_cliente, guardar_pedido, guardar_factura); estas tres
   funciones se movieron aquí, tal cual, porque las pruebas diferenciales
   (clientes_por_fila_test, pedidos_por_fila_test, facturas_por_fila_test) las
   usan como "la implementación anterior" para demostrar que guardar por fila da
   exactamente lo mismo, y para sembrar datos. Antes también las usaba
   tools/normalizar_telefonos_existentes.php (ya corrida y retirada).
   ========================================================= */

require_once __DIR__ . '/../includes/clientes.php';
require_once __DIR__ . '/../includes/pedidos.php';
require_once __DIR__ . '/../includes/facturas_almacen.php';

/**
 * Reemplaza la lista COMPLETA de clientas. La app ya no lo usa (guarda de a una
 * con guardar_cliente()); vive aquí solo para las pruebas (ver el encabezado de este archivo).
 */
function guardar_clientes(array $clientes): void
{
    en_transaccion(function () use ($clientes): void {
        $pdo = db();
        $pdo->exec('DELETE FROM clientes');
        $st = $pdo->prepare(
            'INSERT INTO clientes (id, nombre, telefono, notas, saldo_favor, creado)
             VALUES (:id, :nombre, :telefono, :notas, :saldo_favor, :creado)'
        );
        foreach ($clientes as $c) {
            $st->execute(cliente_a_parametros($c));
        }
    });
}

/**
 * Reemplaza la lista COMPLETA de pedidos. La app ya no lo usa (guarda de a un pedido
 * con guardar_pedido()); vive aquí solo para las pruebas (ver el encabezado de este archivo).
 */
function guardar_pedidos(array $pedidos): void
{
    en_transaccion(function () use ($pedidos): void {
        $pdo = db();
        // pedido_lineas NO tiene borrado en cascada: se borran a mano primero.
        $pdo->exec('DELETE FROM pedido_lineas');
        $pdo->exec('DELETE FROM pedidos');

        $stPed = $pdo->prepare(
            'INSERT INTO pedidos
                (numero, cliente, telefono, subtotal, total, plan, num_cuotas_preferido,
                 estado, factura_numero, creado, confirmado_el, descartado_el, motivo_descartado)
             VALUES
                (:numero, :cliente, :telefono, :subtotal, :total, :plan, :num_cuotas_preferido,
                 :estado, :factura_numero, :creado, :confirmado_el, :descartado_el, :motivo_descartado)'
        );
        foreach ($pedidos as $p) {
            $stPed->execute(pedido_a_parametros($p));
            insertar_lineas_pedido($pdo, (string) $p['numero'], $p['lineas'] ?? []);
        }
    });
}

/**
 * Reemplaza TODAS las facturas en la base de datos. La app ya no lo usa (guarda de a
 * una con guardar_factura()); vive aquí solo para las pruebas (ver el encabezado de este archivo).
 */
function guardar_facturas(array $f): void
{
    en_transaccion(function () use ($f): void {
        $pdo = db();
        // Los ON DELETE CASCADE de factura_lineas/factura_cuotas/factura_abonos
        // se encargan de limpiar lo dependiente.
        $pdo->exec('DELETE FROM facturas');
        foreach ($f as $factura) {
            insertar_fila_factura($pdo, $factura);
            insertar_detalle_factura($pdo, $factura);
        }
    });
}
