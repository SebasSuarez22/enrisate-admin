<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/facturas.php';

// Página pública: sin login, sin sesión, sin datos internos (costo/margen).

$porMarca = [];
foreach (catalogo() as $p) {
    $porMarca[$p['marca']][] = $p;
}
// Antes: ksort(), que compara bytes y mandaba "kerasolis" al final por ser minúscula.
uksort($porMarca, fn($a, $b) => strcasecmp($a, $b));
foreach ($porMarca as &$ps) {
    usort($ps, fn($a, $b) => $a['nombre'] <=> $b['nombre']);
}
unset($ps);

$totalProductos = count(catalogo());

function cp_slug(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

// Cache-busting: si el archivo cambia (misma foto, contenido nuevo), el navegador
// vuelve a descargarla en lugar de mostrar la versión vieja que tenía cacheada.
function cp_img_v(string $rutaRelativa): string {
    $rutaAbs = __DIR__ . '/' . $rutaRelativa;
    $mtime = @filemtime($rutaAbs);
    return $rutaRelativa . ($mtime ? '?v=' . $mtime : '');
}

// width/height reales del archivo: se los damos al navegador para que reserve
// el espacio antes de que la imagen llegue (menos salto de layout), sin tener
// que llevar esa cuenta a mano cada vez que se reemplaza una foto.
function cp_img_attrs(string $rutaRelativa): string {
    $rutaAbs = __DIR__ . '/' . $rutaRelativa;
    $dim = @getimagesize($rutaAbs);
    return $dim ? sprintf('width="%d" height="%d"', $dim[0], $dim[1]) : '';
}

// Nombres de marca mal escritos en la base. Arréglalos en MySQL cuando puedas
// y esta tabla se queda vacía.
$nombreVisible = [
    'kerasolis' => 'Kerasolis',
];

// Logo por marca. Tongolé es una línea de La Poción, usa el mismo logo.
// Las marcas que no están aquí todavía no tienen logo conseguido.
// menta-hair, origen-botanico y femme: reemplazados 2026-08-29 por versiones
// .webp del tamaño real en que se muestran (los .png originales medían hasta
// 4500x970 para un logo que se ve a menos de 90px de alto). Los .png viejos
// quedan de respaldo en assets/img/marcas/_respaldo_original/.
$logosPorMarca = [
    'Etniker'          => 'etniker.png',
    'Femme'            => 'femme.webp',
    'La Poción'        => 'la-pocion.svg',
    'Tongole'          => 'la-pocion.svg',
    'Lisso Keratin'    => 'lisso-keratin.svg',
    'Menta Hair'       => 'menta-hair.webp',
    'Origen Botánico'  => 'origen-botanico.webp',
    'Skala'            => 'skala.png',
];

/* ---------------------------------------------------------------------
   La categoría de cada producto (shampoo, acondicionador, crema...) vive
   en la columna `categoria` de la tabla productos y se edita en Productos.
   Sirve para dos cosas aquí:
     1. Darle a cada tarjeta sin foto un ícono y un color propios, en vez
        de repetir el mismo bloque 17 veces dentro de una misma marca.
     2. Alimentar el buscador: escribir "shampoo" encuentra los de todas
        las marcas a la vez.

   Si un producto todavía no tiene categoría asignada, categoria_estilo()
   la deduce del nombre; y si tampoco se puede deducir, usa el nombre de
   la marca, que dice más que la palabra "Producto".

   La lista de categorías, sus colores y sus íconos están en
   includes/categorias.php.
--------------------------------------------------------------------- */

// La foto de cada producto vive en assets/img/productos/<SKU>.webp (o
// .jpg/.png si no se subió por acá): producto_ruta_foto(), en
// includes/imagenes.php, la resuelve por SKU. Se sube desde Productos
// y aparece aquí de inmediato, sin tocar código.
//
// Las ~16 fotos de referencia que hubo antes (no eran fotografía propia
// del producto) se archivaron en assets/img/productos/_referencia_no_usar/
// el 2026-09-05, para que no reaparecieran solas al activar esto.
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Catálogo · <?= e(APP_NOMBRE) ?> · <?= e(APP_CIUDAD) ?></title>

<!-- El enlace se comparte por WhatsApp: sin esto llega como una URL pelada,
     sin logo ni título. -->
<meta name="description" content="<?= $totalProductos ?> productos de cuidado capilar en <?= e(APP_CIUDAD) ?>. Arma tu pedido y envíalo por WhatsApp.">
<meta property="og:type" content="website">
<meta property="og:title" content="Catálogo · <?= e(APP_NOMBRE) ?>">
<meta property="og:description" content="<?= $totalProductos ?> productos de cuidado capilar. Arma tu pedido y envíalo por WhatsApp.">
<meta property="og:image" content="assets/img/logo-enrisate.png">
<meta name="theme-color" content="#6B4013">

<link rel="icon" href="assets/img/logo.svg" type="image/svg+xml">
<!-- Fuentes propias (assets/fonts): se precargan para que el texto salga ya con su
     letra, sin esperar a que el CSS las descubra. -->
<link rel="preload" href="assets/fonts/manrope-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="assets/fonts/fraunces-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/css/catalogo-publico.css?v=<?= filemtime(__DIR__ . '/assets/css/catalogo-publico.css') ?>">
</head>
<body>

<div class="cp-franja">Cuidado capilar en <?= e(APP_CIUDAD) ?> · Pide por WhatsApp</div>

<header class="cp-encabezado">
  <?php
    // Logo chico en WebP (2026-08-29) solo para este <img>: es lo primero que
    // pinta la página en cada visita. El .png grande original se queda intacto
    // como og:image, porque WhatsApp arma la vista previa del enlace con él.
    $logoHeader = 'assets/img/logo-enrisate-header.webp';
  ?>
  <p class="cp-marca"><img src="<?= e(cp_img_v($logoHeader)) ?>" alt="<?= e(APP_NOMBRE) ?>" class="cp-logo" <?= cp_img_attrs($logoHeader) ?> fetchpriority="high"></p>
  <h1 class="cp-tagline"><?= e(APP_TAGLINE) ?></h1>
  <p class="cp-instrucciones">Elige lo que quieras. Al final envías todo el pedido con un solo botón.</p>
</header>

<div class="cp-barra">
  <div class="cp-barra-interior">
    <label class="cp-buscador" id="cpBuscador">
      <span class="cp-visualmente-oculto">Buscar producto o marca</span>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
      <input type="search" id="cpQ" placeholder="Buscar shampoo, gel, Skala…" autocomplete="off">
      <button type="button" class="cp-buscador-limpiar" id="cpLimpiar" aria-label="Borrar la búsqueda">×</button>
    </label>

    <nav class="cp-chips" id="chips" aria-label="Marcas">
      <?php foreach (array_keys($porMarca) as $i => $marca): ?>
        <a class="cp-chip<?= $i === 0 ? ' activo' : '' ?>" href="#marca-<?= cp_slug($marca) ?>" data-slug="marca-<?= cp_slug($marca) ?>"><?= e($nombreVisible[$marca] ?? $marca) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>
</div>

<main class="cp-lienzo">
  <p class="cp-conteo-total" id="cpConteo"><?= $totalProductos ?> productos</p>

  <?php
    // Las primeras tarjetas se ven sin hacer scroll (la marca que sale primero
    // en orden alfabético). Esas se cargan de inmediato y con prioridad alta;
    // el resto sigue con loading="lazy" como hasta ahora.
    $cpIndiceGlobal = 0;
    $cpPrimerasVisibles = 4;
  ?>
  <?php foreach ($porMarca as $marca => $ps): ?>
    <?php $marcaVisible = $nombreVisible[$marca] ?? $marca; ?>
    <section class="cp-marca-grupo" id="marca-<?= cp_slug($marca) ?>">
      <h2 class="cp-marca-titulo">
        <?= e($marcaVisible) ?>
        <span class="cp-marca-conteo"><?= count($ps) ?> <?= count($ps) === 1 ? 'producto' : 'productos' ?></span>
      </h2>
      <div class="cp-rejilla">
        <?php foreach ($ps as $p): ?>
          <?php
            $rutaFoto  = producto_ruta_foto($p['sku']);
            $tieneFoto = $rutaFoto !== null;
            $tieneLogo = isset($logosPorMarca[$marca]);
            [$tipoEtq, $tipoFondo, $tipoTinta, $tipoIcono] = categoria_estilo($p, $marcaVisible);
            $busca = mb_strtolower($p['nombre'] . ' ' . $marcaVisible . ' ' . $tipoEtq, 'UTF-8');
            $cpIndiceGlobal++;
            $cpPrioritaria = $cpIndiceGlobal <= $cpPrimerasVisibles;
            $cpCarga = $cpPrioritaria ? 'eager' : 'lazy';
            $cpPrioridadAttr = $cpPrioritaria ? 'fetchpriority="high"' : '';
          ?>
          <article class="cp-tarjeta"
                   data-sku="<?= e($p['sku']) ?>"
                   data-nombre="<?= e($p['nombre']) ?>"
                   data-precio="<?= (int) $p['precio'] ?>"
                   data-descripcion="<?= e($p['descripcion'] ?? '') ?>"
                   data-busca="<?= e($busca) ?>">
            <div class="cp-img-wrap <?= $tieneFoto ? 'cp-img-foto' : '' ?>" style="--tipo-fondo: <?= $tipoFondo ?>; --tipo-tinta: <?= $tipoTinta ?>">
              <?php if ($tieneFoto): ?>
                <img class="cp-foto" src="<?= e(cp_img_v($rutaFoto)) ?>" alt="<?= e($p['nombre']) ?> · <?= e($marcaVisible) ?>" <?= cp_img_attrs($rutaFoto) ?> loading="<?= $cpCarga ?>" <?= $cpPrioridadAttr ?> decoding="async">
                <?php if ($tieneLogo): ?>
                  <?php $cpRutaLogo = 'assets/img/marcas/' . $logosPorMarca[$marca]; ?>
                  <span class="cp-marca-logo"><img src="<?= e(cp_img_v($cpRutaLogo)) ?>" alt="<?= e($marcaVisible) ?>" <?= cp_img_attrs($cpRutaLogo) ?> loading="lazy"></span>
                <?php endif; ?>
              <?php else: ?>
                <?php if ($tieneLogo): ?>
                  <?php $cpRutaLogoFondo = 'assets/img/marcas/' . $logosPorMarca[$marca]; ?>
                  <img class="cp-logo-fondo" src="<?= e(cp_img_v($cpRutaLogoFondo)) ?>" alt="" <?= cp_img_attrs($cpRutaLogoFondo) ?> loading="<?= $cpCarga ?>" <?= $cpPrioridadAttr ?> decoding="async">
                <?php else: ?>
                  <?= categoria_icono($tipoIcono) ?>
                <?php endif; ?>
                <span class="cp-tipo-nombre"><?= e($tipoEtq) ?></span>
                <span class="cp-proximamente">Sin foto aún</span>
              <?php endif; ?>
            </div>
            <div class="cp-info">
              <p class="cp-nombre"><?= e($p['nombre']) ?></p>
              <p class="cp-precio"><?= pesos($p['precio']) ?></p>
              <div class="cp-fila-agregar">
                <button type="button" class="cp-boton-agregar" aria-label="Agregar <?= e($p['nombre']) ?> al pedido">
                  <svg class="cp-icono-mas" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                  <svg class="cp-icono-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12.5l4.5 4.5L20 6"/></svg>
                  <span class="cp-agregar-texto">Agregar</span>
                </button>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>

  <p class="cp-vacio" id="cpVacio" role="status">
    <strong>Nada con ese nombre</strong>
    Prueba con la marca (Skala, Femme) o el tipo (shampoo, gel, aceite).
  </p>
</main>

<footer class="cp-pie">
  <?php
    // El número se guarda pegado y con indicativo (APP_WHATSAPP) porque así
    // lo necesita wa.me; acá se separa solo para mostrarlo legible, sin
    // tener que repetirlo a mano en dos formatos.
    $waCrudo   = APP_WHATSAPP;
    $waVisible = '+' . substr($waCrudo, 0, 2) . ' ' . substr($waCrudo, 2, 3)
               . ' ' . substr($waCrudo, 5, 3) . ' ' . substr($waCrudo, 8);
  ?>
  <img class="cp-pie-logo" src="<?= e(cp_img_v('assets/img/logo-enrisate-pie.webp')) ?>" alt="" <?= cp_img_attrs('assets/img/logo-enrisate-pie.webp') ?> loading="lazy" decoding="async">
  <p class="cp-pie-titulo">¿Dudas o pedidos? Escríbenos</p>

  <nav class="cp-pie-redes" aria-label="Contacto y redes">
    <a class="cp-red cp-red-whatsapp" href="https://wa.me/<?= e($waCrudo) ?>" target="_blank" rel="noopener">
      <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm0 18.15h-.01a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.2 8.2 0 0 1-1.26-4.38c0-4.54 3.7-8.24 8.25-8.24a8.2 8.2 0 0 1 5.83 2.42 8.19 8.19 0 0 1 2.41 5.83c0 4.54-3.7 8.23-8.24 8.23Zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.24-.64.8-.78.97-.15.16-.29.18-.53.06-.25-.13-1.05-.39-1.99-1.23-.74-.66-1.24-1.47-1.38-1.72-.15-.25-.02-.38.11-.5.11-.11.25-.29.37-.44.13-.15.17-.25.25-.42.09-.16.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.43h-.47c-.16 0-.43.06-.65.31-.22.24-.86.84-.86 2.05s.88 2.38 1 2.54c.13.17 1.73 2.64 4.2 3.7.59.26 1.04.41 1.4.52.59.19 1.12.16 1.55.1.47-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.15-1.18-.06-.11-.22-.17-.47-.29Z"/></svg>
      <span class="cp-red-texto">
        <span class="cp-red-nombre">WhatsApp</span>
        <span class="cp-red-dato"><?= e($waVisible) ?></span>
      </span>
    </a>

    <a class="cp-red cp-red-instagram" href="https://www.instagram.com/<?= e(APP_INSTAGRAM) ?>" target="_blank" rel="noopener">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2.8" y="2.8" width="18.4" height="18.4" rx="5.2"/><circle cx="12" cy="12" r="4.1"/><circle cx="17.4" cy="6.6" r="1.15" fill="currentColor" stroke="none"/></svg>
      <span class="cp-red-texto">
        <span class="cp-red-nombre">Instagram</span>
        <span class="cp-red-dato">@<?= e(APP_INSTAGRAM) ?></span>
      </span>
    </a>
  </nav>

  <p class="cp-pie-nota">Precios sujetos a disponibilidad · <?= e(APP_NOMBRE) ?> · <?= e(APP_CIUDAD) ?></p>
</footer>

<!-- Botón flotante del carrito: cantidad + total + etiqueta -->
<button type="button" class="cp-fab-carrito" id="fabCarrito" hidden aria-haspopup="dialog" aria-expanded="false" aria-controls="hojaCarrito">
  <span class="cp-fab-insignia" id="fabInsignia">0</span>
  <span class="cp-fab-total" id="fabTotal">$0</span>
  <span>Ver pedido</span>
</button>

<!-- Fondo oscurecido detrás de la hoja de carrito -->
<div class="cp-carrito-fondo" id="carritoFondo" hidden></div>

<!-- Hoja de carrito: productos, plan de pago y datos de contacto en una sola vista -->
<div class="cp-hoja-carrito" id="hojaCarrito" role="dialog" aria-modal="true" aria-labelledby="hojaCarritoTitulo" hidden>
  <div class="cp-hoja-agarradera" aria-hidden="true"></div>

  <div class="cp-hoja-cabecera">
    <h2 class="cp-hoja-titulo" id="hojaCarritoTitulo">Tu pedido</h2>
    <div class="cp-hoja-acciones">
      <button type="button" class="cp-hoja-vaciar" id="vaciarPedido">Vaciar</button>
      <button type="button" class="cp-hoja-cerrar" id="cerrarCarrito" aria-label="Cerrar el pedido">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
      </button>
    </div>
  </div>

  <div class="cp-hoja-cuerpo">
    <div class="cp-lista-pedido" id="listaPedido"></div>

    <div class="cp-hoja-total">
      <span>Total</span>
      <strong id="hojaTotal">$0</strong>
    </div>

    <div class="cp-form-campo" id="cpBloquePlan" hidden>
      <p class="cp-form-etiqueta">¿Cómo prefieres pagar?</p>
      <div class="cp-form-opciones">
        <label class="cp-form-opcion">
          <input type="radio" name="cpPlan" value="contado" checked>
          <span>De contado</span>
        </label>
        <label class="cp-form-opcion">
          <input type="radio" name="cpPlan" value="cuotas">
          <span>A cuotas</span>
        </label>
      </div>
      <div class="cp-form-campo" id="cpBloqueNumCuotas" hidden>
        <label for="cpNumCuotas">Número de cuotas</label>
        <select id="cpNumCuotas">
          <option value="2" selected>2 cuotas</option>
          <option value="3">3 cuotas</option>
          <option value="4">4 cuotas</option>
        </select>
        <p class="cp-form-pista">Gina te confirma los montos y las fechas exactas por WhatsApp.</p>
      </div>
    </div>

    <p class="cp-form-titulo cp-hoja-subtitulo">Para mandar tu pedido, ¿cómo te contactamos?</p>

    <div class="cp-form-campo">
      <label for="cpNombre">Tu nombre</label>
      <input type="text" id="cpNombre" placeholder="Nombre y apellido" autocomplete="name" maxlength="120">
    </div>
    <div class="cp-form-campo">
      <label for="cpTelefono">Tu WhatsApp</label>
      <input type="tel" id="cpTelefono" placeholder="3001234567" inputmode="numeric" autocomplete="tel" maxlength="15">
    </div>

    <!-- Campo trampa: invisible para personas, solo lo llena un bot. -->
    <div class="cp-form-trampa" aria-hidden="true">
      <label for="cpSitioWeb">Sitio web</label>
      <input type="text" id="cpSitioWeb" tabindex="-1" autocomplete="off">
    </div>

    <p class="cp-form-error" id="cpFormError" hidden></p>
  </div>

  <div class="cp-hoja-pie">
    <button type="button" class="cp-form-enviar" id="cpConfirmarPedido">
      <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm5.8 14.06c-.25.69-1.44 1.32-1.99 1.37-.53.05-1.02.24-3.45-.72-2.9-1.14-4.75-4.1-4.9-4.29-.14-.19-1.17-1.55-1.17-2.96s.74-2.1 1-2.39c.26-.29.57-.36.76-.36l.54.01c.17.01.41-.07.64.49.25.6.84 2.07.91 2.22.07.15.12.32.02.51-.1.19-.15.31-.29.48l-.44.51c-.14.14-.29.3-.13.59.17.29.74 1.22 1.59 1.98 1.09.97 2.01 1.27 2.3 1.42.29.14.46.12.63-.07.17-.19.73-.85.92-1.14.19-.29.39-.24.65-.14.26.09 1.66.78 1.95.93.29.14.48.22.55.34.07.12.07.7-.18 1.39Z"/></svg>
      <span id="cpEnviarTexto">Confirmar y enviar por WhatsApp</span>
    </button>
  </div>
</div>

<!-- Fondo oscurecido detrás de la ficha de producto -->
<div class="cp-carrito-fondo" id="productoFondo" hidden></div>

<!-- Ficha de producto: foto, nombre y descripción al tocar una tarjeta
     (la descripción es la misma que se edita en Productos). -->
<div class="cp-hoja-carrito cp-hoja-producto" id="hojaProducto" role="dialog" aria-modal="true" aria-labelledby="hojaProductoTitulo" hidden>
  <div class="cp-hoja-agarradera" aria-hidden="true"></div>

  <div class="cp-hoja-cabecera">
    <div class="cp-hoja-logo">
      <img src="<?= e(cp_img_v('assets/img/logo-enrisate-marca.webp')) ?>" alt="" <?= cp_img_attrs('assets/img/logo-enrisate-marca.webp') ?> loading="lazy">
    </div>
    <h2 class="cp-hoja-titulo" id="hojaProductoTitulo">&nbsp;</h2>
    <div class="cp-hoja-acciones">
      <button type="button" class="cp-hoja-cerrar" id="cerrarProducto" aria-label="Cerrar">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
      </button>
    </div>
  </div>

  <div class="cp-hoja-cuerpo cp-producto-cuerpo">
    <div class="cp-producto-foto" id="productoFoto"></div>
    <div class="cp-producto-texto">
      <p class="cp-producto-etiqueta">Descripción</p>
      <p class="cp-producto-descripcion" id="productoDescripcion"></p>
    </div>
  </div>

  <div class="cp-hoja-pie">
    <button type="button" class="cp-boton-agregar" id="productoAgregarBtn" aria-label="Agregar al pedido">
      <svg class="cp-icono-mas" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
      <svg class="cp-icono-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12.5l4.5 4.5L20 6"/></svg>
      <span class="cp-agregar-texto">Agregar</span>
    </button>
  </div>
</div>

<script>
/* Los datos que cambian de una instalación a otra viven aquí, en la página; todo el
   código está en assets/js/catalogo-publico.js (un archivo aparte que el navegador
   guarda: antes viajaba dentro del HTML en cada visita). */
const WHATSAPP = '<?= e(APP_WHATSAPP) ?>';
const NOMBRE_NEGOCIO = '<?= e(APP_NOMBRE) ?>';
const UMBRAL_CUOTAS = <?= (int) UMBRAL_CUOTAS_PUBLICO ?>;
const TOTAL_PRODUCTOS = <?= $totalProductos ?>;
</script>
<script src="assets/js/catalogo-publico.js?v=<?= filemtime(__DIR__ . '/assets/js/catalogo-publico.js') ?>" defer></script>

</body>
</html>
