/* =========================================================
   Cartera · tablero de tablet y celular
   =========================================================
   Hace funcionar lo que cartera.php (includes/cartera_tablero.php) dibuja hasta 959px:
   - una sola búsqueda de clientas, facturas y productos;
   - los filtros de "Todas las cuotas" (por urgencia y por clienta);
   - la ventana "Cobrar", con los mismos formularios que el panel de escritorio (abono o descuento);
   - "Historial" y "Detalle por SKU" cerrados al abrir, para que los reportes queden a mano.
   Los datos salen de <script id="datos-tablero">; el servidor sigue siendo quien valida todo.
   ========================================================= */
(function () {
  'use strict';

  var raiz = document.getElementById('tablero');
  var datosEl = document.getElementById('datos-tablero');
  if (!raiz || !datosEl) return;

  var D;
  try { D = JSON.parse(datosEl.textContent); } catch (e) { return; }

  var $ = function (id) { return document.getElementById(id); };
  var pesos = function (n) { return '$' + Math.round(n).toLocaleString('es-CO'); };
  var sinT = function (t) { return String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim(); };
  var ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' };
  var esc = function (t) { return String(t).replace(/[&<>"]/g, function (c) { return ESC[c]; }); };
  var soloNumeros = function (t) { return parseInt(String(t).replace(/\D/g, ''), 10) || 0; };
  var conPuntos = function (n) { return n ? n.toLocaleString('es-CO') : ''; };
  var compacto = window.matchMedia('(max-width: 959px)');

  /* ---------- "Para atender primero" en celular: una cuota abierta a la vez ---------- */
  var listaUrg = raiz.querySelector('.tc-urg-lista');
  if (listaUrg) {
    listaUrg.addEventListener('click', function (e) {
      var boton = e.target.closest('.tc-abrir');
      if (!boton) return;
      var carta = boton.closest('.tc-carta');
      var abrir = !carta.classList.contains('abierta');
      listaUrg.querySelectorAll('.tc-carta.abierta').forEach(function (c) {
        c.classList.remove('abierta');
        c.querySelector('.tc-abrir').setAttribute('aria-expanded', 'false');
      });
      if (abrir) {
        carta.classList.add('abierta');
        boton.setAttribute('aria-expanded', 'true');
      }
    });
  }

  /* ---------- Filtros de "Todas las cuotas" ---------- */
  var filtro = { grupo: 'todas', clienta: null };
  var lista = $('tc-lista');
  var vacio = $('tc-vacio');
  var barraCliente = $('tc-filtro-cliente');

  function aplicarFiltros() {
    if (!lista) return;
    var k = filtro.clienta ? sinT(filtro.clienta) : null, visibles = 0;
    Array.prototype.forEach.call(lista.querySelectorAll('.tc-fila'), function (fila) {
      var ok = (filtro.grupo === 'todas' || fila.dataset.grupo === filtro.grupo) && (!k || sinT(fila.dataset.cliente) === k);
      fila.hidden = !ok;
      if (ok) visibles++;
    });
    Array.prototype.forEach.call(raiz.querySelectorAll('[data-tc-filtro]'), function (b) {
      b.setAttribute('aria-pressed', String(b.dataset.tcFiltro === filtro.grupo));
    });
    barraCliente.hidden = !filtro.clienta;
    if (filtro.clienta) $('tc-filtro-nombre').textContent = filtro.clienta;
    vacio.hidden = visibles > 0;
    if (!visibles) {
      vacio.textContent = filtro.clienta
        ? filtro.clienta + ' no tiene cuotas en este grupo.'
        : 'No hay cuotas en este grupo.';
    }
  }

  raiz.addEventListener('click', function (e) {
    var b = e.target.closest('[data-tc-filtro]');
    if (b) { filtro.grupo = b.dataset.tcFiltro; aplicarFiltros(); return; }
    if (e.target.closest('#tc-filtro-quitar')) { filtro.clienta = null; aplicarFiltros(); return; }
    var c = e.target.closest('[data-tc-cobrar]');
    if (c) abrirCobrar(c.dataset.tcCobrar);
  });

  /* ---------- Ventana "Cobrar" ---------- */
  var dlg = $('tc-cobrar');
  var actual = null;
  var opener = null;
  var abrirCobrar = function () {};   // la ventana la reemplaza si el navegador la soporta

  if (dlg && typeof dlg.showModal === 'function') {
    var formAbono = $('tc-form-abono'), formDesc = $('tc-form-desc');
    var montoA = $('tc-monto'), montoD = $('tc-monto-d'), motivo = $('tc-motivo');
    var errorA = $('tc-error'), errorD = $('tc-error-d');
    var atajos = Array.prototype.slice.call(dlg.querySelectorAll('.tc-atajo'));
    var enlaceWa = $('tc-wa'), enlaceFecha = $('tc-ir-fecha'), enlaceDesc = $('tc-ir-desc');

    var mostrar = function (cual) {
      formAbono.hidden = cual !== 'abono';
      formDesc.hidden = cual !== 'descuento';
      enlaceDesc.hidden = cual === 'descuento';
    };
    var decir = function (el, campo, t) { el.textContent = t || ''; campo.setAttribute('aria-invalid', t ? 'true' : 'false'); };
    var sincronizar = function (form, campo) {
      var n = soloNumeros(campo.value);
      form.elements.monto.value = n;
      return n;
    };
    var marcarAtajos = function () {
      var n = soloNumeros(montoA.value);
      atajos.forEach(function (a) { a.setAttribute('aria-pressed', String(parseInt(a.dataset.valor, 10) === n)); });
    };

    function abrirDialogo(id) {
      var c = D.cuotas[id];
      if (!c) return;
      actual = c;
      opener = document.activeElement;
      $('tc-cobrar-t').textContent = c.cliente;
      $('tc-cobrar-sub').textContent = c.rotulo + ' · vence ' + c.venceTxt + ' · factura ' + c.numero;
      $('tc-cobrar-pend').textContent = pesos(c.pendiente);
      [formAbono, formDesc].forEach(function (f) {
        f.elements.numero.value = c.numero;
        f.elements.cuota_idx.value = c.idx;
        f.elements.monto.value = c.pendiente;
      });
      montoA.value = conPuntos(c.pendiente);
      montoD.value = conPuntos(c.pendiente);
      motivo.value = '';
      decir(errorA, montoA, ''); decir(errorD, montoD, '');

      var mitad = Math.round(c.pendiente / 2);
      atajos[0].dataset.valor = c.pendiente; atajos[0].textContent = 'Total ' + pesos(c.pendiente);
      atajos[1].dataset.valor = mitad;       atajos[1].textContent = 'Mitad ' + pesos(mitad);
      atajos[1].hidden = c.pendiente < 2;
      marcarAtajos();

      enlaceWa.hidden = !c.wa;
      if (c.wa) {
        enlaceWa.href = c.wa;
        enlaceWa.querySelector('span').textContent = c.mora ? 'Escribir por WhatsApp (mora)' : 'Recordar por WhatsApp';
      }
      enlaceFecha.dataset.numero = c.numero;
      enlaceFecha.dataset.idx = c.idx;
      mostrar('abono');
      dlg.showModal();
      montoA.focus();
      montoA.select();
    }
    abrirCobrar = abrirDialogo;

    function cerrar() { dlg.close(); }
    dlg.addEventListener('close', function () { if (opener && opener.focus && document.contains(opener)) opener.focus(); });
    dlg.addEventListener('click', function (e) {
      if (e.target === dlg || e.target.closest('[data-tc-cerrar]')) cerrar(); // clic en el fondo oscuro o en la X
    });
    enlaceFecha.addEventListener('click', cerrar); // la ventana de cambiar fecha la abre cartera-reprogramar.js
    enlaceDesc.addEventListener('click', function () { mostrar('descuento'); montoD.focus(); montoD.select(); });
    dlg.querySelector('[data-tc-volver]').addEventListener('click', function () { mostrar('abono'); montoA.focus(); });

    atajos.forEach(function (a) {
      a.addEventListener('click', function () {
        montoA.value = conPuntos(parseInt(a.dataset.valor, 10));
        decir(errorA, montoA, '');
        marcarAtajos();
        montoA.focus();
      });
    });
    [[montoA, errorA, formAbono], [montoD, errorD, formDesc]].forEach(function (par) {
      par[0].addEventListener('input', function () {
        par[0].value = conPuntos(soloNumeros(par[0].value));
        sincronizar(par[2], par[0]);
        decir(par[1], par[0], '');
        if (par[0] === montoA) marcarAtajos();
      });
    });

    function validar(form, campo, error, esDescuento) {
      var monto = sincronizar(form, campo);
      if (monto < 1) { decir(error, campo, 'Escribe un monto mayor a $0.'); campo.focus(); return false; }
      if (monto > actual.pendiente) {
        decir(error, campo, 'No puede pasar de ' + pesos(actual.pendiente) + ', que es lo que falta de esta cuota.');
        campo.focus();
        return false;
      }
      if (esDescuento) {
        if (motivo.value.trim() === '') { decir(error, motivo, 'Escribe el motivo del descuento.'); motivo.focus(); return false; }
        if (!window.confirm('¿Aplicar un descuento de ' + pesos(monto) + ' a esta cuota? No es un abono real: quedará marcado como descuento.')) return false;
      }
      return true;
    }
    formAbono.addEventListener('submit', function (e) { if (!validar(formAbono, montoA, errorA, false)) e.preventDefault(); });
    formDesc.addEventListener('submit', function (e) { if (!validar(formDesc, montoD, errorD, true)) e.preventDefault(); });
  }

  /* ---------- Buscador de clientas, facturas y productos ---------- */
  var q = $('tc-q'), res = $('tc-res'), borrar = $('tc-borrar');

  function cerrarRes() { res.hidden = true; q.setAttribute('aria-expanded', 'false'); }
  function abrirRes() { res.hidden = false; q.setAttribute('aria-expanded', 'true'); }

  function resultados() {
    var n = sinT(q.value);
    borrar.hidden = !q.value;
    if (!n) { sugerencias(); return; }
    var terminos = n.split(/\s+/);
    var coincide = function (texto) { var h = sinT(texto); return terminos.every(function (t) { return h.indexOf(t) !== -1; }); };

    var cl = D.clientas.filter(function (c) { return coincide(c.nombre); }).slice(0, 4);
    var fa = D.facturas.filter(function (f) { return coincide(f.n + ' ' + f.c); }).slice(0, 4);
    var pr = D.productos.filter(function (p) { return coincide(p.n + ' ' + p.sku + ' ' + p.m); }).slice(0, 4);
    var html = '';

    if (cl.length) {
      html += '<h3>Clientas</h3>' + cl.map(function (c) {
        return '<div class="tc-item"><button type="button" class="tc-item-main" data-clienta="' + esc(c.nombre) + '"><b>' + esc(c.nombre) + '</b><small>' +
          (c.cuotas ? pesos(c.pendiente) + ' por cobrar en ' + c.cuotas + (c.cuotas === 1 ? ' cuota' : ' cuotas') : 'Sin cuotas pendientes') + '</small></button>' +
          (c.wa ? '<a class="tc-item-wa" href="' + esc(c.wa) + '" target="_blank" rel="noopener" aria-label="Abrir WhatsApp con ' + esc(c.nombre) + '"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2C6.49 2 2 6.49 2 12c0 1.87.5 3.62 1.38 5.13L2 22l4.99-1.31A9.94 9.94 0 0 0 12 22c5.51 0 10-4.49 10-10S17.51 2 12 2Zm5.2 14.2c-.22.62-1.28 1.2-1.77 1.24-.45.05-.98.24-3.29-.7-2.77-1.13-4.53-3.92-4.66-4.1-.14-.18-1.1-1.47-1.1-2.8 0-1.34.7-1.99.95-2.26.24-.27.53-.34.7-.34h.5c.16 0 .38-.03.58.44.22.51.75 1.77.82 1.9.06.13.1.28.02.46-.08.18-.13.29-.25.44-.13.16-.27.35-.38.47-.13.13-.27.28-.11.55.16.27.7 1.16 1.51 1.87 1.04.93 1.92 1.22 2.19 1.36.27.14.43.12.6-.07.16-.19.68-.79.86-1.06.18-.27.36-.22.6-.13.24.09 1.55.73 1.82.86.27.13.45.2.51.31.07.12.07.68-.15 1.36Z"/></svg></a>' : '') + '</div>';
      }).join('');
    }
    if (fa.length) {
      html += '<h3>Facturas</h3>' + fa.map(function (f) {
        return '<div class="tc-item"><a class="tc-item-main" href="factura.php?n=' + encodeURIComponent(f.n) + '"><b>' + esc(f.n) + ' · ' + esc(f.c) + '</b><small>' + esc(f.f) + ' · ' + esc(f.e) + '</small></a><span class="tc-item-der">' + pesos(f.t) + '</span></div>';
      }).join('');
    }
    if (pr.length) {
      html += '<h3>Productos</h3>' + pr.map(function (p) {
        return '<div class="tc-item"><button type="button" class="tc-item-main" data-sku="' + esc(p.sku) + '"><b>' + esc(p.n) + '</b><small>' + esc(p.m) + ' · ' + esc(p.sku) + '</small></button><span class="tc-item-der">' + p.u + (p.u === 1 ? ' ud' : ' uds') + '</span></div>';
      }).join('');
    }
    res.innerHTML = html || '<p class="tc-res-nada">Nada coincide con "' + esc(q.value) + '". Prueba con un nombre, un número de factura o una marca.</p>';
    abrirRes();
  }

  function sugerencias() {
    var ejemplos = [];
    D.clientas.slice(0, 2).forEach(function (c) { ejemplos.push(c.nombre.split(' ')[0]); });
    if (D.facturas[0]) ejemplos.push(D.facturas[0].n);
    if (D.productos[0]) ejemplos.push(D.productos[0].m);
    if (!ejemplos.length) { cerrarRes(); return; }
    res.innerHTML = '<h3>Prueba con</h3><div class="tc-sugeridas">' + ejemplos.map(function (t) {
      return '<button type="button" class="tc-chip" data-sugerida="' + esc(t) + '">' + esc(t) + '</button>';
    }).join('') + '</div>';
    abrirRes();
  }

  function irA(el) {
    if (!el) return;
    el.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
  }

  q.addEventListener('input', resultados);
  q.addEventListener('focus', function () { if (!q.value) sugerencias(); else resultados(); });
  q.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { cerrarRes(); return; }
    if (e.key === 'ArrowDown') {
      var primero = res.querySelector('button, a');
      if (primero) { e.preventDefault(); primero.focus(); }
    }
  });
  res.addEventListener('keydown', function (e) {
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
    var items = Array.prototype.slice.call(res.querySelectorAll('button, a'));
    var i = items.indexOf(document.activeElement);
    if (i < 0) return;
    e.preventDefault();
    var sig = items[i + (e.key === 'ArrowDown' ? 1 : -1)];
    if (sig) sig.focus(); else if (e.key === 'ArrowUp') q.focus();
  });
  borrar.addEventListener('click', function () { q.value = ''; borrar.hidden = true; q.focus(); sugerencias(); });

  res.addEventListener('click', function (e) {
    var s = e.target.closest('[data-sugerida]');
    if (s) { q.value = s.dataset.sugerida; resultados(); q.focus(); return; }
    var c = e.target.closest('[data-clienta]');
    if (c) {
      filtro.clienta = c.dataset.clienta; filtro.grupo = 'todas';
      aplicarFiltros(); cerrarRes(); irA($('tc-todas'));
      return;
    }
    var p = e.target.closest('[data-sku]');
    if (p) {
      cerrarRes();
      var det = $('sku-det'), tarjeta = $('sku-c-' + p.dataset.sku), fila = $('sku-t-' + p.dataset.sku);
      if (det) det.open = true;
      var destino = tarjeta && tarjeta.offsetParent !== null ? tarjeta : fila;
      if (destino && destino.tagName === 'DETAILS') destino.open = true;
      if (destino) { irA(destino); destino.classList.add('tc-resaltado'); window.setTimeout(function () { destino.classList.remove('tc-resaltado'); }, 2200); }
    }
  });
  document.addEventListener('click', function (e) { if (!e.target.closest('.tc-buscar')) cerrarRes(); });

  /* ---------- Historial y Detalle por SKU: cerrados al abrir en tablet y celular ---------- */
  var buscandoHistorial = location.search.indexOf('q=') !== -1 || location.hash === '#historial';
  if (compacto.matches) {
    var sku = $('sku-det'), hist = $('historial-det');
    if (sku) sku.open = false;
    if (hist && !buscandoHistorial) hist.open = false;
  }

  aplicarFiltros();
})();
