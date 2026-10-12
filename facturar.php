<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/formato.php';
require_once __DIR__ . '/includes/catalogo.php';
require_once __DIR__ . '/includes/pedidos.php';
require_once __DIR__ . '/includes/clientes.php';
require_once __DIR__ . '/includes/cuentas.php';

requerir_sesion();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'La página estuvo abierta demasiado tiempo. Recárgala e intenta de nuevo.';
    } else {
        // Toda la lógica (validar, calcular y guardar factura, clienta, saldo y pedido
        // en una sola operación de todo o nada) vive en includes/cuentas.php.
        try {
            $numero = crear_cuenta_desde_formulario($_POST);
            header('Location: factura.php?n=' . urlencode($numero));
            exit;
        } catch (DomainException | RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}

$catalogo = catalogo();

$clientesExistentes = leer_clientes();
usort($clientesExistentes, fn($a, $b) => $a['nombre'] <=> $b['nombre']);

// Si se abrió desde "Confirmar y facturar" en Pedidos, precarga esa
// propuesta — pero solo si el pedido sigue pendiente (no reabre uno ya
// confirmado o descartado).
$pedidoDesde = null;
$numeroPedidoGet = trim((string) ($_GET['desde_pedido'] ?? ''));
if ($numeroPedidoGet !== '') {
    $pd = pedido_por_numero($numeroPedidoGet);
    if ($pd && ($pd['estado'] ?? '') === 'pendiente') {
        $pedidoDesde = $pd;
    }
}

// Si quien pidió por el catálogo público ya es una clienta guardada (mismo
// teléfono, o mismo nombre si no hay teléfono), se precarga como "Clienta
// existente" en vez de "Nueva clienta" — así se ve su saldo a favor si tiene.
$clienteCoincidente = null;
if ($pedidoDesde) {
    $clienteCoincidente = buscar_cliente_coincidente(
        $clientesExistentes,
        trim((string) ($pedidoDesde['cliente'] ?? '')),
        normalizar_telefono((string) ($pedidoDesde['telefono'] ?? ''))
    );
}

$token = csrf_token();

$tituloPagina = 'Nueva cuenta';
$barraVolver  = true;
require __DIR__ . '/includes/cabecera.php';
?>

<main class="lienzo ancho">
  <h1 class="saludo">Nueva cuenta de cobro</h1>
  <p class="subsaludo">Escoge los productos, define cómo paga el cliente y se genera el PDF.</p>

  <?php if ($error !== ''): ?>
    <p class="aviso" role="alert"><?= e($error) ?></p>
  <?php endif; ?>
  <?php if ($pedidoDesde): ?>
    <p class="aviso neutro">Prellenado desde el pedido <?= e($pedidoDesde['numero']) ?> de <?= e($pedidoDesde['cliente']) ?>. Revisa todo antes de generar la factura.<?php
      $dtoPedido = (int) ($pedidoDesde['subtotal'] ?? 0) - (int) ($pedidoDesde['total'] ?? 0);
      if ($dtoPedido > 0): ?><br>Este pedido trae el descuento de la promoción del catálogo (<?= e(pesos($dtoPedido)) ?>): ya quedó en el campo Descuento.<?php endif; ?></p>
    <label class="opcion opcion-pago-pedido">
      <input type="checkbox" id="pedidoYaPago">
      <span>Ya me pagó el total</span>
    </label>
    <p class="pista">Si no te ha pagado todavía (lo normal en un pedido recién llegado), déjalo sin marcar: la cuenta queda pendiente de cobro hasta que la marques como pagada. Ajusta el plan, los montos y las fechas según lo que quedaron con ella por WhatsApp.</p>
  <?php endif; ?>

  <form method="post" action="facturar.php" id="cuenta" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e($token) ?>">
    <input type="hidden" name="items" id="items" value="[]">
    <input type="hidden" name="desde_pedido" value="<?= e($pedidoDesde['numero'] ?? '') ?>">

    <div class="dos-columnas">

      <!-- ------------- Productos ------------- -->
      <section class="panel">
        <h2 class="titulo-panel">Productos</h2>

        <div class="fila-agregar">
          <div class="buscador">
            <input type="text" id="buscarProd" placeholder="Escribe el producto…"
                   autocomplete="off" role="combobox" aria-expanded="false"
                   aria-autocomplete="list" aria-controls="sugerencias" aria-label="Buscar producto">
            <ul id="sugerencias" class="sugerencias" role="listbox" hidden></ul>
          </div>
          <input type="number" id="cant" value="1" min="1" max="99" aria-label="Cantidad">
          <button type="button" class="boton mini" id="agregar">Agregar</button>
        </div>

        <div class="tabla-scroll">
        <table class="lineas" id="tabla">
          <thead>
            <tr><th>Producto</th><th class="num">Cant.</th><th class="num">Valor</th><th></th></tr>
          </thead>
          <tbody id="cuerpo">
            <tr class="vacio"><td colspan="4">Todavía no hay productos en la cuenta.</td></tr>
          </tbody>
        </table>
        </div>

        <div class="campo apretado">
          <label for="descuento">Descuento</label>
          <input type="number" id="descuento" name="descuento" value="0" min="0" step="1">
        </div>
      </section>

      <!-- ------------- Clienta y pago ------------- -->
      <section class="panel">
        <h2 class="titulo-panel">Cliente</h2>

        <div class="opciones">
          <label class="opcion">
            <input type="radio" name="modoCliente" value="existente" <?= $clientesExistentes ? 'checked' : '' ?>>
            <span>Cliente existente</span>
          </label>
          <label class="opcion">
            <input type="radio" name="modoCliente" value="nueva" <?= !$clientesExistentes ? 'checked' : '' ?>>
            <span>Nuevo cliente</span>
          </label>
        </div>

        <div class="campo" id="campoClienteSel">
          <label for="clienteSel">Elige de la lista</label>
          <select id="clienteSel" <?= !$clientesExistentes ? 'disabled' : '' ?>>
            <option value="">Selecciona un cliente…</option>
            <?php foreach ($clientesExistentes as $c): ?>
              <option value="<?= e($c['nombre']) ?>"
                      data-id="<?= e($c['id']) ?>"
                      data-telefono="<?= e($c['telefono']) ?>"
                      data-saldo="<?= (int) ($c['saldo_favor'] ?? 0) ?>">
                <?= e($c['nombre']) ?><?= $c['telefono'] !== '' ? ' · ' . e($c['telefono']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (!$clientesExistentes): ?>
            <p class="pista">Todavía no hay clientes guardados — usa "Nuevo cliente".</p>
          <?php endif; ?>
        </div>

        <div class="campo">
          <label for="cliente">Nombre</label>
          <input type="text" id="cliente" name="cliente" placeholder="Gina Pérez" required readonly>
        </div>

        <div class="campo">
          <label for="telefono">WhatsApp</label>
          <input type="tel" id="telefono" name="telefono" placeholder="3001234567" inputmode="numeric" readonly>
        </div>

        <input type="hidden" name="cliente_id" id="clienteId" value="">

        <div class="campo" id="campoSaldo" hidden>
          <label for="saldoAplicado">Usar de su saldo a favor <span class="pista-inline" id="saldoDisponibleTxt"></span></label>
          <input type="number" id="saldoAplicado" name="saldo_aplicado" min="0" step="500" value="0">
          <p class="pista">Se descuenta de su saldo y cubre parte de esta cuenta.</p>
        </div>

        <h2 class="titulo-panel espaciado">Forma de pago</h2>

        <div class="opciones">
          <label class="opcion">
            <input type="radio" name="plan" value="contado" checked>
            <span>De contado</span>
          </label>
          <label class="opcion">
            <input type="radio" name="plan" value="cuotas">
            <span>A cuotas</span>
          </label>
        </div>

        <div id="bloqueContado">
          <div class="campo">
            <label for="fecha_pago">Fecha de pago</label>
            <input type="date" id="fecha_pago" name="fecha_pago" value="<?= date('Y-m-d') ?>">
          </div>
        </div>

        <div id="bloqueCuotas" hidden>
          <div class="campo">
            <label for="abono">Abono recibido</label>
            <input type="number" id="abono" name="abono" min="0" step="500" value="0">
            <p class="pista">Lo que el cliente ya te pagó. No es descuento: no baja la ganancia.</p>
          </div>
          <div class="campo">
            <label for="numCuotas">Número de cuotas</label>
            <select id="numCuotas" name="num_cuotas">
              <option value="1">1 cuota (pago único a plazo)</option>
              <option value="2" selected>2 cuotas</option>
              <option value="3">3 cuotas</option>
              <option value="4">4 cuotas</option>
            </select>
            <p class="pista">"1 cuota" es para un cliente que paga todo junto pero en una fecha futura (su día de pago): queda pendiente hasta que marques el pago, y se ve como vencida si no paga a tiempo — a diferencia de "De contado", que se da por pagado el mismo día.</p>
          </div>
          <div id="filasCuotas"></div>
        </div>

        <div class="campo">
          <label for="nota">Nota para el cliente</label>
          <input type="text" id="nota" name="nota" placeholder="Opcional">
        </div>

        <div class="resumen">
          <div class="linea-res"><span>Subtotal</span><strong id="verSub">$0</strong></div>
          <div class="linea-res"><span>Descuento</span><strong id="verDesc">$0</strong></div>
          <div class="linea-res total"><span>Total</span><strong id="verTotal">$0</strong></div>
          <div class="linea-res" id="filaSaldo" hidden><span>Saldo a favor aplicado</span><strong id="verSaldo">$0</strong></div>
          <div class="linea-res" id="filaAbono" hidden><span>Abono recibido</span><strong id="verAbono">$0</strong></div>
          <div class="linea-res" id="filaResto" hidden><span>Resto a cuotas</span><strong id="verResto">$0</strong></div>
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

const esc  = s => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const norm = s => (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');

const PRODUCTOS = <?= json_encode(array_map(fn($p) => [
    'sku'    => $p['sku'],
    'nombre' => $p['nombre'],
    'marca'  => $p['marca'],
    'precio' => (int) $p['precio'],
    'costo'  => (int) $p['costo'],
], $catalogo), JSON_UNESCAPED_UNICODE) ?>;

const PEDIDO_DESDE = <?= json_encode($pedidoDesde, JSON_UNESCAPED_UNICODE) ?>;
const CLIENTE_COINCIDENTE = <?= json_encode($clienteCoincidente, JSON_UNESCAPED_UNICODE) ?>;

const cant   = document.getElementById('cant');
const cuerpo = document.getElementById('cuerpo');
const items  = [];

/* ---------- Buscador de productos: escribe una palabra y elige ---------- */
const buscar = document.getElementById('buscarProd');
const suger  = document.getElementById('sugerencias');
let productoSel = null;
let idxActivo   = -1;

function filtrar(q) {
  const nq = norm(q).trim();
  if (!nq) return [];
  const terminos = nq.split(/\s+/);
  return PRODUCTOS
    .filter(p => {
      const heno = norm(p.nombre + ' ' + p.marca + ' ' + p.sku);
      return terminos.every(t => heno.includes(t));
    })
    .slice(0, 8);
}

function abrirSug()  { suger.hidden = false; buscar.setAttribute('aria-expanded', 'true'); }
function cerrarSug() { suger.hidden = true;  buscar.setAttribute('aria-expanded', 'false'); idxActivo = -1; }

function pintarSug(lista) {
  suger.innerHTML = '';
  idxActivo = -1;
  if (!buscar.value.trim()) { cerrarSug(); return; }
  if (!lista.length) {
    suger.innerHTML = '<li class="vacio-sug">Sin coincidencias</li>';
    abrirSug();
    return;
  }
  lista.forEach(p => {
    const li = document.createElement('li');
    li.dataset.sku = p.sku;
    li.setAttribute('role', 'option');
    li.innerHTML =
      '<span class="sug-nombre">' + esc(p.nombre) +
      ' <span class="sug-marca">' + esc(p.marca) + '</span></span>' +
      '<span class="sug-precio">' + cop(p.precio) + '</span>';
    // mousedown (no click) para que se dispare antes del blur del input
    li.addEventListener('mousedown', e => { e.preventDefault(); elegir(p); agregarProducto(); });
    suger.appendChild(li);
  });
  abrirSug();
}

function elegir(p) { productoSel = p; buscar.value = p.nombre; }

function marcarActivo(lis) {
  lis.forEach((li, i) => li.classList.toggle('activa', i === idxActivo));
  if (lis[idxActivo]) lis[idxActivo].scrollIntoView({ block: 'nearest' });
}

buscar.addEventListener('input', () => { productoSel = null; pintarSug(filtrar(buscar.value)); });

buscar.addEventListener('keydown', e => {
  const lis = [...suger.querySelectorAll('li[data-sku]')];
  if (e.key === 'ArrowDown') {
    e.preventDefault();
    if (lis.length) { idxActivo = Math.min(lis.length - 1, idxActivo + 1); marcarActivo(lis); }
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    if (lis.length) { idxActivo = Math.max(0, idxActivo - 1); marcarActivo(lis); }
  } else if (e.key === 'Enter') {
    e.preventDefault();
    if (idxActivo >= 0 && lis[idxActivo]) {
      elegir(PRODUCTOS.find(p => p.sku === lis[idxActivo].dataset.sku)); agregarProducto();
    } else if (lis.length === 1) {
      elegir(PRODUCTOS.find(p => p.sku === lis[0].dataset.sku)); agregarProducto();
    } else if (productoSel) {
      agregarProducto();
    }
  } else if (e.key === 'Escape') {
    cerrarSug();
  }
});

document.addEventListener('click', e => { if (!e.target.closest('.buscador')) cerrarSug(); });

function agregarProducto() {
  if (!productoSel) { buscar.focus(); return; }
  const c = Math.max(1, Math.min(99, parseInt(cant.value, 10) || 1));
  const ya = items.find(i => i.sku === productoSel.sku);
  if (ya) { ya.cant = Math.min(99, ya.cant + c); }
  else {
    items.push({
      sku:    productoSel.sku,
      nombre: productoSel.nombre,
      precio: productoSel.precio,
      costo:  productoSel.costo,
      cant:   c
    });
  }
  productoSel = null;
  buscar.value = '';
  cant.value = 1;
  cerrarSug();
  buscar.focus();
  pintar();
}

document.getElementById('agregar').addEventListener('click', agregarProducto);

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
      '<td>' + esc(i.nombre) + '<span class="sku">' + esc(i.sku) + '</span></td>' +
      '<td class="num">' + i.cant + '</td>' +
      '<td class="num">' + cop(i.precio * i.cant) + '</td>' +
      '<td class="num"><button type="button" class="quitar" aria-label="Quitar ' + esc(i.nombre) + '">&times;</button></td>';
    tr.querySelector('.quitar').addEventListener('click', () => quitar(i.sku));
    cuerpo.appendChild(tr);
  });
  calcular();
}

function calcular() {
  const sub    = items.reduce((s, i) => s + i.precio * i.cant, 0);
  const costo  = items.reduce((s, i) => s + i.costo  * i.cant, 0);
  const desc   = Math.max(0, Math.min(sub, parseInt(document.getElementById('descuento').value, 10) || 0));
  const total  = sub - desc;

  document.getElementById('verSub').textContent    = cop(sub);
  document.getElementById('verDesc').textContent   = cop(desc);
  document.getElementById('verTotal').textContent  = cop(total);
  document.getElementById('verMargen').textContent = cop(total - costo);
  document.getElementById('items').value = JSON.stringify(items.map(i => ({ sku: i.sku, cant: i.cant })));

  // Saldo a favor de la clienta (si tiene): cubre parte del total en ambos planes.
  const saldoI     = document.getElementById('saldoAplicado');
  const disponible = parseInt(saldoI.dataset.disponible, 10) || 0;
  const saldo      = Math.max(0, Math.min(disponible, total, parseInt(saldoI.value, 10) || 0));

  const esCuotas = document.querySelector('input[name="plan"]:checked').value === 'cuotas';
  const abonoI   = document.getElementById('abono');
  const abono    = esCuotas ? Math.max(0, Math.min(total - saldo, parseInt(abonoI.value, 10) || 0)) : 0;
  const resto    = total - saldo - abono;

  document.getElementById('filaSaldo').hidden = saldo <= 0;
  document.getElementById('verSaldo').textContent = cop(saldo);
  document.getElementById('filaAbono').hidden = !esCuotas || abono <= 0;
  document.getElementById('verAbono').textContent = cop(abono);
  document.getElementById('filaResto').hidden = !esCuotas || (saldo <= 0 && abono <= 0);
  document.getElementById('verResto').textContent = cop(resto);

  if (esCuotas) {
    const montoInputs = [...filasCuotas.querySelectorAll('.montoCuota')];
    const n = montoInputs.length + 1; // cuotas libres + la última calculada
    let sumaLibres = 0;
    montoInputs.forEach((input, i) => {
      const restantesDespues = n - 1 - i;
      const tope = Math.max(1, resto - sumaLibres - restantesDespues);
      input.max = tope;
      if (!+input.value) {
        input.value = Math.max(0, Math.round((resto / n) / 500) * 500);
      } else if (+input.value > tope) {
        // Baja al múltiplo de 500 más cercano por debajo del tope: si se deja el
        // tope exacto (normalmente no es múltiplo de 500), el navegador lo marca
        // inválido por el step del input y bloquea el envío igual que antes.
        input.value = Math.max(0, Math.floor(tope / 500) * 500);
      }
      const v = Math.max(0, Math.min(tope, +input.value || 0));
      sumaLibres += v;
    });
    const verUltima = document.getElementById('verUltimaCuota');
    if (verUltima) verUltima.textContent = cop(Math.max(0, resto - sumaLibres));
  }
}

document.getElementById('descuento').addEventListener('input', calcular);
document.getElementById('abono').addEventListener('input', calcular);
document.getElementById('saldoAplicado').addEventListener('input', calcular);

const filasCuotas  = document.getElementById('filasCuotas');
const numCuotasSel = document.getElementById('numCuotas');

function sumarDias(dias) {
  const d = new Date();
  d.setDate(d.getDate() + dias);
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

function pintarFilasCuotas() {
  const n = parseInt(numCuotasSel.value, 10);
  let html = '';
  for (let i = 0; i < n - 1; i++) {
    html +=
      '<div class="campo">' +
        '<label>Cuota ' + (i + 1) + '</label>' +
        '<input type="number" name="cuota_monto[]" min="0" step="500" value="0" class="montoCuota">' +
      '</div>' +
      '<div class="campo">' +
        '<label>Fecha de la cuota ' + (i + 1) + '</label>' +
        '<input type="date" name="cuota_vence[]" class="venceCuota" value="' + sumarDias(30 * i) + '">' +
      '</div>';
  }
  const rotuloUltima = n === 1 ? 'Total a pagar' : ('Cuota ' + n + ' (última)');
  const rotuloFecha  = n === 1 ? 'Fecha de pago acordada' : ('Fecha de la cuota ' + n);
  const fechaDefecto = n === 1 ? sumarDias(15) : sumarDias(30 * (n - 1));
  html +=
    '<p class="pista">' + rotuloUltima + ': <strong id="verUltimaCuota">$0</strong></p>' +
    '<div class="campo">' +
      '<label>' + rotuloFecha + '</label>' +
      '<input type="date" name="cuota_vence[]" class="venceCuota" value="' + fechaDefecto + '">' +
    '</div>';
  filasCuotas.innerHTML = html;
  filasCuotas.querySelectorAll('.montoCuota').forEach(i => i.addEventListener('input', calcular));
  calcular();
}

numCuotasSel.addEventListener('change', pintarFilasCuotas);

document.querySelectorAll('input[name="plan"]').forEach(r =>
  r.addEventListener('change', e => {
    document.getElementById('bloqueCuotas').hidden  = (e.target.value !== 'cuotas');
    document.getElementById('bloqueContado').hidden = (e.target.value !== 'contado');
    if (e.target.value === 'cuotas' && !filasCuotas.children.length) pintarFilasCuotas();
    calcular();
  })
);

/* ------------- Clienta: existente (de la lista) vs. nueva ------------- */
const campoClienteSel = document.getElementById('campoClienteSel');
const clienteSel      = document.getElementById('clienteSel');
const clienteInput    = document.getElementById('cliente');
const telefonoInput   = document.getElementById('telefono');
const clienteIdInput  = document.getElementById('clienteId');
const campoSaldo      = document.getElementById('campoSaldo');
const saldoInput      = document.getElementById('saldoAplicado');
const saldoDispTxt    = document.getElementById('saldoDisponibleTxt');

/* Carga (o limpia) el saldo a favor según la clienta elegida. */
function aplicarSaldoCliente(option) {
  const saldo = parseInt(option?.dataset.saldo, 10) || 0;
  clienteIdInput.value          = option?.dataset.id || '';
  saldoInput.dataset.disponible = saldo;
  if (saldo > 0) {
    campoSaldo.hidden        = false;
    saldoInput.max           = saldo;
    saldoDispTxt.textContent = '(tiene ' + cop(saldo) + ')';
  } else {
    campoSaldo.hidden             = true;
    saldoInput.value             = 0;
    saldoInput.dataset.disponible = 0;
    saldoDispTxt.textContent     = '';
  }
  calcular(false);
}

function aplicarModoCliente(modo) {
  if (modo === 'nueva') {
    campoClienteSel.hidden   = true;
    clienteInput.readOnly    = false;
    telefonoInput.readOnly   = false;
    clienteInput.value       = '';
    telefonoInput.value      = '';
    clienteSel.value         = '';
    aplicarSaldoCliente(null); // clienta nueva no tiene saldo
  } else {
    campoClienteSel.hidden   = false;
    clienteInput.readOnly    = true;
    telefonoInput.readOnly   = true;
    clienteInput.value       = clienteSel.value;
    telefonoInput.value      = clienteSel.selectedOptions[0]?.dataset.telefono || '';
    aplicarSaldoCliente(clienteSel.selectedOptions[0]);
  }
}

document.querySelectorAll('input[name="modoCliente"]').forEach(r =>
  r.addEventListener('change', e => aplicarModoCliente(e.target.value))
);

clienteSel.addEventListener('change', () => {
  clienteInput.value  = clienteSel.value;
  telefonoInput.value = clienteSel.selectedOptions[0]?.dataset.telefono || '';
  aplicarSaldoCliente(clienteSel.selectedOptions[0]);
});

aplicarModoCliente(document.querySelector('input[name="modoCliente"]:checked').value);

document.getElementById('cuenta').addEventListener('submit', e => {
  const modo = document.querySelector('input[name="modoCliente"]:checked').value;
  if (modo === 'existente' && !clienteInput.value) {
    e.preventDefault();
    clienteSel.focus();
    alert('Elige un cliente de la lista, o cambia a "Nuevo cliente".');
  }
});

/* ------------- Precarga desde un pedido del catálogo público -------------
   Los montos y fechas de cuotas NUNCA vienen calculados de antemano: eso se
   negocia de verdad con la clienta por WhatsApp. Lo único que se precarga
   es la propuesta (contado o cuántas cuotas prefiere) como punto de
   partida, y si ya te pagó o no — por defecto se asume que NO, porque
   ningún pedido del catálogo trae plata: si no ha pagado, se arma como
   "1 cuota" (pago pendiente, no "de contado") para que quede visible en
   Cartera hasta que la marques como cobrada. */
if (PEDIDO_DESDE) {
  (PEDIDO_DESDE.lineas || []).forEach(l => {
    const prod = PRODUCTOS.find(p => p.sku === l.sku);
    if (prod) {
      items.push({ sku: prod.sku, nombre: prod.nombre, precio: prod.precio, costo: prod.costo, cant: l.cant });
    }
  });

  // Descuento de la promoción del catálogo (3 o más productos): el pedido guarda el total ya
  // rebajado, y la diferencia con el subtotal se precarga aquí para que llegue a la cuenta.
  const descuentoPedido = Math.max(0, (parseInt(PEDIDO_DESDE.subtotal, 10) || 0) - (parseInt(PEDIDO_DESDE.total, 10) || 0));
  if (descuentoPedido > 0) {
    document.getElementById('descuento').value = String(descuentoPedido);
  }

  // Si el teléfono (o, si no hay, el nombre) ya corresponde a una clienta
  // guardada, se precarga como "Clienta existente" (así se ve su saldo a
  // favor si tiene). Si no, se asume "Nueva clienta" como antes.
  const opcionCoincidente = CLIENTE_COINCIDENTE
    ? clienteSel.querySelector('option[data-id="' + CSS.escape(CLIENTE_COINCIDENTE.id) + '"]')
    : null;

  if (opcionCoincidente) {
    document.querySelector('input[name="modoCliente"][value="existente"]').checked = true;
    opcionCoincidente.selected = true;
    aplicarModoCliente('existente');
  } else {
    document.querySelector('input[name="modoCliente"][value="nueva"]').checked = true;
    aplicarModoCliente('nueva');
    clienteInput.value  = PEDIDO_DESDE.cliente || '';
    telefonoInput.value = PEDIDO_DESDE.telefono || '';
  }

  const yaPagoInput = document.getElementById('pedidoYaPago');

  function aplicarPlanDelPedido() {
    const yaPago = !!(yaPagoInput && yaPagoInput.checked);

    if (yaPago) {
      document.querySelector('input[name="plan"][value="contado"]').checked = true;
      document.getElementById('bloqueCuotas').hidden  = true;
      document.getElementById('bloqueContado').hidden = false;
    } else {
      // No ha pagado: siempre queda como pendiente de cobro, sea que la
      // clienta haya pedido "de contado" (1 cuota = paga todo pero después)
      // o "a cuotas" (se usa el número que ella prefirió, como punto de
      // partida — ajústalo si quedaron en otra cosa por WhatsApp).
      const nPreferido = PEDIDO_DESDE.plan === 'cuotas' && PEDIDO_DESDE.num_cuotas_preferido
        ? Math.max(1, Math.min(4, parseInt(PEDIDO_DESDE.num_cuotas_preferido, 10) || 1))
        : 1;
      document.querySelector('input[name="plan"][value="cuotas"]').checked = true;
      document.getElementById('bloqueCuotas').hidden  = false;
      document.getElementById('bloqueContado').hidden = true;
      numCuotasSel.value = String(nPreferido);
      pintarFilasCuotas();
      // "1 cuota con plazo": la fecha que ella escogió en el catálogo queda como la fecha
      // de pago (el monto es el total; la fecha se puede ajustar si acordaron otra).
      if (nPreferido === 1 && PEDIDO_DESDE.fecha_pago_preferida) {
        const campoFecha = filasCuotas.querySelector('.venceCuota');
        if (campoFecha) campoFecha.value = PEDIDO_DESDE.fecha_pago_preferida;
      }
    }
    calcular();
  }

  if (yaPagoInput) yaPagoInput.addEventListener('change', aplicarPlanDelPedido);
  aplicarPlanDelPedido();
}

pintar();
</script>

</body>
</html>
