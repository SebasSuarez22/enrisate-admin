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

/* --- Primera cuenta del panel ------------------------------------------
   Cada persona entra con su propio usuario, su contraseña y el código de 6
   dígitos de su app autenticadora (ver includes/auth.php e includes/usuarios.php).
   Esta cuenta solo se usa UNA vez: cuando la tabla de usuarios está vacía, se
   convierte en el primer usuario administrador.
     · En XAMPP local: usuario admin, clave Enrisate2026* (pide cambiarla al entrar).
     · En Railway: solo si la variable de entorno ADMIN_HASH está definida
       (php tools/generar_hash.php "TuClave"); la clave de arriba nunca abre el
       panel en producción.
   ADMIN_HASH también firma los enlaces de los PDF de factura (includes/facturas.php):
   no se cambia sin necesidad, o los enlaces ya enviados dejan de abrir.
   La verificación en dos pasos necesita además APP_SECRETO (ver includes/cifrado.php).
------------------------------------------------------------------------ */
const ADMIN_USUARIO = 'admin';
const ADMIN_NOMBRE  = 'Administradora';
define('ADMIN_HASH', getenv('ADMIN_HASH') ?: '$2y$12$050ePq4.ZgMGoIKURU/VouuVfJv1jdPlNgmkIsMmY8FozBvqJMb6G');

/* --- Contacto --- */
// Número al que llegan los pedidos del catálogo público (sin +, con indicativo).
// Es el WhatsApp Business de Enrísate (+57 305 241 8417). Si algún día cambia,
// cambia solo este valor: el catálogo arma todos sus enlaces a partir de aquí.
const APP_WHATSAPP = '573052418417';

// Enlace oficial del catálogo público: es el que va en el mensaje de bienvenida
// (módulo Mensajes). Cuando haya dominio propio, cambia solo este valor.
const APP_URL_CATALOGO = 'https://enrisate-admin-production.up.railway.app/catalogo-publico.php';

// Usuario de Instagram, sin la @ y sin la URL: el catálogo público arma el
// enlace con esto en el pie de página.
const APP_INSTAGRAM = 'enrisate';

/* --- Pedidos desde el catálogo público --- */
// Total mínimo del carrito para que el catálogo público le ofrezca a la
// clienta pagar a cuotas (por debajo de esto, solo ve "de contado").
const UMBRAL_CUOTAS_PUBLICO = 70000;
// "1 cuota con plazo": la clienta paga todo en una sola fecha que ella escoge, de mañana
// a este número de días (el catálogo y pedido-publico.php usan el mismo límite).
const PLAZO_MAX_DIAS_PUBLICO = 30;
// Cuántos pedidos como máximo puede mandar la misma IP en una hora
// (protección simple contra spam/bots en el formulario público).
const MAX_PEDIDOS_POR_HORA = 8;

/* --- Promoción del catálogo público --- */
// Por la compra de N o más productos (unidades en total) hay un X % de
// descuento en toda la compra. Lo anuncia el aviso de arriba del catálogo
// público y lo aplican el carrito, el pedido guardado y la cuenta de cobro.
const PROMO_MINIMO_UNIDADES = 3;
const PROMO_PORCENTAJE      = 10;

/* --- Seguridad ---
   Límite de intentos del ingreso: includes/usuarios.php (por cuenta y por IP real).
   Sesión máxima de 8 h y pre-ingreso de 10 min: includes/auth.php. */
const SESION_INACTIVIDAD = 1800; // 30 minutos sin actividad = cierre de sesión

/* --- Rutas --- */
define('BASE_DIR', __DIR__);
define('STORAGE_DIR', BASE_DIR . '/storage');

/* --- Errores: aviso genérico en pantalla, detalle al registro (ver el archivo) --- */
require_once __DIR__ . '/includes/errores.php';

/* --- Base de datos (MySQL) -------------------------------------------------
   En un XAMPP local, sin nada más que hacer, usa lo típico de XAMPP:
   127.0.0.1, usuario root, sin contraseña. En un servidor como Railway,
   estas mismas constantes toman el valor de las variables de entorno que
   Railway ya deja listas al conectar una base de datos MySQL al proyecto
   (MYSQLHOST, MYSQLPORT, MYSQLUSER, MYSQLPASSWORD, MYSQLDATABASE) — no
   hay que tocar este archivo para eso.
------------------------------------------------------------------------ */
define('DB_HOST',   getenv('MYSQLHOST') ?: '127.0.0.1');
define('DB_PORT',   (int) (getenv('MYSQLPORT') ?: 3306));
define('DB_NOMBRE', getenv('MYSQLDATABASE') ?: 'enrisate_admin');
define('DB_USUARIO', getenv('MYSQLUSER') ?: 'root');
define('DB_CLAVE',   getenv('MYSQLPASSWORD') ?: '');
