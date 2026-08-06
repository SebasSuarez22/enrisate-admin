<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/facturas.php';

requerir_sesion();

$error = '';
$ok    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'La página estuvo abierta demasiado tiempo. Recárgala e intenta de nuevo.';
    } elseif (($_POST['accion'] ?? '') === 'eliminar') {
        $skus     = array_filter(array_map('trim', explode(',', (string) ($_POST['skus'] ?? ''))));
        $borrados = eliminar_productos($skus);
        if ($borrados > 0) {
            header('Location: inventario.php?ok=eliminados&n=' . $borrados);
            exit;
        }
        $error = 'Selecciona al menos un producto para eliminar.';
    } elseif (($_POST['accion'] ?? '') === 'editar') {
        if (actualizar_producto(
            (string) ($_POST['sku'] ?? ''),
            (string) ($_POST['marca'] ?? ''),
            (string) ($_POST['nombre'] ?? '')
        )) {
            header('Location: inventario.php?ok=editado');
            exit;
        }
        $error = 'Escribe la marca y el nombre, y verifica que el producto exista.';
    } else {
        $mensaje = agregar_producto(
            (string) ($_POST['sku'] ?? ''),
            (string) ($_POST['marca'] ?? ''),
            (string) ($_POST['nombre'] ?? ''),
            (int) ($_POST['precio'] ?? 0),
            (int) ($_POST['costo'] ?? 0)
        );
        if ($mensaje === '') {
            header('Location: inventario.php?ok=agregado');
            exit;
        }
        $error = $mensaje;
    }
}

if (($_GET['ok'] ?? '') === 'agregado') {
    $ok = 'Producto agregado al catálogo.';
} elseif (($_GET['ok'] ?? '') === 'editado') {
    $ok = 'Producto actualizado.';
} elseif (($_GET['ok'] ?? '') === 'eliminados') {
    $n  = (int) ($_GET['n'] ?? 0);
    $ok = 'Se eliminó ' . $n . ' producto' . ($n === 1 ? '' : 's') . ' del catálogo.';
}

$porMarca = [];
foreach (catalogo() as $p) {
    $porMarca[$p['marca']][] = $p;
}
ksort($porMarca);
foreach ($porMarca as &$ps) {
    usort($ps, fn($a, $b) => sku_orden($a['sku']) <=> sku_orden($b['sku']));
}
unset($ps);

$token = csrf_token();

$tituloPagina = 'Productos';
$barraVolver  = true;
require __DIR__ . '/includes/cabecera.php';
?>

<main class="lienzo ancho">
  <h1 class="saludo">Productos</h1>
  <p class="subsaludo">Todos los productos del catálogo. Agrega los nuevos aquí; el precio y el costo se editan en Márgenes.</p>

  <?php if ($error !== ''): ?>
    <p class="aviso" role="alert"><?= e($error) ?></p>
  <?php endif; ?>
  <?php if ($ok !== ''): ?>
    <p class="aviso neutro"><?= e($ok) ?></p>
  <?php endif; ?>

  <section class="panel">
    <h2 class="titulo-panel">Agregar producto nuevo</h2>
    <form method="post" action="inventario.php" class="rejilla-campos">
      <input type="hidden" name="csrf" value="<?= e($token) ?>">
      <input type="hidden" name="accion" value="agregar">

      <div class="campo">
        <label for="nSku">SKU</label>
        <input type="text" id="nSku" name="sku" placeholder="ACC-09" required>
      </div>
      <div class="campo">
        <label for="nMarca">Marca</label>
        <input type="text" id="nMarca" name="marca" placeholder="Milagros" required>
      </div>
      <div class="campo">
        <label for="nNombre">Nombre</label>
        <input type="text" id="nNombre" name="nombre" placeholder="Shampoo Nuevo" required>
      </div>
      <div class="campo">
        <label for="nPrecio">Precio</label>
        <input type="number" id="nPrecio" name="precio" min="0" step="1000" value="0" required>
      </div>
      <div class="campo">
        <label for="nCosto">Costo</label>
        <input type="number" id="nCosto" name="costo" min="0" step="1000" value="0" required>
      </div>

      <button type="submit" class="boton mini">Agregar producto</button>
    </form>
  </section>

  <?php if ($porMarca): ?>
    <form method="post" action="inventario.php" id="form-eliminar-productos" class="barra-eliminar" onsubmit="return prepararEliminarProductos(this);">
      <input type="hidden" name="csrf" value="<?= e($token) ?>">
      <input type="hidden" name="accion" value="eliminar">
      <input type="hidden" name="skus" id="skusEliminarProductos" value="">
      <label class="seleccionar-todo"><input type="checkbox" id="chkTodosProductos"> Seleccionar todos</label>
      <button type="submit" class="boton mini peligro">Eliminar seleccionados</button>
    </form>
  <?php endif; ?>

  <?php foreach ($porMarca as $marca => $ps): ?>
    <section class="panel panel-inventario">
      <h2 class="titulo-panel"><?= e($marca) ?></h2>
      <table class="lineas">
        <thead>
          <tr>
            <th></th>
            <th>Producto</th>
            <th class="num">Precio</th>
            <th class="num">Costo</th>
            <th class="num">Margen</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ps as $p):
            $margen = $p['precio'] - $p['costo'];
            $pct    = $p['precio'] > 0 ? round($margen / $p['precio'] * 100) : 0;
          ?>
            <tr>
              <td><input type="checkbox" class="chk-producto" value="<?= e($p['sku']) ?>" aria-label="Seleccionar <?= e($p['nombre']) ?>"></td>
              <td>
                <?= e($p['nombre']) ?>
                <span class="sku"><?= e($p['sku']) ?></span>
              </td>
              <td class="num"><?= pesos($p['precio']) ?></td>
              <td class="num"><?= pesos($p['costo']) ?></td>
              <td class="num"><?= pesos($margen) ?> <span class="sku"><?= $pct ?>%</span></td>
              <td class="num acciones-inventario">
                <form method="post" action="inventario.php" class="form-editar-producto">
                  <input type="hidden" name="csrf" value="<?= e($token) ?>">
                  <input type="hidden" name="accion" value="editar">
                  <input type="hidden" name="sku" value="<?= e($p['sku']) ?>">
                  <input type="text" name="marca" value="<?= e($p['marca']) ?>" aria-label="Marca de <?= e($p['nombre']) ?>">
                  <input type="text" name="nombre" value="<?= e($p['nombre']) ?>" aria-label="Nombre de <?= e($p['nombre']) ?>">
                  <button type="submit" class="boton mini">Guardar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  <?php endforeach; ?>

</main>

<script>
const chkTodosProductos = document.getElementById('chkTodosProductos');
if (chkTodosProductos) {
  chkTodosProductos.addEventListener('change', () => {
    document.querySelectorAll('.chk-producto').forEach(chk => { chk.checked = chkTodosProductos.checked; });
  });
}

function prepararEliminarProductos(form) {
  const seleccionados = Array.from(document.querySelectorAll('.chk-producto:checked'));
  if (seleccionados.length === 0) {
    alert('Selecciona al menos un producto para eliminar.');
    return false;
  }
  const plural = seleccionados.length === 1 ? 'el producto seleccionado' : 'los ' + seleccionados.length + ' productos seleccionados';
  if (!confirm('¿Eliminar ' + plural + ' del catálogo? Esta acción no se puede deshacer.')) {
    return false;
  }
  document.getElementById('skusEliminarProductos').value = seleccionados.map(chk => chk.value).join(',');
  return true;
}
</script>

</body>
</html>
