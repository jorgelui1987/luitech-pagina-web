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
 *   nota   (POST)                    Solo admin: agrega una entrada a la bitácora
 *   bitacora (GET)                   Solo admin: historial técnico de la orden
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

aplicar_zona_horaria();

iniciar_respuesta_json();

const ESTADOS_VALIDOS = ['Ingresado', 'En Diagnóstico', 'En Reparación', 'Listo para Retiro'];

/** Porcentaje estándar de avance al cambiar de estado (el tracker público
 *  enciende sus etapas según el estado; sin esto quedarían desincronizados). */
const AVANCE_POR_ESTADO = ['Ingresado' => 10, 'En Diagnóstico' => 30, 'En Reparación' => 60, 'Listo para Retiro' => 100];

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
function guardar_firma_base64(?string $dataUrl, string $codigo, string $etiqueta = 'ingreso'): ?string
{
    if ($dataUrl === null || $dataUrl === '') {
        return null;
    }
    if (preg_match('/^[a-z_]{3,20}$/', $etiqueta) !== 1) {
        $etiqueta = 'ingreso';
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
    $nombre = $codigo . '-' . $etiqueta . '-' . bin2hex(random_bytes(6)) . '.png';
    if (file_put_contents($dir . '/' . $nombre, $binarios) === false) {
        responder(['ok' => false, 'error' => 'No se pudo guardar la firma'], 500);
    }
    return 'uploads/firmas/' . $nombre;
}

/**
 * Registra un ingreso en la caja diaria abierta (si la hay).
 * Devuelve true si quedó registrado; false si no hay caja abierta.
 * El cobro de la orden NUNCA se bloquea por esto: la caja es complementaria.
 */
function registrar_ingreso_caja(PDO $pdo, int $monto, string $concepto): bool
{
    if ($monto < 1) {
        return false;
    }
    $sesion = $pdo->query(
        "SELECT id FROM caja_sesiones WHERE estado = 'Abierta' ORDER BY id DESC LIMIT 1"
    )->fetch();
    if (!$sesion) {
        return false;
    }
    $pdo->prepare('INSERT INTO movimientos_caja (sesion_id, tipo, concepto, monto) VALUES (?, ?, ?, ?)')
        ->execute([(int)$sesion['id'], 'Ingreso', $concepto, $monto]);
    return true;
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
            'SELECT codigo, equipo, falla, estado, avance, tecnico, fecha_ingreso, fecha_entrega
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
                    pin_patron, accesorios, obs_recepcion, firma_ingreso,
                    precio_repuestos, mano_obra, total, abono, estado_pago, metodo_pago, garantia_dias,
                    fecha_entrega, entregado_a, firma_entrega
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

        // Presupuesto (opcional al ingreso): repuestos + mano de obra = total
        $repuestos = max(0, (int)($d['precio_repuestos'] ?? 0));
        $manoObra  = max(0, (int)($d['mano_obra'] ?? 0));
        $total     = max(0, (int)($d['total'] ?? 0));
        if ($total === 0 && ($repuestos > 0 || $manoObra > 0)) {
            $total = $repuestos + $manoObra;
        }
        $garantia = max(0, min(365, (int)($d['garantia_dias'] ?? 0)));

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
                                      pin_patron, accesorios, obs_recepcion, firma_ingreso, fecha_ingreso,
                                      precio_repuestos, mano_obra, total, garantia_dias)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$codigo, $cliente, $equipo, $tipo, $falla, $estadoIn, $avanceIn, $tecnico,
                            $pin, $accs, $obs, $firmaRuta, $fecha, $repuestos, $manoObra, $total, $garantia]);

            // Primera entrada de la bitácora: el ingreso del equipo al taller
            db()->prepare('INSERT INTO orden_bitacora (orden_codigo, tecnico, nota, estado_nuevo) VALUES (?, ?, ?, ?)')
                ->execute([$codigo, $tecnico, 'Orden ingresada al taller', $estadoIn]);

            responder(['ok' => true, 'orden' => [
                'codigo' => $codigo, 'cliente' => $cliente, 'equipo' => $equipo,
                'tipo' => $tipo, 'falla' => $falla, 'estado' => $estadoIn,
                'avance' => $avanceIn, 'tecnico' => $tecnico, 'fecha_ingreso' => $fecha,
                'pin_patron' => $pin, 'accesorios' => $accs,
                'obs_recepcion' => $obs, 'firma_ingreso' => $firmaRuta,
                'precio_repuestos' => $repuestos, 'mano_obra' => $manoObra,
                'total' => $total, 'abono' => 0, 'estado_pago' => 'Pendiente',
                'garantia_dias' => $garantia,
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

        // Estado anterior (para dejar el cambio registrado en la bitácora)
        $estadoAnterior = null;
        $abonoAnterior  = null;
        if (isset($d['estado'])) {
            if (!in_array($d['estado'], ESTADOS_VALIDOS, true)) {
                responder(['ok' => false, 'error' => 'Estado inválido'], 400);
            }
            $st = db()->prepare('SELECT estado FROM ordenes WHERE codigo = ?');
            $st->execute([$codigo]);
            $estadoAnterior = (string)($st->fetchColumn() ?: '');
            $set[]    = 'estado = ?';
            $params[] = $d['estado'];
        }
        // Abono previo (para derivar cuánto dinero nuevo entra a la caja)
        if (isset($d['abono'])) {
            $stA = db()->prepare('SELECT abono FROM ordenes WHERE codigo = ?');
            $stA->execute([$codigo]);
            $abonoAnterior = (int)($stA->fetchColumn() ?: 0);
        }
        if (isset($d['avance'])) {
            $set[]    = 'avance = ?';
            $params[] = max(0, min(100, (int)$d['avance']));
        }
        // Cambiar el estado también avanza el porcentaje automáticamente
        // (si no viene un avance explícito en la misma petición)
        if (isset($d['estado']) && !isset($d['avance'])) {
            $set[]    = 'avance = ?';
            $params[] = AVANCE_POR_ESTADO[$d['estado']] ?? 10;
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

        // --- Cobro de la reparación (repuestos, mano de obra, total, abonos) ---
        if (isset($d['precio_repuestos'])) {
            $set[]    = 'precio_repuestos = ?';
            $params[] = max(0, (int)$d['precio_repuestos']);
        }
        if (isset($d['mano_obra'])) {
            $set[]    = 'mano_obra = ?';
            $params[] = max(0, (int)$d['mano_obra']);
        }
        if (isset($d['total'])) {
            $set[]    = 'total = ?';
            $params[] = max(0, (int)$d['total']);
        }
        if (isset($d['abono'])) {
            $set[]    = 'abono = ?';
            $params[] = max(0, (int)$d['abono']);
        }
        if (isset($d['garantia_dias'])) {
            $set[]    = 'garantia_dias = ?';
            $params[] = max(0, min(365, (int)$d['garantia_dias']));
        }
        if (isset($d['metodo_pago'])) {
            $metodo = in_array($d['metodo_pago'], ['Efectivo', 'Debito', 'Credito', 'Transferencia'], true)
                ? $d['metodo_pago'] : null;
            $set[]    = 'metodo_pago = ?';
            $params[] = $metodo;
        }

        // --- Entrega física del equipo: fecha, quién retira y su firma ---
        if (!empty($d['entregar'])) {
            $retira = campo_texto($d, 'entregado_a', 120);
            if ($retira === null) {
                responder(['ok' => false, 'error' => 'Indica quién retira el equipo'], 400);
            }
            $firmaEntrega = guardar_firma_base64(
                isset($d['firma_entrega']) ? (string)$d['firma_entrega'] : null,
                $codigo,
                'entrega'
            );
            $set[]    = 'fecha_entrega = NOW()';
            $set[]    = 'entregado_a = ?';
            $params[] = $retira;
            if ($firmaEntrega !== null) {
                $set[]    = 'firma_entrega = ?';
                $params[] = $firmaEntrega;
            }
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

        // El estado de pago se deriva de total/abono (nunca lo envía el cliente)
        if (isset($d['total']) || isset($d['abono'])) {
            $row = db()->prepare('SELECT total, abono FROM ordenes WHERE codigo = ?');
            $row->execute([$codigo]);
            $m = $row->fetch() ?: ['total' => 0, 'abono' => 0];
            $t = (int)$m['total'];
            $a = (int)$m['abono'];
            $estadoPago = ($t > 0 && $a >= $t) ? 'Pagado' : ($a > 0 ? 'Abonado' : 'Pendiente');
            db()->prepare('UPDATE ordenes SET estado_pago = ? WHERE codigo = ?')->execute([$estadoPago, $codigo]);
        }

        // Cada cobro (delta de abono) queda como ingreso en la caja diaria
        $avisoCaja = null;
        if (isset($d['abono']) && $abonoAnterior !== null) {
            $delta = max(0, (int)$d['abono'] - $abonoAnterior);
            if ($delta > 0) {
                $info = db()->prepare('SELECT cliente, metodo_pago FROM ordenes WHERE codigo = ?');
                $info->execute([$codigo]);
                $infoRow = $info->fetch() ?: [];
                $concepto = 'Cobro orden ' . $codigo;
                if (!empty($infoRow['metodo_pago'])) {
                    $concepto .= ' (' . $infoRow['metodo_pago'] . ')';
                }
                if (!empty($infoRow['cliente'])) {
                    $concepto .= ' — ' . $infoRow['cliente'];
                }
                if (!registrar_ingreso_caja(db(), $delta, $concepto)) {
                    $avisoCaja = 'Cobro registrado en la orden, pero no hay caja abierta: no se creó el movimiento de caja';
                }
            }
        }

        // Bitácora automática: cada cambio de estado queda registrado
        if ($estadoAnterior !== null && isset($d['estado']) && $d['estado'] !== $estadoAnterior) {
            db()->prepare('INSERT INTO orden_bitacora (orden_codigo, tecnico, nota, estado_nuevo) VALUES (?, ?, ?, ?)')
                ->execute([
                    $codigo,
                    (string)($d['tecnico'] ?? ''),
                    'Estado: ' . $estadoAnterior . ' → ' . $d['estado'],
                    $d['estado'],
                ]);
        }

        $stmt2 = db()->prepare(
            'SELECT codigo, estado, avance, tecnico, falla, precio_repuestos, mano_obra,
                    total, abono, estado_pago, metodo_pago, garantia_dias,
                    fecha_entrega, entregado_a, firma_entrega
             FROM ordenes WHERE codigo = ?'
        );
        $stmt2->execute([$codigo]);
        $respuesta = ['ok' => true, 'orden' => $stmt2->fetch()];
        if ($avisoCaja !== null) {
            $respuesta['aviso'] = $avisoCaja;
        }
        responder($respuesta);
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
        $stmtG = db()->prepare('SELECT firma_ingreso, firma_entrega FROM ordenes WHERE codigo = ?');
        $stmtG->execute([$codigo]);
        $firmas = $stmtG->fetch() ?: [];
        $rutasFirmas = array_values(array_filter([
            (string)($firmas['firma_ingreso'] ?? ''),
            (string)($firmas['firma_entrega'] ?? ''),
        ]));

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
        foreach ($rutasFirmas as $rel) {
            $abs = ruta_uploads((string)$rel);
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

    /* ------------------------------------------------ BITÁCORA NOTA (POST) */
    case 'nota': {
        exigir_admin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $d      = leer_cuerpo();
        $codigo = strtoupper(trim((string)($d['codigo'] ?? '')));
        if (!preg_match('/^LUH-\d{3,8}$/', $codigo)) {
            responder(['ok' => false, 'error' => 'Código inválido'], 400);
        }
        $nota = campo_texto($d, 'nota', 500);
        if ($nota === null) {
            responder(['ok' => false, 'error' => 'La nota está vacía'], 400);
        }
        $existe = db()->prepare('SELECT 1 FROM ordenes WHERE codigo = ?');
        $existe->execute([$codigo]);
        if (!$existe->fetch()) {
            responder(['ok' => false, 'error' => 'Orden no encontrada'], 404);
        }
        $tecnico = campo_texto($d, 'tecnico', 80) ?? '';
        db()->prepare('INSERT INTO orden_bitacora (orden_codigo, tecnico, nota) VALUES (?, ?, ?)')
            ->execute([$codigo, $tecnico, $nota]);
        responder(['ok' => true]);
    }

    /* ------------------------------------------------ BITÁCORA GET (lista) */
    case 'bitacora': {
        exigir_admin();
        $codigo = strtoupper(trim($_GET['codigo'] ?? ''));
        if (!preg_match('/^LUH-\d{3,8}$/', $codigo)) {
            responder(['ok' => false, 'error' => 'Código inválido'], 400);
        }
        $stmt = db()->prepare(
            'SELECT id, tecnico, nota, estado_nuevo, creado_en
             FROM orden_bitacora WHERE orden_codigo = ? ORDER BY id DESC LIMIT 200'
        );
        $stmt->execute([$codigo]);
        responder(['ok' => true, 'bitacora' => $stmt->fetchAll()]);
    }

    default:
        responder(['ok' => false, 'error' => 'Acción desconocida'], 400);
}


