<?php
/**
 * LUITECH API - Configuración base
 * Conexión PDO, sesiones y utilidades JSON compartidas por los endpoints.
 */

declare(strict_types=1);

// --- Credenciales MySQL ------------------------------------------------
// Resolución de credenciales: 1º variables LUITECH_*, 2º variables genéricas
// DB_* (estilo cPanel/Laravel del hosting), 3º valores por defecto de Laragon.
// NUNCA escribas contraseñas aquí: se definen como variables de entorno en
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
const COMISION_PISO = 5000; // piso mínimo de comisión por reparación (Modelo B)

// --- Encabezados comunes para endpoints JSON --------------------------
function iniciar_respuesta_json(): void
{
    // Los errores se registran en el log del servidor, NUNCA se imprimen:
    // un warning impreso antes del JSON corrompe la respuesta (HTTP 200 inválido).
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

// --- Conexión a la base de datos (singleton) --------------------------
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
            echo json_encode(['ok' => false, 'error' => 'Error de conexión con la base de datos'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    return $pdo;
}

// --- Sesión -----------------------------------------------------------
/** Limitador de peticiones por IP (anti-enumeración y anti-fuerza bruta).
 *  Archivo temporal compartido; devuelve false si la IP supera $max en $ventana seg. */
function limitar_ip(string $accion, int $max, int $ventana): bool
{
    $ipRaw = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'ip');
    $ip = preg_replace('/[^0-9a-fA-F:.]/', '', trim(explode(',', $ipRaw)[0]));
    if ($ip === '' || $ip === null) { $ip = 'ip'; }
    $archivo = sys_get_temp_dir() . '/luitech_rl_' . md5($accion) . '.json';
    $ahora = time();
    $datos = json_decode((string)@file_get_contents($archivo), true);
    if (!is_array($datos)) { $datos = []; }
    foreach ($datos as $k => $lista) {
        $datos[$k] = array_values(array_filter((array)$lista, fn($t) => is_int($t) && $t > $ahora - $ventana));
        if (!$datos[$k]) { unset($datos[$k]); }
    }
    $lista = (array)($datos[$ip] ?? []);
    if (count($lista) >= $max) { return false; }
    $lista[] = $ahora;
    $datos[$ip] = $lista;
    @file_put_contents($archivo, json_encode($datos), LOCK_EX);
    return true;
}

/** Política de contraseñas: mínimo 10 + mayúscula + minúscula + número + especial.
 *  Devuelve null si es válida, o el motivo del rechazo. */
function validar_politica_password(string $p): ?string
{
    if (strlen($p) < 10) { return 'Mínimo 10 caracteres'; }
    if (preg_match('/\s/', $p)) { return 'No puede contener espacios'; }
    if (!preg_match('/[A-Z]/', $p)) { return 'Debe incluir al menos una MAYÚSCULA'; }
    if (!preg_match('/[a-z]/', $p)) { return 'Debe incluir al menos una minúscula'; }
    if (!preg_match('/[0-9]/', $p)) { return 'Debe incluir al menos un número'; }
    if (!preg_match('/[^A-Za-z0-9]/', $p)) { return 'Debe incluir al menos un carácter especial (!@#$%&*.-_)'; }
    return null;
}

/* ---------- TOTP (RFC 6238) para doble factor, sin librerías externas ---------- */

function base32_codificar(string $bytes): string
{
    $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bytes) as $b) { $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT); }
    $salida = '';
    foreach (str_split($bits, 5) as $trozo) {
        if (strlen($trozo) < 5) { $trozo = str_pad($trozo, 5, '0'); }
        $salida .= $alfabeto[bindec($trozo)];
    }
    return $salida;
}

function base32_decodificar(string $b32): string
{
    $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper((string)preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $c) {
        $pos = strpos($alfabeto, $c);
        if ($pos !== false) { $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT); }
    }
    $salida = '';
    foreach (str_split($bits, 8) as $trozo) {
        if (strlen($trozo) === 8) { $salida .= chr(bindec($trozo)); }
    }
    return $salida;
}

/** Verifica un código TOTP de 6 dígitos con ventana de ±30 segundos. */
function totp_verificar(string $secretoB32, string $codigo): bool
{
    $codigo = (string)preg_replace('/\D/', '', $codigo);
    if (strlen($codigo) !== 6 || strlen($secretoB32) < 16) { return false; }
    $secreto = base32_decodificar($secretoB32);
    $contador = intdiv(time(), 30);
    for ($i = -1; $i <= 1; $i++) {
        $hash = hash_hmac('sha1', pack('N*', 0, $contador + $i), $secreto, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $valor = (unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF) % 1000000;
        if (hash_equals(str_pad((string)$valor, 6, '0', STR_PAD_LEFT), $codigo)) { return true; }
    }
    return false;
}

/** Genera un secreto TOTP aleatorio (160 bits en base32). */
function totp_generar_secreto(): string
{
    return base32_codificar(random_bytes(20));
}

/** Rol de la sesión actual: 'admin' o 'tecnico'. */
function rol_actual(): string
{
    return ($_SESSION['admin_rol'] ?? 'admin') === 'tecnico' ? 'tecnico' : 'admin';
}

/** Exige cuenta de ADMINISTRADOR (403 para técnicos). */
function exigir_rol_admin(): void
{
    if (rol_actual() !== 'admin') {
        responder(['ok' => false, 'error' => 'Esta acción requiere cuenta de administrador'], 403);
    }
}

function iniciar_sesion(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Cookies endurecidas: inaccesibles a JS (httponly), sin CSRF entre
        // sitios (samesite=Lax) y solo por HTTPS en producción (proxy-aware:
        // en hosting el TLS termina antes del PHP y llega X-Forwarded-Proto).
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $https,
        ]);
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
        // Datos con UTF-8 inválido (p. ej. texto antiguo en la BD): sustituye
        // los bytes malos en vez de devolver un cuerpo vacío/inválido.
        $json = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
    echo ($json === false) ? '{"ok":false,"error":"Error interno al codificar la respuesta"}' : $json;
    exit;
}

/** Lee el cuerpo JSON de la petición (acepta también x-www-form-urlencoded). */
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
 * ÓRDENES: comisión del técnico (Modelo B) y entrega automática al pago completo
 * ========================================================================== */

/** Genera la comisión del técnico para una orden (Modelo B: % del margen neto
 *  real con piso mínimo). Idempotente: si la orden ya tiene comisión, no duplica.
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
        ->execute([$codigo, (string)$tecnicoCom['nombre'], 'Comisión generada: $' . $montoCom . ' (' . $pctCom . '% del margen $' . $baseMargen . ')']);
    return ['tecnico' => (string)$tecnicoCom['nombre'], 'monto' => $montoCom, 'base_margen' => $baseMargen, 'porcentaje' => $pctCom];
}

/** Entrega automática: cuando la orden queda PAGADA por completo y aún no está
 *  entregada, la pasa a Entregado (sin firma) y genera la comisión del técnico.
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
        return null; // aún no está pagada por completo
    }
    if ($o['estado'] === 'Entregado') {
        return null; // ya estaba entregada
    }
    $entregadoA = ($o['cliente'] !== null && $o['cliente'] !== '') ? $o['cliente'] : 'Cliente';
    $pdo->prepare("UPDATE ordenes SET estado = 'Entregado', avance = 100, fecha_entrega = NOW(), entregado_a = ? WHERE codigo = ?")
        ->execute([$entregadoA, $codigo]);
    $pdo->prepare('INSERT INTO orden_bitacora (orden_codigo, tecnico, nota, estado_nuevo) VALUES (?, ?, ?, ?)')
        ->execute([$codigo, (string)($o['tecnico'] ?? ''), 'Pago completo: entrega automática del equipo', 'Entregado']);
    $comision = generar_comision_orden($pdo, $codigo);
    return ['auto' => true, 'entregado_a' => $entregadoA, 'comision' => $comision];
}

/* ==========================================================================
 * CLIENTES: registro, ficha e historial (creación auto-reparable)
 * ========================================================================== */

/** Garantiza la tabla de clientes y la columna cliente_id en ordenes
 *  (mismo espíritu que preparar_proveedores: hostings sin migrate). */
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
    // Compatibilidad: la tabla clientes del diseño original puede existir sin
    // notas/activo (tenía direccion). Agrega las columnas que falten.
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

/** Busca un cliente activo por nombre exacto (sin tildes ni mayúsculas). */
function cliente_id_por_nombre(PDO $pdo, string $nombre): int
{
    $st = $pdo->prepare('SELECT id FROM clientes WHERE LOWER(nombre) = LOWER(?) AND activo = 1 LIMIT 1');
    $st->execute([trim($nombre)]);
    return (int)($st->fetchColumn() ?: 0);
}

/** Garantiza las tablas de proveedores/compras y la columna proveedor_id en
 *  productos (mismo espíritu que preparar_configuraciones: para hostings que
 *  no ejecutan database/migrate.php). */
/* ==========================================================================
 * PROVEEDORES Y COMPRAS DE MERCADERÍA (tablas auto-reparables)
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
    // Compatibilidad: la tabla proveedores del diseño original puede existir
    // sin estas columnas (tenía contacto/email). Agrega lo que falte.
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

/** Texto del cuerpo (trim + longitud máxima). */
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

/** Valida un RUT chileno (acepta con/sin puntos y guion; dígito verificador módulo 11). */
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

/** Garantiza que la tabla de configuraciones exista con sus semillas mínimas
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
    } catch (Exception $e) { /* si la BD no responde, las APIs darán su propio error */ }
}

/** Aplica la zona horaria configurada (una vez por petición). Si la BD aún no
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
    } catch (Exception $e) { /* sin BD todavía: se usa la del servidor */ }
}
