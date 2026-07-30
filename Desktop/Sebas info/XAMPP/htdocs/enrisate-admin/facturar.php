<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/facturas.php';
require_once __DIR__ . '/includes/clientes.php';

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

                // Saldo a favor de la clienta aplicado a esta cuenta (solo clienta
                // existente, y nunca más de lo que tiene ni más que el total).
                $clienteId       = trim((string) ($_POST['cliente_id'] ?? ''));
                $clienteRec      = $clienteId !== '' ? cliente_por_id($clienteId) : null;
                $saldoDisponible = $clienteRec ? (int) ($clienteRec['saldo_favor'] ?? 0) : 0;
                $saldoAplicado   = max(0, min($saldoDisponible, $total, (int) ($_POST['saldo_aplicado'] ?? 0)));

                $abono     = 0;
                $cuotas    = [];
                $fechaPago = null;

                if ($plan === 'cuotas' && $total > 1) {
                    // Abono en efectivo + saldo a favor son plata ya cubierta contra el
                    // precio completo — no son descuento, no tocan $total ni $margen.
                    // Solo reducen lo que queda por repartir en cuotas.
                    $abono = max(0, min($total - $saldoAplicado, (int) ($_POST['abono'] ?? 0)));
                    $resto = $total - $saldoAplicado - $abono;

                    if ($resto <= 1) {
                        $cuotas[] = [
                            'monto'  => max(0, $resto),
                            'vence'  => date('Y-m-d'),
                            'abonos' => [],
                        ];
                    } else {
                        $numCuotas    = max(2, min(4, (int) ($_POST['num_cuotas'] ?? 2)));
                        $montosPost   = is_array($_POST['cuota_monto'] ?? null) ? $_POST['cuota_monto'] : [];
                        $vencesPost   = is_array($_POST['cuota_vence'] ?? null) ? $_POST['cuota_vence'] : [];
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

                    $fechaPago = trim((string) ($_POST['fecha_pago'] ?? ''));
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
                    'nota'           => trim((string) ($_POST['nota'] ?? '')),
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

                header('Location: factura.php?n=' . urlencode($numero));
                exit;
            }
        }
    }
}

$catalogo = catalogo();

$clientesExistentes = leer_clientes();
usort($clientesExistentes, fn($a, $b) => $a['nombre'] <=> $b['nombre']);

$token = csrf_token();

$tituloPagina = 'Nueva cuenta';
$barraVolver  = true;
require __DIR__ . '/includes/cabecera.php';
?>

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
          <div class="buscador">
            <input type="text" id="buscarProd" placeholder="Escribe el producto…"
                   autocomplete="off" role="combobox" aria-expanded="false"
                   aria-autocomplete="list" aria-controls="sugerencias" aria-label="Buscar producto">
            <ul id="sugerencias" class="sugerencias" role="listbox" hidden></ul>
          </div>
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

        <div class="opciones">
          <label class="opcion">
            <input type="radio" name="modoCliente" value="existente" <?= $clientesExistentes ? 'checked' : '' ?>>
            <span>Clienta existente</span>
          </label>
          <label class="opcion">
            <input type="radio" name="modoCliente" value="nueva" <?= !$clientesExistentes ? 'checked' : '' ?>>
            <span>Nueva clienta</span>
          </label>
        </div>

        <div class="campo" id="campoClienteSel">
          <label for="clienteSel">Elige de la lista</label>
          <select id="clienteSel" <?= !$clientesExistentes ? 'disabled' : '' ?>>
            <option value="">Selecciona una clienta…</option>
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
            <p class="pista">Todavía no hay clientas guardadas — usa "Nueva clienta".</p>
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
            <p class="pista">Lo que la clienta ya te pagó. No es descuento: no baja la ganancia.</p>
          </div>
          <div class="campo">
            <label for="numCuotas">Número de cuotas</label>
            <select id="numCuotas" name="num_cuotas">
              <option value="2">2 cuotas</option>
              <option value="3">3 cuotas</option>
              <option value="4">4 cuotas</option>
            </select>
          </div>
          <div id="filasCuotas"></div>
        </div>

        <div class="campo">
          <label for="nota">Nota para la clienta</label>
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
  html +=
    '<p class="pista">Cuota ' + n + ' (última): <strong id="verUltimaCuota">$0</strong></p>' +
    '<div class="campo">' +
      '<label>Fecha de la cuota ' + n + '</label>' +
      '<input type="date" name="cuota_vence[]" class="venceCuota" value="' + sumarDias(30 * (n - 1)) + '">' +
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
    alert('Elige una clienta de la lista, o cambia a "Nueva clienta".');
  }
});

pintar();
</script>

</body>
</html>
