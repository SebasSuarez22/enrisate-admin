<?php
/* =========================================================
   Cabecera compartida: <head> + barra superior.
   Antes de incluir este parcial, define:
     $tituloPagina  → texto para el <title> (obligatorio)
     $barraVolver   → true en páginas internas (muestra "Volver al panel");
                      déjalo en false/sin definir en el panel (muestra "Cerrar sesión").
   ========================================================= */
$tituloPagina = $tituloPagina ?? APP_NOMBRE;
$barraVolver  = $barraVolver ?? false;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($tituloPagina) ?> · <?= e(APP_NOMBRE) ?></title>
<link rel="icon" href="assets/img/logo.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Manrope:wght@400;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/enrisate.css?v=<?= filemtime(__DIR__ . '/../assets/css/enrisate.css') ?>">
<!-- Solo define seleccionEnLote(); no toca el DOM al cargar, así que va
     sin defer y queda lista para el <script> del final de cada página. -->
<script src="assets/js/seleccion-lote.js?v=<?= filemtime(__DIR__ . '/../assets/js/seleccion-lote.js') ?>"></script>
<!-- Este sí con defer: se activa solo en cualquier página que tenga una
     tabla con scroll horizontal, sin que la página tenga que llamarlo. -->
<script defer src="assets/js/tabla-deslizable.js?v=<?= filemtime(__DIR__ . '/../assets/js/tabla-deslizable.js') ?>"></script>
</head>
<body>

<?php require __DIR__ . '/escena.php'; ?>

<header class="barra">
  <img src="assets/img/logo.svg" alt="">
  <span class="wordmark"><?= e(APP_NOMBRE) ?></span>
  <span class="quien"><?= e($_SESSION['nombre'] ?? '') ?></span>
  <?php if ($barraVolver): ?>
    <a class="salir" href="dashboard.php">Volver al panel</a>
  <?php else: ?>
    <a class="salir" href="logout.php">Cerrar sesión</a>
  <?php endif; ?>
</header>
