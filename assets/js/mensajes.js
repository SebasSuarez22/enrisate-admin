/* Mensajes: al tocar "Abrir WhatsApp y enviar", el enlace abre WhatsApp en otra
   pestaña (comportamiento normal del enlace) y, a la vez, esta página marca a la
   clienta como enviada y recarga para mostrar a la siguiente. Sin JavaScript queda
   el botón "Ya la envié", que hace lo mismo con un formulario. */
(function () {
  var enlace = document.getElementById('abrirWhatsapp');
  if (!enlace) { return; }
  enlace.addEventListener('click', function () {
    var form = document.getElementById(enlace.dataset.form);
    if (!form || enlace.dataset.enviando) { return; }
    enlace.dataset.enviando = '1';
    enlace.setAttribute('aria-disabled', 'true');
    fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      headers: { 'X-Requested-With': 'fetch' },
      keepalive: true
    }).then(function () {
      setTimeout(function () { location.reload(); }, 700);
    }).catch(function () {
      enlace.removeAttribute('aria-disabled');
      delete enlace.dataset.enviando;
    });
  });
})();
