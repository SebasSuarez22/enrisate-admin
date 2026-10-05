<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ingreso_vista.php';

/* Paso 2 del ingreso: el código de 6 dígitos de la app autenticadora, o uno de
   los 10 códigos de respaldo si la persona no tiene el celular a mano. */

$u = requerir_preingreso('verificar.php');
$respaldo = ($_GET['modo'] ?? '') === 'respaldo' || ($_POST['accion'] ?? '') === 'respaldo';

$error   = '';
$rechazo = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rechazo = true;
    $claves  = claves_limite((string) $u['usuario']);
    $espera  = espera_ingreso($claves);

    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'La página estuvo abierta demasiado tiempo. Recárgala e intenta de nuevo.';
    } elseif ($espera > 0) {
        $error = texto_espera($espera);
    } else {
        try {
            if ($respaldo) {
                if (usar_codigo_respaldo($u, (string) ($_POST['respaldo'] ?? ''))) {
                    $quedan = respaldos_restantes((int) $u['id']);
                    if ($quedan <= 3) {
                        $_SESSION['aviso_respaldos'] = $quedan;
                    }
                    marcar_codigo_ok('ingreso_respaldo', 'Le quedan ' . $quedan . ' códigos de respaldo');
                    avanzar_ingreso($u);
                }
                $evento = 'fallo_respaldo';
                $error  = 'Ese código de respaldo no sirve. Revisa que lo hayas copiado bien; cada uno sirve una sola vez.';
            } else {
                if (verificar_codigo_usuario($u, (string) ($_POST['codigo'] ?? ''))) {
                    marcar_codigo_ok('ingreso');
                    avanzar_ingreso($u);
                }
                $evento = 'fallo_codigo';
                $error  = mensaje_codigo_fallido(leer_usuario((int) $u['id']) ?? $u, (string) ($_POST['codigo'] ?? ''));
            }
        } catch (LlaveFaltante $e) {
            error_log('[enrisate] ' . $e->getMessage());
            vista_ingreso_no_disponible();
        }
        registrar_fallo_ingreso($claves);
        registrar_evento($evento, $u);
        $espera = espera_ingreso($claves);
        if ($espera > 0) {
            registrar_evento('bloqueo', $u, 'Espera de ' . ceil($espera / 60) . ' min');
            $error = texto_espera($espera);
        }
    }
}

$token = csrf_token();

vista_ingreso_abrir($respaldo ? 'Código de respaldo' : 'Código de verificación', PASOS_INGRESO, 2, $rechazo);
?>
    <?php if (!$respaldo): ?>
      <h1 class="titulo-ingreso">Escribe tu código</h1>
      <p class="sub-ingreso">El de <strong>Enrísate</strong> en Google Authenticator.</p>
      <?php vista_aviso($error); ?>

      <form method="post" action="verificar.php" novalidate data-codigo>
        <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <input type="hidden" name="accion" value="codigo">
        <label class="visualmente-oculto" for="codigo">Código de 6 dígitos</label>
        <input class="codigo-grande" type="text" id="codigo" name="codigo"
               inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7"
               placeholder="000 000" autofocus required>
        <p class="reloj" data-reloj aria-hidden="true">
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" class="reloj-fondo"/><circle cx="12" cy="12" r="9" class="reloj-arco" pathLength="30"/></svg>
          <span>El código cambia cada 30 s</span>
        </p>
        <button type="submit" class="boton">Verificar</button>
      </form>

      <details class="otras-opciones">
        <summary>Otras opciones</summary>
        <a class="opcion" href="verificar.php?modo=respaldo">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M17 6l3 3M15 8l2 2"/></svg>
          <span>Usar un código de respaldo<small>Si no tienes el celular a mano</small></span>
        </a>
        <div class="opcion">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.8c2 .7 3.2 2.4 3.6 5.2"/></svg>
          <span>¿Perdiste el celular y los códigos?<small>Pide a alguien de administración que restablezca tu verificación desde Usuarios.</small></span>
        </div>
      </details>
    <?php else: ?>
      <h1 class="titulo-ingreso">Código de respaldo</h1>
      <p class="sub-ingreso">Escribe uno de los 10 que guardaste. Cada uno sirve una sola vez.</p>
      <?php vista_aviso($error); ?>

      <form method="post" action="verificar.php?modo=respaldo" novalidate>
        <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <input type="hidden" name="accion" value="respaldo">
        <div class="campo">
          <label for="respaldo">Código de respaldo</label>
          <input class="codigo-respaldo" type="text" id="respaldo" name="respaldo" maxlength="12"
                 placeholder="XXXX-XXXX" autocomplete="off" autocapitalize="characters" spellcheck="false" autofocus required>
        </div>
        <button type="submit" class="boton">Entrar</button>
      </form>
      <p class="nota-ingreso">Al entrar verás cuántos te quedan. Si quedan 3 o menos, genera nuevos en <strong>Mi cuenta</strong>.</p>
      <a class="enlace-ingreso" href="verificar.php">Usar la app autenticadora</a>
    <?php endif; ?>
    <a class="enlace-ingreso tenue" href="index.php">Volver al inicio</a>
<?php
vista_ingreso_cerrar(false);
