/* =========================================================
   ENRÍSATE · Pista de "desliza para ver más" en tablas anchas
   =========================================================
   Mismo recurso que el carrusel de marcas del catálogo público:
   difumina el borde de una tabla con scroll horizontal (.tabla-scroll)
   cuando quedan columnas ocultas a los lados, para que se sienta como
   "hay más, desliza" en vez de un corte feo. Se activa solo en cada
   página que tenga una .tabla-scroll, sin que la página tenga que
   llamarlo — por eso va con "defer" en vez de cargarse junto con
   seleccion-lote.js.
   ========================================================= */
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.tabla-scroll').forEach(caja => {
    function actualizar() {
      const restante    = caja.scrollWidth - caja.clientWidth;
      const desbordaIzq = caja.scrollLeft > 2;
      const desbordaDer = caja.scrollLeft < restante - 2;
      caja.classList.toggle('desborda-ambos', desbordaIzq && desbordaDer);
      caja.classList.toggle('desborda-izquierda', desbordaIzq && !desbordaDer);
      caja.classList.toggle('desborda-derecha', !desbordaIzq && desbordaDer);
    }
    caja.addEventListener('scroll', actualizar, { passive: true });
    window.addEventListener('resize', actualizar);
    actualizar();
  });
});
