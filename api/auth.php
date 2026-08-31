<?php
/**
 * LUITECH API - Autenticación del panel administrativo.
 * Acciones (GET param ?action=):
 *   login  (POST) {usuario, password}  -> inicia sesión
 *   me     (GET)                       -> estado de sesión actual
 *   logout (POST)                      -> cierra sesión
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

iniciar_respuesta_json();
iniciar_sesion();

$maxIntentos   = 5;
$ventanaBloqueo = 10 * 60; // segundos

$action = $_GET['action'] ?? '';

switch ($action) {

    // ------------------------------------------------------------- LOGIN
    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }

        // Bloqueo temporal tras intentos fallidos repetidos
        $ahora = time();
        $_SESSION['auth_fails']    = $_SESSION['auth_fails']    ?? [];
        $_SESSION['auth_bloqueado']= $_SESSION['auth_bloqueado'] ?? 0;

        if ($_SESSION['auth_bloqueado'] > $ahora) {
            $espera = ceil(($_SESSION['auth_bloqueado'] - $ahora) / 60);
            responder(['ok' => false, 'error' => "Demasiados intentos. Reintenta en {$espera} min."], 429);
        }

        // Límite por IP (independiente de la sesión: frena bots que rotan cookies)
        if (!limitar_ip('login', 20, 600)) {
            responder(['ok' => false, 'error' => 'Demasiados intentos desde tu conexión. Espera unos minutos.'], 429);
        }

        $cuerpo   = leer_cuerpo();
        $usuario  = campo_texto($cuerpo, 'usuario', 50);
        $password = (string)($cuerpo['password'] ?? '');

        if ($usuario === null || $password === '' || strlen($password) > 255) {
            responder(['ok' => false, 'error' => 'Usuario o contraseña inválidos'], 400);
        }

        $stmt = db()->prepare('SELECT id, usuario, password_hash, nombre, rol, tecnico_id, totp_enabled, totp_secret FROM usuarios_admin WHERE usuario = ? LIMIT 1');
        $stmt->execute([$usuario]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            $_SESSION['auth_fails'][] = $ahora;
            $_SESSION['auth_fails']   = array_values(array_filter($_SESSION['auth_fails'], fn($t) => $t > $ahora - $ventanaBloqueo));
            if (count($_SESSION['auth_fails']) >= $maxIntentos) {
                $_SESSION['auth_bloqueado'] = $ahora + $ventanaBloqueo;
                $_SESSION['auth_fails']     = [];
            }
            usleep(300000); // frena ataques automatizados
            responder(['ok' => false, 'error' => 'Credenciales incorrectas'], 401);
        }

        session_regenerate_id(true);
        unset($_SESSION['auth_fails'], $_SESSION['auth_bloqueado']);

        // Doble factor activo: la sesión queda PENDIENTE del código de 6 dígitos
        if ((int)($admin['totp_enabled'] ?? 0) === 1 && !empty($admin['totp_secret'])) {
            $_SESSION['totp_pendiente'] = ['id' => (int)$admin['id'], 'exp' => time() + 300];
            responder(['ok' => true, 'paso' => '2fa', 'nombre' => $admin['nombre'] !== '' ? $admin['nombre'] : $admin['usuario']]);
        }

        $_SESSION['admin_id']   = (int)$admin['id'];
        $_SESSION['admin_user'] = $admin['usuario'];
        $_SESSION['admin_name'] = $admin['nombre'] !== '' ? $admin['nombre'] : $admin['usuario'];
        $_SESSION['admin_rol']  = ($admin['rol'] ?? 'admin') === 'tecnico' ? 'tecnico' : 'admin';
        $_SESSION['admin_tecnico_id'] = $admin['tecnico_id'] !== null ? (int)$admin['tecnico_id'] : null;

        responder(['ok' => true, 'nombre' => $_SESSION['admin_name'], 'rol' => $_SESSION['admin_rol']]);
        break;

    // ------------------------------------------------------ LOGIN PASO 2FA
    case 'login_2fa': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $pend = $_SESSION['totp_pendiente'] ?? null;
        if (!is_array($pend) || (int)($pend['exp'] ?? 0) < time()) {
            unset($_SESSION['totp_pendiente']);
            responder(['ok' => false, 'error' => 'La verificación expiró: inicia sesión de nuevo'], 401);
        }
        // Límite por IP también en el paso 2FA (frena bombardeo de códigos)
        if (!limitar_ip('2fa', 20, 600)) {
            responder(['ok' => false, 'error' => 'Demasiados intentos desde tu conexión. Espera unos minutos.'], 429);
        }
        $codigo = trim((string)(leer_cuerpo()['codigo'] ?? ''));
        if ($codigo === '') {
            responder(['ok' => false, 'error' => 'Ingresa el código de 6 dígitos'], 400);
        }
        $st = db()->prepare('SELECT id, usuario, nombre, rol, tecnico_id, totp_enabled, totp_secret, totp_backup FROM usuarios_admin WHERE id = ? LIMIT 1');
        $st->execute([(int)$pend['id']]);
        $admin = $st->fetch();
        if (!$admin || (int)$admin['totp_enabled'] !== 1) {
            unset($_SESSION['totp_pendiente']);
            responder(['ok' => false, 'error' => 'Reinicia la sesión'], 401);
        }
        $aceptado = totp_verificar((string)$admin['totp_secret'], $codigo);
        if (!$aceptado) {
            // códigos de respaldo (un solo uso, guardados como sha256)
            $lista = json_decode((string)($admin['totp_backup'] ?? ''), true);
            if (is_array($lista)) {
                $pos = array_search(hash('sha256', $codigo), $lista, true);
                if ($pos !== false) {
                    $aceptado = true;
                    array_splice($lista, (int)$pos, 1);
                    db()->prepare('UPDATE usuarios_admin SET totp_backup = ? WHERE id = ?')
                         ->execute([json_encode(array_values($lista)), (int)$admin['id']]);
                }
            }
        }
        if (!$aceptado) {
            usleep(300000);
            responder(['ok' => false, 'error' => 'Código incorrecto'], 401);
        }
        session_regenerate_id(true);
        unset($_SESSION['totp_pendiente'], $_SESSION['auth_fails'], $_SESSION['auth_bloqueado']);
        $_SESSION['admin_id']   = (int)$admin['id'];
        $_SESSION['admin_user'] = $admin['usuario'];
        $_SESSION['admin_name'] = $admin['nombre'] !== '' ? $admin['nombre'] : $admin['usuario'];
        $_SESSION['admin_rol']  = ($admin['rol'] ?? 'admin') === 'tecnico' ? 'tecnico' : 'admin';
        $_SESSION['admin_tecnico_id'] = $admin['tecnico_id'] !== null ? (int)$admin['tecnico_id'] : null;
        responder(['ok' => true, 'nombre' => $_SESSION['admin_name'], 'rol' => $_SESSION['admin_rol']]);
        break;
    }

    // ---------------------------------------------------------------- ME
    case 'me':
        if (es_admin()) {
            $st = db()->prepare('SELECT rol, totp_enabled FROM usuarios_admin WHERE id = ? LIMIT 1');
            $st->execute([(int)$_SESSION['admin_id']]);
            $u = $st->fetch() ?: [];
            responder(['ok' => true, 'logueado' => true, 'nombre' => $_SESSION['admin_name'],
                       'rol' => ($u['rol'] ?? 'admin') === 'tecnico' ? 'tecnico' : 'admin',
                       'tecnico_id' => isset($u['tecnico_id']) && $u['tecnico_id'] !== null ? (int)$u['tecnico_id'] : null,
                       'totp_enabled' => (int)($u['totp_enabled'] ?? 0) === 1]);
        }
        responder(['ok' => true, 'logueado' => false]);
        break;

    // ------------------------------------------------------------- LOGOUT
    case 'logout':
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            setcookie(session_name(), '', time() - 42000, '/');
        }
        session_destroy();
        responder(['ok' => true]);
        break;

    /* --------------------------------------------- CAMBIAR CLAVE PROPIA */
    case 'cambiar_clave': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        if (!es_admin()) {
            responder(['ok' => false, 'error' => 'No autorizado'], 401);
        }
        $d          = leer_cuerpo();
        $actual     = (string)($d['clave_actual'] ?? '');
        $nueva      = (string)($d['nueva'] ?? '');
        $repetida   = (string)($d['repetida'] ?? '');

        if ($nueva !== $repetida) {
            responder(['ok' => false, 'error' => 'La contraseña nueva y su repetición no coinciden'], 400);
        }
        if (strlen($nueva) > 72) {
            responder(['ok' => false, 'error' => 'La contraseña no puede superar 72 caracteres'], 400);
        }
        $errorPolitica = validar_politica_password($nueva);
        if ($errorPolitica !== null) {
            responder(['ok' => false, 'error' => $errorPolitica . ' (mínimo 10: mayúscula, minúscula, número y carácter especial)'], 400);
        }

        $st = db()->prepare('SELECT id, password_hash FROM usuarios_admin WHERE id = ? LIMIT 1');
        $st->execute([(int)$_SESSION['admin_id']]);
        $fila = $st->fetch();
        if (!$fila || !password_verify($actual, $fila['password_hash'])) {
            usleep(300000);
            responder(['ok' => false, 'error' => 'La contraseña actual es incorrecta'], 401);
        }
        db()->prepare('UPDATE usuarios_admin SET password_hash = ? WHERE id = ?')
             ->execute([password_hash($nueva, PASSWORD_BCRYPT), (int)$fila['id']]);
        responder(['ok' => true]);
    }

    /* ------------------------------------------------ DOBLE FACTOR (TOTP) */
    case 'totp_inicio': {
        if (!es_admin()) { responder(['ok' => false, 'error' => 'No autorizado'], 401); }
        $secreto = totp_generar_secreto();
        $st = db()->prepare('SELECT usuario FROM usuarios_admin WHERE id = ? LIMIT 1');
        $st->execute([(int)$_SESSION['admin_id']]);
        $u = $st->fetch();
        db()->prepare('UPDATE usuarios_admin SET totp_secret = ?, totp_enabled = 0 WHERE id = ?')
             ->execute([$secreto, (int)$_SESSION['admin_id']]);
        $uri = 'otpauth://totp/' . rawurlencode('Luitech:' . ($u['usuario'] ?? 'admin'))
             . '?secret=' . $secreto . '&issuer=Luitech&algorithm=SHA1&digits=6&period=30';
        responder(['ok' => true, 'secreto' => $secreto, 'uri' => $uri]);
    }

    case 'totp_confirmar': {
        if (!es_admin()) { responder(['ok' => false, 'error' => 'No autorizado'], 401); }
        $codigo = trim((string)(leer_cuerpo()['codigo'] ?? ''));
        $st = db()->prepare('SELECT totp_secret FROM usuarios_admin WHERE id = ? LIMIT 1');
        $st->execute([(int)$_SESSION['admin_id']]);
        $secreto = (string)($st->fetch()['totp_secret'] ?? '');
        if ($secreto === '') {
            responder(['ok' => false, 'error' => 'Primero inicia la activación (genera el QR)'], 400);
        }
        if (!totp_verificar($secreto, $codigo)) {
            responder(['ok' => false, 'error' => 'Código incorrecto: usa el código ACTUAL de la app e intenta de nuevo'], 400);
        }
        $codigos = [];
        $hashes = [];
        for ($i = 0; $i < 5; $i++) {
            $c = strtoupper(bin2hex(random_bytes(4)));
            $codigos[] = $c;
            $hashes[] = hash('sha256', $c);
        }
        db()->prepare('UPDATE usuarios_admin SET totp_enabled = 1, totp_backup = ? WHERE id = ?')
             ->execute([json_encode($hashes), (int)$_SESSION['admin_id']]);
        responder(['ok' => true, 'codigos_respaldo' => $codigos]);
    }

    case 'totp_desactivar': {
        if (!es_admin()) { responder(['ok' => false, 'error' => 'No autorizado'], 401); }
        $d = leer_cuerpo();
        $pass = (string)($d['password'] ?? '');
        $st = db()->prepare('SELECT password_hash FROM usuarios_admin WHERE id = ? LIMIT 1');
        $st->execute([(int)$_SESSION['admin_id']]);
        $fila = $st->fetch();
        if (!$fila || !password_verify($pass, $fila['password_hash'])) {
            usleep(300000);
            responder(['ok' => false, 'error' => 'Contraseña incorrecta'], 401);
        }
        db()->prepare('UPDATE usuarios_admin SET totp_secret = NULL, totp_enabled = 0, totp_backup = NULL WHERE id = ?')
             ->execute([(int)$_SESSION['admin_id']]);
        responder(['ok' => true]);
    }

    default:
        responder(['ok' => false, 'error' => 'Acción desconocida'], 400);
}
