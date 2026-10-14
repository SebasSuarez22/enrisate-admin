/* =========================================================
   Clientes · directorio de escritorio
   =========================================================
   Hace funcionar lo que clientes.php (includes/clientes_directorio.php) dibuja desde 960px:
   - el buscador y el filtro por segmento de la lista;
   - el índice A–Z;
   - la ficha de la clienta elegida (cifras, segmentos, facturas, ofertas por WhatsApp);
   - los formularios de editar, agregar y eliminar, que mandan lo mismo que siempre a clientes.php.
   Los datos salen de <script id="datos-clientes">; el servidor sigue siendo quien valida todo.
   ========================================================= */
(function () {
  'use strict';

  var raiz = document.getElementById('directorio');
  var datosEl = document.getElementById('datos-clientes');
  if (!raiz || !datosEl) return;

  var D;
  try { D = JSON.parse(datosEl.textContent); } catch (e) { return; }

  var $ = function (id) { return document.getElementById(id); };
  var lista = $('cd-lista'), ficha = $('cd-ficha'), buscar = $('cd-q'), selSeg = $('cd-seg'), vacio = $('cd-vacio'), az = $('cd-az');
  var csrf = raiz.dataset.csrf;
  var ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' };
  var esc = function (t) { return String(t).replace(/[&<>"]/g, function (c) { return ESC[c]; }); };
  var sinT = function (t) { return String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim(); };
  var pesos = function (n) { return '$' + Math.round(n).toLocaleString('es-CO'); };
  var iniciales = function (n) { return String(n).trim().split(/\s+/).slice(0, 2).map(function (p) { return p.charAt(0); }).join('').toUpperCase() || '·'; };
  var filas = Array.prototype.slice.call(lista.querySelectorAll('.cd-fila'));
  var ETQ = { rep: 'Le toca reponer', cuo: 'Cuota pendiente', nue: 'Nueva', sal: 'Saldo a favor', ina: 'Inactiva', sin: 'Sin teléfono' };

  var estado = { sel: null };

  var I = {
    wa: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2C6.49 2 2 6.49 2 12c0 1.87.5 3.62 1.38 5.13L2 22l4.99-1.31A9.94 9.94 0 0 0 12 22c5.51 0 10-4.49 10-10S17.51 2 12 2Zm5.2 14.2c-.22.62-1.28 1.2-1.77 1.24-.45.05-.98.24-3.29-.7-2.77-1.13-4.53-3.92-4.66-4.1-.14-.18-1.1-1.47-1.1-2.8 0-1.34.7-1.99.95-2.26.24-.27.53-.34.7-.34h.5c.16 0 .38-.03.58.44.22.51.75 1.77.82 1.9.06.13.1.28.02.46-.08.18-.13.29-.25.44-.13.16-.27.35-.38.47-.13.13-.27.28-.11.55.16.27.7 1.16 1.51 1.87 1.04.93 1.92 1.22 2.19 1.36.27.14.43.12.6-.07.16-.19.68-.79.86-1.06.18-.27.36-.22.6-.13.24.09 1.55.73 1.82.86.27.13.45.2.51.31.07.12.07.68-.15 1.36Z"/></svg>',
    edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.17 6.81a1 1 0 0 0-3.99-3.99L3.84 16.17a2 2 0 0 0-.5.83l-1.32 4.35a.5.5 0 0 0 .62.62l4.35-1.32a2 2 0 0 0 .83-.5Z"/><path d="m15 5 4 4"/></svg>',
    borrar: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>'
  };

  /* ---------- La lista: buscador, segmento e índice ---------- */
  function aplicarFiltros() {
    var n = sinT(buscar.value), seg = selSeg.value, visibles = 0, letras = {};
    filas.forEach(function (f) {
      var ok = (!n || f.dataset.q.indexOf(n) !== -1) && (seg === 'todas' || f.dataset.segs.split(' ').indexOf(seg) !== -1);
      f.hidden = !ok;
      if (ok) { visibles++; letras[f.dataset.letra] = true; }
    });
    Array.prototype.forEach.call(lista.querySelectorAll('.cd-letra'), function (l) { l.hidden = !letras[l.dataset.letra]; });
    Array.prototype.forEach.call(az.querySelectorAll('button'), function (b) { b.disabled = !letras[b.dataset.letra]; });
    vacio.hidden = visibles > 0;
  }
  buscar.addEventListener('input', aplicarFiltros);
  selSeg.addEventListener('change', aplicarFiltros);

  az.addEventListener('click', function (e) {
    var b = e.target.closest('button');
    if (!b || b.disabled) return;
    // Se mide con la primera clienta de la letra: el encabezado se queda pegado arriba al desplazarse y su posición no sirve.
    var primera = lista.querySelector('.cd-fila[data-letra="' + b.dataset.letra + '"]:not([hidden])');
    var cab = lista.querySelector('.cd-letra[data-letra="' + b.dataset.letra + '"]');
    if (primera && cab) lista.scrollTop = primera.offsetTop - cab.offsetHeight - 4;
  });

  /* ---------- La ficha ---------- */
  function etiquetas(c) {
    return c.segs.map(function (k) {
      return '<span class="cd-etq ' + k + '"><i></i>' + (k === 'cuo' && c.vencida ? 'Cuota vencida' : ETQ[k]) + '</span>';
    }).join('');
  }
  function mosaico(titulo, valor, extra) {
    return '<div class="cd-tile' + (extra && extra.ancho ? ' ancho' : '') + '"><span>' + titulo + '</span><b' + (extra && extra.txt ? ' class="txt"' : '') + (extra && extra.alerta ? ' style="color:var(--alerta)"' : '') + '>' + valor + '</b></div>';
  }
  function cabecera(c, botones) {
    return '<div class="cd-f-cab"><span class="cd-ini grande' + (c.vencida ? ' v' : '') + '" aria-hidden="true">' + esc(iniciales(c.nombre)) + '</span>' +
      '<div class="cd-f-nom"><h3>' + esc(c.nombre) + '</h3><p>' + (c.telefono ? esc(c.telefono) : 'Sin teléfono') + '</p></div>' + (botones || '') + '</div>';
  }
  function verFicha(c) {
    var botones = '<div class="cd-f-acc"><button type="button" class="cd-btn" data-editar>' + I.edit + 'Editar</button>' +
      '<form method="post" action="clientes.php" data-eliminar="' + esc(c.nombre) + '"><input type="hidden" name="csrf" value="' + esc(csrf) + '"><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="ids" value="' + esc(c.id) + '">' +
      '<button type="submit" class="cd-btn peligro">' + I.borrar + 'Eliminar</button></form></div>';
    var facturas = c.facturas.length
      ? c.facturas.map(function (f) {
        return '<a class="cd-fact" href="factura.php?n=' + encodeURIComponent(f.n) + '"><b>' + esc(f.n) + '</b><span>' + esc(f.f) + '</span><span class="cd-fact-t">' + pesos(f.t) + '</span><span class="cd-etq ' + f.c + '"><i></i>' + esc(f.e) + '</span></a>';
      }).join('')
      : '<p class="cd-fino">Todavía no tiene facturas.</p>';
    var ofertas = c.ofertas.length
      ? '<div class="cd-ofertas">' + c.ofertas.map(function (o) {
        return '<a class="cd-btn wa" href="' + esc(o.url) + '" target="_blank" rel="noopener noreferrer">' + I.wa + esc(o.t) + '</a>';
      }).join('') + '</div>'
      : '<p class="cd-fino">' + (c.telefono ? 'Todavía no hay datos de compra para ofrecerle algo.' : 'Agrega un teléfono para poder escribirle.') + '</p>';
    var cartera = c.debe > 0 ? '<a class="cd-enlace" href="cartera.php?q=' + encodeURIComponent(c.nombre) + '">Ver sus facturas en Cartera</a>' : '';
    return cabecera(c, botones) +
      '<div class="cd-etqs">' + (etiquetas(c) || '<span class="cd-fino">Sin segmentos especiales</span>') + '</div>' +
      '<div class="cd-tiles">' + mosaico('Compras', c.compras) + mosaico('Cada cuánto compra', c.frecuencia === null ? '—' : (c.frecuencia < 1 ? 'Muy seguido' : '~' + c.frecuencia + ' días')) + mosaico('Última compra', esc(c.ultima), { txt: true }) +
        mosaico('Producto favorito', c.favorito ? esc(c.favorito) : '—', { txt: true, ancho: true }) + mosaico('Saldo a favor', pesos(c.saldo)) +
        mosaico('Por cobrar', pesos(c.debe), { alerta: c.vencida }) + '</div>' + cartera +
      (c.notas ? '<p class="cd-nota">' + esc(c.notas) + '</p>' : '') +
      '<div class="cd-sec"><h4>Facturas recientes</h4>' + facturas + '</div>' +
      '<div class="cd-sec"><h4>Escribirle por WhatsApp</h4>' + ofertas + '</div>';
  }
  function formFicha(c) {
    var nueva = !c;
    var v = c || { id: '', nombre: '', telefono: '', notas: '', saldo: 0 };
    return '<form method="post" action="clientes.php" class="cd-form"' + (nueva ? ' data-nueva' : '') + '>' +
      '<h3>' + (nueva ? 'Agregar cliente' : 'Editar a ' + esc(v.nombre)) + '</h3>' +
      '<input type="hidden" name="csrf" value="' + esc(csrf) + '">' + (nueva ? '' : '<input type="hidden" name="accion" value="editar"><input type="hidden" name="id" value="' + esc(v.id) + '">') +
      '<div class="campo"><label for="cd-f-nombre">Nombre completo</label><input type="text" id="cd-f-nombre" name="nombre" value="' + esc(v.nombre) + '" placeholder="Gina Pertuz" required></div>' +
      '<div class="campo"><label for="cd-f-tel">Teléfono</label><input type="tel" id="cd-f-tel" name="telefono" value="' + esc(v.telefono) + '" placeholder="3001234567" inputmode="numeric"></div>' +
      (nueva ? '' : '<div class="campo"><label for="cd-f-saldo">Saldo a favor</label><input type="number" id="cd-f-saldo" name="saldo_favor" value="' + v.saldo + '" min="0" step="500"></div>') +
      '<div class="campo ancho"><label for="cd-f-notas">Notas</label><input type="text" id="cd-f-notas" name="notas" value="' + esc(v.notas) + '" placeholder="' + (nueva ? 'Opcional' : 'Notas') + '"></div>' +
      '<div class="cd-form-fin"><button type="submit" class="cd-btn principal">' + (nueva ? 'Agregar cliente' : 'Guardar') + '</button><button type="button" class="cd-btn" data-cancelar>Cancelar</button></div></form>';
  }
  function mostrar(c, modo) {
    ficha.classList.toggle('v', !!(c && c.vencida && modo === 'ver'));
    if (modo === 'ver') {
      ficha.innerHTML = verFicha(c);
    } else {
      ficha.innerHTML = '<div class="cd-f-form">' + formFicha(c) + '</div>';
      var primero = ficha.querySelector('input[name="nombre"]');
      if (primero) { primero.focus(); primero.select(); }
    }
  }

  function elegir(id, opciones) {
    var c = D.clientes[id];
    if (!c) return;
    estado.sel = id;
    filas.forEach(function (f) { f.setAttribute('aria-current', String(f.dataset.id === id)); });
    mostrar(c, 'ver');
    try { history.replaceState(null, '', '#c-' + encodeURIComponent(id)); } catch (e) { /* sin historial: no pasa nada */ }
    if (opciones && opciones.desplazar) {
      var fila = lista.querySelector('.cd-fila[data-id="' + (window.CSS && CSS.escape ? CSS.escape(id) : id) + '"]');
      if (fila) fila.scrollIntoView({ block: 'nearest' });
    }
  }

  lista.addEventListener('click', function (e) {
    var f = e.target.closest('.cd-fila');
    if (f) elegir(f.dataset.id);
  });
  lista.addEventListener('keydown', function (e) {
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
    var visibles = filas.filter(function (f) { return !f.hidden; });
    var i = visibles.indexOf(document.activeElement);
    if (i < 0) return;
    var sig = visibles[i + (e.key === 'ArrowDown' ? 1 : -1)];
    if (!sig) return;
    e.preventDefault();
    sig.focus();
    elegir(sig.dataset.id, { desplazar: true });
  });

  ficha.addEventListener('click', function (e) {
    if (e.target.closest('[data-editar]')) { mostrar(D.clientes[estado.sel], 'editar'); return; }
    if (e.target.closest('[data-cancelar]')) {
      if (estado.sel) mostrar(D.clientes[estado.sel], 'ver');
      else ficha.innerHTML = '<p class="cd-vacio-ficha">Elige una clienta de la lista para ver su ficha.</p>';
    }
  });
  ficha.addEventListener('submit', function (e) {
    var f = e.target.closest('form[data-eliminar]');
    if (f && !window.confirm('¿Eliminar a ' + f.dataset.eliminar + '? Esta acción no se puede deshacer.')) e.preventDefault();
  });

  $('cd-nuevo').addEventListener('click', function () {
    estado.sel = null;
    filas.forEach(function (f) { f.setAttribute('aria-current', 'false'); });
    ficha.classList.remove('v');
    ficha.innerHTML = '<div class="cd-f-form">' + formFicha(null) + '</div>';
    ficha.querySelector('input[name="nombre"]').focus();
  });

  /* ---------- Al abrir: la clienta de ?sel= (tras guardar), la del enlace o la primera ---------- */
  var pedida = new URLSearchParams(location.search).get('sel') || decodeURIComponent((location.hash.match(/^#c-(.+)$/) || [])[1] || '');
  if (!D.clientes[pedida]) pedida = filas.length ? filas[0].dataset.id : '';
  if (pedida) {
    elegir(pedida, { desplazar: true });
  } else {
    ficha.innerHTML = '<p class="cd-vacio-ficha">Todavía no hay clientes en la lista. Usa "Agregar" para empezar, o trae a las que ya están en facturación.</p>';
  }
  aplicarFiltros();
})();
