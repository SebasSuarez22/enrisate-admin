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
        $error = 'Selecciona al menos una clienta para eliminar.';
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
    $ok = 'Clienta agregada a la lista.';
} elseif (($_GET['ok'] ?? '') === 'editada') {
    $ok = 'Datos de la clienta actualizados.';
} elseif (($_GET['ok'] ?? '') === 'eliminadas') {
    $n  = (int) ($_GET['n'] ?? 0);
    $ok = 'Se eliminó ' . $n . ' clienta' . ($n === 1 ? '' : 's') . ' de la lista.';
} elseif (($_GET['ok'] ?? '') === 'sync') {
    $n  = (int) ($_GET['n'] ?? 0);
    $ok = $n > 0
        ? 'Se agregaron ' . $n . ' clienta' . ($n === 1 ? '' : 's') . ' que ya estaban en facturación.'
        : 'Ya estaban todas: no había clientas nuevas por agregar desde facturación.';
}

$clientes = leer_clientes();
usort($clientes, fn($a, $b) => $a['nombre'] <=> $b['nombre']);

$token = csrf_token();

$tituloPagina = 'Clientes';
$barraVolver  = true;
require __DIR__ . '/includes/cabecera.php';
?>

<main class="lienzo ancho">
  <h1 class="saludo">Clientes</h1>
  <p class="subsaludo">La lista de clientas: nombre, teléfono y notas. Ve agregándolas aquí a medida que las tengas.</p>

  <?php if ($error !== ''): ?>
    <p class="aviso" role="alert"><?= e($error) ?></p>
  <?php endif; ?>
  <?php if ($ok !== ''): ?>
    <p class="aviso neutro"><?= e($ok) ?></p>
  <?php endif; ?>

  <section class="panel">
    <h2 class="titulo-panel">Agregar clienta</h2>
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

      <button type="submit" class="boton mini">Agregar clienta</button>
    </form>
  </section>

  <section class="panel">
    <h2 class="titulo-panel">Todas las clientas</h2>
    <p class="subsaludo">Cada factura nueva agrega a la clienta aquí solita. Si tienes facturas viejas de antes de este cambio, usa este botón una vez para traerlas.</p>
    <form method="post" action="clientes.php" style="margin-bottom: 18px;">
      <input type="hidden" name="csrf" value="<?= e($token) ?>">
      <input type="hidden" name="accion" value="sincronizar">
      <button type="submit" class="boton mini">Traer clientas de facturación</button>
    </form>
    <?php if (!$clientes): ?>
      <p class="subsaludo">Todavía no hay clientas en la lista.</p>
    <?php else: ?>
      <form method="post" action="clientes.php" id="form-eliminar-clientes" class="barra-eliminar" onsubmit="return prepararEliminarClientes(this);">
        <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <input type="hidden" name="accion" value="eliminar">
        <input type="hidden" name="ids" id="idsEliminarClientes" value="">
        <button type="submit" class="boton mini peligro">Eliminar seleccionadas</button>
      </form>
      <table class="lineas">
        <thead>
          <tr>
            <th><input type="checkbox" id="chkTodosClientes" aria-label="Seleccionar todas"></th>
            <th>Nombre</th>
            <th>Teléfono</th>
            <th class="num">Saldo a favor</th>
            <th>Notas</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($clientes as $c): $saldo = (int) ($c['saldo_favor'] ?? 0); ?>
            <tr id="vista-<?= e($c['id']) ?>">
              <td><input type="checkbox" class="chk-cliente" value="<?= e($c['id']) ?>" aria-label="Seleccionar a <?= e($c['nombre']) ?>"></td>
              <td><?= e($c['nombre']) ?></td>
              <td><?= e($c['telefono']) ?></td>
              <td class="num"><?= $saldo > 0 ? '<strong class="saldo-favor">' . pesos($saldo) . '</strong>' : '—' ?></td>
              <td><?= e($c['notas']) ?></td>
              <td class="num">
                <button type="button" class="boton mini btn-editar-cliente" data-id="<?= e($c['id']) ?>">Editar</button>
              </td>
            </tr>
            <tr id="edicion-<?= e($c['id']) ?>" hidden>
              <td colspan="6">
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
    <?php endif; ?>
  </section>

</main>

<script>
document.querySelectorAll('.btn-editar-cliente').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('vista-' + btn.dataset.id).hidden   = true;
    document.getElementById('edicion-' + btn.dataset.id).hidden = false;
  });
});
document.querySelectorAll('.btn-cancelar-cliente').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('vista-' + btn.dataset.id).hidden   = false;
    document.getElementById('edicion-' + btn.dataset.id).hidden = true;
  });
});

const chkTodosClientes = document.getElementById('chkTodosClientes');
if (chkTodosClientes) {
  chkTodosClientes.addEventListener('change', () => {
    document.querySelectorAll('.chk-cliente').forEach(chk => { chk.checked = chkTodosClientes.checked; });
  });
}

function prepararEliminarClientes(form) {
  const seleccionadas = Array.from(document.querySelectorAll('.chk-cliente:checked'));
  if (seleccionadas.length === 0) {
    alert('Selecciona al menos una clienta para eliminar.');
    return false;
  }
  const plural = seleccionadas.length === 1 ? 'la clienta seleccionada' : 'las ' + seleccionadas.length + ' clientas seleccionadas';
  if (!confirm('¿Eliminar ' + plural + '? Esta acción no se puede deshacer.')) {
    return false;
  }
  document.getElementById('idsEliminarClientes').value = seleccionadas.map(chk => chk.value).join(',');
  return true;
}
</script>

</body>
</html>
