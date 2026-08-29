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
