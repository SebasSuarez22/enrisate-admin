<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mensajes.php';

requerir_sesion();

/* Módulo Mensajes (ver includes/mensajes.php). Sin ?c= muestra las campañas;
   con ?c=bienvenida muestra la cola de envío de esa campaña. */

$campana = (string) ($_GET['c'] ?? '');
if ($campana !== '' && !mensajes_campana_valida($campana)) {
    header('Location: mensajes.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campanaPost = (string) ($_POST['c'] ?? '');
    $cliente     = (string) ($_POST['cliente'] ?? '');
    $accion      = (string) ($_POST['accion'] ?? '');
    $quien       = (string) ($_SESSION['nombre'] ?? '');
    $viaFetch    = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

    $csrfOk = csrf_valido($_POST['csrf'] ?? null);
    $hecho  = false;
    if (!$csrfOk) {
        $error = 'La página estuvo abierta demasiado tiempo. Recárgala e intenta de nuevo.';
    } elseif (!mensajes_campana_valida($campanaPost)) {
        $error = 'No encontré esa campaña.';
    } else {
        $hecho = match ($accion) {
            'enviado'  => mensajes_marcar($campanaPost, $cliente, 'enviado', $quien),
            'saltado'  => mensajes_marcar($campanaPost, $cliente, 'saltado', $quien),
            'deshacer' => mensajes_deshacer($campanaPost, $cliente),
            'permiso'  => mensajes_poner_permiso($cliente, (string) ($_POST['valor'] ?? '-'), $quien),
            default    => false,
        };
    }

    // Petición del botón "Abrir WhatsApp" (fetch): solo se responde el código, sin página.
    if ($viaFetch) {
        http_response_code($hecho ? 204 : ($csrfOk ? 422 : 403));
        exit;
    }
    if ($hecho) {
        header('Location: mensajes.php?c=' . rawurlencode($campanaPost));
        exit;
    }
    if ($error === '') {
        $error = 'No pude guardar ese cambio. Recarga la página e intenta de nuevo.';
    }
    if (mensajes_campana_valida($campanaPost)) {
        $campana = $campanaPost;
    }
}

$token = csrf_token();

/** "3001234567" → "300 123 4567" */
function telefono_bonito(string $t): string
{
    return strlen($t) === 10 ? substr($t, 0, 3) . ' ' . substr($t, 3, 3) . ' ' . substr($t, 6) : $t;
}

/** Texto de WhatsApp (*negrita*) a HTML seguro, para la vista previa. */
function whatsapp_a_html(string $texto): string
{
    $h = nl2br(e($texto), false);
    return (string) preg_replace('/\*([^*]+)\*/u', '<strong>$1</strong>', $h);
}

$estado  = $campana !== '' ? mensajes_estado_clientas($campana) : mensajes_estado_clientas('bienvenida');
$resumen = mensajes_resumen($estado);
$cola    = mensajes_cola($estado);

$tituloPagina = 'Mensajes';
$barraVolver  = true;
require __DIR__ . '/includes/cabecera.php';
?>

<main class="lienzo">
<?php if ($campana === ''): ?>

  <h1 class="saludo">Mensajes</h1>
  <p class="subsaludo">Escríbele a tus clientas desde aquí. Tú revisas cada mensaje y tocas Enviar en WhatsApp.</p>

  <?php if ($error !== ''): ?><p class="aviso" role="alert"><?= e($error) ?></p><?php endif; ?>

  <section class="panel msg-destacado">
    <span class="msg-chip <?= $resumen['pendientes'] > 0 ? '' : 'listo' ?>"><?= $resumen['pendientes'] > 0 ? 'Lista para enviar' : 'Todo al día' ?></span>
    <h2 class="msg-titulo">Bienvenida</h2>
    <p class="msg-texto">Presenta el nuevo WhatsApp de Enrísate y el catálogo, y pregunta si quiere recibir promociones y novedades.</p>
    <p class="msg-dato"><strong><?= numero($resumen['pendientes']) ?></strong>
      <span><?= $resumen['pendientes'] === 1 ? 'clienta por escribir' : 'clientas por escribir' ?></span></p>
    <a class="boton" href="mensajes.php?c=bienvenida"><?= $resumen['pendientes'] > 0 ? 'Revisar y enviar' : 'Ver el historial' ?></a>
  </section>

  <section class="panel">
    <h2 class="titulo-panel">Próximamente</h2>
    <p class="msg-prox"><span>Recordatorio de cuota</span><span>Promoción del mes</span><span>Gracias por tu compra</span></p>
  </section>

  <section class="panel">
    <h2 class="titulo-panel">Historial</h2>
    <dl class="msg-cifras">
      <div><dt>Enviadas</dt><dd><?= numero($resumen['enviadas']) ?></dd></div>
      <div><dt>Quieren mensajes</dt><dd><?= numero($resumen['dijeron_si']) ?></dd></div>
      <div><dt>No quieren</dt><dd><?= numero($resumen['dijeron_no']) ?></dd></div>
      <div><dt>Sin celular válido</dt><dd><?= numero($resumen['sin_celular']) ?></dd></div>
    </dl>
  </section>

<?php else:
    $nombreCampana = MENSAJES_CAMPANAS[$campana];
    $siguiente = $cola[0] ?? null;
    $porcentaje = $resumen['elegibles'] > 0 ? (int) round($resumen['atendidas'] / $resumen['elegibles'] * 100) : 100;
    $atendidas = array_values(array_filter($estado, static fn(array $c): bool => $c['envio'] !== '' || $c['permiso'] !== ''));
    $sinCelular = array_values(array_filter($estado, static fn(array $c): bool => !$c['valido']));

    /** Un botón suelto que manda una acción por POST. */
    $accionForm = static function (string $accion, string $id, string $texto, string $clase = 'msg-mini', string $valor = '') use ($campana, $token): string {
        return '<form method="post" action="mensajes.php?c=' . e($campana) . '" class="msg-form">'
            . '<input type="hidden" name="csrf" value="' . e($token) . '">'
            . '<input type="hidden" name="c" value="' . e($campana) . '">'
            . '<input type="hidden" name="accion" value="' . e($accion) . '">'
            . '<input type="hidden" name="cliente" value="' . e($id) . '">'
            . ($valor !== '' || $accion === 'permiso' ? '<input type="hidden" name="valor" value="' . e($valor) . '">' : '')
            . '<button type="submit" class="' . e($clase) . '">' . e($texto) . '</button></form>';
    };
?>

  <a class="msg-volver" href="mensajes.php">← Mensajes</a>
  <h1 class="saludo"><?= e($nombreCampana) ?></h1>
  <p class="subsaludo">Se abre WhatsApp con el mensaje ya escrito. Tú tocas Enviar y vuelves aquí para seguir con la siguiente.</p>

  <?php if ($error !== ''): ?><p class="aviso" role="alert"><?= e($error) ?></p><?php endif; ?>

  <div class="msg-progreso" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $porcentaje ?>">
    <div class="msg-barra"><i style="width:<?= $porcentaje ?>%"></i></div>
    <small><?= numero($resumen['atendidas']) ?> de <?= numero($resumen['elegibles']) ?> atendidas</small>
  </div>

  <?php if ($siguiente !== null): ?>
    <section class="panel msg-destacado" aria-live="polite">
      <small class="msg-sigue">Sigue</small>
      <h2 class="msg-titulo"><?= e($siguiente['nombre']) ?></h2>
      <p class="msg-texto"><?= e(telefono_bonito($siguiente['telefono'])) ?></p>
      <div class="msg-burbuja"><?= whatsapp_a_html(mensaje_bienvenida($siguiente['nombre'])) ?></div>

      <form method="post" action="mensajes.php?c=<?= e($campana) ?>" id="formEnviado">
        <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <input type="hidden" name="c" value="<?= e($campana) ?>">
        <input type="hidden" name="accion" value="enviado">
        <input type="hidden" name="cliente" value="<?= e($siguiente['id']) ?>">
        <a class="boton" id="abrirWhatsapp" data-form="formEnviado" target="_blank" rel="noopener noreferrer"
           href="<?= e(enlace_whatsapp($siguiente['telefono'], mensaje_bienvenida($siguiente['nombre']))) ?>">Abrir WhatsApp y enviar</a>
        <button type="submit" class="msg-enlace">Ya la envié</button>
      </form>
      <?= $accionForm('saltado', $siguiente['id'], 'Saltar a la siguiente', 'boton fantasma') ?>
    </section>
  <?php else: ?>
    <section class="panel msg-destacado">
      <span class="msg-chip listo">Todo al día</span>
      <h2 class="msg-titulo">No queda nadie por escribir</h2>
      <p class="msg-texto">Cuando agregues clientas nuevas con celular, aparecerán aquí.</p>
    </section>
  <?php endif; ?>

  <?php if ($atendidas): ?>
    <section class="panel">
      <h2 class="titulo-panel">Ya atendidas y sus respuestas</h2>
      <p class="msg-texto">Cuando una clienta responda <strong>SÍ</strong> o <strong>NO</strong> a la pregunta, anótalo aquí. A quien dice NO no se le vuelve a escribir.</p>
      <ul class="msg-lista">
        <?php foreach ($atendidas as $c): ?>
          <li class="msg-fila">
            <div class="msg-quien">
              <b><?= e($c['nombre']) ?></b>
              <span>
                <?php if ($c['envio'] === 'enviado'): ?><span class="msg-etq">Enviada</span><?php endif; ?>
                <?php if ($c['envio'] === 'saltado'): ?><span class="msg-etq tenue">Saltada</span><?php endif; ?>
                <?php if ($c['permiso'] === 'si'): ?><span class="msg-etq ok">Quiere mensajes</span><?php endif; ?>
                <?php if ($c['permiso'] === 'no'): ?><span class="msg-etq no">No quiere</span><?php endif; ?>
              </span>
            </div>
            <div class="msg-botones">
              <?= $accionForm('permiso', $c['id'], 'Dijo SÍ', $c['permiso'] === 'si' ? 'msg-mini activo' : 'msg-mini', $c['permiso'] === 'si' ? '' : 'si') ?>
              <?= $accionForm('permiso', $c['id'], 'Dijo NO', $c['permiso'] === 'no' ? 'msg-mini activo' : 'msg-mini', $c['permiso'] === 'no' ? '' : 'no') ?>
              <?php if ($c['envio'] !== ''): ?><?= $accionForm('deshacer', $c['id'], 'Deshacer', 'msg-mini tenue') ?><?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <?php if ($sinCelular): ?>
    <section class="panel">
      <h2 class="titulo-panel">Sin celular válido</h2>
      <p class="msg-texto">A estas clientas no se les puede escribir por WhatsApp. Agrega su celular en <a href="clientes.php">Clientes</a>.</p>
      <p class="msg-prox"><?php foreach ($sinCelular as $c): ?><span><?= e($c['nombre']) ?></span><?php endforeach; ?></p>
    </section>
  <?php endif; ?>

  <script src="assets/js/mensajes.js?v=<?= filemtime(__DIR__ . '/assets/js/mensajes.js') ?>" defer></script>
<?php endif; ?>
</main>

</body>
</html>
