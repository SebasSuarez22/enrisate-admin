<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

/* Cerrar sesión: solo con el botón del panel (POST con su token), así ningún enlace
   de otra página puede sacar a alguien del panel a la fuerza. Un enlace viejo a
   logout.php (GET) solo lleva al panel. */

iniciar_sesion();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido($_POST['csrf'] ?? null)) {
    header('Location: ' . (hay_sesion() ? 'dashboard.php' : 'index.php'));
    exit;
}

$u = usuario_actual();
if ($u) {
    registrar_evento('salida', $u);
}
cerrar_sesion();

header('Location: index.php?motivo=salida');
exit;
