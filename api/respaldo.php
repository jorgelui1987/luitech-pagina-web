<?php
/**
 * LUITECH - Respaldo completo del sistema (solo administrador).
 * GET ?action=descargar
 * Genera un ZIP con:
 *   1) respaldo-luitech.sql  -> todas las tablas con estructura y datos
 *   2) carpeta uploads/      -> firmas, fotos, logo
 * Además guarda una copia en /backups/ (carpeta protegida, se conservan
 * las últimas 10). La descarga es directa (no JSON) para el navegador.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

iniciar_sesion();
if (!es_admin()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'No autorizado'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'El servidor no tiene la extensión ZIP (ZipArchive)'], JSON_UNESCAPED_UNICODE);
    exit;
}

@set_time_limit(300);

/** Respuesta de error en JSON (para que el navegador/JS la entienda). */
function respaldo_error(string $mensaje, int $codigo): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();

/* ---------- 1) Volcado SQL de todas las tablas ------------------------- */
$sql  = "-- Respaldo Luitech — " . date('Y-m-d H:i:s') . "\n";
$sql .= "-- Base de datos: " . DB_NAME . "\n";
$sql .= "SET NAMES utf8mb4;\n";
$sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

$tablas = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach ($tablas as $tabla) {
    $tabla = (string)$tabla;
    $segura = str_replace('`', '', $tabla);
    $create = $pdo->query("SHOW CREATE TABLE `" . $segura . "`")->fetch(PDO::FETCH_NUM);
    $sql .= "DROP TABLE IF EXISTS `" . $segura . "`;\n";
    $sql .= $create[1] . ";\n\n";
    $filas = $pdo->query("SELECT * FROM `" . $segura . "`");
    while ($fila = $filas->fetch(PDO::FETCH_ASSOC)) {
        $columnas = '`' . implode('`, `', array_keys($fila)) . '`';
        $valores  = implode(', ', array_map(function ($v) use ($pdo) {
            return ($v === null) ? 'NULL' : $pdo->quote((string)$v);
        }, array_values($fila)));
        $sql .= "INSERT INTO `" . $segura . "` ($columnas) VALUES ($valores);\n";
    }
    $sql .= "\n";
}
$sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

/* ---------- 2) ZIP: respaldo.sql + carpeta uploads/ -------------------- */
$dirBackups = __DIR__ . '/../backups';
if (!is_dir($dirBackups) && !mkdir($dirBackups, 0755, true) && !is_dir($dirBackups)) {
    respaldo_error('No se pudo crear la carpeta backups', 500);
}
$nombreZip = 'respaldo-luitech-' . date('Y-m-d-Hi') . '.zip';
$rutaZip   = $dirBackups . '/' . $nombreZip;

$zip = new ZipArchive();
if ($zip->open($rutaZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    respaldo_error('No se pudo crear el archivo ZIP', 500);
}
$zip->addFromString('respaldo-luitech.sql', $sql);

$raizUploads = realpath(__DIR__ . '/../uploads');
if ($raizUploads !== false) {
    $iterador = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($raizUploads, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterador as $archivo) {
        $ruta     = (string)$archivo;
        $relativa = 'uploads/' . ltrim(substr($ruta, strlen($raizUploads)), '/\\');
        $zip->addFile($ruta, str_replace('\\', '/', $relativa));
    }
}
$zip->close();

/* ---------- 3) Conservar solo las últimas 10 copias -------------------- */
$copias = glob($dirBackups . '/respaldo-luitech-*.zip');
if (is_array($copias) && count($copias) > 10) {
    sort($copias);
    foreach (array_slice($copias, 0, count($copias) - 10) as $vieja) {
        @unlink($vieja);
    }
}

/* ---------- 4) Descargar ---------------------------------------------- */
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $nombreZip . '"');
header('Content-Length: ' . (string)filesize($rutaZip));
header('Cache-Control: no-store');
readfile($rutaZip);
exit;
