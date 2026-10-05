<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ingreso_vista.php';

/* Quien entra con una contraseña temporal (la que le puso administración) crea
   aquí la suya antes de seguir. */

$u = requerir_preingreso('nueva-clave.php');

$error   = '';
$rechazo = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rechazo  = true;
    $nueva    = (string) ($_POST['nueva'] ?? '');
    $confirma = (string) ($_POST['confirma'] ?? '');
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'La página estuvo abierta demasiado tiempo. Recárgala e intenta de nuevo.';
    } elseif ($nueva !== $confirma) {
        $error = 'Las dos contraseñas no son iguales.';
    } elseif (password_verify($nueva, (string) $u['clave_hash'])) {
        $error = 'Debe ser distinta de la contraseña temporal.';
    } else {
        try {
            cambiar_clave_usuario($u, '', $nueva, false);
            // Cambiar la contraseña sube la versión de sesión; esta sesión la sigue.
            $_SESSION['pre']['version'] = (int) leer_usuario((int) $u['id'])['sesion_version'];
            $_SESSION['pre']['nueva_clave'] = true;
            avanzar_ingreso($u);
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    }
}

$token = csrf_token();
$pasos = (int) $u['totp_activo'] === 1
    ? ['Contraseña', 'Código', 'Nueva contraseña']
    : ['Contraseña', 'Nueva contraseña', 'Conectar celular', 'Códigos'];

vista_ingreso_abrir('Crea tu contraseña', $pasos, (int) $u['totp_activo'] === 1 ? 3 : 2, $rechazo);
?>
    <h1 class="titulo-ingreso">Crea tu contraseña</h1>
    <p class="sub-ingreso">La que te dieron era temporal. Usa al menos <?= CLAVE_MINIMA ?> caracteres: una frase que recuerdes sirve, por ejemplo “mi perro come mango”.</p>
    <?php vista_aviso($error); ?>

    <form method="post" action="nueva-clave.php" novalidate>
      <input type="hidden" name="csrf" value="<?= e($token) ?>">
      <input type="text" name="usuario" value="<?= e($u['usuario']) ?>" autocomplete="username" hidden>
      <div class="campo">
        <label for="nueva">Contraseña nueva</label>
        <div class="envoltura">
          <input type="password" id="nueva" name="nueva" autocomplete="new-password" minlength="<?= CLAVE_MINIMA ?>" maxlength="<?= CLAVE_MAXIMA ?>" autofocus required>
          <button type="button" class="ojo" data-ver="nueva" aria-label="Mostrar contraseña" aria-pressed="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
        <p class="mayusculas" data-mayus="nueva">Bloq Mayús está activado</p>
      </div>
      <div class="campo">
        <label for="confirma">Repítela</label>
        <div class="envoltura">
          <input type="password" id="confirma" name="confirma" autocomplete="new-password" maxlength="<?= CLAVE_MAXIMA ?>" required>
        </div>
      </div>
      <button type="submit" class="boton">Guardar y continuar</button>
    </form>
<?php
vista_ingreso_cerrar(false);
