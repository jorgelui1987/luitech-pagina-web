<?php
/**
 * Migración automática e idempotente al arranque del contenedor.
 * Crea las tablas si no existen y siembra datos iniciales (INSERT IGNORE),
 * usando las variables de entorno DB_* definidas en el panel (Dokploy).
 */
declare(strict_types=1);

require __DIR__ . '/../api/config.php';

$maxIntentos = 12;
$pdo = null;

for ($i = 1; $i <= $maxIntentos; $i++) {
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 4]
        );
        echo "[migrate] Conectado a " . DB_HOST . ":" . DB_PORT . "/" . DB_NAME . "\n";
        break;
    } catch (PDOException $e) {
        if ($i === $maxIntentos) {
            fwrite(STDERR, '[migrate] BD no disponible tras ' . $maxIntentos . " intentos: " . $e->getMessage() . "\n");
            exit(1);
        }
        echo "[migrate] Esperando base de datos ({$i}/{$maxIntentos})...\n";
        sleep(5);
    }
}

// --- Tabla de administradores -----------------------------------------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS usuarios_admin (
        id            INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
        usuario       VARCHAR(50)      NOT NULL UNIQUE,
        password_hash VARCHAR(255)     NOT NULL,
        nombre        VARCHAR(100)     NOT NULL DEFAULT '',
        creado_en     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Admin inicial (usuario admin · clave luitech2026 — cambiar tras primer login)
$pdo->prepare('INSERT IGNORE INTO usuarios_admin (usuario, password_hash, nombre) VALUES (?, ?, ?)')
    ->execute(['admin', '$2y$10$dexr0Src7HyB4oAmWadqw.SH3DfipUA9iVmFXtYgkUY/TcHk5U8Qm', 'Administrador Luitech']);

// --- Tabla de órdenes ---------------------------------------------------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS ordenes (
        id             INT UNSIGNED      AUTO_INCREMENT PRIMARY KEY,
        codigo         VARCHAR(12)       NOT NULL UNIQUE,
        cliente        VARCHAR(120)      NOT NULL,
        equipo         VARCHAR(120)      NOT NULL,
        tipo           ENUM('Celular','PC/Notebook','Otro') NOT NULL DEFAULT 'Celular',
        falla          TEXT              NOT NULL,
        estado         ENUM('Ingresado','En Diagnóstico','En Reparación','Listo para Retiro')
                                         NOT NULL DEFAULT 'Ingresado',
        avance         TINYINT UNSIGNED  NOT NULL DEFAULT 10,
        tecnico        VARCHAR(80)       NOT NULL DEFAULT 'Por Asignar',
        fecha_ingreso  DATE              NOT NULL,
        creado_en      TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        actualizado_en TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_estado (estado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Semilla demo (INSERT IGNORE: no duplica ni pisa datos reales)
$ordenStmt = $pdo->prepare(
    'INSERT IGNORE INTO ordenes (codigo, cliente, equipo, tipo, falla, estado, avance, tecnico, fecha_ingreso)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$semillas = [
    ['LUH-1024', 'Carlos Mendoza',    'iPhone 13 Pro',          'Celular',     'Cambio de Pantalla OLED',          'Listo para Retiro', 100, 'Sebastián R.', '2026-07-16'],
    ['LUH-1025', 'María Paz Rojas',   'Notebook Asus ROG',      'PC/Notebook', 'Mantenimiento térmico y limpieza', 'En Reparación',      60, 'Alexis M.',    '2026-07-16'],
    ['LUH-1026', 'Juan Pablo Cortés', 'Samsung S22 Ultra',      'Celular',     'Cambio de puerto de carga',        'En Diagnóstico',     30, 'Sebastián R.', '2026-07-17'],
    ['LUH-1027', 'Valentina Silva',   'PC de Escritorio Gamer', 'PC/Notebook', 'Instalación de Sistema y SSD',     'Listo para Retiro', 100, 'Alexis M.',    '2026-07-15'],
    ['LUH-1028', 'Pedro Aguilera',    'Xiaomi Redmi Note 11',   'Celular',     'Cambio de batería',                'Ingresado',          10, 'Por Asignar',  '2026-07-17'],
];
foreach ($semillas as $s) {
    $ordenStmt->execute($s);
}

// --- Tabla de inventario ----------------------------------------------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS productos (
        id              INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
        codigo          VARCHAR(30)      NOT NULL UNIQUE,
        nombre          VARCHAR(120)     NOT NULL,
        categoria       VARCHAR(60)      NOT NULL DEFAULT 'Repuesto',
        precio_costo    INT UNSIGNED     NOT NULL DEFAULT 0,
        precio_venta    INT UNSIGNED     NOT NULL DEFAULT 0,
        stock           INT              NOT NULL DEFAULT 0,
        stock_minimo    INT UNSIGNED     NOT NULL DEFAULT 3,
        controlar_stock TINYINT(1)       NOT NULL DEFAULT 1,
        activo          TINYINT(1)       NOT NULL DEFAULT 1,
        creado_en       TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// --- Ventas (cabecera) -------------------------------------------------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS ventas (
        id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
        numero       VARCHAR(15)   NOT NULL UNIQUE,
        vendedor     VARCHAR(80)   NOT NULL DEFAULT 'Mostrador',
        cliente      VARCHAR(120)  NOT NULL DEFAULT 'Publico General',
        total        INT UNSIGNED  NOT NULL DEFAULT 0,
        medio_pago   ENUM('Efectivo','Debito','Credito','Transferencia') NOT NULL DEFAULT 'Efectivo',
        orden_codigo VARCHAR(12)   NULL,
        creado_en    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// --- Detalle de cada venta ---------------------------------------------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS venta_items (
        id             INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
        venta_id       INT UNSIGNED  NOT NULL,
        producto_id    INT UNSIGNED  NULL,
        descripcion    VARCHAR(150)  NOT NULL,
        cantidad       INT UNSIGNED  NOT NULL DEFAULT 1,
        precio_unitario INT UNSIGNED NOT NULL DEFAULT 0,
        FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE,
        INDEX idx_vi_venta (venta_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Semillas de productos demo (INSERT IGNORE: no duplica ni pisa datos reales)
$prodStmt = $pdo->prepare(
    'INSERT IGNORE INTO productos (codigo, nombre, categoria, precio_costo, precio_venta, stock, stock_minimo, controlar_stock)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$productosSemilla = [
    ['CT-GNRL',  'Cristal templado genérico',            'Accesorios', 1200, 3000, 25, 5, 1],
    ['FUN-SIL',  'Funda silicona variada',               'Accesorios', 2500, 5000, 20, 4, 1],
    ['CAR-USBC', 'Cargador USB-C 20W',                   'Accesorios', 4500, 9000, 10, 2, 1],
    ['CAB-LGTN', 'Cable Lightning 1m',                   'Accesorios', 3800, 7000, 12, 3, 1],
    ['MIC-HIDRO','Mica hidrogel (instalada)',            'Servicios',  1000, 6000,  0, 0, 0],
    ['MAN-EXPR', 'Mantención express celular',           'Servicios',     0,12000,  0, 0, 0],
];
foreach ($productosSemilla as $p) {
    $prodStmt->execute($p);
}

// --- Caja diaria --------------------------------------------------------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS caja_sesiones (
        id             INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
        abierta_por    VARCHAR(80)      NOT NULL DEFAULT 'Mostrador',
        monto_apertura INT UNSIGNED     NOT NULL DEFAULT 0,
        apertura_ts    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        cierre_ts      DATETIME         NULL,
        monto_cierre   INT UNSIGNED     NULL,
        diferencia     INT              NULL,
        estado         ENUM('Abierta','Cerrada') NOT NULL DEFAULT 'Abierta'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// --- Movimientos de caja (ventas efectivo + manuales) -------------------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS movimientos_caja (
        id        INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
        sesion_id INT UNSIGNED  NOT NULL,
        tipo      ENUM('Ingreso','Egreso') NOT NULL,
        concepto  VARCHAR(200)  NOT NULL,
        monto     INT UNSIGNED  NOT NULL,
        venta_id  INT UNSIGNED  NULL,
        creado_en TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sesion_id) REFERENCES caja_sesiones(id) ON DELETE CASCADE,
        INDEX idx_mc_sesion (sesion_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// --- Gastos del negocio ---------------------------------------------------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS gastos (
        id        INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
        concepto  VARCHAR(200)  NOT NULL,
        categoria VARCHAR(60)   NOT NULL DEFAULT 'General',
        monto     INT UNSIGNED  NOT NULL,
        fecha     DATE          NOT NULL,
        creado_en TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_gastos_fecha (fecha)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// --- GRUPO: módulos ERP (Fase 3) — helpers y columnas nuevas ------------
$tieneCol = function ($tabla, $col) use ($pdo) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$tabla, $col]);
    return ((int)$st->fetchColumn()) > 0;
};
if (!$tieneCol('ventas', 'cliente_id')) { $pdo->exec("ALTER TABLE ventas ADD COLUMN cliente_id INT UNSIGNED NULL"); }
if (!$tieneCol('ordenes', 'cliente_id')) { $pdo->exec("ALTER TABLE ordenes ADD COLUMN cliente_id INT UNSIGNED NULL"); }
if (!$tieneCol('usuarios_admin', 'rol')) {
    $pdo->exec("ALTER TABLE usuarios_admin ADD COLUMN rol ENUM('admin','vendedor','tecnico') NOT NULL DEFAULT 'admin'");
}

$pdo->exec("CREATE TABLE IF NOT EXISTS clientes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rut VARCHAR(15) NOT NULL UNIQUE, nombre VARCHAR(120) NOT NULL,
    telefono VARCHAR(25) DEFAULT '', email VARCHAR(120) DEFAULT '', direccion VARCHAR(160) DEFAULT '',
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->prepare('INSERT IGNORE INTO clientes (rut, nombre, telefono) VALUES (?, ?, ?)')
    ->execute(['11111111-1', 'Cliente Demo', '+56912345678']);

$pdo->exec("CREATE TABLE IF NOT EXISTS proveedores (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(120) NOT NULL,
    contacto VARCHAR(120) DEFAULT '', telefono VARCHAR(25) DEFAULT '',
    email VARCHAR(120) DEFAULT '', notas VARCHAR(255) DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS compras (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, numero VARCHAR(15) NOT NULL UNIQUE,
    proveedor_id INT UNSIGNED NOT NULL, total INT UNSIGNED NOT NULL DEFAULT 0,
    estado ENUM('Pendiente','Recibida') NOT NULL DEFAULT 'Pendiente',
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_compra_prov (proveedor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS compra_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, compra_id INT UNSIGNED NOT NULL,
    producto_id INT UNSIGNED NULL, descripcion VARCHAR(150) NOT NULL,
    cantidad INT UNSIGNED NOT NULL DEFAULT 1, costo_unitario INT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE,
    INDEX idx_ci_compra (compra_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS movimientos_stock (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, producto_id INT UNSIGNED NOT NULL,
    tipo ENUM('Entrada','Salida','Ajuste') NOT NULL, cantidad INT UNSIGNED NOT NULL DEFAULT 1,
    motivo VARCHAR(200) DEFAULT '', ref_tipo VARCHAR(30) DEFAULT 'manual', ref_id INT UNSIGNED NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_ms_prod (producto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS devoluciones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, venta_num VARCHAR(15) NOT NULL,
    descripcion VARCHAR(150) NOT NULL, cantidad INT UNSIGNED NOT NULL DEFAULT 1,
    monto INT UNSIGNED NOT NULL DEFAULT 0, motivo VARCHAR(200) DEFAULT '',
    producto_id INT UNSIGNED NULL, creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS garantias (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ref_tipo ENUM('Venta','Orden') NOT NULL, ref_codigo VARCHAR(15) NOT NULL,
    cliente VARCHAR(120) NOT NULL, producto VARCHAR(150) NOT NULL,
    meses INT UNSIGNED NOT NULL DEFAULT 3, inicio DATE NOT NULL, fin DATE NOT NULL,
    usada TINYINT(1) NOT NULL DEFAULT 0, creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_gar_fin (fin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS gastos_fijos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, concepto VARCHAR(150) NOT NULL,
    categoria VARCHAR(60) NOT NULL DEFAULT 'General', monto INT UNSIGNED NOT NULL,
    dia_pago TINYINT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS configuracion (
    k VARCHAR(60) PRIMARY KEY, v TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$cfgSeed = $pdo->prepare('INSERT IGNORE INTO configuracion (k, v) VALUES (?, ?)');
foreach ([['negocio_nombre','Luitech Servicio Técnico'],['negocio_rut','22484469-7'],
          ['negocio_direccion',"B.O'Higgins 564, Local 13, La Serena"],
          ['negocio_whatsapp','+56 9 8220 9690'],
          ['boleta_pie','Garantía por escrito. ¡Gracias por tu compra!'],
          ['comision_pct','5']] as $kv) { $cfgSeed->execute($kv); }

$pdo->exec("CREATE TABLE IF NOT EXISTS auditoria (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, usuario VARCHAR(80) NOT NULL DEFAULT '',
    accion VARCHAR(60) NOT NULL, detalle VARCHAR(400) DEFAULT '',
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_aud_fecha (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Usuarios demo con roles (clave luitech2026 hasheada igual)
$hashBase = '$2y$10$dexr0Src7HyB4oAmWadqw.SH3DfipUA9iVmFXtYgkUY/TcHk5U8Qm';
$usrStmt = $pdo->prepare("INSERT IGNORE INTO usuarios_admin (usuario, password_hash, nombre, rol) VALUES (?, ?, ?, ?)");
$usrStmt->execute(['mostrador', $hashBase, 'Usuario Mostrador', 'vendedor']);
$usrStmt->execute(['tecnico1', $hashBase, 'Técnico Uno', 'tecnico']);

// Semillas de gastos demo (usando fechas reales para el reporte mensual)
$pdo->prepare("INSERT IGNORE INTO gastos (concepto, categoria, monto, fecha) VALUES (?, ?, ?, CURDATE())")
    ->execute(['Gastos comunes taller', 'General', 5000]);
$pdo->prepare("INSERT IGNORE INTO gastos (concepto, categoria, monto, fecha) VALUES (?, ?, ?, CURDATE())")
    ->execute(['Repuestos comprados al proveedor', 'Mercaderia', 30000]);


$ordenes = (int)$pdo->query('SELECT COUNT(*) FROM ordenes')->fetchColumn();
echo "[migrate] Esquema verificado. Órdenes en BD: {$ordenes}\n";

