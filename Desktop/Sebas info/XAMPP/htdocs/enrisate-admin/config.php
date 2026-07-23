<?php
declare(strict_types=1);

/* =========================================================
   ENRÍSATE · Panel de administración
   Configuración central
   ========================================================= */

/* --- Identidad --- */
const APP_NOMBRE  = 'Enrísate';
const APP_TAGLINE = 'Cuidado del cabello';
const APP_CIUDAD  = 'Barranquilla';
const APP_VERSION = 'v0.1';

/* --- Cuenta de la administradora ---------------------------------------
   Clave inicial: Enrisate2026*
   CÁMBIALA antes de subir esto a un servidor:
       php tools/generar_hash.php "TuNuevaClave"
   y pega el resultado en ADMIN_HASH.
------------------------------------------------------------------------ */
const ADMIN_USUARIO = 'admin';
const ADMIN_NOMBRE  = 'Administradora';
const ADMIN_HASH    = '$2y$12$050ePq4.ZgMGoIKURU/VouuVfJv1jdPlNgmkIsMmY8FozBvqJMb6G';

/* --- Contacto --- */
// Número al que llegan los pedidos del catálogo público (sin +, con indicativo).
// Hoy es un número personal; cuando pasen a WhatsApp Business, cambia solo este valor.
const APP_WHATSAPP = '573163770326';

/* --- Seguridad --- */
const MAX_INTENTOS       = 5;    // intentos fallidos antes de bloquear
const BLOQUEO_SEGUNDOS   = 300;  // 5 minutos de bloqueo
const SESION_INACTIVIDAD = 1800; // 30 minutos sin actividad = cierre de sesión

/* --- Rutas --- */
define('BASE_DIR', __DIR__);
define('STORAGE_DIR', BASE_DIR . '/storage');
