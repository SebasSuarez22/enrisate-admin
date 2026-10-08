<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ingreso_vista.php';

/* Primera vez (o después de que administración restableció la verificación):
   se conecta el celular con la app autenticadora y se entregan los 10 códigos
   de respaldo.
     · En computador: QR para escanear con el celular.
     · En el celular: botón que abre la app con la cuenta ya cargada.
     · En ambos: la clave de configuración para escribirla o pegarla a mano.
   El secreto queda pendiente en la sesión (cifrado) hasta que la persona prueba
   un código de la app; solo entonces se guarda y se activa. */

$u = requerir_preingreso('configurar-verificacion.php');

$error   = '';
$rechazo = false;

try {
    /* --- Fase 2: mostrar los códigos de respaldo recién creados --- */
    if (!empty($_SESSION['codigos_nuevos'])) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'listo') {
            if (csrf_valido($_POST['csrf'] ?? null) && !empty($_POST['guardados'])) {
                unset($_SESSION['codigos_nuevos']);
                avanzar_ingreso($u);
            }
            $error   = empty($_POST['guardados']) ? 'Marca la casilla cuando los hayas guardado.' : 'La página estuvo abierta demasiado tiempo. Recárgala.';
            $rechazo = true;
        }
        $token = csrf_token();
        vista_ingreso_abrir('Tus códigos de respaldo', pasos_primera_vez(), count(pasos_primera_vez()), $rechazo);
        ?>
        <p class="aviso exito" role="status">Listo: tu cuenta ya pide el código de la app al entrar.</p>
        <h1 class="titulo-ingreso">Guarda tus códigos de respaldo</h1>
        <p class="sub-ingreso">Te sirven si pierdes el celular. Cada uno sirve una sola vez. <strong>Solo los verás ahora.</strong></p>
        <?php vista_aviso($error); ?>
        <?php vista_codigos_respaldo($_SESSION['codigos_nuevos'], (string) $u['usuario']); ?>
        <form method="post" action="configurar-verificacion.php" novalidate>
          <input type="hidden" name="csrf" value="<?= e($token) ?>">
          <input type="hidden" name="accion" value="listo">
          <label class="casilla">
            <input type="checkbox" name="guardados" value="1" required data-habilita="entrarPanel">
            <span>Los guardé en un lugar seguro, fuera de este celular.</span>
          </label>
          <button type="submit" class="boton" id="entrarPanel">Ir al panel</button>
        </form>
        <?php
        vista_ingreso_cerrar(false);
        exit;
    }

    /* --- Fase 1: conectar el celular --- */
    if (empty($_SESSION['pre']['secreto'])) {
        $_SESSION['pre']['secreto'] = cifrar(totp_nuevo_secreto());
    }
    $secreto = descifrar((string) $_SESSION['pre']['secreto']);
    if ($secreto === null) {
        unset($_SESSION['pre']['secreto']);
        header('Location: configurar-verificacion.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $rechazo = true;
        $claves  = claves_limite((string) $u['usuario']);
        $espera  = espera_ingreso($claves);
        if (!csrf_valido($_POST['csrf'] ?? null)) {
            $error = 'La página estuvo abierta demasiado tiempo. Recárgala e intenta de nuevo.';
        } elseif ($espera > 0) {
            $error = texto_espera($espera);
        } else {
            $paso = totp_verificar($secreto, (string) ($_POST['codigo'] ?? ''));
            if ($paso !== null) {
                activar_verificacion($u, $secreto, $paso);
                $codigos = generar_codigos_respaldo($u);
                registrar_evento('codigos_generados', $u, 'Al conectar su app');
                unset($_SESSION['pre']['secreto']);
                marcar_codigo_ok('ingreso');
                $_SESSION['codigos_nuevos'] = $codigos;
                header('Location: configurar-verificacion.php');
                exit;
            }
            registrar_fallo_ingreso($claves);
            registrar_evento('fallo_codigo', $u, 'Al conectar su app');
            $espera = espera_ingreso($claves);
            $error  = $espera > 0
                ? texto_espera($espera)
                : 'Ese código no coincide. Escribe el que aparece ahora debajo de “Enrísate” y revisa que la hora del celular esté en automático.';
        }
    }
} catch (LlaveFaltante $e) {
    error_log('[enrisate] ' . $e->getMessage());
    vista_ingreso_no_disponible();
}

$b32   = base32_codificar($secreto);
$uri   = totp_uri((string) $u['usuario'], $b32);
$token = csrf_token();

vista_ingreso_abrir('Conecta tu celular', pasos_primera_vez(), count(pasos_primera_vez()) - 1, $rechazo);
?>
    <h1 class="titulo-ingreso">Conecta tu celular</h1>
    <p class="sub-ingreso sub-conectar">Solo esta vez. Instala <strong>Google Authenticator</strong> (gratis, en Play Store o App Store) y agrega tu cuenta.</p>
    <?php vista_aviso($error); ?>

    <!-- En el celular: botón que abre la app con la cuenta ya cargada -->
    <a class="boton-app" href="<?= e($uri) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="2.5" width="12" height="19" rx="3"/><path d="M12 8v6M9 11h6"/></svg>
      Agregar a Google Authenticator
    </a>
    <p class="nota-ingreso solo-tactil">Se abre la app con Enrísate ya cargado. Luego vuelve aquí y escribe el código.</p>

    <!-- En computador: QR para escanear con el celular -->
    <div class="qr-ingreso">
      <div class="qr-caja"><?= qr_svg($uri) ?></div>
      <p>En la app toca <strong>“+”</strong> → <strong>“Escanear un código QR”</strong> y apunta a este código.</p>
    </div>

    <!-- La clave sigue disponible para quien no puede escanear; va plegada para que la pantalla quepa sin scroll -->
    <details class="clave-plegable">
      <summary>¿No puedes escanear? Usa la clave</summary>
      <div class="clave-config">
        <span class="clave-titulo">Escribe o pega esta clave en la app (“+” → “Ingresar clave de configuración”):</span>
        <div class="clave-fila">
          <code id="claveConfig"><?= e(totp_clave_legible($b32)) ?></code>
          <button type="button" class="salir" data-copiar="claveConfig" data-valor="<?= e($b32) ?>">Copiar</button>
        </div>
      </div>
    </details>

    <form method="post" action="configurar-verificacion.php" novalidate data-codigo>
      <input type="hidden" name="csrf" value="<?= e($token) ?>">
      <div class="campo">
        <label for="codigo">Código que aparece en la app</label>
        <input class="codigo-grande compacto" type="text" id="codigo" name="codigo"
               inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" placeholder="000 000" required>
      </div>
      <button type="submit" class="boton">Continuar</button>
    </form>
    <a class="enlace-ingreso tenue" href="index.php">Volver al inicio</a>
<?php
vista_ingreso_cerrar(false);
