<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

/* =========================================================
   Usuarios (solo administración)
   =========================================================
   Quién puede entrar al panel y con qué rol. Toda acción que cambia el acceso
   de alguien (crear, desactivar, cambiar rol, contraseña temporal, restablecer
   la verificación) pide de nuevo el código de 6 dígitos de quien la hace y
   queda en el registro. Siempre deben quedar ADMINS_MINIMOS administradores.
   (OWASP ASVS V6.3 · V8 · V16 · re-autenticación para acciones sensibles)
   ========================================================= */

requerir_admin();
$yo = usuario_actual();

const ACCIONES_USUARIO = [
    'crear'       => 'Agregar usuario',
    'restablecer' => 'Restablecer la verificación',
    'temporal'    => 'Poner una contraseña temporal',
    'rol'         => 'Cambiar el rol',
    'desactivar'  => 'Desactivar',
    'activar'     => 'Activar',
];

/** Contraseña temporal fácil de dictar: 3 bloques de 4 sin caracteres que se confundan. */
function clave_temporal(): string
{
    $alfabeto = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $bloques = [];
    for ($b = 0; $b < 3; $b++) {
        $t = '';
        for ($i = 0; $i < 4; $i++) {
            $t .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }
        $bloques[] = $t;
    }
    return implode('-', $bloques);
}

$error    = '';
$ok       = '';
$temporal = null;   // [usuario, contraseña] para mostrar UNA vez

$accion = (string) ($_POST['accion'] ?? $_GET['confirmar'] ?? '');
$objId  = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
$obj    = $objId > 0 ? leer_usuario($objId) : null;
if (!isset(ACCIONES_USUARIO[$accion]) || ($accion !== 'crear' && !$obj)) {
    $accion = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion !== '') {
    $claves = claves_limite((string) $yo['usuario']);
    $espera = espera_ingreso($claves);
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'La página estuvo abierta demasiado tiempo. Recárgala e intenta de nuevo.';
    } elseif ($espera > 0) {
        $error = 'Demasiados intentos. Espera ' . (int) ceil($espera / 60) . ' minuto(s).';
    } elseif (!verificar_codigo_usuario($yo, (string) ($_POST['codigo'] ?? ''))) {
        registrar_fallo_ingreso($claves);
        registrar_evento('fallo_codigo', $yo, 'Al confirmar: ' . ACCIONES_USUARIO[$accion]);
        $error = mensaje_codigo_fallido(leer_usuario((int) $yo['id']) ?? $yo, (string) ($_POST['codigo'] ?? ''));
    } else {
        try {
            switch ($accion) {
                case 'crear':
                    $clave = clave_temporal();
                    $nuevo = crear_usuario(
                        (string) ($_POST['usuario'] ?? ''),
                        (string) ($_POST['nombre'] ?? ''),
                        (string) ($_POST['rol'] ?? 'ventas'),
                        $clave,
                        true,
                        $yo
                    );
                    $temporal = [leer_usuario($nuevo)['usuario'], $clave];
                    $ok = 'Listo: ' . trim((string) $_POST['nombre']) . ' ya tiene usuario.';
                    break;
                case 'temporal':
                    $clave = clave_temporal();
                    poner_clave_temporal($obj, $clave, $yo);
                    $temporal = [$obj['usuario'], $clave];
                    $ok = 'Contraseña temporal lista para ' . $obj['nombre'] . '.';
                    break;
                case 'restablecer':
                    restablecer_verificacion($obj, $yo);
                    $ok = 'Listo: ' . $obj['nombre'] . ' conectará su celular nuevo en su próximo ingreso y recibirá 10 códigos nuevos.';
                    break;
                case 'rol':
                    cambiar_rol($obj, (string) ($_POST['rol'] ?? ''), $yo);
                    $ok = 'Rol de ' . $obj['nombre'] . ' actualizado.';
                    break;
                case 'desactivar':
                case 'activar':
                    cambiar_activo($obj, $accion === 'activar', $yo);
                    $ok = $obj['nombre'] . ($accion === 'activar' ? ' puede volver a entrar.' : ' ya no puede entrar al panel.');
                    break;
            }
            $accion = '';
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    }
}

$usuarios = listar_usuarios();
$admins   = contar_admins_activos();
$registro = leer_registro(25);
$token    = csrf_token();

$tituloPagina = 'Usuarios';
$barraVolver  = true;
require __DIR__ . '/includes/cabecera.php';

/** "Hoy 9:12 a. m." / "Ayer 6:40 p. m." / "12 sep" */
function fecha_corta(?string $f): string
{
    if (!$f) {
        return 'Nunca';
    }
    $t = strtotime($f);
    $hora = date('g:i', $t) . (date('G', $t) < 12 ? ' a. m.' : ' p. m.');
    if (date('Y-m-d', $t) === date('Y-m-d')) {
        return 'Hoy ' . $hora;
    }
    if (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) {
        return 'Ayer ' . $hora;
    }
    $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    return (int) date('j', $t) . ' ' . $meses[(int) date('n', $t) - 1] . (date('Y', $t) !== date('Y') ? ' ' . date('Y', $t) : '');
}

function iniciales(string $nombre): string
{
    $partes = preg_split('/\s+/', trim($nombre)) ?: [];
    $ini = mb_substr($partes[0] ?? '', 0, 1) . (count($partes) > 1 ? mb_substr($partes[count($partes) - 1], 0, 1) : '');
    return mb_strtoupper($ini ?: '?');
}

/** Campo del código de 6 dígitos para confirmar una acción. */
function campo_codigo(): void
{
    ?>
    <div class="campo">
      <label for="codigoConfirmar">Para confirmar, escribe tu código de 6 dígitos</label>
      <input class="codigo-grande compacto" type="text" id="codigoConfirmar" name="codigo"
             inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" placeholder="000 000" required autofocus>
    </div>
    <?php
}
?>

<main class="lienzo ancho">
  <h1 class="saludo">Usuarios</h1>
  <p class="subsaludo">Quién puede entrar al panel y qué puede hacer. Cada persona entra con su usuario, su contraseña y el código de su app.</p>

  <?php if ($error !== ''): ?>
    <p class="aviso" role="alert"><?= e($error) ?></p>
  <?php endif; ?>
  <?php if ($ok !== ''): ?>
    <p class="aviso exito" role="status"><?= e($ok) ?></p>
  <?php endif; ?>

  <?php if ($temporal): ?>
    <section class="panel panel-temporal" aria-labelledby="tituloTemporal">
      <h2 class="titulo-panel" id="tituloTemporal">Contraseña temporal de <?= e($temporal[0]) ?></h2>
      <div class="clave-fila clave-temporal">
        <code id="claveTemporal"><?= e($temporal[1]) ?></code>
        <button type="button" class="salir" data-copiar="claveTemporal">Copiar</button>
      </div>
      <p class="subsaludo">Dásela en persona o por un medio seguro. Solo se muestra ahora. Al entrar con <strong><?= e($temporal[0]) ?></strong> y esta contraseña, tendrá que crear la suya y conectar su celular.</p>
    </section>
  <?php endif; ?>

  <p class="aviso <?= $admins >= ADMINS_MINIMOS ? 'neutro' : '' ?>">
    <strong><?= $admins ?> administrador<?= $admins === 1 ? '' : 'es' ?> activo<?= $admins === 1 ? '' : 's' ?>.</strong>
    <?= $admins >= ADMINS_MINIMOS
        ? 'Siempre deben quedar al menos ' . ADMINS_MINIMOS . ' con verificación, por eso no se puede desactivar a uno si solo quedan ' . ADMINS_MINIMOS . '.'
        : 'Agrega otro: si pierdes el celular y tus códigos, nadie más podría restablecer tu verificación.' ?>
  </p>

  <?php if ($accion !== '' && $accion !== 'crear' && $obj): ?>
    <!-- Confirmación de una acción sobre otra persona -->
    <section class="panel panel-confirmar" aria-labelledby="tituloConfirmar">
      <h2 class="titulo-confirmar" id="tituloConfirmar"><?= e(ACCIONES_USUARIO[$accion]) ?> · <?= e($obj['nombre']) ?></h2>
      <?php if ($accion === 'restablecer'): ?>
        <p>Úsalo si <?= e($obj['nombre']) ?> perdió o cambió el celular. Esto hace:</p>
        <ol class="lista-efectos">
          <li>Borra su autenticador y sus códigos de respaldo.</li>
          <li>Cierra las sesiones que tenga abiertas.</li>
          <li>En su próximo ingreso conecta el celular nuevo y recibe 10 códigos nuevos.</li>
        </ol>
      <?php elseif ($accion === 'temporal'): ?>
        <p>Si <?= e($obj['nombre']) ?> olvidó su contraseña: se crea una temporal que verás una sola vez. Al entrar tendrá que crear la suya. Su app autenticadora sigue igual.</p>
      <?php elseif ($accion === 'desactivar'): ?>
        <p><?= e($obj['nombre']) ?> no podrá entrar al panel y sus sesiones abiertas se cierran. Puedes volver a activarla cuando quieras.</p>
      <?php elseif ($accion === 'activar'): ?>
        <p><?= e($obj['nombre']) ?> podrá volver a entrar con su usuario, su contraseña y su código.</p>
      <?php endif; ?>
      <form method="post" action="usuarios.php" novalidate data-confirmar>
        <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <input type="hidden" name="accion" value="<?= e($accion) ?>">
        <input type="hidden" name="id" value="<?= (int) $obj['id'] ?>">
        <?php if ($accion === 'rol'): ?>
          <div class="campo">
            <label for="rolNuevo">Rol nuevo</label>
            <select id="rolNuevo" name="rol">
              <?php foreach (ROLES as $clave => $nombreRol): ?>
                <option value="<?= e($clave) ?>" <?= $obj['rol'] === $clave ? 'selected' : '' ?>><?= e($nombreRol) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="ayuda-campo">Administración: todo el panel y esta pantalla. Ventas: todo menos Usuarios.</p>
          </div>
        <?php endif; ?>
        <?php campo_codigo(); ?>
        <div class="acciones-confirmar">
          <a class="boton fantasma" href="usuarios.php">Cancelar</a>
          <button type="submit" class="boton<?= $accion === 'desactivar' ? ' peligro' : '' ?>"><?= e(ACCIONES_USUARIO[$accion]) ?></button>
        </div>
      </form>
    </section>
  <?php endif; ?>

  <section class="panel">
    <div class="panel-header">
      <h2 class="titulo-panel">Equipo</h2>
      <?php if ($accion !== 'crear'): ?>
        <a class="boton mini" href="usuarios.php?confirmar=crear#agregar">+ Agregar usuario</a>
      <?php endif; ?>
    </div>

    <?php if ($accion === 'crear'): ?>
      <form method="post" action="usuarios.php" class="form-agregar-usuario" id="agregar" novalidate data-confirmar>
        <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <input type="hidden" name="accion" value="crear">
        <div class="rejilla-campos">
          <div class="campo">
            <label for="uNombre">Nombre</label>
            <input type="text" id="uNombre" name="nombre" maxlength="80" value="<?= e((string) ($_POST['nombre'] ?? '')) ?>" placeholder="Gina" required>
          </div>
          <div class="campo">
            <label for="uUsuario">Usuario</label>
            <input type="text" id="uUsuario" name="usuario" maxlength="40" value="<?= e((string) ($_POST['usuario'] ?? '')) ?>" placeholder="gina" autocapitalize="none" spellcheck="false" required>
          </div>
          <div class="campo">
            <label for="uRol">Rol</label>
            <select id="uRol" name="rol">
              <?php foreach (ROLES as $clave => $nombreRol): ?>
                <option value="<?= e($clave) ?>" <?= ($_POST['rol'] ?? 'ventas') === $clave ? 'selected' : '' ?>><?= e($nombreRol) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <p class="ayuda-campo">Se crea con una contraseña temporal que verás una sola vez. Al entrar, la persona crea la suya y conecta su celular.</p>
        <?php campo_codigo(); ?>
        <div class="acciones-confirmar">
          <a class="boton fantasma" href="usuarios.php">Cancelar</a>
          <button type="submit" class="boton">Crear usuario</button>
        </div>
      </form>
    <?php endif; ?>

    <ul class="equipo">
      <li class="equipo-cabeza" aria-hidden="true">
        <span>Persona</span><span>Rol</span><span>Verificación</span><span>Último ingreso</span><span>Acciones</span>
      </li>
      <?php foreach ($usuarios as $u): $esYo = (int) $u['id'] === (int) $yo['id']; ?>
        <?php
          if ((int) $u['activo'] !== 1) {
              [$estClase, $estTexto] = ['off', 'Desactivada'];
          } elseif ((int) $u['totp_activo'] === 1) {
              [$estClase, $estTexto] = ['ok', 'Activa'];
          } else {
              [$estClase, $estTexto] = ['pen', 'Pendiente de configurar'];
          }
        ?>
        <li class="miembro<?= (int) $u['activo'] !== 1 ? ' inactivo' : '' ?>">
          <div class="persona">
            <span class="avatar <?= $u['rol'] === 'admin' ? 'admin' : '' ?>" aria-hidden="true"><?= e(iniciales((string) $u['nombre'])) ?></span>
            <span><?= e($u['nombre']) ?><small><?= e($u['usuario']) ?><?= $esYo ? ' · tú' : '' ?></small></span>
          </div>
          <div><span class="rol-chip <?= $u['rol'] === 'admin' ? 'admin' : '' ?>"><?= e(ROLES[$u['rol']] ?? $u['rol']) ?></span></div>
          <div><span class="estado <?= $estClase ?>"><?= e($estTexto) ?></span>
            <?php if ((int) $u['totp_activo'] === 1 && (int) $u['activo'] === 1): ?><small class="respaldos"><?= (int) $u['respaldos'] ?> códigos de respaldo</small><?php endif; ?>
          </div>
          <div class="ultimo"><span class="etiqueta-movil">Último ingreso: </span><?= e(fecha_corta($u['ultimo_ingreso'])) ?></div>
          <div class="acciones-miembro">
            <?php if ($esYo): ?>
              <a class="salir" href="mi-cuenta.php">Mi cuenta</a>
            <?php elseif ((int) $u['activo'] !== 1): ?>
              <a class="salir" href="usuarios.php?confirmar=activar&amp;id=<?= (int) $u['id'] ?>">Activar</a>
            <?php else: ?>
              <?php if ((int) $u['totp_activo'] === 1): ?>
                <a class="salir" href="usuarios.php?confirmar=restablecer&amp;id=<?= (int) $u['id'] ?>">Restablecer verificación</a>
              <?php endif; ?>
              <a class="salir" href="usuarios.php?confirmar=temporal&amp;id=<?= (int) $u['id'] ?>">Contraseña temporal</a>
              <a class="salir" href="usuarios.php?confirmar=rol&amp;id=<?= (int) $u['id'] ?>">Rol</a>
              <a class="salir peligro" href="usuarios.php?confirmar=desactivar&amp;id=<?= (int) $u['id'] ?>">Desactivar</a>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="panel">
    <h2 class="titulo-panel">Registro de ingresos y cambios</h2>
    <?php if (!$registro): ?>
      <p class="subsaludo">Todavía no hay movimientos.</p>
    <?php else: ?>
      <ul class="registro-seguridad">
        <?php foreach ($registro as $r):
            $esFallo = str_starts_with((string) $r['evento'], 'fallo') || $r['evento'] === 'bloqueo';
            $quien   = (string) ($r['nombre'] ?? '') !== '' ? (string) $r['nombre'] : ($r['usuario'] !== '' ? '“' . $r['usuario'] . '”' : 'alguien');
            $texto   = $esFallo
                ? (EVENTOS[$r['evento']] ?? $r['evento']) . ' para ' . $quien
                : $quien . ' ' . (EVENTOS[$r['evento']] ?? $r['evento']);
        ?>
          <li class="<?= $esFallo ? 'fallo' : '' ?>">
            <time datetime="<?= e((string) $r['momento']) ?>"><?= e(fecha_corta((string) $r['momento'])) ?></time>
            <span><?= e($texto) ?><?php if ($r['detalle'] !== ''): ?> <small>· <?= e($r['detalle']) ?></small><?php endif; ?></span>
            <em><?= e((string) $r['ip']) ?></em>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</main>

<script src="assets/js/ingreso.js?v=<?= filemtime(__DIR__ . '/assets/js/ingreso.js') ?>" defer></script>
</body>
</html>
