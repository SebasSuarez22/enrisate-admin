<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/formato.php';
require_once __DIR__ . '/includes/catalogo.php';
require_once __DIR__ . '/includes/imagenes.php';

requerir_sesion();

/* Revisar fondos de fotos: pasa las fotos ya subidas por el mismo arreglo que reciben
   las nuevas (ver procesar_foto_producto en includes/imagenes.php) y muestra, tarjeta por
   tarjeta, cómo quedó cada una. Va por lotes pequeños desde el navegador para que ninguna
   petición tarde más de unos segundos, por lento que esté el servidor. */

const RF_LOTE = 6;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'lote') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        http_response_code(403);
        echo json_encode(['error' => 'La página estuvo abierta demasiado tiempo. Recárgala e intenta de nuevo.']);
        exit;
    }
    if (!extension_loaded('gd')) {
        http_response_code(500);
        echo json_encode(['error' => 'El servidor no tiene activada la extensión GD de PHP.']);
        exit;
    }
    $id = (string) ($_POST['respaldo'] ?? '');
    if (!respaldo_valido($id)) {
        $id = date('Ymd-His');
    }
    $skus  = skus_con_foto();
    $desde = max(0, (int) ($_POST['desde'] ?? 0));
    $filas = [];
    foreach (array_slice($skus, $desde, RF_LOTE) as $sku) {
        $filas[$sku] = revisar_foto_producto($sku, $id);
    }
    guardar_reporte_revision($id, $filas);
    echo json_encode(['respaldo' => $id, 'siguiente' => $desde + RF_LOTE, 'total' => count($skus)]);
    exit;
}

$error = '';
$ok    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'La página estuvo abierta demasiado tiempo. Recárgala e intenta de nuevo.';
    } elseif (($_POST['accion'] ?? '') === 'deshacer') {
        $n = deshacer_revision_fotos((string) ($_POST['respaldo'] ?? ''));
        header('Location: revisar-fotos.php?ok=deshecha&n=' . $n);
        exit;
    }
}

if (($_GET['ok'] ?? '') === 'revisadas') {
    $ok = 'Revisión lista. Así quedaron las fotos en el catálogo.';
} elseif (($_GET['ok'] ?? '') === 'deshecha') {
    $n  = (int) ($_GET['n'] ?? 0);
    $ok = 'Revisión deshecha: ' . $n . ' foto' . ($n === 1 ? '' : 's') . ' volvieron a como estaban.';
}

$nombres = [];
foreach (catalogo() as $p) {
    $nombres[$p['sku']] = [$p['nombre'], $p['marca']];
}

$totalFotos = count(skus_con_foto());
$idRevision = ultima_revision_fotos();
$reporte    = $idRevision ? reporte_revision_fotos($idRevision) : [];

// Cada foto cae en un grupo; los que piden algo de ti van primero.
$grupos = [
    'subir'     => ['Volver a subir', 'El fondo no es blanco y no se pudo arreglar solo (por ejemplo, un envase blanco sobre gris). Súbela de nuevo con fondo blanco desde Productos.'],
    'arreglada' => ['Arregladas', 'El fondo gris quedó blanco o el de color quedó transparente.'],
    'recortada' => ['Recortadas', 'Se quitaron los bordes vacíos para que el producto se vea del mismo tamaño que los demás.'],
    'igual'     => ['Sin cambios', 'Ya estaban bien.'],
];
$porGrupo = array_fill_keys(array_keys($grupos), []);
$conCambios = 0;
foreach ($reporte as $sku => $fila) {
    $cambios = $fila['cambios'] ?? [];
    if ($cambios) {
        $conCambios++;
    }
    if (!($fila['fondo_ok'] ?? true)) {
        $grupo = 'subir';
    } elseif (array_intersect($cambios, ['blanqueada', 'transparente'])) {
        $grupo = 'arreglada';
    } elseif ($cambios) {
        $grupo = 'recortada';
    } else {
        $grupo = 'igual';
    }
    $porGrupo[$grupo][] = $sku;
}

$token = csrf_token();

$tituloPagina = 'Revisar fondos de fotos';
$barraVolver  = true;
require __DIR__ . '/includes/cabecera.php';
?>

<main class="lienzo ancho">
  <h1 class="saludo">Revisar fondos de fotos</h1>
  <p class="subsaludo">Pasa las fotos que ya están subidas por el mismo arreglo que reciben las nuevas: el fondo gris claro queda blanco, el de color queda transparente y se recortan los bordes vacíos, para que en el catálogo todas compartan el mismo fondo y se vean del mismo tamaño. Antes de cambiar una foto se guarda la original, así que puedes deshacerlo.</p>

  <?php if ($error !== ''): ?>
    <p class="aviso" role="alert"><?= e($error) ?></p>
  <?php endif; ?>
  <?php if ($ok !== ''): ?>
    <p class="aviso neutro"><?= e($ok) ?></p>
  <?php endif; ?>

  <section class="panel rf-panel">
    <h2 class="titulo-panel">Revisión</h2>
    <?php if ($totalFotos === 0): ?>
      <p class="rf-texto">Todavía no hay fotos subidas. Súbelas desde <a href="inventario.php">Productos</a>.</p>
    <?php else: ?>
      <p class="rf-texto">Hay <?= $totalFotos ?> foto<?= $totalFotos === 1 ? '' : 's' ?> subida<?= $totalFotos === 1 ? '' : 's' ?>. La revisión tarda cerca de un minuto; no cierres la página mientras avanza.</p>
      <div class="rf-acciones">
        <button type="button" class="boton mini" id="rfRevisar" data-csrf="<?= e($token) ?>">Revisar las <?= $totalFotos ?> fotos</button>
        <div class="rf-progreso" id="rfProgreso" hidden>
          <progress id="rfBarra" max="<?= $totalFotos ?>" value="0"></progress>
          <span id="rfEstado" role="status">Revisando…</span>
        </div>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($reporte): ?>
    <section class="panel rf-panel">
      <div class="panel-header">
        <h2 class="titulo-panel">Última revisión · <?= e(substr($idRevision, 6, 2) . '/' . substr($idRevision, 4, 2) . '/' . substr($idRevision, 0, 4) . ' ' . substr($idRevision, 9, 2) . ':' . substr($idRevision, 11, 2)) ?></h2>
        <?php if ($conCambios > 0): ?>
          <form method="post" action="revisar-fotos.php">
            <input type="hidden" name="csrf" value="<?= e($token) ?>">
            <input type="hidden" name="accion" value="deshacer">
            <input type="hidden" name="respaldo" value="<?= e($idRevision) ?>">
            <button type="submit" class="boton fantasma compacto">Deshacer esta revisión</button>
          </form>
        <?php endif; ?>
      </div>

      <ul class="rf-resumen">
        <?php foreach ($grupos as $clave => [$titulo]): ?>
          <li class="rf-cuenta rf-<?= $clave ?>"><?= e($titulo) ?><strong><?= count($porGrupo[$clave]) ?></strong></li>
        <?php endforeach; ?>
      </ul>

      <?php foreach ($grupos as $clave => [$titulo, $explica]): ?>
        <?php if (!$porGrupo[$clave]) { continue; } ?>
        <h3 class="rf-grupo"><?= e($titulo) ?> <span>(<?= count($porGrupo[$clave]) ?>)</span></h3>
        <p class="rf-explica"><?= e($explica) ?></p>
        <ul class="rf-rejilla">
          <?php foreach ($porGrupo[$clave] as $sku): ?>
            <?php
              $ruta = producto_ruta_foto($sku);
              [$nombre, $marca] = $nombres[$sku] ?? [$sku, ''];
            ?>
            <li class="rf-tarjeta rf-<?= $clave ?>">
              <div class="rf-foto">
                <?php if ($ruta): ?>
                  <img src="<?= e($ruta . '?v=' . (int) @filemtime(__DIR__ . '/' . $ruta)) ?>" alt="<?= e($nombre) ?>" loading="lazy" decoding="async">
                <?php endif; ?>
              </div>
              <p class="rf-nombre"><?= e($nombre) ?></p>
              <p class="rf-meta"><?= e(trim($marca . ' · ' . $sku, ' ·')) ?></p>
              <?php if ($clave === 'subir'): ?>
                <a class="rf-enlace" href="inventario.php">Subir otra foto</a>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</main>

<script>
(() => {
  const boton = document.getElementById('rfRevisar');
  if (!boton) return;
  const progreso = document.getElementById('rfProgreso');
  const barra = document.getElementById('rfBarra');
  const estado = document.getElementById('rfEstado');

  boton.addEventListener('click', async () => {
    boton.disabled = true;
    progreso.hidden = false;
    let respaldo = '', desde = 0, total = Number(barra.max) || 1;
    try {
      while (desde < total) {
        const datos = new FormData();
        datos.append('accion', 'lote');
        datos.append('csrf', boton.dataset.csrf);
        datos.append('respaldo', respaldo);
        datos.append('desde', String(desde));
        const r = await fetch('revisar-fotos.php', { method: 'POST', body: datos, credentials: 'same-origin' });
        let j;
        try { j = await r.json(); } catch (e) { throw new Error('Tu sesión se cerró. Vuelve a entrar e intenta de nuevo.'); }
        if (!r.ok || j.error) throw new Error(j.error || 'No se pudo revisar. Intenta de nuevo.');
        respaldo = j.respaldo; desde = j.siguiente; total = j.total;
        barra.max = total;
        barra.value = Math.min(desde, total);
        estado.textContent = 'Revisando… ' + Math.min(desde, total) + ' de ' + total;
      }
      estado.textContent = 'Listo.';
      location.href = 'revisar-fotos.php?ok=revisadas';
    } catch (e) {
      estado.textContent = e.message;
      boton.disabled = false;
    }
  });
})();
</script>
</body>
</html>
