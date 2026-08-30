<?php
/**
 * LUITECH API - ConfiguraciÃ³n base
 * ConexiÃ³n PDO, sesiones y utilidades JSON compartidas por los endpoints.
 */

declare(strict_types=1);

// --- Credenciales MySQL ------------------------------------------------
// ResoluciÃ³n de credenciales: 1Âº variables LUITECH_*, 2Âº variables genÃ©ricas
// DB_* (estilo cPanel/Laravel del hosting), 3Âº valores por defecto de Laragon.
// NUNCA escribas contraseÃ±as aquÃ­: se definen como variables de entorno en
// el panel del hosting o quedan los valores locales de desarrollo.
function env_var(string ...$nombres): ?string
{
    foreach ($nombres as $n) {
        $v = getenv($n);
        if ($v !== false && $v !== '') {
            return $v;
        }
    }
    return null;
}

define('DB_HOST', env_var('LUITECH_DB_HOST', 'DB_HOST') ?? '127.0.0.1');
define('DB_PORT', env_var('LUITECH_DB_PORT', 'DB_PORT') ?? '3306');
define('DB_NAME', env_var('LUITECH_DB_NAME', 'DB_DATABASE', 'DB_NAME') ?? 'luitech');
define('DB_USER', env_var('LUITECH_DB_USER', 'DB_USERNAME', 'DB_USER') ?? 'root');
define('DB_PASS', env_var('LUITECH_DB_PASS', 'DB_PASSWORD', 'DB_PASS') ?? '');

const ADMIN_USER_MIN_LEN = 3;
const COMISION_PISO = 5000; // piso mÃ­nimo de comisiÃ³n por reparaciÃ³n (Modelo B)

// --- Encabezados comunes para endpoints JSON --------------------------
function iniciar_respuesta_json(): void
{
    // Los errores se registran en el log del servidor, NUNCA se imprimen:
    // un warning impreso antes del JSON corrompe la respuesta (HTTP 200 invÃ¡lido).
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

// --- ConexiÃ³n a la base de datos (singleton) --------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Error de conexiÃ³n con la base de datos'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    return $pdo;
}

// --- SesiÃ³n -----------------------------------------------------------
function iniciar_sesion(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('LUITECHSESSID');
        session_start();
    }
}

function es_admin(): bool
{
    iniciar_sesion();
    return !empty($_SESSION['admin_id']);
}

function exigir_admin(): void
{
    if (!es_admin()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'No autorizado'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// --- Utilidades JSON --------------------------------------------------
function responder(array $datos, int $codigo_http = 200): never
{
    http_response_code($codigo_http);
    $json = json_encode($datos, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        // Datos con UTF-8 invÃ¡lido (p. ej. texto antiguo en la BD): sustituye
        // los bytes malos en vez de devolver un cuerpo vacÃ­o/invÃ¡lido.
        $json = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
    echo ($json === false) ? '{"ok":false,"error":"Error interno al codificar la respuesta"}' : $json;
    exit;
}

/** Lee el cuerpo JSON de la peticiÃ³n (acepta tambiÃ©n x-www-form-urlencoded). */
function leer_cuerpo(): array
{
    $tipo = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($tipo, 'application/json') !== false) {
        $raw  = file_get_contents('php://input') ?: '';
        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }
    return $_POST ?: [];
}

/* ==========================================================================
 * Ã“RDENES: comisiÃ³n del tÃ©cnico (Modelo B) y entrega automÃ¡tica al pago completo
 * ========================================================================== */

/** Genera la comisiÃ³n del tÃ©cnico para una orden (Modelo B: % del margen neto
 *  real con piso mÃ­nimo). Idempotente: si la orden ya tiene comisiÃ³n, no duplica.
 *  Devuelve ['tecnico','monto','base_margen','porcentaje'] o null si no corresponde. */
function generar_comision_orden(PDO $pdo, string $codigo): ?array
{
    $rowO = $pdo->prepare('SELECT tecnico_id, total, costo_repuesto FROM ordenes WHERE codigo = ? LIMIT 1');
    $rowO->execute([$codigo]);
    $oFila = $rowO->fetch();
    if (!$oFila || (int)$oFila['tecnico_id'] <= 0 || (int)$oFila['total'] <= 0) {
        return null;
    }
    $yaExiste = $pdo->prepare('SELECT id FROM comisiones WHERE orden_codigo = ? LIMIT 1');
    $yaExiste->execute([$codigo]);
    if ($yaExiste->fetch()) {
        return null;
    }
    $stTec = $pdo->prepare('SELECT nombre, porcentaje_comision FROM tecnicos WHERE id = ? LIMIT 1');
    $stTec->execute([(int)$oFila['tecnico_id']]);
    $tecnicoCom = $stTec->fetch();
    if (!$tecnicoCom) {
        return null;
    }
    $tasaIva     = (int)(config_valor($pdo, 'iva_porcentaje', '19'));
    $netoCobrado = (int)round((int)$oFila['total'] * 100 / (100 + max(0, $tasaIva)));
    $costoNeto   = (int)round((int)$oFila['costo_repuesto'] * 100 / (100 + max(0, $tasaIva)));
    $baseMargen  = max(0, $netoCobrado - $costoNeto);
    if ($baseMargen <= 0) {
        return null;
    }
    $pctCom   = max(0, min(100, (int)$tecnicoCom['porcentaje_comision']));
    $montoCom = max(COMISION_PISO, (int)round($baseMargen * $pctCom / 100));
    $pdo->prepare('INSERT INTO comisiones (orden_codigo, tecnico_id, tecnico_nombre, base_margen, porcentaje, monto) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$codigo, (int)$oFila['tecnico_id'], (string)$tecnicoCom['nombre'], $baseMargen, $pctCom, $montoCom]);
    $pdo->prepare('INSERT INTO orden_bitacora (orden_codigo, tecnico, nota) VALUES (?, ?, ?)')
        ->execute([$codigo, (string)$tecnicoCom['nombre'], 'ComisiÃ³n generada: $' . $montoCom . ' (' . $pctCom . '% del margen $' . $baseMargen . ')']);
    return ['tecnico' => (string)$tecnicoCom['nombre'], 'monto' => $montoCom, 'base_margen' => $baseMargen, 'porcentaje' => $pctCom];
}

/** Entrega automÃ¡tica: cuando la orden queda PAGADA por completo y aÃºn no estÃ¡
 *  entregada, la pasa a Entregado (sin firma) y genera la comisiÃ³n del tÃ©cnico.
 *  Devuelve ['auto'=>true,'entregado_a','comision'] o null si no corresponde. */
function auto_entregar_si_pagada(PDO $pdo, string $codigo): ?array
{
    $st = $pdo->prepare('SELECT estado, total, abono, cliente, tecnico FROM ordenes WHERE codigo = ? LIMIT 1');
    $st->execute([$codigo]);
    $o = $st->fetch();
    if (!$o) {
        return null;
    }
    if ((int)$o['total'] <= 0 || (int)$o['abono'] < (int)$o['total']) {
        return null; // aÃºn no estÃ¡ pagada por completo
    }
    if ($o['estado'] === 'Entregado') {
        return null; // ya estaba entregada
    }
    $entregadoA = ($o['cliente'] !== null && $o['cliente'] !== '') ? $o['cliente'] : 'Cliente';
    $pdo->prepare("UPDATE ordenes SET estado = 'Entregado', avance = 100, fecha_entrega = NOW(), entregado_a = ? WHERE codigo = ?")
        ->execute([$entregadoA, $codigo]);
    $pdo->prepare('INSERT INTO orden_bitacora (orden_codigo, tecnico, nota, estado_nuevo) VALUES (?, ?, ?, ?)')
        ->execute([$codigo, (string)($o['tecnico'] ?? ''), 'Pago completo: entrega automÃ¡tica del equipo', 'Entregado']);
    $comision = generar_comision_orden($pdo, $codigo);
    return ['auto' => true, 'entregado_a' => $entregadoA, 'comision' => $comision];
}

/* ==========================================================================
 * CLIENTES: registro, ficha e historial (creaciÃ³n auto-reparable)
 * ========================================================================== */

/** Garantiza la tabla de clientes y la columna cliente_id en ordenes
 *  (mismo espÃ­ritu que preparar_proveedores: hostings sin migrate). */
function preparar_clientes(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS clientes (
        id          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
        nombre      VARCHAR(120)  NOT NULL,
        rut         VARCHAR(15)   NULL,
        telefono    VARCHAR(40)   NULL,
        email       VARCHAR(120)  NULL,
        notas       VARCHAR(255)  NULL,
        activo      TINYINT(1)    NOT NULL DEFAULT 1,
        direccion   VARCHAR(160)  NULL,
        creado_en   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $col = $pdo->query("SHOW COLUMNS FROM ordenes LIKE 'cliente_id'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        $pdo->exec("ALTER TABLE ordenes ADD COLUMN cliente_id INT UNSIGNED NULL AFTER cliente");
    }
    // Compatibilidad: la tabla clientes del diseÃ±o original puede existir sin
    // notas/activo (tenÃ­a direccion). Agrega las columnas que falten.
    foreach ([
        'notas' => "VARCHAR(255) NULL AFTER email",
        'activo' => "TINYINT(1) NOT NULL DEFAULT 1 AFTER notas",
    ] as $colCli => $tipoCli) {
        $tieneCli = $pdo->query("SHOW COLUMNS FROM clientes LIKE '" . $colCli . "'")->fetch(PDO::FETCH_ASSOC);
        if (!$tieneCli) {
            $pdo->exec("ALTER TABLE clientes ADD COLUMN " . $colCli . " " . $tipoCli);
        }
    }
}

/** Busca un cliente activo por nombre exacto (sin tildes ni mayÃºsculas). */
function cliente_id_por_nombre(PDO $pdo, string $nombre): int
{
    $st = $pdo->prepare('SELECT id FROM clientes WHERE LOWER(nombre) = LOWER(?) AND activo = 1 LIMIT 1');
    $st->execute([trim($nombre)]);
    return (int)($st->fetchColumn() ?: 0);
}

/** Garantiza las tablas de proveedores/compras y la columna proveedor_id en
 *  productos (mismo espÃ­ritu que preparar_configuraciones: para hostings que
 *  no ejecutan database/migrate.php). */
/* ==========================================================================
 * PROVEEDORES Y COMPRAS DE MERCADERÃA (tablas auto-reparables)
 * ========================================================================== */

function preparar_proveedores(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS proveedores (
        id              INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
        nombre          VARCHAR(120)     NOT NULL,
        rut             VARCHAR(12)      NULL,
        telefono        VARCHAR(40)      NULL,
        notas           VARCHAR(255)     NULL,
        activo          TINYINT(1)       NOT NULL DEFAULT 1,
        creado_en       TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS entradas_stock (
        id              INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
        proveedor_id    INT UNSIGNED     NOT NULL,
        producto_id     INT UNSIGNED     NOT NULL,
        cantidad        INT UNSIGNED     NOT NULL DEFAULT 1,
        costo_unitario  INT UNSIGNED     NOT NULL DEFAULT 0,
        total           INT UNSIGNED     NOT NULL DEFAULT 0,
        pagada_caja     TINYINT(1)       NOT NULL DEFAULT 1,
        nota            VARCHAR(255)     NULL,
        fecha           TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ent_prov (proveedor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS catalogo_proveedores (
        id              INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
        proveedor_id    INT UNSIGNED     NOT NULL,
        modelo          VARCHAR(80)      NOT NULL,
        pieza           VARCHAR(60)      NOT NULL,
        precio          INT UNSIGNED     NOT NULL DEFAULT 0,
        disponible      TINYINT(1)       NOT NULL DEFAULT 1,
        actualizado_en  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cat_prov (proveedor_id),
        INDEX idx_cat_modelo (modelo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $col = $pdo->query("SHOW COLUMNS FROM productos LIKE 'proveedor_id'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        $pdo->exec("ALTER TABLE productos ADD COLUMN proveedor_id INT UNSIGNED NULL AFTER proveedor");
    }
    // Compatibilidad: la tabla proveedores del diseÃ±o original puede existir
    // sin estas columnas (tenÃ­a contacto/email). Agrega lo que falte.
    foreach ([
        'rut' => "VARCHAR(12) NULL AFTER nombre",
        'notas' => "VARCHAR(255) NULL AFTER telefono",
        'activo' => "TINYINT(1) NOT NULL DEFAULT 1",
    ] as $colPv => $tipoPv) {
        $tiene = $pdo->query("SHOW COLUMNS FROM proveedores LIKE '" . $colPv . "'")->fetch(PDO::FETCH_ASSOC);
        if (!$tiene) {
            $pdo->exec("ALTER TABLE proveedores ADD COLUMN " . $colPv . " " . $tipoPv);
        }
    }
}

/** Texto del cuerpo (trim + longitud mÃ¡xima). */
function campo_texto(array $fuente, string $clave, int $max = 255): ?string
{
    $valor = trim((string)($fuente[$clave] ?? ''));
    if ($valor === '') {
        return null;
    }
    return mb_substr($valor, 0, $max);
}

/** Lee un valor de la tabla configuraciones (o el fallback si no existe). */
function config_valor(PDO $pdo, string $clave, string $fallback): string
{
    $st = $pdo->prepare('SELECT valor FROM configuraciones WHERE clave = ? LIMIT 1');
    $st->execute([$clave]);
    $valor = $st->fetchColumn();
    return ($valor === false || $valor === null) ? $fallback : (string)$valor;
}

/** Valida un RUT chileno (acepta con/sin puntos y guion; dÃ­gito verificador mÃ³dulo 11). */
function validar_rut_chileno(string $rut): bool
{
    $limpio = strtoupper(preg_replace('/[^0-9kK]/', '', $rut) ?? '');
    if (preg_match('/^(\d{1,8})([\dkK])$/', $limpio, $m) !== 1) {
        return false;
    }
    $suma = 0;
    $factor = 2;
    for ($i = strlen($m[1]) - 1; $i >= 0; $i--) {
        $suma += (int)$m[1][$i] * $factor;
        $factor = ($factor === 7) ? 2 : $factor + 1;
    }
    $resto = 11 - ($suma % 11);
    $esperado = ($resto === 11) ? '0' : (($resto === 10) ? 'K' : (string)$resto);
    return $m[2] === $esperado;
}

/** Garantiza que la tabla de configuraciones exista con sus semillas mÃ­nimas
 *  (auto-reparable: sirve cuando el hosting no ejecuta database/migrate.php). */
function preparar_configuraciones(): void
{
    static $lista = false;
    if ($lista) {
        return;
    }
    $lista = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS configuraciones (
            clave          VARCHAR(50)  PRIMARY KEY,
            valor          VARCHAR(100) NOT NULL,
            actualizado_en TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                           ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db()->prepare("INSERT IGNORE INTO configuraciones (clave, valor) VALUES ('iva_porcentaje', '19'), ('zona_horaria', 'America/Santiago')")
            ->execute();
    } catch (Exception $e) { /* si la BD no responde, las APIs darÃ¡n su propio error */ }
}

/** Aplica la zona horaria configurada (una vez por peticiÃ³n). Si la BD aÃºn no
 *  responde, deja la del servidor. Llamar antes de usar date() en las APIs. */
function aplicar_zona_horaria(): void
{
    static $aplicada = false;
    if ($aplicada) {
        return;
    }
    $aplicada = true;
    try {
        $tz = db()->query("SELECT valor FROM configuraciones WHERE clave = 'zona_horaria' LIMIT 1")->fetchColumn();
        if (is_string($tz) && $tz !== '' && in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            date_default_timezone_set($tz);
        }
    } catch (Exception $e) { /* sin BD todavÃ­a: se usa la del servidor */ }
}
