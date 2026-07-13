<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/facturas.php';

requerir_sesion();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'La página estuvo abierta demasiado tiempo. Recárgala e intenta de nuevo.';
    } else {
        $cliente  = trim((string) ($_POST['cliente'] ?? ''));
        $telefono = preg_replace('/\D+/', '', (string) ($_POST['telefono'] ?? ''));
        $items    = json_decode((string) ($_POST['items'] ?? '[]'), true);

        if ($cliente === '') {
            $error = 'Escribe el nombre de la clienta.';
        } elseif (!is_array($items) || count($items) === 0) {
            $error = 'Agrega al menos un producto a la cuenta.';
        } else {
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
                $error = 'Ninguno de los productos existe en el catálogo.';
            } else {
                $descuento = max(0, min($subtotal, (int) ($_POST['descuento'] ?? 0)));
                $total     = $subtotal - $descuento;

                $plan = ($_POST['plan'] ?? 'contado') === 'cuotas' ? 'cuotas' : 'contado';

                $cuota1 = $total;
                $cuota2 = 0;
                $vence  = null;

                if ($plan === 'cuotas' && $total > 1) {
                    $cuota1 = (int) ($_POST['cuota1'] ?? 0);
                    $cuota1 = max(1, min($total - 1, $cuota1));
                    $cuota2 = $total - $cuota1;
                    $vence  = trim((string) ($_POST['vence'] ?? ''));
                    if ($vence === '') {
                        $vence = date('Y-m-d', strtotime('+30 days'));
                    }
                } else {
                    $plan = 'contado';
                }

                $numero = crear_factura([
                    'cliente'   => $cliente,
                    'telefono'  => $telefono,
                    'nota'      => trim((string) ($_POST['nota'] ?? '')),
                    'lineas'    => $lineas,
                    'subtotal'  => $subtotal,
                    'descuento' => $descuento,
                    'total'     => $total,
                    'costo'     => $costo,          // interno, nunca se imprime
                    'margen'    => $total - $costo, // interno
                    'plan'      => $plan,
                    'cuota1'    => $cuota1,
                    'cuota2'    => $cuota2,
                    'vence'     => $vence,
                    'pagado'    => $plan === 'contado' ? $total : $cuota1,
                ]);

                header('Location: factura.php?n=' . urlencode($numero));
                exit;
            }
        }
    }
}

$catalogo = catalogo();
$porMarca = [];
foreach ($catalogo as $p) {
    $porMarca[$p['marca']][] = $p;
}
ksort($porMarca);

$token = csrf_token();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Nueva cuenta · <?= e(APP_NOMBRE) ?></title>
<link rel="icon" href="assets/img/logo.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Manrope:wght@400;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/enrisate.css">
</head>
<body>

<?php require __DIR__ . '/includes/escena.php'; ?>

<header class="barra">
  <img src="assets/img/logo.svg" alt="">
  <span class="wordmark"><?= e(APP_NOMBRE) ?></span>
  <span class="quien"><?= e($_SESSION['nombre'] ?? '') ?></span>
  <a class="salir" href="dashboard.php">Volver al panel</a>
</header>

<main class="lienzo ancho">
  <h1 class="saludo">Nueva cuenta de cobro</h1>
  <p class="subsaludo">Escoge los productos, define cómo paga la clienta y se genera el PDF.</p>

  <?php if ($error !== ''): ?>
    <p class="aviso" role="alert"><?= e($error) ?></p>
  <?php endif; ?>

  <form method="post" action="facturar.php" id="cuenta" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e($token) ?>">
    <input type="hidden" name="items" id="items" value="[]">

    <div class="dos-columnas">

      <!-- ------------- Productos ------------- -->
      <section class="panel">
        <h2 class="titulo-panel">Productos</h2>

        <div class="fila-agregar">
          <select id="sku" aria-label="Producto">
            <option value="">Buscar producto…</option>
            <?php foreach ($porMarca as $marca => $ps): ?>
              <optgroup label="<?= e($marca) ?>">
                <?php foreach ($ps as $p): ?>
                  <option value="<?= e($p['sku']) ?>"
                          data-nombre="<?= e($p['nombre']) ?>"
                          data-precio="<?= (int) $p['precio'] ?>"
                          data-costo="<?= (int) $p['costo'] ?>">
                    <?= e($p['nombre']) ?> — <?= pesos($p['precio']) ?>
                  </option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
          <input type="number" id="cant" value="1" min="1" max="99" aria-label="Cantidad">
          <button type="button" class="boton mini" id="agregar">Agregar</button>
        </div>

        <table class="lineas" id="tabla">
          <thead>
            <tr><th>Producto</th><th class="num">Cant.</th><th class="num">Valor</th><th></th></tr>
          </thead>
          <tbody id="cuerpo">
            <tr class="vacio"><td colspan="4">Todavía no hay productos en la cuenta.</td></tr>
          </tbody>
        </table>

        <div class="campo apretado">
          <label for="descuento">Descuento</label>
          <input type="number" id="descuento" name="descuento" value="0" min="0" step="1000">
        </div>
      </section>

      <!-- ------------- Clienta y pago ------------- -->
      <section class="panel">
        <h2 class="titulo-panel">Clienta</h2>

        <div class="campo">
          <label for="cliente">Nombre</label>
          <input type="text" id="cliente" name="cliente" placeholder="Gina Pérez" required>
        </div>

        <div class="campo">
          <label for="telefono">WhatsApp</label>
          <input type="tel" id="telefono" name="telefono" placeholder="3001234567" inputmode="numeric">
        </div>

        <h2 class="titulo-panel espaciado">Forma de pago</h2>

        <div class="opciones">
          <label class="opcion">
            <input type="radio" name="plan" value="contado" checked>
            <span>De contado</span>
          </label>
          <label class="opcion">
            <input type="radio" name="plan" value="cuotas">
            <span>En 2 cuotas</span>
          </label>
        </div>

        <div id="bloqueCuotas" hidden>
          <div class="campo">
            <label for="cuota1">Primera cuota (hoy)</label>
            <input type="number" id="cuota1" name="cuota1" min="0" step="1000" value="0">
          </div>
          <p class="pista">Segunda cuota: <strong id="verCuota2">$0</strong></p>
          <div class="campo">
            <label for="vence">Fecha de la segunda cuota</label>
            <input type="date" id="vence" name="vence"
                   value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
          </div>
        </div>

        <div class="campo">
          <label for="nota">Nota para la clienta</label>
          <input type="text" id="nota" name="nota" placeholder="Opcional">
        </div>

        <div class="resumen">
          <div class="linea-res"><span>Subtotal</span><strong id="verSub">$0</strong></div>
          <div class="linea-res"><span>Descuento</span><strong id="verDesc">$0</strong></div>
          <div class="linea-res total"><span>Total</span><strong id="verTotal">$0</strong></div>
          <div class="linea-res margen" title="Solo lo ves tú. No sale en la factura.">
            <span>Ganancia <em>(interno)</em></span><strong id="verMargen">$0</strong>
          </div>
        </div>

        <button type="submit" class="boton">Generar factura</button>
      </section>

    </div>
  </form>
</main>

<script>
const cop = n => '$' + Math.round(n).toLocaleString('es-CO');

const sel   = document.getElementById('sku');
const cant  = document.getElementById('cant');
const cuerpo= document.getElementById('cuerpo');
const items = [];

document.getElementById('agregar').addEventListener('click', () => {
  const o = sel.selectedOptions[0];
  if (!o || !o.value) { sel.focus(); return; }
  const c = Math.max(1, Math.min(99, parseInt(cant.value, 10) || 1));

  const ya = items.find(i => i.sku === o.value);
  if (ya) { ya.cant = Math.min(99, ya.cant + c); }
  else {
    items.push({
      sku: o.value,
      nombre: o.dataset.nombre,
      precio: +o.dataset.precio,
      costo: +o.dataset.costo,
      cant: c
    });
  }
  sel.value = ''; cant.value = 1;
  pintar();
});

function quitar(sku) {
  const i = items.findIndex(x => x.sku === sku);
  if (i > -1) items.splice(i, 1);
  pintar();
}

function pintar() {
  cuerpo.innerHTML = '';
  if (!items.length) {
    cuerpo.innerHTML = '<tr class="vacio"><td colspan="4">Todavía no hay productos en la cuenta.</td></tr>';
  }
  items.forEach(i => {
    const tr = document.createElement('tr');
    tr.innerHTML =
      '<td>' + i.nombre + '<span class="sku">' + i.sku + '</span></td>' +
      '<td class="num">' + i.cant + '</td>' +
      '<td class="num">' + cop(i.precio * i.cant) + '</td>' +
      '<td class="num"><button type="button" class="quitar" aria-label="Quitar ' + i.nombre + '">&times;</button></td>';
    tr.querySelector('.quitar').addEventListener('click', () => quitar(i.sku));
    cuerpo.appendChild(tr);
  });
  calcular();
}

function calcular(editandoCuota1) {
  const sub    = items.reduce((s, i) => s + i.precio * i.cant, 0);
  const costo  = items.reduce((s, i) => s + i.costo  * i.cant, 0);
  const desc   = Math.max(0, Math.min(sub, parseInt(document.getElementById('descuento').value, 10) || 0));
  const total  = sub - desc;

  document.getElementById('verSub').textContent    = cop(sub);
  document.getElementById('verDesc').textContent   = cop(desc);
  document.getElementById('verTotal').textContent  = cop(total);
  document.getElementById('verMargen').textContent = cop(total - costo);
  document.getElementById('items').value = JSON.stringify(items.map(i => ({ sku: i.sku, cant: i.cant })));

  const c1i = document.getElementById('cuota1');
  c1i.max = Math.max(1, total - 1);
  if (!editandoCuota1 && (!+c1i.value || +c1i.value > total)) {
    c1i.value = Math.round(total / 2 / 1000) * 1000;
  }
  const c1 = Math.max(0, Math.min(total, +c1i.value || 0));
  document.getElementById('verCuota2').textContent = cop(total - c1);
}

document.getElementById('descuento').addEventListener('input', () => calcular(false));
document.getElementById('cuota1').addEventListener('input', () => calcular(true));

document.querySelectorAll('input[name="plan"]').forEach(r =>
  r.addEventListener('change', e => {
    document.getElementById('bloqueCuotas').hidden = (e.target.value !== 'cuotas');
    calcular();
  })
);

pintar();
</script>

</body>
</html>
