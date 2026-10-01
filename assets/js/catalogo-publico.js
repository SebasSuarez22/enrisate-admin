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

const TEXTO_ENVIAR = 'Confirmar y enviar por WhatsApp';
const cop = n => '$' + Math.round(n).toLocaleString('es-CO');

const pedido = [];

const fab         = document.getElementById('fabCarrito');
const fabInsignia = document.getElementById('fabInsignia');
const fabTotal    = document.getElementById('fabTotal');
const fondo       = document.getElementById('carritoFondo');
const hoja        = document.getElementById('hojaCarrito');
const cerrarBtn   = document.getElementById('cerrarCarrito');
const listaPedido = document.getElementById('listaPedido');
const hojaTotal   = document.getElementById('hojaTotal');

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

function totalPedido() {
  return pedido.reduce((s, i) => s + i.precio * i.cant, 0);
}

function cantidadTotal() {
  return pedido.reduce((s, i) => s + i.cant, 0);
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
function agregarAlPedido(sku, nombre, precio) {
  const ya = pedido.find(i => i.sku === sku);
  if (ya) {
    ya.cant = Math.min(99, ya.cant + 1);
  } else {
    pedido.push({ sku, nombre, precio, cant: 1 });
  }
  sincronizarTarjeta(sku);
  sincronizarBotonProducto(sku);
  actualizarCarrito();
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

    const info = document.createElement('div');
    info.className = 'cp-lista-info';
    const nombre = document.createElement('span');
    nombre.className = 'cp-lista-nombre';
    nombre.textContent = i.nombre;
    const precio = document.createElement('span');
    precio.className = 'cp-lista-precio';
    precio.textContent = cop(i.precio * i.cant);
    info.append(nombre, precio);

    const controles = document.createElement('div');
    controles.className = 'cp-lista-controles';

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

    const quitar = document.createElement('button');
    quitar.type = 'button';
    quitar.className = 'cp-lista-quitar';
    quitar.setAttribute('aria-label', 'Quitar ' + i.nombre + ' del pedido');
    quitar.textContent = '×';
    quitar.addEventListener('click', () => quitarDelPedido(i.sku));

    controles.append(stepper, quitar);
    fila.append(info, controles);
    listaPedido.appendChild(fila);
  });
}

function actualizarInsignia() {
  const cant = cantidadTotal();
  fabInsignia.textContent = cant > 99 ? '99+' : String(cant);
  fabInsignia.classList.remove('cp-fab-insignia-pulso');
  void fabInsignia.offsetWidth; // reinicia la animación de "pop"
  fabInsignia.classList.add('cp-fab-insignia-pulso');
}

function actualizarCarrito() {
  const hayPedido = pedido.length > 0;
  fab.hidden = !hayPedido;
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
  renderLista();
  hojaTotal.textContent = cop(totalPedido());

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
  actualizarBloquePlan();
  cpFormError.hidden = true;
  cerrarBtn.focus();
}

function cerrarCarrito() {
  if (hoja.hidden) return;
  fondo.classList.remove('visible');
  hoja.classList.remove('abierta');
  if (hojaProducto.hidden) document.body.classList.remove('cp-bloqueo-scroll');
  fab.setAttribute('aria-expanded', 'false');

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

function mensajePedido(numero, plan) {
  let msg = 'Hola! Quiero pedir esto de ' + NOMBRE_NEGOCIO + ':\n\n';
  pedido.forEach(i => {
    msg += '• ' + i.cant + ' x ' + i.nombre + ' — ' + cop(i.precio * i.cant) + '\n';
  });
  msg += '\nTotal aproximado: ' + cop(totalPedido()) + '\n';
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

function restaurarBotonEnviar() {
  cpConfirmar.disabled = false;
  cpEnviarTexto.textContent = TEXTO_ENVIAR;
}

cpConfirmar.addEventListener('click', async () => {
  const cliente  = cpNombre.value.trim();
  const telefono = cpTelefono.value.replace(/\D+/g, '');

  if (!cliente) { mostrarErrorForm('Escribe tu nombre.'); cpNombre.focus(); return; }
  if (telefono.length < 7) { mostrarErrorForm('Escribe un WhatsApp válido.'); cpTelefono.focus(); return; }

  const plan = document.querySelector('input[name="cpPlan"]:checked').value;

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
      mostrarErrorForm(data.error || 'No se pudo enviar el pedido. Intenta de nuevo.');
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
    mostrarErrorForm('No hay conexión. Revisa tu internet e intenta de nuevo.');
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

  boton.addEventListener('click', () => agregarAlPedido(sku, nombre, precio));
});

/* --- Ficha de producto: foto + descripción al tocar la tarjeta (en
       cualquier parte que no sea el botón "Agregar"). La descripción es
       la misma que se edita en Productos; si todavía no tiene, se avisa
       en vez de dejar la ficha vacía. --- */
const productoFondo       = document.getElementById('productoFondo');
const hojaProducto        = document.getElementById('hojaProducto');
const cerrarProductoBtn   = document.getElementById('cerrarProducto');
const hojaProductoTitulo  = document.getElementById('hojaProductoTitulo');
const productoFoto        = document.getElementById('productoFoto');
const productoDescripcion = document.getElementById('productoDescripcion');
const productoAgregarBtn  = document.getElementById('productoAgregarBtn');

let ultimoFocoProducto = null;
// Qué producto muestra la ficha ahora mismo — el botón "Agregar" de acá
// abajo lo necesita para saber a cuál sku/nombre/precio agregar.
let productoActualSku    = null;
let productoActualNombre = '';
let productoActualPrecio = 0;

function abrirProducto(tarjeta) {
  const imgWrap = tarjeta.querySelector('.cp-img-wrap');
  const descripcion = (tarjeta.dataset.descripcion || '').trim();

  hojaProductoTitulo.textContent = tarjeta.dataset.nombre;
  productoFoto.innerHTML = '';
  if (imgWrap) productoFoto.appendChild(imgWrap.cloneNode(true));

  productoDescripcion.textContent = descripcion || 'Todavía no tiene descripción.';
  productoDescripcion.classList.toggle('cp-sin-descripcion', !descripcion);

  productoActualSku    = tarjeta.dataset.sku;
  productoActualNombre = tarjeta.dataset.nombre;
  productoActualPrecio = +tarjeta.dataset.precio;
  sincronizarBotonAgregar(productoAgregarBtn, pedido.some(i => i.sku === productoActualSku));

  ultimoFocoProducto = document.activeElement;
  productoFondo.hidden = false;
  hojaProducto.hidden = false;
  document.body.classList.add('cp-bloqueo-scroll');
  requestAnimationFrame(() => {
    productoFondo.classList.add('visible');
    hojaProducto.classList.add('abierta');
  });
  cerrarProductoBtn.focus();
}

function cerrarProducto() {
  if (hojaProducto.hidden) return;
  productoFondo.classList.remove('visible');
  hojaProducto.classList.remove('abierta');
  if (hoja.hidden) document.body.classList.remove('cp-bloqueo-scroll');

  let hecho = false;
  const finalizar = () => {
    if (hecho) return;
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
