<?php
declare(strict_types=1);

/* =========================================================
   Plantilla de las pantallas del ingreso (propuesta B · "Pasos guiados")
   =========================================================
   Una tarjeta centrada con la marca y, arriba, la barra de pasos
   (Contraseña → Código → Listo, o la de la primera vez). Cada página
   llama a vista_ingreso_abrir() y a vista_ingreso_cerrar().
   ========================================================= */

const PASOS_INGRESO       = ['Contraseña', 'Código', 'Listo'];
const PASOS_PRIMERA_VEZ   = ['Contraseña', 'Conectar celular', 'Códigos'];

/** Pasos de la primera vez; si la persona tuvo que crear su contraseña, ese paso también aparece. */
function pasos_primera_vez(): array
{
    return empty($_SESSION['pre']['nueva_clave'])
        ? PASOS_PRIMERA_VEZ
        : ['Contraseña', 'Nueva contraseña', 'Conectar celular', 'Códigos'];
}

/**
 * @param string[] $pasos   nombres de los pasos de la barra
 * @param int      $actual  paso en curso (1 = el primero)
 * @param bool     $grande  marca grande (pantalla de usuario y contraseña) o compacta
 */
function vista_ingreso_abrir(string $titulo, array $pasos, int $actual, bool $rechazo = false, bool $grande = false): void
{
    header('Cache-Control: no-store');
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?= e($titulo) ?> · <?= e(APP_NOMBRE) ?></title>
<link rel="icon" href="assets/img/logo.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/enrisate.css?v=<?= filemtime(__DIR__ . '/../assets/css/enrisate.css') ?>">
</head>
<body>

<?php require __DIR__ . '/escena.php'; ?>

<main class="entrada">
  <section class="tarjeta<?= $rechazo ? ' rechazo' : '' ?><?= $grande ? '' : ' tarjeta-paso' ?>">

    <?php if ($grande): ?>
      <header class="marca">
        <img src="assets/img/logo.svg" alt="">
        <h1 class="wordmark"><?= e(APP_NOMBRE) ?></h1>
        <p class="tagline"><?= e(APP_TAGLINE) ?></p>
      </header>
    <?php else: ?>
      <header class="marca marca-mini">
        <img src="assets/img/logo.svg" alt="">
        <span class="wordmark"><?= e(APP_NOMBRE) ?></span>
      </header>
    <?php endif; ?>

    <ol class="pasos-ingreso" aria-label="Pasos para entrar">
      <?php foreach ($pasos as $i => $nombre): $n = $i + 1; ?>
        <li class="<?= $n < $actual ? 'hecho' : ($n === $actual ? 'actual' : '') ?>"<?= $n === $actual ? ' aria-current="step"' : '' ?>>
          <span class="visualmente-oculto"><?= $n < $actual ? 'Listo: ' : ($n === $actual ? 'Paso actual: ' : 'Siguiente: ') ?></span><?= e($nombre) ?>
        </li>
      <?php endforeach; ?>
    </ol>
    <?php
}

function vista_ingreso_cerrar(bool $pie = true): void
{
    ?>
    <?php if ($pie): ?>
      <p class="pie"><?= e(APP_CIUDAD) ?> · Panel privado · <?= e(APP_VERSION) ?></p>
    <?php endif; ?>
  </section>
</main>
<script src="assets/js/ingreso.js?v=<?= filemtime(__DIR__ . '/../assets/js/ingreso.js') ?>" defer></script>
</body>
</html>
    <?php
}

/** Aviso de error (rojo) o neutro sobre la tarjeta. */
function vista_aviso(string $error, string $neutro = ''): void
{
    if ($error !== '') {
        echo '<p class="aviso" role="alert">' . e($error) . '</p>';
    } elseif ($neutro !== '') {
        echo '<p class="aviso neutro" role="status">' . e($neutro) . '</p>';
    }
}

/** "Espera 3 minutos" a partir de segundos. */
function texto_espera(int $segundos): string
{
    $min = (int) ceil($segundos / 60);
    return 'Demasiados intentos. Espera ' . $min . ' minuto' . ($min === 1 ? '' : 's') . ' e intenta otra vez.';
}

/** Pantalla cuando el ingreso no puede funcionar (falta configuración del servidor). */
function vista_ingreso_no_disponible(): never
{
    http_response_code(503);
    vista_ingreso_abrir('Ingreso no disponible', PASOS_INGRESO, 1, false, true);
    vista_aviso('El ingreso no está disponible en este momento. Avísale a quien administra el sistema.');
    vista_ingreso_cerrar();
    exit;
}

/** QR en SVG (sin imágenes externas: el secreto nunca sale del servidor hacia otro servicio). */
function qr_svg(string $texto): string
{
    require_once __DIR__ . '/../vendor/autoload.php';
    $opciones = new chillerlan\QRCode\QROptions([
        'outputType'       => chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG,
        'outputBase64'     => false,
        'svgAddXmlHeader'  => false,
        'addQuietzone'     => true,
        'quietzoneSize'    => 2,
        'drawLightModules' => false,
        'eccLevel'         => chillerlan\QRCode\Common\EccLevel::M,
    ]);
    $svg = (new chillerlan\QRCode\QRCode($opciones))->render($texto);
    return str_replace('<svg ', '<svg role="img" aria-label="Código QR para la app autenticadora" ', $svg);
}

/** Los 10 códigos de respaldo, con Copiar y Descargar. Se muestran una sola vez. */
function vista_codigos_respaldo(array $codigos, string $usuario): void
{
    $texto = "Códigos de respaldo de " . APP_NOMBRE . " · usuario: $usuario\n"
           . "Cada código sirve una sola vez. Guárdalos fuera de tu celular.\n\n"
           . implode("\n", array_map(fn($c, $i) => ($i + 1) . '. ' . $c, $codigos, array_keys($codigos))) . "\n";
    ?>
    <ol class="codigos-respaldo" data-codigos="<?= e($texto) ?>">
      <?php foreach ($codigos as $c): ?>
        <li><?= e($c) ?></li>
      <?php endforeach; ?>
    </ol>
    <div class="acciones-codigos">
      <button type="button" class="boton fantasma" data-copiar-codigos>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/></svg>
        <span>Copiar</span>
      </button>
      <button type="button" class="boton fantasma" data-descargar-codigos="enrisate-codigos-<?= e($usuario) ?>.txt">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>
        <span>Descargar</span>
      </button>
    </div>
    <?php
}
