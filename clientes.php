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
    <form method="post" action="clientes.php" class="form-sincronizar">
      <input type="hidden" name="csrf" value="<?= e($token) ?>">
      <input type="hidden" name="accion" value="sincronizar">
      <button type="submit" class="boton mini">Traer clientes de facturación</button>
    </form>
    <?php if (!$clientes): ?>
      <p class="subsaludo">Todavía no hay clientes en la lista.</p>
    <?php else: ?>
      <form method="post" action="clientes.php" id="form-eliminar-clientes" class="barra-eliminar">
        <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <input type="hidden" name="accion" value="eliminar">
        <input type="hidden" name="ids" id="idsEliminarClientes" value="">
        <button type="button" id="btnEditarSeleccionada" class="boton mini" disabled>Editar seleccionado</button>
        <button type="submit" class="boton mini peligro">Eliminar seleccionados</button>
      </form>
      <div class="tabla-scroll">
      <table class="lineas">
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
              <td><input type="checkbox" class="chk-cliente" id="chk-<?= e($c['id']) ?>" value="<?= e($c['id']) ?>" aria-label="Seleccionar a <?= e($c['nombre']) ?>"></td>
              <td><?= e($c['nombre']) ?></td>
              <td><?= e($c['telefono']) ?></td>
              <td>
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
              </td>
            </tr>
            <tr id="edicion-<?= e($c['id']) ?>" hidden>
              <td colspan="4">
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
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </section>

</main>

<script>
/* Seleccionar todas, confirmar antes de borrar y habilitar "Editar"
   solo con una marcada: todo eso vive en assets/js/seleccion-lote.js,
   compartido con Productos. Acá solo va lo propio de esta página:
   mostrar la fila de edición de la clienta elegida. */
const loteClientes = seleccionEnLote({
  item:         '.chk-cliente',
  chkTodos:     '#chkTodosClientes',
  formEliminar: '#form-eliminar-clientes',
  campoValores: '#idsEliminarClientes',
  btnEditar:    '#btnEditarSeleccionada',
  alEditar: id => {
    document.getElementById('vista-' + id).hidden   = true;
    document.getElementById('edicion-' + id).hidden = false;
    document.getElementById('edicion-' + id).scrollIntoView({ behavior: 'smooth', block: 'center' });
  },
  textos: {
    ninguno: 'Selecciona al menos un cliente para eliminar.',
    confirmar: n => n === 1
      ? '¿Eliminar el cliente seleccionado? Esta acción no se puede deshacer.'
      : '¿Eliminar los ' + n + ' clientes seleccionados? Esta acción no se puede deshacer.',
  },
});

/* Cancelar una edición vuelve a mostrar la fila normal y desmarca la
   casilla. Como desmarcarla por código no dispara "change", hay que
   avisarle a la selección para que el botón "Editar" se deshabilite. */
document.querySelectorAll('.btn-cancelar-cliente').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('vista-' + btn.dataset.id).hidden   = false;
    document.getElementById('edicion-' + btn.dataset.id).hidden = true;
    const chk = document.getElementById('chk-' + btn.dataset.id);
    if (chk) chk.checked = false;
    loteClientes.refrescar();
  });
});
</script>

</body>
</html>
