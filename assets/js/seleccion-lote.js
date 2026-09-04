/* =========================================================
   ENRÍSATE · Selección en lote para las tablas del panel
   =========================================================
   Clientes y Productos tenían cada uno su propia copia de esta misma
   lógica —"seleccionar todos", la confirmación antes de borrar y el
   candado de "editar solo uno a la vez"— escrita dos veces con
   distintos nombres (prepararEliminarClientes / prepararEliminarProductos,
   chkTodosClientes / chkTodosProductos…). Acá vive una sola vez.

   Cada página solo declara sus selectores, cómo se llama lo que está
   listando y qué hacer cuando se pulsa Editar.

   Se carga desde includes/cabecera.php, sin defer: este archivo solo
   define la función, no toca el DOM al cargar, y así ya está disponible
   para el <script> del final de cada página.
   ========================================================= */

/**
 * @param {object}   op
 * @param {string}   op.item          Selector de las casillas de cada fila.
 * @param {string}  [op.chkTodos]     Selector de la casilla "seleccionar todos".
 * @param {string}  [op.formEliminar] Selector del <form> de eliminar.
 * @param {string}  [op.campoValores] Input oculto que recibe los valores marcados.
 * @param {string}  [op.btnEditar]    Botón "Editar" (se habilita solo con uno marcado).
 * @param {function}[op.alEditar]     Se llama con el valor de la fila marcada.
 * @param {object}  [op.textos]       { ninguno: string, confirmar: (n) => string }
 */
function seleccionEnLote(op) {
  const casillas = () => Array.from(document.querySelectorAll(op.item));
  const marcadas = () => casillas().filter(c => c.checked);

  const botonEditar = op.btnEditar ? document.querySelector(op.btnEditar) : null;

  /* El botón de editar solo tiene sentido con exactamente una fila
     marcada. Deshabilitarlo comunica eso mejor que dejar pulsarlo y
     responder con un alert, que era lo que hacía Clientes. */
  function refrescarEditar() {
    if (botonEditar) botonEditar.disabled = marcadas().length !== 1;
  }

  const todos = op.chkTodos ? document.querySelector(op.chkTodos) : null;
  if (todos) {
    todos.addEventListener('change', () => {
      casillas().forEach(c => { c.checked = todos.checked; });
      refrescarEditar();
    });
  }

  casillas().forEach(c => c.addEventListener('change', refrescarEditar));
  refrescarEditar();

  if (botonEditar && op.alEditar) {
    botonEditar.addEventListener('click', () => {
      const sel = marcadas();
      if (sel.length === 1) op.alEditar(sel[0].value);
    });
  }

  const form = op.formEliminar ? document.querySelector(op.formEliminar) : null;
  if (form) {
    form.addEventListener('submit', e => {
      const sel = marcadas();
      if (sel.length === 0) {
        e.preventDefault();
        alert(op.textos.ninguno);
        return;
      }
      if (!confirm(op.textos.confirmar(sel.length))) {
        e.preventDefault();
        return;
      }
      document.querySelector(op.campoValores).value = sel.map(c => c.value).join(',');
    });
  }

  /* Marcar o desmarcar una casilla desde JS (por ejemplo, al cancelar una
     edición) no dispara el evento "change", así que el estado del botón
     se quedaría desactualizado. La página llama a refrescar() en esos
     casos. */
  return { refrescar: refrescarEditar };
}
