<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/facturas.php';

requerir_sesion();

$hora   = (int) date('G');
$saludo = $hora < 12 ? 'Buenos días' : ($hora < 19 ? 'Buenas tardes' : 'Buenas noches');

$r = metricas_panel();

$modulos = [
    ['Facturación',      'Armar la cuenta de cobro, imprimir el PDF y enviarla por WhatsApp.', 'Listo', 'facturar.php'],
    ['Productos',        'Agrega productos nuevos al catálogo: SKU, marca, nombre y precio.', 'Listo', 'inventario.php'],
    ['Catálogo público', 'La vista sin login que ven las clientas. Arman su pedido y te llega por WhatsApp.', 'Listo', 'catalogo-publico.php', true],
    ['Cartera y reportes', 'Cuotas pendientes, historial de facturas y cómo va la ganancia y las ventas.', 'Listo', 'cartera.php'],
    ['Clientes',         'Nombre, teléfono y notas de cada clienta.',                          'Listo', 'clientes.php'],
    ['Márgenes',         'Costo contra precio, producto por producto. Edítalos aquí.',         'Listo', 'margenes.php'],
    ['Ajustes',          'Contraseña, datos del negocio y respaldo de la información.',        'Pendiente',  null],
];

$tituloPagina = 'Panel';
require __DIR__ . '/includes/cabecera.php';
?>

<main class="lienzo">
  <h1 class="saludo"><?= e($saludo) ?>.</h1>
  <p class="subsaludo">Un vistazo rápido a cómo va el negocio. Abajo tienes cada módulo.</p>

  <?php if ($r['cuotasVencidas'] > 0): ?>
    <div class="alertas">
      <a class="alerta peligro" href="cartera.php">
        <span class="alerta-punto"></span>
        <span><strong><?= numero($r['cuotasVencidas']) ?> cuota<?= $r['cuotasVencidas'] === 1 ? '' : 's' ?> vencida<?= $r['cuotasVencidas'] === 1 ? '' : 's' ?></strong> · <?= pesos($r['vencido']) ?> por cobrar ya</span>
        <span class="alerta-ir">Ver cartera →</span>
      </a>
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
