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
            $mensaje = '[migrate] BD no disponible tras ' . $maxIntentos . " intentos: " . $e->getMessage() . "\n";
            if (defined('STDERR')) {
                fwrite(STDERR, $mensaje); // CLI
            } else {
                echo '<pre>' . htmlspecialchars($mensaje) . '</pre>'; // navegador
            }
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

// --- Acta de recepción: columnas nuevas en ordenes (idempotente) --------
// PIN/patrón, accesorios, observaciones y firma del cliente al ingreso.
$columnasExistentes = $pdo->query('SHOW COLUMNS FROM ordenes')->fetchAll(PDO::FETCH_COLUMN);
$migracionColumnas = [
    'pin_patron'    => 'ALTER TABLE ordenes ADD COLUMN pin_patron VARCHAR(50) NULL AFTER tecnico',
    'accesorios'    => 'ALTER TABLE ordenes ADD COLUMN accesorios VARCHAR(255) NULL AFTER pin_patron',
    'obs_recepcion' => 'ALTER TABLE ordenes ADD COLUMN obs_recepcion VARCHAR(250) NULL AFTER accesorios',
    'firma_ingreso' => 'ALTER TABLE ordenes ADD COLUMN firma_ingreso VARCHAR(255) NULL AFTER obs_recepcion',
];
foreach ($migracionColumnas as $columna => $sql) {
    if (!in_array($columna, $columnasExistentes, true)) {
        $pdo->exec($sql);
        echo "[migrate] Columna agregada: ordenes.{$columna}\n";
    }
}

// --- Cobro y cierre de la orden (idempotente) ----------------------------
// Valor de la reparación (repuestos + mano de obra), pagos, garantía y
// datos de la entrega física (fecha, quién retira, su firma).
$migracionCobro = [
    'precio_repuestos' => 'ALTER TABLE ordenes ADD COLUMN precio_repuestos INT UNSIGNED NOT NULL DEFAULT 0 AFTER firma_ingreso',
    'mano_obra'        => 'ALTER TABLE ordenes ADD COLUMN mano_obra INT UNSIGNED NOT NULL DEFAULT 0 AFTER precio_repuestos',
    'total'            => 'ALTER TABLE ordenes ADD COLUMN total INT UNSIGNED NOT NULL DEFAULT 0 AFTER mano_obra',
    'abono'            => 'ALTER TABLE ordenes ADD COLUMN abono INT UNSIGNED NOT NULL DEFAULT 0 AFTER total',
    'estado_pago'      => "ALTER TABLE ordenes ADD COLUMN estado_pago ENUM('Pendiente','Abonado','Pagado') NOT NULL DEFAULT 'Pendiente' AFTER abono",
    'metodo_pago'      => "ALTER TABLE ordenes ADD COLUMN metodo_pago ENUM('Efectivo','Debito','Credito','Transferencia') NULL AFTER estado_pago",
    'garantia_dias'    => 'ALTER TABLE ordenes ADD COLUMN garantia_dias SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER metodo_pago',
    'fecha_entrega'    => 'ALTER TABLE ordenes ADD COLUMN fecha_entrega DATETIME NULL AFTER garantia_dias',
    'entregado_a'      => 'ALTER TABLE ordenes ADD COLUMN entregado_a VARCHAR(120) NULL AFTER fecha_entrega',
    'firma_entrega'    => 'ALTER TABLE ordenes ADD COLUMN firma_entrega VARCHAR(255) NULL AFTER entregado_a',
];
foreach ($migracionCobro as $columna => $sql) {
    if (!in_array($columna, $columnasExistentes, true)) {
        $pdo->exec($sql);
        echo "[migrate] Columna agregada: ordenes.{$columna}\n";
    }
}

// --- Bitácora de reparación (historial técnico por orden) -----------------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS orden_bitacora (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        orden_codigo VARCHAR(12)  NOT NULL,
        tecnico      VARCHAR(80)  NOT NULL DEFAULT '',
        nota         VARCHAR(500) NOT NULL,
        estado_nuevo VARCHAR(30)  NULL,
        creado_en    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (orden_codigo) REFERENCES ordenes(codigo) ON DELETE CASCADE,
        INDEX idx_ob_orden (orden_codigo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// --- Técnicos y comisiones (modelo de negocio del taller) ----------------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS tecnicos (
        id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        nombre              VARCHAR(120) NOT NULL,
        rut                 VARCHAR(12)  NULL,
        telefono            VARCHAR(40)  NULL,
        porcentaje_comision TINYINT UNSIGNED NOT NULL DEFAULT 30,
        activo              TINYINT(1)   NOT NULL DEFAULT 1,
        creado_en           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS comisiones (
        id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        orden_codigo   VARCHAR(12)  NOT NULL,
        tecnico_id     INT UNSIGNED NOT NULL,
        tecnico_nombre VARCHAR(120) NOT NULL,
        base_margen    INT UNSIGNED NOT NULL DEFAULT 0,
        porcentaje     TINYINT UNSIGNED NOT NULL DEFAULT 30,
        monto          INT UNSIGNED NOT NULL DEFAULT 0,
        estado         ENUM('Pendiente','Pagada','Anulada') NOT NULL DEFAULT 'Pendiente',
        fecha_generada TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        fecha_pagada   DATETIME NULL,
        INDEX idx_com_orden (orden_codigo),
        INDEX idx_com_tecnico (tecnico_id),
        FOREIGN KEY (orden_codigo) REFERENCES ordenes(codigo) ON DELETE CASCADE,
        FOREIGN KEY (tecnico_id) REFERENCES tecnicos(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$columnasOrdenes2 = $pdo->query('SHOW COLUMNS FROM ordenes')->fetchAll(PDO::FETCH_COLUMN);
$migracionComisiones = [
    'tecnico_id'     => 'ALTER TABLE ordenes ADD COLUMN tecnico_id INT UNSIGNED NULL AFTER tecnico',
    'costo_repuesto' => 'ALTER TABLE ordenes ADD COLUMN costo_repuesto INT UNSIGNED NOT NULL DEFAULT 0 AFTER precio_repuestos',
];
foreach ($migracionComisiones as $columna => $sql) {
    if (!in_array($columna, $columnasOrdenes2, true)) {
        $pdo->exec($sql);
        echo "[migrate] Columna agregada: ordenes.{$columna}\n";
    }
}

// --- Fotos de respaldo por orden (evidencia del estado al ingreso) ------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS orden_fotos (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        orden_codigo VARCHAR(12)  NOT NULL,
        archivo      VARCHAR(255) NOT NULL,
        creado_en    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (orden_codigo) REFERENCES ordenes(codigo) ON DELETE CASCADE,
        INDEX idx_of_orden (orden_codigo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
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
        proveedor       VARCHAR(120)     NULL,
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

// --- Configuraciones globales (ej: tasa de IVA editable) ------------------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS configuraciones (
        clave          VARCHAR(50)  PRIMARY KEY,
        valor          VARCHAR(100) NOT NULL,
        actualizado_en TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                       ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$pdo->prepare("INSERT IGNORE INTO configuraciones (clave, valor) VALUES ('iva_porcentaje', '19')")
    ->execute();

// Semillas de configuración general (INSERT IGNORE: no pisa lo guardado)
$configSemillas = [
    'empresa_nombre'        => 'Luitech Servicio Técnico',
    'empresa_rut'           => '',
    'empresa_giro'          => '',
    'empresa_direccion'     => "B.O'Higgins 564, La Serena",
    'empresa_pais'          => 'Chile',
    'empresa_telefono'      => '+56 9 8220 9690',
    'empresa_email'         => '',
    'empresa_logo'          => '',
    'moneda'                => 'CLP',
    'moneda_simbolo'        => '$',
    'zona_horaria'          => 'America/Santiago',
    'garantia_dias_default' => '30',
    'terminos_texto'        => '¡Gracias por confiar en Luitech! Conserve este comprobante para hacer efectiva la garantía.',
    'dte_habilitado'        => '0',
    'dte_proveedor'         => '',
    'dte_api_key'           => '',
    'mp_enabled'            => '0',
    'mp_public_key'         => '',
    'mp_access_token'       => '',
    'mp_point_device'       => '',
];
$stmtCfg = $pdo->prepare('INSERT IGNORE INTO configuraciones (clave, valor) VALUES (?, ?)');
foreach ($configSemillas as $claveCfg => $valorCfg) {
    $stmtCfg->execute([$claveCfg, $valorCfg]);
}

// --- IVA por venta (desglose fiscal congelado al momento de cobrar) -------
// En Chile el precio al público incluye el IVA: neto = total / (1 + tasa).
// Cada venta guarda la tasa usada, así el historial no cambia si la ley cambia.
$columnasVentas = $pdo->query('SHOW COLUMNS FROM ventas')->fetchAll(PDO::FETCH_COLUMN);
$migracionVentasIva = [
    'cliente_rut' => 'ALTER TABLE ventas ADD COLUMN cliente_rut VARCHAR(12) NULL AFTER cliente',
    'iva_tasa'    => 'ALTER TABLE ventas ADD COLUMN iva_tasa SMALLINT UNSIGNED NULL AFTER total',
    'neto'        => 'ALTER TABLE ventas ADD COLUMN neto INT UNSIGNED NULL AFTER iva_tasa',
    'iva_monto'   => 'ALTER TABLE ventas ADD COLUMN iva_monto INT UNSIGNED NULL AFTER neto',
];
foreach ($migracionVentasIva as $columna => $sql) {
    if (!in_array($columna, $columnasVentas, true)) {
        $pdo->exec($sql);
        echo "[migrate] Columna agregada: ventas.{$columna}\n";
    }
}

// --- Mercado Pago como medio de pago (ordenes y ventas) ------------------
$colMetodoOrden = $pdo->query("SHOW COLUMNS FROM ordenes LIKE 'metodo_pago'")->fetch(PDO::FETCH_ASSOC);
if ($colMetodoOrden && strpos($colMetodoOrden['Type'], 'Mercado Pago') === false) {
    $pdo->exec("ALTER TABLE ordenes MODIFY COLUMN metodo_pago ENUM('Efectivo','Debito','Credito','Transferencia','Mercado Pago') NULL");
    echo "[migrate] ordenes.metodo_pago ahora acepta Mercado Pago\n";
}
$colMedioVenta = $pdo->query("SHOW COLUMNS FROM ventas LIKE 'medio_pago'")->fetch(PDO::FETCH_ASSOC);
if ($colMedioVenta && strpos($colMedioVenta['Type'], 'Mercado Pago') === false) {
    $pdo->exec("ALTER TABLE ventas MODIFY COLUMN medio_pago ENUM('Efectivo','Debito','Credito','Transferencia','Mercado Pago') NOT NULL DEFAULT 'Efectivo'");
    echo "[migrate] ventas.medio_pago ahora acepta Mercado Pago\n";
}

// --- Estado "Entregado" en el ENUM de ordenes (entrega con o sin firma) ----
$colEstado = $pdo->query("SHOW COLUMNS FROM ordenes LIKE 'estado'")->fetch(PDO::FETCH_ASSOC);
if ($colEstado && strpos($colEstado['Type'], 'Entregado') === false) {
    $pdo->exec("ALTER TABLE ordenes MODIFY COLUMN estado ENUM('Ingresado','En Diagnóstico','En Reparación','Listo para Retiro','Entregado') NOT NULL DEFAULT 'Ingresado'");
    echo "[migrate] ordenes.estado ahora acepta Entregado\n";
}

// --- Proveedor en los productos del inventario ----------------------------
$colProv = $pdo->query("SHOW COLUMNS FROM productos LIKE 'proveedor'")->fetch(PDO::FETCH_ASSOC);
if (!$colProv) {
    $pdo->exec("ALTER TABLE productos ADD COLUMN proveedor VARCHAR(120) NULL AFTER categoria");
    echo "[migrate] productos.proveedor agregado\n";
}

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

// Semillas de gastos demo (usando fechas reales para el reporte mensual)
$pdo->prepare("INSERT IGNORE INTO gastos (concepto, categoria, monto, fecha) VALUES (?, ?, ?, CURDATE())")
    ->execute(['Gastos comunes taller', 'General', 5000]);
$pdo->prepare("INSERT IGNORE INTO gastos (concepto, categoria, monto, fecha) VALUES (?, ?, ?, CURDATE())")
    ->execute(['Repuestos comprados al proveedor', 'Mercaderia', 30000]);


$ordenes = (int)$pdo->query('SELECT COUNT(*) FROM ordenes')->fetchColumn();
echo "[migrate] Esquema verificado. Órdenes en BD: {$ordenes}\n";

