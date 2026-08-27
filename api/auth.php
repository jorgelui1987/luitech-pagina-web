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

    default:
        responder(['ok' => false, 'error' => 'Acción desconocida'], 400);
}
