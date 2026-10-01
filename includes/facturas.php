<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/catalogo.php';
require_once __DIR__ . '/cuotas.php';
require_once __DIR__ . '/facturas_almacen.php';
require_once __DIR__ . '/formato.php';

/* =========================================================
   Facturas · operaciones de negocio
   =========================================================
   Crear una factura, registrar abonos, aplicar descuentos, agregar
   productos, anular y reactivar; los números del panel; y el enlace
   público del PDF. Cada operación que escribe toma el candado de
   facturas, lee SOLO la factura que toca y la guarda con guardar_factura().

   Este archivo era uno solo con el catálogo, las cuotas, el almacén y el
   formato (1.243 líneas). Ahora cada parte vive en su archivo:
     catalogo.php          productos
     cuotas.php            cálculos de cuotas y abonos (puros)
     facturas_almacen.php  leer y guardar facturas en MySQL
     formato.php           pesos, fechas, teléfono, escape para HTML
   y este los carga, así que las páginas que ya hacían
   require_once includes/facturas.php siguen funcionando igual.
   ========================================================= */

/** Guarda una factura nueva y devuelve su número. */
function crear_factura(array $datos): string
{
    return con_bloqueo_facturas(function () use ($datos) {
        $numero = siguiente_numero();

        $datos['numero'] = $numero;
        $datos['creada'] = date('c');

        guardar_factura($datos, true);

        return $numero;
    });
}

/** Aplica cambios a una factura existente. Devuelve true si la encontró. */
function actualizar_factura(string $numero, array $cambios): bool
{
    return con_bloqueo_facturas(function () use ($numero, $cambios) {
        // Solo esta factura, tal cual está guardada (sin migrar), con el número exacto.
        $f = leer_factura_raw($numero);
        if ($f === null) {
            return false;
        }
        $f = array_merge($f, $cambios);
        // La fila a actualizar es la de $numero: un cambio no puede renombrar la
        // factura (ningún llamador lo intenta).
        $f['numero'] = $numero;
        guardar_factura($f, false);
        return true;
    });
}

/**
 * Registra un abono de monto libre contra una cuota específica de una
 * factura a plazos. Rechaza (no recorta) montos mayores al pendiente,
 * índices de cuota inexistentes, montos de cero o facturas anuladas.
 * Devuelve true si quedó registrado.
 */
function registrar_abono(string $numero, int $indiceCuota, int $monto): bool
{
    return con_bloqueo_facturas(function () use ($numero, $indiceCuota, $monto) {
        $f = factura_por_numero($numero);
        if (!$f || !empty($f['anulada']) || ($f['plan'] ?? '') !== 'cuotas') {
            return false;
        }
        if (!isset($f['cuotas'][$indiceCuota])) {
            return false;
        }
        if ($monto <= 0) {
            return false;
        }

        $pendiente = cuota_pendiente($f['cuotas'][$indiceCuota]);
        if ($monto > $pendiente) {
            return false;
        }

        $f['cuotas'][$indiceCuota]['abonos'][] = [
            'monto'  => $monto,
            'fecha'  => date('c'),
            'tipo'   => 'abono',
            'motivo' => '',
        ];

        return actualizar_factura($numero, [
            'cuotas' => $f['cuotas'],
            'pagado' => total_pagado_cuotas($f),
        ]);
    });
}

/**
 * Perdona (descuenta) un monto de lo pendiente de una cuota específica.
 * Es igual a registrar_abono() en las validaciones — misma factura activa
 * a plazos, mismo tope del monto — pero NO es plata recibida: se marca
 * como 'descuento' para no mezclarse con lo realmente cobrado en efectivo
 * si en algún momento se arma un reporte de caja. Por eso exige un motivo.
 */
function aplicar_descuento_cuota(string $numero, int $indiceCuota, int $monto, string $motivo): bool
{
    return con_bloqueo_facturas(function () use ($numero, $indiceCuota, $monto, $motivo) {
        $motivo = trim($motivo);
        if ($motivo === '') {
            return false;
        }

        $f = factura_por_numero($numero);
        if (!$f || !empty($f['anulada']) || ($f['plan'] ?? '') !== 'cuotas') {
            return false;
        }
        if (!isset($f['cuotas'][$indiceCuota])) {
            return false;
        }
        if ($monto <= 0) {
            return false;
        }

        $pendiente = cuota_pendiente($f['cuotas'][$indiceCuota]);
        if ($monto > $pendiente) {
            return false;
        }

        $f['cuotas'][$indiceCuota]['abonos'][] = [
            'monto'  => $monto,
            'fecha'  => date('c'),
            'tipo'   => 'descuento',
            'motivo' => $motivo,
        ];

        return actualizar_factura($numero, [
            'cuotas' => $f['cuotas'],
            'pagado' => total_pagado_cuotas($f),
        ]);
    });
}

/**
 * Agrega uno o más productos a una factura ya existente — la clienta pidió
 * algo más días después y no hace falta una factura aparte. Lo agregado
 * queda como una cuota pendiente nueva (con su propia fecha de
 * vencimiento), nunca se marca pagada de una: así aparece sola en Cartera
 * para cobrarla, reutilizando WhatsApp/registrar abono/aplicar descuento
 * que ya existen para las demás cuotas — sin ninguna pantalla nueva ahí.
 *
 * Si la factura era "de contado", pasa a ser "a cuotas" (ya no tiene
 * sentido lo contrario con un saldo pendiente): lo que ya se había pagado
 * de contado se deja como una cuota "Recibida" aparte, con su propio
 * abono por ese monto, para no perder el rastro de que esa plata ya
 * había entrado. Las facturas de contado siempre nacen totalmente
 * pagadas (ver facturar.php), así que ese monto es $f['pagado'] tal cual
 * quedó guardado.
 *
 * Rechaza facturas anuladas y listas de ítems vacías o sin ningún
 * producto real del catálogo — igual que facturar.php, los precios se
 * recalculan aquí con producto_por_sku(), nunca se confía en lo que
 * mande el formulario.
 */
function agregar_lineas_factura(string $numero, array $items, string $vence): bool
{
    return con_bloqueo_facturas(function () use ($numero, $items, $vence) {
        $f = factura_por_numero($numero);
        if (!$f || !empty($f['anulada'])) {
            return false;
        }

        $lineasNuevas  = [];
        $subtotalNuevo = 0;
        $costoNuevo    = 0;
        foreach ($items as $it) {
            $p = producto_por_sku((string) ($it['sku'] ?? ''));
            $c = max(1, min(99, (int) ($it['cant'] ?? 1)));
            if (!$p) {
                continue;
            }
            $lineasNuevas[] = [
                'sku'    => $p['sku'],
                'nombre' => $p['nombre'],
                'marca'  => $p['marca'],
                'precio' => $p['precio'],
                'costo'  => $p['costo'],
                'cant'   => $c,
            ];
            $subtotalNuevo += $p['precio'] * $c;
            $costoNuevo    += $p['costo'] * $c;
        }

        if (!$lineasNuevas) {
            return false;
        }

        $vence  = trim($vence) !== '' ? $vence : date('Y-m-d');
        $cuotas = $f['cuotas'] ?? [];

        if (($f['plan'] ?? 'contado') !== 'cuotas') {
            $montoYaPagado = (int) ($f['pagado'] ?? $f['total']);
            if ($montoYaPagado > 0) {
                $fechaPago = (string) ($f['fecha_pago'] ?? $f['creada']);
                $cuotas[]  = [
                    'monto'  => $montoYaPagado,
                    'vence'  => substr($fechaPago, 0, 10),
                    'abonos' => [[
                        'monto'  => $montoYaPagado,
                        'fecha'  => $fechaPago,
                        'tipo'   => 'abono',
                        'motivo' => '',
                    ]],
                ];
            }
        }

        $cuotas[] = ['monto' => $subtotalNuevo, 'vence' => $vence, 'abonos' => []];

        $cambios = [
            'lineas'   => array_merge($f['lineas'], $lineasNuevas),
            'subtotal' => (int) $f['subtotal'] + $subtotalNuevo,
            'total'    => (int) $f['total'] + $subtotalNuevo,
            'costo'    => (int) $f['costo'] + $costoNuevo,
            'margen'   => (int) $f['margen'] + ($subtotalNuevo - $costoNuevo),
            'plan'     => 'cuotas',
            'cuotas'   => $cuotas,
        ];
        $cambios['pagado'] = total_pagado_cuotas(array_merge($f, $cambios));

        return actualizar_factura($numero, $cambios);
    });
}

/**
 * Anula una factura: no se borra, queda marcada como anulada (deja rastro
 * contable y conserva el consecutivo). Sale de todos los cálculos y de la
 * cartera, pero sigue viéndose en el historial. Devuelve true si la halló.
 */
function anular_factura(string $numero, string $motivo = ''): bool
{
    return con_bloqueo_facturas(function () use ($numero, $motivo) {
        $f = factura_por_numero($numero);
        if (!$f || !empty($f['anulada'])) {
            return false;
        }
        return actualizar_factura($numero, [
            'anulada'          => true,
            'anulada_el'       => date('c'),
            'motivo_anulacion' => trim($motivo),
        ]);
    });
}

/** Revierte la anulación de una factura (por si se anuló por error). */
function reactivar_factura(string $numero): bool
{
    return con_bloqueo_facturas(function () use ($numero) {
        $f = factura_por_numero($numero);
        if (!$f || empty($f['anulada'])) {
            return false;
        }
        return actualizar_factura($numero, [
            'anulada'    => false,
            'anulada_el' => null,
        ]);
    });
}

/** Fecha "de la venta" a mostrar: la de pago si es de contado, o la de creación. */
function fecha_factura(array $f): string
{
    return $f['fecha_pago'] ?? $f['creada'];
}

/**
 * Números del resumen del panel: cuánto hay por cobrar, cuánto está vencido
 * y la ganancia de este mes. Es la única fuente de estos cálculos para el
 * dashboard (así no se repiten en varias páginas).
 */
function metricas_panel(): array
{
    $facturas = leer_facturas();
    $mesHoy   = date('Y-m');
    $anioHoy  = date('Y');

    $porCobrar        = 0; // suma de cuotas no pagadas
    $vencido          = 0; // de esas, las que ya se pasaron de fecha
    $cuotasPendientes = 0;
    $cuotasVencidas   = 0;
    $gananciaMes      = 0;
    $gananciaAnio     = 0;
    $ventaAnio        = 0; // total vendido (facturado) este año
    $totalInvertido   = 0; // costo histórico de todo lo vendido

    foreach ($facturas as $f) {
        if (!empty($f['anulada'])) {
            continue; // una factura anulada no cuenta en ningún número
        }

        $totalInvertido += (int) ($f['costo'] ?? 0);

        $fecha = fecha_factura($f);
        if (substr($fecha, 0, 7) === $mesHoy) {
            $gananciaMes += (int) ($f['margen'] ?? 0);
        }
        if (substr($fecha, 0, 4) === $anioHoy) {
            $gananciaAnio += (int) ($f['margen'] ?? 0);
            $ventaAnio    += (int) ($f['total'] ?? 0);
        }

        if (($f['plan'] ?? '') !== 'cuotas') {
            continue;
        }

        foreach ($f['cuotas'] ?? [] as $cuota) {
            $pendiente = cuota_pendiente($cuota);
            if ($pendiente <= 0) {
                continue;
            }
            $porCobrar += $pendiente;
            $cuotasPendientes++;
            if (esta_vencida((string) ($cuota['vence'] ?? ''))) {
                $vencido += $pendiente;
                $cuotasVencidas++;
            }
        }
    }

    return compact(
        'porCobrar', 'vencido', 'cuotasPendientes', 'cuotasVencidas', 'gananciaMes',
        'gananciaAnio', 'ventaAnio', 'totalInvertido'
    );
}

/**
 * Token que autoriza ver/descargar el PDF de una factura sin haber
 * iniciado sesión: el link va dentro del mensaje de WhatsApp, que la
 * clienta abre en su propio celular, no en el panel. No se guarda en
 * ningún lado — se recalcula a partir del número de factura firmado con
 * ADMIN_HASH (ya es secreto y distinto en cada servidor), así nadie
 * puede armar el link de otra factura solo por adivinar el número.
 */
function factura_pdf_token(string $numero): string
{
    return substr(hash_hmac('sha256', $numero, ADMIN_HASH), 0, 24);
}

function factura_pdf_token_valido(string $numero, string $token): bool
{
    return $token !== '' && hash_equals(factura_pdf_token($numero), $token);
}

/**
 * URL pública (sin sesión) del PDF de una factura, lista para pegar en
 * el mensaje de WhatsApp. Arma la URL absoluta con el host real de la
 * visita — Railway pone la app detrás de un proxy HTTPS, por eso se
 * revisa X-Forwarded-Proto igual que en includes/auth.php.
 */
function factura_pdf_url(string $numero): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return ($https ? 'https://' : 'http://') . $host
        . '/factura-pdf.php?n=' . rawurlencode($numero)
        . '&t=' . factura_pdf_token($numero);
}
