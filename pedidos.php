<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/formato.php';
require_once __DIR__ . '/includes/pedidos.php';
require_once __DIR__ . '/includes/imagenes.php';

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
      // "Confirmar y facturar" / "Descartar" se ven igual en la fila de
      // tabla (escritorio) y en la tarjeta desplegable (celular); se arman
      // una sola vez por pedido para no repetir el formulario dos veces.
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
      <div class="tabla-scroll oculta-en-movil tabla-pedidos-wrap">
      <table class="lineas tabla-pedidos-revisar">
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
              <td class="col-productos">
                <?php foreach ($p['lineas'] ?? [] as $l): ?>
                  <?= (int) $l['cant'] ?> × <?= e($l['nombre']) ?><br>
                <?php endforeach; ?>
              </td>
              <td class="col-plan">
                <?php if (($p['plan'] ?? 'contado') === 'cuotas'): ?>
                  Prefiere <?= numero((int) ($p['num_cuotas_preferido'] ?? 2)) ?> cuotas
                  <br><span class="sku">Montos y fechas: por definir con ella</span>
                <?php else: ?>
                  De contado
                <?php endif; ?>
              </td>
              <td class="num"><?= pesos((int) ($p['total'] ?? 0)) ?></td>
              <td><?= e(fecha_larga(substr((string) ($p['creado'] ?? ''), 0, 10))) ?></td>
              <td class="num"><div class="acciones-pedido"><?= $accionesPedido($p) ?></div></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <!-- Solo en escritorio (≥960px, ver "PEDIDOS · escritorio" al final de
           enrisate.css): cada pedido es una ficha con sus productos en
           mini‑tarjetas con foto. La tabla de arriba se esconde ahí y
           queda tal cual en tablet; las acciones son las mismas. -->
      <div class="pedidos-fichas">
        <?php foreach ($pendientes as $p):
            $unidades = 0;
            foreach ($p['lineas'] ?? [] as $l) { $unidades += (int) $l['cant']; }
            $cuotas = ($p['plan'] ?? 'contado') === 'cuotas';
        ?>
          <article class="ped-ficha">
            <header class="ped-cabeza">
              <div class="ped-quien">
                <h3 class="ped-nombre"><?= e($p['cliente']) ?></h3>
                <p class="ped-meta">
                  <span class="ped-tel"><?= !empty($p['telefono']) ? e($p['telefono']) : 'Sin teléfono' ?></span>
                  <span>Llegó <?= e(fecha_abreviada(substr((string) ($p['creado'] ?? ''), 0, 10))) ?> · <?= numero($unidades) ?> unidad<?= $unidades === 1 ? '' : 'es' ?></span>
                  <?php $dto = (int) ($p['subtotal'] ?? 0) - (int) ($p['total'] ?? 0); if ($dto > 0): ?>
                    <span class="ped-dto">Incluye descuento de <?= e(pesos($dto)) ?></span>
                  <?php endif; ?>
                </p>
              </div>
              <span class="ped-plan<?= $cuotas ? ' es-cuotas' : '' ?>">
                <?= $cuotas ? 'Prefiere ' . numero((int) ($p['num_cuotas_preferido'] ?? 2)) . ' cuotas' : 'De contado' ?>
              </span>
              <span class="ped-total"><?= pesos((int) ($p['total'] ?? 0)) ?></span>
              <div class="acciones-pedido ped-acciones"><?= $accionesPedido($p) ?></div>
            </header>
            <ul class="ped-items">
              <?php foreach ($p['lineas'] ?? [] as $l):
                  $foto = producto_ruta_foto((string) ($l['sku'] ?? ''));
                  $cant = (int) $l['cant'];
              ?>
                <li class="ped-item">
                  <span class="ped-foto">
                    <?php if ($foto !== null): ?>
                      <img src="<?= e($foto) ?>" alt="" width="46" height="46" decoding="async">
                    <?php else: ?>
                      <span class="ped-foto-vacia" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $l['nombre'], 0, 1))) ?></span>
                    <?php endif; ?>
                  </span>
                  <span class="ped-item-texto">
                    <span class="ped-item-nombre"><?= e($l['nombre']) ?></span>
                    <?php if (($l['marca'] ?? '') !== ''): ?><span class="ped-item-marca"><?= e($l['marca']) ?></span><?php endif; ?>
                  </span>
                  <span class="ped-item-cifra">
                    <strong>×<?= $cant ?></strong>
                    <span><?= pesos((int) ($l['precio'] ?? 0) * $cant) ?></span>
                  </span>
                </li>
              <?php endforeach; ?>
            </ul>
          </article>
        <?php endforeach; ?>
      </div>

      <!-- Solo en celular (ver .filas-esencial / .oculta-en-movil en
           enrisate.css): cada pedido es una tarjeta que se despliega al
           tocarla, en vez de una fila de tabla angosta con el bloque de
           acciones cortado por el lado derecho. -->
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
              <?php $dtoM = (int) ($p['subtotal'] ?? 0) - (int) ($p['total'] ?? 0); if ($dtoM > 0): ?>
                <div class="campo-detalle"><strong>Descuento del catálogo</strong>−<?= e(pesos($dtoM)) ?> (el total ya lo incluye)</div>
              <?php endif; ?>
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
</main>

</body>
</html>
