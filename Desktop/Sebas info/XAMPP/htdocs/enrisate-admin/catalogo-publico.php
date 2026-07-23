<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/facturas.php';

// Página pública: sin login, sin sesión, sin datos internos (costo/margen).

$porMarca = [];
foreach (catalogo() as $p) {
    $porMarca[$p['marca']][] = $p;
}
ksort($porMarca);
foreach ($porMarca as &$ps) {
    usort($ps, fn($a, $b) => $a['nombre'] <=> $b['nombre']);
}
unset($ps);

function cp_slug(string $s): string {
    $s = strtolower($s);
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

// Logo por marca. Tongolé es una línea de La Poción, usa el mismo logo.
// Las marcas que no están aquí todavía no tienen logo conseguido.
$logosPorMarca = [
    'Etniker'          => 'etniker.png',
    'Femme'            => 'femme.png',
    'La Poción'        => 'la-pocion.svg',
    'Tongole'          => 'la-pocion.svg',
    'Lisso Keratin'    => 'lisso-keratin.svg',
    'Menta Hair'       => 'menta-hair.png',
    'Origen Botánico'  => 'origen-botanico.png',
    'Skala'            => 'skala.png',
];

// Fotos reales por SKU. Los productos que no están aquí siguen mostrando el ícono genérico.
$fotosPorSku = [
    'ACC-04' => 'ACC-04.png', // Carotone 135 mL
    'ACC-03' => 'ACC-03.png', // Carotone 300 mL
    'ACC-05' => 'ACC-05.jpg', // Jabón carotone
    'ACC-01' => 'ACC-01.jpg', // Cepillo definidor
    'ACC-02' => 'ACC-02.jpg', // Cepillo secador Avocado 3D
    'ACC-06' => 'ACC-06.webp', // Rapunzel tónico capilar (Lola Cosmetics)
    'ACC-07' => 'ACC-07.jpg', // Karsell
    'ACC-08' => 'ACC-08.jpg', // Sobres de Karsell
    'DES01'  => 'DES01.jpg', // Desodorante piedra de alumbre
    'ET-01'  => 'ET-01.png', // Shampoo Etniker
    'ET-02'  => 'ET-02.webp', // Espuma intensificadora de rizos
    'ET-03'  => 'ET-03.png', // Crema de peinar
    'ET-04'  => 'ET-04.png', // Gel definidor de rizos
    'FEM-01' => 'FEM-01.jpg', // Crema de Peinar 500 ml
    'FEM-05' => 'FEM-05.jpg', // Gel Fluido 300 ml
    'FEM-06' => 'FEM-06.jpg', // Gel Fluido 500 ml
    'FEM-07' => 'FEM-07.jpg', // Gel duro
    'FEM-03' => 'FEM-03.jpg', // Shampoo Femme
    'FEM-04' => 'FEM-04.jpg', // Óleo Dorado
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
    'LP-02'  => 'LP-02.webp', // Acondicionador Lisos
    'LP-04'  => 'LP-04.png', // Shampoo Rizos
    'LP-05'  => 'LP-05.png', // Acondicionador Rizos (Mascarilla Nutritiva)
    'LP-06'  => 'LP-06.png', // Crema de Peinar Rizos (Definidor de Rizos)
    'LP-07'  => 'LP-07.png', // Gelatina Rizos (línea kids)
    'LP-08'  => 'LP-08.png', // Shampoo 2 en 1 Niños
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Catálogo · <?= e(APP_NOMBRE) ?></title>
<link rel="icon" href="assets/img/logo.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/catalogo-publico.css">
</head>
<body>

<div class="cp-franja">✨ Tendencias en cuidado capilar · Pide fácil por WhatsApp ✨</div>

<header class="cp-encabezado">
  <h1 class="cp-marca"><img src="assets/img/logo-enrisate.png" alt="<?= e(APP_NOMBRE) ?>" class="cp-logo"></h1>
  <p class="cp-tagline"><?= e(APP_TAGLINE) ?> · <?= e(APP_CIUDAD) ?></p>
  <p class="cp-instrucciones">Marca lo que te gusta y la cantidad, y al final tocas un solo botón para mandarnos tu pedido por WhatsApp.</p>
</header>

<nav class="cp-chips">
  <?php foreach (array_keys($porMarca) as $marca): ?>
    <a class="cp-chip" href="#marca-<?= cp_slug($marca) ?>"><?= e($marca) ?></a>
  <?php endforeach; ?>
</nav>

<main class="cp-lienzo">
  <?php foreach ($porMarca as $marca => $ps): ?>
    <section class="cp-marca-grupo" id="marca-<?= cp_slug($marca) ?>">
      <h2 class="cp-marca-titulo"><?= e($marca) ?></h2>
      <div class="cp-rejilla">
        <?php foreach ($ps as $p): ?>
          <article class="cp-tarjeta"
                   data-sku="<?= e($p['sku']) ?>"
                   data-nombre="<?= e($p['nombre']) ?>"
                   data-precio="<?= (int) $p['precio'] ?>">
            <div class="cp-img-wrap">
              <?php if (isset($fotosPorSku[$p['sku']])): ?>
                <img class="cp-foto" src="assets/img/productos/<?= e($fotosPorSku[$p['sku']]) ?>" alt="">
              <?php endif; ?>
              <?php if (isset($logosPorMarca[$marca])): ?>
                <span class="cp-marca-logo"><img src="assets/img/marcas/<?= e($logosPorMarca[$marca]) ?>" alt="<?= e($marca) ?>"></span>
              <?php endif; ?>
            </div>
            <div class="cp-info">
              <p class="cp-nombre"><?= e($p['nombre']) ?></p>
              <p class="cp-precio"><?= pesos($p['precio']) ?></p>
              <div class="cp-fila-agregar">
                <input type="number" class="cp-cant" value="1" min="1" max="99" aria-label="Cantidad">
                <button type="button" class="cp-boton-agregar">Agregar</button>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
</main>

<footer class="cp-pie">
  <p>Precios sujetos a disponibilidad. Escríbenos si tienes dudas.</p>
</footer>

<div class="cp-barra-pedido" id="barraPedido" hidden>
  <p class="cp-barra-resumen" id="resumenPedido"></p>
  <button type="button" class="cp-barra-vaciar" id="vaciarPedido">Vaciar</button>
  <a class="cp-barra-link" id="enlaceWhatsapp" href="#" target="_blank" rel="noopener">Pedir por WhatsApp</a>
</div>

<script>
const WHATSAPP = '<?= e(APP_WHATSAPP) ?>';
const NOMBRE_NEGOCIO = '<?= e(APP_NOMBRE) ?>';
const cop = n => '$' + Math.round(n).toLocaleString('es-CO');

const pedido = [];
const barra    = document.getElementById('barraPedido');
const resumen  = document.getElementById('resumenPedido');
const enlace   = document.getElementById('enlaceWhatsapp');

function actualizarBarra() {
  if (!pedido.length) {
    barra.hidden = true;
    return;
  }
  barra.hidden = false;

  const cantTotal = pedido.reduce((s, i) => s + i.cant, 0);
  const total     = pedido.reduce((s, i) => s + i.precio * i.cant, 0);
  resumen.innerHTML = cantTotal + ' producto' + (cantTotal === 1 ? '' : 's') + ' · <strong>' + cop(total) + '</strong>';

  let msg = 'Hola! Quiero pedir esto de ' + NOMBRE_NEGOCIO + ':\n\n';
  pedido.forEach(i => {
    msg += '• ' + i.cant + ' x ' + i.nombre + ' — ' + cop(i.precio * i.cant) + '\n';
  });
  msg += '\nTotal aproximado: ' + cop(total) + '\n\n(Este es mi pedido, todavía no he pagado nada)';

  enlace.href = 'https://wa.me/' + WHATSAPP + '?text=' + encodeURIComponent(msg);
}

document.querySelectorAll('.cp-tarjeta').forEach(tarjeta => {
  const sku    = tarjeta.dataset.sku;
  const nombre = tarjeta.dataset.nombre;
  const precio = +tarjeta.dataset.precio;
  const cantInput = tarjeta.querySelector('.cp-cant');
  const boton     = tarjeta.querySelector('.cp-boton-agregar');
  if (!boton) return;

  boton.addEventListener('click', () => {
    const cant = Math.max(1, Math.min(99, parseInt(cantInput.value, 10) || 1));
    const ya = pedido.find(i => i.sku === sku);
    if (ya) {
      ya.cant = Math.min(99, ya.cant + cant);
    } else {
      pedido.push({ sku, nombre, precio, cant });
    }
    boton.textContent = 'En tu pedido ✓';
    boton.classList.add('en-pedido');
    actualizarBarra();
  });
});

document.getElementById('vaciarPedido').addEventListener('click', () => {
  pedido.length = 0;
  document.querySelectorAll('.cp-boton-agregar.en-pedido').forEach(b => {
    b.textContent = 'Agregar';
    b.classList.remove('en-pedido');
  });
  actualizarBarra();
});
</script>

</body>
</html>
