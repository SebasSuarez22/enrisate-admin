<?php
/* =========================================================
   Clientes · directorio de escritorio y tablet (700px o más)
   ---------------------------------------------------------
   A la izquierda, la lista agrupada por letra con un índice A–Z, un buscador y un filtro por
   segmento; a la derecha, la ficha de la clienta elegida. La lista y la ficha se desplazan por
   dentro: la página no crece con cada clienta.

   Se incluye desde clientes.php, que ya calculó:
     $clientesAZ   las clientas ordenadas de la A a la Z (sin tildes que desordenen)
     $dirDatos     datos_directorio_clientes(): todo lo de cada ficha, ya calculado
     $token        el token CSRF
   assets/js/clientes-directorio.js arma la ficha con $dirDatos y hace funcionar buscador,
   filtro, índice y formularios. Los formularios mandan lo mismo que siempre (agregar, editar,
   eliminar y traer de facturación); el servidor sigue siendo quien valida todo.
   Desde 700px se ve esto (directorio de escritorio y de tablet). Debajo de 700px se ve, en su lugar, el
   directorio de celular (más abajo): mosaicos de segmento, lista y ficha por pasos, que arma
   assets/js/clientes-celular.js con los mismos datos.
   ========================================================= */

$iniciales = function (string $nombre): string {
    $letras = '';
    foreach (array_slice(preg_split('/\s+/u', trim($nombre)) ?: [], 0, 2) as $palabra) {
        $letras .= mb_strtoupper(mb_substr($palabra, 0, 1));
    }
    return $letras !== '' ? $letras : '·';
};
$conteoSegmento = ['todas' => count($clientesAZ)];
foreach (SEGMENTOS_CLIENTE as $clave => $_) {
    $conteoSegmento[$clave] = count(array_filter($dirDatos, fn(array $d) => in_array($clave, $d['segs'], true)));
}
$letrasPresentes = [];
foreach ($clientesAZ as $c) {
    $letrasPresentes[letra_cliente((string) $c['nombre'])] = true;
}
?>
<div class="cd" id="directorio" data-csrf="<?= e($token) ?>">
  <div class="cd-izq">
    <div class="cd-izq-cab">
      <h2 class="cd-titulo">Clientes</h2>
      <div class="cd-acc">
        <form method="post" action="clientes.php">
          <input type="hidden" name="csrf" value="<?= e($token) ?>">
          <input type="hidden" name="accion" value="sincronizar">
          <button type="submit" class="cd-btn solo" title="Traer clientes de facturación" aria-label="Traer clientes de facturación">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15V3"/><path d="M3 15v4a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-4"/><path d="m7 10 5 5 5-5"/></svg>
          </button>
        </form>
        <button type="button" class="cd-btn principal" id="cd-nuevo">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Agregar
        </button>
      </div>
    </div>

    <div class="cd-filtros">
      <label class="cd-busca">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/></svg>
        <input type="search" id="cd-q" placeholder="Buscar nombre o teléfono" aria-label="Buscar cliente" autocomplete="off">
      </label>
      <select id="cd-seg" aria-label="Segmento">
        <option value="todas">Todas (<?= $conteoSegmento['todas'] ?>)</option>
        <?php foreach (SEGMENTOS_CLIENTE as $clave => [$titulo]): ?>
          <option value="<?= $clave ?>"><?= e($titulo) ?> (<?= $conteoSegmento[$clave] ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="cd-lista-caja">
      <div class="cd-lista" id="cd-lista">
        <?php $letraActual = null; foreach ($clientesAZ as $c):
            $d = $dirDatos[(string) $c['id']];
            $letra = letra_cliente((string) $c['nombre']);
            if ($letra !== $letraActual): $letraActual = $letra; ?>
              <div class="cd-letra" id="cd-l-<?= e($letra === '#' ? 'num' : $letra) ?>" data-letra="<?= e($letra) ?>"><?= e($letra) ?></div>
            <?php endif;
            $punto = $d['debe'] > 0 ? 'cuo' : (in_array('rep', $d['segs'], true) ? 'rep' : ''); ?>
          <button type="button" class="cd-fila" data-id="<?= e($c['id']) ?>" data-letra="<?= e($letra) ?>" data-segs="<?= e(implode(' ', $d['segs'])) ?>"
                  data-q="<?= e(clave_nombre_cliente((string) $c['nombre']) . ' ' . normalizar_telefono((string) $c['telefono'])) ?>" aria-current="false">
            <span class="cd-ini<?= $d['vencida'] ? ' v' : '' ?>" aria-hidden="true"><?= e($iniciales((string) $c['nombre'])) ?></span>
            <span class="cd-fila-txt"><span class="cd-nombre"><?= e($c['nombre']) ?></span><span class="cd-pista"><?= e($d['pista']) ?></span></span>
            <?php if ($punto !== ''): ?><span class="cd-punto <?= $punto ?>" aria-hidden="true"></span><?php endif; ?>
          </button>
        <?php endforeach; ?>
      </div>
      <nav class="cd-az" id="cd-az" aria-label="Ir a una letra">
        <?php foreach (array_merge(range('A', 'Z'), ['#']) as $l): ?>
          <button type="button" data-letra="<?= e($l) ?>"<?= isset($letrasPresentes[$l]) ? '' : ' disabled' ?> aria-label="Ir a la letra <?= e($l) ?>"><?= e($l) ?></button>
        <?php endforeach; ?>
      </nav>
    </div>
    <p class="cd-vacio" id="cd-vacio" hidden>Nadie coincide con la búsqueda. Prueba con otro nombre, un teléfono o cambia de segmento.</p>
  </div>

  <section class="cd-ficha" id="cd-ficha" aria-live="polite" tabindex="-1"></section>
</div>

<!-- Celular (menos de 700px): mosaicos de segmento → lista → ficha con pestañas. Todo lo arma clientes-celular.js;
     aquí solo está el contenedor y la ventana de "Agregar cliente", que manda el formulario de siempre. -->
<div class="cm" id="cm" data-csrf="<?= e($token) ?>" data-total="<?= count($clientesAZ) ?>">
  <div class="cm-pantalla" id="cm-pantalla" tabindex="-1"></div>
</div>
<dialog class="cm-hoja" id="cm-hoja" aria-labelledby="cm-hoja-t">
  <div class="cm-hoja-agarre" aria-hidden="true"></div>
  <form method="post" action="clientes.php" class="cm-form" id="cm-form-nuevo">
    <div class="cm-hoja-cab">
      <h2 id="cm-hoja-t">Agregar cliente</h2>
      <button type="button" class="cm-cerrar" data-cm-cerrar aria-label="Cerrar">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
      </button>
    </div>
    <input type="hidden" name="csrf" value="<?= e($token) ?>">
    <div class="campo"><label for="cm-nombre">Nombre completo</label><input type="text" id="cm-nombre" name="nombre" placeholder="Gina Pertuz" required autocomplete="off"></div>
    <div class="campo"><label for="cm-tel">Teléfono</label><input type="tel" id="cm-tel" name="telefono" placeholder="3001234567" inputmode="numeric" autocomplete="off"></div>
    <div class="campo"><label for="cm-notas">Notas</label><input type="text" id="cm-notas" name="notas" placeholder="Opcional" autocomplete="off"></div>
    <button type="submit" class="cm-btn principal ancho">Agregar cliente</button>
  </form>
</dialog>

<script type="application/json" id="datos-clientes"><?= json_encode([
    'orden'     => array_map('strval', array_keys($dirDatos)),
    'clientes'  => $dirDatos,
    'segmentos' => array_map(fn(array $s) => ['t' => $s[0], 'd' => $s[1]], SEGMENTOS_CLIENTE),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="assets/js/clientes-directorio.js?v=<?= filemtime(__DIR__ . '/../assets/js/clientes-directorio.js') ?>" defer></script>
<script src="assets/js/clientes-celular.js?v=<?= filemtime(__DIR__ . '/../assets/js/clientes-celular.js') ?>" defer></script>
