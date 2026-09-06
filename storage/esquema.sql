-- =========================================================
-- ENRÍSATE · Panel de administración
-- Esquema completo de la base de datos (MySQL / MariaDB)
-- =========================================================
-- Crea la base "enrisate_admin" y sus 8 tablas si todavía no existen.
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
  `notas` text NOT NULL DEFAULT '',
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
  `descripcion` text NOT NULL DEFAULT '',
  PRIMARY KEY (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `facturas` (
  `numero` varchar(40) NOT NULL,
  `cliente` varchar(150) NOT NULL DEFAULT '',
  `telefono` varchar(30) NOT NULL DEFAULT '',
  `nota` text NOT NULL DEFAULT '',
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
  `motivo_anulacion` text NOT NULL DEFAULT '',
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
  `motivo` text NOT NULL DEFAULT '',
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
  `pedido_numero` varchar(40) NOT NULL,
  `orden` int(11) NOT NULL,
  `sku` varchar(40) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `marca` varchar(80) NOT NULL,
  `precio` int(11) NOT NULL DEFAULT 0,
  `cant` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`pedido_numero`,`orden`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
