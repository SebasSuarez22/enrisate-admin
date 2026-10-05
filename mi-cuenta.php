<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ingreso_vista.php';

/* =========================================================
   Mi cuenta (cualquier persona con sesión)
   =========================================================
   · Cambiar mi contraseña (pide la actual).
   · Generar 10 códigos de respaldo nuevos (pide el código de la app).
   · Cambiar de celular: borra el autenticador actual y cierra la sesión; al
     volver a entrar se conecta el celular nuevo (pide el código de la app).
   · Cerrar mis otras sesiones.
   ========================================================= */

requerir_sesion();
$yo = usuario_actual();

$error   = '';
$ok      = '';
$codigos = null;

/** Tras un cambio que sube la versión de sesión, esta sesión sigue abierta. */
function seguir_con_esta_sesion(int $id): void
{
    $_SESSION['version'] = (int) leer_usuario($id)['sesion_version'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    $claves = claves_limite((string) $yo['usuario']);
    $espera = espera_ingreso($claves);
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'La página estuvo abierta demasiado tiempo. Recárgala e intenta de nuevo.';
    } elseif ($espera > 0) {
        $error = texto_espera($espera);
    } elseif ($accion === 'clave') {
        $nueva = (string) ($_POST['nueva'] ?? '');
        if ($nueva !== (string) ($_POST['confirma'] ?? '')) {
            $error = 'Las dos contraseñas nuevas no son iguales.';
        } else {
            try {
                cambiar_clave_usuario($yo, (string) ($_POST['actual'] ?? ''), $nueva);
                seguir_con_esta_sesion((int) $yo['id']);
                $ok = 'Contraseña cambiada. Se cerraron tus sesiones en otros equipos.';
            } catch (InvalidArgumentException $e) {
                if (!password_verify((string) ($_POST['actual'] ?? ''), (string) $yo['clave_hash'])) {
                    registrar_fallo_ingreso($claves);
                    registrar_evento('fallo_clave', $yo, 'Al cambiar su contraseña');
                }
                $error = $e->getMessage();
            }
        }
    } elseif (in_array($accion, ['codigos', 'celular'], true)) {
        if (!verificar_codigo_usuario($yo, (string) ($_POST['codigo'] ?? ''))) {
            registrar_fallo_ingreso($claves);
            registrar_evento('fallo_codigo', $yo, $accion === 'codigos' ? 'Al generar códigos' : 'Al cambiar de celular');
            $error = mensaje_codigo_fallido(leer_usuario((int) $yo['id']) ?? $yo, (string) ($_POST['codigo'] ?? ''));
        } elseif ($accion === 'codigos') {
            $codigos = generar_codigos_respaldo($yo);
            registrar_evento('codigos_generados', $yo, 'Desde Mi cuenta');
            unset($_SESSION['aviso_respaldos']);
        } else {
            restablecer_verificacion($yo, $yo);
            registrar_evento('salida', $yo, 'Para conectar un celular nuevo');
            cerrar_sesion();
            header('Location: index.php?motivo=celular');
            exit;
        }
    } elseif ($accion === 'sesiones') {
        db()->prepare('UPDATE usuarios SET sesion_version = sesion_version + 1 WHERE id = ?')->execute([(int) $yo['id']]);
        seguir_con_esta_sesion((int) $yo['id']);
        $ok = 'Listo: se cerraron tus sesiones en otros equipos. Esta sigue abierta.';
    }
}

$yo       = leer_usuario((int) $yo['id']);
$quedan   = respaldos_restantes((int) $yo['id']);
$token    = csrf_token();

$tituloPagina = 'Mi cuenta';
$barraVolver  = true;
require __DIR__ . '/includes/cabecera.php';
?>

<main class="lienzo">
  <h1 class="saludo">Mi cuenta</h1>
  <p class="subsaludo"><?= e($yo['nombre']) ?> · usuario <strong><?= e($yo['usuario']) ?></strong> · <?= e(ROLES[$yo['rol']] ?? $yo['rol']) ?></p>

  <?php if ($error !== ''): ?>
    <p class="aviso" role="alert"><?= e($error) ?></p>
  <?php endif; ?>
  <?php if ($ok !== ''): ?>
    <p class="aviso exito" role="status"><?= e($ok) ?></p>
  <?php endif; ?>

  <?php if ($codigos): ?>
    <section class="panel panel-codigos" aria-labelledby="tituloCodigosNuevos">
      <h2 class="titulo-panel" id="tituloCodigosNuevos">Tus 10 códigos de respaldo nuevos</h2>
      <p class="subsaludo">Los anteriores ya no sirven. Guárdalos fuera de tu celular: <strong>solo los verás ahora.</strong></p>
      <?php vista_codigos_respaldo($codigos, (string) $yo['usuario']); ?>
    </section>
  <?php endif; ?>

  <section class="panel">
    <h2 class="titulo-panel">Verificación en dos pasos</h2>
    <p class="estado-cuenta"><span class="estado ok">Activa</span> con tu app autenticadora · <?= $quedan ?> código<?= $quedan === 1 ? '' : 's' ?> de respaldo sin usar</p>

    <div class="dos-acciones">
      <form method="post" action="mi-cuenta.php" class="tarjeta-accion" novalidate>
        <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <input type="hidden" name="accion" value="codigos">
        <h3>Códigos de respaldo nuevos</h3>
        <p>Genera 10 nuevos si usaste varios o los perdiste. Los anteriores dejan de servir.</p>
        <label class="visualmente-oculto" for="codigoRespaldos">Tu código de 6 dígitos</label>
        <input class="codigo-grande compacto" type="text" id="codigoRespaldos" name="codigo" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" placeholder="000 000" required>
        <button type="submit" class="boton">Generar 10 códigos</button>
      </form>

      <form method="post" action="mi-cuenta.php" class="tarjeta-accion" novalidate>
        <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <input type="hidden" name="accion" value="celular">
        <h3>Cambiar de celular</h3>
        <p>Si todavía tienes el celular viejo: confirma con su código, se cierra tu sesión y al volver a entrar conectas el nuevo.</p>
        <label class="visualmente-oculto" for="codigoCelular">Tu código de 6 dígitos</label>
        <input class="codigo-grande compacto" type="text" id="codigoCelular" name="codigo" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" placeholder="000 000" required>
        <button type="submit" class="boton fantasma">Cambiar de celular</button>
      </form>
    </div>
    <p class="ayuda-campo">¿Perdiste el celular? Pide a alguien de administración que restablezca tu verificación desde <strong>Usuarios</strong>.</p>
  </section>

  <section class="panel">
    <h2 class="titulo-panel">Cambiar mi contraseña</h2>
    <form method="post" action="mi-cuenta.php" class="form-clave" novalidate>
      <input type="hidden" name="csrf" value="<?= e($token) ?>">
      <input type="hidden" name="accion" value="clave">
      <input type="text" name="usuario" value="<?= e($yo['usuario']) ?>" autocomplete="username" hidden>
      <div class="campo">
        <label for="actual">Contraseña actual</label>
        <input type="password" id="actual" name="actual" autocomplete="current-password" maxlength="200" required>
      </div>
      <div class="campo">
        <label for="nueva">Contraseña nueva</label>
        <div class="envoltura">
          <input type="password" id="nueva" name="nueva" autocomplete="new-password" minlength="<?= CLAVE_MINIMA ?>" maxlength="<?= CLAVE_MAXIMA ?>" required>
          <button type="button" class="ojo" data-ver="nueva" aria-label="Mostrar contraseña" aria-pressed="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>
      <div class="campo">
        <label for="confirma">Repite la nueva</label>
        <input type="password" id="confirma" name="confirma" autocomplete="new-password" maxlength="<?= CLAVE_MAXIMA ?>" required>
      </div>
      <p class="ayuda-campo">Al menos <?= CLAVE_MINIMA ?> caracteres. Una frase que recuerdes sirve, por ejemplo “mi perro come mango”.</p>
      <button type="submit" class="boton mini">Cambiar contraseña</button>
    </form>
  </section>

  <section class="panel">
    <h2 class="titulo-panel">Sesiones</h2>
    <form method="post" action="mi-cuenta.php" class="fila-sesiones">
      <input type="hidden" name="csrf" value="<?= e($token) ?>">
      <input type="hidden" name="accion" value="sesiones">
      <p>Si entraste desde un equipo que no es tuyo y no cerraste sesión, ciérrala desde aquí.</p>
      <button type="submit" class="boton mini fantasma">Cerrar mis otras sesiones</button>
    </form>
  </section>
</main>

<script src="assets/js/ingreso.js?v=<?= filemtime(__DIR__ . '/assets/js/ingreso.js') ?>" defer></script>
</body>
</html>
