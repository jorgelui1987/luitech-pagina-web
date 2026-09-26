<?php
/** Migracion tablet tactil sin RUT (idempotente, correr una vez). */
declare(strict_types=1);
require __DIR__ . '/../api/config.php';
$pdo = db();
$cols = $pdo->query('SHOW COLUMNS FROM clientes')->fetchAll(PDO::FETCH_COLUMN);
$agregar = [
    'apellido' => "ALTER TABLE clientes ADD COLUMN apellido VARCHAR(80) NULL AFTER nombre",
    'telefono_norm' => "ALTER TABLE clientes ADD COLUMN telefono_norm VARCHAR(12) NULL AFTER telefono",
    'codigo_cli' => "ALTER TABLE clientes ADD COLUMN codigo_cli VARCHAR(12) NULL AFTER telefono_norm",
    'pin_retiro_hash' => "ALTER TABLE clientes ADD COLUMN pin_retiro_hash VARCHAR(255) NULL AFTER codigo_cli",
    'desbloqueo_tipo' => "ALTER TABLE clientes ADD COLUMN desbloqueo_tipo ENUM('ninguno','pin','patron','sin_clave') NOT NULL DEFAULT 'ninguno' AFTER pin_retiro_hash",
    'desbloqueo_clave' => "ALTER TABLE clientes ADD COLUMN desbloqueo_clave VARCHAR(100) NULL AFTER desbloqueo_tipo",
    'origen' => "ALTER TABLE clientes ADD COLUMN origen VARCHAR(20) NOT NULL DEFAULT 'mostrador' AFTER desbloqueo_clave",
    'estado_validacion' => "ALTER TABLE clientes ADD COLUMN estado_validacion ENUM('pendiente','validado') NOT NULL DEFAULT 'validado' AFTER origen",
    'acepta_privacidad' => "ALTER TABLE clientes ADD COLUMN acepta_privacidad TINYINT(1) NOT NULL DEFAULT 0 AFTER estado_validacion",
];
foreach ($agregar as $c => $sql) {
    if (!in_array($c, $cols, true)) { $pdo->exec($sql); echo "[ok] clientes.$c agregado\n"; }
    else { echo "[ya] clientes.$c existe\n"; }
}
try {
    $idx = $pdo->query("SHOW INDEX FROM clientes WHERE Key_name = 'uq_clientes_telnorm'")->fetch(PDO::FETCH_ASSOC);
    if (!$idx) { $pdo->exec("CREATE UNIQUE INDEX uq_clientes_telnorm ON clientes (telefono_norm)"); echo "[ok] indice telefono_norm\n"; }
    else { echo "[ya] indice telefono_norm existe\n"; }
} catch (Throwable $e) { echo "[aviso] " . $e->getMessage() . "\n"; }
echo "Listo. Abre registro-tactil.html\n";
