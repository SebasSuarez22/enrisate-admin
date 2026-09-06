<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/pedidos.php';

requerir_sesion();

$hora   = (int) date('G');
$saludo = $hora < 12 ? 'Buenos días' : ($hora < 19 ? 'Buenas tardes' : 'Buenas noches');

$r = metricas_panel();

$pedidosPendientes = pedidos_pendientes_count();

/* Los íconos son de la librería Lucide (línea, currentColor), el mismo
   estilo que ya se usa en el catálogo público. */
$modulos = [
    [
        'Facturación', 'facturar.php',
        '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M9 13h6"/><path d="M9 17h6"/>',
    ],
    [
        'Pedidos', 'pedidos.php',
        '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
    ],
    [
        'Productos', 'inventario.php',
        '<path d="M12.6 2.6a2 2 0 0 0-1.4-.6H4a2 2 0 0 0-2 2v7.2c0 .5.2 1 .6 1.4l8.7 8.7a2.4 2.4 0 0 0 3.4 0l6.6-6.6a2.4 2.4 0 0 0 0-3.4Z"/><circle cx="7.5" cy="7.5" r="1.2" fill="currentColor" stroke="none"/>',
    ],
    [
        'Catálogo público', 'catalogo-publico.php',
        '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
        true,
    ],
    [
        'Cartera y reportes', 'cartera.php',
        '<path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/>',
    ],
    [
        'Clientes', 'clientes.php',
        '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    ],
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
    <?php foreach ($modulos as $m): [$titulo, $link, $icono] = $m; $nuevaPestana = $m[3] ?? false; ?>
      <a class="modulo" href="<?= e($link) ?>"<?= $nuevaPestana ? ' target="_blank" rel="noopener"' : '' ?>>
        <span class="modulo-icono">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $icono ?></svg>
        </span>
        <span class="modulo-nombre"><?= e($titulo) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</main>

</body>
</html>
