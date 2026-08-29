<?php
/**
 * LUITECH - Respaldo completo del sistema (solo administrador).
 * GET ?action=descargar
 * Genera un ZIP construido en PHP puro (SIN depender de la extensión
 * ZipArchive del servidor) con:
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

@set_time_limit(300);

/** Escritor de ZIP en PHP puro (almacenado/deflate): funciona en cualquier
 *  hosting aunque no tenga la extensión ZipArchive habilitada. */
class ZiperSimple
{
    private $locales  = '';
    private $central  = '';
    private $offset   = 0;
    private $cantidad = 0;

    public function agregar(string $nombre, string $contenido): void
    {
        $metodo = 0;
        $datos  = $contenido;
        if (function_exists('gzdeflate')) {
            $defl = @gzdeflate($contenido, 6);
            if (is_string($defl) && strlen($defl) < strlen($contenido)) {
                $datos  = $defl;
                $metodo = 8;
            }
        }
        [$hora, $fecha] = self::fechaDos();
        $crc = pack('V', crc32($contenido));
        $inicioLocal = $this->offset;

        // Cabecera local (30 bytes): firma, versión, flags, método, hora, fecha,
        // crc, tamaño comprimido, tamaño original, largo nombre, largo extra
        $this->locales .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $metodo, $hora, $fecha, $crc,
                strlen($datos), strlen($contenido), strlen($nombre), 0)
            . $nombre . $datos;

        // Entrada del directorio central (46 bytes): igual + quién lo hizo,
        // largo comentario, disco inicial, atributos y offset de la cabecera local
        $this->central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, $metodo, $hora, $fecha, $crc,
                strlen($datos), strlen($contenido), strlen($nombre), 0, 0, 0, 0, 0, $inicioLocal)
            . $nombre;

        $this->offset = $inicioLocal + 30 + strlen($nombre) + strlen($datos);
        $this->cantidad++;
    }

    public function agregarArchivo(string $rutaAbsoluta, string $nombreEnZip): void
    {
        $contenido = @file_get_contents($rutaAbsoluta);
        if (is_string($contenido)) {
            $this->agregar($nombreEnZip, $contenido);
        }
    }

    public function terminar(): string
    {
        return $this->locales
            . $this->central
            . pack('VvvvvVVv', 0x06054b50, 0, 0, $this->cantidad, $this->cantidad,
                   strlen($this->central), $this->offset, 0);
    }

    /** Fecha/hora en el formato DOS que usa el ZIP. */
    private static function fechaDos(): array
    {
        return [
            ((int)date('G') << 11) | ((int)date('i') << 5) | intdiv((int)date('s'), 2),
            (((int)date('Y') - 1980) << 9) | ((int)date('n') << 5) | (int)date('j'),
        ];
    }
}

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

/* ---------- 2) ZIP construido en PHP puro (sin extensiones) ------------ */
$dirBackups = __DIR__ . '/../backups';
if (!is_dir($dirBackups) && !mkdir($dirBackups, 0755, true) && !is_dir($dirBackups)) {
    respaldo_error('No se pudo crear la carpeta backups', 500);
}
$nombreZip = 'respaldo-luitech-' . date('Y-m-d-Hi') . '.zip';
$rutaZip   = $dirBackups . '/' . $nombreZip;

$zip = new ZiperSimple();
$zip->agregar('respaldo-luitech.sql', $sql);

$raizUploads = realpath(__DIR__ . '/../uploads');
if ($raizUploads !== false) {
    $iterador = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($raizUploads, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterador as $archivo) {
        $ruta     = (string)$archivo;
        $relativa = 'uploads/' . ltrim(substr($ruta, strlen($raizUploads)), '/\\');
        $zip->agregarArchivo($ruta, str_replace('\\', '/', $relativa));
    }
}
file_put_contents($rutaZip, $zip->terminar());
if (!is_file($rutaZip)) {
    respaldo_error('No se pudo guardar el archivo ZIP', 500);
}

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
