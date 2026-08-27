<?php
/**
 * LUITECH API - Órdenes de trabajo.
 * Acciones (GET param ?action=):
 *   track  (GET)  ?codigo=LUH-1024   Público: consulta individual (sin datos personales)
 *   resumen(GET)                     Público: lista ligera para pantalla TV de sala
 *   list   (GET)                     Solo admin: todas las órdenes completas
 *   create (POST)                    Solo admin: nueva orden (+ acta de recepción)
 *   update (POST)                    Solo admin: cambia estado/avance u otros campos
 *   delete (POST)                    Solo admin: elimina orden y sus archivos
 *   fotos  (GET)                     Solo admin: fotos de respaldo de una orden
 *   subir_foto (POST multipart)      Solo admin: sube foto de respaldo
 *   borrar_foto (POST)               Solo admin: elimina una foto
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

iniciar_respuesta_json();

const ESTADOS_VALIDOS = ['Ingresado', 'En Diagnóstico', 'En Reparación', 'Listo para Retiro'];

/* ---------------------------------------------------------------------
 * Archivos del acta de recepción (fotos de respaldo y firma del cliente)
 * ------------------------------------------------------------------- */
const MAX_FOTOS_ORDEN = 12;        // máximo de fotos acumuladas por orden
const MAX_BYTES_FOTO  = 5242880;   // 5 MB por foto
const MAX_BYTES_FIRMA = 614400;    // ~600 KB de PNG decodificado para la firma

/** Carpeta física donde se guardan los archivos subidos. */
function base_uploads(): string
{
    return __DIR__ . '/../uploads';
}

/**
 * Convierte una ruta relativa guardada en BD ('uploads/ordenes/...') en ruta
 * física dentro de uploads/, validando que no escape de esa carpeta.
 * Devuelve '' si la ruta es inválida.
 */
function ruta_uploads(string $relativa): string
{
    if (preg_match('#^[a-z0-9_\-]+(/[a-zA-Z0-9._\-]+)+$#', $relativa) !== 1
        || !str_starts_with($relativa, 'uploads/')) {
        return '';
    }
    $base = realpath(base_uploads());
    $abs  = realpath(base_uploads() . '/../' . $relativa);
    if ($base === false || $abs === false || !str_starts_with($abs, $base . DIRECTORY_SEPARATOR)) {
        return '';
    }
    return $abs;
}

/**
 * Valida y guarda un dataURL PNG (firma dibujada en canvas).
 * Devuelve la ruta relativa ('uploads/firmas/...') o null si no vino firma.
 */
function guardar_firma_base64(?string $dataUrl, string $codigo): ?string
{
    if ($dataUrl === null || $dataUrl === '') {
        return null;
    }
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\r\n]+)$#', $dataUrl, $m)) {
        responder(['ok' => false, 'error' => 'Formato de firma inválido (se espera PNG base64)'], 400);
    }
    $binarios = base64_decode(str_replace(["\r", "\n"], '', $m[1]), true);
    if ($binarios === false || $binarios === '' || strlen($binarios) > MAX_BYTES_FIRMA) {
        responder(['ok' => false, 'error' => 'La firma es inválida o demasiado grande'], 400);
    }
    if (strncmp($binarios, "\x89PNG\r\n\x1a\n", 8) !== 0) {
        responder(['ok' => false, 'error' => 'La firma no es una imagen PNG válida'], 400);
    }
    $dir = base_uploads() . '/firmas';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        responder(['ok' => false, 'error' => 'No se pudo crear la carpeta de firmas'], 500);
    }
    $nombre = $codigo . '-ingreso-' . bin2hex(random_bytes(6)) . '.png';
    if (file_put_contents($dir . '/' . $nombre, $binarios) === false) {
        responder(['ok' => false, 'error' => 'No se pudo guardar la firma'], 500);
    }
    return 'uploads/firmas/' . $nombre;
}

$action = $_GET['action'] ?? '';

switch ($action) {

    /* ------------------------------------------------------------ TRACK */
    case 'track': {
        $codigo = strtoupper(trim($_GET['codigo'] ?? ''));
        if (!preg_match('/^LUH-\d{3,8}$/', $codigo)) {
            responder(['ok' => false, 'error' => 'Formato de código inválido (ej: LUH-1024)'], 400);
        }

        $stmt = db()->prepare(
            'SELECT codigo, equipo, falla, estado, avance, tecnico, fecha_ingreso
             FROM ordenes WHERE codigo = ? LIMIT 1'
        );
        $stmt->execute([$codigo]);
        $orden = $stmt->fetch();

        if (!$orden) {
            responder(['ok' => false, 'error' => 'Orden no encontrada'], 404);
        }

        // Nota: se omite deliberadamente el nombre del cliente (privacidad pública)
        responder(['ok' => true, 'orden' => $orden]);
    }

    /* ---------------------------------------------------------- RESUMEN */
    case 'resumen': {
        $stmt = db()->query(
            "SELECT codigo, equipo, estado, avance FROM ordenes
             WHERE estado IN ('Listo para Retiro','En Reparación','En Diagnóstico')
             ORDER BY FIELD(estado,'Listo para Retiro','En Reparación','En Diagnóstico'), id DESC"
        );
        responder(['ok' => true, 'ordenes' => $stmt->fetchAll()]);
    }


    /* ------------------------------------------------------------- LIST */
    case 'list': {
        exigir_admin();
        $stmt = db()->query(
            'SELECT id, codigo, cliente, equipo, tipo, falla, estado, avance, tecnico, fecha_ingreso,
                    pin_patron, accesorios, obs_recepcion, firma_ingreso
             FROM ordenes ORDER BY id DESC'
        );
        responder(['ok' => true, 'ordenes' => $stmt->fetchAll()]);
    }

    /* ----------------------------------------------------------- CREATE */
    case 'create': {
        exigir_admin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }

        $d = leer_cuerpo();

        $cliente = campo_texto($d, 'cliente', 120);
        $equipo  = campo_texto($d, 'equipo', 120);
        $falla   = campo_texto($d, 'falla', 1000);
        $tecnico = campo_texto($d, 'tecnico', 80) ?? 'Por Asignar';
        $tipo    = in_array(($d['tipo'] ?? ''), ['Celular', 'PC/Notebook', 'Otro'], true) ? $d['tipo'] : 'Otro';

        // Acta de recepción (opcionales)
        $pin  = campo_texto($d, 'pin_patron', 50);
        $accs = campo_texto($d, 'accesorios', 255);
        $obs  = campo_texto($d, 'obs_recepcion', 250);

        if ($cliente === null || $equipo === null || $falla === null) {
            responder(['ok' => false, 'error' => 'Faltan campos obligatorios (cliente, equipo, falla)'], 400);
        }

        // Código: autogenerado como siguiente correlativo (LUH-nnnn) si no viene uno válido
        $codigo = strtoupper(trim((string)($d['codigo'] ?? '')));
        if ($codigo !== '' && !preg_match('/^LUH-\d{3,8}$/', $codigo)) {
            responder(['ok' => false, 'error' => 'El código debe tener formato LUH-número (ej: LUH-1029)'], 400);
        }
        if ($codigo === '') {
            $siguiente = (int)(db()->query(
                "SELECT COALESCE(MAX(CAST(SUBSTRING(codigo, 5) AS UNSIGNED)), 1023) AS m FROM ordenes"
            )->fetch()['m'] ?? 1023) + 1;
            $codigo = 'LUH-' . $siguiente;
        }

        try {
            $fecha    = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['fecha'] ?? '')) ? $d['fecha'] : date('Y-m-d');
            $estadoIn = in_array(($d['estado'] ?? ''), ESTADOS_VALIDOS, true) ? $d['estado'] : 'Ingresado';
            $avanceIn = max(0, min(100, (int)($d['avance'] ?? 10)));
            $firmaRuta = guardar_firma_base64(isset($d['firma']) ? (string)$d['firma'] : null, $codigo);

            $stmt = db()->prepare(
                'INSERT INTO ordenes (codigo, cliente, equipo, tipo, falla, estado, avance, tecnico,
                                      pin_patron, accesorios, obs_recepcion, firma_ingreso, fecha_ingreso)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$codigo, $cliente, $equipo, $tipo, $falla, $estadoIn, $avanceIn, $tecnico,
                            $pin, $accs, $obs, $firmaRuta, $fecha]);

            responder(['ok' => true, 'orden' => [
                'codigo' => $codigo, 'cliente' => $cliente, 'equipo' => $equipo,
                'tipo' => $tipo, 'falla' => $falla, 'estado' => $estadoIn,
                'avance' => $avanceIn, 'tecnico' => $tecnico, 'fecha_ingreso' => $fecha,
                'pin_patron' => $pin, 'accesorios' => $accs,
                'obs_recepcion' => $obs, 'firma_ingreso' => $firmaRuta,
            ]]);
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000) {
                responder(['ok' => false, 'error' => 'Ya existe una orden con ese código'], 409);
            }
            throw $e;
        }
    }

    /* ----------------------------------------------------------- UPDATE */
    case 'update': {
        exigir_admin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }

        $d      = leer_cuerpo();
        $codigo = strtoupper(trim((string)($d['codigo'] ?? '')));
        if (!preg_match('/^LUH-\d{3,8}$/', $codigo)) {
            responder(['ok' => false, 'error' => 'Código inválido'], 400);
        }

        $set    = [];
        $params = [];

        if (isset($d['estado'])) {
            if (!in_array($d['estado'], ESTADOS_VALIDOS, true)) {
                responder(['ok' => false, 'error' => 'Estado inválido'], 400);
            }
            $set[]    = 'estado = ?';
            $params[] = $d['estado'];
        }
        if (isset($d['avance'])) {
            $set[]    = 'avance = ?';
            $params[] = max(0, min(100, (int)$d['avance']));
        }
        if (isset($d['falla'])) {
            $falla = campo_texto($d, 'falla', 1000);
            if ($falla === null) {
                responder(['ok' => false, 'error' => 'Detalle inválido'], 400);
            }
            $set[]    = 'falla = ?';
            $params[] = $falla;
        }
        if (isset($d['tecnico'])) {
            $set[]    = 'tecnico = ?';
            $params[] = campo_texto($d, 'tecnico', 80) ?? 'Por Asignar';
        }

        if (!$set) {
            responder(['ok' => false, 'error' => 'Nada que actualizar'], 400);
        }

        $params[] = $codigo;
        $stmt = db()->prepare('UPDATE ordenes SET ' . implode(', ', $set) . ' WHERE codigo = ?');
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            $existe = db()->prepare('SELECT 1 FROM ordenes WHERE codigo = ?');
            $existe->execute([$codigo]);
            if (!$existe->fetch()) {
                responder(['ok' => false, 'error' => 'Orden no encontrada'], 404);
            }
        }

        $stmt2 = db()->prepare('SELECT codigo, estado, avance, tecnico, falla FROM ordenes WHERE codigo = ?');
        $stmt2->execute([$codigo]);
        responder(['ok' => true, 'orden' => $stmt2->fetch()]);
    }

    /* ----------------------------------------------------------- DELETE */
    case 'delete': {
        exigir_admin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $d      = leer_cuerpo();
        $codigo = strtoupper(trim((string)($d['codigo'] ?? '')));
        if (!preg_match('/^LUH-\d{3,8}$/', $codigo)) {
            responder(['ok' => false, 'error' => 'Código inválido'], 400);
        }

        // Archivos asociados (se borran del disco solo si la orden existía)
        $stmtF = db()->prepare('SELECT archivo FROM orden_fotos WHERE orden_codigo = ?');
        $stmtF->execute([$codigo]);
        $archivosFotos = $stmtF->fetchAll(PDO::FETCH_COLUMN);
        $stmtG = db()->prepare('SELECT firma_ingreso FROM ordenes WHERE codigo = ?');
        $stmtG->execute([$codigo]);
        $firmaRuta = (string)($stmtG->fetchColumn() ?: '');

        $stmt = db()->prepare('DELETE FROM ordenes WHERE codigo = ?');
        $stmt->execute([$codigo]);
        if ($stmt->rowCount() === 0) {
            responder(['ok' => false, 'error' => 'Orden no encontrada'], 404);
        }

        // El ON DELETE CASCADE ya limpió orden_fotos; ahora los archivos físicos
        foreach ($archivosFotos as $rel) {
            $abs = ruta_uploads((string)$rel);
            if ($abs !== '' && is_file($abs)) {
                @unlink($abs);
            }
        }
        if (is_dir(base_uploads() . '/ordenes/' . $codigo)) {
            @rmdir(base_uploads() . '/ordenes/' . $codigo); // solo si quedó vacía
        }
        if ($firmaRuta !== '') {
            $abs = ruta_uploads($firmaRuta);
            if ($abs !== '' && is_file($abs)) {
                @unlink($abs);
            }
        }
        responder(['ok' => true]);
    }

    /* --------------------------------------------------------- FOTOS GET */
    case 'fotos': {
        exigir_admin();
        $codigo = strtoupper(trim($_GET['codigo'] ?? ''));
        if (!preg_match('/^LUH-\d{3,8}$/', $codigo)) {
            responder(['ok' => false, 'error' => 'Código inválido'], 400);
        }
        $stmt = db()->prepare(
            'SELECT id, archivo, creado_en FROM orden_fotos WHERE orden_codigo = ? ORDER BY id ASC'
        );
        $stmt->execute([$codigo]);
        responder(['ok' => true, 'fotos' => $stmt->fetchAll()]);
    }

    /* ------------------------------------------------- FOTOS SUBIR (POST) */
    case 'subir_foto': {
        exigir_admin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }

        $codigo = strtoupper(trim((string)($_POST['codigo'] ?? '')));
        if (!preg_match('/^LUH-\d{3,8}$/', $codigo)) {
            responder(['ok' => false, 'error' => 'Código inválido'], 400);
        }
        $existe = db()->prepare('SELECT 1 FROM ordenes WHERE codigo = ?');
        $existe->execute([$codigo]);
        if (!$existe->fetch()) {
            responder(['ok' => false, 'error' => 'Orden no encontrada'], 404);
        }

        $stmtC = db()->prepare('SELECT COUNT(*) FROM orden_fotos WHERE orden_codigo = ?');
        $stmtC->execute([$codigo]);
        if ((int)$stmtC->fetchColumn() >= MAX_FOTOS_ORDEN) {
            responder(['ok' => false, 'error' => 'Esta orden ya alcanzó el máximo de ' . MAX_FOTOS_ORDEN . ' fotos'], 400);
        }

        $f = $_FILES['foto'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
            $mensaje = ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE)
                ? 'La foto excede el tamaño permitido por el servidor'
                : 'No se recibió ninguna foto';
            responder(['ok' => false, 'error' => $mensaje], 400);
        }
        if ($f['size'] <= 0 || $f['size'] > MAX_BYTES_FOTO) {
            responder(['ok' => false, 'error' => 'La foto debe pesar entre 0 y 5 MB'], 400);
        }
        if (!is_uploaded_file($f['tmp_name'])) {
            responder(['ok' => false, 'error' => 'Subida inválida'], 400);
        }

        // Validar el contenido real (no confiar en la extensión ni en el nombre)
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $extensiones = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensiones[$mime]) || @getimagesize($f['tmp_name']) === false) {
            responder(['ok' => false, 'error' => 'Solo se aceptan fotos JPG, PNG o WebP'], 400);
        }

        $dir = base_uploads() . '/ordenes/' . $codigo;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            responder(['ok' => false, 'error' => 'No se pudo crear la carpeta de la orden'], 500);
        }

        // Nombre aleatorio e impredecible: nunca el nombre original del cliente
        $nombre  = bin2hex(random_bytes(8)) . '-' . time() . '.' . $extensiones[$mime];
        $relativa = 'uploads/ordenes/' . $codigo . '/' . $nombre;
        if (!move_uploaded_file($f['tmp_name'], base_uploads() . '/ordenes/' . $codigo . '/' . $nombre)) {
            responder(['ok' => false, 'error' => 'No se pudo guardar la foto en el servidor'], 500);
        }

        db()->prepare('INSERT INTO orden_fotos (orden_codigo, archivo) VALUES (?, ?)')
            ->execute([$codigo, $relativa]);

        responder(['ok' => true, 'foto' => [
            'id' => (int)db()->lastInsertId(),
            'archivo' => $relativa,
        ]]);
    }

    /* ------------------------------------------------- FOTOS BORRAR (POST) */
    case 'borrar_foto': {
        exigir_admin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $d  = leer_cuerpo();
        $id = (int)($d['id'] ?? 0);
        if ($id <= 0) {
            responder(['ok' => false, 'error' => 'Identificador de foto inválido'], 400);
        }

        $stmt = db()->prepare('SELECT id, archivo FROM orden_fotos WHERE id = ?');
        $stmt->execute([$id]);
        $foto = $stmt->fetch();
        if (!$foto) {
            responder(['ok' => false, 'error' => 'Foto no encontrada'], 404);
        }

        db()->prepare('DELETE FROM orden_fotos WHERE id = ?')->execute([$id]);
        $abs = ruta_uploads((string)$foto['archivo']);
        if ($abs !== '' && is_file($abs)) {
            @unlink($abs);
        }
        responder(['ok' => true]);
    }

    default:
        responder(['ok' => false, 'error' => 'Acción desconocida'], 400);
}


