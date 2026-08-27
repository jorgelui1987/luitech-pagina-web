<?php
/**
 * Catálogo genérico de personas: clientes y proveedores.
 * ?tipo=cliente|proveedor  + acción ?action=list|create|update|delete
 */
declare(strict_types=1);
require __DIR__ . '/config.php';
iniciar_respuesta_json();

$tipo = ($_GET['tipo'] ?? '') === 'proveedor' ? 'proveedor' : 'cliente';
exigir_rol(['admin', 'vendedor', 'tecnico']);

$config = $tipo === 'cliente'
    ? ['tabla' => 'clientes', 'orden' => 'nombre']
    : ['tabla' => 'proveedores', 'orden' => 'nombre'];

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        $rows = db()->query("SELECT * FROM {$config['tabla']} ORDER BY nombre")->fetchAll();
        responder(['ok' => true, 'items' => $rows]);
        break;

    case 'create': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { responder(['ok' => false, 'error' => 'Método no permitido'], 405); }
        $d   = leer_cuerpo();
        $nom = campo_texto($d, 'nombre', 120);
        if ($nom === null) { responder(['ok' => false, 'error' => 'Nombre obligatorio'], 400); }

        if ($tipo === 'cliente') {
            $rut = strtoupper(campo_texto($d, 'rut', 15) ?? '');
            if (!preg_match('/^\d{7,8}-[\dkK]$/', $rut)) {
                responder(['ok' => false, 'error' => 'RUT inválido (formato: 12345678-9)'], 400);
            }
            try {
                db()->prepare('INSERT INTO clientes (rut, nombre, telefono, email, direccion) VALUES (?,?,?,?,?)')
                     ->execute([$rut, $nom, campo_texto($d,'telefono',25), campo_texto($d,'email',120), campo_texto($d,'direccion',160)]);
            } catch (PDOException $e) {
                responder(['ok' => false, 'error' => 'Ese RUT ya está registrado'], 409);
            }
        } else {
            db()->prepare('INSERT INTO proveedores (nombre, contacto, telefono, email, notas) VALUES (?,?,?,?,?)')
                 ->execute([$nom, campo_texto($d,'contacto',120), campo_texto($d,'telefono',25),
                            campo_texto($d,'email',120), campo_texto($d,'notas',255)]);
        }
        log_audit("crear_$tipo", "nombre=$nom");
        responder(['ok' => true, 'id' => (int)db()->lastInsertId()]);
        break;
    }

    case 'update': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { responder(['ok' => false, 'error' => 'Método no permitido'], 405); }
        $d  = leer_cuerpo();
        $id = (int)($d['id'] ?? 0);
        $nom = trim((string)($d['nombre'] ?? ''));
        if ($id <= 0 || $nom === '') { responder(['ok' => false, 'error' => 'ID y Nombre requeridos'], 400); }

        if ($tipo === 'cliente') {
            db()->prepare('UPDATE clientes SET nombre=?, telefono=?, email=?, direccion=? WHERE id=?')
                 ->execute([$nom, campo_texto($d,'telefono',25), campo_texto($d,'email',120),
                            campo_texto($d,'direccion',160), $id]);
        } else {
            db()->prepare('UPDATE proveedores SET nombre=?, contacto=?, telefono=?, email=?, notas=? WHERE id=?')
                 ->execute([$nom, campo_texto($d,'contacto',120), campo_texto($d,'telefono',25),
                            campo_texto($d,'email',120), campo_texto($d,'notas',255), $id]);
        }
        log_audit("editar_$tipo", "id=$id");
        responder(['ok' => true]);
        break;
    }

    case 'delete':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { responder(['ok' => false, 'error' => 'Método no permitido'], 405); }
        $id = (int)(leer_cuerpo()['id'] ?? 0);
        db()->prepare("DELETE FROM {$config['tabla']} WHERE id = ?")->execute([$id]);
        log_audit("eliminar_$tipo", "id=$id");
        responder(['ok' => true]);
        break;

    default:
        responder(['ok' => false, 'error' => 'Acción desconocida'], 400);
}
