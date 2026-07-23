<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/facturas.php';

requerir_sesion();

$error = '';
$ok    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'La página estuvo abierta demasiado tiempo. Recárgala e intenta de nuevo.';
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

  <?php foreach ($porMarca as $marca => $ps): ?>
    <section class="panel panel-inventario">
      <h2 class="titulo-panel"><?= e($marca) ?></h2>
      <table class="lineas">
        <thead>
          <tr>
            <th>Producto</th>
            <th class="num">Precio</th>
            <th class="num">Costo</th>
            <th class="num">Margen</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ps as $p):
            $margen = $p['precio'] - $p['costo'];
            $pct    = $p['precio'] > 0 ? round($margen / $p['precio'] * 100) : 0;
          ?>
            <tr>
              <td>
                <?= e($p['nombre']) ?>
                <span class="sku"><?= e($p['sku']) ?></span>
              </td>
              <td class="num"><?= pesos($p['precio']) ?></td>
              <td class="num"><?= pesos($p['costo']) ?></td>
              <td class="num"><?= pesos($margen) ?> <span class="sku"><?= $pct ?>%</span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  <?php endforeach; ?>

</main>

</body>
</html>
