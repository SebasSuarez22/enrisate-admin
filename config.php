<?php
declare(strict_types=1);

/* =========================================================
   ENRÍSATE · Panel de administración
   Configuración central
   ========================================================= */

/* Sin esto, PHP usa la zona horaria del servidor (a veces UTC), y el
   saludo de "Buenos días/tardes/noches" del panel quedaba con la hora
   de otro país en vez de la de Colombia. Colombia no cambia de hora
   en el año, así que un solo valor fijo sirve siempre. */
date_default_timezone_set('America/Bogota');

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

// Usuario de Instagram, sin la @ y sin la URL: el catálogo público arma el
// enlace con esto en el pie de página.
const APP_INSTAGRAM = 'enrisate';

/* --- Pedidos desde el catálogo público --- */
// Total mínimo del carrito para que el catálogo público le ofrezca a la
// clienta pagar a cuotas (por debajo de esto, solo ve "de contado").
const UMBRAL_CUOTAS_PUBLICO = 70000;
// Cuántos pedidos como máximo puede mandar la misma IP en una hora
// (protección simple contra spam/bots en el formulario público).
const MAX_PEDIDOS_POR_HORA = 8;

/* --- Seguridad --- */
const MAX_INTENTOS       = 5;    // intentos fallidos antes de bloquear
const BLOQUEO_SEGUNDOS   = 300;  // 5 minutos de bloqueo
const SESION_INACTIVIDAD = 1800; // 30 minutos sin actividad = cierre de sesión

/* --- Rutas --- */
define('BASE_DIR', __DIR__);
define('STORAGE_DIR', BASE_DIR . '/storage');

/* --- Base de datos (MySQL de XAMPP) ---------------------------------------
   Valores por defecto de XAMPP: host 127.0.0.1, usuario root, sin contraseña.
   Si tu XAMPP tiene otra configuración (otro puerto, contraseña de root,
   etc.), cambia solo estas constantes.
------------------------------------------------------------------------ */
const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NOMBRE = 'enrisate_admin';
const DB_USUARIO = 'root';
const DB_CLAVE = '';
