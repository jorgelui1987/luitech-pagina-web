<?php
/**
 * API de ejemplo (UN solo archivo) — Nueva Orden de Trabajo / Acta de Recepción.
 * Reutilizable: PHP + SQLite (orders.db se crea sola al primer uso).
 * ⚠ Solo para aprender/probar: antes de producción agrega TU autenticación real
 *    y cambia SQLite por tu MySQL/PostgreSQL.
 *
 * Uso:  php -S 127.0.0.1:8090 -t .     (desde esta carpeta)
 *       http://127.0.0.1:8090/api-ejemplo.php?action=create
 */
declare(strict_types=1);

const MAX_BYTES_FOTO  = 5242880;   // 5 MB por foto
const MAX_FOTOS_ORDEN = 12;
const MAX_BYTES_FIRMA = 614400;    // ~600 KB de PNG

// ——— Conexión + esquema (SQLite para cero configuración) ————————
$pdo = new PDO('sqlite:' . __DIR__ . '/orders.db');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE IF NOT EXISTS orders (
    code TEXT PRIMARY KEY, client TEXT NOT NULL, device TEXT NOT NULL, type TEXT,
    issue TEXT NOT NULL, status TEXT DEFAULT "Received", progress INTEGER DEFAULT 10,
    technician TEXT, pin_pattern TEXT, accessories TEXT, intake_notes TEXT,
    intake_sign TEXT, intake_date TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE IF NOT EXISTS order_files (
    id INTEGER PRIMARY KEY AUTOINCREMENT, order_code TEXT NOT NULL,
    file_path TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');

function json_out(array $d, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}
function body(): array {
    $t = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($t, 'application/json') !== false) {
        $j = json_decode((string)file_get_contents('php://input'), true);
        return is_array($j) ? $j : [];
    }
    return $_POST ?: [];
}
function texto(array $b, string $k, int $max): ?string {
    $v = trim((string)($b[$k] ?? ''));
    return $v === '' ? null : mb_substr($v, 0, $max);
}
// ⚠ AUTENTICACIÓN: reemplaza esto por tu login real (sesión/JWT).
function exigir_token(): void {
    $recibido = $_SERVER['HTTP_X_API_TOKEN'] ?? ($_POST['token'] ?? '');
    if (!hash_equals('CAMBIA-ESTE-TOKEN', (string)$recibido ?? '')) {
        json_out(['ok' => false, 'error' => 'No autorizado'], 401);
    }
}
function crear_directorio(string $ruta): bool {
    return is_dir($ruta) || mkdir($ruta, 0777, true);
}
/** Valida y guarda la firma (dataURL PNG). Devuelve ruta relativa o null. */
function guardar_firma(?string $dataUrl, string $code): ?string {
    if ($dataUrl === null || $dataUrl === '') return null;
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
        json_out(['ok' => false, 'error' => 'Firma: formato inválido (PNG base64)'], 400);
    }
    $bin = base64_decode($m[1], true);
    if ($bin === false || strlen($bin) > MAX_BYTES_FIRMA) {
        json_out(['ok' => false, 'error' => 'Firma inválida o muy grande'], 400);
    }
    if (!str_starts_with($bin, "\x89PNG\r\n\x1a\n")) {
        json_out(['ok' => false, 'error' => 'La firma no es PNG'], 400);
    }
    $dir = __DIR__ . '/uploads/firmas';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $nombre = $code . '-ingreso-' . bin2hex(random_bytes(6)) . '.png';
    file_put_contents("$dir/$nombre", $bin);
    return 'uploads/firmas/' . $nombre;
}
/* ---------- Rutas (action=) — todas tras autenticación ---------- */
$action = $_GET['action'] ?? '';
exigir_token();

switch ($action) {

case 'create': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Método no permitido'], 405);
    $b = body();
    $code = strtoupper(texto($b, 'code', 12) ?? ('ORD-' . random_int(1000, 9999)));
    $client = texto($b, 'client', 120);
    $device = texto($b, 'device', 120);
    $issue  = texto($b, 'issue', 2000);
    if (!$client || !$device || !$issue) {
        json_out(['ok' => false, 'error' => 'Faltan client, device o issue'], 400);
    }
    $sign = guardar_firma(isset($b['signature']) ? (string)$b['signature'] : null, $code);
    $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($b['intake_date'] ?? ''))
        ? $b['intake_date'] : date('Y-m-d');
    try {
        $pdo->prepare('INSERT INTO orders (code, client, device, type, issue, technician,
            pin_pattern, accessories, intake_notes, intake_sign, intake_date)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([
            $code, $client, $device, texto($b, 'type', 20) ?? 'Otro', $issue,
            texto($b, 'technician', 80) ?? 'Por asignar', texto($b, 'pin_pattern', 50),
            texto($b, 'accessories', 255), texto($b, 'intake_notes', 250), $sign, $fecha]);
    } catch (PDOException $e) {
        json_out(['ok' => false, 'error' => 'Ya existe una orden con ese código'], 409);
    }
    json_out(['ok' => true, 'order' => ['code' => $code, 'intake_sign' => $sign]]);
}

case 'upload_photo': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Método no permitido'], 405);
    $code = strtoupper(trim((string)($_POST['code'] ?? '')));
    if (!preg_match('/^[A-Z]{2,4}-\d{3,8}$/', $code)) json_out(['ok' => false, 'error' => 'Código inválido'], 400);
    $q = $pdo->prepare('SELECT 1 FROM orders WHERE code = ?');
    $q->execute([$code]);
    if (!$q->fetch()) json_out(['ok' => false, 'error' => 'Orden no encontrada'], 404);

    $c = $pdo->prepare('SELECT COUNT(*) FROM order_files WHERE order_code = ?');
    $c->execute([$code]);
    if ((int)$c->fetchColumn() >= MAX_FOTOS_ORDEN) {
        json_out(['ok' => false, 'error' => 'Máximo ' . MAX_FOTOS_ORDEN . ' fotos por orden'], 400);
    }
    $f = $_FILES['photo'] ?? null;
    if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
        json_out(['ok' => false, 'error' => 'No se recibió la foto'], 400);
    }
    if ($f['size'] <= 0 || $f['size'] > MAX_BYTES_FOTO) {
        json_out(['ok' => false, 'error' => 'La foto debe pesar entre 0 y 5 MB'], 400);
    }
    if (!is_uploaded_file($f['tmp_name'])) json_out(['ok' => false, 'error' => 'Subida inválida'], 400);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $exts = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($exts[$mime]) || @getimagesize($f['tmp_name']) === false) {
        json_out(['ok' => false, 'error' => 'Solo JPG, PNG o WebP'], 400);
    }
    $dir = __DIR__ . '/uploads/orders/' . $code;
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $nombre = bin2hex(random_bytes(8)) . '-' . time() . '.' . $exts[$mime];
    $relativa = 'uploads/orders/' . $code . '/' . $nombre;
    if (!move_uploaded_file($f['tmp_name'], "$dir/$nombre")) {
        json_out(['ok' => false, 'error' => 'No se pudo guardar'], 500);
    }
    $pdo->prepare('INSERT INTO order_files (order_code, file_path) VALUES (?, ?)')->execute([$code, $relativa]);
    json_out(['ok' => true, 'photo' => ['id' => (int)$pdo->lastInsertId(), 'file_path' => $relativa]]);
}

case 'photos': {
    $code = strtoupper(trim((string)($_GET['code'] ?? '')));
    $q = $pdo->prepare('SELECT id, file_path, created_at FROM order_files WHERE order_code = ? ORDER BY id');
    $q->execute([$code]);
    json_out(['ok' => true, 'photos' => $q->fetchAll(PDO::FETCH_ASSOC)]);
}

case 'delete_photo': {
    $b = body(); $id = (int)($b['id'] ?? 0);
    if ($id <= 0) json_out(['ok' => false, 'error' => 'Id inválido'], 400);
    $q = $pdo->prepare('SELECT id, file_path FROM order_files WHERE id = ?');
    $q->execute([$id]);
    $foto = $q->fetch(PDO::FETCH_ASSOC);
    if (!$foto) json_out(['ok' => false, 'error' => 'Foto no encontrada'], 404);
    $pdo->prepare('DELETE FROM order_files WHERE id = ?')->execute([$id]);
    $abs = __DIR__ . '/' . $foto['file_path'];
    if (is_file($abs)) @unlink($abs);
    json_out(['ok' => true]);
}

case 'delete': {
    $b = body(); $code = strtoupper(trim((string)($b['code'] ?? '')));
    $q = $pdo->prepare('SELECT file_path FROM order_files WHERE order_code = ?');
    $q->execute([$code]);
    $archivos = $q->fetchAll(PDO::FETCH_COLUMN);
    $g = $pdo->prepare('SELECT intake_sign FROM orders WHERE code = ?');
    $g->execute([$code]);
    $firma = (string)($g->fetchColumn() ?: '');
    $del = $pdo->prepare('DELETE FROM orders WHERE code = ?');
    $del->execute([$code]);
    if (!$del->rowCount()) json_out(['ok' => false, 'error' => 'Orden no encontrada'], 404);
    foreach ($archivos as $rel) { $abs = __DIR__ . '/' . $rel; if (is_file($abs)) @unlink($abs); }
    if (is_dir(__DIR__ . '/uploads/orders/' . $code)) @rmdir(__DIR__ . '/uploads/orders/' . $code);
    if ($firma !== '') { $abs = __DIR__ . '/' . $firma; if (is_file($abs)) @unlink($abs); }
    json_out(['ok' => true]);
}

default:
    json_out(['ok' => false, 'error' => 'Acción desconocida'], 400);
}