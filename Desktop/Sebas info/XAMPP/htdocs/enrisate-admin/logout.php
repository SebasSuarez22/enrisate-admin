<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

iniciar_sesion();
cerrar_sesion();

header('Location: index.php?motivo=salida');
exit;
