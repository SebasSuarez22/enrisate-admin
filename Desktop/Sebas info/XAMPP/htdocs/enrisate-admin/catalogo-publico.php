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

// Fotos reales por SKU: DESACTIVADAS por ahora (2026-08-20). Las fotos que había eran
// de referencia, no fotografía propia del producto — hasta que esté la sesión de fotos
// real, el catálogo muestra el marcador por tipo de producto.
// Para reactivarlas cuando ya estén listas: cambia $tieneFoto más abajo por
//     $tieneFoto = isset($fotosPorSku[$p['sku']]);
$fotosPorSku = [
    'ACC-04' => 'ACC-04.webp', // Carotone 135 mL
    'ACC-03' => 'ACC-03.webp', // Carotone 300 mL
    'ACC-05' => 'ACC-05.webp', // Jabón carotone
    'ACC-01' => 'ACC-01.webp', // Cepillo definidor
    'ACC-02' => 'ACC-02.webp', // Cepillo aguacate (secador-cepillo Avocado 3D)
    'ACC-06' => 'ACC-06.webp', // Rapunzel tónico capilar (Lola Cosmetics)
    'ACC-07' => 'ACC-07.webp', // Karsell
    'ACC-08' => 'ACC-08.webp', // Sobres de Karsell
    'DES01'  => 'DES01.jpg', // Desodorante piedra de alumbre
    'ET-01'  => 'ET-01.webp', // Shampoo Etniker
    'ET-02'  => 'ET-02.webp', // Espuma intensificadora de rizos
    'ET-03'  => 'ET-03.webp', // Crema de peinar
    'ET-04'  => 'ET-04.webp', // Gel definidor de rizos
    'ET-05'  => 'ET-05.webp', // Aceite
    'FEM-01' => 'FEM-01.webp', // Crema de Peinar 500 ml
    'FEM-05' => 'FEM-05.webp', // Gel Fluido 300 ml
    'FEM-06' => 'FEM-06.webp', // Gel Fluido 500 ml
    'FEM-07' => 'FEM-07.webp', // Gel duro
    'FEM-03' => 'FEM-03.webp', // Shampoo Femme
    'FEM-04' => 'FEM-04.webp', // Óleo Dorado
    'FEM-02' => 'FEM-02.webp', // Tratamiento Femme (Soy Rizos 2en1)
    'POC-01' => 'POC-01.png', // Shampoo
    'POC-02' => 'POC-02.png', // Acondicionador
    'POC-03' => 'POC-03.png', // Crema de peinar
    'POC-04' => 'POC-04.png', // Shampoo Anticaspa
    'POC-05' => 'POC-05.webp', // Ancestral
    'POC-06' => 'POC-06.png', // Tonico (control caspa)
    'POC-07' => 'POC-07.png', // Shampoo Graso
    'POC-08' => 'POC-08.png', // Shampoo kids
    'POC-09' => 'POC-09.png', // Acondicionador kids
    'POC-10' => 'POC-10.png', // Spray desenredante
    'LP-01'  => 'LP-01.webp', // Shampoo Lisos
    'LP-02'  => 'LP-02.webp', // Acondicionador Lisos
    'LP-04'  => 'LP-04.webp', // Shampoo Rizos
    'LP-05'  => 'LP-05.png', // Acondicionador Rizos (Mascarilla Nutritiva)
    'LP-06'  => 'LP-06.png', // Crema de Peinar Rizos (Definidor de Rizos)
    'LP-07'  => 'LP-07.png', // Gelatina Rizos (línea kids)
    'LP-08'  => 'LP-08.png', // Shampoo 2 en 1 Niños
    'CA-01'  => 'CA-01.webp', // Masaje Candela
    'ACC-09' => 'ACC-09.webp', // Anti-Friction (Megababe Thigh Rescue)
    'FEM-08' => 'FEM-08.webp', // Crema de Peinar 1000 g (Femme / Soy Rizos)
];

// De la lista de arriba, cuáles YA se muestran en el catálogo. Las demás
// siguen desactivadas (ver nota arriba) hasta que haya sesión de fotos real;
// esta lista deja prender una por una a medida que estén listas, sin
// reactivar de golpe las 32 fotos de referencia viejas.
$fotosActivas = [
    'LP-01', // Shampoo Lisos — foto real puesta el 2026-08-23
    'LP-04', // Shampoo Rizos — foto real puesta el 2026-08-23
    'ACC-04', // Carotone 135 mL — foto real puesta el 2026-08-23
    'ACC-03', // Carotone 300 mL — foto real puesta el 2026-08-23
    'ACC-02', // Cepillo aguacate — foto real puesta el 2026-08-23
    'ACC-01', // Cepillo definidor — foto real puesta el 2026-08-23
    'ACC-05', // Jabón carotone — foto real puesta el 2026-08-23
    'ACC-06', // Rapunzel — foto real puesta el 2026-08-23
    'ACC-08', // Sobres de Karsell — foto real puesta el 2026-08-23
    'ACC-07', // Karsell — foto real puesta el 2026-08-23
    'CA-01', // Masaje Candela — foto real puesta el 2026-08-23
    'ET-05', // Aceite (Etniker) — foto real puesta el 2026-08-23
    'ET-03', // Crema de peinar (Etniker) — foto real puesta el 2026-08-23
    'ET-02', // Espuma (Etniker) — foto real puesta el 2026-08-24
    'ET-04', // Gel duro (Etniker) — foto real puesta el 2026-08-24
    'ET-01', // Shampoo (Etniker) — foto real puesta el 2026-08-25
    'ACC-09', // Anti-Friction (Megababe Thigh Rescue) — foto real puesta el 2026-08-25
    'FEM-08', // Crema de Peinar 1000 g (Femme) — foto real puesta el 2026-08-28
    'FEM-01', // Crema de Peinar 500 ml (Femme) — foto real puesta el 2026-08-28
    'FEM-06', // Gel Fluido 500 ml (Femme) — foto real puesta el 2026-08-28
    'FEM-05', // Gel Fluido 300 ml (Femme) — foto real puesta el 2026-08-28
    'FEM-07', // Gel duro (Femme) — foto real puesta el 2026-08-28
    'FEM-03', // Shampoo Femme — foto real puesta el 2026-08-28
    'FEM-02', // Tratamiento Femme (Soy Rizos 2en1) — foto real puesta el 2026-08-29
    'FEM-04', // Óleo Dorado — foto real puesta el 2026-08-29
];
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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
      <a class="cp-chip activo" href="#" data-slug="__todas">Todas</a>
      <?php foreach (array_keys($porMarca) as $marca): ?>
        <a class="cp-chip" href="#marca-<?= cp_slug($marca) ?>" data-slug="marca-<?= cp_slug($marca) ?>"><?= e($nombreVisible[$marca] ?? $marca) ?></a>
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
            $tieneFoto = isset($fotosPorSku[$p['sku']]) && in_array($p['sku'], $fotosActivas, true);
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
                   data-busca="<?= e($busca) ?>">
            <div class="cp-img-wrap <?= $tieneFoto ? 'cp-img-foto' : '' ?>" style="--tipo-fondo: <?= $tipoFondo ?>; --tipo-tinta: <?= $tipoTinta ?>">
              <?php if ($tieneFoto): ?>
                <?php $cpRutaFoto = 'assets/img/productos/' . $fotosPorSku[$p['sku']]; ?>
                <img class="cp-foto" src="<?= e(cp_img_v($cpRutaFoto)) ?>" alt="<?= e($p['nombre']) ?> · <?= e($marcaVisible) ?>" <?= cp_img_attrs($cpRutaFoto) ?> loading="<?= $cpCarga ?>" <?= $cpPrioridadAttr ?> decoding="async">
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
  <p>Precios sujetos a disponibilidad · Escríbenos si tienes dudas</p>
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

<script>
const WHATSAPP = '<?= e(APP_WHATSAPP) ?>';
const NOMBRE_NEGOCIO = '<?= e(APP_NOMBRE) ?>';
const UMBRAL_CUOTAS = <?= (int) UMBRAL_CUOTAS_PUBLICO ?>;
const TEXTO_ENVIAR = 'Confirmar y enviar por WhatsApp';
const cop = n => '$' + Math.round(n).toLocaleString('es-CO');

const pedido = [];

const fab         = document.getElementById('fabCarrito');
const fabInsignia = document.getElementById('fabInsignia');
const fabTotal    = document.getElementById('fabTotal');
const fondo       = document.getElementById('carritoFondo');
const hoja        = document.getElementById('hojaCarrito');
const cerrarBtn   = document.getElementById('cerrarCarrito');
const listaPedido = document.getElementById('listaPedido');
const hojaTotal   = document.getElementById('hojaTotal');

const cpNombre          = document.getElementById('cpNombre');
const cpTelefono        = document.getElementById('cpTelefono');
const cpSitioWeb        = document.getElementById('cpSitioWeb');
const cpBloquePlan      = document.getElementById('cpBloquePlan');
const cpBloqueNumCuotas = document.getElementById('cpBloqueNumCuotas');
const cpNumCuotas       = document.getElementById('cpNumCuotas');
const cpFormError       = document.getElementById('cpFormError');
const cpConfirmar       = document.getElementById('cpConfirmarPedido');
const cpEnviarTexto     = document.getElementById('cpEnviarTexto');

let ultimoFoco = null;

function totalPedido() {
  return pedido.reduce((s, i) => s + i.precio * i.cant, 0);
}

function cantidadTotal() {
  return pedido.reduce((s, i) => s + i.cant, 0);
}

function sincronizarBotonAgregar(boton, enPedido) {
  boton.classList.toggle('en-pedido', enPedido);
  const etiqueta = enPedido ? 'Ya está en tu pedido' : 'Agregar al pedido';
  boton.setAttribute('aria-label', etiqueta);
  boton.title = etiqueta;
  const texto = boton.querySelector('.cp-agregar-texto');
  if (texto) texto.textContent = enPedido ? 'En el pedido' : 'Agregar';
}

function sincronizarTarjeta(sku) {
  const tarjeta = document.querySelector('.cp-tarjeta[data-sku="' + CSS.escape(sku) + '"]');
  if (!tarjeta) return;
  const enPedido = pedido.some(i => i.sku === sku);
  tarjeta.classList.toggle('en-pedido', enPedido);
  const boton = tarjeta.querySelector('.cp-boton-agregar');
  if (boton) sincronizarBotonAgregar(boton, enPedido);
}

function quitarDelPedido(sku) {
  const idx = pedido.findIndex(i => i.sku === sku);
  if (idx === -1) return;
  pedido.splice(idx, 1);
  sincronizarTarjeta(sku);
  actualizarCarrito();
}

function cambiarCantidadPedido(sku, delta) {
  const item = pedido.find(i => i.sku === sku);
  if (!item) return;
  item.cant = Math.max(1, Math.min(99, item.cant + delta));
  actualizarCarrito();
}

function renderLista() {
  listaPedido.innerHTML = '';
  pedido.forEach(i => {
    const fila = document.createElement('div');
    fila.className = 'cp-lista-fila';

    const info = document.createElement('div');
    info.className = 'cp-lista-info';
    const nombre = document.createElement('span');
    nombre.className = 'cp-lista-nombre';
    nombre.textContent = i.nombre;
    const precio = document.createElement('span');
    precio.className = 'cp-lista-precio';
    precio.textContent = cop(i.precio * i.cant);
    info.append(nombre, precio);

    const controles = document.createElement('div');
    controles.className = 'cp-lista-controles';

    const stepper = document.createElement('div');
    stepper.className = 'cp-stepper-mini';

    const restar = document.createElement('button');
    restar.type = 'button';
    restar.className = 'cp-paso';
    restar.textContent = '−';
    restar.setAttribute('aria-label', 'Restar uno a ' + i.nombre);
    restar.addEventListener('click', () => cambiarCantidadPedido(i.sku, -1));

    const cant = document.createElement('span');
    cant.className = 'cp-cant-valor';
    cant.textContent = String(i.cant);

    const sumar = document.createElement('button');
    sumar.type = 'button';
    sumar.className = 'cp-paso';
    sumar.textContent = '+';
    sumar.setAttribute('aria-label', 'Sumar uno a ' + i.nombre);
    sumar.addEventListener('click', () => cambiarCantidadPedido(i.sku, 1));

    stepper.append(restar, cant, sumar);

    const quitar = document.createElement('button');
    quitar.type = 'button';
    quitar.className = 'cp-lista-quitar';
    quitar.setAttribute('aria-label', 'Quitar ' + i.nombre + ' del pedido');
    quitar.textContent = '×';
    quitar.addEventListener('click', () => quitarDelPedido(i.sku));

    controles.append(stepper, quitar);
    fila.append(info, controles);
    listaPedido.appendChild(fila);
  });
}

function actualizarInsignia() {
  const cant = cantidadTotal();
  fabInsignia.textContent = cant > 99 ? '99+' : String(cant);
  fabInsignia.classList.remove('cp-fab-insignia-pulso');
  void fabInsignia.offsetWidth; // reinicia la animación de "pop"
  fabInsignia.classList.add('cp-fab-insignia-pulso');
}

function actualizarCarrito() {
  const hayPedido = pedido.length > 0;
  fab.hidden = !hayPedido;

  if (!hayPedido) {
    cerrarCarrito();
    return;
  }

  actualizarInsignia();
  fabTotal.textContent = cop(totalPedido());
  renderLista();
  hojaTotal.textContent = cop(totalPedido());

  // Si la hoja ya está abierta y el carrito cambió, refleja si ahora
  // sí (o ya no) alcanza el mínimo para cuotas.
  if (!hoja.hidden) actualizarBloquePlan();
}

function abrirCarrito() {
  if (!pedido.length) return;
  ultimoFoco = document.activeElement;
  fondo.hidden = false;
  hoja.hidden = false;
  document.body.classList.add('cp-bloqueo-scroll');
  requestAnimationFrame(() => {
    fondo.classList.add('visible');
    hoja.classList.add('abierta');
  });
  fab.setAttribute('aria-expanded', 'true');
  actualizarBloquePlan();
  cpFormError.hidden = true;
  cerrarBtn.focus();
}

function cerrarCarrito() {
  if (hoja.hidden) return;
  fondo.classList.remove('visible');
  hoja.classList.remove('abierta');
  document.body.classList.remove('cp-bloqueo-scroll');
  fab.setAttribute('aria-expanded', 'false');

  let hecho = false;
  const finalizar = () => {
    if (hecho) return;
    hecho = true;
    fondo.hidden = true;
    hoja.hidden = true;
    hoja.removeEventListener('transitionend', finalizar);
  };
  hoja.addEventListener('transitionend', finalizar);
  setTimeout(finalizar, 350);

  if (ultimoFoco && document.contains(ultimoFoco)) ultimoFoco.focus();
}

function actualizarBloquePlan() {
  const alcanza = totalPedido() >= UMBRAL_CUOTAS;
  cpBloquePlan.hidden = !alcanza;
  if (!alcanza) {
    document.querySelector('input[name="cpPlan"][value="contado"]').checked = true;
    cpBloqueNumCuotas.hidden = true;
  }
}

function mensajePedido(numero, plan) {
  let msg = 'Hola! Quiero pedir esto de ' + NOMBRE_NEGOCIO + ':\n\n';
  pedido.forEach(i => {
    msg += '• ' + i.cant + ' x ' + i.nombre + ' — ' + cop(i.precio * i.cant) + '\n';
  });
  msg += '\nTotal aproximado: ' + cop(totalPedido()) + '\n';
  msg += plan === 'cuotas' ? 'Prefiero pagar a cuotas.\n' : 'Prefiero pagar de contado.\n';
  if (numero) msg += '\nPedido ' + numero + ' (este es mi pedido, todavía no he pagado nada)';
  return msg;
}

fab.addEventListener('click', abrirCarrito);
cerrarBtn.addEventListener('click', cerrarCarrito);
fondo.addEventListener('click', cerrarCarrito);
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && !hoja.hidden) cerrarCarrito();
});

document.querySelectorAll('input[name="cpPlan"]').forEach(r =>
  r.addEventListener('change', e => {
    cpBloqueNumCuotas.hidden = e.target.value !== 'cuotas';
  })
);

function mostrarErrorForm(texto) {
  cpFormError.textContent = texto;
  cpFormError.hidden = false;
}

function restaurarBotonEnviar() {
  cpConfirmar.disabled = false;
  cpEnviarTexto.textContent = TEXTO_ENVIAR;
}

cpConfirmar.addEventListener('click', async () => {
  const cliente  = cpNombre.value.trim();
  const telefono = cpTelefono.value.replace(/\D+/g, '');

  if (!cliente) { mostrarErrorForm('Escribe tu nombre.'); cpNombre.focus(); return; }
  if (telefono.length < 7) { mostrarErrorForm('Escribe un WhatsApp válido.'); cpTelefono.focus(); return; }

  const plan = document.querySelector('input[name="cpPlan"]:checked').value;

  cpConfirmar.disabled = true;
  cpEnviarTexto.textContent = 'Enviando...';
  cpFormError.hidden = true;

  try {
    const resp = await fetch('pedido-publico.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        cliente,
        telefono,
        plan,
        num_cuotas: parseInt(cpNumCuotas.value, 10) || 2,
        items: pedido.map(i => ({ sku: i.sku, cant: i.cant })),
        sitio_web: cpSitioWeb.value,
      }),
    });
    const data = await resp.json();

    if (!resp.ok || !data.ok) {
      mostrarErrorForm(data.error || 'No se pudo enviar el pedido. Intenta de nuevo.');
      restaurarBotonEnviar();
      return;
    }

    const msg = mensajePedido(data.numero, data.plan);
    window.open('https://wa.me/' + WHATSAPP + '?text=' + encodeURIComponent(msg), '_blank', 'noopener');

    pedido.length = 0;
    document.querySelectorAll('.cp-tarjeta.en-pedido').forEach(t => {
      t.classList.remove('en-pedido');
      const b = t.querySelector('.cp-boton-agregar');
      if (b) sincronizarBotonAgregar(b, false);
    });
    cpNombre.value = '';
    cpTelefono.value = '';
    restaurarBotonEnviar();
    actualizarCarrito(); // pedido vacío: oculta el botón y cierra la hoja
  } catch (err) {
    mostrarErrorForm('No hay conexión. Revisa tu internet e intenta de nuevo.');
    restaurarBotonEnviar();
  }
});

/* --- Agregar desde la tarjeta: una sola acción, sin selector de cantidad.
       La cantidad se ajusta en el carrito. --- */
document.querySelectorAll('.cp-tarjeta').forEach(tarjeta => {
  const sku    = tarjeta.dataset.sku;
  const nombre = tarjeta.dataset.nombre;
  const precio = +tarjeta.dataset.precio;
  const boton  = tarjeta.querySelector('.cp-boton-agregar');
  if (!boton) return;

  boton.addEventListener('click', () => {
    const ya = pedido.find(i => i.sku === sku);
    if (ya) {
      ya.cant = Math.min(99, ya.cant + 1);
    } else {
      pedido.push({ sku, nombre, precio, cant: 1 });
    }
    sincronizarTarjeta(sku);
    actualizarCarrito();
  });
});

document.getElementById('vaciarPedido').addEventListener('click', () => {
  pedido.length = 0;
  document.querySelectorAll('.cp-tarjeta.en-pedido').forEach(t => {
    t.classList.remove('en-pedido');
    const b = t.querySelector('.cp-boton-agregar');
    if (b) sincronizarBotonAgregar(b, false);
  });
  actualizarCarrito();
});

/* --- Buscador: filtra en el navegador por nombre, marca y tipo --- */
const cpQ        = document.getElementById('cpQ');
const cpEnvoltura = document.getElementById('cpBuscador');
const cpVacio    = document.getElementById('cpVacio');
const cpConteo   = document.getElementById('cpConteo');
const gruposMarca = [...document.querySelectorAll('.cp-marca-grupo')];
const TOTAL_PRODUCTOS = <?= $totalProductos ?>;

function filtrarCatalogo() {
  const t = cpQ.value.trim().toLowerCase();
  cpEnvoltura.classList.toggle('tiene-texto', t.length > 0);
  let visibles = 0;
  gruposMarca.forEach(g => {
    let enGrupo = 0;
    g.querySelectorAll('.cp-tarjeta').forEach(c => {
      const ok = !t || c.dataset.busca.includes(t);
      c.hidden = !ok;
      if (ok) enGrupo++;
    });
    g.hidden = enGrupo === 0;
    visibles += enGrupo;
  });
  cpVacio.classList.toggle('visible', visibles === 0);
  cpConteo.textContent = t
    ? visibles + (visibles === 1 ? ' producto' : ' productos')
    : TOTAL_PRODUCTOS + ' productos';
}
cpQ.addEventListener('input', filtrarCatalogo);
document.getElementById('cpLimpiar').addEventListener('click', () => {
  cpQ.value = '';
  filtrarCatalogo();
  cpQ.focus();
});

/* --- Chips de marca --- */
const chips = document.querySelectorAll('.cp-chip');
chips.forEach(ch => ch.addEventListener('click', e => {
  if (ch.dataset.slug === '__todas') {
    e.preventDefault();
    cpQ.value = '';
    filtrarCatalogo();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
}));

/* Chips de marca · sombra de scroll: difumina el borde donde hay más chips
   ocultos, para que el que queda a la vista no se vea "recortado" sino
   como una pista de que hay más para deslizar. */
const cpChipsNav = document.getElementById('chips');
function cpActualizarDesborde() {
  const restante = cpChipsNav.scrollWidth - cpChipsNav.clientWidth;
  const desbordaIzq = cpChipsNav.scrollLeft > 2;
  const desbordaDer = cpChipsNav.scrollLeft < restante - 2;
  cpChipsNav.classList.toggle('cp-desborda-ambos', desbordaIzq && desbordaDer);
  cpChipsNav.classList.toggle('cp-desborda-izquierda', desbordaIzq && !desbordaDer);
  cpChipsNav.classList.toggle('cp-desborda-derecha', !desbordaIzq && desbordaDer);
}
if (cpChipsNav) {
  cpChipsNav.addEventListener('scroll', cpActualizarDesborde, { passive: true });
  window.addEventListener('resize', cpActualizarDesborde);
  cpActualizarDesborde();

  // Con mouse normal (sin trackpad) la fila no se podía mover: la rueda
  // solo hace scroll vertical y este contenedor no tiene alto que desbordar.
  // Se traduce la rueda a desplazamiento horizontal mientras el mouse esté encima.
  cpChipsNav.addEventListener('wheel', (e) => {
    if (Math.abs(e.deltaY) > Math.abs(e.deltaX)) {
      cpChipsNav.scrollLeft += e.deltaY;
      e.preventDefault();
    }
  }, { passive: false });
}

if (chips.length && gruposMarca.length) {
  const observador = new IntersectionObserver(entradas => {
    entradas.forEach(entrada => {
      if (entrada.isIntersecting) {
        chips.forEach(chip => {
          const esActivo = chip.dataset.slug === entrada.target.id;
          chip.classList.toggle('activo', esActivo);
          // Antes: solo se pintaba el chip activo, pero la fila nunca se
          // movía para mostrarlo — si estaba fuera de vista (como "Leche
          // pal pelo", casi siempre tapado a la derecha) parecía que el
          // carrusel no reaccionaba al bajar la página.
          if (esActivo) chip.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
        });
      }
    });
  }, { rootMargin: '-130px 0px -70% 0px' });
  gruposMarca.forEach(grupo => observador.observe(grupo));
}
</script>

</body>
</html>
