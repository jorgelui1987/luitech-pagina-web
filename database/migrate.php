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

$ordenes = (int)$pdo->query('SELECT COUNT(*) FROM ordenes')->fetchColumn();
echo "[migrate] Esquema verificado. Órdenes en BD: {$ordenes}\n";
