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

<main class="lienzo ancho facturar">
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

<?php
/* Las filas del resumen se pintan dos veces con los mismos números (data-v): en el panel de
   escritorio y en la hoja que se abre desde el total, en el celular. */
$filasResumen = static fn(): string =>
    '<div class="linea-res"><span>Subtotal</span><strong data-v="sub">$0</strong></div>'
  . '<div class="linea-res"><span>Descuento</span><strong data-v="desc">$0</strong></div>'
  . '<div class="linea-res total"><span>Total</span><strong data-v="total">$0</strong></div>'
  . '<div class="linea-res" data-fila="saldo" hidden><span>Saldo a favor aplicado</span><strong data-v="saldo">$0</strong></div>'
  . '<div class="linea-res" data-fila="abono" hidden><span>Abono recibido</span><strong data-v="abono">$0</strong></div>'
  . '<div class="linea-res" data-fila="resto" hidden><span>Resto a cuotas</span><strong data-v="resto">$0</strong></div>'
  . '<div class="linea-res margen" title="Solo lo ves tú. No sale en la factura."><span>Ganancia <em>(interno)</em></span><strong data-v="margen">$0</strong></div>';
?>
    <div class="dos-columnas">

      <!-- ------------- Productos ------------- -->
      <section class="panel seccion" data-sec="productos">
        <h2 class="titulo-panel sec-titulo">
          <button type="button" class="sec-cab" aria-controls="cuerpoProductos" aria-expanded="true">
            <span class="sec-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8h12l-1 12H7L6 8Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg></span>
            <span class="sec-txt"><span class="sec-nombre">Productos</span><span class="sec-res" data-res="productos"></span></span>
            <span class="sec-flecha" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></span>
          </button>
        </h2>

        <div class="sec-cuerpo" id="cuerpoProductos">
          <div class="fila-agregar">
            <div class="buscador">
              <input type="text" id="buscarProd" placeholder="Escribe el producto…"
                     autocomplete="off" enterkeyhint="search" role="combobox" aria-expanded="false"
                     aria-autocomplete="list" aria-controls="sugerencias" aria-label="Buscar producto">
              <button type="button" class="buscador-borrar" id="borrarBusqueda" aria-label="Borrar búsqueda" hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
              </button>
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
          <!-- En tablet y celular las líneas son tarjetas con − y +, no una tabla que se desliza -->
          <div class="lineas-lista" id="lineasLista" aria-live="polite"></div>

          <div class="campo apretado">
            <label for="descuento">Descuento</label>
            <input type="number" id="descuento" name="descuento" value="0" min="0" step="1">
          </div>
          <p class="aviso aviso-campo" id="errProductos" role="alert" hidden></p>
        </div>
      </section>

      <!-- ------------- Cliente y pago ------------- -->
      <div class="columna-cobro">

        <section class="seccion" data-sec="cliente">
          <h2 class="titulo-panel sec-titulo">
            <button type="button" class="sec-cab" aria-controls="cuerpoCliente" aria-expanded="true">
              <span class="sec-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c.8-3.6 3.6-5.5 7-5.5s6.2 1.9 7 5.5"/></svg></span>
              <span class="sec-txt"><span class="sec-nombre">Cliente</span><span class="sec-res" data-res="cliente"></span></span>
              <span class="sec-flecha" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></span>
            </button>
          </h2>

          <div class="sec-cuerpo" id="cuerpoCliente">
            <div class="opciones">
              <label class="opcion">
                <input type="radio" name="modoCliente" value="existente" <?= $clientesExistentes ? 'checked' : '' ?>>
                <span>Existente</span>
              </label>
              <label class="opcion">
                <input type="radio" name="modoCliente" value="nueva" <?= !$clientesExistentes ? 'checked' : '' ?>>
                <span>Nuevo</span>
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
                <p class="pista">Todavía no hay clientes guardados — usa "Nuevo".</p>
              <?php endif; ?>
            </div>

            <div class="campo">
              <label for="cliente">Nombre</label>
              <input type="text" id="cliente" name="cliente" placeholder="Gina Pérez" readonly>
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
              <button type="button" class="boton mini fantasma" id="usarTodoSaldo">Usar todo el saldo</button>
            </div>
            <p class="aviso aviso-campo" id="errCliente" role="alert" hidden></p>
          </div>
        </section>

        <section class="seccion" data-sec="pago">
          <h2 class="titulo-panel sec-titulo espaciado">
            <button type="button" class="sec-cab" aria-controls="cuerpoPago" aria-expanded="true">
              <span class="sec-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="12" rx="2.5"/><path d="M3 10h18M7 15h3"/></svg></span>
              <span class="sec-txt"><span class="sec-nombre">Forma de pago</span><span class="sec-res" data-res="pago"></span></span>
              <span class="sec-flecha" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></span>
            </button>
          </h2>

          <div class="sec-cuerpo" id="cuerpoPago">
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
                <span class="etq" id="etqCuotas">Número de cuotas</span>
                <div class="opciones cuatro" id="numCuotas" role="radiogroup" aria-labelledby="etqCuotas">
                  <label class="opcion"><input type="radio" name="num_cuotas" value="1"><span>1</span></label>
                  <label class="opcion"><input type="radio" name="num_cuotas" value="2" checked><span>2</span></label>
                  <label class="opcion"><input type="radio" name="num_cuotas" value="3"><span>3</span></label>
                  <label class="opcion"><input type="radio" name="num_cuotas" value="4"><span>4</span></label>
                </div>
                <p class="pista" id="ayudaCuotas" hidden></p>
              </div>
              <div id="filasCuotas"></div>
            </div>

            <div class="campo">
              <label for="nota">Nota para el cliente</label>
              <input type="text" id="nota" name="nota" placeholder="Opcional">
            </div>
          </div>
        </section>

        <!-- Escritorio: resumen y botón al final de la columna. En tablet y celular los reemplaza la barra de abajo. -->
        <div class="cobro-cierre">
          <div class="resumen"><?= $filasResumen() ?></div>
          <button type="submit" class="boton">Generar factura</button>
        </div>

      </div>
    </div>

    <!-- Tablet y celular: total y "Generar factura" siempre a la vista -->
    <div class="barra-cobro">
      <button type="button" class="barra-total" id="abrirResumen" aria-haspopup="dialog">
        <span class="barra-etq">Total
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 15 6-6 6 6"/></svg>
        </span>
        <strong data-v="total">$0</strong>
      </button>
      <div class="barra-cifras">
        <div><span>Subtotal</span><strong data-v="sub">$0</strong></div>
        <div><span>Descuento</span><strong data-v="desc">$0</strong></div>
        <div class="gana"><span>Ganancia <em>(interno)</em></span><strong data-v="margen">$0</strong></div>
        <div class="gran"><span>Total</span><strong data-v="total">$0</strong></div>
      </div>
      <button type="submit" class="boton">Generar factura</button>
    </div>
  </form>

  <!-- Celular: el resumen completo (con la ganancia) se abre desde el total -->
  <dialog class="hoja-resumen" id="hojaResumen" aria-labelledby="tituloHoja">
    <div class="hoja-cuerpo">
      <h2 id="tituloHoja">Resumen de la cuenta</h2>
      <div class="resumen"><?= $filasResumen() ?></div>
      <button type="button" class="boton fantasma" data-cerrar-hoja>Cerrar</button>
    </div>
  </dialog>
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

const formulario = document.getElementById('cuenta');
const cant   = document.getElementById('cant');
const cuerpo = document.getElementById('cuerpo');
const lista  = document.getElementById('lineasLista');
const items  = [];

/* ---------- Buscador de productos: escribe una palabra y elige ---------- */
const buscar = document.getElementById('buscarProd');
const suger  = document.getElementById('sugerencias');
const borrarBusqueda = document.getElementById('borrarBusqueda');
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
  borrarBusqueda.hidden = !buscar.value;
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

borrarBusqueda.addEventListener('click', () => {
  buscar.value = '';
  productoSel = null;
  pintarSug([]);
  buscar.focus();
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
      marca:  productoSel.marca,
      precio: productoSel.precio,
      costo:  productoSel.costo,
      cant:   c
    });
  }
  productoSel = null;
  buscar.value = '';
  borrarBusqueda.hidden = true;
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

const ICONO_QUITAR = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12h10l1-12M9 7V4h6v3"/></svg>';

function tarjetaLinea(i) {
  const sku = esc(i.sku);
  return '<div class="linea-tarjeta">' +
    '<div class="lt-nombre">' + esc(i.nombre) + '<small>' + esc(i.marca || '') + ', ' + cop(i.precio) + ' c/u</small></div>' +
    '<div class="lt-valor">' + cop(i.precio * i.cant) + '</div>' +
    '<div class="lt-ctrl">' +
      '<div class="lt-cant" role="group" aria-label="Cantidad de ' + esc(i.nombre) + '">' +
        '<button type="button" data-acc="menos" data-sku="' + sku + '" aria-label="Quitar una unidad">−</button>' +
        '<b>' + i.cant + '</b>' +
        '<button type="button" data-acc="mas" data-sku="' + sku + '" aria-label="Agregar una unidad">+</button>' +
      '</div>' +
      '<button type="button" class="lt-quitar" data-acc="quitar" data-sku="' + sku + '" aria-label="Quitar ' + esc(i.nombre) + ' de la cuenta">' + ICONO_QUITAR + '</button>' +
    '</div></div>';
}

lista.addEventListener('click', e => {
  const b = e.target.closest('[data-acc]');
  const it = b && items.find(x => x.sku === b.dataset.sku);
  if (!it) return;
  const acc = b.dataset.acc;
  if (acc === 'quitar') { quitar(it.sku); return; }
  it.cant = Math.max(1, Math.min(99, it.cant + (acc === 'mas' ? 1 : -1)));
  pintar();
  // pintar() vuelve a crear los botones: el foco regresa al que se tocó
  [...lista.querySelectorAll('[data-acc]')].find(x => x.dataset.acc === acc && x.dataset.sku === it.sku)?.focus();
});

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
  lista.innerHTML = items.length
    ? items.map(tarjetaLinea).join('')
    : '<p class="lineas-vacio">Todavía no hay productos en la cuenta.</p>';
  calcular();
}

/* Subtotal, descuento, total y costo: los usan calcular() y "Usar todo el saldo". */
function totales() {
  const sub   = items.reduce((s, i) => s + i.precio * i.cant, 0);
  const costo = items.reduce((s, i) => s + i.costo  * i.cant, 0);
  const desc  = Math.max(0, Math.min(sub, parseInt(document.getElementById('descuento').value, 10) || 0));
  return { sub, costo, desc, total: sub - desc };
}

function calcular() {
  const { sub, costo, desc, total } = totales();
  document.getElementById('items').value = JSON.stringify(items.map(i => ({ sku: i.sku, cant: i.cant })));

  // Saldo a favor de la clienta (si tiene): cubre parte del total en ambos planes.
  const saldoI     = document.getElementById('saldoAplicado');
  const disponible = parseInt(saldoI.dataset.disponible, 10) || 0;
  const saldo      = Math.max(0, Math.min(disponible, total, parseInt(saldoI.value, 10) || 0));

  const esCuotas = document.querySelector('input[name="plan"]:checked').value === 'cuotas';
  const abonoI   = document.getElementById('abono');
  const abono    = esCuotas ? Math.max(0, Math.min(total - saldo, parseInt(abonoI.value, 10) || 0)) : 0;
  const resto    = total - saldo - abono;

  let primeraCuota = resto;
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
    primeraCuota = montoInputs.length ? (+montoInputs[0].value || 0) : resto;
  }

  // Las mismas cifras se muestran en el panel de escritorio, la barra de abajo y la hoja del celular.
  const valores = { sub: cop(sub), desc: cop(desc), total: cop(total), margen: cop(total - costo), saldo: cop(saldo), abono: cop(abono), resto: cop(resto) };
  document.querySelectorAll('[data-v]').forEach(el => { el.textContent = valores[el.dataset.v]; });
  const filas = { saldo: saldo > 0, abono: esCuotas && abono > 0, resto: esCuotas && (saldo > 0 || abono > 0) };
  document.querySelectorAll('[data-fila]').forEach(el => { el.hidden = !filas[el.dataset.fila]; });

  // Lo que cada sección cuenta de sí misma cuando está cerrada (celular).
  const uds = items.reduce((s, i) => s + i.cant, 0);
  const n   = numCuotas();
  const textos = {
    productos: items.length ? uds + (uds === 1 ? ' producto, ' : ' productos, ') + cop(sub) : 'Sin productos todavía',
    cliente:   clienteInput.value.trim() || (modoCliente() === 'nueva' ? 'Cliente nuevo, falta el nombre' : 'Falta elegir el cliente'),
    pago:      !esCuotas ? 'De contado, ' + cop(total - saldo)
                         : (n === 1 ? 'Un pago a plazo, ' + cop(resto) : n + ' cuotas de ' + cop(primeraCuota))
  };
  document.querySelectorAll('[data-res]').forEach(el => { el.textContent = textos[el.dataset.res]; });
  const listas = { productos: items.length > 0, cliente: clienteInput.value.trim() !== '', pago: true };
  document.querySelectorAll('.seccion').forEach(s => s.classList.toggle('lista', !!listas[s.dataset.sec]));

  if (errorVisible && !faltante()) limpiarError();
}

document.getElementById('descuento').addEventListener('input', calcular);
document.getElementById('abono').addEventListener('input', calcular);
document.getElementById('saldoAplicado').addEventListener('input', calcular);
document.getElementById('usarTodoSaldo').addEventListener('click', () => {
  const saldoI = document.getElementById('saldoAplicado');
  saldoI.value = Math.min(parseInt(saldoI.dataset.disponible, 10) || 0, totales().total);
  calcular();
});

const filasCuotas  = document.getElementById('filasCuotas');
const numCuotasEl  = document.getElementById('numCuotas');
const ayudaCuotas  = document.getElementById('ayudaCuotas');
const numCuotas    = () => parseInt(numCuotasEl.querySelector('input:checked').value, 10);
const fijarNumCuotas = n => { numCuotasEl.querySelector('input[value="' + n + '"]').checked = true; };

function sumarDias(dias) {
  const d = new Date();
  d.setDate(d.getDate() + dias);
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

function pintarFilasCuotas() {
  const n = numCuotas();
  let html = '';
  for (let i = 0; i < n - 1; i++) {
    html +=
      '<div class="cuota">' +
        '<h3 class="cuota-tit">Cuota ' + (i + 1) + '</h3>' +
        '<div class="campo"><label for="cuotaMonto' + i + '">Valor<span class="visualmente-oculto"> de la cuota ' + (i + 1) + '</span></label>' +
          '<input type="number" id="cuotaMonto' + i + '" name="cuota_monto[]" min="0" step="500" value="0" class="montoCuota"></div>' +
        '<div class="campo"><label for="cuotaFecha' + i + '">Fecha<span class="visualmente-oculto"> de la cuota ' + (i + 1) + '</span></label>' +
          '<input type="date" id="cuotaFecha' + i + '" name="cuota_vence[]" class="venceCuota" value="' + sumarDias(30 * i) + '"></div>' +
      '</div>';
  }
  const rotuloUltima = n === 1 ? 'Pago único' : ('Cuota ' + n);
  const fechaDefecto = n === 1 ? sumarDias(15) : sumarDias(30 * (n - 1));
  html +=
    '<div class="cuota">' +
      '<h3 class="cuota-tit">' + rotuloUltima + (n > 1 ? ' <small>se calcula sola</small>' : '') + '</h3>' +
      '<div class="campo"><span class="etq">Valor<span class="visualmente-oculto"> de ' + (n === 1 ? 'el pago' : 'la cuota ' + n) + '</span></span>' +
        '<div class="valor-calculado"><strong id="verUltimaCuota">$0</strong></div></div>' +
      '<div class="campo"><label for="cuotaFechaUltima">Fecha<span class="visualmente-oculto"> de ' + (n === 1 ? 'el pago' : 'la cuota ' + n) + '</span></label>' +
        '<input type="date" id="cuotaFechaUltima" name="cuota_vence[]" class="venceCuota" value="' + fechaDefecto + '"></div>' +
    '</div>';
  filasCuotas.innerHTML = html;
  filasCuotas.querySelectorAll('.montoCuota').forEach(i => i.addEventListener('input', calcular));
  // "1 cuota" es para quien paga todo junto pero en una fecha futura (su día de pago): queda
  // pendiente hasta que se marque el pago, a diferencia de "De contado", que se da por pagado ese día.
  ayudaCuotas.textContent = n === 1
    ? 'Un solo pago, en la fecha que elijas: queda pendiente hasta que marques el pago, y se ve como vencido si no paga a tiempo.'
    : '';
  ayudaCuotas.hidden = n !== 1;
  calcular();
}

numCuotasEl.addEventListener('change', pintarFilasCuotas);

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
const modoCliente     = () => document.querySelector('input[name="modoCliente"]:checked').value;

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
  calcular();
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

clienteInput.addEventListener('input', calcular);

/* ------------- Secciones: en el celular se abre una a la vez ------------- */
const celular   = matchMedia('(max-width: 699px)');
const secciones = [...document.querySelectorAll('.seccion')];
let abierta = 'productos';

function aplicarAcordeon() {
  secciones.forEach(s => {
    // En tablet y escritorio todas están abiertas y el encabezado no se toca.
    const abre = !celular.matches || abierta === s.dataset.sec;
    const cab  = s.querySelector('.sec-cab');
    s.classList.toggle('cerrada', !abre);
    cab.disabled = !celular.matches;
    cab.setAttribute('aria-expanded', String(abre));
  });
}
function abrirSeccion(id) { abierta = id; aplicarAcordeon(); }

document.querySelectorAll('.sec-cab').forEach(cab =>
  cab.addEventListener('click', () => {
    const id = cab.closest('.seccion').dataset.sec;
    abrirSeccion(abierta === id ? null : id);
  })
);
celular.addEventListener('change', aplicarAcordeon);

/* ------------- Hoja con el resumen completo (celular) ------------- */
const hoja = document.getElementById('hojaResumen');
document.getElementById('abrirResumen').addEventListener('click', () => hoja.showModal());
hoja.addEventListener('click', e => {
  if (e.target === hoja || e.target.closest('[data-cerrar-hoja]')) hoja.close();
});

/* ------------- Lo que falta para generar la factura ------------- */
let errorVisible = null;

function faltante() {
  if (!items.length) {
    return { sec: 'productos', error: 'errProductos', campo: buscar, mensaje: 'Agrega al menos un producto para generar la factura.' };
  }
  if (modoCliente() === 'existente' && !clienteInput.value) {
    return { sec: 'cliente', error: 'errCliente', campo: clienteSel, mensaje: 'Elige el cliente para generar la factura, o cambia a "Nuevo".' };
  }
  if (modoCliente() === 'nueva' && !clienteInput.value.trim()) {
    return { sec: 'cliente', error: 'errCliente', campo: clienteInput, mensaje: 'Escribe el nombre del cliente para generar la factura.' };
  }
  return null;
}

function limpiarError() {
  document.querySelectorAll('.aviso-campo').forEach(el => { el.hidden = true; });
  errorVisible = null;
}

formulario.addEventListener('submit', e => {
  const falta = faltante();
  if (!falta) return;
  e.preventDefault();
  limpiarError();
  const aviso = document.getElementById(falta.error);
  aviso.textContent = falta.mensaje;
  aviso.hidden = false;
  errorVisible = falta.error;
  abrirSeccion(falta.sec);
  falta.campo.focus();
  aviso.scrollIntoView({ block: 'nearest' });
});

// Si el navegador frena el envío por un campo inválido que quedó en una sección cerrada, la abre.
formulario.addEventListener('invalid', e => {
  const s = e.target.closest('.seccion');
  if (s) abrirSeccion(s.dataset.sec);
}, true);

/* ------------- Precarga desde un pedido del catálogo público -------------
   Los montos y fechas de cuotas NUNCA vienen calculados de antemano: eso se
   negocia de verdad con la clienta por WhatsApp. Lo único que se precarga
   es la propuesta (contado o cuántas cuotas prefiere) como punto de
   partida, y si ya te pagó o no — por defecto se asume que NO, porque
   ningún pedido del catálogo trae plata: si no ha pagado, se arma como
   "1 cuota" (pago pendiente, no "de contado") para que quede visible en
   Cartera hasta que la marques como cobrada. */
aplicarModoCliente(modoCliente());

if (PEDIDO_DESDE) {
  (PEDIDO_DESDE.lineas || []).forEach(l => {
    const prod = PRODUCTOS.find(p => p.sku === l.sku);
    if (prod) {
      items.push({ sku: prod.sku, nombre: prod.nombre, marca: prod.marca, precio: prod.precio, costo: prod.costo, cant: l.cant });
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
      fijarNumCuotas(nPreferido);
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
aplicarAcordeon();
</script>

</body>
</html>
