/* --- Fotos que no llegan: se vuelven a pedir solas --------------------------
   Una foto puede fallar por un corte momentáneo de la red del celular (sobre
   todo justo al abrir la página, cuando se piden muchas cosas a la vez). El
   navegador no reintenta y deja el cuadrito roto con el nombre del producto
   hasta que la clienta recarga. Aquí se reintenta hasta 3 veces, con una
   espera creciente, pidiendo la foto con un parámetro nuevo (?r=1, ?r=2...)
   para saltarse cualquier copia dañada que haya quedado guardada.
   Las que fallaron ANTES de que corriera este script se revisan al arrancar;
   las que fallen después, con el evento "error" (no sube por el árbol, por eso
   se escucha en la fase de captura). */
const REINTENTOS_FOTO = [800, 2500, 6000];

function reintentarFoto(img) {
  if (img.dataset.rendida === '1') return;
  const intento = parseInt(img.dataset.intentos || '0', 10);
  if (intento >= REINTENTOS_FOTO.length) { img.dataset.rendida = '1'; return; }
  img.dataset.intentos = String(intento + 1);
  if (!img.dataset.srcOriginal) img.dataset.srcOriginal = img.getAttribute('src');
  setTimeout(() => {
    const base = img.dataset.srcOriginal;
    img.src = base + (base.includes('?') ? '&' : '?') + 'r=' + (intento + 1);
  }, REINTENTOS_FOTO[intento]);
}

document.addEventListener('error', e => {
  if (e.target instanceof HTMLImageElement) reintentarFoto(e.target);
}, true);

document.querySelectorAll('img').forEach(img => {
  if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) reintentarFoto(img);
});

const TEXTO_ENVIAR = 'Enviar por WhatsApp';
const cop = n => '$' + Math.round(n).toLocaleString('es-CO');

const pedido = [];

const fab         = document.getElementById('fabCarrito');
const fabZona     = document.getElementById('fabZona');
const fabDto      = document.getElementById('fabDto');
const fabInsignia = document.getElementById('fabInsignia');
const fabTotal    = document.getElementById('fabTotal');
const fondo       = document.getElementById('carritoFondo');
const hoja        = document.getElementById('hojaCarrito');
const cerrarBtn   = document.getElementById('cerrarCarrito');
const listaPedido = document.getElementById('listaPedido');
const hojaTotal   = document.getElementById('hojaTotal');
const hojaResumen       = document.getElementById('hojaResumen');
const hojaSubtotal      = document.getElementById('hojaSubtotal');
const hojaDescEtiqueta  = document.getElementById('hojaDescEtiqueta');
const hojaDescuento     = document.getElementById('hojaDescuento');
const hojaPromoPista    = document.getElementById('hojaPromoPista');
const hojaPromoTexto    = document.getElementById('hojaPromoTexto');
const hojaPromoBarra    = document.getElementById('hojaPromoBarra');
const rielTotal         = document.getElementById('rielTotal');
const rielAhorro        = document.getElementById('rielAhorro');
const pieAhorro         = document.getElementById('pieAhorro');
const datosTotal        = document.getElementById('datosTotal');
const pasoAtras         = document.getElementById('pasoAtras');
const pasoSigue         = document.getElementById('pasoSigue');
const pasosCuerpo       = document.getElementById('pasosCuerpo');
const revLineas         = document.getElementById('revLineas');
const revSubtotalFila   = document.getElementById('revSubtotalFila');
const revDescFila       = document.getElementById('revDescFila');
const revSubtotal       = document.getElementById('revSubtotal');
const revDescEtiqueta   = document.getElementById('revDescEtiqueta');
const revDescuento      = document.getElementById('revDescuento');
const revTotal          = document.getElementById('revTotal');
const revPago           = document.getElementById('revPago');
const revNombre         = document.getElementById('revNombre');
const revTelefono       = document.getElementById('revTelefono');

const cpNombre          = document.getElementById('cpNombre');
const cpTelefono        = document.getElementById('cpTelefono');
const cpSitioWeb        = document.getElementById('cpSitioWeb');
const cpBloquePlan      = document.getElementById('cpBloquePlan');
const cpBloqueNumCuotas = document.getElementById('cpBloqueNumCuotas');
const cpNumCuotas       = document.getElementById('cpNumCuotas');
const cpFormError       = document.getElementById('cpFormError');
const cpConfirmar       = document.getElementById('cpConfirmarPedido');
const cpEnviarTexto     = document.getElementById('cpEnviarTexto');

let ultimoFoco = null;

function subtotalPedido() {
  return pedido.reduce((s, i) => s + i.precio * i.cant, 0);
}

function cantidadTotal() {
  return pedido.reduce((s, i) => s + i.cant, 0);
}

/* Promoción: PROMO_PCT % en toda la compra desde PROMO_MIN unidades. Misma cuenta que
   descuento_promo() en PHP (el servidor la recalcula y es quien manda). */
function descuentoPedido() {
  const sub = subtotalPedido();
  if (sub <= 0 || cantidadTotal() < PROMO_MIN) return 0;
  return Math.round(sub * PROMO_PCT / 100);
}

/* Lo que de verdad paga la clienta: ya con el descuento. Es el que ve el botón flotante, el que
   decide si alcanza para cuotas y el que va en el mensaje de WhatsApp. */
function totalPedido() {
  return subtotalPedido() - descuentoPedido();
}

function sincronizarBotonAgregar(boton, enPedido) {
  boton.classList.toggle('en-pedido', enPedido);
  const etiqueta = enPedido ? 'Ya está en tu pedido' : 'Agregar al pedido';
  boton.setAttribute('aria-label', etiqueta);
  boton.title = etiqueta;
  const texto = boton.querySelector('.cp-agregar-texto');
  if (texto) texto.textContent = enPedido ? 'En el pedido' : 'Agregar';
}

function sincronizarTarjeta(sku) {
  const tarjeta = document.querySelector('.cp-tarjeta[data-sku="' + CSS.escape(sku) + '"]');
  if (!tarjeta) return;
  const enPedido = pedido.some(i => i.sku === sku);
  tarjeta.classList.toggle('en-pedido', enPedido);
  const boton = tarjeta.querySelector('.cp-boton-agregar');
  if (boton) sincronizarBotonAgregar(boton, enPedido);
}

/* El botón "Agregar" de la ficha de producto es el mismo componente que
   el de la tarjeta, pero vive aparte (la ficha se rellena con datos de
   la tarjeta que la abrió, no es la tarjeta misma) — se sincroniza solo
   cuando la ficha está mostrando justo ese sku. */
function sincronizarBotonProducto(sku) {
  if (productoActualSku !== sku) return;
  sincronizarBotonAgregar(productoAgregarBtn, pedido.some(i => i.sku === sku));
}

/* Agregar al pedido: una sola acción, sin selector de cantidad (la
   cantidad se ajusta en el carrito). La usan tanto el botón de la
   tarjeta como el de la ficha de producto. */
function fotoDeTarjeta(sku) {
  const img = document.querySelector('.cp-tarjeta[data-sku="' + CSS.escape(sku) + '"] .cp-foto');
  return img ? (img.dataset.srcOriginal || img.getAttribute('src') || '') : '';
}

function agregarAlPedido(sku, nombre, precio, origen) {
  const ya = pedido.find(i => i.sku === sku);
  if (ya) {
    ya.cant = Math.min(99, ya.cant + 1);
  } else {
    pedido.push({ sku, nombre, precio, cant: 1, foto: fotoDeTarjeta(sku) });
  }
  sincronizarTarjeta(sku);
  sincronizarBotonProducto(sku);
  actualizarCarrito();
  mostrarTicket();
  animarGota(origen);
}

function quitarDelPedido(sku) {
  const idx = pedido.findIndex(i => i.sku === sku);
  if (idx === -1) return;
  pedido.splice(idx, 1);
  sincronizarTarjeta(sku);
  sincronizarBotonProducto(sku);
  actualizarCarrito();
}

function cambiarCantidadPedido(sku, delta) {
  const item = pedido.find(i => i.sku === sku);
  if (!item) return;
  item.cant = Math.max(1, Math.min(99, item.cant + delta));
  actualizarCarrito();
}

function renderLista() {
  listaPedido.innerHTML = '';
  pedido.forEach(i => {
    const fila = document.createElement('div');
    fila.className = 'cp-lista-fila';

    const foto = document.createElement('div');
    foto.className = 'cp-lista-foto';
    if (i.foto) {
      const img = document.createElement('img');
      img.src = i.foto;
      img.alt = '';
      img.decoding = 'async';
      foto.appendChild(img);
    } else {
      foto.textContent = i.nombre.trim().charAt(0).toUpperCase();
    }

    const info = document.createElement('div');
    info.className = 'cp-lista-info';
    const nombre = document.createElement('span');
    nombre.className = 'cp-lista-nombre';
    nombre.textContent = i.nombre;
    const unidad = document.createElement('span');
    unidad.className = 'cp-lista-unidad';
    unidad.textContent = cop(i.precio) + ' c/u';

    const stepper = document.createElement('div');
    stepper.className = 'cp-stepper-mini';

    const restar = document.createElement('button');
    restar.type = 'button';
    restar.className = 'cp-paso';
    restar.textContent = '−';
    restar.setAttribute('aria-label', 'Restar uno a ' + i.nombre);
    restar.addEventListener('click', () => cambiarCantidadPedido(i.sku, -1));

    const cant = document.createElement('span');
    cant.className = 'cp-cant-valor';
    cant.textContent = String(i.cant);

    const sumar = document.createElement('button');
    sumar.type = 'button';
    sumar.className = 'cp-paso';
    sumar.textContent = '+';
    sumar.setAttribute('aria-label', 'Sumar uno a ' + i.nombre);
    sumar.addEventListener('click', () => cambiarCantidadPedido(i.sku, 1));

    stepper.append(restar, cant, sumar);
    info.append(nombre, unidad, stepper);

    const lado = document.createElement('div');
    lado.className = 'cp-lista-lado';
    const precio = document.createElement('span');
    precio.className = 'cp-lista-precio';
    precio.textContent = cop(i.precio * i.cant);

    const quitar = document.createElement('button');
    quitar.type = 'button';
    quitar.className = 'cp-lista-quitar';
    quitar.setAttribute('aria-label', 'Quitar ' + i.nombre + ' del pedido');
    quitar.textContent = '×';
    quitar.addEventListener('click', () => quitarDelPedido(i.sku));

    lado.append(precio, quitar);
    fila.append(foto, info, lado);
    listaPedido.appendChild(fila);
  });
}

/* Subtotal y descuento (solo cuando ya aplica) o, si todavía faltan unidades, cuánto falta
   (con una barra que avanza hacia el mínimo). */
function renderResumen() {
  const faltan = PROMO_MIN - cantidadTotal();
  const desc = descuentoPedido();
  hojaResumen.hidden = desc === 0;
  if (desc > 0) {
    hojaSubtotal.textContent = cop(subtotalPedido());
    hojaDescEtiqueta.textContent = 'Descuento ' + PROMO_PCT + ' %';
    hojaDescuento.textContent = '−' + cop(desc);
  }
  if (faltan > 0) {
    hojaPromoTexto.textContent = 'Agrega ' + faltan + (faltan === 1 ? ' producto más' : ' productos más')
      + ' y llevas ' + PROMO_PCT + ' % de descuento en toda la compra.';
    hojaPromoPista.classList.remove('lograda');
  } else {
    hojaPromoTexto.textContent = '¡Listo! Tienes ' + PROMO_PCT + ' % de descuento en toda la compra.';
    hojaPromoPista.classList.add('lograda');
  }
  hojaPromoBarra.style.width = Math.min(100, Math.round(cantidadTotal() / PROMO_MIN * 100)) + '%';
  hojaPromoPista.hidden = false;
}

function actualizarInsignia() {
  const cant = cantidadTotal();
  fabInsignia.textContent = cant > 99 ? '99+' : String(cant);
  fabInsignia.classList.remove('cp-fab-insignia-pulso');
  void fabInsignia.offsetWidth; // reinicia la animación de "pop"
  fabInsignia.classList.add('cp-fab-insignia-pulso');
}

function movimientoReducido() {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/* El total del botón flotante sale en un "ticket" unos segundos al agregar algo. */
let ticketTimer = null;
function mostrarTicket(ms = 3600) {
  fabZona.classList.add('ver');
  clearTimeout(ticketTimer);
  ticketTimer = setTimeout(() => fabZona.classList.remove('ver'), ms);
}

/* Una gota rosa viaja del botón "Agregar" (de la tarjeta) al carrito, que rebota al recibirla.
   No se hace con la ficha abierta (el botón queda detrás del fondo oscuro) ni con "reducir movimiento". */
function animarGota(origen) {
  if (!origen || movimientoReducido() || !hojaProducto.hidden || fabZona.hidden) return;
  const o = origen.getBoundingClientRect();
  const f = fab.getBoundingClientRect();
  if (!o.width || !f.width) return;
  const gota = document.createElement('span');
  gota.className = 'cp-gota';
  gota.setAttribute('aria-hidden', 'true');
  document.body.appendChild(gota);
  const x0 = o.left + o.width / 2 - 10, y0 = o.top + o.height / 2 - 10;
  const x1 = f.left + f.width / 2 - 10, y1 = f.top + f.height / 2 - 10;
  const vuelo = gota.animate([
    { transform: 'translate(' + x0 + 'px,' + y0 + 'px) scale(1)', offset: 0 },
    { transform: 'translate(' + (x0 + x1) / 2 + 'px,' + (Math.min(y0, y1) - 50) + 'px) scale(1.1)', offset: .5 },
    { transform: 'translate(' + x1 + 'px,' + y1 + 'px) scale(.4)', opacity: .9, offset: 1 },
  ], { duration: 620, easing: 'cubic-bezier(.4,.1,.6,1)' });
  vuelo.onfinish = () => {
    gota.remove();
    fab.classList.remove('rebota');
    void fab.offsetWidth;                      // reinicia la animación
    fab.classList.add('rebota');
  };
  vuelo.oncancel = () => gota.remove();
}

function actualizarCarrito() {
  const hayPedido = pedido.length > 0;
  const eraOculto = fabZona.hidden;
  fabZona.hidden = !hayPedido;
  if (hayPedido && eraOculto && !movimientoReducido()) {
    fabZona.classList.remove('entra');
    void fabZona.offsetWidth;
    fabZona.classList.add('entra');
  }
  if (!hayPedido) fabZona.classList.remove('ver');
  // El pie reserva espacio de más solo mientras el botón flotante está
  // realmente ahí (con carrito vacío, que es la mayoría de las visitas,
  // no hace falta ese aire de sobra al final de la página).
  document.body.classList.toggle('cp-con-carrito', hayPedido);

  if (!hayPedido) {
    cerrarCarrito();
    return;
  }

  actualizarInsignia();
  fabTotal.textContent = cop(totalPedido());
  const dto = descuentoPedido();
  fabDto.hidden = dto === 0;
  fabDto.textContent = '−' + PROMO_PCT + ' % aplicado';
  const unidades = cantidadTotal();
  fab.setAttribute('aria-label', 'Ver pedido: ' + unidades + (unidades === 1 ? ' producto' : ' productos')
    + ', total ' + cop(totalPedido()) + (dto > 0 ? ', con ' + PROMO_PCT + ' % de descuento' : ''));
  renderLista();
  renderResumen();
  const total = cop(totalPedido());
  hojaTotal.textContent = total;
  rielTotal.textContent = total;
  datosTotal.textContent = total;
  const ahorro = descuentoPedido();
  [rielAhorro, pieAhorro].forEach(el => {
    el.hidden = ahorro === 0;
    el.textContent = 'Ahorras ' + cop(ahorro);
  });
  if (!hoja.hidden) renderPasos();

  // Si la hoja ya está abierta y el carrito cambió, refleja si ahora
  // sí (o ya no) alcanza el mínimo para cuotas.
  if (!hoja.hidden) actualizarBloquePlan();
}

function abrirCarrito() {
  if (!pedido.length) return;
  ultimoFoco = document.activeElement;
  fondo.hidden = false;
  hoja.hidden = false;
  document.body.classList.add('cp-bloqueo-scroll');
  requestAnimationFrame(() => {
    fondo.classList.add('visible');
    hoja.classList.add('abierta');
  });
  fab.setAttribute('aria-expanded', 'true');
  fabZona.classList.add('abierta');
  actualizarBloquePlan();
  cpFormError.hidden = true;
  irAPaso(1, { sinFoco: true });
  cerrarBtn.focus();
}

function cerrarCarrito() {
  if (hoja.hidden) return;
  fondo.classList.remove('visible');
  hoja.classList.remove('abierta');
  if (hojaProducto.hidden) document.body.classList.remove('cp-bloqueo-scroll');
  fab.setAttribute('aria-expanded', 'false');
  fabZona.classList.remove('abierta');

  let hecho = false;
  const finalizar = () => {
    if (hecho) return;
    hecho = true;
    fondo.hidden = true;
    hoja.hidden = true;
    hoja.removeEventListener('transitionend', finalizar);
  };
  hoja.addEventListener('transitionend', finalizar);
  setTimeout(finalizar, 350);

  if (ultimoFoco && document.contains(ultimoFoco)) ultimoFoco.focus();
}

function actualizarBloquePlan() {
  const alcanza = totalPedido() >= UMBRAL_CUOTAS;
  cpBloquePlan.hidden = !alcanza;
  if (!alcanza) {
    document.querySelector('input[name="cpPlan"][value="contado"]').checked = true;
    cpBloqueNumCuotas.hidden = true;
  }
}

/* --- Pasos: 1 Pedido · 2 Datos · 3 Confirmar --- */
let pasoActual = 1;
const PASOS = 3;

function datosContacto() {
  return { cliente: cpNombre.value.trim(), telefono: cpTelefono.value.replace(/\D+/g, '') };
}

/* Revisa nombre y WhatsApp; si falta algo, deja el aviso en el paso 2 y devuelve false. */
function contactoValido() {
  const { cliente, telefono } = datosContacto();
  if (!cliente) { mostrarErrorForm('Escribe tu nombre.'); cpNombre.focus(); return false; }
  if (telefono.length < 7) { mostrarErrorForm('Escribe un WhatsApp válido.'); cpTelefono.focus(); return false; }
  cpFormError.hidden = true;
  return true;
}

function planElegido() {
  return document.querySelector('input[name="cpPlan"]:checked').value;
}

function renderRevision() {
  revLineas.innerHTML = '';
  pedido.forEach(i => {
    const l = document.createElement('div');
    l.className = 'cp-rev-linea';
    const c = document.createElement('i');
    c.textContent = i.cant + '×';
    const n = document.createElement('span');
    n.textContent = i.nombre;
    const p = document.createElement('b');
    p.textContent = cop(i.precio * i.cant);
    l.append(c, n, p);
    revLineas.appendChild(l);
  });
  const desc = descuentoPedido();
  revSubtotalFila.hidden = revDescFila.hidden = desc === 0;
  revSubtotal.textContent = cop(subtotalPedido());
  revDescEtiqueta.textContent = 'Descuento ' + PROMO_PCT + ' %';
  revDescuento.textContent = '−' + cop(desc);
  revTotal.textContent = cop(totalPedido());
  const { cliente, telefono } = datosContacto();
  revPago.textContent = planElegido() === 'cuotas' && !cpBloquePlan.hidden
    ? 'A cuotas (' + (parseInt(cpNumCuotas.value, 10) || 2) + ')'
    : 'De contado';
  revNombre.textContent = cliente || '—';
  revTelefono.textContent = telefono || '—';
}

/* Pinta el paso actual: el panel visible, el riel de pasos y los botones del pie. */
function renderPasos() {
  hoja.dataset.paso = String(pasoActual);
  document.querySelectorAll('.cp-paso-panel').forEach(p => {
    p.hidden = Number(p.dataset.paso) !== pasoActual;
  });
  document.querySelectorAll('.cp-paso-tab').forEach(t => {
    const n = Number(t.dataset.paso);
    const li = t.parentElement;
    li.classList.toggle('actual', n === pasoActual);
    li.classList.toggle('hecho', n < pasoActual);
    t.querySelector('.cp-paso-num').textContent = n < pasoActual ? '✓' : String(n);
    if (n === pasoActual) t.setAttribute('aria-current', 'step'); else t.removeAttribute('aria-current');
  });
  pasoAtras.hidden = pasoActual === 1;
  pasoSigue.hidden = pasoActual === PASOS;
  cpConfirmar.hidden = pasoActual !== PASOS;
  if (pasoActual === PASOS) renderRevision();
}

function irAPaso(n, opciones = {}) {
  n = Math.max(1, Math.min(PASOS, n));
  // Para llegar a Confirmar hacen falta nombre y WhatsApp válidos.
  if (n === PASOS && !opciones.sinValidar) {
    if (!pedido.length) return;
    if (!contactoValido()) { n = 2; }
  }
  const cambio = n !== pasoActual;
  pasoActual = n;
  renderPasos();
  pasosCuerpo.scrollTop = 0;
  if (cambio && !opciones.sinFoco) {
    const titulo = document.getElementById('pasoTitulo' + n);
    if (titulo && !(n === 2 && !cpFormError.hidden)) titulo.focus({ preventScroll: true });
  }
}

pasoSigue.addEventListener('click', () => irAPaso(pasoActual + 1));
pasoAtras.addEventListener('click', () => irAPaso(pasoActual - 1));
document.querySelectorAll('.cp-paso-tab, .cp-cambiar').forEach(b =>
  b.addEventListener('click', () => irAPaso(Number(b.dataset.paso)))
);
// Enter dentro de los campos del paso 2 equivale a "Continuar".
[cpNombre, cpTelefono].forEach(c => c.addEventListener('keydown', e => {
  if (e.key === 'Enter') { e.preventDefault(); irAPaso(PASOS); }
}));

function mensajePedido(numero, plan) {
  let msg = 'Hola! Quiero pedir esto de ' + NOMBRE_NEGOCIO + ':\n\n';
  pedido.forEach(i => {
    msg += '• ' + i.cant + ' x ' + i.nombre + ' — ' + cop(i.precio * i.cant) + '\n';
  });
  const desc = descuentoPedido();
  if (desc > 0) {
    msg += '\nSubtotal: ' + cop(subtotalPedido()) + '\n';
    msg += 'Descuento ' + PROMO_PCT + ' % (' + PROMO_MIN + ' o más productos): −' + cop(desc) + '\n';
  }
  msg += (desc > 0 ? '' : '\n') + 'Total aproximado: ' + cop(totalPedido()) + '\n';
  msg += plan === 'cuotas' ? 'Prefiero pagar a cuotas.\n' : 'Prefiero pagar de contado.\n';
  if (numero) msg += '\nPedido ' + numero + ' (este es mi pedido, todavía no he pagado nada)';
  return msg;
}

fab.addEventListener('click', abrirCarrito);
cerrarBtn.addEventListener('click', cerrarCarrito);
fondo.addEventListener('click', cerrarCarrito);
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && !hoja.hidden) cerrarCarrito();
});

document.querySelectorAll('input[name="cpPlan"]').forEach(r =>
  r.addEventListener('change', e => {
    cpBloqueNumCuotas.hidden = e.target.value !== 'cuotas';
  })
);

function mostrarErrorForm(texto) {
  cpFormError.textContent = texto;
  cpFormError.hidden = false;
}

/* Un error del servidor o de la red se muestra en el paso 2 (ahí vive el aviso), así que se vuelve a él. */
function mostrarErrorEnvio(texto) {
  mostrarErrorForm(texto);
  irAPaso(2, { sinFoco: true });
}

function restaurarBotonEnviar() {
  cpConfirmar.disabled = false;
  cpEnviarTexto.textContent = TEXTO_ENVIAR;
}

cpConfirmar.addEventListener('click', async () => {
  // Defensa extra: si por cualquier motivo faltan los datos, vuelve al paso 2 con el aviso.
  if (!contactoValido()) { irAPaso(2, { sinFoco: true }); return; }
  const { cliente, telefono } = datosContacto();
  const plan = planElegido();

  cpConfirmar.disabled = true;
  cpEnviarTexto.textContent = 'Enviando...';
  cpFormError.hidden = true;

  try {
    const resp = await fetch('pedido-publico.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        cliente,
        telefono,
        plan,
        num_cuotas: parseInt(cpNumCuotas.value, 10) || 2,
        items: pedido.map(i => ({ sku: i.sku, cant: i.cant })),
        sitio_web: cpSitioWeb.value,
      }),
    });
    const data = await resp.json();

    if (!resp.ok || !data.ok) {
      mostrarErrorEnvio(data.error || 'No se pudo enviar el pedido. Intenta de nuevo.');
      restaurarBotonEnviar();
      return;
    }

    const msg = mensajePedido(data.numero, data.plan);
    window.open('https://wa.me/' + WHATSAPP + '?text=' + encodeURIComponent(msg), '_blank', 'noopener');

    pedido.length = 0;
    document.querySelectorAll('.cp-tarjeta.en-pedido').forEach(t => {
      t.classList.remove('en-pedido');
      const b = t.querySelector('.cp-boton-agregar');
      if (b) sincronizarBotonAgregar(b, false);
    });
    if (productoActualSku) sincronizarBotonAgregar(productoAgregarBtn, false);
    cpNombre.value = '';
    cpTelefono.value = '';
    restaurarBotonEnviar();
    actualizarCarrito(); // pedido vacío: oculta el botón y cierra la hoja
  } catch (err) {
    mostrarErrorEnvio('No hay conexión. Revisa tu internet e intenta de nuevo.');
    restaurarBotonEnviar();
  }
});

/* --- Agregar desde la tarjeta: una sola acción, sin selector de cantidad.
       La cantidad se ajusta en el carrito. --- */
document.querySelectorAll('.cp-tarjeta').forEach(tarjeta => {
  const sku    = tarjeta.dataset.sku;
  const nombre = tarjeta.dataset.nombre;
  const precio = +tarjeta.dataset.precio;
  const boton  = tarjeta.querySelector('.cp-boton-agregar');
  if (!boton) return;

  boton.addEventListener('click', () => agregarAlPedido(sku, nombre, precio, boton));
});

/* --- Ficha de producto: foto + descripción al tocar la tarjeta (en
       cualquier parte que no sea el botón "Agregar"). La descripción es
       la misma que se edita en Productos; si todavía no tiene, se avisa
       en vez de dejar la ficha vacía. --- */
const productoFondo       = document.getElementById('productoFondo');
const hojaProducto        = document.getElementById('hojaProducto');
const cerrarProductoBtn   = document.getElementById('cerrarProducto');
const hojaProductoTitulo  = document.getElementById('hojaProductoTitulo');
const productoCuerpo      = document.getElementById('productoCuerpo');
const productoDetalle     = document.getElementById('productoDetalle');
const productoEscena      = document.getElementById('productoEscena');
const productoFoto        = document.getElementById('productoFoto');
const productoChips       = document.getElementById('productoChips');
const productoDescripcion = document.getElementById('productoDescripcion');
const productoMas         = document.getElementById('productoMas');
const productoPrecio      = document.getElementById('productoPrecio');
const productoAgregarBtn  = document.getElementById('productoAgregarBtn');

let ultimoFocoProducto = null;
// Qué producto muestra la ficha ahora mismo — el botón "Agregar" de acá
// abajo lo necesita para saber a cuál sku/nombre/precio agregar.
let productoActualSku    = null;
let productoActualNombre = '';
let productoActualPrecio = 0;
let productoDescExpandida = false;
let productoCierre = 0;   // cuenta los cierres: un cierre viejo no puede esconder una ficha recién abierta

/* Degradado al pie del área que se desliza: aparece solo si queda texto POR VER. Se mide hasta
   donde termina el texto (sin el margen de abajo): antes se contaba también ese margen y el
   degradado oscurecía la última línea aunque ya se viera todo. Según el tamaño de pantalla,
   el que se desliza es el cuerpo entero (celular de pie, panel lateral) o solo el detalle
   (celular acostado, dos columnas): se revisan los dos. */
function restanteFicha(el) {
  const pie = parseFloat(getComputedStyle(productoDetalle).paddingBottom) || 0;
  return (el.scrollHeight - pie) - el.scrollTop - el.clientHeight;
}
function pistaFicha() {
  const linea = parseFloat(getComputedStyle(productoDescripcion).lineHeight) || 27;
  [productoCuerpo, productoDetalle].forEach(el => {
    const restante = restanteFicha(el);
    const activo = el.scrollHeight > el.clientHeight && restante >= linea * 0.9;
    el.classList.toggle('mas', activo);
    // sombra proporcional: con poco texto oculto, una sombra corta
    if (activo) el.style.setProperty('--sombra', Math.min(44, Math.max(20, restante * 0.7)) + 'px');
  });
}

/* Si falta muy poco para que todo el texto quepa (hasta ~2 líneas), se gana ese espacio en vez
   de dejar texto a medias bajo una sombra: la foto baja un poco (hoja y panel) o el detalle
   aprieta sus márgenes (dos columnas). Si no alcanza, queda el degradado normal. */
function ajustarFicha() {
  productoEscena.style.removeProperty('--quita');
  productoDetalle.classList.remove('apretada');
  const dos = getComputedStyle(productoCuerpo).display === 'contents';
  const el = dos ? productoDetalle : productoCuerpo;
  const falta = restanteFicha(el) + el.scrollTop;
  if (falta <= 0 || falta > 64) return;
  if (dos) {
    productoDetalle.classList.add('apretada');
  } else if (productoEscena.offsetHeight - falta - 2 >= 200) {
    productoEscena.style.setProperty('--quita', Math.ceil(falta + 2) + 'px');
  }
}

/* "Leer más": el botón y el recorte solo existen si el recorte esconde de verdad más de una
   línea y media. Antes el degradado del recorte se aplicaba siempre, aunque el texto cupiera
   entero, y apagaba la última línea de las descripciones cortas. El alto del recorte (--recorte)
   depende del tamaño de pantalla (celular, tablet o escritorio en dos columnas), por eso se
   vuelve a revisar al girar o cambiar el tamaño de la ventana. */
function revisarLeerMas() {
  productoDescripcion.classList.add('recortada');            // se mide con el recorte puesto
  const linea = parseFloat(getComputedStyle(productoDescripcion).lineHeight) || 27;
  const oculto = productoDescripcion.scrollHeight - productoDescripcion.clientHeight;
  const haceFalta = oculto > linea * 1.5;
  if (!haceFalta && productoDescExpandida) {                // ya no hace falta (p. ej. giró el celular)
    productoDescExpandida = false;
    productoMas.textContent = 'Leer más';
    productoMas.setAttribute('aria-expanded', 'false');
  }
  productoMas.hidden = !haceFalta;
  productoDescripcion.classList.toggle('recortada', haceFalta && !productoDescExpandida);
  ajustarFicha();
  pistaFicha();
}

/* --- Descripción justificada con guiones ---
   El texto de la ficha va justificado (parejo a los dos lados). Sin separar las palabras largas
   quedan huecos grandes entre palabras en las columnas angostas, así que a cada palabra de 7 letras
   o más se le insertan guiones suaves (U+00AD) entre sílabas: el navegador solo los muestra
   cuando una palabra se corta al final de la línea. No depende del diccionario de cada navegador
   (hyphens: auto no existe igual en todos). La separación es aproximada, pensada para el español
   de estas descripciones: diptongos juntos, hiatos separados, grupos pr/br/tr/pl/bl/ch/ll/rr... juntos. */
function silabasEs(palabra) {
  const V = 'aeiouáéíóúüAEIOUÁÉÍÓÚÜ', FUERTES = 'aeoáéíóúAEOÁÉÍÓÚ', TILDE_DEBIL = 'íúÍÚ';
  const grupos = ['pr', 'br', 'tr', 'dr', 'cr', 'gr', 'fr', 'pl', 'bl', 'cl', 'gl', 'fl', 'ch', 'll', 'rr', 'kr', 'kl'];
  // La u de "que/qui" y "gue/gui" no suena: es parte de la consonante, no una vocal.
  const uMuda = i => /[uU]/.test(palabra[i]) &&
    (/[qQ]/.test(palabra[i - 1] || '') || (/[gG]/.test(palabra[i - 1] || '') && /[eiéíEIÉÍ]/.test(palabra[i + 1] || '')));
  const esV = (c, i) => V.includes(c) && !uMuda(i);
  const n = palabra.length;
  const nucleos = [];
  for (let i = 0; i < n;) {
    if (!esV(palabra[i], i)) { i++; continue; }
    let j = i + 1;
    while (j < n && esV(palabra[j], j)) {
      const a = palabra[j - 1], b = palabra[j];
      // hiato: dos vocales fuertes, o una débil acentuada junto a otra vocal
      if ((FUERTES.includes(a) && FUERTES.includes(b)) || TILDE_DEBIL.includes(a) || TILDE_DEBIL.includes(b)) break;
      j++;
    }
    nucleos.push([i, j]);
    i = j;
  }
  const cortes = [];
  for (let k = 0; k < nucleos.length - 1; k++) {
    const fin = nucleos[k][1];
    let cons = palabra.slice(fin, nucleos[k + 1][0]).toLowerCase();
    // "qu" / "gu" mudas viajan juntas a la sílaba siguiente; lo que quede antes cierra la anterior.
    if (/(qu|gu)$/.test(cons)) {
      cons = cons.slice(0, -2);
      cortes.push(fin + cons.length);
      continue;
    }
    const m = cons.length;
    if (m <= 1) cortes.push(fin);
    else if (m === 2) cortes.push(grupos.includes(cons) ? fin : fin + 1);
    else if (m === 3) cortes.push(grupos.includes(cons.slice(1)) ? fin + 1 : fin + 2);
    else cortes.push(grupos.includes(cons.slice(2)) ? fin + 2 : fin + 3);
  }
  const silabas = [];
  let ant = 0;
  cortes.forEach(c => { silabas.push(palabra.slice(ant, c)); ant = c; });
  silabas.push(palabra.slice(ant));
  return silabas;
}

function conGuionesSuaves(texto) {
  return texto.split(/(\s+)/).map(trozo => {
    if (/[@:\/]|www\./i.test(trozo)) return trozo;               // enlaces y correos, tal cual
    return trozo.replace(/[A-Za-zÁÉÍÓÚÜáéíóúüñÑ]{7,}/g, palabra => {
      let r = '', hasta = '';
      silabasEs(palabra).forEach((sil, i) => {
        // nunca deja menos de 2 letras antes del guion ni menos de 3 después
        if (i > 0 && hasta.length >= 2 && palabra.length - hasta.length >= 3) r += '\u00AD';
        r += sil;
        hasta += sil;
      });
      return r;
    });
  }).join('');
}

function pintarDescripcion(texto, nombre) {
  productoDescripcion.replaceChildren();
  if (!texto) {
    const caja = document.createElement('div');
    caja.className = 'cp-ficha-sin';
    const t = document.createElement('strong');
    t.textContent = 'Esta descripción está en camino.';
    const p = document.createElement('span');
    p.append('¿Quieres saber más de este producto? ');
    const a = document.createElement('a');
    a.href = 'https://wa.me/' + WHATSAPP + '?text=' + encodeURIComponent('Hola, quiero saber más de ' + nombre);
    a.target = '_blank'; a.rel = 'noopener';
    a.textContent = 'Pregúntanos por WhatsApp';
    p.append(a);
    caja.append(t, p);
    productoDescripcion.append(caja);
    return;
  }
  // Un párrafo por cada bloque separado por una línea en blanco; los saltos de línea sueltos se respetan.
  texto.split(/\n\s*\n/).map(x => x.trim()).filter(Boolean).forEach(parrafo => {
    const p = document.createElement('p');
    p.textContent = conGuionesSuaves(parrafo);
    productoDescripcion.append(p);
  });
}

/* La foto de la ficha sigue el mismo criterio que la tarjeta: apoyada abajo y con el tamaño
   según la forma del envase (--a y --fh, que la tarjeta trae de PHP). Aquí la escena no es
   cuadrada, así que el tamaño se calcula en px; la sombra de apoyo toma ese ancho y esa base.
   Se recalcula cada vez que cambia el tamaño de la escena (abrir, girar, --quita). */
/* Cuánto aire (fracción del alto de la foto) queda VACÍO bajo el producto. Las fotos se recortan al
   subirlas, pero ese aire va de 0 a 6 % según la foto (un envase blanco sobre blanco casi no deja
   nada), así que la sombra de apoyo se coloca con la medida real: se baja la foto a una miniatura
   y se busca desde abajo la primera fila con algo que no sea blanco ni transparente. */
function medirPieFoto(img) {
  try {
    const nw = img.naturalWidth, nh = img.naturalHeight;
    if (!nw || !nh) return null;
    const w = Math.min(nw, 96), h = Math.max(1, Math.round(w * nh / nw));
    const lienzo = document.createElement('canvas');
    lienzo.width = w; lienzo.height = h;
    const g = lienzo.getContext('2d', { willReadFrequently: true });
    g.drawImage(img, 0, 0, w, h);
    const d = g.getImageData(0, 0, w, h).data;
    for (let y = h - 1; y >= 0; y--) {
      for (let x = 0; x < w; x++) {
        const i = (y * w + x) * 4;
        if (d[i + 3] > 40 && Math.min(d[i], d[i + 1], d[i + 2]) < 240) return (h - 1 - y) / h;
      }
    }
  } catch (e) { /* sin canvas o foto de otro origen: se queda el valor por defecto */ }
  return null;
}

document.querySelectorAll('.cp-img-foto .cp-foto').forEach(img => {
  const fijar = () => {
    const pie = medirPieFoto(img);
    if (pie !== null) img.closest('.cp-img-wrap').style.setProperty('--pie', pie.toFixed(4));
  };
  if (img.complete && img.naturalWidth) fijar(); else img.addEventListener('load', fijar, { once: true });
});

function encuadrarFotoFicha() {
  const img = productoFoto.querySelector('img.cp-foto');
  const W = productoFoto.clientWidth, H = productoFoto.clientHeight;
  if (!img || !W || !H) return;
  const a  = parseFloat(productoEscena.dataset.a) || (img.naturalWidth / img.naturalHeight) || 0.6;
  // En la ficha el producto va un 10 % más grande que en la tarjeta (relativo a su zona).
  const fh = Math.min(1, (parseFloat(productoEscena.dataset.fh) || 0.85) * 1.1);
  let h = fh * H, w = a * h;
  const maxW = Math.min(W, productoEscena.clientWidth * 0.74);
  if (w > maxW) { w = maxW; h = w / a; }
  img.style.width = w + 'px';
  img.style.height = h + 'px';
  const base = productoEscena.getBoundingClientRect().bottom - productoFoto.getBoundingClientRect().bottom;
  productoEscena.style.setProperty('--ancho-producto', w + 'px');
  const pie = parseFloat(productoEscena.dataset.pie);
  productoEscena.style.setProperty('--apoyo', (base + h * (isNaN(pie) ? 0.03 : pie)) + 'px');
}
if ('ResizeObserver' in window) new ResizeObserver(encuadrarFotoFicha).observe(productoFoto);

function pintarFotoFicha(tarjeta) {
  productoFoto.replaceChildren();
  const fotoTarjeta = tarjeta.querySelector('.cp-foto');
  if (fotoTarjeta) {
    const envolturaFoto = fotoTarjeta.closest('.cp-img-wrap');
    productoEscena.dataset.a  = envolturaFoto.style.getPropertyValue('--a').trim();
    productoEscena.dataset.fh = envolturaFoto.style.getPropertyValue('--fh').trim();
    productoEscena.dataset.pie = envolturaFoto.style.getPropertyValue('--pie').trim();
    const img = document.createElement('img');
    img.className = 'cp-foto';
    img.alt = fotoTarjeta.alt;
    img.decoding = 'async';
    img.addEventListener('load', () => {
      const pie = medirPieFoto(img);                       // la versión grande, por si la de la tarjeta aún no se midió
      if (pie !== null) productoEscena.dataset.pie = pie.toFixed(4);
      encuadrarFotoFicha();
    });
    // Se muestra al instante la de la tarjeta (ya está en caché) y se cambia por la grande
    // (hasta 1200 px) en cuanto termina de llegar, sin dejar la ficha en blanco mientras tanto.
    img.src = fotoTarjeta.currentSrc || fotoTarjeta.src;
    const grande = fotoTarjeta.dataset.grande;
    if (grande && !img.src.endsWith(grande)) {
      const previa = new Image();
      previa.onload = () => { if (img.isConnected) img.src = previa.src; };
      previa.src = grande;
    }
    productoFoto.append(img);
    productoEscena.classList.remove('sin-foto');
    encuadrarFotoFicha();
  } else {
    const envoltura = tarjeta.querySelector('.cp-img-wrap');
    if (envoltura) productoFoto.append(envoltura.cloneNode(true));
    productoEscena.classList.add('sin-foto');
  }
}

function pintarChips(tarjeta) {
  productoChips.replaceChildren();
  const marca = tarjeta.dataset.marca;
  if (marca) {
    const c = document.createElement('span');
    c.className = 'cp-ficha-chip cp-ficha-chip--marca';
    c.textContent = marca;
    productoChips.append(c);
  }
  const categoria = tarjeta.dataset.categoria;
  if (categoria) {
    const c = document.createElement('span');
    c.className = 'cp-ficha-chip';
    c.style.setProperty('--chip-fondo', tarjeta.dataset.catFondo);
    c.style.setProperty('--chip-tinta', tarjeta.dataset.catTinta);
    c.textContent = categoria;
    productoChips.append(c);
  }
}

function abrirProducto(tarjeta) {
  const descripcion = (tarjeta.dataset.descripcion || '').trim();

  hojaProductoTitulo.textContent = tarjeta.dataset.nombre;
  productoPrecio.textContent = cop(+tarjeta.dataset.precio);
  pintarChips(tarjeta);
  pintarFotoFicha(tarjeta);
  pintarDescripcion(descripcion, tarjeta.dataset.nombre);
  productoDescExpandida = false;
  productoMas.textContent = 'Leer más';
  productoMas.setAttribute('aria-expanded', 'false');

  productoActualSku    = tarjeta.dataset.sku;
  productoActualNombre = tarjeta.dataset.nombre;
  productoActualPrecio = +tarjeta.dataset.precio;
  sincronizarBotonAgregar(productoAgregarBtn, pedido.some(i => i.sku === productoActualSku));

  productoCierre++;
  ultimoFocoProducto = document.activeElement;
  productoFondo.hidden = false;
  hojaProducto.hidden = false;
  document.body.classList.add('cp-bloqueo-scroll');
  // Con la hoja oculta el navegador no suelta el desplazamiento anterior: se reinicia ya visible.
  productoCuerpo.scrollTop = 0;
  productoDetalle.scrollTop = 0;
  revisarLeerMas();
  requestAnimationFrame(() => {
    productoFondo.classList.add('visible');
    hojaProducto.classList.add('abierta');
  });
  // El foco va a la hoja (sin aro visible), no al botón de cerrar: con el botón enfocado
  // aparecía un aro grueso alrededor de la X cada vez que se abría una ficha.
  hojaProducto.focus({ preventScroll: true });
}

productoMas.addEventListener('click', () => {
  productoDescExpandida = !productoDescExpandida;
  productoMas.textContent = productoDescExpandida ? 'Leer menos' : 'Leer más';
  productoMas.setAttribute('aria-expanded', String(productoDescExpandida));
  revisarLeerMas();
});
productoCuerpo.addEventListener('scroll', pistaFicha, { passive: true });
productoDetalle.addEventListener('scroll', pistaFicha, { passive: true });
window.addEventListener('resize', () => { if (!hojaProducto.hidden) revisarLeerMas(); });
if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => { if (!hojaProducto.hidden) revisarLeerMas(); });

function cerrarProducto() {
  if (hojaProducto.hidden) return;
  productoFondo.classList.remove('visible');
  hojaProducto.classList.remove('abierta');
  if (hoja.hidden) document.body.classList.remove('cp-bloqueo-scroll');

  const idCierre = ++productoCierre;
  let hecho = false;
  const finalizar = () => {
    if (hecho || idCierre !== productoCierre) return;
    hecho = true;
    productoFondo.hidden = true;
    hojaProducto.hidden = true;
    hojaProducto.removeEventListener('transitionend', finalizar);
  };
  hojaProducto.addEventListener('transitionend', finalizar);
  setTimeout(finalizar, 350);

  if (ultimoFocoProducto && document.contains(ultimoFocoProducto)) ultimoFocoProducto.focus();
}

productoAgregarBtn.addEventListener('click', () => {
  if (!productoActualSku) return;
  agregarAlPedido(productoActualSku, productoActualNombre, productoActualPrecio);
});

cerrarProductoBtn.addEventListener('click', cerrarProducto);
productoFondo.addEventListener('click', cerrarProducto);
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && !hojaProducto.hidden) cerrarProducto();
});

document.querySelectorAll('.cp-tarjeta').forEach(tarjeta => {
  tarjeta.addEventListener('click', e => {
    if (e.target.closest('.cp-fila-agregar')) return;
    abrirProducto(tarjeta);
  });
});

document.getElementById('vaciarPedido').addEventListener('click', () => {
  pedido.length = 0;
  document.querySelectorAll('.cp-tarjeta.en-pedido').forEach(t => {
    t.classList.remove('en-pedido');
    const b = t.querySelector('.cp-boton-agregar');
    if (b) sincronizarBotonAgregar(b, false);
  });
  if (productoActualSku) sincronizarBotonAgregar(productoAgregarBtn, false);
  actualizarCarrito();
});

/* --- Buscador: filtra en el navegador por nombre, marca y tipo --- */
const cpQ        = document.getElementById('cpQ');
const cpEnvoltura = document.getElementById('cpBuscador');
const cpVacio    = document.getElementById('cpVacio');
const cpConteo   = document.getElementById('cpConteo');
const gruposMarca = [...document.querySelectorAll('.cp-marca-grupo')];

function filtrarCatalogo() {
  const t = cpQ.value.trim().toLowerCase();
  cpEnvoltura.classList.toggle('tiene-texto', t.length > 0);
  let visibles = 0;
  gruposMarca.forEach(g => {
    let enGrupo = 0;
    g.querySelectorAll('.cp-tarjeta').forEach(c => {
      const ok = !t || c.dataset.busca.includes(t);
      c.hidden = !ok;
      if (ok) enGrupo++;
    });
    g.hidden = enGrupo === 0;
    visibles += enGrupo;
  });
  cpVacio.classList.toggle('visible', visibles === 0);
  cpConteo.textContent = t
    ? visibles + (visibles === 1 ? ' producto' : ' productos')
    : TOTAL_PRODUCTOS + ' productos';
}
cpQ.addEventListener('input', filtrarCatalogo);
document.getElementById('cpLimpiar').addEventListener('click', () => {
  cpQ.value = '';
  filtrarCatalogo();
  cpQ.focus();
});

/* --- Chips de marca --- */
const chips = document.querySelectorAll('.cp-chip');

/* Chips de marca · sombra de scroll: difumina el borde donde hay más chips
   ocultos, para que el que queda a la vista no se vea "recortado" sino
   como una pista de que hay más para deslizar. */
const cpChipsNav = document.getElementById('chips');
function cpActualizarDesborde() {
  const restante = cpChipsNav.scrollWidth - cpChipsNav.clientWidth;
  const desbordaIzq = cpChipsNav.scrollLeft > 2;
  const desbordaDer = cpChipsNav.scrollLeft < restante - 2;
  cpChipsNav.classList.toggle('cp-desborda-ambos', desbordaIzq && desbordaDer);
  cpChipsNav.classList.toggle('cp-desborda-izquierda', desbordaIzq && !desbordaDer);
  cpChipsNav.classList.toggle('cp-desborda-derecha', !desbordaIzq && desbordaDer);
}
if (cpChipsNav) {
  cpChipsNav.addEventListener('scroll', cpActualizarDesborde, { passive: true });
  window.addEventListener('resize', cpActualizarDesborde);
  cpActualizarDesborde();

  // Con mouse normal (sin trackpad) la fila no se podía mover: la rueda
  // solo hace scroll vertical y este contenedor no tiene alto que desbordar.
  // Se traduce la rueda a desplazamiento horizontal mientras el mouse esté encima.
  cpChipsNav.addEventListener('wheel', (e) => {
    if (Math.abs(e.deltaY) > Math.abs(e.deltaX)) {
      cpChipsNav.scrollLeft += e.deltaY;
      e.preventDefault();
    }
  }, { passive: false });
}

if (chips.length && gruposMarca.length) {
  const observador = new IntersectionObserver(entradas => {
    entradas.forEach(entrada => {
      if (entrada.isIntersecting) {
        chips.forEach(chip => {
          const esActivo = chip.dataset.slug === entrada.target.id;
          chip.classList.toggle('activo', esActivo);
          // Antes: solo se pintaba el chip activo, pero la fila nunca se
          // movía para mostrarlo — si estaba fuera de vista (como "Leche
          // pal pelo", casi siempre tapado a la derecha) parecía que el
          // carrusel no reaccionaba al bajar la página.
          if (esActivo) chip.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
        });
      }
    });
  }, { rootMargin: '-130px 0px -70% 0px' });
  gruposMarca.forEach(grupo => observador.observe(grupo));
}

/* --- Aviso corredizo: botón de pausa (el mensaje también se pausa con el mouse encima o con el foco) --- */
const cpFranja = document.getElementById('cpFranja');
const cpAvisoPausa = document.getElementById('cpAvisoPausa');
if (cpFranja && cpAvisoPausa) {
  cpAvisoPausa.addEventListener('click', () => {
    const pausado = cpFranja.classList.toggle('pausado');
    cpAvisoPausa.setAttribute('aria-pressed', String(pausado));
    cpAvisoPausa.setAttribute('aria-label', pausado ? 'Reanudar el mensaje' : 'Pausar el mensaje');
  });
}
