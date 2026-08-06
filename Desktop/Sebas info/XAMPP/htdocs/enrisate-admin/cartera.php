<?php
declare(strict_types=1);
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

$waAbono = null;

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
        $msg .= $pendiente > 0
            ? "Saldo pendiente de esa cuota: " . pesos($pendiente) . "\n"
            : "Con esto, esa cuota queda completa. ¡Gracias!\n";

        $waAbono = enlace_whatsapp($fAbono['telefono'], $msg);
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

<main class="lienzo ancho">
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

  <section class="panel">
    <h2 class="titulo-panel">Cartera pendiente</h2>
    <?php if (!$pendientes): ?>
      <p class="subsaludo">No hay cuotas pendientes por cobrar.</p>
    <?php else: ?>
      <table class="lineas">
        <thead>
          <tr>
            <th>Clienta</th>
            <th>No. factura</th>
            <th>Cuota</th>
            <th>Vence</th>
            <th class="num">Monto pendiente</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pendientes as $p): $f = $p['factura']; $vencida = esta_vencida($p['vence']); $abonado = $p['pactado'] - $p['pendiente']; ?>
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
              <td class="num">
                <form method="post" action="cartera.php" class="en-linea">
                  <input type="hidden" name="csrf" value="<?= e($token) ?>">
                  <input type="hidden" name="numero" value="<?= e($f['numero']) ?>">
                  <input type="hidden" name="cuota_idx" value="<?= (int) $p['idx'] ?>">
                  <input type="number" name="monto" min="1" max="<?= (int) $p['pendiente'] ?>" step="1"
                         value="<?= (int) $p['pendiente'] ?>" aria-label="Monto del abono">
                  <button type="submit" class="boton mini">Registrar abono</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <section class="panel">
    <h2 class="titulo-panel">Historial de facturas</h2>

    <form method="get" action="cartera.php" class="form-busqueda">
      <div class="campo apretado">
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar por clienta o número de factura…">
      </div>
      <button type="submit" class="boton mini">Buscar</button>
      <?php if ($q !== ''): ?><a class="salir" href="cartera.php">Limpiar</a><?php endif; ?>
    </form>

    <?php if (!$historial): ?>
      <p class="subsaludo">No hay facturas que coincidan con la búsqueda.</p>
    <?php else: ?>
      <table class="lineas">
        <thead>
          <tr>
            <th>No.</th>
            <th>Fecha</th>
            <th>Clienta</th>
            <th class="num">Total</th>
            <th class="num">Ganancia</th>
            <th>Estado</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($historial as $f):
            $anulada = !empty($f['anulada']);
            if ($anulada) {
                $estadoClase = 'anulada';
                $estadoTexto = 'Anulada';
            } elseif ($f['plan'] !== 'cuotas') {
                $estadoClase = '';
                $estadoTexto = 'Contado';
            } else {
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
                    $estadoClase = 'pagada';
                    $estadoTexto = 'Pagada';
                } elseif ($algunaVencida) {
                    $estadoClase = 'vencida';
                    $estadoTexto = 'Vencida';
                } else {
                    $estadoClase = 'pendiente';
                    $estadoTexto = 'Pendiente';
                }
            }
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
              <td class="num acciones-factura">
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
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
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
    <table class="lineas">
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
  </section>

  <?php endif; ?>

</main>

</body>
</html>
