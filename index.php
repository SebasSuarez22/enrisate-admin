<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

iniciar_sesion();

// Si ya entró, no tiene nada que hacer aquí
if (hay_sesion()) {
    header('Location: dashboard.php');
    exit;
}

$error   = '';
$neutro  = '';
$rechazo = false;

$bloqueo = segundos_bloqueo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $error   = 'La página estuvo abierta demasiado tiempo. Recárgala e intenta de nuevo.';
        $rechazo = true;
    } elseif ($bloqueo > 0) {
        $error   = 'Demasiados intentos. Espera ' . ceil($bloqueo / 60) . ' minuto(s) e intenta otra vez.';
        $rechazo = true;
    } else {
        $usuario = trim((string) ($_POST['usuario'] ?? ''));
        $clave   = (string) ($_POST['clave'] ?? '');

        if ($usuario === '' || $clave === '') {
            $error   = 'Escribe tu usuario y tu contraseña.';
            $rechazo = true;
        } elseif (intentar_login($usuario, $clave)) {
            header('Location: dashboard.php');
            exit;
        } else {
            $bloqueo = segundos_bloqueo();
            if ($bloqueo > 0) {
                $error = 'Cuenta bloqueada por 5 minutos. Vuelve a intentar más tarde.';
            } else {
                $restan = intentos_restantes();
                $error  = 'El usuario o la contraseña no coinciden. Te quedan '
                        . $restan . ' intento' . ($restan === 1 ? '' : 's') . '.';
            }
            $rechazo = true;
        }
    }
}

// Mensajes que llegan desde otras páginas
$motivo = $_GET['motivo'] ?? '';
if ($motivo === 'inactividad') {
    $neutro = 'Cerramos la sesión por inactividad. Ingresa de nuevo.';
} elseif ($motivo === 'sesion') {
    $neutro = 'Ingresa para abrir el panel.';
} elseif ($motivo === 'salida') {
    $neutro = 'Sesión cerrada. Hasta pronto.';
}

$token = csrf_token();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Ingresar · <?= e(APP_NOMBRE) ?></title>
<link rel="icon" href="assets/img/logo.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Manrope:wght@400;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/enrisate.css?v=<?= filemtime(__DIR__ . '/assets/css/enrisate.css') ?>">
</head>
<body>

<?php require __DIR__ . '/includes/escena.php'; ?>

<main class="entrada">
  <section class="tarjeta<?= $rechazo ? ' rechazo' : '' ?>">

    <header class="marca">
      <img src="assets/img/logo.svg" alt="">
      <h1 class="wordmark"><?= e(APP_NOMBRE) ?></h1>
      <p class="tagline"><?= e(APP_TAGLINE) ?></p>
    </header>

    <?php if ($error !== ''): ?>
      <p class="aviso" role="alert"><?= e($error) ?></p>
    <?php elseif ($neutro !== ''): ?>
      <p class="aviso neutro"><?= e($neutro) ?></p>
    <?php endif; ?>

    <form method="post" action="index.php" autocomplete="off" novalidate>
      <input type="hidden" name="csrf" value="<?= e($token) ?>">

      <div class="campo">
        <label for="usuario">Usuario</label>
        <div class="envoltura">
          <input type="text" id="usuario" name="usuario" placeholder="admin"
                 value="<?= e($_POST['usuario'] ?? '') ?>"
                 autocapitalize="none" spellcheck="false" autofocus required>
        </div>
      </div>

      <div class="campo">
        <label for="clave">Contraseña</label>
        <div class="envoltura">
          <input type="password" id="clave" name="clave" placeholder="••••••••" required>
          <button type="button" class="ojo" id="verClave"
                  aria-label="Mostrar contraseña" aria-pressed="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/>
              <circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
        <p class="mayusculas" id="avisoMayus">Bloq Mayús está activado</p>
      </div>

      <button type="submit" class="boton" <?= $bloqueo > 0 ? 'disabled' : '' ?>>
        <?= $bloqueo > 0 ? 'Bloqueado unos minutos' : 'Entrar al panel' ?>
      </button>
    </form>

    <p class="pie"><?= e(APP_CIUDAD) ?> · Panel privado · <?= e(APP_VERSION) ?></p>

  </section>
</main>

<script>
  const clave  = document.getElementById('clave');
  const ojo    = document.getElementById('verClave');
  const mayus  = document.getElementById('avisoMayus');

  ojo.addEventListener('click', () => {
    const oculta = clave.type === 'password';
    clave.type = oculta ? 'text' : 'password';
    ojo.setAttribute('aria-pressed', String(oculta));
    ojo.setAttribute('aria-label', oculta ? 'Ocultar contraseña' : 'Mostrar contraseña');
    clave.focus();
  });

  const revisarMayus = (ev) => {
    if (typeof ev.getModifierState !== 'function') return;
    mayus.classList.toggle('visible', ev.getModifierState('CapsLock'));
  };
  clave.addEventListener('keyup', revisarMayus);
  clave.addEventListener('keydown', revisarMayus);
  clave.addEventListener('blur', () => mayus.classList.remove('visible'));
</script>

</body>
</html>
