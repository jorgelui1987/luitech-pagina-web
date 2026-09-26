<?php
/** LUITECH API - Auto-registro tactil PUBLICO (sin login, sin RUT). */
declare(strict_types=1);
require __DIR__ . '/config.php';
iniciar_respuesta_json();
preparar_clientes(db());
$action = $_GET['action'] ?? '';
function normalizar_whatsapp_cl(string $tel): ?string
{
    $d = preg_replace('/\D/', '', $tel) ?? '';
    $d = preg_replace('/^(00)?(56)?0?/', '', $d) ?? '';
    if (preg_match('/^9\d{8}$/', $d) === 1) { return $d; }
    return null;
}
function nombre_mascarado(string $nombre, string $telNorm): string
{
    $partes = preg_split('/\s+/', trim($nombre)) ?: [];
    $ini = mb_substr($partes[0] ?? 'Cliente', 0, 1);
    return $ini . '*** ****' . substr($telNorm, -4);
}
function generar_codigo_cli(PDO $pdo): string
{
    for ($i = 0; $i < 20; $i++) {
        $n = (int)$pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM clientes")->fetchColumn();
        $cod = 'CLI-' . str_pad((string)($n + $i), 4, '0', STR_PAD_LEFT);
        $st = $pdo->prepare('SELECT id FROM clientes WHERE codigo_cli = ? LIMIT 1');
        $st->execute([$cod]);
        if (!$st->fetch()) { return $cod; }
    }
    return 'CLI-' . str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
}
switch ($action) {
    case 'check': {
        if (!limitar_ip('registro_check', 60, 60)) {
            responder(['ok' => false, 'error' => 'Demasiadas consultas, espera un momento'], 429);
        }
        $norm = normalizar_whatsapp_cl((string)($_GET['telefono'] ?? ''));
        if ($norm === null) { responder(['ok' => true, 'existe' => false]); }
        $st = db()->prepare('SELECT nombre FROM clientes WHERE telefono_norm = ? AND activo = 1 LIMIT 1');
        $st->execute([$norm]);
        $fila = $st->fetch();
        if (!$fila) { responder(['ok' => true, 'existe' => false]); }
        responder(['ok' => true, 'existe' => true, 'mascara' => nombre_mascarado((string)$fila['nombre'], $norm)]);
    }
    case 'register': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Metodo no permitido'], 405);
        }
        if (!limitar_ip('registro_create', 15, 300)) {
            responder(['ok' => false, 'error' => 'Demasiados registros, avisa en mostrador'], 429);
        }
        $d = leer_cuerpo();
        $nombre = trim(mb_substr((string)($d['nombre'] ?? ''), 0, 60));
        $apellido = trim(mb_substr((string)($d['apellido'] ?? ''), 0, 80));
        $email = trim(mb_substr((string)($d['email'] ?? ''), 0, 120)) ?: null;
        $pin = (string)($d['pin_retiro'] ?? '');
        $acepta = !empty($d['acepta_privacidad']);
        $tipo = (string)($d['desbloqueo_tipo'] ?? 'ninguno');
        $clave = trim(mb_substr((string)($d['desbloqueo_clave'] ?? ''), 0, 60));
        if ($nombre === '' || mb_strlen($nombre) < 2) {
            responder(['ok' => false, 'error' => 'Escribe tu nombre'], 400);
        }
        $norm = normalizar_whatsapp_cl((string)($d['telefono'] ?? $d['whatsapp'] ?? ''));
        if ($norm === null) {
            responder(['ok' => false, 'error' => 'WhatsApp invalido: debe ser 9XXXXXXXX'], 400);
        }
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            responder(['ok' => false, 'error' => 'Email invalido (o dejalo vacio)'], 400);
        }
        if (!preg_match('/^\d{4}$/', $pin)) {
            responder(['ok' => false, 'error' => 'Crea un PIN de 4 digitos para retirar'], 400);
        }
        if (!$acepta) {
            responder(['ok' => false, 'error' => 'Marca la autorizacion de uso de tu WhatsApp'], 400);
        }
        if (!in_array($tipo, ['ninguno', 'pin', 'patron', 'sin_clave'], true)) { $tipo = 'ninguno'; }
        $guardado = null;
        if ($tipo === 'pin' || $tipo === 'patron') {
            if ($clave === '') {
                responder(['ok' => false, 'error' => 'Escribe o dibuja tu clave (o elige Sin clave)'], 400);
            }
            $guardado = 'ENC:' . base64_encode($clave);
        }
        $completo = trim($nombre . ' ' . $apellido);
        $lindo = '+56 9 ' . substr($norm, 1, 4) . ' ' . substr($norm, 5, 4);
        $st = db()->prepare('SELECT id, nombre, codigo_cli FROM clientes WHERE telefono_norm = ? AND activo = 1 LIMIT 1');
        $st->execute([$norm]);
        $ex = $st->fetch();
        if ($ex) {
            db()->prepare('UPDATE clientes SET nombre = ?, telefono = ?, email = COALESCE(?, email), pin_retiro_hash = ?, desbloqueo_tipo = ?, desbloqueo_clave = ?, origen = ?, estado_validacion = ?, acepta_privacidad = 1 WHERE id = ?')
                ->execute([$completo, $lindo, $email, password_hash($pin, PASSWORD_DEFAULT), $tipo, $guardado, 'tablet', 'pendiente', (int)$ex['id']]);
            responder(['ok' => true, 'reutilizado' => true, 'codigo_cli' => (string)($ex['codigo_cli'] ?: 'CLI'), 'nombre' => $completo]);
        }
        $codigoCli = generar_codigo_cli(db());
        try {
            db()->prepare('INSERT INTO clientes (nombre, apellido, telefono, telefono_norm, codigo_cli, email, pin_retiro_hash, desbloqueo_tipo, desbloqueo_clave, origen, estado_validacion, acepta_privacidad) VALUES (?,?,?,?,?,?,?,?,?,?,?,1)')
                ->execute([$completo, ($apellido ?: null), $lindo, $norm, $codigoCli, $email, password_hash($pin, PASSWORD_DEFAULT), $tipo, $guardado, 'tablet', 'pendiente']);
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                responder(['ok' => false, 'error' => 'Ese WhatsApp ya esta registrado, avisa en mostrador'], 409);
            }
            responder(['ok' => false, 'error' => 'No se pudo registrar, intenta de nuevo'], 500);
        }
        responder(['ok' => true, 'reutilizado' => false, 'codigo_cli' => $codigoCli, 'nombre' => $completo]);
    }
    default:
        responder(['ok' => false, 'error' => 'Accion desconocida'], 400);
}
