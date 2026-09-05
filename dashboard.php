<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/pedidos.php';

requerir_sesion();

$hora   = (int) date('G');
$saludo = $hora < 12 ? 'Buenos días' : ($hora < 19 ? 'Buenas tardes' : 'Buenas noches');

$r = metricas_panel();

$pedidosPendientes = pedidos_pendientes_count();
$textoPedidos = $pedidosPendientes > 0
    ? numero($pedidosPendientes) . ' pedido' . ($pedidosPendientes === 1 ? '' : 's') . ' por revisar.'
    : 'Lo que llega del catálogo público, listo para confirmar o descartar.';

$modulos = [
    ['Facturación',      'Armar la cuenta de cobro, imprimir el PDF y enviarla por WhatsApp.', 'Listo', 'facturar.php'],
    ['Pedidos',          $textoPedidos, 'Listo', 'pedidos.php'],
    ['Productos',        'Agrega productos nuevos al catálogo: SKU, marca, nombre y precio.', 'Listo', 'inventario.php'],
    ['Catálogo público', 'La vista sin login que ven los clientes. Arman su pedido y te llega por WhatsApp.', 'Listo', 'catalogo-publico.php', true],
    ['Cartera y reportes', 'Cuotas pendientes, historial de facturas y cómo va la ganancia y las ventas.', 'Listo', 'cartera.php'],
    ['Clientes',         'Nombre, teléfono y notas de cada cliente.',                          'Listo', 'clientes.php'],
];

$tituloPagina = 'Panel';
require __DIR__ . '/includes/cabecera.php';
?>

<main class="lienzo">
  <h1 class="saludo"><?= e($saludo) ?>.</h1>
  <p class="subsaludo">Un vistazo rápido a cómo va el negocio. Abajo tienes cada módulo.</p>

  <?php if ($r['cuotasVencidas'] > 0 || $pedidosPendientes > 0): ?>
    <div class="alertas">
      <?php if ($pedidosPendientes > 0): ?>
        <a class="alerta" href="pedidos.php">
          <span class="alerta-punto"></span>
          <span><strong><?= numero($pedidosPendientes) ?> pedido<?= $pedidosPendientes === 1 ? '' : 's' ?> nuevo<?= $pedidosPendientes === 1 ? '' : 's' ?></strong> del catálogo público por revisar</span>
          <span class="alerta-ir">Ver pedidos →</span>
        </a>
      <?php endif; ?>
      <?php if ($r['cuotasVencidas'] > 0): ?>
        <a class="alerta peligro" href="cartera.php">
          <span class="alerta-punto"></span>
          <span><strong><?= numero($r['cuotasVencidas']) ?> cuota<?= $r['cuotasVencidas'] === 1 ? '' : 's' ?> vencida<?= $r['cuotasVencidas'] === 1 ? '' : 's' ?></strong> · <?= pesos($r['vencido']) ?> por cobrar ya</span>
          <span class="alerta-ir">Ver cartera →</span>
        </a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="kpis">
    <div class="kpi acento">
      <p class="kpi-etiqueta">Por cobrar</p>
      <p class="kpi-valor"><?= pesos($r['porCobrar']) ?></p>
      <p class="kpi-sub"><?= $r['cuotasPendientes'] > 0
        ? numero($r['cuotasPendientes']) . ' cuota' . ($r['cuotasPendientes'] === 1 ? '' : 's') . ' pendiente' . ($r['cuotasPendientes'] === 1 ? '' : 's')
        : 'Todo al día' ?></p>
    </div>
    <div class="kpi<?= $r['vencido'] > 0 ? ' peligro' : '' ?>">
      <p class="kpi-etiqueta">Vencido</p>
      <p class="kpi-valor"><?= pesos($r['vencido']) ?></p>
      <p class="kpi-sub"><?= $r['cuotasVencidas'] > 0
        ? numero($r['cuotasVencidas']) . ' cuota' . ($r['cuotasVencidas'] === 1 ? '' : 's') . ' por cobrar ya'
        : 'Sin cuotas vencidas' ?></p>
    </div>
    <div class="kpi">
      <p class="kpi-etiqueta">Ganancia del mes</p>
      <p class="kpi-valor"><?= pesos($r['gananciaMes']) ?></p>
      <p class="kpi-sub"><?= e(mes_largo(date('Y-m'))) ?></p>
    </div>
    <div class="kpi">
      <p class="kpi-etiqueta">Ganancia del año</p>
      <p class="kpi-valor"><?= pesos($r['gananciaAnio']) ?></p>
      <p class="kpi-sub">Año <?= e(date('Y')) ?></p>
    </div>
    <div class="kpi">
      <p class="kpi-etiqueta">Total vendido en el año</p>
      <p class="kpi-valor"><?= pesos($r['ventaAnio']) ?></p>
      <p class="kpi-sub">Año <?= e(date('Y')) ?></p>
    </div>
    <div class="kpi">
      <p class="kpi-etiqueta">Total invertido</p>
      <p class="kpi-valor"><?= pesos($r['totalInvertido']) ?></p>
      <p class="kpi-sub">Costo de lo vendido · histórico</p>
    </div>
  </div>

  <h2 class="titulo-modulo">Módulos</h2>
  <div class="rejilla">
    <?php foreach ($modulos as $m): [$titulo, $texto, $estado, $link] = $m; $nuevaPestana = $m[4] ?? false; ?>
      <article class="modulo<?= $link ? ' activo' : '' ?>">
        <h3><?= e($titulo) ?></h3>
        <p><?= e($texto) ?></p>
        <?php if ($link): ?>
          <a class="boton mini" href="<?= e($link) ?>"<?= $nuevaPestana ? ' target="_blank" rel="noopener"' : '' ?>>Abrir</a>
        <?php else: ?>
          <span class="etiqueta"><?= e($estado) ?></span>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
</main>

</body>
</html>
