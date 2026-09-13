<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/clientes.php';
require_once __DIR__ . '/includes/facturas.php';

requerir_sesion();

$error = '';
$ok    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'La página estuvo abierta demasiado tiempo. Recárgala e intenta de nuevo.';
    } elseif (($_POST['accion'] ?? '') === 'sincronizar') {
        $agregadas = sincronizar_clientes_desde_facturas(leer_facturas());
        header('Location: clientes.php?ok=sync&n=' . $agregadas);
        exit;
    } elseif (($_POST['accion'] ?? '') === 'editar') {
        $mensaje = actualizar_cliente(
            (string) ($_POST['id'] ?? ''),
            (string) ($_POST['nombre'] ?? ''),
            (string) ($_POST['telefono'] ?? ''),
            (string) ($_POST['notas'] ?? ''),
            (int) ($_POST['saldo_favor'] ?? 0)
        );
        if ($mensaje === '') {
            header('Location: clientes.php?ok=editada');
            exit;
        }
        $error = $mensaje;
    } elseif (($_POST['accion'] ?? '') === 'eliminar') {
        $ids      = array_filter(array_map('trim', explode(',', (string) ($_POST['ids'] ?? ''))));
        $borradas = eliminar_clientes($ids);
        if ($borradas > 0) {
            header('Location: clientes.php?ok=eliminadas&n=' . $borradas);
            exit;
        }
        $error = 'Selecciona al menos un cliente para eliminar.';
    } else {
        $mensaje = agregar_cliente(
            (string) ($_POST['nombre'] ?? ''),
            (string) ($_POST['telefono'] ?? ''),
            (string) ($_POST['notas'] ?? '')
        );
        if ($mensaje === '') {
            header('Location: clientes.php?ok=1');
            exit;
        }
        $error = $mensaje;
    }
}

if (($_GET['ok'] ?? '') === '1') {
    $ok = 'Cliente agregado a la lista.';
} elseif (($_GET['ok'] ?? '') === 'editada') {
    $ok = 'Datos del cliente actualizados.';
} elseif (($_GET['ok'] ?? '') === 'eliminadas') {
    $n  = (int) ($_GET['n'] ?? 0);
    $ok = 'Se eliminó ' . $n . ' cliente' . ($n === 1 ? '' : 's') . ' de la lista.';
} elseif (($_GET['ok'] ?? '') === 'sync') {
    $n  = (int) ($_GET['n'] ?? 0);
    $ok = $n > 0
        ? 'Se agregaron ' . $n . ' cliente' . ($n === 1 ? '' : 's') . ' que ya estaban en facturación.'
        : 'Ya estaban todos: no había clientes nuevos por agregar desde facturación.';
}

$clientes = leer_clientes();
usort($clientes, fn($a, $b) => $a['nombre'] <=> $b['nombre']);

$facturas = leer_facturas();

$token = csrf_token();

$tituloPagina = 'Clientes';
$barraVolver  = true;
require __DIR__ . '/includes/cabecera.php';
?>

<main class="lienzo ancho">
  <h1 class="saludo">Clientes</h1>
  <p class="subsaludo">La lista de clientes: nombre, teléfono y notas. Ve agregándolos aquí a medida que los tengas.</p>

  <?php if ($error !== ''): ?>
    <p class="aviso" role="alert"><?= e($error) ?></p>
  <?php endif; ?>
  <?php if ($ok !== ''): ?>
    <p class="aviso neutro"><?= e($ok) ?></p>
  <?php endif; ?>

  <section class="panel">
    <h2 class="titulo-panel">Agregar cliente</h2>
    <form method="post" action="clientes.php" class="rejilla-campos">
      <input type="hidden" name="csrf" value="<?= e($token) ?>">

      <div class="campo">
        <label for="cNombre">Nombre completo</label>
        <input type="text" id="cNombre" name="nombre" placeholder="Gina Pertuz" required>
      </div>
      <div class="campo">
        <label for="cTelefono">Teléfono</label>
        <input type="tel" id="cTelefono" name="telefono" placeholder="3001234567" inputmode="numeric">
      </div>
      <div class="campo">
        <label for="cNotas">Notas</label>
        <input type="text" id="cNotas" name="notas" placeholder="Opcional">
      </div>

      <button type="submit" class="boton mini">Agregar cliente</button>
    </form>
  </section>

  <section class="panel">
    <h2 class="titulo-panel">Todos los clientes</h2>
    <p class="subsaludo">Cada factura nueva agrega al cliente aquí solito. Si tienes facturas viejas de antes de este cambio, usa este botón una vez para traerlas.</p>
    <form method="post" action="clientes.php" class="form-sincronizar grupo-acciones">
      <input type="hidden" name="csrf" value="<?= e($token) ?>">
      <input type="hidden" name="accion" value="sincronizar">
      <button type="submit" class="accion-icono" title="Traer clientes de facturación">
        <span class="accion-icono-circulo">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15V3"/><path d="M3 15v4a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-4"/><path d="m7 10 5 5 5-5"/></svg>
        </span>
        <span class="accion-icono-nombre">Traer de facturación</span>
      </button>
    </form>
    <?php if (!$clientes): ?>
      <p class="subsaludo">Todavía no hay clientes en la lista.</p>
    <?php else: ?>
      <form method="post" action="clientes.php" id="form-eliminar-clientes" class="barra-eliminar grupo-acciones">
        <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <input type="hidden" name="accion" value="eliminar">
        <input type="hidden" name="ids" id="idsEliminarClientes" value="">
        <button type="button" id="btnEditarSeleccionada" class="accion-icono" disabled title="Editar el cliente marcado">
          <span class="accion-icono-circulo">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497Z"/><path d="m15 5 4 4"/></svg>
          </span>
          <span class="accion-icono-nombre">Editar</span>
        </button>
        <button type="submit" class="accion-icono peligro" title="Eliminar los clientes marcados">
          <span class="accion-icono-circulo">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
          </span>
          <span class="accion-icono-nombre">Eliminar</span>
        </button>
      </form>
      <?php
      // Notas + estadísticas de compra, y el formulario de editar, se ven
      // igual en la fila de tabla (escritorio) y en la tarjeta de
      // celular; se arman una sola vez por cliente para no repetir el
      // cálculo ni el HTML a mano.
      $notasCliente = function (array $c, ?array $stats): string {
          ob_start();
          ?>
          <?= e($c['notas']) ?>
          <?php if ($stats): ?>
            <div class="cliente-insights">
              <?php if ($stats['frecuencia_dias'] !== null): ?>
                <span class="cliente-insight">Compra cada <strong><?= $stats['frecuencia_dias'] < 1 ? 'muy seguido' : '~' . $stats['frecuencia_dias'] . ' días' ?></strong></span>
              <?php elseif ($stats['compras'] === 1): ?>
                <span class="cliente-insight">1 compra hasta ahora</span>
              <?php endif; ?>
              <?php if ($stats['favorito']): ?>
                <span class="cliente-insight">Favorito: <strong><?= e($stats['favorito']) ?></strong></span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <?php
          return ob_get_clean();
      };
      $formEditarCliente = function (array $c, int $saldo) use ($token): string {
          ob_start();
          ?>
          <form method="post" action="clientes.php" class="form-editar-cliente">
            <input type="hidden" name="csrf" value="<?= e($token) ?>">
            <input type="hidden" name="accion" value="editar">
            <input type="hidden" name="id" value="<?= e($c['id']) ?>">
            <div class="campo">
              <label>Nombre completo</label>
              <input type="text" name="nombre" value="<?= e($c['nombre']) ?>" placeholder="Nombre completo" required>
            </div>
            <div class="campo">
              <label>Teléfono</label>
              <input type="tel" name="telefono" value="<?= e($c['telefono']) ?>" placeholder="Teléfono" inputmode="numeric">
            </div>
            <div class="campo">
              <label>Saldo a favor</label>
              <input type="number" name="saldo_favor" value="<?= $saldo ?>" min="0" step="500">
            </div>
            <div class="campo">
              <label>Notas</label>
              <input type="text" name="notas" value="<?= e($c['notas']) ?>" placeholder="Notas">
            </div>
            <button type="submit" class="boton mini">Guardar</button>
            <button type="button" class="boton mini btn-cancelar-cliente" data-id="<?= e($c['id']) ?>">Cancelar</button>
          </form>
          <?php
          return ob_get_clean();
      };
      ?>
      <div class="tabla-scroll oculta-en-movil">
      <table class="lineas tabla-clientes">
        <thead>
          <tr>
            <th><input type="checkbox" id="chkTodosClientes" aria-label="Seleccionar todas"></th>
            <th>Nombre</th>
            <th>Teléfono</th>
            <th>Notas</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($clientes as $c): $saldo = (int) ($c['saldo_favor'] ?? 0); $stats = estadisticas_compra_cliente($c, $facturas); ?>
            <tr id="vista-<?= e($c['id']) ?>">
              <td><input type="checkbox" class="chk-cliente" value="<?= e($c['id']) ?>" aria-label="Seleccionar a <?= e($c['nombre']) ?>"></td>
              <td><?= e($c['nombre']) ?></td>
              <td><?= e($c['telefono']) ?></td>
              <td class="col-notas"><?= $notasCliente($c, $stats) ?></td>
            </tr>
            <tr id="edicion-<?= e($c['id']) ?>" hidden>
              <td colspan="4"><?= $formEditarCliente($c, $saldo) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <!-- Solo en celular: cada cliente es una tarjeta que se despliega
           al tocarla, en vez de una fila de tabla angosta. La casilla
           comparte value con la de la tabla (sin id propio) para que
           seleccion-lote.js las trate como una sola selección. -->
      <div class="filas-esencial">
        <?php foreach ($clientes as $c): $saldo = (int) ($c['saldo_favor'] ?? 0); $stats = estadisticas_compra_cliente($c, $facturas); ?>
          <details class="fila-lista" id="vista-movil-<?= e($c['id']) ?>">
            <summary>
              <input type="checkbox" class="chk-cliente" value="<?= e($c['id']) ?>" aria-label="Seleccionar a <?= e($c['nombre']) ?>" onclick="event.stopPropagation()">
              <span class="fila-lista-resumen">
                <span class="fila-lista-titulo"><?= e($c['nombre']) ?></span>
                <?php if ($stats && $stats['favorito']): ?>
                  <span class="fila-lista-pista">Favorito: <?= e($stats['favorito']) ?></span>
                <?php elseif ($c['telefono'] !== ''): ?>
                  <span class="fila-lista-pista"><?= e($c['telefono']) ?></span>
                <?php endif; ?>
              </span>
              <svg class="fila-lista-flecha" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </summary>
            <div class="fila-lista-detalle">
              <div class="campo-detalle"><strong>Teléfono</strong><?= $c['telefono'] !== '' ? e($c['telefono']) : 'Sin teléfono' ?></div>
              <?= $notasCliente($c, $stats) ?>
            </div>
          </details>
          <div id="edicion-movil-<?= e($c['id']) ?>" class="fila-lista-editar" hidden><?= $formEditarCliente($c, $saldo) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

</main>

<script>
/* Seleccionar todas, confirmar antes de borrar y habilitar "Editar"
   solo con una marcada: todo eso vive en assets/js/seleccion-lote.js,
   compartido con Productos. Acá solo va lo propio de esta página:
   mostrar la fila de edición de la clienta elegida.

   Cada cliente existe por partida doble en el HTML: la fila de la tabla
   (escritorio/tablet) y la tarjeta desplegable (celular) — solo una de
   las dos está visible a la vez, según el ancho de pantalla (CSS). Las
   funciones de abajo tocan las dos copias por igual; la que esté oculta
   por CSS simplemente no se nota. Las casillas comparten value en vez
   de id (seleccion-lote.js ya trabaja por value), así que no hace falta
   distinguir cuál copia se marcó. */
function mostrarEdicionCliente(id) {
  document.getElementById('vista-' + id).hidden = true;
  const vistaMovil = document.getElementById('vista-movil-' + id);
  if (vistaMovil) vistaMovil.hidden = true;
  document.getElementById('edicion-' + id).hidden = false;
  const edicionMovil = document.getElementById('edicion-movil-' + id);
  if (edicionMovil) edicionMovil.hidden = false;
}
function ocultarEdicionCliente(id) {
  document.getElementById('vista-' + id).hidden = false;
  const vistaMovil = document.getElementById('vista-movil-' + id);
  if (vistaMovil) vistaMovil.hidden = false;
  document.getElementById('edicion-' + id).hidden = true;
  const edicionMovil = document.getElementById('edicion-movil-' + id);
  if (edicionMovil) edicionMovil.hidden = true;
}

const loteClientes = seleccionEnLote({
  item:         '.chk-cliente',
  chkTodos:     '#chkTodosClientes',
  formEliminar: '#form-eliminar-clientes',
  campoValores: '#idsEliminarClientes',
  btnEditar:    '#btnEditarSeleccionada',
  alEditar: id => {
    mostrarEdicionCliente(id);
    (document.getElementById('edicion-movil-' + id) ?? document.getElementById('edicion-' + id))
      .scrollIntoView({ behavior: 'smooth', block: 'center' });
  },
  textos: {
    ninguno: 'Selecciona al menos un cliente para eliminar.',
    confirmar: n => n === 1
      ? '¿Eliminar el cliente seleccionado? Esta acción no se puede deshacer.'
      : '¿Eliminar los ' + n + ' clientes seleccionados? Esta acción no se puede deshacer.',
  },
});

/* Cancelar una edición vuelve a mostrar la fila/tarjeta normal y
   desmarca la casilla (la de tabla y la de celular: comparten value, no
   id). Como desmarcarla por código no dispara "change", hay que
   avisarle a la selección para que el botón "Editar" se deshabilite. */
document.querySelectorAll('.btn-cancelar-cliente').forEach(btn => {
  btn.addEventListener('click', () => {
    ocultarEdicionCliente(btn.dataset.id);
    document.querySelectorAll('.chk-cliente[value="' + CSS.escape(btn.dataset.id) + '"]')
      .forEach(chk => { chk.checked = false; });
    loteClientes.refrescar();
  });
});
</script>

</body>
</html>
