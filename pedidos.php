<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/pedidos.php';

requerir_sesion();

$error = '';
$ok    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'La página estuvo abierta demasiado tiempo. Recárgala e intenta de nuevo.';
    } elseif (($_POST['accion'] ?? '') === 'descartar') {
        if (descartar_pedido((string) ($_POST['numero'] ?? ''), (string) ($_POST['motivo'] ?? ''))) {
            header('Location: pedidos.php?ok=descartado');
            exit;
        }
        $error = 'No pude descartar ese pedido.';
    }
}

if (($_GET['ok'] ?? '') === 'descartado') {
    $ok = 'Pedido descartado.';
}

$pendientes = pedidos_pendientes();

$historial = array_values(array_filter(
    leer_pedidos(),
    fn($p) => ($p['estado'] ?? '') !== 'pendiente'
));
usort($historial, fn($a, $b) => ($b['creado'] ?? '') <=> ($a['creado'] ?? ''));
$historial = array_slice($historial, 0, 30);

$token = csrf_token();

$tituloPagina = 'Pedidos';
$barraVolver  = true;
require __DIR__ . '/includes/cabecera.php';
?>

<main class="lienzo ancho">
  <h1 class="saludo">Pedidos</h1>
  <p class="subsaludo">Lo que llega del catálogo público. Revisa cada uno y confirma la factura, o descarta si el cliente no siguió.</p>

  <?php if ($error !== ''): ?>
    <p class="aviso" role="alert"><?= e($error) ?></p>
  <?php endif; ?>
  <?php if ($ok !== ''): ?>
    <p class="aviso neutro"><?= e($ok) ?></p>
  <?php endif; ?>

  <section class="panel">
    <h2 class="titulo-panel">Por revisar <span class="titulo-nota"><?= numero(count($pendientes)) ?> pedido<?= count($pendientes) === 1 ? '' : 's' ?></span></h2>

    <?php if (!$pendientes): ?>
      <p class="subsaludo">No hay pedidos nuevos por ahora.</p>
    <?php else: ?>
      <div class="tabla-scroll">
      <table class="lineas">
        <thead>
          <tr>
            <th>Cliente</th>
            <th>Productos</th>
            <th>Plan propuesto</th>
            <th class="num">Total</th>
            <th>Llegó</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pendientes as $p): ?>
            <tr>
              <td>
                <?= e($p['cliente']) ?>
                <?php if (!empty($p['telefono'])): ?><span class="sku"><?= e($p['telefono']) ?></span><?php endif; ?>
              </td>
              <td>
                <?php foreach ($p['lineas'] ?? [] as $l): ?>
                  <?= (int) $l['cant'] ?> × <?= e($l['nombre']) ?><br>
                <?php endforeach; ?>
              </td>
              <td>
                <?php if (($p['plan'] ?? 'contado') === 'cuotas'): ?>
                  Prefiere <?= numero((int) ($p['num_cuotas_preferido'] ?? 2)) ?> cuotas
                  <br><span class="sku">Montos y fechas: por definir con ella</span>
                <?php else: ?>
                  De contado
                <?php endif; ?>
              </td>
              <td class="num"><?= pesos((int) ($p['total'] ?? 0)) ?></td>
              <td><?= e(fecha_larga(substr((string) ($p['creado'] ?? ''), 0, 10))) ?></td>
              <td class="num acciones-pedido">
                <a class="boton compacto" href="facturar.php?desde_pedido=<?= e($p['numero']) ?>">Confirmar y facturar</a>
                <form method="post" action="pedidos.php" class="en-linea"
                      onsubmit="return confirm('¿Descartar el pedido de <?= e($p['cliente']) ?>? No se puede deshacer.');">
                  <input type="hidden" name="csrf" value="<?= e($token) ?>">
                  <input type="hidden" name="accion" value="descartar">
                  <input type="hidden" name="numero" value="<?= e($p['numero']) ?>">
                  <button type="submit" class="salir como-boton peligro">Descartar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="panel">
    <h2 class="titulo-panel">Historial reciente <span class="titulo-nota">últimos <?= numero(count($historial)) ?></span></h2>
    <?php if (!$historial): ?>
      <p class="subsaludo">Todavía no hay pedidos confirmados ni descartados.</p>
    <?php else: ?>
      <div class="tabla-scroll">
      <table class="lineas">
        <thead>
          <tr>
            <th>No.</th>
            <th>Cliente</th>
            <th class="num">Total</th>
            <th>Estado</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($historial as $p): $confirmado = ($p['estado'] ?? '') === 'confirmado'; ?>
            <tr>
              <td><?= e($p['numero']) ?></td>
              <td><?= e($p['cliente']) ?></td>
              <td class="num"><?= pesos((int) ($p['total'] ?? 0)) ?></td>
              <td>
                <span class="etiqueta <?= $confirmado ? 'pagada' : 'anulada' ?>">
                  <?= $confirmado ? 'Confirmado' : 'Descartado' ?>
                </span>
              </td>
              <td class="num">
                <?php if ($confirmado && !empty($p['factura_numero'])): ?>
                  <a class="salir" href="factura.php?n=<?= e($p['factura_numero']) ?>">Ver factura</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </section>
</main>

</body>
</html>
