-- =====================================================================
--  LUITECH - Base de datos MySQL (Laragon)
--  Importación manual: phpMyAdmin o HeidiSQL, o por consola:
--    mysql -u root --default-character-set=utf8mb4 < database/luitech.sql
-- =====================================================================

SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS luitech
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE luitech;

-- ---------------------------------------------------------------------
-- Usuarios administradores del panel de control
-- (login con password_hash de PHP / bcrypt)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios_admin (
    id            INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    usuario       VARCHAR(50)      NOT NULL UNIQUE,
    password_hash VARCHAR(255)     NOT NULL,
    nombre        VARCHAR(100)     NOT NULL DEFAULT '',
    creado_en     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Órdenes de trabajo del taller
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ordenes (
    id             INT UNSIGNED      AUTO_INCREMENT PRIMARY KEY,
    codigo         VARCHAR(12)       NOT NULL UNIQUE,
    cliente        VARCHAR(120)      NOT NULL,
    equipo         VARCHAR(120)      NOT NULL,
    tipo           ENUM('Celular','PC/Notebook','Otro') NOT NULL DEFAULT 'Celular',
    falla          TEXT              NOT NULL,
    estado         ENUM('Ingresado','En Diagnóstico','En Reparación','Listo para Retiro','Entregado')
                                     NOT NULL DEFAULT 'Ingresado',
    avance         TINYINT UNSIGNED  NOT NULL DEFAULT 10,
    tecnico        VARCHAR(80)       NOT NULL DEFAULT 'Por Asignar',
    pin_patron     VARCHAR(50)       NULL,
    accesorios     VARCHAR(255)      NULL,
    obs_recepcion  VARCHAR(250)      NULL,
    firma_ingreso  VARCHAR(255)      NULL,
    -- Cobro y cierre: valor de la reparación, pagos, garantía y entrega
    precio_repuestos INT UNSIGNED    NOT NULL DEFAULT 0,
    mano_obra        INT UNSIGNED    NOT NULL DEFAULT 0,
    total            INT UNSIGNED    NOT NULL DEFAULT 0,
    abono            INT UNSIGNED    NOT NULL DEFAULT 0,
    estado_pago    ENUM('Pendiente','Abonado','Pagado') NOT NULL DEFAULT 'Pendiente',
    metodo_pago    ENUM('Efectivo','Debito','Credito','Transferencia') NULL,
    garantia_dias  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    fecha_entrega  DATETIME          NULL,
    entregado_a    VARCHAR(120)      NULL,
    firma_entrega  VARCHAR(255)      NULL,
    fecha_ingreso  DATE              NOT NULL,
    creado_en      TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Fotos de respaldo del equipo al ingreso (evidencia por orden)
-- El archivo se guarda en uploads/ordenes/{codigo}/ y aquí solo su ruta.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orden_fotos (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    orden_codigo VARCHAR(12)  NOT NULL,
    archivo      VARCHAR(255) NOT NULL,
    creado_en    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (orden_codigo) REFERENCES ordenes(codigo) ON DELETE CASCADE,
    INDEX idx_of_orden (orden_codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Bitácora de reparación (historial técnico por orden: notas y cambios
-- de estado). Se borra en cascada junto con la orden.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orden_bitacora (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    orden_codigo VARCHAR(12)  NOT NULL,
    tecnico      VARCHAR(80)  NOT NULL DEFAULT '',
    nota         VARCHAR(500) NOT NULL,
    estado_nuevo VARCHAR(30)  NULL,
    creado_en    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (orden_codigo) REFERENCES ordenes(codigo) ON DELETE CASCADE,
    INDEX idx_ob_orden (orden_codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Semillas
-- Admin inicial:  usuario: admin   |   contraseña: luitech2026  (!CAMBIAR!)
-- ---------------------------------------------------------------------
INSERT INTO usuarios_admin (usuario, password_hash, nombre)
VALUES ('admin', '$2y$10$dexr0Src7HyB4oAmWadqw.SH3DfipUA9iVmFXtYgkUY/TcHk5U8Qm', 'Administrador Luitech');

INSERT INTO ordenes (codigo, cliente, equipo, tipo, falla, estado, avance, tecnico, fecha_ingreso) VALUES
('LUH-1024', 'Carlos Mendoza',   'iPhone 13 Pro',          'Celular',     'Cambio de Pantalla OLED',            'Listo para Retiro', 100, 'Sebastián R.', '2026-07-16'),
('LUH-1025', 'María Paz Rojas',  'Notebook Asus ROG',      'PC/Notebook', 'Mantenimiento térmico y limpieza',   'En Reparación',      60, 'Alexis M.',    '2026-07-16'),
('LUH-1026', 'Juan Pablo Cortés','Samsung S22 Ultra',      'Celular',     'Cambio de puerto de carga',          'En Diagnóstico',     30, 'Sebastián R.', '2026-07-17'),
('LUH-1027', 'Valentina Silva',  'PC de Escritorio Gamer', 'PC/Notebook', 'Instalación de Sistema y SSD',       'Listo para Retiro', 100, 'Alexis M.',    '2026-07-15'),
('LUH-1028', 'Pedro Aguilera',   'Xiaomi Redmi Note 11',   'Celular',     'Cambio de batería',                  'Ingresado',          10, 'Por Asignar',  '2026-07-17');
