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
        $sku = (string) ($_POST['sku'] ?? '');
        if (actualizar_precio_costo($sku, (int) ($_POST['precio'] ?? 0), (int) ($_POST['costo'] ?? 0))) {
            header('Location: margenes.php?ok=1');
            exit;
        }
        $error = 'No encontré ese producto en el catálogo.';
    }
}

if (($_GET['ok'] ?? '') === '1') {
    $ok = 'Precio y costo actualizados. Ya se reflejan en el catálogo público.';
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

$tituloPagina = 'Márgenes';
$barraVolver  = true;
require __DIR__ . '/includes/cabecera.php';
?>

<main class="lienzo ancho">
  <h1 class="saludo">Márgenes</h1>
  <p class="subsaludo">Costo contra precio, producto por producto. Cambia lo que necesites y se actualiza al instante en el catálogo público y en Facturación.</p>

  <?php if ($error !== ''): ?>
    <p class="aviso" role="alert"><?= e($error) ?></p>
  <?php endif; ?>
  <?php if ($ok !== ''): ?>
    <p class="aviso neutro"><?= e($ok) ?></p>
  <?php endif; ?>

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
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ps as $p):
            $margen = $p['precio'] - $p['costo'];
            $pct    = $p['precio'] > 0 ? round($margen / $p['precio'] * 100) : 0;
            $claseMargen = $margen < 0 ? 'vencida' : ($pct < 20 ? 'bajo' : 'pagada');
          ?>
            <tr>
              <td>
                <?= e($p['nombre']) ?>
                <span class="sku"><?= e($p['sku']) ?></span>
              </td>
              <td class="num"><?= pesos($p['precio']) ?></td>
              <td class="num"><?= pesos($p['costo']) ?></td>
              <td class="num">
                <span class="etiqueta <?= $claseMargen ?>"><?= pesos($margen) ?> · <?= $pct ?>%</span>
              </td>
              <td class="num">
                <form method="post" action="margenes.php" class="form-precios">
                  <input type="hidden" name="csrf" value="<?= e($token) ?>">
                  <input type="hidden" name="sku" value="<?= e($p['sku']) ?>">
                  <input type="number" name="precio" value="<?= (int) $p['precio'] ?>" min="0" step="1000" aria-label="Precio de <?= e($p['nombre']) ?>">
                  <input type="number" name="costo" value="<?= (int) $p['costo'] ?>" min="0" step="1000" aria-label="Costo de <?= e($p['nombre']) ?>">
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

</body>
</html>
