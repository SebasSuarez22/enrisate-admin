<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ingreso_vista.php';

/* Paso 1 del ingreso: usuario y contraseña. Si coinciden, la persona pasa a
   "pre-ingreso" y sigue al código de su app (o, la primera vez, a conectar el
   celular). Ver includes/auth.php. */

iniciar_sesion();

if (hay_sesion() && usuario_actual()) {
    header('Location: dashboard.php');
    exit;
}

$error   = '';
$neutro  = '';
$rechazo = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim((string) ($_POST['usuario'] ?? ''));
    $clave   = (string) ($_POST['clave'] ?? '');

    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'La página estuvo abierta demasiado tiempo. Recárgala e intenta de nuevo.';
    } elseif ($usuario === '' || $clave === '') {
        $error = 'Escribe tu usuario y tu contraseña.';
    } else {
        $r = revisar_contrasena($usuario, $clave);
        if ($r['estado'] === 'ok') {
            iniciar_preingreso($r['usuario']);
            avanzar_ingreso($r['usuario']);
        }
        // Nunca se dice si falló el usuario o la contraseña.
        $error = $r['estado'] === 'espera'
            ? texto_espera((int) $r['segundos'])
            : 'No pudimos verificar tus datos. Revisa el usuario y la contraseña.';
    }
    $rechazo = true;
} else {
    // Volver aquí a mitad del paso 2 lo cancela: se empieza de nuevo.
    unset($_SESSION['pre'], $_SESSION['codigos_nuevos']);
}

$motivo = $_GET['motivo'] ?? '';
$neutro = match ($motivo) {
    'inactividad' => 'Cerramos la sesión por inactividad. Ingresa de nuevo.',
    'vencida'     => 'Tu sesión cumplió 8 horas y se cerró por seguridad. Ingresa de nuevo.',
    'sesion'      => 'Ingresa para abrir el panel.',
    'salida'      => 'Sesión cerrada. Hasta pronto.',
    'pasos'       => 'Pasaron más de 10 minutos sin terminar. Ingresa de nuevo.',
    'celular'     => 'Listo. Ingresa de nuevo para conectar tu celular nuevo.',
    default       => '',
};

$token = csrf_token();
$usuarioEscrito = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['usuario'] ?? '') : '';

vista_ingreso_abrir('Ingresar', PASOS_INGRESO, 1, $rechazo, true);
vista_aviso($error, $neutro);
?>
    <form method="post" action="index.php" novalidate>
      <input type="hidden" name="csrf" value="<?= e($token) ?>">

      <div class="campo">
        <label for="usuario">Usuario</label>
        <div class="envoltura">
          <input type="text" id="usuario" name="usuario" value="<?= e($usuarioEscrito) ?>"
                 autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="60"
                 <?= $usuarioEscrito === '' ? 'autofocus' : '' ?> required>
        </div>
      </div>

      <div class="campo">
        <label for="clave">Contraseña</label>
        <div class="envoltura">
          <input type="password" id="clave" name="clave" autocomplete="current-password" maxlength="200"
                 <?= $usuarioEscrito !== '' ? 'autofocus' : '' ?> required>
          <button type="button" class="ojo" data-ver="clave" aria-label="Mostrar contraseña" aria-pressed="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
        <p class="mayusculas" data-mayus="clave">Bloq Mayús está activado</p>
      </div>

      <button type="submit" class="boton">Continuar</button>
      <p class="nota-ingreso">Después te pediremos el código de tu app autenticadora.</p>
    </form>
<?php
vista_ingreso_cerrar();
