<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/facturas.php';

requerir_sesion();

$error = '';
$ok    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'La página estuvo abierta demasiado tiempo. Recárgala e intenta de nuevo.';
    } elseif (($_POST['accion'] ?? '') === 'eliminar') {
        $skus     = array_filter(array_map('trim', explode(',', (string) ($_POST['skus'] ?? ''))));
        $borrados = eliminar_productos($skus);
        if ($borrados > 0) {
            header('Location: inventario.php?ok=eliminados&n=' . $borrados);
            exit;
        }
        $error = 'Selecciona al menos un producto para eliminar.';
    } elseif (($_POST['accion'] ?? '') === 'editar') {
        $sku = (string) ($_POST['sku'] ?? '');
        // La categoría no se toca desde este formulario (se omite el 4º
        // argumento a propósito, así actualizar_producto la deja como está).
        // El precio y el costo se guardan SOLO si el nombre pasó: al
        // revés quedaba una escritura a medias — el nombre rechazado por
        // vacío pero el precio ya guardado, y la página mostrando error
        // como si no se hubiera cambiado nada.
        if (actualizar_producto(
                $sku,
                (string) ($_POST['marca'] ?? ''),
                (string) ($_POST['nombre'] ?? '')
            )
            && actualizar_precio_costo(
                $sku,
                (int) ($_POST['precio'] ?? 0),
                (int) ($_POST['costo'] ?? 0)
            )
        ) {
            header('Location: inventario.php?ok=editado');
            exit;
        }
        $error = 'Escribe el nombre y verifica que el producto exista.';
    } elseif (($_POST['accion'] ?? '') === 'descripcion') {
        $sku = (string) ($_POST['sku'] ?? '');
        if (actualizar_descripcion($sku, (string) ($_POST['descripcion'] ?? ''))) {
            header('Location: inventario.php?ok=descripcion');
            exit;
        }
        $error = 'No encontré ese producto en el catálogo.';
    } elseif (($_POST['accion'] ?? '') === 'imagen') {
        $sku = (string) ($_POST['sku'] ?? '');
        if (!producto_por_sku($sku)) {
            $error = 'No encontré ese producto en el catálogo.';
        } else {
            $mensaje = guardar_imagen_producto($sku, $_FILES['imagen'] ?? []);
            if ($mensaje === '') {
                header('Location: inventario.php?ok=imagen');
                exit;
            }
            $error = $mensaje;
        }
    } elseif (($_POST['accion'] ?? '') === 'quitar_imagen') {
        $sku = (string) ($_POST['sku'] ?? '');
        quitar_imagen_producto($sku);
        header('Location: inventario.php?ok=imagen_quitada');
        exit;
    } else {
        $mensaje = agregar_producto(
            (string) ($_POST['sku'] ?? ''),
            (string) ($_POST['marca'] ?? ''),
            (string) ($_POST['nombre'] ?? ''),
            (int) ($_POST['precio'] ?? 0),
            (int) ($_POST['costo'] ?? 0),
            (string) ($_POST['categoria'] ?? '')
        );
        if ($mensaje === '') {
            header('Location: inventario.php?ok=agregado');
            exit;
        }
        $error = $mensaje;
    }
}

if (($_GET['ok'] ?? '') === 'agregado') {
    $ok = 'Producto agregado al catálogo.';
} elseif (($_GET['ok'] ?? '') === 'editado') {
    $ok = 'Producto actualizado.';
} elseif (($_GET['ok'] ?? '') === 'eliminados') {
    $n  = (int) ($_GET['n'] ?? 0);
    $ok = 'Se eliminó ' . $n . ' producto' . ($n === 1 ? '' : 's') . ' del catálogo.';
} elseif (($_GET['ok'] ?? '') === 'descripcion') {
    $ok = 'Descripción actualizada.';
} elseif (($_GET['ok'] ?? '') === 'imagen') {
    $ok = 'Foto actualizada. Ya se ve en el catálogo público.';
} elseif (($_GET['ok'] ?? '') === 'imagen_quitada') {
    $ok = 'Foto eliminada.';
}

$porMarca = [];
foreach (catalogo() as $p) {
    $porMarca[$p['marca']][] = $p;
}
ksort($porMarca);
foreach ($porMarca as &$ps) {
    usort($ps, fn($a, $b) => sku_orden($a['sku']) <=> sku_orden($b['sku']));
}
unset($ps);

// Si todavía no se importó storage/actualizar-2026-08-23-categorias.sql,
// la columna no existe y la categoría no se puede guardar: se sigue
// deduciendo del nombre. Avisarlo aquí evita que parezca que el
// desplegable "no funciona".
$hayColumnaCategoria = productos_tiene_categoria();

// Igual mecanismo que $hayColumnaCategoria: mientras no se importe
// storage/actualizar-2026-09-05-descripcion.sql, la columna no existe y
// lo que se escriba en el botón de descripción no se guarda.
$hayColumnaDescripcion = productos_tiene_descripcion();

$token = csrf_token();

$tituloPagina = 'Productos';
$barraVolver  = true;
require __DIR__ . '/includes/cabecera.php';
?>

<main class="lienzo ancho">
  <h1 class="saludo">Productos</h1>
  <p class="subsaludo">Todos los productos del catálogo. Agrega los nuevos aquí; para editar uno existente, márcalo abajo y usa "Editar".</p>

  <?php if (!$hayColumnaCategoria): ?>
    <p class="aviso" role="alert">
      Falta importar <code>storage/actualizar-2026-08-23-categorias.sql</code> desde phpMyAdmin.
      Mientras tanto la categoría se deduce del nombre.
    </p>
  <?php endif; ?>

  <?php if (!$hayColumnaDescripcion): ?>
    <p class="aviso" role="alert">
      Falta importar <code>storage/actualizar-2026-09-05-descripcion.sql</code> desde phpMyAdmin.
      Mientras tanto, lo que escribas en el botón de descripción no se guarda.
    </p>
  <?php endif; ?>

  <?php if ($error !== ''): ?>
    <p class="aviso" role="alert"><?= e($error) ?></p>
  <?php endif; ?>
  <?php if ($ok !== ''): ?>
    <p class="aviso neutro"><?= e($ok) ?></p>
  <?php endif; ?>

  <section class="panel">
    <h2 class="titulo-panel">Agregar producto nuevo</h2>
    <form method="post" action="inventario.php" class="rejilla-campos">
      <input type="hidden" name="csrf" value="<?= e($token) ?>">
      <input type="hidden" name="accion" value="agregar">

      <div class="campo">
        <label for="nSku">SKU</label>
        <input type="text" id="nSku" name="sku" placeholder="ACC-09" required>
      </div>
      <div class="campo">
        <label for="nMarca">Marca</label>
        <input type="text" id="nMarca" name="marca" placeholder="Milagros" required>
      </div>
      <div class="campo">
        <label for="nNombre">Nombre</label>
        <input type="text" id="nNombre" name="nombre" placeholder="Shampoo Nuevo" required>
      </div>
      <!-- step="1", no "1000": con el paso en 1000 el navegador rechazaba
           en silencio cualquier valor que no fuera múltiplo de mil, y 13
           productos del catálogo tienen costos como 32.500 o 30.500. El
           formulario simplemente no se enviaba. -->
      <div class="campo">
        <label for="nPrecio">Precio</label>
        <input type="number" id="nPrecio" name="precio" min="0" step="1" value="0" required>
      </div>
      <div class="campo">
        <label for="nCosto">Costo</label>
        <input type="number" id="nCosto" name="costo" min="0" step="1" value="0" required>
      </div>

      <button type="submit" class="boton mini">Agregar producto</button>
    </form>
  </section>

  <?php if ($porMarca): ?>
    <section class="panel panel-buscador-productos">
      <div class="panel-header">
        <h2 class="titulo-panel">Buscar y navegar</h2>
        <span class="contador-productos" id="contadorProductos"><?= count(catalogo()) ?> productos en <?= count($porMarca) ?> secciones</span>
      </div>

      <form class="form-busqueda" onsubmit="return false;">
        <div class="campo apretado">
          <input type="search" id="buscarProductos" placeholder="Buscar por nombre, SKU o marca…" autocomplete="off">
        </div>
        <button type="button" class="boton mini" id="btnBuscarProductos">Buscar</button>
      </form>

      <div class="carrusel-marcas">
        <button type="button" class="boton-icono fantasma carrusel-flecha" id="carruselIzq" aria-label="Ver secciones anteriores">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg>
        </button>
        <div class="carrusel-pista" id="carruselPista" role="tablist" aria-label="Ir a una sección">
          <?php foreach (array_keys($porMarca) as $i => $marca): $ps = $porMarca[$marca]; ?>
            <button type="button" class="chip-marca<?= $i === 0 ? ' activa' : '' ?>" data-marca="<?= e($marca) ?>" role="tab" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>">
              <?= e($marca) ?> <span class="chip-contador"><?= count($ps) ?></span>
            </button>
          <?php endforeach; ?>
        </div>
        <button type="button" class="boton-icono fantasma carrusel-flecha" id="carruselDer" aria-label="Ver más secciones">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg>
        </button>
      </div>
    </section>

    <form method="post" action="inventario.php" id="form-eliminar-productos" class="barra-eliminar">
      <input type="hidden" name="csrf" value="<?= e($token) ?>">
      <input type="hidden" name="accion" value="eliminar">
      <input type="hidden" name="skus" id="skusEliminarProductos" value="">
      <!-- Editar es por fila (el lápiz al final de cada producto). Acá
           solo queda la selección múltiple, que sirve para eliminar. -->
      <label class="seleccionar-todo"><input type="checkbox" id="chkTodosProductos"> Seleccionar todos los visibles</label>
      <button type="submit" class="accion-icono peligro" title="Eliminar los productos marcados">
        <span class="accion-icono-circulo">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
        </span>
        <span class="accion-icono-nombre">Eliminar</span>
      </button>
    </form>
  <?php endif; ?>

  <?php foreach ($porMarca as $marca => $ps): ?>
    <section class="panel panel-inventario" data-marca="<?= e($marca) ?>">
      <h2 class="titulo-panel"><?= e($marca) ?></h2>
      <?php
      // Los 3 botones (editar, descripción, foto) abren cada uno el
      // mismo modal compartido para cualquier producto (abrirModalEditar,
      // abrirModalDescripcion, abrirModalImagen en el <script> de más
      // abajo, todos por sku) — se arman una sola vez para no repetir el
      // HTML entre la fila de tabla y la tarjeta de celular.
      $accionesProducto = function (array $p): string {
          ob_start();
          ?>
          <button type="button" class="boton-icono fantasma btn-editar-fila"
                  data-sku="<?= e($p['sku']) ?>"
                  title="Editar <?= e($p['nombre']) ?>" aria-label="Editar <?= e($p['nombre']) ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L18.5 9.5a2.1 2.1 0 0 0-3-3L5 17v3z"/><path d="M13.5 6.5l4 4"/></svg>
          </button>
          <button type="button" class="boton-icono fantasma btn-descripcion-fila"
                  data-sku="<?= e($p['sku']) ?>"
                  title="Descripción de <?= e($p['nombre']) ?>" aria-label="Descripción de <?= e($p['nombre']) ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3.5h7l4 4V20a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4.5a1 1 0 0 1 1-1z"/><path d="M14 3.5V8h4"/><path d="M8.5 12.5h6M8.5 16h4"/></svg>
          </button>
          <button type="button" class="boton-icono fantasma btn-imagen-fila"
                  data-sku="<?= e($p['sku']) ?>"
                  title="Foto de <?= e($p['nombre']) ?>" aria-label="Foto de <?= e($p['nombre']) ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="6.5" width="18" height="13.5" rx="2"/><path d="M8 6.5l1.3-2.1a1 1 0 0 1 .85-.4h3.7a1 1 0 0 1 .85.4L16 6.5"/><circle cx="12" cy="13.2" r="3.4"/></svg>
          </button>
          <?php
          return ob_get_clean();
      };
      ?>
      <div class="tabla-scroll oculta-en-movil">
      <table class="lineas tabla-productos">
        <thead>
          <tr>
            <th></th>
            <th>Producto</th>
            <th class="num">Precio</th>
            <th class="num">Costo</th>
            <th class="num">Margen</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ps as $p):
            $margen = $p['precio'] - $p['costo'];
            $pct    = $p['precio'] > 0 ? round($margen / $p['precio'] * 100) : 0;
          ?>
            <tr data-sku="<?= e($p['sku']) ?>">
              <td><input type="checkbox" class="chk-producto" value="<?= e($p['sku']) ?>" aria-label="Seleccionar <?= e($p['nombre']) ?>"></td>
              <td>
                <?= e($p['nombre']) ?>
                <span class="sku"><?= e($p['sku']) ?></span>
              </td>
              <td class="num"><?= pesos($p['precio']) ?></td>
              <td class="num"><?= pesos($p['costo']) ?></td>
              <td class="num"><?= pesos($margen) ?> <span class="sku"><?= $pct ?>%</span></td>
              <td class="num"><div class="acciones-producto"><?= $accionesProducto($p) ?></div></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <!-- Solo en celular: cada producto es una tarjeta en vez de una
           fila de tabla angosta con 6 columnas (checkbox, precio,
           costo, margen y 3 acciones) imposibles de leer. -->
      <div class="filas-esencial">
        <?php foreach ($ps as $p):
          $margen = $p['precio'] - $p['costo'];
          $pct    = $p['precio'] > 0 ? round($margen / $p['precio'] * 100) : 0;
        ?>
          <details class="fila-lista" data-sku="<?= e($p['sku']) ?>">
            <summary>
              <input type="checkbox" class="chk-producto" value="<?= e($p['sku']) ?>" aria-label="Seleccionar <?= e($p['nombre']) ?>" onclick="event.stopPropagation()">
              <span class="fila-lista-resumen">
                <span class="fila-lista-titulo"><?= e($p['nombre']) ?></span>
                <span class="fila-lista-pista"><?= pesos($p['precio']) ?> · <?= e($p['sku']) ?></span>
              </span>
              <svg class="fila-lista-flecha" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </summary>
            <div class="fila-lista-detalle">
              <div class="campo-detalle"><strong>Costo</strong><?= pesos($p['costo']) ?></div>
              <div class="campo-detalle"><strong>Margen</strong><?= pesos($margen) ?> (<?= $pct ?>%)</div>
              <div class="acciones-fila"><?= $accionesProducto($p) ?></div>
            </div>
          </details>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>

  <div class="modal-fondo" id="modalEditarFondo" hidden>
    <div class="modal-caja panel" role="dialog" aria-modal="true" aria-labelledby="modalEditarTitulo">
      <h2 class="titulo-panel" id="modalEditarTitulo">Editar producto</h2>
      <p class="modal-sku" id="modalEditarSkuTexto"></p>
      <form method="post" action="inventario.php">
        <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <input type="hidden" name="accion" value="editar">
        <input type="hidden" name="sku" id="modalEditarSku">
        <input type="hidden" name="marca" id="modalEditarMarca">

        <div class="campo">
          <label for="modalEditarNombre">Nombre</label>
          <input type="text" id="modalEditarNombre" name="nombre" required>
        </div>
        <div class="rejilla-campos">
          <div class="campo">
            <label for="modalEditarPrecio">Precio</label>
            <input type="number" id="modalEditarPrecio" name="precio" min="0" step="1" required>
          </div>
          <div class="campo">
            <label for="modalEditarCosto">Costo</label>
            <input type="number" id="modalEditarCosto" name="costo" min="0" step="1" required>
          </div>
        </div>

        <div class="modal-acciones">
          <button type="button" class="boton mini fantasma" id="btnCancelarEditar">Cancelar</button>
          <button type="submit" class="boton mini">Guardar</button>
        </div>
      </form>
    </div>
  </div>

  <div class="modal-fondo" id="modalDescripcionFondo" hidden>
    <div class="modal-caja panel" role="dialog" aria-modal="true" aria-labelledby="modalDescripcionTitulo">
      <h2 class="titulo-panel" id="modalDescripcionTitulo">Descripción del producto</h2>
      <p class="modal-sku" id="modalDescripcionSkuTexto"></p>
      <form method="post" action="inventario.php">
        <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <input type="hidden" name="accion" value="descripcion">
        <input type="hidden" name="sku" id="modalDescripcionSku">

        <div class="campo">
          <label for="modalDescripcionTexto">Descripción</label>
          <textarea id="modalDescripcionTexto" name="descripcion" rows="4" placeholder="Ej: Shampoo reparador para cabello dañado, sin sulfatos, 250ml."></textarea>
        </div>

        <div class="modal-acciones">
          <button type="button" class="boton mini fantasma" id="btnCancelarDescripcion">Cancelar</button>
          <button type="submit" class="boton mini">Guardar</button>
        </div>
      </form>
    </div>
  </div>

  <div class="modal-fondo" id="modalImagenFondo" hidden>
    <div class="modal-caja panel" role="dialog" aria-modal="true" aria-labelledby="modalImagenTitulo">
      <h2 class="titulo-panel" id="modalImagenTitulo">Foto del producto</h2>
      <p class="modal-sku" id="modalImagenSkuTexto"></p>

      <div class="modal-imagen-vista">
        <img id="modalImagenVista" alt="" hidden>
        <span id="modalImagenSinFoto">Sin foto todavía</span>
      </div>

      <form method="post" action="inventario.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <input type="hidden" name="accion" value="imagen">
        <input type="hidden" name="sku" id="modalImagenSku">

        <div class="campo">
          <label id="modalImagenLabel" for="modalImagenArchivo">Elegir foto (JPG, PNG o WebP, máx. 8 MB)</label>
          <div class="campo-archivo">
            <input class="campo-archivo-input" type="file" id="modalImagenArchivo" name="imagen" accept="image/jpeg,image/png,image/webp" aria-labelledby="modalImagenLabel" required>
            <label for="modalImagenArchivo" class="boton mini fantasma">Elegir archivo</label>
            <span class="campo-archivo-nombre" id="modalImagenNombreArchivo">Ningún archivo seleccionado</span>
          </div>
        </div>

        <div class="modal-acciones">
          <button type="button" class="boton mini fantasma" id="btnCancelarImagen">Cancelar</button>
          <button type="submit" class="boton mini">Subir foto</button>
        </div>
      </form>

      <form method="post" action="inventario.php" id="formQuitarImagen" class="modal-quitar-foto" hidden>
        <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <input type="hidden" name="accion" value="quitar_imagen">
        <input type="hidden" name="sku" id="modalQuitarImagenSku">
        <button type="submit" class="boton mini fantasma">Quitar foto actual</button>
      </form>
    </div>
  </div>

</main>

<script>
const PRODUCTOS_POR_SKU = <?= json_encode(array_column(catalogo(), null, 'sku'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

<?php
  // La foto no vive en catalogo() (es un archivo, no una columna de la
  // base de datos), así que va en su propio objeto: SKU -> ruta, o null
  // si el producto no tiene foto todavía.
  $fotosPorSkuJs = [];
  foreach (catalogo() as $p) {
      $fotosPorSkuJs[$p['sku']] = producto_ruta_foto($p['sku']);
  }
?>
const FOTOS_POR_SKU = <?= json_encode($fotosPorSkuJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>

<script>
/* Seleccionar los visibles y confirmar antes de borrar viven en
   assets/js/seleccion-lote.js, compartido con Clientes. Acá va lo
   propio de esta página: el modal de edición y el lápiz de cada fila. */
const modalEditarFondo = document.getElementById('modalEditarFondo');

function abrirModalEditar(sku) {
  const p = PRODUCTOS_POR_SKU[sku];
  if (!p) return;

  document.getElementById('modalEditarSkuTexto').textContent = p.marca + ' · ' + sku;
  document.getElementById('modalEditarSku').value             = sku;
  document.getElementById('modalEditarMarca').value            = p.marca;
  document.getElementById('modalEditarNombre').value           = p.nombre;
  document.getElementById('modalEditarPrecio').value           = p.precio;
  document.getElementById('modalEditarCosto').value            = p.costo;

  modalEditarFondo.hidden = false;
  document.getElementById('modalEditarNombre').focus();
}

function cerrarModalEditar() {
  modalEditarFondo.hidden = true;
}

if (modalEditarFondo) {
  document.getElementById('btnCancelarEditar').addEventListener('click', cerrarModalEditar);
  modalEditarFondo.addEventListener('click', e => {
    if (e.target === modalEditarFondo) cerrarModalEditar();
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && !modalEditarFondo.hidden) cerrarModalEditar();
  });
}

/* Editar es por fila: el lápiz de cada producto abre el modal de ese
   producto, sin marcar nada ni subir hasta la barra de arriba. */
document.querySelectorAll('.btn-editar-fila').forEach(btn => {
  btn.addEventListener('click', () => abrirModalEditar(btn.dataset.sku));
});

/* Mismo patrón que el modal de editar, pero para la descripción de cada
   producto: un botón por fila, un solo modal que se rellena según cuál
   se apretó. */
const modalDescripcionFondo = document.getElementById('modalDescripcionFondo');

function abrirModalDescripcion(sku) {
  const p = PRODUCTOS_POR_SKU[sku];
  if (!p) return;

  document.getElementById('modalDescripcionSkuTexto').textContent = p.marca + ' · ' + sku;
  document.getElementById('modalDescripcionSku').value             = sku;
  document.getElementById('modalDescripcionTexto').value           = p.descripcion || '';

  modalDescripcionFondo.hidden = false;
  document.getElementById('modalDescripcionTexto').focus();
}

function cerrarModalDescripcion() {
  modalDescripcionFondo.hidden = true;
}

if (modalDescripcionFondo) {
  document.getElementById('btnCancelarDescripcion').addEventListener('click', cerrarModalDescripcion);
  modalDescripcionFondo.addEventListener('click', e => {
    if (e.target === modalDescripcionFondo) cerrarModalDescripcion();
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && !modalDescripcionFondo.hidden) cerrarModalDescripcion();
  });
}

document.querySelectorAll('.btn-descripcion-fila').forEach(btn => {
  btn.addEventListener('click', () => abrirModalDescripcion(btn.dataset.sku));
});

/* Foto del producto: mismo patrón de un modal + botón por fila, más la
   vista previa (la foto actual si ya tiene, o la que se acaba de elegir
   antes de subirla) y un botón aparte para quitarla. */
const modalImagenFondo = document.getElementById('modalImagenFondo');

function mostrarVistaImagen(src) {
  const vista   = document.getElementById('modalImagenVista');
  const sinFoto = document.getElementById('modalImagenSinFoto');
  if (src) {
    vista.src = src;
    vista.hidden = false;
    sinFoto.hidden = true;
  } else {
    vista.removeAttribute('src');
    vista.hidden = true;
    sinFoto.hidden = false;
  }
}

function abrirModalImagen(sku) {
  const p = PRODUCTOS_POR_SKU[sku];
  if (!p) return;

  document.getElementById('modalImagenSkuTexto').textContent = p.marca + ' · ' + sku;
  document.getElementById('modalImagenSku').value = sku;
  document.getElementById('modalQuitarImagenSku').value = sku;
  document.getElementById('modalImagenArchivo').value = '';
  document.getElementById('modalImagenNombreArchivo').textContent = 'Ningún archivo seleccionado';

  const foto = FOTOS_POR_SKU[sku];
  mostrarVistaImagen(foto ? (foto + '?v=' + Date.now()) : null);
  document.getElementById('formQuitarImagen').hidden = !foto;

  modalImagenFondo.hidden = false;
}

function cerrarModalImagen() {
  modalImagenFondo.hidden = true;
}

if (modalImagenFondo) {
  document.getElementById('btnCancelarImagen').addEventListener('click', cerrarModalImagen);
  modalImagenFondo.addEventListener('click', e => {
    if (e.target === modalImagenFondo) cerrarModalImagen();
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && !modalImagenFondo.hidden) cerrarModalImagen();
  });
  document.getElementById('formQuitarImagen').addEventListener('submit', e => {
    if (!confirm('¿Quitar la foto de este producto? Deja de verse en el catálogo público hasta que subas otra.')) {
      e.preventDefault();
    }
  });
}

document.querySelectorAll('.btn-imagen-fila').forEach(btn => {
  btn.addEventListener('click', () => abrirModalImagen(btn.dataset.sku));
});

// Vista previa del archivo elegido, antes de subirlo, y su nombre junto
// al botón (el input real queda oculto; el botón visible es un <label>
// que apunta a él).
document.getElementById('modalImagenArchivo')?.addEventListener('change', function () {
  const archivo = this.files[0];
  document.getElementById('modalImagenNombreArchivo').textContent =
    archivo ? archivo.name : 'Ningún archivo seleccionado';
  if (!archivo) return;
  const lector = new FileReader();
  lector.onload = e => mostrarVistaImagen(e.target.result);
  lector.readAsDataURL(archivo);
});

/* La selección múltiple queda solo para eliminar. */
const loteProductos = seleccionEnLote({
  item:         '.chk-producto',
  chkTodos:     '#chkTodosProductos',
  formEliminar: '#form-eliminar-productos',
  campoValores: '#skusEliminarProductos',
  textos: {
    ninguno: 'Selecciona al menos un producto para eliminar.',
    confirmar: n => n === 1
      ? '¿Eliminar el producto seleccionado del catálogo? Esta acción no se puede deshacer.'
      : '¿Eliminar los ' + n + ' productos seleccionados del catálogo? Esta acción no se puede deshacer.',
  },
});

(function () {
  const pista = document.getElementById('carruselPista');
  if (!pista) return;

  const chips     = Array.from(pista.querySelectorAll('.chip-marca'));
  const secciones = Array.from(document.querySelectorAll('.panel-inventario'));
  const input     = document.getElementById('buscarProductos');
  const btnBuscar = document.getElementById('btnBuscarProductos');
  const contador  = document.getElementById('contadorProductos');
  let marcaActiva = chips.length ? (chips[0].dataset.marca || '') : '';

  function aplicarFiltros() {
    const q = (input.value || '').trim().toLowerCase();
    /* Buscando se mira TODO el catálogo, no solo la sección abierta:
       antes, buscar "Rapunzel" estando en Accesorios devolvía "0
       productos" aunque el producto existiera en otra marca. El
       carrusel filtra cuando no hay búsqueda; la búsqueda manda cuando
       la hay. */
    const buscando = q !== '';
    let visibles = 0;

    secciones.forEach(sec => {
      const marca = sec.dataset.marca || '';
      const seccionCoincideMarca = buscando || marcaActiva === '' || marca === marcaActiva;
      let filasVisibles = 0;

      /* Cada producto existe dos veces en el HTML (fila de tabla y
         tarjeta de celular — ver el bloque .filas-esencial de esta
         sección). El texto de la fila de tabla manda para decidir si
         coincide con la búsqueda; la tarjeta comparte el mismo sku y se
         oculta/muestra igual, así el buscador filtra las dos vistas sin
         importar cuál esté visible según el ancho de pantalla. */
      const marcaCoincide = marca.toLowerCase().includes(q);
      sec.querySelectorAll('tbody tr[data-sku]').forEach(fila => {
        const coincide = !buscando || marcaCoincide || fila.textContent.toLowerCase().includes(q);
        fila.style.display = coincide ? '' : 'none';
        const tarjeta = sec.querySelector('.filas-esencial [data-sku="' + CSS.escape(fila.dataset.sku) + '"]');
        if (tarjeta) tarjeta.style.display = coincide ? '' : 'none';
        if (coincide) filasVisibles++;
      });

      const mostrarSeccion = seccionCoincideMarca && filasVisibles > 0;
      sec.hidden = !mostrarSeccion;
      if (mostrarSeccion) visibles += filasVisibles;
    });

    if (contador) {
      const plural = visibles === 1 ? 'producto' : 'productos';
      const donde  = buscando ? ' en todo el catálogo'
                              : (marcaActiva ? ' en ' + marcaActiva : ' en todas las secciones');
      contador.textContent = visibles + ' ' + plural + donde;
    }

    /* Lo que se acaba de ocultar no debe quedar marcado. */
    loteProductos.limpiarOcultas();
  }

  chips.forEach(chip => {
    chip.addEventListener('click', () => {
      marcaActiva = chip.dataset.marca || '';
      chips.forEach(c => {
        const activa = c === chip;
        c.classList.toggle('activa', activa);
        c.setAttribute('aria-selected', activa ? 'true' : 'false');
      });
      aplicarFiltros();
      if (marcaActiva) {
        const objetivo = document.querySelector('.panel-inventario[data-marca="' + CSS.escape(marcaActiva) + '"]');
        if (objetivo) objetivo.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  });

  input.addEventListener('input', aplicarFiltros);
  if (btnBuscar) btnBuscar.addEventListener('click', aplicarFiltros);

  const flechaIzq = document.getElementById('carruselIzq');
  const flechaDer = document.getElementById('carruselDer');
  if (flechaIzq) flechaIzq.addEventListener('click', () => pista.scrollBy({ left: -240, behavior: 'smooth' }));
  if (flechaDer) flechaDer.addEventListener('click', () => pista.scrollBy({ left: 240, behavior: 'smooth' }));

  aplicarFiltros();
})();
</script>

</body>
</html>
