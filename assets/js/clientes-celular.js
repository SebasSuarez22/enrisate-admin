/* =========================================================
   Clientes · celular (menos de 700px)
   =========================================================
   Hace funcionar lo que clientes.php (includes/clientes_directorio.php) muestra en celular:
   mosaicos de segmento → lista de ese segmento → ficha de la clienta, en pantallas por pasos.
   La ficha se reparte en tres pestañas (Resumen, Compras, Escribirle) y Editar y Eliminar
   quedan fijos abajo. "Agregar" abre una ventana desde abajo con el formulario de siempre.
   Los datos son los mismos de <script id="datos-clientes"> que usa el directorio de
   escritorio y tablet; el servidor sigue siendo quien valida todo (agregar, editar, eliminar).
   El botón "Atrás" del celular vuelve a la pantalla anterior (cada paso es una entrada del historial).
   ========================================================= */
(function () {
  'use strict';

  var raiz = document.getElementById('cm');
  var datosEl = document.getElementById('datos-clientes');
  var pantalla = document.getElementById('cm-pantalla');
  if (!raiz || !datosEl || !pantalla) return;

  var D;
  try { D = JSON.parse(datosEl.textContent); } catch (e) { return; }

  var csrf = raiz.dataset.csrf;
  var PAG = 8;
  var ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' };
  var esc = function (t) { return String(t).replace(/[&<>"]/g, function (c) { return ESC[c]; }); };
  var sinT = function (t) { return String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim(); };
  var pesos = function (n) { return '$' + Math.round(n).toLocaleString('es-CO'); };
  var iniciales = function (n) { return String(n).trim().split(/\s+/).slice(0, 2).map(function (p) { return p.charAt(0); }).join('').toUpperCase() || '·'; };
  var lista = D.orden.map(function (id) { return D.clientes[id]; });   // de la A a la Z
  var SEG = Object.keys(D.segmentos);
  var enSegmento = function (k) { return k === 'todas' ? lista : lista.filter(function (c) { return c.segs.indexOf(k) !== -1; }); };
  var textoSeg = function (k) { return k === 'todas' ? { t: 'Todas de la A a la Z', d: 'Todas las clientas' } : D.segmentos[k]; };
  var ETQ = { rep: 'Le toca reponer', cuo: 'Cuota pendiente', nue: 'Nueva', sal: 'Saldo a favor', ina: 'Inactiva', sin: 'Sin teléfono' };
  var TABS = [['resumen', 'Resumen'], ['compras', 'Compras'], ['escribir', 'Escribirle']];

  var I = {
    chev: '<svg class="cm-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>',
    atras: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg>',
    mas: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>',
    lupa: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/></svg>',
    wa: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2C6.49 2 2 6.49 2 12c0 1.87.5 3.62 1.38 5.13L2 22l4.99-1.31A9.94 9.94 0 0 0 12 22c5.51 0 10-4.49 10-10S17.51 2 12 2Zm5.2 14.2c-.22.62-1.28 1.2-1.77 1.24-.45.05-.98.24-3.29-.7-2.77-1.13-4.53-3.92-4.66-4.1-.14-.18-1.1-1.47-1.1-2.8 0-1.34.7-1.99.95-2.26.24-.27.53-.34.7-.34h.5c.16 0 .38-.03.58.44.22.51.75 1.77.82 1.9.06.13.1.28.02.46-.08.18-.13.29-.25.44-.13.16-.27.35-.38.47-.13.13-.27.28-.11.55.16.27.7 1.16 1.51 1.87 1.04.93 1.92 1.22 2.19 1.36.27.14.43.12.6-.07.16-.19.68-.79.86-1.06.18-.27.36-.22.6-.13.24.09 1.55.73 1.82.86.27.13.45.2.51.31.07.12.07.68-.15 1.36Z"/></svg>',
    edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.17 6.81a1 1 0 0 0-3.99-3.99L3.84 16.17a2 2 0 0 0-.5.83l-1.32 4.35a.5.5 0 0 0 .62.62l4.35-1.32a2 2 0 0 0 .83-.5Z"/><path d="m15 5 4 4"/></svg>',
    borrar: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>'
  };

  /* ---------- Estado y pasos (cada paso es una entrada del historial) ---------- */
  var estado = { v: 'inicio', seg: null, id: null };
  var vista = { n: PAG, q: '', tab: 'resumen' };   // lo que no hace falta recordar al volver atrás
  var profundidad = 0;

  function ir(nuevo) {
    estado = nuevo;
    vista = { n: PAG, q: '', tab: 'resumen' };
    try { history.pushState(estado, ''); profundidad++; } catch (e) { /* sin historial: igual se ve la pantalla */ }
    pintar(true);
  }
  function atras() {
    if (profundidad > 0) { history.back(); return; }
    // Se llegó directo a una ficha (tras guardar): se vuelve al inicio sin salir de la página
    estado = estado.v === 'editar' ? { v: 'ficha', seg: estado.seg, id: estado.id } : { v: 'inicio', seg: null, id: null };
    vista = { n: PAG, q: '', tab: 'resumen' };
    pintar(true);
  }
  window.addEventListener('popstate', function (e) {
    if (profundidad > 0) profundidad--;
    estado = (e.state && e.state.v) ? e.state : { v: 'inicio', seg: null, id: null };
    vista = { n: PAG, q: '', tab: 'resumen' };
    pintar(true);
  });

  /* ---------- Piezas ---------- */
  function fila(c) {
    return '<button type="button" class="cm-fila" data-id="' + esc(c.id) + '"><span class="cm-ini' + (c.vencida ? ' v' : '') + '" aria-hidden="true">' + esc(iniciales(c.nombre)) + '</span>' +
      '<span class="cm-fila-txt"><span class="cm-nombre">' + esc(c.nombre) + '</span><span class="cm-pista">' + esc(c.pista) + '</span></span>' + I.chev + '</button>';
  }
  function buscador(ph) {
    return '<label class="cm-busca">' + I.lupa + '<input type="search" id="cm-q" placeholder="' + ph + '" aria-label="Buscar cliente" autocomplete="off" enterkeyhint="search" value="' + esc(vista.q) + '"></label>';
  }
  function etiquetas(c) {
    return c.segs.map(function (k) { return '<span class="cm-etq ' + k + '"><i></i>' + (k === 'cuo' && c.vencida ? 'Cuota vencida' : ETQ[k]) + '</span>'; }).join('');
  }
  function mosaico(titulo, valor, txt, alerta) {
    return '<div class="cm-tile"><span>' + titulo + '</span><b' + (txt ? ' class="txt"' : '') + (alerta ? ' style="color:var(--alerta)"' : '') + '>' + valor + '</b></div>';
  }
  function coincide(c, q) { q = sinT(q); return !q || sinT(c.nombre + ' ' + c.telefono).indexOf(q) !== -1; }

  /* ---------- Pantalla: inicio ---------- */
  function zonaInicio() {
    if (vista.q) {
      var res = lista.filter(function (c) { return coincide(c, vista.q); });
      return '<p class="cm-cuenta">' + res.length + (res.length === 1 ? ' resultado' : ' resultados') + '</p><div class="cm-lista">' +
        (res.slice(0, 10).map(fila).join('') || '<p class="cm-vacio">Nadie coincide. Prueba con otro nombre o un teléfono.</p>') + '</div>' +
        (res.length > 10 ? '<p class="cm-fino">Y ' + (res.length - 10) + ' más: afina la búsqueda.</p>' : '');
    }
    var urgentes = lista.filter(function (c) { return c.urgencia < 5000; }).sort(function (a, b) { return a.urgencia - b.urgencia; }).slice(0, 2);
    return '<div class="cm-mosaicos">' + SEG.map(function (k) {
      return '<button type="button" class="cm-mosaico ' + k + '" data-seg="' + k + '"><span class="n">' + enSegmento(k).length + '</span><span class="t">' + esc(D.segmentos[k].t) + '</span></button>';
    }).join('') + '<button type="button" class="cm-mosaico todas" data-seg="todas"><span class="n">' + lista.length + '</span><span class="t">Todas de la A a la Z</span>' + I.chev + '</button></div>' +
      (urgentes.length ? '<h2 class="cm-sec">Para atender primero</h2><div class="cm-lista">' + urgentes.map(fila).join('') + '</div>' : '') +
      '<form method="post" action="clientes.php" class="cm-sync"><input type="hidden" name="csrf" value="' + esc(csrf) + '"><input type="hidden" name="accion" value="sincronizar">' +
      '<p class="cm-fino">Cada factura nueva agrega a la clienta aquí. Si tienes facturas de antes, tráelas una vez.</p><button type="submit" class="cm-btn ancho">Traer clientas de facturación</button></form>';
  }
  function inicio() {
    return '<div class="cm-cab"><div><h2 class="cm-titulo" id="cm-t" tabindex="-1">Clientes</h2><p class="cm-sub">' + lista.length + ' clientas</p></div>' +
      '<button type="button" class="cm-btn principal" data-nuevo>' + I.mas + 'Agregar</button></div>' + buscador('Buscar nombre o teléfono') + '<div id="cm-zona">' + zonaInicio() + '</div>';
  }

  /* ---------- Pantalla: lista de un segmento ---------- */
  function zonaSegmento() {
    var filas = enSegmento(estado.seg).filter(function (c) { return coincide(c, vista.q); }), vis = filas.slice(0, vista.n);
    if (!filas.length) return '<p class="cm-vacio">Nadie coincide con "' + esc(vista.q) + '" en este segmento.</p>';
    return '<p class="cm-cuenta">Mostrando ' + vis.length + ' de ' + filas.length + '</p><div class="cm-lista">' + vis.map(fila).join('') + '</div>' +
      (filas.length > vis.length ? '<button type="button" class="cm-btn ancho cm-mas" data-mas>Ver ' + Math.min(PAG, filas.length - vis.length) + ' más</button>' : '');
  }
  function segmento() {
    var tx = textoSeg(estado.seg);
    return '<button type="button" class="cm-atras" data-atras>' + I.atras + 'Inicio</button><h2 class="cm-pt" id="cm-t" tabindex="-1">' + esc(tx.t) + '</h2><p class="cm-sub" style="margin-bottom:12px">' + esc(tx.d) + '</p>' +
      buscador('Buscar en este segmento') + '<div id="cm-zona">' + zonaSegmento() + '</div>';
  }

  /* ---------- Pantalla: ficha con pestañas ---------- */
  function panelFicha(c) {
    if (vista.tab === 'compras') {
      return '<div class="cm-sec-bloque"><h3>Facturas recientes</h3>' + (c.facturas.length ? c.facturas.map(function (f) {
        return '<a class="cm-fact" href="factura.php?n=' + encodeURIComponent(f.n) + '"><b>' + esc(f.n) + '</b><span>' + esc(f.f.replace(/\s\d{4}$/, '')) + '</span><span class="t">' + pesos(f.t) + '</span><span class="cm-etq ' + f.c + '"><i></i>' + esc(f.e) + '</span></a>';
      }).join('') : '<p class="cm-fino">Todavía no tiene facturas.</p>') + '</div>' +
        '<div class="cm-tiles">' + mosaico('Última compra', esc(c.ultima), true) + mosaico('Producto favorito', c.favorito ? esc(c.favorito) : '—', true) + '</div>' +
        (c.debe > 0 ? '<a class="cm-enlace" href="cartera.php?q=' + encodeURIComponent(c.nombre) + '">Ver sus facturas en Cartera</a>' : '');
    }
    if (vista.tab === 'escribir') {
      return '<div class="cm-sec-bloque"><h3>Escribirle por WhatsApp</h3>' + (c.ofertas.length
        ? '<div class="cm-ofertas">' + c.ofertas.map(function (o) { return '<a class="cm-btn wa ancho" href="' + esc(o.url) + '" target="_blank" rel="noopener noreferrer">' + I.wa + esc(o.t) + '</a>'; }).join('') + '</div>'
        : '<p class="cm-fino">' + (c.telefono ? 'Todavía no hay datos de compra para ofrecerle algo.' : 'Agrega un teléfono para poder escribirle.') + '</p>') + '</div>';
    }
    return '<div class="cm-etqs">' + (etiquetas(c) || '<span class="cm-fino">Sin segmentos especiales</span>') + '</div><div class="cm-tiles">' +
      mosaico('Compras', c.compras) + mosaico('Cada cuánto compra', c.frecuencia === null ? '—' : (c.frecuencia < 1 ? 'Muy seguido' : '~' + c.frecuencia + ' días')) +
      mosaico('Última compra', esc(c.ultima), true) + mosaico('Producto favorito', c.favorito ? esc(c.favorito) : '—', true) +
      mosaico('Saldo a favor', pesos(c.saldo)) + mosaico('Por cobrar', pesos(c.debe), false, c.vencida) + '</div>' +
      (c.notas ? '<p class="cm-nota">' + esc(c.notas) + '</p>' : '');
  }
  function ficha() {
    var c = D.clientes[estado.id];
    if (!c) { estado = { v: 'inicio', seg: null, id: null }; return inicio(); }
    return '<button type="button" class="cm-atras" data-atras>' + I.atras + (estado.seg ? esc(textoSeg(estado.seg).t) : 'Inicio') + '</button>' +
      '<div class="cm-f-cab"><span class="cm-ini grande' + (c.vencida ? ' v' : '') + '" aria-hidden="true">' + esc(iniciales(c.nombre)) + '</span><div><h2 class="cm-pt" id="cm-t" tabindex="-1">' + esc(c.nombre) + '</h2><p class="cm-fino">' + (c.telefono ? esc(c.telefono) : 'Sin teléfono') + '</p></div></div>' +
      '<div class="cm-tabs" role="tablist" aria-label="Datos de ' + esc(c.nombre) + '">' + TABS.map(function (t) {
        return '<button type="button" role="tab" class="cm-tab" data-tab="' + t[0] + '" aria-selected="' + (vista.tab === t[0]) + '">' + t[1] + '</button>';
      }).join('') + '</div><div class="cm-panel" id="cm-panel" role="tabpanel">' + panelFicha(c) + '</div>' +
      '<div class="cm-barra"><button type="button" class="cm-btn" data-editar>' + I.edit + 'Editar</button>' +
      '<form method="post" action="clientes.php" data-eliminar="' + esc(c.nombre) + '"><input type="hidden" name="csrf" value="' + esc(csrf) + '"><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="ids" value="' + esc(c.id) + '">' +
      '<button type="submit" class="cm-btn peligro ancho">' + I.borrar + 'Eliminar</button></form></div>';
  }

  /* ---------- Pantalla: editar ---------- */
  function editar() {
    var c = D.clientes[estado.id];
    if (!c) return inicio();
    return '<button type="button" class="cm-atras" data-atras>' + I.atras + 'Volver</button><form method="post" action="clientes.php" class="cm-form">' +
      '<h2 class="cm-pt" id="cm-t" tabindex="-1">Editar a ' + esc(c.nombre) + '</h2><input type="hidden" name="csrf" value="' + esc(csrf) + '"><input type="hidden" name="accion" value="editar"><input type="hidden" name="id" value="' + esc(c.id) + '">' +
      '<div class="campo"><label for="cm-e-nombre">Nombre completo</label><input type="text" id="cm-e-nombre" name="nombre" value="' + esc(c.nombre) + '" required autocomplete="off"></div>' +
      '<div class="campo"><label for="cm-e-tel">Teléfono</label><input type="tel" id="cm-e-tel" name="telefono" value="' + esc(c.telefono) + '" inputmode="numeric" autocomplete="off"></div>' +
      '<div class="campo"><label for="cm-e-saldo">Saldo a favor</label><input type="number" id="cm-e-saldo" name="saldo_favor" value="' + c.saldo + '" min="0" step="500"></div>' +
      '<div class="campo"><label for="cm-e-notas">Notas</label><input type="text" id="cm-e-notas" name="notas" value="' + esc(c.notas) + '" autocomplete="off"></div>' +
      '<button type="submit" class="cm-btn principal ancho">Guardar</button><button type="button" class="cm-btn ancho" data-atras>Cancelar</button></form>';
  }

  /* ---------- Pintar ---------- */
  function pintar(arriba) {
    raiz.dataset.pantalla = estado.v;
    pantalla.innerHTML = ({ inicio: inicio, seg: segmento, ficha: ficha, editar: editar })[estado.v]();
    if (arriba) {
      window.scrollTo(0, 0);
      var t = document.getElementById('cm-t');
      if (t && history.state !== null) t.focus({ preventScroll: true });
    }
  }

  /* ---------- Eventos ---------- */
  pantalla.addEventListener('click', function (e) {
    var b;
    if ((b = e.target.closest('[data-seg]'))) { ir({ v: 'seg', seg: b.dataset.seg, id: null }); return; }
    if ((b = e.target.closest('.cm-fila'))) { ir({ v: 'ficha', seg: estado.v === 'seg' ? estado.seg : null, id: b.dataset.id }); return; }
    if ((b = e.target.closest('[data-tab]'))) {
      vista.tab = b.dataset.tab;
      Array.prototype.forEach.call(pantalla.querySelectorAll('.cm-tab'), function (t) { t.setAttribute('aria-selected', String(t === b)); });
      $panel().innerHTML = panelFicha(D.clientes[estado.id]);
      return;
    }
    if (e.target.closest('[data-mas]')) {
      var y = window.scrollY;
      vista.n += PAG;
      document.getElementById('cm-zona').innerHTML = zonaSegmento();
      window.scrollTo(0, y);
      return;
    }
    if (e.target.closest('[data-atras]')) { atras(); return; }
    if (e.target.closest('[data-editar]')) { ir({ v: 'editar', seg: estado.seg, id: estado.id }); return; }
    if (e.target.closest('[data-nuevo]')) abrirNuevo();
  });
  function $panel() { return document.getElementById('cm-panel'); }

  pantalla.addEventListener('input', function (e) {
    if (e.target.id !== 'cm-q') return;
    vista.q = e.target.value; vista.n = PAG;
    document.getElementById('cm-zona').innerHTML = estado.v === 'inicio' ? zonaInicio() : zonaSegmento();
  });
  pantalla.addEventListener('submit', function (e) {
    var f = e.target.closest('form[data-eliminar]');
    if (f && !window.confirm('¿Eliminar a ' + f.dataset.eliminar + '? Esta acción no se puede deshacer.')) e.preventDefault();
  });

  /* ---------- Ventana "Agregar cliente" (sube desde abajo) ---------- */
  var hoja = document.getElementById('cm-hoja');
  function abrirNuevo() {
    if (!hoja || typeof hoja.showModal !== 'function') return;
    hoja.showModal();
    document.getElementById('cm-nombre').focus();
  }
  if (hoja) {
    hoja.addEventListener('click', function (e) {
      if (e.target === hoja || e.target.closest('[data-cm-cerrar]')) hoja.close();   // el fondo oscuro o la X
    });
  }

  /* ---------- Al abrir: la clienta de ?sel= (tras guardar o agregar) o el inicio ---------- */
  var pedida = new URLSearchParams(location.search).get('sel');
  if (pedida && D.clientes[pedida]) {
    estado = { v: 'ficha', seg: null, id: pedida };
    try { history.replaceState(estado, ''); } catch (e) { /* sin historial */ }
  } else {
    try { history.replaceState(estado, ''); } catch (e) { /* sin historial */ }
  }
  pintar(false);
})();
