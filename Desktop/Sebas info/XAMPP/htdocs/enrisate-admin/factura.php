<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/facturas.php';

requerir_sesion();

$f = factura_por_numero((string) ($_GET['n'] ?? ''));

if (!$f) {
    http_response_code(404);
    exit('Esa factura no existe.');
}

$fecha = fecha_larga($f['creada']);

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
    $msg .= "\nQuedamos así:\n";
    $msg .= "Primera cuota (hoy): " . pesos($f['cuota1']) . "\n";
    $msg .= "Segunda cuota (" . fecha_larga($f['vence']) . "): " . pesos($f['cuota2']) . "\n";
}
if (!empty($f['nota'])) {
    $msg .= "\n" . $f['nota'];
}
$msg .= "\n¡Gracias por confiar en nosotros!";

$wa = 'https://wa.me/'
    . ($f['telefono'] !== '' ? '57' . ltrim($f['telefono'], '5') : '')
    . '?text=' . rawurlencode($msg);
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
<link rel="stylesheet" href="assets/css/enrisate.css">
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

<article class="hoja">

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
      <div class="cuotas">
        <div class="cuota pagada">
          <p class="cuota-rot">Primera cuota · hoy</p>
          <p class="cuota-val"><?= pesos($f['cuota1']) ?></p>
          <p class="cuota-est">Recibida</p>
        </div>
        <div class="cuota">
          <p class="cuota-rot">Segunda cuota · <?= e(fecha_larga($f['vence'])) ?></p>
          <p class="cuota-val"><?= pesos($f['cuota2']) ?></p>
          <p class="cuota-est pendiente">Pendiente</p>
        </div>
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

</body>
</html>
