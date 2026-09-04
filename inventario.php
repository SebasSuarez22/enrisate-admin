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
        $nombreOk = actualizar_producto(
            $sku,
            (string) ($_POST['marca'] ?? ''),
            (string) ($_POST['nombre'] ?? '')
        );
        $precioOk = actualizar_precio_costo(
            $sku,
            (int) ($_POST['precio'] ?? 0),
            (int) ($_POST['costo'] ?? 0)
        );
        if ($nombreOk && $precioOk) {
            header('Location: inventario.php?ok=editado');
            exit;
        }
        $error = 'Escribe el nombre y verifica que el producto exista.';
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

// Productos que todavía no tienen categoría asignada ni se puede deducir
// del nombre: son los que hay que clasificar a mano.
$sinCategoria = array_values(array_filter(catalogo(), fn($p) => categoria_de($p) === ''));

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

  <?php if ($hayColumnaCategoria && $sinCategoria): ?>
    <p class="aviso neutro">
      <?= count($sinCategoria) ?> producto<?= count($sinCategoria) === 1 ? '' : 's' ?> sin categoría
      (no se pudo deducir del nombre). El catálogo público les muestra el nombre de la marca
      en vez del tipo de producto hasta que se les asigne una desde la base de datos.
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
      <div class="campo">
        <label for="nPrecio">Precio</label>
        <input type="number" id="nPrecio" name="precio" min="0" step="1000" value="0" required>
      </div>
      <div class="campo">
        <label for="nCosto">Costo</label>
        <input type="number" id="nCosto" name="costo" min="0" step="1000" value="0" required>
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
          <input type="search" id="buscarProductos" placeholder="Buscar por nombre, SKU o categoría…" autocomplete="off">
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
      <label class="seleccionar-todo"><input type="checkbox" id="chkTodosProductos"> Seleccionar todos</label>
      <button type="button" class="boton mini" id="btnEditarProducto" disabled>Editar</button>
      <button type="submit" class="boton mini peligro">Eliminar seleccionados</button>
    </form>
  <?php endif; ?>

  <?php foreach ($porMarca as $marca => $ps): ?>
    <section class="panel panel-inventario" data-marca="<?= e($marca) ?>">
      <h2 class="titulo-panel"><?= e($marca) ?></h2>
      <div class="tabla-scroll">
      <table class="lineas">
        <thead>
          <tr>
            <th></th>
            <th>Producto</th>
            <th class="num">Precio</th>
            <th class="num">Costo</th>
            <th class="num">Margen</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ps as $p):
            $margen = $p['precio'] - $p['costo'];
            $pct    = $p['precio'] > 0 ? round($margen / $p['precio'] * 100) : 0;
          ?>
            <tr>
              <td><input type="checkbox" class="chk-producto" value="<?= e($p['sku']) ?>" aria-label="Seleccionar <?= e($p['nombre']) ?>"></td>
              <td>
                <?= e($p['nombre']) ?>
                <span class="sku"><?= e($p['sku']) ?></span>
              </td>
              <td class="num"><?= pesos($p['precio']) ?></td>
              <td class="num"><?= pesos($p['costo']) ?></td>
              <td class="num"><?= pesos($margen) ?> <span class="sku"><?= $pct ?>%</span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
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
            <input type="number" id="modalEditarPrecio" name="precio" min="0" step="1000" required>
          </div>
          <div class="campo">
            <label for="modalEditarCosto">Costo</label>
            <input type="number" id="modalEditarCosto" name="costo" min="0" step="1000" required>
          </div>
        </div>

        <div class="modal-acciones">
          <button type="button" class="boton mini fantasma" id="btnCancelarEditar">Cancelar</button>
          <button type="submit" class="boton mini">Guardar</button>
        </div>
      </form>
    </div>
  </div>

</main>

<script>
const PRODUCTOS_POR_SKU = <?= json_encode(array_column(catalogo(), null, 'sku'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>

<script>
/* Seleccionar todos, confirmar antes de borrar y habilitar "Editar"
   solo con uno marcado: todo eso vive en assets/js/seleccion-lote.js,
   compartido con Clientes. Acá solo va lo propio de esta página: el
   modal de edición. */
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

seleccionEnLote({
  item:         '.chk-producto',
  chkTodos:     '#chkTodosProductos',
  formEliminar: '#form-eliminar-productos',
  campoValores: '#skusEliminarProductos',
  btnEditar:    '#btnEditarProducto',
  alEditar:     abrirModalEditar,
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
    let visibles = 0;

    secciones.forEach(sec => {
      const marca = sec.dataset.marca || '';
      const seccionCoincideMarca = marcaActiva === '' || marca === marcaActiva;
      let filasVisibles = 0;

      sec.querySelectorAll('tbody tr').forEach(fila => {
        const coincide = q === '' || fila.textContent.toLowerCase().includes(q);
        fila.style.display = coincide ? '' : 'none';
        if (coincide) filasVisibles++;
      });

      const mostrarSeccion = seccionCoincideMarca && filasVisibles > 0;
      sec.hidden = !mostrarSeccion;
      if (mostrarSeccion) visibles += filasVisibles;
    });

    if (contador) {
      const plural = visibles === 1 ? 'producto' : 'productos';
      const donde  = marcaActiva ? ' en ' + marcaActiva : ' en todas las secciones';
      contador.textContent = visibles + ' ' + plural + donde;
    }
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
