<?php
declare(strict_types=1);

require_once __DIR__ . '/formato.php';
require_once __DIR__ . '/facturas.php';
require_once __DIR__ . '/pedidos.php';
require_once __DIR__ . '/clientes.php';

/* =========================================================
   Crear una cuenta de cobro (factura) desde el formulario de facturar.php
   =========================================================
   Antes esto vivía dentro de facturar.php y hacía cuatro cosas seguidas, cada
   una por su lado: crear la factura, agregar a la clienta, descontarle el saldo
   a favor y marcar el pedido como confirmado. Si algo fallaba a la mitad quedaba
   la factura creada con el saldo ya gastado pero el pedido "pendiente": se veía
   otra vez en Pedidos por revisar y se podía facturar dos veces.

   Ahora todo ocurre en UNA transacción de MySQL (o se guarda todo, o nada) y con
   las facturas, las clientas y los pedidos reservados mientras dura: así el saldo
   a favor y el estado del pedido que se leen para calcular la cuenta son los
   mismos que se guardan, y dos personas no pueden gastar el mismo saldo ni
   facturar el mismo pedido a la vez.
   ========================================================= */

/**
 * Valida el formulario, calcula la cuenta en el servidor (los precios del
 * navegador no son de fiar) y la guarda. Devuelve el número de factura.
 *
 * Lanza DomainException con un mensaje listo para mostrarle a quien factura
 * cuando algo del formulario no sirve; RuntimeException si no consiguió turno
 * para guardar (también con mensaje amable). Si lanza cualquier excepción, no
 * quedó guardado nada.
 */
function crear_cuenta_desde_formulario(array $post): string
{
    $cliente  = trim((string) ($post['cliente'] ?? ''));
    $telefono = normalizar_telefono((string) ($post['telefono'] ?? ''));
    $items    = json_decode((string) ($post['items'] ?? '[]'), true);

    if ($cliente === '') {
        throw new DomainException('Escribe el nombre del cliente.');
    }
    if (!is_array($items) || count($items) === 0) {
        throw new DomainException('Agrega al menos un producto a la cuenta.');
    }

    // Se recalcula todo en el servidor: los precios del navegador no son de fiar
    $lineas   = [];
    $subtotal = 0;
    $costo    = 0;

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
            'costo'  => $p['costo'],
            'cant'   => $c,
        ];
        $subtotal += $p['precio'] * $c;
        $costo    += $p['costo'] * $c;
    }

    if (!$lineas) {
        throw new DomainException('Ninguno de los productos existe en el catálogo.');
    }

    // Candados primero y transacción después (ver en_transaccion()): lo que se lee
    // adentro —saldo de la clienta, estado del pedido— ya es lo más reciente.
    return con_bloqueos(['facturas', 'clientes', 'pedidos'], fn() => en_transaccion(
        function () use ($post, $cliente, $telefono, $lineas, $subtotal, $costo): string {
            $descuento = max(0, min($subtotal, (int) ($post['descuento'] ?? 0)));
            $total     = $subtotal - $descuento;

            $plan = ($post['plan'] ?? 'contado') === 'cuotas' ? 'cuotas' : 'contado';

            // Si esta cuenta vino de un pedido del catálogo público, ese pedido tiene
            // que seguir pendiente: si ya lo confirmaron (por ejemplo, con un doble
            // clic) o lo descartaron, facturarlo otra vez duplicaría la cuenta.
            $numeroPedidoOrigen = trim((string) ($post['desde_pedido'] ?? ''));
            if ($numeroPedidoOrigen !== '') {
                $pedido = pedido_por_numero($numeroPedidoOrigen);
                if ($pedido !== null && ($pedido['estado'] ?? '') !== 'pendiente') {
                    throw new DomainException(
                        'Ese pedido ya fue ' . (($pedido['estado'] ?? '') === 'confirmado' ? 'confirmado y facturado' : 'descartado')
                        . '. No se puede facturar otra vez.'
                    );
                }
            }

            // Saldo a favor de la clienta aplicado a esta cuenta (solo clienta
            // existente, y nunca más de lo que tiene ni más que el total).
            $clienteId       = trim((string) ($post['cliente_id'] ?? ''));
            $clienteRec      = $clienteId !== '' ? cliente_por_id($clienteId) : null;
            $saldoDisponible = $clienteRec ? (int) ($clienteRec['saldo_favor'] ?? 0) : 0;
            $saldoAplicado   = max(0, min($saldoDisponible, $total, (int) ($post['saldo_aplicado'] ?? 0)));

            $abono     = 0;
            $cuotas    = [];
            $fechaPago = null;

            if ($plan === 'cuotas' && $total > 1) {
                // Abono en efectivo + saldo a favor son plata ya cubierta contra el
                // precio completo — no son descuento, no tocan $total ni $margen.
                // Solo reducen lo que queda por repartir en cuotas.
                $abono = max(0, min($total - $saldoAplicado, (int) ($post['abono'] ?? 0)));
                $resto = $total - $saldoAplicado - $abono;

                if ($resto <= 1) {
                    $cuotas[] = [
                        'monto'  => max(0, $resto),
                        'vence'  => date('Y-m-d'),
                        'abonos' => [],
                    ];
                } else {
                    $numCuotas    = max(1, min(4, (int) ($post['num_cuotas'] ?? 2)));
                    $montosPost   = is_array($post['cuota_monto'] ?? null) ? $post['cuota_monto'] : [];
                    $vencesPost   = is_array($post['cuota_vence'] ?? null) ? $post['cuota_vence'] : [];
                    $sumaAnterior = 0;

                    for ($i = 0; $i < $numCuotas; $i++) {
                        $vence = trim((string) ($vencesPost[$i] ?? ''));
                        if ($vence === '') {
                            $vence = date('Y-m-d', strtotime('+' . (30 * $i) . ' days'));
                        }

                        if ($i === $numCuotas - 1) {
                            // La última cuota es siempre el resto exacto: nunca se
                            // confía en lo que mande el navegador, para que la suma
                            // cuadre con el total.
                            $monto = $resto - $sumaAnterior;
                        } else {
                            $restantesDespues = $numCuotas - 1 - $i;
                            $monto = (int) ($montosPost[$i] ?? 0);
                            $monto = max(1, min($resto - $sumaAnterior - $restantesDespues, $monto));
                        }

                        $cuotas[] = ['monto' => $monto, 'vence' => $vence, 'abonos' => []];
                        $sumaAnterior += $monto;
                    }
                }
            } else {
                $plan = 'contado';

                $fechaPago = trim((string) ($post['fecha_pago'] ?? ''));
                if ($fechaPago === '') {
                    $fechaPago = date('Y-m-d');
                }
            }

            // Lo ya cubierto: contado = todo el total (parte con saldo, parte en
            // efectivo hoy). Cuotas = saldo aplicado + abono en efectivo.
            $pagado = $plan === 'contado' ? $total : ($saldoAplicado + $abono);

            $numero = crear_factura([
                'cliente'        => $cliente,
                'telefono'       => $telefono,
                'nota'           => trim((string) ($post['nota'] ?? '')),
                'lineas'         => $lineas,
                'subtotal'       => $subtotal,
                'descuento'      => $descuento,
                'total'          => $total,
                'costo'          => $costo,          // interno, nunca se imprime
                'margen'         => $total - $costo, // interno, no lo afecta el abono ni el saldo
                'plan'           => $plan,
                'abono'          => $abono,
                'saldo_aplicado' => $saldoAplicado,
                'cuotas'         => $cuotas,
                'pagado'         => $pagado,
                'fecha_pago'     => $fechaPago,
            ]);

            agregar_cliente_desde_factura($cliente, $telefono);

            // Descuenta de la cuenta de la clienta el saldo que se usó aquí.
            if ($clienteRec && $saldoAplicado > 0) {
                restar_saldo_favor($clienteId, $saldoAplicado);
            }

            // Marca el pedido de origen como confirmado y lo enlaza con la factura.
            if ($numeroPedidoOrigen !== '') {
                marcar_pedido_confirmado($numeroPedidoOrigen, $numero);
            }

            return $numero;
        }
    ));
}
