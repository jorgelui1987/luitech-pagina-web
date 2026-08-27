<?php
/**
 * Módulo SISTEMA (solo rol admin):
 *   cfg_get / cfg_set · usuarios CRUD · auditoria_list
 *   backup (export JSON descargable) · restore (POST JSON)
 */
declare(strict_types=1);
require __DIR__ . '/config.php';
iniciar_respuesta_json();
exigir_rol(['admin']);

$action = $_GET['action'] ?? '';
$TABLAS = ['ordenes','productos','ventas','venta_items','clientes','proveedores',
           'compras','compra_items','devoluciones','garantias','gastos_fijos',
           'gastos','caja_sesiones','movimientos_caja','movimientos_stock'];

switch ($action) {

    case 'cfg_get':
        responder(['ok' => true, 'items' => db()->query('SELECT k, v FROM configuracion ORDER BY k')->fetchAll()]);
        break;

    case 'cfg_set': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { responder(['ok' => false, 'error' => 'Método no permitido'], 405); }
        foreach (leer_cuerpo() as $k => $v) {
            if (!preg_match('/^[a-z_]{2,60}$/', $k)) { continue; }
            db()->prepare('INSERT INTO configuracion (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')
                 ->execute([$k, trim((string)$v)]);
        }
        log_audit('config_set', json_encode(array_keys((array)leer_cuerpo())));
        responder(['ok' => true]);
        break;
    }

    /* ---------------------------------------------------------- USUARIOS */
    case 'usuarios':
        responder(['ok' => true,
                   'usuarios' => db()->query('SELECT id, usuario, nombre, rol FROM usuarios_admin ORDER BY id')->fetchAll()]);
        break;

    case 'usuario_create': {
        $d = leer_cuerpo();
        $u = campo_texto($d, 'usuario', 50);
        $p = (string)($d['password'] ?? '');
        $r = in_array(($d['rol'] ?? ''), ['admin','vendedor','tecnico'], true) ? $d['rol'] : 'vendedor';
        if ($u === null || strlen($p) < 8) {
            responder(['ok' => false, 'error' => 'Usuario y contraseña de 8+ caracteres requeridos'], 400);
        }
        try {
            db()->prepare('INSERT INTO usuarios_admin (usuario, password_hash, nombre, rol) VALUES (?, ?, ?, ?)')
                 ->execute([$u, password_hash($p, PASSWORD_BCRYPT), campo_texto($d,'nombre',80) ?? '', $r]);
        } catch (PDOException $e) {
            responder(['ok' => false, 'error' => 'Ese usuario ya existe'], 409);
        }
        log_audit('usuario_crear', "$u ($r)");
        responder(['ok' => true]);
        break;
    }

    case 'usuario_update': {
        $d = leer_cuerpo();
        $id = (int)($d['id'] ?? 0);
        $rol = in_array(($d['rol'] ?? ''), ['admin','vendedor','tecnico'], true) ? $d['rol'] : null;
        $nombre = campo_texto($d, 'nombre', 80);
        if ($id <= 0 || (!$rol && $nombre === null)) {
            responder(['ok' => false, 'error' => 'Nada que actualizar'], 400);
        }
        iniciar_sesion();
        if ((int)$_SESSION['admin_id'] === $id && $rol !== null && $rol !== 'admin') {
            responder(['ok' => false, 'error' => 'No puedes quitarte tu propio rol de admin'], 400);
        }
        db()->prepare('UPDATE usuarios_admin SET nombre = COALESCE(?, nombre), rol = COALESCE(?, rol) WHERE id = ?')
             ->execute([$nombre, $rol, $id]);
        log_audit('usuario_editar', "id=$id");
        responder(['ok' => true]);
        break;
    }

    case 'usuario_delete': {
        $id = (int)(leer_cuerpo()['id'] ?? 0);
        iniciar_sesion();
        if ($id === (int)$_SESSION['admin_id']) {
            responder(['ok' => false, 'error' => 'No puedes eliminar tu propia cuenta'], 400);
        }
        db()->prepare('DELETE FROM usuarios_admin WHERE id = ?')->execute([$id]);
        log_audit('usuario_eliminar', "id=$id");
        responder(['ok' => true]);
        break;
    }

    /* --------------------------------------------------------- AUDITORÍA */
    case 'auditoria_list':
        responder(['ok' => true,
                   'eventos' => db()->query('SELECT * FROM auditoria ORDER BY id DESC LIMIT 150')->fetchAll()]);
        break;

    /* ------------------------------------------------------------- BACKUP */
    case 'backup': {
        $dump = [];
        foreach ($TABLAS as $t) {
            try { $dump[$t] = db()->query("SELECT * FROM `{$t}`")->fetchAll(); }
            catch (Exception $e) { $dump[$t] = []; }
        }
        $json = json_encode(['version' => date('Y-m-d H:i'), 'data' => $dump],
                            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="luitech-backup-' . date('Ymd-His') . '.json"');
        echo $json;
        log_audit('backup_export');
        exit;
    }

    case 'restore': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { responder(['ok' => false, 'error' => 'Método no permitido'], 405); }
        $data = (leer_cuerpo()['data'] ?? null);
        if (!is_array($data)) { responder(['ok' => false, 'error' => 'Formato inválido'], 400); }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $orden = array_reverse(array_intersect($TABLAS, array_keys($data)));
            foreach ($orden as $tabla) {
                $pdo->exec("DELETE FROM `{$tabla}`");
                $filas = $data[$tabla] ?? [];
                if (!is_array($filas) || !count($filas)) continue;
                $cols   = array_keys(reset($filas));
                $seguro = implode(',', array_map(fn($c) => '`' . preg_replace('/[^a-z_]/i', '', $c) . '`', $cols));
                $marcas = implode(',', array_fill(0, count($cols), '?'));
                $st = $pdo->prepare("INSERT INTO `{$tabla}` ($seguro) VALUES ($marcas)");
                foreach ($filas as $fila) {
                    $st->execute(array_values(is_array($fila) ? $fila : []));
                }
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            responder(['ok' => false, 'error' => 'Error al restaurar: ' . substr($e->getMessage(), 0, 120)], 500);
        }
        log_audit('restaurar_backup');
        responder(['ok' => true]);
        break;
    }

    default:
        responder(['ok' => false, 'error' => 'Acción desconocida'], 400);
}

