-- =========================================================
-- ENRÍSATE · Panel de administración
-- Esquema completo de la base de datos (MySQL / MariaDB)
-- =========================================================
-- Crea la base "enrisate_admin" y sus tablas si todavía no existen.
-- No borra ni pisa nada: usa CREATE TABLE IF NOT EXISTS, así que se
-- puede importar varias veces sin riesgo.
--
-- Cómo usarlo en XAMPP: phpMyAdmin → pestaña Importar → elige este
-- archivo → Continuar.
--
-- En un servidor como Railway esto se aplica solo (ver
-- tools/aplicar_esquema.php), no hace falta hacerlo a mano.

CREATE DATABASE IF NOT EXISTS `enrisate_admin`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `enrisate_admin`;

CREATE TABLE IF NOT EXISTS `clientes` (
  `id` varchar(64) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `telefono` varchar(30) NOT NULL DEFAULT '',
  `notas` text NOT NULL,
  `saldo_favor` int(11) NOT NULL DEFAULT 0,
  `creado` varchar(40) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `productos` (
  `sku` varchar(32) NOT NULL,
  `marca` varchar(80) NOT NULL,
  `nombre` varchar(160) NOT NULL,
  `categoria` varchar(40) NOT NULL DEFAULT '',
  `precio` int(11) NOT NULL DEFAULT 0,
  `costo` int(11) NOT NULL DEFAULT 0,
  `stock` int(11) NOT NULL DEFAULT 0,
  `descripcion` text NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `facturas` (
  `numero` varchar(40) NOT NULL,
  `cliente` varchar(150) NOT NULL DEFAULT '',
  `telefono` varchar(30) NOT NULL DEFAULT '',
  `nota` text NOT NULL,
  `subtotal` int(11) NOT NULL DEFAULT 0,
  `descuento` int(11) NOT NULL DEFAULT 0,
  `total` int(11) NOT NULL DEFAULT 0,
  `costo` int(11) NOT NULL DEFAULT 0,
  `margen` int(11) NOT NULL DEFAULT 0,
  `plan` varchar(20) NOT NULL DEFAULT 'contado',
  `abono` int(11) NOT NULL DEFAULT 0,
  `saldo_aplicado` int(11) NOT NULL DEFAULT 0,
  `pagado` int(11) NOT NULL DEFAULT 0,
  `fecha_pago` varchar(40) DEFAULT NULL,
  `creada` varchar(40) NOT NULL DEFAULT '',
  `anulada` tinyint(1) NOT NULL DEFAULT 0,
  `anulada_el` varchar(40) DEFAULT NULL,
  `motivo_anulacion` text NOT NULL,
  PRIMARY KEY (`numero`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `factura_cuotas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `factura_numero` varchar(40) NOT NULL,
  `orden` int(11) NOT NULL DEFAULT 0,
  `monto` int(11) NOT NULL DEFAULT 0,
  `vence` varchar(40) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `factura_numero` (`factura_numero`),
  CONSTRAINT `factura_cuotas_ibfk_1` FOREIGN KEY (`factura_numero`) REFERENCES `facturas` (`numero`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `factura_lineas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `factura_numero` varchar(40) NOT NULL,
  `orden` int(11) NOT NULL DEFAULT 0,
  `sku` varchar(40) NOT NULL DEFAULT '',
  `nombre` varchar(150) NOT NULL DEFAULT '',
  `marca` varchar(80) NOT NULL DEFAULT '',
  `precio` int(11) NOT NULL DEFAULT 0,
  `costo` int(11) NOT NULL DEFAULT 0,
  `cant` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `factura_numero` (`factura_numero`),
  CONSTRAINT `factura_lineas_ibfk_1` FOREIGN KEY (`factura_numero`) REFERENCES `facturas` (`numero`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `factura_abonos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cuota_id` int(11) NOT NULL,
  `orden` int(11) NOT NULL DEFAULT 0,
  `monto` int(11) NOT NULL DEFAULT 0,
  `fecha` varchar(40) NOT NULL DEFAULT '',
  `tipo` varchar(20) NOT NULL DEFAULT 'abono',
  `motivo` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `cuota_id` (`cuota_id`),
  CONSTRAINT `factura_abonos_ibfk_1` FOREIGN KEY (`cuota_id`) REFERENCES `factura_cuotas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `pedidos` (
  `numero` varchar(40) NOT NULL,
  `cliente` varchar(150) NOT NULL,
  `telefono` varchar(30) NOT NULL DEFAULT '',
  `subtotal` int(11) NOT NULL DEFAULT 0,
  `total` int(11) NOT NULL DEFAULT 0,
  `plan` varchar(20) NOT NULL DEFAULT 'contado',
  `num_cuotas_preferido` int(11) DEFAULT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'pendiente',
  `factura_numero` varchar(40) DEFAULT NULL,
  `creado` varchar(40) NOT NULL,
  `confirmado_el` varchar(40) DEFAULT NULL,
  `descartado_el` varchar(40) DEFAULT NULL,
  `motivo_descartado` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`numero`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `pedido_lineas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `pedido_numero` varchar(40) NOT NULL,
  `orden` int(11) NOT NULL,
  `sku` varchar(40) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `marca` varchar(80) NOT NULL,
  `precio` int(11) NOT NULL DEFAULT 0,
  `cant` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pedido_numero` (`pedido_numero`,`orden`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Límite anti-spam del formulario de pedidos del catálogo público: un registro
-- por pedido aceptado, por IP real del visitante (ver includes/red.php). Antes
-- vivía en storage/pedidos_intentos.json, que se borraba con cada despliegue.
CREATE TABLE IF NOT EXISTS `limite_pedidos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip_clave` varchar(64) NOT NULL,
  `momento` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ip_momento` (`ip_clave`,`momento`),
  KEY `momento` (`momento`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------
-- Mensajes a las clientas (módulo "Mensajes", includes/mensajes.php).
--  · mensajes_permisos: si la clienta dijo que SÍ o que NO quiere recibir
--    mensajes automáticos de promociones y novedades. Sin fila = no ha respondido.
--  · mensajes_envios: a quién ya se le atendió cada campaña (enviado o saltado),
--    para no escribirle dos veces. Si se borra la clienta, se borran sus filas.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mensajes_permisos` (
  `cliente_id` varchar(64) NOT NULL,
  `permiso` enum('si','no') NOT NULL,
  `actualizado` varchar(40) NOT NULL DEFAULT '',
  `por` varchar(150) NOT NULL DEFAULT '',
  PRIMARY KEY (`cliente_id`),
  CONSTRAINT `mensajes_permisos_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `mensajes_envios` (
  `campana` varchar(40) NOT NULL,
  `cliente_id` varchar(64) NOT NULL,
  `estado` enum('enviado','saltado') NOT NULL,
  `momento` varchar(40) NOT NULL DEFAULT '',
  `por` varchar(150) NOT NULL DEFAULT '',
  PRIMARY KEY (`campana`,`cliente_id`),
  CONSTRAINT `mensajes_envios_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------
-- Ingreso al panel: usuarios con verificación en dos pasos
-- (contraseña + código de 6 dígitos de una app autenticadora).
-- Ver includes/usuarios.php e includes/auth.php.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `usuario` varchar(40) NOT NULL,
  `nombre` varchar(80) NOT NULL,
  `rol` enum('admin','ventas') NOT NULL DEFAULT 'ventas',
  `clave_hash` varchar(255) NOT NULL,
  `cambiar_clave` tinyint(1) NOT NULL DEFAULT 0,
  `totp_secreto` varchar(255) DEFAULT NULL,
  `totp_activo` tinyint(1) NOT NULL DEFAULT 0,
  `totp_ultimo_paso` bigint(20) NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `sesion_version` int(10) unsigned NOT NULL DEFAULT 1,
  `creado` datetime NOT NULL DEFAULT current_timestamp(),
  `ultimo_ingreso` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `usuario` (`usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `usuario_codigos_respaldo` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` int(10) unsigned NOT NULL,
  `huella` char(64) NOT NULL,
  `usado_el` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `codigos_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `intentos_ingreso` (
  `clave` varchar(120) NOT NULL,
  `fallos` int(10) unsigned NOT NULL DEFAULT 0,
  `bloqueado_hasta` int(10) unsigned NOT NULL DEFAULT 0,
  `ultimo` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`clave`),
  KEY `ultimo` (`ultimo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `registro_seguridad` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `momento` datetime NOT NULL DEFAULT current_timestamp(),
  `usuario_id` int(10) unsigned DEFAULT NULL,
  `usuario` varchar(60) NOT NULL DEFAULT '',
  `evento` varchar(40) NOT NULL,
  `detalle` varchar(255) NOT NULL DEFAULT '',
  `ip` varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `momento` (`momento`),
  KEY `usuario_id` (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
