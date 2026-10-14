<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/clientes.php';
require_once __DIR__ . '/includes/facturas.php';

requerir_sesion();

$error = '';
$ok    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'La página estuvo abierta demasiado tiempo. Recárgala e intenta de nuevo.';
    } elseif (($_POST['accion'] ?? '') === 'sincronizar') {
        $agregadas = sincronizar_clientes_desde_facturas(leer_facturas());
        header('Location: clientes.php?ok=sync&n=' . $agregadas);
        exit;
    } elseif (($_POST['accion'] ?? '') === 'editar') {
        $mensaje = actualizar_cliente(
            (string) ($_POST['id'] ?? ''),
            (string) ($_POST['nombre'] ?? ''),
            (string) ($_POST['telefono'] ?? ''),
            (string) ($_POST['notas'] ?? ''),
            (int) ($_POST['saldo_favor'] ?? 0)
        );
        if ($mensaje === '') {
            header('Location: clientes.php?ok=editada&sel=' . rawurlencode((string) ($_POST['id'] ?? '')));
            exit;
        }
        $error = $mensaje;
    } elseif (($_POST['accion'] ?? '') === 'eliminar') {
        $ids      = array_filter(array_map('trim', explode(',', (string) ($_POST['ids'] ?? ''))));
        $borradas = eliminar_clientes($ids);
        if ($borradas > 0) {
            header('Location: clientes.php?ok=eliminadas&n=' . $borradas);
            exit;
        }
        $error = 'Selecciona al menos un cliente para eliminar.';
    } else {
        $mensaje = agregar_cliente(
            (string) ($_POST['nombre'] ?? ''),
            (string) ($_POST['telefono'] ?? ''),
            (string) ($_POST['notas'] ?? '')
        );
        if ($mensaje === '') {
            // En escritorio, el directorio abre la ficha de la clienta recién agregada.
            $nueva = buscar_cliente_coincidente(leer_clientes(), trim((string) ($_POST['nombre'] ?? '')), normalizar_telefono((string) ($_POST['telefono'] ?? '')));
            header('Location: clientes.php?ok=1' . ($nueva ? '&sel=' . rawurlencode((string) $nueva['id']) : ''));
            exit;
        }
        $error = $mensaje;
    }
}

if (($_GET['ok'] ?? '') === '1') {
    $ok = 'Cliente agregado a la lista.';
} elseif (($_GET['ok'] ?? '') === 'editada') {
    $ok = 'Datos del cliente actualizados.';
} elseif (($_GET['ok'] ?? '') === 'eliminadas') {
    $n  = (int) ($_GET['n'] ?? 0);
    $ok = 'Se eliminó ' . $n . ' cliente' . ($n === 1 ? '' : 's') . ' de la lista.';
} elseif (($_GET['ok'] ?? '') === 'sync') {
    $n  = (int) ($_GET['n'] ?? 0);
    $ok = $n > 0
        ? 'Se agregaron ' . $n . ' cliente' . ($n === 1 ? '' : 's') . ' que ya estaban en facturación.'
        : 'Ya estaban todos: no había clientes nuevos por agregar desde facturación.';
}

// El directorio (escritorio, tablet y celular): de la A a la Z sin que las tildes desordenen, y la ficha de cada una ya calculada.
$clientesAZ = leer_clientes();
usort($clientesAZ, fn($a, $b) => strcmp(clave_nombre_cliente((string) $a['nombre']), clave_nombre_cliente((string) $b['nombre'])));
$dirDatos = datos_directorio_clientes($clientesAZ, leer_facturas());

$token = csrf_token();

$tituloPagina = 'Clientes';
$barraVolver  = true;
require __DIR__ . '/includes/cabecera.php';
?>

<main class="lienzo ancho clientes">
  <h1 class="saludo">Clientes</h1>
  <p class="subsaludo">La lista de clientes: nombre, teléfono y notas. Ve agregándolos aquí a medida que los tengas.</p>

  <?php if ($error !== ''): ?>
    <p class="aviso" role="alert"><?= e($error) ?></p>
  <?php endif; ?>
  <?php if ($ok !== ''): ?>
    <p class="aviso neutro"><?= e($ok) ?></p>
  <?php endif; ?>

  <?php require __DIR__ . '/includes/clientes_directorio.php'; ?>

</main>

</body>
</html>
