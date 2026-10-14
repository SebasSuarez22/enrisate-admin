<?php
/* =========================================================
   Cartera y reportes · las cifras de "Reportes" en celular (menos de 700px)
   ---------------------------------------------------------
   En lugar de los dos carruseles de tarjetas (que cortaban la tarjeta del borde), una sola tarjeta con
   tres botones —Hoy, Este mes y Este año— que muestra la ganancia de ese periodo y, para hoy y este mes,
   lo más vendido; abajo queda fijo el histórico (total vendido y total invertido). Tablet y escritorio
   siguen con las tarjetas .kpi de arriba; esto no se ve ahí.
   Se incluye desde cartera.php, que ya calculó $gananciaHoy/Mes/Anio, $hoy, $mesHoy, $anioHoy,
   $totalVendido, $totalInvertido, $numVendidas, $masVendidoHoy/Mes, $cantHoy/Mes y $porSku.
   assets/js/cartera-reportes-celular.js solo cambia de periodo; los tres paneles ya vienen escritos.
   ========================================================= */
$unidades = fn(int $n): string => numero($n) . ' unidad' . ($n === 1 ? '' : 'es');
$periodos = [
    'hoy'  => ['boton' => 'Hoy',      'etiqueta' => 'Ganancia hoy',        'valor' => $gananciaHoy,  'pie' => fecha_larga($hoy),
               'mejor' => ['titulo' => 'Más vendido hoy',      'sku' => $masVendidoHoy, 'cant' => $cantHoy,  'vacio' => 'Sin ventas todavía hoy']],
    'mes'  => ['boton' => 'Este mes', 'etiqueta' => 'Ganancia este mes',   'valor' => $gananciaMes,  'pie' => mes_largo($mesHoy),
               'mejor' => ['titulo' => 'Más vendido este mes', 'sku' => $masVendidoMes, 'cant' => $cantMes, 'vacio' => 'Sin ventas todavía este mes']],
    'anio' => ['boton' => 'Este año', 'etiqueta' => 'Ganancia este año',   'valor' => $gananciaAnio, 'pie' => 'Año ' . $anioHoy,
               'mejor' => null],   // el panel no calcula "más vendido" por año
];
$elegido = 'mes';
?>
<section class="rc" id="rc" aria-label="Cifras de reportes">
  <noscript><style>.rc-panel[hidden] { display: grid !important; } .rc-tabs { display: none !important; }</style></noscript>
  <div class="rc-tarjeta">
    <div class="rc-tabs" role="tablist" aria-label="Periodo de la ganancia">
      <?php foreach ($periodos as $clave => $p): ?>
        <button type="button" role="tab" class="rc-tab" id="rc-t-<?= $clave ?>" data-periodo="<?= $clave ?>" aria-controls="rc-p-<?= $clave ?>"
                aria-selected="<?= $clave === $elegido ? 'true' : 'false' ?>" tabindex="<?= $clave === $elegido ? '0' : '-1' ?>"><?= e($p['boton']) ?></button>
      <?php endforeach; ?>
    </div>
    <?php foreach ($periodos as $clave => $p): ?>
      <div class="rc-panel" id="rc-p-<?= $clave ?>" role="tabpanel" aria-labelledby="rc-t-<?= $clave ?>"<?= $clave === $elegido ? '' : ' hidden' ?>>
        <div>
          <p class="rc-etq"><?= e($p['etiqueta']) ?></p>
          <p class="rc-valor"><?= pesos($p['valor']) ?></p>
          <p class="rc-pie"><?= e($p['pie']) ?></p>
        </div>
        <?php if ($p['mejor']): $m = $p['mejor']; ?>
          <div class="rc-mejor">
            <p class="rc-etq"><?= e($m['titulo']) ?></p>
            <?php if ($m['sku']): ?>
              <p class="rc-nombre"><?= e($porSku[$m['sku']]['nombre']) ?></p>
              <p class="rc-pie"><?= $unidades((int) $m['cant'][$m['sku']]) ?> · <?= e($porSku[$m['sku']]['sku']) ?></p>
            <?php else: ?>
              <p class="rc-nombre">—</p>
              <p class="rc-pie"><?= e($m['vacio']) ?></p>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <h3 class="rc-sec">Histórico</h3>
  <div class="rc-hist">
    <div>
      <p class="rc-etq">Total vendido</p>
      <p class="rc-valor chico"><?= pesos($totalVendido) ?></p>
      <p class="rc-pie">Histórico · <?= numero($numVendidas) ?> factura<?= $numVendidas === 1 ? '' : 's' ?></p>
    </div>
    <div>
      <p class="rc-etq">Total invertido</p>
      <p class="rc-valor chico"><?= pesos($totalInvertido) ?></p>
      <p class="rc-pie">Costo de lo vendido</p>
    </div>
  </div>
</section>
