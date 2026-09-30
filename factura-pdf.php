<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/facturas.php';
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/* Sin sesión a propósito: este es el link que la clienta abre desde el
   mensaje de WhatsApp en su propio celular, nunca desde el panel. Lo
   que evita que cualquiera vea la factura de otra persona adivinando el
   número no es el login sino el token (ver factura_pdf_token() en
   includes/facturas.php). */
$numero = (string) ($_GET['n'] ?? '');
$token  = (string) ($_GET['t'] ?? '');

// Primero el token (no toca la base): un enlace inventado se rechaza sin
// consultar nada. Después se lee solo esa factura. Las dos negativas responden
// exactamente igual, para que nadie pueda averiguar qué números de factura existen.
$f = ($numero !== '' && factura_pdf_token_valido($numero, $token))
    ? factura_por_numero($numero)
    : null;

if (!$f) {
    http_response_code(404);
    exit('Ese enlace no es válido o ya venció.');
}

$fecha = fecha_larga(fecha_factura($f));

/* Plantilla propia, más simple que factura.php: acá manda que Dompdf la
   pueda dibujar igual en cualquier lado (sin fuentes de Google Fonts,
   sin flexbox/grid, todo con tablas y bloques), no que se vea idéntica
   pixel a pixel a la vista en el panel. Mismos colores y misma
   información. */
ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
  @page { margin: 16mm; }
  * { box-sizing: border-box; }
  body {
    font-family: "DejaVu Sans", sans-serif;
    color: #2A1B24;
    font-size: 11.5px;
    line-height: 1.5;
  }
  table { border-collapse: collapse; width: 100%; }

  .encabezado { width: 100%; margin-bottom: 16px; padding-bottom: 14px; border-bottom: 2px solid #2A1B24; }
  .encabezado td { vertical-align: top; }
  .nombre-negocio {
    margin: 0;
    font-size: 19px;
    font-weight: bold;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: #2A1B24;
  }
  .lema { margin: 3px 0 0; font-size: 8.5px; letter-spacing: 1px; text-transform: uppercase; color: #8A7580; }
  .folio-num { margin: 0; font-size: 12px; font-weight: bold; color: #B98B45; text-align: right; }
  .folio-fecha { margin: 3px 0 0; font-size: 11px; color: #8A7580; text-align: right; }

  .sello-anulada {
    margin: 0 0 16px; padding: 8px 14px; border: 1.5px solid #b23a48; border-radius: 4px;
    text-align: center; font-size: 11px; letter-spacing: 1.5px; text-transform: uppercase;
    color: #b23a48; background: #fbeceec;
  }

  .rotulo { margin: 0 0 4px; font-size: 8.5px; letter-spacing: 1.5px; text-transform: uppercase; color: #8A7580; }
  .cliente-nombre { margin: 0; font-size: 16px; font-weight: bold; }
  .cliente-tel { margin: 2px 0 0; font-size: 11px; color: #8A7580; }
  .para { margin: 14px 0 18px; }

  .detalle th {
    padding: 0 10px 7px 0; font-size: 8.5px; font-weight: bold; letter-spacing: 1px;
    text-transform: uppercase; text-align: left; color: #8A7580; border-bottom: 1px solid #E7DCD5;
  }
  .detalle td { padding: 8px 10px 8px 0; font-size: 11px; border-bottom: 1px solid #E7DCD5; vertical-align: top; }
  .detalle th:last-child, .detalle td:last-child { padding-right: 0; }
  .num { text-align: right; }
  .prod { display: block; font-weight: bold; }
  .marca { display: block; margin-top: 1px; font-size: 9.5px; color: #8A7580; }

  .totales { margin: 16px 0 0; width: 220px; float: right; }
  .totales td { padding: 4px 0; font-size: 11px; color: #8A7580; }
  .totales td.num { text-align: right; }
  .totales tr.grande td { padding-top: 8px; border-top: 2px solid #2A1B24; font-size: 15px; font-weight: bold; color: #2A1B24; }
  .limpiar { clear: both; }

  .plan { margin-top: 14px; }
  .cuota {
    padding: 8px 12px; margin-bottom: 6px; border: 1px solid #E7DCD5; border-radius: 5px; background: #FBF6F1;
    page-break-inside: avoid;
  }
  .cuota.pagada { border-color: #2E7D5B; background: #eef6f1; }
  .cuota-rot { margin: 0; font-size: 9.5px; color: #8A7580; }
  .cuota-val { margin: 3px 0; font-size: 14px; font-weight: bold; }
  .cuota-est { margin: 0; font-size: 8.5px; letter-spacing: 1px; text-transform: uppercase; color: #2E7D5B; }
  .cuota-est.pendiente { color: #B98B45; }

  .sello {
    display: inline-block; margin-top: 4px; padding: 6px 14px; border: 1.5px solid #2E7D5B; border-radius: 4px;
    font-size: 10px; letter-spacing: 1.5px; text-transform: uppercase; color: #2E7D5B;
  }

  .nota { margin-top: 14px; padding: 8px 12px; border-left: 2px solid #B98B45; background: #FBF6F1; font-size: 10.5px; page-break-inside: avoid; }

  .cierre { margin-top: 18px; padding-top: 10px; border-top: 1px solid #E7DCD5; text-align: center; page-break-inside: avoid; }
  .cierre p { margin: 0; font-size: 10.5px; color: #8A7580; }
  .cierre .fino { margin-top: 4px; font-size: 8px; letter-spacing: 1px; text-transform: uppercase; color: #B6A6AE; }
</style>
</head>
<body>

<?php if (!empty($f['anulada'])): ?>
  <p class="sello-anulada">Factura anulada</p>
<?php endif; ?>

<table class="encabezado">
  <tr>
    <td style="width: 60%;">
      <p class="nombre-negocio"><?= e(APP_NOMBRE) ?></p>
      <p class="lema"><?= e(APP_TAGLINE) ?> &middot; <?= e(APP_CIUDAD) ?></p>
    </td>
    <td style="width: 40%;">
      <p class="folio-num">No. <?= e($f['numero']) ?></p>
      <p class="folio-fecha"><?= e($fecha) ?></p>
    </td>
  </tr>
</table>

<div class="para">
  <p class="rotulo">Para</p>
  <p class="cliente-nombre"><?= e($f['cliente']) ?></p>
  <?php if ($f['telefono'] !== ''): ?>
    <p class="cliente-tel"><?= e($f['telefono']) ?></p>
  <?php endif; ?>
</div>

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

<table class="totales">
  <tr><td>Subtotal</td><td class="num"><?= pesos($f['subtotal']) ?></td></tr>
  <?php if ($f['descuento'] > 0): ?>
    <tr><td>Descuento</td><td class="num">&minus;<?= pesos($f['descuento']) ?></td></tr>
  <?php endif; ?>
  <tr class="grande"><td>Total</td><td class="num"><?= pesos($f['total']) ?></td></tr>
</table>
<div class="limpiar"></div>

<?php if ($f['plan'] === 'cuotas'): ?>
  <div class="plan">
    <p class="rotulo">Plan de pago</p>
    <?php if (!empty($f['saldo_aplicado'])): ?>
      <p class="cuota-rot">Saldo a favor aplicado: <?= pesos($f['saldo_aplicado']) ?></p>
    <?php endif; ?>
    <?php if (!empty($f['abono'])): ?>
      <p class="cuota-rot">Abono recibido: <?= pesos($f['abono']) ?></p>
    <?php endif; ?>
    <?php foreach ($f['cuotas'] as $idx => $cuota):
        $rotulo    = rotulo_cuota($idx, count($f['cuotas']));
        $pagado    = cuota_pagado($cuota);
        $pendiente = cuota_pendiente($cuota);
        $pagada    = $pendiente <= 0;
    ?>
      <div class="cuota <?= $pagada ? 'pagada' : '' ?>">
        <p class="cuota-rot"><?= e($rotulo) ?> &middot; <?= e(fecha_larga($cuota['vence'])) ?></p>
        <p class="cuota-val"><?= pesos((int) $cuota['monto']) ?></p>
        <p class="cuota-est <?= $pagada ? '' : 'pendiente' ?>">
          <?php if ($pagada): ?>
            Recibida
          <?php elseif ($pagado > 0): ?>
            Abonado <?= pesos($pagado) ?> &middot; Pendiente <?= pesos($pendiente) ?>
          <?php else: ?>
            Pendiente
          <?php endif; ?>
        </p>
      </div>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <p class="sello">Pagado de contado</p>
<?php endif; ?>

<?php if (!empty($f['nota'])): ?>
  <p class="nota"><?= e($f['nota']) ?></p>
<?php endif; ?>

<div class="cierre">
  <p>Gracias por confiar en <?= e(APP_NOMBRE) ?>.</p>
  <p class="fino">Documento de cobro. No es factura electrónica DIAN.</p>
</div>

</body>
</html>
<?php
$html = ob_get_clean();

$opciones = new Options();
$opciones->setChroot(__DIR__);
$opciones->set('isRemoteEnabled', false);
$opciones->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($opciones);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('letter', 'portrait');
$dompdf->render();

$nombreArchivo = 'Factura-' . preg_replace('/[^A-Za-z0-9._-]/', '', $f['numero']) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $nombreArchivo . '"');
echo $dompdf->output();
