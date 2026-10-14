/* =========================================================
   Cartera y reportes · cifras de "Reportes" en celular
   =========================================================
   Cambia de periodo (Hoy · Este mes · Este año) en la tarjeta de includes/cartera_reportes_celular.php.
   Los tres paneles ya vienen escritos por el servidor; aquí solo se muestra uno y se esconden los demás.
   Con teclado: flechas izquierda y derecha cambian de periodo; Inicio y Fin van al primero y al último.
   ========================================================= */
(function () {
  'use strict';

  var raiz = document.getElementById('rc');
  if (!raiz) return;
  var tabs = Array.prototype.slice.call(raiz.querySelectorAll('.rc-tab'));

  function elegir(tab, enfocar) {
    tabs.forEach(function (t) {
      var activo = t === tab;
      t.setAttribute('aria-selected', String(activo));
      t.tabIndex = activo ? 0 : -1;
      document.getElementById(t.getAttribute('aria-controls')).hidden = !activo;
    });
    if (enfocar) tab.focus();
  }

  raiz.addEventListener('click', function (e) {
    var t = e.target.closest('.rc-tab');
    if (t) elegir(t, false);
  });
  raiz.addEventListener('keydown', function (e) {
    var i = tabs.indexOf(document.activeElement);
    if (i < 0) return;
    var destino = e.key === 'ArrowRight' ? tabs[(i + 1) % tabs.length]
      : e.key === 'ArrowLeft' ? tabs[(i + tabs.length - 1) % tabs.length]
      : e.key === 'Home' ? tabs[0]
      : e.key === 'End' ? tabs[tabs.length - 1] : null;
    if (!destino) return;
    e.preventDefault();
    elegir(destino, true);
  });
})();
