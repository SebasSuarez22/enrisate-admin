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
      <?php
      // "Confirmar y facturar" / "Descartar" son iguales en la tabla y en
      // la tarjeta de celular; se arman una sola vez por pedido.
      $accionesPedido = function (array $p) use ($token): string {
          ob_start();
          ?>
          <a class="boton compacto" href="facturar.php?desde_pedido=<?= e($p['numero']) ?>">Confirmar y facturar</a>
          <form method="post" action="pedidos.php" class="en-linea"
                onsubmit="return confirm('¿Descartar el pedido de <?= e($p['cliente']) ?>? No se puede deshacer.');">
            <input type="hidden" name="csrf" value="<?= e($token) ?>">
            <input type="hidden" name="accion" value="descartar">
            <input type="hidden" name="numero" value="<?= e($p['numero']) ?>">
            <button type="submit" class="salir como-boton peligro">Descartar</button>
          </form>
          <?php
          return ob_get_clean();
      };
      ?>
      <div class="tabla-scroll oculta-en-movil">
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
              <td class="num acciones-pedido"><?= $accionesPedido($p) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <!-- Solo en celular: cada pedido es una tarjeta que se despliega al
           tocarla, en vez de una fila de tabla angosta. -->
      <div class="filas-esencial">
        <?php foreach ($pendientes as $p): ?>
          <details class="fila-lista">
            <summary>
              <span class="fila-lista-resumen">
                <span class="fila-lista-titulo"><?= e($p['cliente']) ?></span>
                <span class="fila-lista-pista"><?= pesos((int) ($p['total'] ?? 0)) ?></span>
              </span>
              <svg class="fila-lista-flecha" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </summary>
            <div class="fila-lista-detalle">
              <?php if (!empty($p['telefono'])): ?>
                <div class="campo-detalle"><strong>Teléfono</strong><?= e($p['telefono']) ?></div>
              <?php endif; ?>
              <div class="campo-detalle">
                <strong>Productos</strong>
                <?php foreach ($p['lineas'] ?? [] as $l): ?>
                  <?= (int) $l['cant'] ?> × <?= e($l['nombre']) ?><br>
                <?php endforeach; ?>
              </div>
              <div class="campo-detalle">
                <strong>Plan propuesto</strong>
                <?php if (($p['plan'] ?? 'contado') === 'cuotas'): ?>
                  Prefiere <?= numero((int) ($p['num_cuotas_preferido'] ?? 2)) ?> cuotas
                  <br>Montos y fechas: por definir con ella
                <?php else: ?>
                  De contado
                <?php endif; ?>
              </div>
              <div class="campo-detalle"><strong>Llegó</strong><?= e(fecha_larga(substr((string) ($p['creado'] ?? ''), 0, 10))) ?></div>
              <div class="acciones-fila"><?= $accionesPedido($p) ?></div>
            </div>
          </details>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="panel">
    <h2 class="titulo-panel">Historial reciente <span class="titulo-nota">últimos <?= numero(count($historial)) ?></span></h2>
    <?php if (!$historial): ?>
      <p class="subsaludo">Todavía no hay pedidos confirmados ni descartados.</p>
    <?php else: ?>
      <div class="tabla-scroll oculta-en-movil">
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

      <!-- Solo en celular: cada pedido del historial es una tarjeta que se
           despliega al tocarla, en vez de una fila de tabla angosta. -->
      <div class="filas-esencial">
        <?php foreach ($historial as $p): $confirmado = ($p['estado'] ?? '') === 'confirmado'; $verFactura = $confirmado && !empty($p['factura_numero']); ?>
          <<?= $verFactura ? 'details' : 'div' ?> class="fila-lista">
            <summary>
              <span class="fila-lista-resumen">
                <span class="fila-lista-titulo"><?= e($p['cliente']) ?></span>
                <span class="fila-lista-pista">No. <?= e($p['numero']) ?> · <?= pesos((int) ($p['total'] ?? 0)) ?></span>
              </span>
              <span class="etiqueta <?= $confirmado ? 'pagada' : 'anulada' ?>">
                <?= $confirmado ? 'Confirmado' : 'Descartado' ?>
              </span>
              <?php if ($verFactura): ?>
                <svg class="fila-lista-flecha" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
              <?php endif; ?>
            </summary>
            <?php if ($verFactura): ?>
              <div class="fila-lista-detalle">
                <div class="acciones-fila"><a class="salir" href="factura.php?n=<?= e($p['factura_numero']) ?>">Ver factura</a></div>
              </div>
            <?php endif; ?>
          </<?= $verFactura ? 'details' : 'div' ?>>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</main>

</body>
</html>
