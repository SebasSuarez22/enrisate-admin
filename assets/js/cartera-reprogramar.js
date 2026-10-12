/* =========================================================
   Cartera · ventana "Reprogramar"
   =========================================================
   Cambia la fecha de vencimiento de una cuota y, si se pide, corre las siguientes los
   mismos días. Esta ventana solo MUESTRA cómo va a quedar: la regla de verdad (y la que
   manda) es reprogramar_cuotas() en includes/cuotas.php, que el servidor vuelve a aplicar
   al guardar. Si se cambia una, hay que cambiar la otra.

   Los datos salen de <script id="datos-reprogramar"> (una entrada por factura con cuotas
   pendientes); cada botón .btn-reprogramar trae solo el número de factura y la cuota.
   ========================================================= */
(function () {
  'use strict';

  var dlg = document.getElementById('reprogramar');
  var datosEl = document.getElementById('datos-reprogramar');
  if (!dlg || !datosEl || typeof dlg.showModal !== 'function') return;

  var DATOS;
  try { DATOS = JSON.parse(datosEl.textContent); } catch (e) { return; }

  var MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  var DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
  var MES_C = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

  function deIso(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || '');
    if (!m) return null;
    var f = new Date(+m[1], +m[2] - 1, +m[3]);
    return f.getMonth() === +m[2] - 1 ? f : null;
  }
  function iso(f) { return f.getFullYear() + '-' + String(f.getMonth() + 1).padStart(2, '0') + '-' + String(f.getDate()).padStart(2, '0'); }
  function mas(f, n) { return new Date(f.getFullYear(), f.getMonth(), f.getDate() + n); }
  function dif(a, b) { return Math.round((a - b) / 86400000); }
  function larga(f) { return f.getDate() + ' de ' + MESES[f.getMonth()] + ' de ' + f.getFullYear(); }
  function corta(f) { return f.getDate() + ' ' + MES_C[f.getMonth()] + ' ' + f.getFullYear(); }
  function pesos(n) { return '$' + String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
  function signo(n) { return (n > 0 ? '+' : '−') + Math.abs(n) + (Math.abs(n) === 1 ? ' día' : ' días'); }
  function may(t) { return t.charAt(0).toUpperCase() + t.slice(1); }
  function minus1(t) { return t.charAt(0).toLowerCase() + t.slice(1); }

  var HOY = deIso(dlg.dataset.hoy) || new Date();
  HOY = new Date(HOY.getFullYear(), HOY.getMonth(), HOY.getDate());
  var NEGOCIO = dlg.dataset.negocio || '';

  var form = document.getElementById('rp-form');
  var $ = function (id) { return document.getElementById(id); };
  var el = {
    titulo: $('rp-titulo'), sub: $('rp-sub'), previo: $('rp-previo'), mes: $('rp-mes'), rej: $('rp-rej'),
    ant: $('rp-ant'), sig: $('rp-sig'), error: $('rp-error'), lista: $('rp-lista'), alcance: $('rp-alcance'),
    wa: $('rp-wa'), avisar: $('rp-avisar'), avisarTxt: $('rp-avisar-txt'), msg: $('rp-msg'),
    guardar: $('rp-guardar'), guardarTxt: $('rp-guardar-txt')
  };
  var st = { data: null, numero: '', idx: 0, fecha: null, correr: true, mes: null, opener: null, enviando: false };

  /* La misma regla que reprogramar_cuotas() en PHP. */
  function calcular() {
    var cu = st.data.cuotas, ed = st.idx;
    var base = deIso(cu[ed].vence);
    var min = mas(HOY, 1);
    if (ed > 0) { var ant = deIso(cu[ed - 1].vence); if (ant && mas(ant, 1) > min) min = mas(ant, 1); }
    var f = st.fecha ? deIso(st.fecha) : null;
    var error = '';
    if (f) {
      if (f < min) error = 'La fecha debe ser desde el ' + larga(min) + ' y después de la cuota anterior.';
      else if (!st.correr && cu[ed + 1] && f > deIso(cu[ed + 1].vence)) error = 'Pasa de la fecha de la cuota siguiente (' + larga(deIso(cu[ed + 1].vence)) + '). Corre también las siguientes o elige una fecha anterior.';
      else if (dif(f, base) === 0) error = 'Es la misma fecha que ya tiene. Elige otra.';
    }
    var valida = !!f && !error;
    var delta = f ? dif(f, base) : 0;
    var filas = cu.map(function (c, i) {
      var antes = deIso(c.vence), fila = { r: c.r, i: i, antes: antes, despues: antes, delta: 0, estado: 'fija' };
      if (c.pagada) { fila.estado = 'pagada'; return fila; }
      if (i < ed) return fila;
      if (i === ed) { fila.estado = 'edita'; if (valida) { fila.despues = f; fila.delta = delta; } return fila; }
      if (st.correr && valida) { fila.despues = mas(antes, delta); fila.delta = delta; fila.estado = 'mueve'; }
      return fila;
    });
    return { min: min, base: base, f: f, error: error, valida: valida, filas: filas, delta: delta };
  }
  function cambia(fila) { return fila.despues.getTime() !== fila.antes.getTime(); }
  function hayPosteriores() {
    var cu = st.data.cuotas;
    for (var i = st.idx + 1; i < cu.length; i++) if (!cu[i].pagada) return true;
    return false;
  }

  /* El texto que se manda: igual a mensaje_reprogramacion() en includes/mensajes.php. */
  function fechaMsg(f) { return f.getDate() + ' de ' + MESES[f.getMonth()] + (f.getFullYear() !== HOY.getFullYear() ? ' de ' + f.getFullYear() : ''); }
  function mensaje(res) {
    var nombre = (st.data.cliente || '').trim().split(' ')[0] || '';
    var fila = res.filas[st.idx];
    var t = 'Hola' + (nombre ? ' ' + nombre : '') + ' 💛 Quedó anotado el cambio: tu ' + minus1(fila.r) + ' de ' + NEGOCIO + ' ahora vence el ' + fechaMsg(fila.despues) + '.';
    var sig = res.filas.filter(function (x) { return x.estado === 'mueve' && cambia(x); });
    if (sig.length) {
      var partes = sig.map(function (x) { return 'la ' + minus1(x.r.replace(' cuota', '')) + ' el ' + fechaMsg(x.despues); });
      var ultima = partes.pop();
      t += ' Las siguientes quedan así: ' + (partes.length ? partes.join(', ') + ' y ' : '') + ultima + '.';
    }
    return t + ' ¡Gracias!';
  }

  function pintarCalendario(res) {
    var m = st.mes, desfase = (m.getDay() + 6) % 7;
    var dm = new Date(m.getFullYear(), m.getMonth() + 1, 0).getDate();
    el.mes.textContent = may(MESES[m.getMonth()]) + ' ' + m.getFullYear();
    el.rej.textContent = '';
    for (var i = 0; i < desfase; i++) el.rej.appendChild(document.createElement('span'));
    var movidas = {}, pagadas = {};
    res.filas.forEach(function (f) {
      if (f.estado === 'mueve' && cambia(f)) movidas[f.despues.getTime()] = true;
      if (f.estado === 'pagada') pagadas[f.antes.getTime()] = true;
    });
    for (var n = 1; n <= dm; n++) {
      var d = new Date(m.getFullYear(), m.getMonth(), n), t = d.getTime(), b = document.createElement('button');
      b.type = 'button'; b.className = 'rp-dia'; b.textContent = n;
      b.setAttribute('aria-label', DIAS[d.getDay()] + ' ' + larga(d));
      b.disabled = d < res.min;
      if (t === res.base.getTime()) b.classList.add('antes');
      if (t === HOY.getTime()) b.classList.add('hoy');
      if (movidas[t]) b.classList.add('mueve');
      if (pagadas[t]) b.classList.add('paga');
      b.setAttribute('aria-pressed', res.f && t === res.f.getTime() ? 'true' : 'false');
      b.dataset.iso = iso(d);
      el.rej.appendChild(b);
    }
    el.ant.disabled = new Date(m.getFullYear(), m.getMonth(), 1) <= new Date(HOY.getFullYear(), HOY.getMonth(), 1);
  }

  function pintar() {
    var res = calcular();
    pintarCalendario(res);
    el.error.textContent = res.error;
    el.lista.textContent = '';
    res.filas.forEach(function (f) {
      var tag = f.estado === 'pagada' ? 'Pagada' : cambia(f) ? signo(f.delta) : 'Sin cambio';
      var li = document.createElement('li');
      li.className = 'rp-fila ' + f.estado;
      var nombre = document.createElement('span'); nombre.className = 'rp-fila-nombre'; nombre.textContent = f.r;
      var fechas = document.createElement('span'); fechas.className = 'rp-fila-fechas';
      if (cambia(f)) {
        var s = document.createElement('s'); s.textContent = corta(f.antes);
        var b = document.createElement('b'); b.textContent = corta(f.despues);
        fechas.appendChild(s); fechas.appendChild(b);
      } else {
        fechas.textContent = corta(f.despues);
      }
      var tg = document.createElement('span'); tg.className = 'rp-fila-tag'; tg.textContent = tag;
      li.appendChild(nombre); li.appendChild(fechas); li.appendChild(tg);
      el.lista.appendChild(li);
    });

    var tieneTel = (st.data.telefono || '').trim() !== '';
    var avisar = tieneTel && el.avisar.checked;
    el.wa.hidden = !tieneTel;
    el.msg.hidden = !avisar || !res.valida;
    if (res.valida) el.msg.textContent = mensaje(res);
    el.guardarTxt.textContent = avisar ? 'Guardar y avisar' : 'Guardar fecha';
    el.guardar.disabled = !res.valida || st.enviando;
    form.elements.fecha.value = res.valida ? st.fecha : '';
    form.elements.cuota_idx.value = String(st.idx);
    form.elements.numero.value = st.numero;
  }

  function abrir(numero, idx, boton) {
    var data = DATOS[numero];
    if (!data || !data.cuotas[idx]) return;
    st.data = data; st.numero = numero; st.idx = idx; st.fecha = null; st.correr = true; st.enviando = false; st.opener = boton;
    var c = data.cuotas[idx];
    el.titulo.textContent = 'Reprogramar la ' + minus1(c.r);
    el.sub.textContent = data.cliente + ' · factura ' + numero + ' · pendiente ' + pesos(c.pendiente);
    var previo = data.cambios && data.cambios[idx];
    el.previo.hidden = !previo;
    if (previo) el.previo.textContent = 'Ya se cambió ' + (previo.veces === 1 ? '1 vez' : previo.veces + ' veces') + ': la fecha original era el ' + larga(deIso(previo.antes)) + '.';
    form.elements.alcance.value = 'siguientes';
    el.alcance.hidden = !hayPosteriores();
    var tieneTel = (data.telefono || '').trim() !== '';
    el.avisar.checked = tieneTel;
    el.avisarTxt.textContent = 'Avisar a ' + ((data.cliente || '').trim().split(' ')[0] || 'la clienta') + ' por WhatsApp';
    var base = deIso(c.vence), min = mas(HOY, 1);
    var ref = base && base > min ? base : min;
    st.mes = new Date(ref.getFullYear(), ref.getMonth(), 1);
    pintar();
    document.documentElement.classList.add('rp-abierto');
    dlg.showModal();
    dlg.scrollTop = 0;
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('.btn-reprogramar') : null;
    if (b) { e.preventDefault(); abrir(b.dataset.numero, parseInt(b.dataset.idx, 10), b); }
  });
  el.rej.addEventListener('click', function (e) {
    var b = e.target.closest('.rp-dia');
    if (!b || b.disabled) return;
    st.fecha = b.dataset.iso;
    pintar();
  });
  el.ant.addEventListener('click', function () { st.mes = new Date(st.mes.getFullYear(), st.mes.getMonth() - 1, 1); pintar(); });
  el.sig.addEventListener('click', function () { st.mes = new Date(st.mes.getFullYear(), st.mes.getMonth() + 1, 1); pintar(); });
  Array.prototype.forEach.call(form.elements.alcance, function (r) {
    r.addEventListener('change', function () { st.correr = form.elements.alcance.value === 'siguientes'; pintar(); });
  });
  el.avisar.addEventListener('change', pintar);
  form.addEventListener('submit', function (e) {
    var res = calcular();
    if (!res.valida || st.enviando) { e.preventDefault(); return; }
    st.enviando = true;
    el.guardar.disabled = true;
    el.guardarTxt.textContent = 'Guardando…';
  });

  function cerrar() { if (dlg.open) dlg.close(); }
  $('rp-cerrar').addEventListener('click', cerrar);
  $('rp-cancelar').addEventListener('click', cerrar);
  dlg.addEventListener('click', function (e) { if (e.target === dlg) cerrar(); }); // clic en el fondo oscuro
  dlg.addEventListener('close', function () {
    document.documentElement.classList.remove('rp-abierto');
    if (st.opener && document.contains(st.opener)) st.opener.focus();
  });
})();
