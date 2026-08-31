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

        $stmt = db()->prepare('SELECT id, usuario, password_hash, nombre FROM usuarios_admin WHERE usuario = ? LIMIT 1');
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
        $_SESSION['admin_id']   = (int)$admin['id'];
        $_SESSION['admin_user'] = $admin['usuario'];
        $_SESSION['admin_name'] = $admin['nombre'] !== '' ? $admin['nombre'] : $admin['usuario'];

        responder(['ok' => true, 'nombre' => $_SESSION['admin_name']]);
        break;

    // ---------------------------------------------------------------- ME
    case 'me':
        if (es_admin()) {
            responder(['ok' => true, 'logueado' => true, 'nombre' => $_SESSION['admin_name']]);
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
        if (strlen($nueva) < 8 || strlen($nueva) > 72) {
            responder(['ok' => false, 'error' => 'La nueva contraseña debe tener entre 8 y 72 caracteres'], 400);
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

    default:
        responder(['ok' => false, 'error' => 'Acción desconocida'], 400);
}
