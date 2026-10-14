<?php
/* =========================================================
   Cartera · tablero de tablet y celular (se muestra hasta 959px; escritorio sigue con las tablas)
   ---------------------------------------------------------
   Se incluye desde cartera.php, que ya calculó $tablero (una entrada por cuota pendiente), $tb (los
   totales por urgencia), $datosTablero (lo que usa el buscador y la ventana "Cobrar") y $token.
   assets/js/cartera-tablero.js hace funcionar el buscador, los filtros y la ventana de cobro.
   ========================================================= */
$icWa    = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2C6.49 2 2 6.49 2 12c0 1.87.5 3.62 1.38 5.13L2 22l4.99-1.31A9.94 9.94 0 0 0 12 22c5.51 0 10-4.49 10-10S17.51 2 12 2Zm5.2 14.2c-.22.62-1.28 1.2-1.77 1.24-.45.05-.98.24-3.29-.7-2.77-1.13-4.53-3.92-4.66-4.1-.14-.18-1.1-1.47-1.1-2.8 0-1.34.7-1.99.95-2.26.24-.27.53-.34.7-.34h.5c.16 0 .38-.03.58.44.22.51.75 1.77.82 1.9.06.13.1.28.02.46-.08.18-.13.29-.25.44-.13.16-.27.35-.38.47-.13.13-.27.28-.11.55.16.27.7 1.16 1.51 1.87 1.04.93 1.92 1.22 2.19 1.36.27.14.43.12.6-.07.16-.19.68-.79.86-1.06.18-.27.36-.22.6-.13.24.09 1.55.73 1.82.86.27.13.45.2.51.31.07.12.07.68-.15 1.36Z"/></svg>';
$icCal   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>';
$icExcel = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15V3"/><path d="M3 15v4a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-4"/><path d="m7 10 5 5 5-5"/></svg>';
$icOk    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12.5l4.5 4.5L20 6"/></svg>';
$icDesc  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M5 19 19 5M7.5 8.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm9 9a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z"/></svg>';
$icX     = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>';

$urgentes = array_values(array_filter($tablero, fn($c) => $c['grupo'] !== 'd'));
$etiquetaGrupo = ['v' => 'vencida', 's' => 'pronto', 'd' => ''];
$nombreGrupo   = ['v' => 'Vencidas', 's' => 'Esta semana', 'd' => 'Después'];
// "25 sep 2026" → "25 sep" (el año se esconde en celular y tablet, donde el espacio es poco).
$sinAnio = fn(string $fecha): string => (string) preg_replace('/\s\d{4}$/', '', $fecha);
// Las dos primeras letras de la clienta, para el círculo de la tarjeta en celular.
$iniciales = function (string $nombre): string {
    $letras = '';
    foreach (array_slice(preg_split('/\s+/u', trim($nombre)) ?: [], 0, 2) as $palabra) {
        $letras .= mb_strtoupper(mb_substr($palabra, 0, 1));
    }
    return $letras !== '' ? $letras : '·';
};
?>
<!-- Una sola búsqueda para clientas, facturas y productos -->
<div class="tc-buscar">
  <div class="tc-buscador">
    <label class="visualmente-oculto" for="tc-q">Buscar clienta, factura o producto</label>
    <svg class="tc-lupa" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/></svg>
    <input type="search" id="tc-q" placeholder="Clienta, factura o producto" autocomplete="off" enterkeyhint="search"
           role="combobox" aria-expanded="false" aria-controls="tc-res" aria-autocomplete="list">
    <button type="button" class="tc-borrar" id="tc-borrar" aria-label="Borrar búsqueda" hidden><?= $icX ?></button>
  </div>
  <div class="tc-res" id="tc-res" role="listbox" aria-label="Resultados" hidden></div>
</div>


<div class="tc<?= $tablero ? '' : ' tc-sin-cuotas' ?>" id="tablero">

  <!-- Lo que hay por cobrar y cuánto ya venció -->
  <section class="tc-hero" aria-label="Resumen de la cartera">
    <div>
      <p class="tc-hero-t">Por cobrar</p>
      <p class="tc-hero-m"><?= pesos($tb['total']) ?></p>
    </div>
    <?php if ($tablero): ?>
      <p class="tc-hero-s"><b><?= pesos($tb['v']) ?></b> ya venció · <?= count($tablero) ?> <?= count($tablero) === 1 ? 'cuota pendiente' : 'cuotas pendientes' ?></p>
      <div class="tc-barra" role="img" aria-label="Por cobrar según la urgencia: vencido <?= e(pesos($tb['v'])) ?>, esta semana <?= e(pesos($tb['s'])) ?>, después <?= e(pesos($tb['d'])) ?>">
        <?php foreach (['v' => 'vencido', 's' => 'semana', 'd' => 'despues'] as $g => $clase): if ($tb[$g] > 0): ?>
          <i class="<?= $clase ?>" style="flex: <?= (int) $tb[$g] ?>"></i>
        <?php endif; endforeach; ?>
      </div>
      <ul class="tc-leyenda">
        <li class="vencido">Vencido <?= pesos($tb['v']) ?></li>
        <li class="semana">Esta semana <?= pesos($tb['s']) ?></li>
        <li class="despues">Después <?= pesos($tb['d']) ?></li>
      </ul>
      <a class="tc-ico tc-hero-xl" href="cartera-excel.php" title="Descargar Excel: vencidas y pendientes" aria-label="Descargar Excel: vencidas y pendientes"><?= $icExcel ?></a>
    <?php else: ?>
      <p class="tc-hero-s">No hay cuotas pendientes por cobrar.</p>
    <?php endif; ?>
  </section>

  <?php if ($tablero): ?>
    <!-- Lo que hay que atender primero: vencidas y las de esta semana -->
    <section class="tc-seccion tc-urgentes" aria-labelledby="tc-urg-t">
      <div class="tc-seccion-cab">
        <h2 class="tc-titulo" id="tc-urg-t">Para atender primero</h2>
        <small><?= count($urgentes) ?> <?= count($urgentes) === 1 ? 'cuota' : 'cuotas' ?></small>
      </div>
      <?php if ($urgentes): ?>
        <div class="tc-urg-lista" role="list" aria-label="Cuotas vencidas o que vencen esta semana">
          <?php foreach ($urgentes as $i => $c):
            // En celular la fecha va sin el año ("25 sep"); en tablet se ve completa, como antes.
            $venceCorta = $sinAnio($c['venceTxt']);
            $venceAnio  = substr($c['venceTxt'], strlen($venceCorta));
          ?>
            <article class="tc-carta <?= $etiquetaGrupo[$c['grupo']] ?><?= $i === 0 ? ' abierta' : '' ?>" role="listitem">
              <span class="tc-ini" aria-hidden="true"><?= e($iniciales($c['cliente'])) ?></span>
              <div class="tc-carta-q">
                <b><?= e($c['cliente']) ?></b>
                <span class="tc-pill <?= $etiquetaGrupo[$c['grupo']] ?>"><?= e($textoDias($c['dias'])) ?></span>
              </div>
              <div class="tc-carta-m">
                <p class="tc-monto"><?= pesos($c['pendiente']) ?></p>
                <p class="tc-meta"><?= e($c['rotulo']) ?> · <span class="tc-vence">vence </span><?= e($venceCorta) ?><span class="tc-vence"><?= e($venceAnio) ?></span></p>
              </div>
              <button type="button" class="tc-abrir" aria-expanded="<?= $i === 0 ? 'true' : 'false' ?>" aria-controls="tc-panel-<?= $i ?>"
                      aria-label="Ver las acciones de <?= e($c['cliente']) ?>, <?= e($c['rotulo']) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>
              <div class="tc-panel" id="tc-panel-<?= $i ?>">
                <?php if ($c['pendiente'] < $c['pactado']): ?>
                  <p class="tc-de">Cuota de <?= pesos($c['pactado']) ?> · vence <?= e($c['venceTxt']) ?></p>
                <?php endif; ?>
                <div class="tc-acc">
                  <button type="button" class="tc-cobrar" data-tc-cobrar="<?= e($c['id']) ?>"><?= $icOk ?>Cobrar</button>
                  <?php if ($c['wa']): ?>
                    <a class="tc-ico wa" href="<?= e($c['wa']) ?>" target="_blank" rel="noopener"
                       aria-label="<?= $c['mora'] ? 'Escribir por WhatsApp a ' . e($c['cliente']) . ' por la mora' : 'Recordar por WhatsApp a ' . e($c['cliente']) ?>"><?= $icWa ?><span class="tc-et" aria-hidden="true">Escribir</span></a>
                  <?php endif; ?>
                  <button type="button" class="tc-ico btn-reprogramar" data-numero="<?= e($c['numero']) ?>" data-idx="<?= (int) $c['idx'] ?>"
                          aria-label="Cambiar la fecha de vencimiento de <?= e($c['cliente']) ?>, <?= e($c['rotulo']) ?>"><?= $icCal ?><span class="tc-et" aria-hidden="true">Fecha</span></button>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="tc-vacio">Nada vence en los próximos 7 días y no hay cuotas vencidas.</p>
      <?php endif; ?>
    </section>

    <!-- Todas las cuotas, en filas compactas -->
    <section class="tc-seccion tc-todas" id="tc-todas" aria-labelledby="tc-todas-t">
      <div class="tc-seccion-cab">
        <h2 class="tc-titulo" id="tc-todas-t">Todas las cuotas</h2>
        <a class="tc-ico" href="cartera-excel.php" title="Descargar Excel: vencidas y pendientes" aria-label="Descargar Excel: vencidas y pendientes"><?= $icExcel ?></a>
      </div>
      <div class="tc-chips" role="group" aria-label="Filtrar cuotas">
        <button type="button" class="tc-chip" data-tc-filtro="todas" aria-pressed="true">Todas <b><?= count($tablero) ?></b></button>
        <button type="button" class="tc-chip" data-tc-filtro="v" aria-pressed="false">Vencidas <b><?= $tb['n_v'] ?></b></button>
        <button type="button" class="tc-chip" data-tc-filtro="s" aria-pressed="false">Esta semana <b><?= $tb['n_s'] ?></b></button>
        <button type="button" class="tc-chip" data-tc-filtro="d" aria-pressed="false">Después <b><?= $tb['n_d'] ?></b></button>
      </div>
      <p class="tc-filtro-cliente" id="tc-filtro-cliente" hidden>
        <span>Cuotas de <b id="tc-filtro-nombre"></b></span>
        <button type="button" id="tc-filtro-quitar" aria-label="Quitar el filtro por clienta"><?= $icX ?></button>
      </p>
      <div class="tc-compacta" id="tc-lista">
        <?php $grupoPrevio = null; foreach ($tablero as $c): ?>
          <?php if ($c['grupo'] !== $grupoPrevio): $grupoPrevio = $c['grupo']; ?>
            <div class="tc-grupo <?= $c['grupo'] ?>" data-tc-grupo="<?= $c['grupo'] ?>">
              <span class="tc-grupo-p" aria-hidden="true"></span><b><?= e($nombreGrupo[$c['grupo']]) ?></b>
              <span class="tc-grupo-n"><?= (int) $tb['n_' . $c['grupo']] ?></span><span class="tc-grupo-s"><?= pesos($tb[$c['grupo']]) ?></span>
            </div>
          <?php endif; ?>
          <button type="button" class="tc-fila" data-tc-cobrar="<?= e($c['id']) ?>" data-grupo="<?= $c['grupo'] ?>" data-cliente="<?= e($c['cliente']) ?>">
            <span class="tc-fila-n"><?= e($c['cliente']) ?></span>
            <span class="tc-fila-m"><?= e($c['rotulo']) ?> · <?= e($sinAnio($c['venceTxt'])) ?><span class="tc-anio"><?= e(substr($c['venceTxt'], strlen($sinAnio($c['venceTxt'])))) ?></span></span>
            <span class="tc-fila-d"><b><?= pesos($c['pendiente']) ?></b><small class="<?= $c['grupo'] === 'v' ? 'v' : '' ?>"><?= e($textoDias($c['dias'])) ?></small></span>
          </button>
        <?php endforeach; ?>
      </div>
      <p class="tc-vacio" id="tc-vacio" hidden></p>
    </section>
  <?php endif; ?>

<?php if ($tablero): ?>
<!-- Ventana "Cobrar": una sola para toda la página; cartera-tablero.js la llena con la cuota que se toca.
     Los formularios mandan lo mismo que el panel de escritorio (abono o descuento). En celular sube
     como hoja desde abajo; en tablet no es una ventana: es el panel fijo de la derecha, siempre abierto
     con la cuota elegida en la lista. -->
<dialog class="tc-dlg" id="tc-cobrar" aria-labelledby="tc-cobrar-t">
  <div class="tc-hoja">
    <div class="tc-agarre" aria-hidden="true"></div>
    <header class="tc-hoja-cab">
      <span class="tc-ini-p" id="tc-cobrar-ini" aria-hidden="true"></span>
      <div class="tc-hoja-tit">
        <h2 id="tc-cobrar-t">Cobrar</h2>
        <p id="tc-cobrar-sub"></p>
      </div>
      <button type="button" class="tc-cerrar" data-tc-cerrar aria-label="Cerrar"><?= $icX ?></button>
    </header>

    <div class="tc-resumen-cuota">
      <span>Falta por pagar</span>
      <b id="tc-cobrar-pend"></b>
      <span class="tc-pill tc-resumen-extra" id="tc-cobrar-dias"></span>
      <small class="tc-resumen-extra" id="tc-cobrar-de"></small>
    </div>

    <form method="post" action="cartera.php" class="tc-form" id="tc-form-abono" novalidate>
      <input type="hidden" name="csrf" value="<?= e($token) ?>">
      <input type="hidden" name="numero" value="">
      <input type="hidden" name="cuota_idx" value="">
      <input type="hidden" name="monto" value="">
      <label class="tc-campo-monto" for="tc-monto">
        <span>Monto del abono</span>
        <span class="tc-monto-caja"><i aria-hidden="true">$</i><input type="text" id="tc-monto" inputmode="numeric" autocomplete="off" aria-describedby="tc-error"></span>
      </label>
      <div class="tc-atajos">
        <button type="button" class="tc-atajo" data-atajo="total" aria-pressed="true"></button>
        <button type="button" class="tc-atajo" data-atajo="mitad" aria-pressed="false"></button>
      </div>
      <p class="tc-error" id="tc-error" role="alert"></p>
      <button type="submit" class="tc-confirmar"><?= $icOk ?>Confirmar abono</button>
    </form>

    <form method="post" action="cartera.php" class="tc-form" id="tc-form-desc" novalidate hidden>
      <input type="hidden" name="csrf" value="<?= e($token) ?>">
      <input type="hidden" name="accion" value="descuento">
      <input type="hidden" name="numero" value="">
      <input type="hidden" name="cuota_idx" value="">
      <input type="hidden" name="monto" value="">
      <label class="tc-campo-monto" for="tc-monto-d">
        <span>Monto del descuento</span>
        <span class="tc-monto-caja"><i aria-hidden="true">$</i><input type="text" id="tc-monto-d" inputmode="numeric" autocomplete="off" aria-describedby="tc-error-d"></span>
      </label>
      <label class="tc-campo-texto" for="tc-motivo">
        <span>Motivo del descuento</span>
        <input type="text" id="tc-motivo" name="motivo" maxlength="255" autocomplete="off" placeholder="Por qué se le descuenta">
      </label>
      <p class="tc-error" id="tc-error-d" role="alert"></p>
      <button type="submit" class="tc-confirmar"><?= $icOk ?>Confirmar descuento</button>
      <button type="button" class="tc-enlace" data-tc-volver>Volver al abono</button>
    </form>

    <div class="tc-enlaces" id="tc-enlaces">
      <a class="tc-enlace" id="tc-wa" href="#" target="_blank" rel="noopener" hidden><?= $icWa ?><span class="tc-et-largo"></span><span class="tc-et-corto"></span></a>
      <button type="button" class="tc-enlace" id="tc-ir-desc"><?= $icDesc ?>Aplicar descuento</button>
      <button type="button" class="tc-enlace btn-reprogramar" id="tc-ir-fecha" data-numero="" data-idx=""><?= $icCal ?><span class="tc-et-largo">Cambiar la fecha</span><span class="tc-et-corto">Cambiar fecha</span></button>
    </div>
  </div>
</dialog>
<?php endif; ?>
</div>

<script type="application/json" id="datos-tablero"><?= json_encode($datosTablero, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

