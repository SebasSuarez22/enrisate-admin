<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/facturas.php';

requerir_sesion();

$numero = (string) ($_GET['n'] ?? '');

$errorAgregar = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $numero = (string) ($_POST['numero'] ?? $numero);

    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $errorAgregar = 'La página estuvo abierta demasiado tiempo. Recárgala e intenta de nuevo.';
    } else {
        $sku   = (string) ($_POST['sku'] ?? '');
        $cant  = (int) ($_POST['cant'] ?? 1);
        $vence = (string) ($_POST['vence'] ?? '');

        if ($sku === '') {
            $errorAgregar = 'Escoge un producto.';
        } elseif (agregar_lineas_factura($numero, [['sku' => $sku, 'cant' => $cant]], $vence)) {
            header('Location: factura.php?n=' . urlencode($numero) . '&ok=1');
            exit;
        } else {
            $errorAgregar = 'No pude agregar ese producto. Revisa que exista en el catálogo y que la factura no esté anulada.';
        }
    }
}

$f = factura_por_numero($numero);

if (!$f) {
    http_response_code(404);
    exit('Esa factura no existe.');
}

$fecha = fecha_larga(fecha_factura($f));
$token = csrf_token();

$porMarcaAgregar = [];
foreach (catalogo() as $p) {
    $porMarcaAgregar[$p['marca']][] = $p;
}
ksort($porMarcaAgregar);

/* Mensaje de WhatsApp */
$msg  = "Hola " . $f['cliente'] . " 💛\n";
$msg .= "Aquí está tu cuenta de " . APP_NOMBRE . " (No. " . $f['numero'] . "):\n\n";
foreach ($f['lineas'] as $l) {
    $msg .= "• " . $l['cant'] . " x " . $l['nombre'] . " — " . pesos($l['precio'] * $l['cant']) . "\n";
}
if ($f['descuento'] > 0) {
    $msg .= "\nDescuento: -" . pesos($f['descuento']);
}
$msg .= "\nTotal: " . pesos($f['total']) . "\n";
if ($f['plan'] === 'cuotas') {
    if (!empty($f['saldo_aplicado'])) {
        $msg .= "Saldo a favor aplicado: " . pesos($f['saldo_aplicado']) . "\n";
    }
    if (!empty($f['abono'])) {
        $msg .= "Abono recibido: " . pesos($f['abono']) . "\n";
    }
    $msg .= "\nQuedamos así:\n";
    foreach ($f['cuotas'] as $idx => $cuota) {
        $rotulo = rotulo_cuota($idx, count($f['cuotas']));
        $msg .= $rotulo . ' (' . fecha_larga($cuota['vence']) . '): ' . pesos((int) $cuota['monto']) . "\n";
    }
}
if (!empty($f['nota'])) {
    $msg .= "\n" . $f['nota'];
}
$msg .= "\n📄 Tu factura en PDF: " . factura_pdf_url($f['numero']) . "\n";
$msg .= "\n" . (factura_totalmente_pagada($f)
    ? "¡Gracias por tu compra! Eres un cliente muy valioso para nosotros. 💛"
    : "¡Gracias por confiar en nosotros!");

$wa = enlace_whatsapp($f['telefono'], $msg);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Factura <?= e($f['numero']) ?> · <?= e(APP_NOMBRE) ?></title>
<link rel="icon" href="assets/img/logo.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Manrope:wght@400;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/enrisate.css?v=<?= filemtime(__DIR__ . '/assets/css/enrisate.css') ?>">
<link rel="stylesheet" href="assets/css/factura.css">
</head>
<body class="modo-papel">

<nav class="acciones">
  <a class="salir" href="facturar.php">Nueva cuenta</a>
  <a class="salir" href="dashboard.php">Panel</a>
  <button type="button" class="boton mini" onclick="window.print()">Guardar como PDF</button>
  <?php if ($f['telefono'] !== ''): ?>
    <a class="boton mini verde" href="<?= e($wa) ?>" target="_blank" rel="noopener">Enviar por WhatsApp</a>
  <?php endif; ?>
</nav>

<article class="hoja<?= !empty($f['anulada']) ? ' hoja-anulada' : '' ?>">

  <?php if (!empty($f['anulada'])): ?>
    <p class="sello-anulada">Factura anulada</p>
  <?php endif; ?>

  <header class="encabezado">
    <div class="identidad">
      <img src="assets/img/logo.svg" alt="">
      <div>
        <p class="nombre-negocio"><?= e(APP_NOMBRE) ?></p>
        <p class="lema"><?= e(APP_TAGLINE) ?> · <?= e(APP_CIUDAD) ?></p>
      </div>
    </div>
    <div class="folio">
      <p class="folio-num">No. <?= e($f['numero']) ?></p>
      <p class="folio-fecha"><?= e($fecha) ?></p>
    </div>
  </header>

  <section class="para">
    <p class="rotulo">Para</p>
    <p class="cliente-nombre"><?= e($f['cliente']) ?></p>
    <?php if ($f['telefono'] !== ''): ?>
      <p class="cliente-tel"><?= e($f['telefono']) ?></p>
    <?php endif; ?>
  </section>

  <table class="detalle">
    <thead>
      <tr>
        <th>Producto</th>
        <th class="num">Cant.</th>
        <th class="num">Unitario</th>
        <th class="num">Valor</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($f['lineas'] as $l): ?>
        <tr>
          <td>
            <span class="prod"><?= e($l['nombre']) ?></span>
            <span class="marca"><?= e($l['marca']) ?></span>
          </td>
          <td class="num"><?= (int) $l['cant'] ?></td>
          <td class="num"><?= pesos($l['precio']) ?></td>
          <td class="num"><?= pesos($l['precio'] * $l['cant']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <section class="totales">
    <div class="tot"><span>Subtotal</span><span><?= pesos($f['subtotal']) ?></span></div>
    <?php if ($f['descuento'] > 0): ?>
      <div class="tot"><span>Descuento</span><span>−<?= pesos($f['descuento']) ?></span></div>
    <?php endif; ?>
    <div class="tot grande"><span>Total</span><span><?= pesos($f['total']) ?></span></div>
  </section>

  <?php if ($f['plan'] === 'cuotas'): ?>
    <section class="plan">
      <p class="rotulo">Plan de pago</p>
      <?php if (!empty($f['saldo_aplicado'])): ?>
        <p class="cuota-rot">Saldo a favor aplicado: <?= pesos($f['saldo_aplicado']) ?></p>
      <?php endif; ?>
      <?php if (!empty($f['abono'])): ?>
        <p class="cuota-rot">Abono recibido: <?= pesos($f['abono']) ?></p>
      <?php endif; ?>
      <div class="cuotas">
        <?php foreach ($f['cuotas'] as $idx => $cuota):
            $rotulo    = rotulo_cuota($idx, count($f['cuotas']));
            $pagado    = cuota_pagado($cuota);
            $pendiente = cuota_pendiente($cuota);
            $pagada    = $pendiente <= 0;
        ?>
          <div class="cuota <?= $pagada ? 'pagada' : '' ?>">
            <p class="cuota-rot"><?= e($rotulo) ?> · <?= e(fecha_larga($cuota['vence'])) ?></p>
            <p class="cuota-val"><?= pesos((int) $cuota['monto']) ?></p>
            <p class="cuota-est <?= $pagada ? '' : 'pendiente' ?>">
              <?php if ($pagada): ?>
                Recibida
              <?php elseif ($pagado > 0): ?>
                Abonado <?= pesos($pagado) ?> · Pendiente <?= pesos($pendiente) ?>
              <?php else: ?>
                Pendiente
              <?php endif; ?>
            </p>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php else: ?>
    <section class="plan">
      <p class="sello">Pagado de contado</p>
    </section>
  <?php endif; ?>

  <?php if (!empty($f['nota'])): ?>
    <p class="nota"><?= e($f['nota']) ?></p>
  <?php endif; ?>

  <footer class="cierre">
    <p>Gracias por confiar en <?= e(APP_NOMBRE) ?>.</p>
    <p class="fino">Documento de cobro. No es factura electrónica DIAN.</p>
  </footer>

</article>

<?php if (empty($f['anulada'])): ?>
  <section class="panel panel-agregar-producto">
    <h2 class="titulo-panel">Agregar producto a esta factura</h2>
    <p class="pista">Para cuando la clienta pide algo más días después de esta cuenta: el producto se suma al detalle y su valor queda como una cuota pendiente nueva, lista para cobrar desde Cartera.</p>

    <?php if ($errorAgregar): ?>
      <p class="aviso" role="alert"><?= e($errorAgregar) ?></p>
    <?php elseif (!empty($_GET['ok'])): ?>
      <p class="aviso neutro">Producto agregado. Usa «Enviar por WhatsApp» arriba para avisarle a la clienta con la cuenta actualizada.</p>
    <?php endif; ?>

    <form method="post" action="factura.php?n=<?= e($f['numero']) ?>" class="form-agregar-producto">
      <input type="hidden" name="csrf" value="<?= e($token) ?>">
      <input type="hidden" name="numero" value="<?= e($f['numero']) ?>">
      <div class="campo">
        <label for="agregarSku">Producto</label>
        <select id="agregarSku" name="sku" required>
          <option value="">Selecciona un producto…</option>
          <?php foreach ($porMarcaAgregar as $marca => $ps): ?>
            <optgroup label="<?= e($marca) ?>">
              <?php foreach ($ps as $p): ?>
                <option value="<?= e($p['sku']) ?>"><?= e($p['nombre']) ?> — <?= pesos($p['precio']) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo cantidad">
        <label for="agregarCant">Cantidad</label>
        <input type="number" id="agregarCant" name="cant" min="1" max="99" value="1" required>
      </div>
      <div class="campo fecha">
        <label for="agregarVence">Vence</label>
        <input type="date" id="agregarVence" name="vence" value="<?= e(date('Y-m-d')) ?>" required>
      </div>
      <button type="submit" class="boton mini">Agregar producto</button>
    </form>
  </section>
<?php endif; ?>

</body>
</html>
