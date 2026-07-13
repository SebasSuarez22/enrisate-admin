<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

requerir_sesion();

$hora    = (int) date('G');
$saludo  = $hora < 12 ? 'Buenos días' : ($hora < 19 ? 'Buenas tardes' : 'Buenas noches');
$nombre  = $_SESSION['nombre'] ?? 'Administradora';

$modulos = [
    ['Facturación','Armar la cuenta de cobro, imprimir el PDF y enviarla por WhatsApp.', 'Listo',      'facturar.php'],
    ['Catálogo',   '92 productos con precio y costo. Por ahora se edita en el Excel.',   'Siguiente',  null],
    ['Cartera',    'Segundas cuotas pendientes y desde cuándo.',                         'Siguiente',  null],
    ['Clientes',   'Historial de compras y quién vuelve a comprar.',                     'Pendiente',  null],
    ['Márgenes',   'Costo contra precio, producto por producto.',                        'Pendiente',  null],
    ['Ajustes',    'Contraseña, datos del negocio y respaldo de la información.',        'Pendiente',  null],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Panel · <?= e(APP_NOMBRE) ?></title>
<link rel="icon" href="assets/img/logo.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Manrope:wght@400;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/enrisate.css">
</head>
<body>

<?php require __DIR__ . '/includes/escena.php'; ?>

<header class="barra">
  <img src="assets/img/logo.svg" alt="">
  <span class="wordmark"><?= e(APP_NOMBRE) ?></span>
  <span class="quien"><?= e($nombre) ?></span>
  <a class="salir" href="logout.php">Cerrar sesión</a>
</header>

<main class="lienzo">
  <h1 class="saludo"><?= e($saludo) ?>.</h1>
  <p class="subsaludo">Ya estás dentro. Estos son los módulos que vamos a construir.</p>

  <div class="rejilla">
    <?php foreach ($modulos as [$titulo, $texto, $estado, $link]): ?>
      <article class="modulo<?= $link ? ' activo' : '' ?>">
        <h3><?= e($titulo) ?></h3>
        <p><?= e($texto) ?></p>
        <?php if ($link): ?>
          <a class="boton mini" href="<?= e($link) ?>">Abrir</a>
        <?php else: ?>
          <span class="etiqueta"><?= e($estado) ?></span>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
</main>

</body>
</html>
