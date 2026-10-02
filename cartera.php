<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/facturas.php';

requerir_sesion();

$error = '';
$ok    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'La página estuvo abierta demasiado tiempo. Recárgala e intenta de nuevo.';
    } elseif (($_POST['accion'] ?? '') === 'anular') {
        if (anular_factura((string) ($_POST['numero'] ?? ''), (string) ($_POST['motivo'] ?? ''))) {
            header('Location: cartera.php?ok=anulada');
            exit;
        }
        $error = 'No pude anular esa factura.';
    } elseif (($_POST['accion'] ?? '') === 'reactivar') {
        if (reactivar_factura((string) ($_POST['numero'] ?? ''))) {
            header('Location: cartera.php?ok=reactivada');
            exit;
        }
        $error = 'No pude reactivar esa factura.';
    } elseif (($_POST['accion'] ?? '') === 'descuento') {
        $numero = (string) ($_POST['numero'] ?? '');
        $idx    = (int) ($_POST['cuota_idx'] ?? -1);
        $monto  = (int) ($_POST['monto'] ?? 0);
        $motivo = (string) ($_POST['motivo'] ?? '');
        if (aplicar_descuento_cuota($numero, $idx, $monto, $motivo)) {
            header('Location: cartera.php?ok=descuento&numero=' . urlencode($numero) . '&cuota=' . $idx . '&monto=' . $monto);
            exit;
        }
        $error = trim($motivo) === ''
            ? 'Escribe el motivo del descuento.'
            : 'No pude aplicar ese descuento. Revisa que el monto no supere lo pendiente.';
    } else {
        $numero = (string) ($_POST['numero'] ?? '');
        $idx    = (int) ($_POST['cuota_idx'] ?? -1);
        $monto  = (int) ($_POST['monto'] ?? 0);
        if (registrar_abono($numero, $idx, $monto)) {
            header('Location: cartera.php?ok=abono&numero=' . urlencode($numero) . '&cuota=' . $idx . '&monto=' . $monto);
            exit;
        }
        $error = 'No pude registrar ese abono. Revisa que el monto no supere lo pendiente.';
    }
}

$waAbono     = null;
$waDescuento = null;

if (($_GET['ok'] ?? '') === 'abono') {
    $ok     = 'Abono registrado.';
    $fNum   = (string) ($_GET['numero'] ?? '');
    $idx    = (int) ($_GET['cuota'] ?? -1);
    $monto  = (int) ($_GET['monto'] ?? 0);
    $fAbono = $fNum !== '' ? factura_por_numero($fNum) : null;

    if ($fAbono && $fAbono['telefono'] !== '' && isset($fAbono['cuotas'][$idx]) && $monto > 0) {
        $cuotaAbono = $fAbono['cuotas'][$idx];
        $pendiente  = cuota_pendiente($cuotaAbono);

        $msg  = "Hola " . $fAbono['cliente'] . " 💛\n";
        $msg .= "Recibimos tu abono de " . pesos($monto) . " (" . fecha_larga(date('Y-m-d')) . ").\n";
        $msg .= "Corresponde a la cuota de " . pesos((int) $cuotaAbono['monto'])
              . " con vencimiento " . fecha_larga($cuotaAbono['vence']) . ".\n";
        if ($pendiente > 0) {
            $msg .= "Saldo pendiente de esa cuota: " . pesos($pendiente) . "\n";
        } elseif (factura_totalmente_pagada($fAbono)) {
            $msg .= "¡Con esto completaste tu compra! Muchas gracias, eres un cliente muy valioso para nosotros. 💛\n";
        } else {
            $msg .= "Con esto, esa cuota queda completa. ¡Gracias!\n";
        }

        $waAbono = enlace_whatsapp($fAbono['telefono'], $msg);
    }
} elseif (($_GET['ok'] ?? '') === 'descuento') {
    $ok       = 'Descuento aplicado.';
    $fNum     = (string) ($_GET['numero'] ?? '');
    $idx      = (int) ($_GET['cuota'] ?? -1);
    $monto    = (int) ($_GET['monto'] ?? 0);
    $fDescto  = $fNum !== '' ? factura_por_numero($fNum) : null;

    if ($fDescto && $fDescto['telefono'] !== '' && isset($fDescto['cuotas'][$idx]) && $monto > 0) {
        $cuotaDescto = $fDescto['cuotas'][$idx];
        $pendiente   = cuota_pendiente($cuotaDescto);
        $rotuloDescto = rotulo_cuota($idx, count($fDescto['cuotas']));

        $msg  = "Hola " . $fDescto['cliente'] . " 💛\n";
        $msg .= "Te aplicamos un descuento de " . pesos($monto) . " a tu " . lcfirst($rotuloDescto) . ".\n";
        $msg .= $pendiente > 0
            ? "Saldo pendiente de esa cuota: " . pesos($pendiente) . "\n"
            : "Con esto, esa cuota queda al día. ¡Gracias por tu confianza!\n";

        $waDescuento = enlace_whatsapp($fDescto['telefono'], $msg);
    }
} elseif (($_GET['ok'] ?? '') === 'anulada') {
    $ok = 'Factura anulada. Ya no cuenta en los reportes ni en la cartera.';
} elseif (($_GET['ok'] ?? '') === 'reactivada') {
    $ok = 'Factura reactivada. Vuelve a contar en los reportes y la cartera.';
}

$q = trim((string) ($_GET['q'] ?? ''));

$todas = leer_facturas();
usort($todas, fn($a, $b) => strcmp($b['creada'], $a['creada']));

$pendientes = [];
foreach ($todas as $f) {
    if (!empty($f['anulada']) || $f['plan'] !== 'cuotas') {
        continue;
    }
    foreach ($f['cuotas'] as $idx => $cuota) {
        $pendiente = cuota_pendiente($cuota);
        if ($pendiente <= 0) {
            continue;
        }
        $pendientes[] = [
            'factura'   => $f,
            'idx'       => $idx,
            'rotulo'    => rotulo_cuota($idx, count($f['cuotas'])),
            'pactado'   => (int) $cuota['monto'],
            'pendiente' => $pendiente,
            'vence'     => $cuota['vence'],
        ];
    }
}
usort($pendientes, fn($a, $b) => strcmp($a['vence'], $b['vence']));

$limiteRecordatorio = date('Y-m-d', strtotime('+5 days'));
$recordatorios = array_values(array_filter($pendientes, fn($p) =>
    !esta_vencida($p['vence']) && $p['vence'] <= $limiteRecordatorio
));

$historial = $todas;
if ($q !== '') {
    $historial = array_filter($todas, fn($f) =>
        stripos($f['cliente'], $q) !== false || stripos($f['numero'], $q) !== false);
}

$hoy     = date('Y-m-d');
$mesHoy  = date('Y-m');
$anioHoy = date('Y');

$gananciaHoy    = 0;
$gananciaMes    = 0;
$gananciaAnio   = 0;
$totalVendido   = 0;
$totalInvertido = 0;

$porSku  = []; // sku => ['sku','nombre','marca','cant','total','costo']
$cantHoy = []; // sku => unidades vendidas hoy
$cantMes = []; // sku => unidades vendidas este mes
$numVendidas = 0; // facturas que sí cuentan (sin anular)

foreach ($todas as $f) {
    if (!empty($f['anulada'])) {
        continue; // una factura anulada no entra en ningún reporte
    }

    $fecha = substr(fecha_factura($f), 0, 10);
    $mes   = substr($fecha, 0, 7);
    $anio  = substr($fecha, 0, 4);

    $numVendidas++;
    $totalVendido   += $f['total'];
    $totalInvertido += $f['costo'];

    if ($fecha === $hoy)    $gananciaHoy  += $f['margen'];
    if ($mes === $mesHoy)   $gananciaMes  += $f['margen'];
    if ($anio === $anioHoy) $gananciaAnio += $f['margen'];

    // El descuento se reparte proporcionalmente entre las líneas, para que
    // la suma del detalle por SKU cuadre con el total real de la factura.
    $factorDescuento = $f['subtotal'] > 0 ? $f['total'] / $f['subtotal'] : 1;

    foreach ($f['lineas'] as $l) {
        $sku = $l['sku'];
        if (!isset($porSku[$sku])) {
            $porSku[$sku] = [
                'sku'    => $sku,
                'nombre' => $l['nombre'],
                'marca'  => $l['marca'],
                'cant'   => 0,
                'total'  => 0,
                'costo'  => 0,
            ];
        }
        $porSku[$sku]['cant']  += $l['cant'];
        $porSku[$sku]['total'] += $l['precio'] * $l['cant'] * $factorDescuento;
        $porSku[$sku]['costo'] += $l['costo']  * $l['cant'];

        if ($fecha === $hoy) {
            $cantHoy[$sku] = ($cantHoy[$sku] ?? 0) + $l['cant'];
        }
        if ($mes === $mesHoy) {
            $cantMes[$sku] = ($cantMes[$sku] ?? 0) + $l['cant'];
        }
    }
}

arsort($cantHoy);
arsort($cantMes);
$masVendidoHoy = $cantHoy ? array_key_first($cantHoy) : null;
$masVendidoMes = $cantMes ? array_key_first($cantMes) : null;

$lista = array_values($porSku);
foreach ($lista as &$p) {
    $p['margen'] = $p['total'] - $p['costo'];
}
unset($p);
usort($lista, fn($a, $b) => $b['total'] <=> $a['total']);

$top      = array_slice($lista, 0, 12);
$maxTotal = $top ? $top[0]['total'] : 0;

$token = csrf_token();

$tituloPagina = 'Cartera y reportes';
$barraVolver  = true;
require __DIR__ . '/includes/cabecera.php';
?>

<main class="lienzo ancho cartera">
  <h1 class="saludo">Cartera y reportes</h1>
  <p class="subsaludo">Cuotas pendientes, historial de facturas y cómo va la ganancia y las ventas.</p>

  <?php if ($error !== ''): ?>
    <p class="aviso" role="alert"><?= e($error) ?></p>
  <?php endif; ?>
  <?php if ($ok !== ''): ?>
    <p class="aviso neutro"><?= e($ok) ?></p>
  <?php endif; ?>
  <?php if ($waAbono): ?>
    <p class="aviso neutro">
      <a class="boton mini verde" href="<?= e($waAbono) ?>" target="_blank" rel="noopener">Enviar comprobante por WhatsApp</a>
    </p>
  <?php endif; ?>
  <?php if ($waDescuento): ?>
    <p class="aviso neutro">
      <a class="boton mini verde" href="<?= e($waDescuento) ?>" target="_blank" rel="noopener">Avisar el descuento por WhatsApp</a>
    </p>
  <?php endif; ?>

  <?php if ($recordatorios): ?>
    <section class="panel">
      <h2 class="titulo-panel">Recordatorios <span class="titulo-nota">cuotas que vencen en los próximos 5 días</span></h2>
      <div class="alertas">
        <?php foreach ($recordatorios as $r): $f = $r['factura'];
          $msgRecordatorio = "Hola " . $f['cliente'] . " 💛\n"
              . "Te recordamos que tu " . lcfirst($r['rotulo']) . " de " . pesos($r['pendiente'])
              . " vence el " . fecha_larga($r['vence']) . ".\n"
              . "¡Cualquier cosa, aquí estamos!";
          $waRecordatorio = $f['telefono'] !== '' ? enlace_whatsapp($f['telefono'], $msgRecordatorio) : null;
        ?>
          <div class="alerta">
            <span class="alerta-punto" aria-hidden="true"></span>
            <span class="alerta-texto">
              <strong><?= e($f['cliente']) ?></strong>
              <a class="alerta-factura" href="factura.php?n=<?= e($f['numero']) ?>">#<?= e($f['numero']) ?></a>
              <span class="alerta-detalle"><?= e($r['rotulo']) ?> de <?= pesos($r['pendiente']) ?> · vence <?= e(fecha_larga($r['vence'])) ?></span>
            </span>
            <?php if ($waRecordatorio): ?>
              <a class="boton-icono fantasma" href="<?= e($waRecordatorio) ?>" target="_blank" rel="noopener"
                 title="Recordar por WhatsApp" aria-label="Recordar por WhatsApp a <?= e($f['cliente']) ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2C6.49 2 2 6.49 2 12c0 1.87.5 3.62 1.38 5.13L2 22l4.99-1.31A9.94 9.94 0 0 0 12 22c5.51 0 10-4.49 10-10S17.51 2 12 2Zm5.2 14.2c-.22.62-1.28 1.2-1.77 1.24-.45.05-.98.24-3.29-.7-2.77-1.13-4.53-3.92-4.66-4.1-.14-.18-1.1-1.47-1.1-2.8 0-1.34.7-1.99.95-2.26.24-.27.53-.34.7-.34h.5c.16 0 .38-.03.58.44.22.51.75 1.77.82 1.9.06.13.1.28.02.46-.08.18-.13.29-.25.44-.13.16-.27.35-.38.47-.13.13-.27.28-.11.55.16.27.7 1.16 1.51 1.87 1.04.93 1.92 1.22 2.19 1.36.27.14.43.12.6-.07.16-.19.68-.79.86-1.06.18-.27.36-.22.6-.13.24.09 1.55.73 1.82.86.27.13.45.2.51.31.07.12.07.68-.15 1.36Z"/></svg>
              </a>
            <?php else: ?>
              <span class="sku">Sin teléfono</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <section class="panel">
    <div class="panel-header">
      <div class="panel-titulo">
        <h2 class="titulo-panel">Cartera pendiente</h2>
        <?php if ($pendientes):
          $resVencido = 0; $resTotal = 0;
          foreach ($pendientes as $rp) {
              $resTotal += $rp['pendiente'];
              if (esta_vencida($rp['vence'])) { $resVencido += $rp['pendiente']; }
          }
        ?>
          <!-- Solo en escritorio (≥1100px): el resumen de lo que hay por cobrar. -->
          <p class="cartera-resumen">
            <span><b><?= count($pendientes) ?></b> <?= count($pendientes) === 1 ? 'cuota' : 'cuotas' ?></span>
            <span class="vencido"><b><?= pesos($resVencido) ?></b> vencido</span>
            <span><b><?= pesos($resTotal) ?></b> por cobrar</span>
          </p>
        <?php endif; ?>
      </div>
      <a class="accion-icono" href="cartera-excel.php" title="Descargar Excel: vencidas y pendientes">
        <span class="accion-icono-circulo">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15V3"/><path d="M3 15v4a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-4"/><path d="m7 10 5 5 5-5"/></svg>
        </span>
        <span class="accion-icono-nombre">Excel</span>
      </a>
    </div>
    <?php if (!$pendientes): ?>
      <p class="subsaludo">No hay cuotas pendientes por cobrar.</p>
    <?php else: ?>
      <?php
      // WhatsApp de mora + registrar abono + aplicar descuento se ven
      // igual en la fila de tabla (escritorio) y en la tarjeta de
      // celular. $sufijo evita que el id de la fila oculta del
      // descuento se repita entre las dos versiones — btn-mostrar-
      // descuento la busca por id (ver el <script> al final de este
      // archivo), y dos elementos con el mismo id rompería el toggle.
      $accionesCartera = function (array $p, string $sufijo) use ($token): array {
          $f = $p['factura'];
          $vencida = esta_vencida($p['vence']);
          $idDescuento = 'desc-' . $f['numero'] . '-' . $p['idx'] . $sufijo;

          // WhatsApp: el de mora si la cuota ya venció, el recordatorio si aún no. El botón de la
          // fila clásica (celular y tablet) usa solo el de mora; el panel de escritorio usa el que aplique.
          $waMora = null;
          $waPanel = null;
          if ($f['telefono'] !== '') {
              if ($vencida) {
                  $msgMora = "Hola " . $f['cliente'] . " 💛\n"
                      . "Notamos que tu " . lcfirst($p['rotulo']) . " de " . pesos($p['pendiente'])
                      . " venció el " . fecha_larga($p['vence']) . " y sigue pendiente.\n"
                      . "¿Nos ayudas poniéndote al día?";
                  $waMora = $waPanel = enlace_whatsapp($f['telefono'], $msgMora);
              } else {
                  $msgRecordatorio = "Hola " . $f['cliente'] . " 💛\n"
                      . "Te recordamos que tu " . lcfirst($p['rotulo']) . " de " . pesos($p['pendiente'])
                      . " vence el " . fecha_larga($p['vence']) . ".\n"
                      . "¡Cualquier cosa, aquí estamos!";
                  $waPanel = enlace_whatsapp($f['telefono'], $msgRecordatorio);
              }
          }
          ob_start();
          ?>
          <?php if ($vencida && $f['telefono'] !== ''): ?>
            <a class="boton-icono fantasma peligro" href="<?= e($waMora) ?>" target="_blank" rel="noopener"
               title="Escribir por WhatsApp" aria-label="Escribir por WhatsApp a <?= e($f['cliente']) ?> por la mora">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2C6.49 2 2 6.49 2 12c0 1.87.5 3.62 1.38 5.13L2 22l4.99-1.31A9.94 9.94 0 0 0 12 22c5.51 0 10-4.49 10-10S17.51 2 12 2Zm5.2 14.2c-.22.62-1.28 1.2-1.77 1.24-.45.05-.98.24-3.29-.7-2.77-1.13-4.53-3.92-4.66-4.1-.14-.18-1.1-1.47-1.1-2.8 0-1.34.7-1.99.95-2.26.24-.27.53-.34.7-.34h.5c.16 0 .38-.03.58.44.22.51.75 1.77.82 1.9.06.13.1.28.02.46-.08.18-.13.29-.25.44-.13.16-.27.35-.38.47-.13.13-.27.28-.11.55.16.27.7 1.16 1.51 1.87 1.04.93 1.92 1.22 2.19 1.36.27.14.43.12.6-.07.16-.19.68-.79.86-1.06.18-.27.36-.22.6-.13.24.09 1.55.73 1.82.86.27.13.45.2.51.31.07.12.07.68-.15 1.36Z"/></svg>
            </a>
          <?php endif; ?>
          <form method="post" action="cartera.php" class="en-linea form-abono">
            <input type="hidden" name="csrf" value="<?= e($token) ?>">
            <input type="hidden" name="numero" value="<?= e($f['numero']) ?>">
            <input type="hidden" name="cuota_idx" value="<?= (int) $p['idx'] ?>">
            <input type="number" name="monto" min="1" max="<?= (int) $p['pendiente'] ?>" step="1"
                   value="<?= (int) $p['pendiente'] ?>" aria-label="Monto del abono">
            <button type="submit" class="boton-icono" title="Registrar abono" aria-label="Registrar abono">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12.5l4.5 4.5L20 6"/></svg>
            </button>
          </form>
          <button type="button" class="boton-icono fantasma btn-mostrar-descuento" data-id="<?= e($idDescuento) ?>"
                  title="Aplicar descuento" aria-label="Aplicar descuento">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 19 19 5M7.5 8.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm9 9a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z"/></svg>
          </button>
          <?php
          $acciones = ob_get_clean();

          ob_start();
          ?>
          <form method="post" action="cartera.php" class="panel-descuento form-descuento" id="form-<?= e($idDescuento) ?>">
            <input type="hidden" name="csrf" value="<?= e($token) ?>">
            <input type="hidden" name="accion" value="descuento">
            <input type="hidden" name="numero" value="<?= e($f['numero']) ?>">
            <input type="hidden" name="cuota_idx" value="<?= (int) $p['idx'] ?>">
            <input type="number" name="monto" min="1" max="<?= (int) $p['pendiente'] ?>" step="1"
                   value="<?= (int) $p['pendiente'] ?>" aria-label="Monto del descuento">
            <input type="text" name="motivo" placeholder="Motivo del descuento" maxlength="255" required aria-label="Motivo del descuento">
            <button type="submit" class="boton mini">Confirmar descuento</button>
            <button type="button" class="salir como-boton cerrar-descuento" data-id="<?= e($idDescuento) ?>">Cancelar</button>
          </form>
          <?php
          return ['id' => $idDescuento, 'acciones' => $acciones, 'form_descuento' => ob_get_clean(), 'wa' => $waPanel, 'mora' => $vencida && $waMora !== null];
      };
      ?>
      <div class="tabla-scroll oculta-en-movil">
      <table class="lineas tabla-cartera-pendiente">
        <thead>
          <tr>
            <th>Cliente</th>
            <th>No. factura</th>
            <th>Cuota</th>
            <th>Vence</th>
            <th class="num">Monto pendiente</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pendientes as $p): $f = $p['factura']; $vencida = esta_vencida($p['vence']); $abonado = $p['pactado'] - $p['pendiente']; $ac = $accionesCartera($p, '-t'); ?>
            <tr>
              <td>
                <?= e($f['cliente']) ?>
                <?php if ($f['telefono'] !== ''): ?><span class="sku"><?= e($f['telefono']) ?></span><?php endif; ?>
              </td>
              <td><a class="salir" href="factura.php?n=<?= e($f['numero']) ?>"><?= e($f['numero']) ?></a></td>
              <td><?= e($p['rotulo']) ?></td>
              <td>
                <?= e(fecha_larga($p['vence'])) ?>
                <?php if ($vencida): ?><span class="etiqueta vencida">Vencida</span><?php endif; ?>
              </td>
              <td class="num">
                <?= pesos($p['pendiente']) ?>
                <?php if ($abonado > 0): ?><span class="sku">de <?= pesos($p['pactado']) ?></span><?php endif; ?>
              </td>
              <td class="num celda-acciones">
                <!-- Celular y tablet (<1100px): los controles de siempre. -->
                <div class="acciones-cartera"><?= $ac['acciones'] ?></div>
                <!-- Escritorio (≥1100px): un solo botón que abre el panel de abajo. -->
                <button type="button" class="btn-cobrar" aria-expanded="false" aria-controls="cobro-<?= e($ac['id']) ?>" data-cobro="cobro-<?= e($ac['id']) ?>">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                  <span>Cobrar</span>
                </button>
              </td>
            </tr>
            <tr class="fila-descuento" id="fila-<?= e($ac['id']) ?>" hidden>
              <td colspan="6"><?= $ac['form_descuento'] ?></td>
            </tr>
            <?php
              $pend = (int) $p['pendiente'];
              $mitad = (int) round($pend / 2);
              $idCobro = 'cobro-' . $ac['id'];
            ?>
            <tr class="fila-cobro" id="<?= e($idCobro) ?>" hidden>
              <td colspan="6">
                <div class="cobro" role="group" aria-label="Cobrar a <?= e($f['cliente']) ?>">
                  <form method="post" action="cartera.php" class="cobro-form cobro-abono" data-pendiente="<?= $pend ?>">
                    <input type="hidden" name="csrf" value="<?= e($token) ?>">
                    <input type="hidden" name="numero" value="<?= e($f['numero']) ?>">
                    <input type="hidden" name="cuota_idx" value="<?= (int) $p['idx'] ?>">
                    <input type="hidden" name="monto" value="<?= $pend ?>">
                    <div class="cobro-datos">
                      <h4 class="cobro-titulo">Registrar abono <span>· <?= e($f['cliente']) ?> · <?= e($p['rotulo']) ?></span></h4>
                      <div class="cobro-campos">
                        <label class="cobro-monto">
                          <span class="cobro-peso" aria-hidden="true">$</span>
                          <input type="text" class="cobro-input" inputmode="numeric" autocomplete="off"
                                 value="<?= e(number_format($pend, 0, ',', '.')) ?>" aria-label="Monto del abono"
                                 aria-describedby="<?= e($idCobro) ?>-error">
                        </label>
                        <button type="button" class="cobro-chip" data-valor="<?= $pend ?>" aria-pressed="true">Total <?= pesos($pend) ?></button>
                        <?php if ($pend >= 2): ?>
                          <button type="button" class="cobro-chip" data-valor="<?= $mitad ?>" aria-pressed="false">Mitad <?= pesos($mitad) ?></button>
                        <?php endif; ?>
                      </div>
                      <p class="cobro-error" id="<?= e($idCobro) ?>-error" role="alert"></p>
                    </div>
                    <button type="submit" class="cobro-confirmar">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12.5l4.5 4.5L20 6"/></svg>
                      Confirmar abono
                    </button>
                    <div class="cobro-sec">
                      <?php if ($ac['wa']): ?>
                        <a class="cobro-enlace" href="<?= e($ac['wa']) ?>" target="_blank" rel="noopener">
                          <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2C6.49 2 2 6.49 2 12c0 1.87.5 3.62 1.38 5.13L2 22l4.99-1.31A9.94 9.94 0 0 0 12 22c5.51 0 10-4.49 10-10S17.51 2 12 2Zm5.2 14.2c-.22.62-1.28 1.2-1.77 1.24-.45.05-.98.24-3.29-.7-2.77-1.13-4.53-3.92-4.66-4.1-.14-.18-1.1-1.47-1.1-2.8 0-1.34.7-1.99.95-2.26.24-.27.53-.34.7-.34h.5c.16 0 .38-.03.58.44.22.51.75 1.77.82 1.9.06.13.1.28.02.46-.08.18-.13.29-.25.44-.13.16-.27.35-.38.47-.13.13-.27.28-.11.55.16.27.7 1.16 1.51 1.87 1.04.93 1.92 1.22 2.19 1.36.27.14.43.12.6-.07.16-.19.68-.79.86-1.06.18-.27.36-.22.6-.13.24.09 1.55.73 1.82.86.27.13.45.2.51.31.07.12.07.68-.15 1.36Z"/></svg>
                          <?= $ac['mora'] ? 'Escribir por WhatsApp (mora)' : 'Recordar por WhatsApp' ?>
                        </a>
                      <?php endif; ?>
                      <button type="button" class="cobro-enlace" data-cobro-descuento>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M5 19 19 5M7.5 8.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm9 9a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z"/></svg>
                        Aplicar descuento
                      </button>
                    </div>
                  </form>
                  <form method="post" action="cartera.php" class="cobro-form cobro-descuento" data-pendiente="<?= $pend ?>" hidden>
                    <input type="hidden" name="csrf" value="<?= e($token) ?>">
                    <input type="hidden" name="accion" value="descuento">
                    <input type="hidden" name="numero" value="<?= e($f['numero']) ?>">
                    <input type="hidden" name="cuota_idx" value="<?= (int) $p['idx'] ?>">
                    <input type="hidden" name="monto" value="<?= $pend ?>">
                    <div class="cobro-datos">
                      <h4 class="cobro-titulo">Aplicar descuento <span>· <?= e($f['cliente']) ?> · <?= e($p['rotulo']) ?></span></h4>
                      <div class="cobro-campos">
                        <label class="cobro-monto">
                          <span class="cobro-peso" aria-hidden="true">$</span>
                          <input type="text" class="cobro-input" inputmode="numeric" autocomplete="off"
                                 value="<?= e(number_format($pend, 0, ',', '.')) ?>" aria-label="Monto del descuento"
                                 aria-describedby="<?= e($idCobro) ?>-error-d">
                        </label>
                        <input type="text" class="cobro-motivo" name="motivo" maxlength="255" placeholder="Motivo del descuento" aria-label="Motivo del descuento" autocomplete="off">
                      </div>
                      <p class="cobro-error" id="<?= e($idCobro) ?>-error-d" role="alert"></p>
                    </div>
                    <button type="submit" class="cobro-confirmar">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12.5l4.5 4.5L20 6"/></svg>
                      Confirmar descuento
                    </button>
                    <div class="cobro-sec">
                      <button type="button" class="cobro-enlace" data-cobro-volver>← Volver al abono</button>
                    </div>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <!-- Solo en celular: cada cuota es una tarjeta que se despliega
           al tocarla, en vez de una fila de tabla angosta con 6
           columnas imposibles de leer. -->
      <div class="filas-esencial">
        <?php foreach ($pendientes as $p): $f = $p['factura']; $vencida = esta_vencida($p['vence']); $abonado = $p['pactado'] - $p['pendiente']; $ac = $accionesCartera($p, '-c'); ?>
          <details class="fila-lista">
            <summary>
              <span class="fila-lista-resumen">
                <span class="fila-lista-titulo"><?= e($f['cliente']) ?></span>
                <span class="fila-lista-pista"><?= pesos($p['pendiente']) ?><?= $vencida ? ' · Vencida' : '' ?></span>
              </span>
              <svg class="fila-lista-flecha" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </summary>
            <div class="fila-lista-detalle">
              <?php if ($f['telefono'] !== ''): ?>
                <div class="campo-detalle"><strong>Teléfono</strong><?= e($f['telefono']) ?></div>
              <?php endif; ?>
              <div class="campo-detalle"><strong>No. factura</strong><a class="salir" href="factura.php?n=<?= e($f['numero']) ?>"><?= e($f['numero']) ?></a></div>
              <div class="campo-detalle"><strong>Cuota</strong><?= e($p['rotulo']) ?></div>
              <div class="campo-detalle"><strong>Vence</strong><?= e(fecha_larga($p['vence'])) ?></div>
              <?php if ($abonado > 0): ?>
                <div class="campo-detalle"><strong>Ya abonado</strong><?= pesos($abonado) ?> de <?= pesos($p['pactado']) ?></div>
              <?php endif; ?>
              <div class="acciones-fila"><?= $ac['acciones'] ?></div>
              <div class="fila-descuento" id="fila-<?= e($ac['id']) ?>" hidden><?= $ac['form_descuento'] ?></div>
            </div>
          </details>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="panel" id="historial">
    <h2 class="titulo-panel">Historial de facturas</h2>

    <form method="get" action="cartera.php#historial" class="form-busqueda">
      <div class="campo apretado">
        <input type="search" name="q" value="<?= e($q) ?>" id="buscar-historial" placeholder="Buscar por cliente o número de factura…">
      </div>
      <button type="submit" class="boton mini">Buscar</button>
      <?php if ($q !== ''): ?><a class="salir" href="cartera.php#historial">Limpiar</a><?php endif; ?>
    </form>

    <?php if (!$historial): ?>
      <p class="subsaludo">No hay facturas que coincidan con la búsqueda.</p>
    <?php else: ?>
      <?php
      // Estado (Pagada/Vencida/Pendiente/Contado/Anulada) y las acciones
      // (Ver + Anular/Reactivar) se ven igual en la fila de tabla y en
      // la tarjeta de celular; se calculan una sola vez por factura.
      $estadoFactura = function (array $f): array {
          if (!empty($f['anulada'])) {
              return ['anulada', 'Anulada'];
          }
          if ($f['plan'] !== 'cuotas') {
              return ['', 'Contado'];
          }
          $todasPagadas  = true;
          $algunaVencida = false;
          foreach ($f['cuotas'] as $cuota) {
              if (!cuota_esta_pagada($cuota)) {
                  $todasPagadas = false;
                  if (esta_vencida($cuota['vence'])) {
                      $algunaVencida = true;
                  }
              }
          }
          if ($todasPagadas) {
              return ['pagada', 'Pagada'];
          }
          return $algunaVencida ? ['vencida', 'Vencida'] : ['pendiente', 'Pendiente'];
      };
      $accionesFactura = function (array $f) use ($token): string {
          $anulada = !empty($f['anulada']);
          ob_start();
          ?>
          <a class="salir" href="factura.php?n=<?= e($f['numero']) ?>">Ver</a>
          <?php if ($anulada): ?>
            <form method="post" action="cartera.php" class="en-linea">
              <input type="hidden" name="csrf" value="<?= e($token) ?>">
              <input type="hidden" name="accion" value="reactivar">
              <input type="hidden" name="numero" value="<?= e($f['numero']) ?>">
              <button type="submit" class="salir como-boton">Reactivar</button>
            </form>
          <?php else: ?>
            <form method="post" action="cartera.php" class="en-linea"
                  onsubmit="return confirm('¿Anular la factura <?= e($f['numero']) ?> de <?= e($f['cliente']) ?>? Dejará de contar en los reportes y en la cartera, pero quedará registrada como anulada.');">
              <input type="hidden" name="csrf" value="<?= e($token) ?>">
              <input type="hidden" name="accion" value="anular">
              <input type="hidden" name="numero" value="<?= e($f['numero']) ?>">
              <button type="submit" class="salir como-boton peligro">Anular</button>
            </form>
          <?php endif; ?>
          <?php
          return ob_get_clean();
      };
      ?>
      <div class="tabla-scroll oculta-en-movil">
      <table class="lineas tabla-historial-facturas">
        <thead>
          <tr>
            <th>No.</th>
            <th>Fecha</th>
            <th>Cliente</th>
            <th class="num">Total</th>
            <th class="num">Ganancia</th>
            <th>Estado</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($historial as $f):
            $anulada = !empty($f['anulada']);
            [$estadoClase, $estadoTexto] = $estadoFactura($f);
          ?>
            <tr<?= $anulada ? ' class="fila-anulada"' : '' ?>>
              <td><?= e($f['numero']) ?></td>
              <td><?= e(fecha_larga(fecha_factura($f))) ?></td>
              <td><?= e($f['cliente']) ?></td>
              <td class="num"><?= pesos($f['total']) ?></td>
              <td class="num<?= !$anulada && $f['margen'] < 0 ? ' negativo' : '' ?>"><?= $anulada ? '—' : pesos($f['margen']) ?></td>
              <td>
                <?php if ($estadoClase !== ''): ?>
                  <span class="etiqueta <?= $estadoClase ?>"><?= e($estadoTexto) ?></span>
                <?php else: ?>
                  <?= e($estadoTexto) ?>
                <?php endif; ?>
              </td>
              <td class="num"><div class="acciones-factura"><?= $accionesFactura($f) ?></div></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <!-- Solo en celular: cada factura es una tarjeta que se despliega
           al tocarla, en vez de una fila de tabla angosta con 7
           columnas imposibles de leer. -->
      <div class="filas-esencial">
        <?php foreach ($historial as $f):
          $anulada = !empty($f['anulada']);
          [$estadoClase, $estadoTexto] = $estadoFactura($f);
        ?>
          <details class="fila-lista">
            <summary>
              <span class="fila-lista-resumen">
                <span class="fila-lista-titulo">No. <?= e($f['numero']) ?> · <?= e($f['cliente']) ?></span>
                <span class="fila-lista-pista"><?= pesos($f['total']) ?> · <?= e($estadoTexto) ?></span>
              </span>
              <svg class="fila-lista-flecha" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </summary>
            <div class="fila-lista-detalle">
              <div class="campo-detalle"><strong>Fecha</strong><?= e(fecha_larga(fecha_factura($f))) ?></div>
              <div class="campo-detalle"><strong>Ganancia</strong><?= $anulada ? '—' : pesos($f['margen']) ?></div>
              <div class="acciones-fila"><?= $accionesFactura($f) ?></div>
            </div>
          </details>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($todas): ?>

  <h2 class="titulo-modulo">Reportes</h2>

  <div class="kpis">
    <div class="kpi acento">
      <p class="kpi-etiqueta">Ganancia hoy</p>
      <p class="kpi-valor"><?= pesos($gananciaHoy) ?></p>
      <p class="kpi-sub"><?= e(fecha_larga($hoy)) ?></p>
    </div>
    <div class="kpi acento">
      <p class="kpi-etiqueta">Ganancia este mes</p>
      <p class="kpi-valor"><?= pesos($gananciaMes) ?></p>
      <p class="kpi-sub"><?= e(mes_largo($mesHoy)) ?></p>
    </div>
    <div class="kpi acento">
      <p class="kpi-etiqueta">Ganancia este año</p>
      <p class="kpi-valor"><?= pesos($gananciaAnio) ?></p>
      <p class="kpi-sub">Año <?= e($anioHoy) ?></p>
    </div>
  </div>

  <div class="kpis">
    <div class="kpi">
      <p class="kpi-etiqueta">Total vendido</p>
      <p class="kpi-valor"><?= pesos($totalVendido) ?></p>
      <p class="kpi-sub">Histórico · <?= numero($numVendidas) ?> factura<?= $numVendidas === 1 ? '' : 's' ?></p>
    </div>
    <div class="kpi">
      <p class="kpi-etiqueta">Total invertido</p>
      <p class="kpi-valor"><?= pesos($totalInvertido) ?></p>
      <p class="kpi-sub">Costo de lo vendido · histórico</p>
    </div>
    <div class="kpi">
      <p class="kpi-etiqueta">Más vendido hoy</p>
      <?php if ($masVendidoHoy): ?>
        <p class="kpi-valor kpi-texto"><?= e($porSku[$masVendidoHoy]['nombre']) ?></p>
        <p class="kpi-sub"><?= numero($cantHoy[$masVendidoHoy]) ?> unidad<?= $cantHoy[$masVendidoHoy] === 1 ? '' : 'es' ?> · <?= e($porSku[$masVendidoHoy]['sku']) ?></p>
      <?php else: ?>
        <p class="kpi-valor kpi-texto">—</p>
        <p class="kpi-sub">Sin ventas todavía hoy</p>
      <?php endif; ?>
    </div>
    <div class="kpi">
      <p class="kpi-etiqueta">Más vendido este mes</p>
      <?php if ($masVendidoMes): ?>
        <p class="kpi-valor kpi-texto"><?= e($porSku[$masVendidoMes]['nombre']) ?></p>
        <p class="kpi-sub"><?= numero($cantMes[$masVendidoMes]) ?> unidad<?= $cantMes[$masVendidoMes] === 1 ? '' : 'es' ?> · <?= e($porSku[$masVendidoMes]['sku']) ?></p>
      <?php else: ?>
        <p class="kpi-valor kpi-texto">—</p>
        <p class="kpi-sub">Sin ventas todavía este mes</p>
      <?php endif; ?>
    </div>
  </div>

  <section class="panel">
    <h2 class="titulo-panel">Ventas por producto <span class="titulo-nota">top <?= count($top) ?> por ingreso, histórico</span></h2>
    <div class="barras">
      <?php foreach ($top as $p): $pct = $maxTotal > 0 ? max(2, round($p['total'] / $maxTotal * 100)) : 0; ?>
        <div class="barra-fila">
          <div class="barra-nombre">
            <?= e($p['nombre']) ?>
            <span class="sku"><?= e($p['sku']) ?></span>
          </div>
          <div class="barra-pista"><div class="barra-lleno" style="width: <?= $pct ?>%"></div></div>
          <div class="barra-valor">
            <?= pesos($p['total']) ?>
            <span class="barra-uds"><?= numero($p['cant']) ?> uds</span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="panel panel-inventario">
    <h2 class="titulo-panel">Detalle por SKU</h2>
    <div class="tabla-scroll oculta-en-movil">
    <table class="lineas tabla-detalle-sku">
      <thead>
        <tr>
          <th>Producto</th>
          <th>Marca</th>
          <th class="num">Unidades</th>
          <th class="num">Total vendido</th>
          <th class="num">Margen</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lista as $p): ?>
          <tr>
            <td>
              <?= e($p['nombre']) ?>
              <span class="sku"><?= e($p['sku']) ?></span>
            </td>
            <td><?= e($p['marca']) ?></td>
            <td class="num"><?= numero($p['cant']) ?></td>
            <td class="num"><?= pesos($p['total']) ?></td>
            <td class="num"><?= pesos($p['margen']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <!-- Solo en celular: cada SKU es una tarjeta en vez de una fila de
         tabla angosta con 5 columnas numéricas imposibles de leer. -->
    <div class="filas-esencial">
      <?php foreach ($lista as $p): ?>
        <details class="fila-lista">
          <summary>
            <span class="fila-lista-resumen">
              <span class="fila-lista-titulo"><?= e($p['nombre']) ?></span>
              <span class="fila-lista-pista"><?= pesos($p['total']) ?> · <?= numero($p['cant']) ?> uds</span>
            </span>
            <svg class="fila-lista-flecha" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
          </summary>
          <div class="fila-lista-detalle">
            <div class="campo-detalle"><strong>SKU</strong><?= e($p['sku']) ?></div>
            <div class="campo-detalle"><strong>Marca</strong><?= e($p['marca']) ?></div>
            <div class="campo-detalle"><strong>Margen</strong><?= pesos($p['margen']) ?></div>
          </div>
        </details>
      <?php endforeach; ?>
    </div>
  </section>

  <?php endif; ?>

</main>

<script>
document.querySelectorAll('.btn-mostrar-descuento').forEach(btn => {
  btn.addEventListener('click', () => {
    const fila = document.getElementById('fila-' + btn.dataset.id);
    if (!fila) return;
    fila.hidden = !fila.hidden;
    if (!fila.hidden) {
      const motivo = fila.querySelector('input[name="motivo"]');
      if (motivo) motivo.focus();
    }
  });
});
document.querySelectorAll('.cerrar-descuento').forEach(btn => {
  btn.addEventListener('click', () => {
    const fila = document.getElementById('fila-' + btn.dataset.id);
    if (fila) fila.hidden = true;
  });
});
document.querySelectorAll('.form-descuento').forEach(form => {
  form.addEventListener('submit', (ev) => {
    const monto  = form.querySelector('input[name="monto"]').value;
    const motivo = form.querySelector('input[name="motivo"]').value.trim();
    if (motivo === '') {
      ev.preventDefault();
      alert('Escribe el motivo del descuento.');
      return;
    }
    if (!confirm('¿Aplicar un descuento de $' + Number(monto).toLocaleString('es-CO') + ' a esta cuota? No es un abono real: quedará marcado como descuento.')) {
      ev.preventDefault();
    }
  });
});

// Escritorio (≥1100px): cada cuota tiene un solo botón, "Cobrar", que abre debajo un panel con el
// abono (monto, "Total"/"Mitad", confirmar) y, como enlaces, WhatsApp y descuento. En celular y
// tablet el botón no se ve y siguen los controles de siempre. Una fila abierta a la vez.
(function () {
  const botones = document.querySelectorAll('.btn-cobrar');
  if (!botones.length) return;
  const soloNumeros = (t) => parseInt(String(t).replace(/\D/g, ''), 10) || 0;
  const con_puntos = (n) => (n ? n.toLocaleString('es-CO') : '');
  const pesos = (n) => '$' + n.toLocaleString('es-CO');
  let abierto = null; // el botón de la fila abierta

  function panelDe(btn) { return document.getElementById(btn.dataset.cobro); }

  function mostrar(panel, cual) { // 'abono' | 'descuento'
    panel.querySelector('.cobro-abono').hidden = cual !== 'abono';
    panel.querySelector('.cobro-descuento').hidden = cual !== 'descuento';
  }

  function cerrar(btn, devolverFoco) {
    const panel = panelDe(btn);
    panel.hidden = true;
    btn.setAttribute('aria-expanded', 'false');
    btn.querySelector('span').textContent = 'Cobrar';
    btn.closest('tr').classList.remove('abierta');
    if (abierto === btn) abierto = null;
    if (devolverFoco) btn.focus();
  }

  function abrir(btn) {
    if (abierto && abierto !== btn) cerrar(abierto, false);
    const panel = panelDe(btn);
    mostrar(panel, 'abono');
    panel.hidden = false;
    btn.setAttribute('aria-expanded', 'true');
    btn.querySelector('span').textContent = 'Cerrar';
    btn.closest('tr').classList.add('abierta');
    abierto = btn;
    const campo = panel.querySelector('.cobro-abono .cobro-input');
    campo.focus();
    campo.select();
  }

  botones.forEach((btn) => {
    btn.addEventListener('click', () => (abierto === btn ? cerrar(btn, true) : abrir(btn)));
  });

  document.querySelectorAll('.fila-cobro').forEach((panel) => {
    panel.querySelectorAll('.cobro-form').forEach((form) => {
      const pendiente = parseInt(form.dataset.pendiente, 10) || 0;
      const campo = form.querySelector('.cobro-input');
      const oculto = form.querySelector('input[name="monto"]');
      const error = form.querySelector('.cobro-error');
      const chips = form.querySelectorAll('.cobro-chip');
      const decir = (t) => { error.textContent = t || ''; campo.setAttribute('aria-invalid', t ? 'true' : 'false'); };
      const sincronizar = () => {
        const n = soloNumeros(campo.value);
        oculto.value = n;
        chips.forEach((c) => c.setAttribute('aria-pressed', String(parseInt(c.dataset.valor, 10) === n)));
      };

      campo.addEventListener('input', () => {
        campo.value = con_puntos(soloNumeros(campo.value));
        sincronizar();
        decir('');
      });
      chips.forEach((chip) => chip.addEventListener('click', () => {
        campo.value = con_puntos(parseInt(chip.dataset.valor, 10));
        sincronizar();
        decir('');
        campo.focus();
      }));

      form.addEventListener('submit', (ev) => {
        sincronizar();
        const monto = parseInt(oculto.value, 10) || 0;
        const descuento = form.classList.contains('cobro-descuento');
        if (monto < 1) { ev.preventDefault(); decir('Escribe un monto mayor a $0.'); campo.focus(); return; }
        if (monto > pendiente) { ev.preventDefault(); decir('No puede pasar de ' + pesos(pendiente) + ', que es lo que falta de esta cuota.'); campo.focus(); return; }
        if (descuento) {
          const motivo = form.querySelector('.cobro-motivo');
          if (motivo.value.trim() === '') { ev.preventDefault(); decir('Escribe el motivo del descuento.'); motivo.focus(); return; }
          if (!confirm('¿Aplicar un descuento de ' + pesos(monto) + ' a esta cuota? No es un abono real: quedará marcado como descuento.')) {
            ev.preventDefault();
          }
        }
      });
    });

    panel.querySelector('[data-cobro-descuento]').addEventListener('click', () => {
      mostrar(panel, 'descuento');
      panel.querySelector('.cobro-descuento .cobro-input').focus();
    });
    panel.querySelector('[data-cobro-volver]').addEventListener('click', () => {
      mostrar(panel, 'abono');
      panel.querySelector('.cobro-abono .cobro-input').focus();
    });
  });

  document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape' && abierto) cerrar(abierto, true);
  });
})();

// La búsqueda de facturas vive lejos del top de la página (después de
// recordatorios y cartera pendiente). Sin esto, cada recarga del
// formulario de búsqueda (Buscar o Limpiar) manda al usuario de vuelta
// al encabezado de arriba de todo, en vez de dejarlo donde estaba
// buscando — tiene que volver a bajar y a hacer clic en el campo.
if (location.search.includes('q=') || location.hash === '#historial') {
  const historial = document.getElementById('historial');
  if (historial) {
    historial.scrollIntoView({ block: 'start' });
    const campo = document.getElementById('buscar-historial');
    if (campo) {
      const valor = campo.value;
      campo.focus();
      campo.setSelectionRange(valor.length, valor.length);
    }
  }
}
</script>

</body>
</html>
