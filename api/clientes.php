<?php
/**
 * LUITECH API - Registro de clientes (admin y encargado; delete solo admin).
 * Acciones (?action=):
 *   list    GET ?q=        -> clientes activos + n órdenes + total gastado
 *   create  POST {...}     -> crea cliente
 *   update  POST {id,...}  -> actualiza
 *   delete  POST {id}      -> baja lógica (activo=0)
 *   ficha   GET ?id=       -> cliente + todas sus órdenes
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

iniciar_respuesta_json();
exigir_admin();
if (($_GET['action'] ?? '') === 'delete') {
    exigir_rol(['admin']); // eliminar clientes: solo el dueño
} else {
    exigir_rol(['admin', 'tecnico']); // crear/editar/ficha: también el encargado
}
preparar_clientes(db()); // tabla auto-reparable (hostings sin migrate)

$action = $_GET['action'] ?? '';

/** Valida y normaliza los datos de un cliente. */
function leer_cliente(array $d): array
{
    $out = [];
    if (isset($d['nombre'])) {
        $nombre = trim((string)$d['nombre']);
        if ($nombre === '' || mb_strlen($nombre) > 120) {
            responder(['ok' => false, 'error' => 'Nombre inválido'], 400);
        }
        $out['nombre'] = $nombre;
    }
    if (isset($d['rut'])) {
        $rut = strtoupper(trim((string)$d['rut']));
        if ($rut !== '' && !validar_rut_chileno($rut)) {
            responder(['ok' => false, 'error' => 'RUT del cliente inválido'], 400);
        }
        $out['rut'] = ($rut === '') ? null : preg_replace('/[^0-9kK]/', '', $rut);
    }
    if (isset($d['telefono'])) {
        $out['telefono'] = trim(mb_substr((string)$d['telefono'], 0, 40)) ?: null;
    }
    if (isset($d['email'])) {
        $out['email'] = trim(mb_substr((string)$d['email'], 0, 120)) ?: null;
    }
    if (isset($d['notas'])) {
        $out['notas'] = trim(mb_substr((string)$d['notas'], 0, 255)) ?: null;
    }
    return $out;
}

switch ($action) {

    case 'list': {
        $q = trim((string)($_GET['q'] ?? ''));
        $sql = 'SELECT c.id, c.nombre, c.rut, c.telefono, c.email, c.notas,
                       (SELECT COUNT(*) FROM ordenes o WHERE o.cliente_id = c.id OR LOWER(o.cliente) = LOWER(c.nombre)) AS ordenes_total,
                       (SELECT COALESCE(SUM(o.total),0) FROM ordenes o WHERE o.cliente_id = c.id OR LOWER(o.cliente) = LOWER(c.nombre)) AS total_gastado,
                       (SELECT MAX(o.fecha_ingreso) FROM ordenes o WHERE o.cliente_id = c.id OR LOWER(o.cliente) = LOWER(c.nombre)) AS ultima_orden
                FROM clientes c WHERE c.activo = 1';
        $params = [];
        if ($q !== '') {
            $sql .= ' AND LOWER(c.nombre) LIKE ?';
            $params[] = '%' . mb_strtolower($q) . '%';
        }
        $sql .= ' ORDER BY c.nombre LIMIT 300';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        responder(['ok' => true, 'clientes' => $stmt->fetchAll()]);
    }

    case 'create': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $d = leer_cliente(leer_cuerpo());
        if (!isset($d['nombre'])) {
            responder(['ok' => false, 'error' => 'El nombre es obligatorio'], 400);
        }
        // Duplicado controlado con mensaje claro (en vez del genérico 500):
        // mismo RUT activo -> se dice de quién es; mismo RUT eliminado ->
        // se reactiva solo. Así "25828408-9 ya registrado" no es un misterio.
        if (!empty($d['rut'])) {
            $dup = db()->prepare('SELECT id, nombre, activo FROM clientes WHERE rut = ? LIMIT 1');
            $dup->execute([$d['rut']]);
            $ex = $dup->fetch();
            if ($ex) {
                if ((int)$ex['activo'] === 1) {
                    responder(['ok' => false, 'error' => 'Ese RUT ya es de "' . $ex['nombre'] . '" (id ' . $ex['id'] . ')'], 409);
                }
                db()->prepare('UPDATE clientes SET nombre = ?, telefono = ?, email = ?, notas = ?, activo = 1 WHERE id = ?')
                    ->execute([$d['nombre'], $d['telefono'] ?? null, $d['email'] ?? null, $d['notas'] ?? null, $ex['id']]);
                responder(['ok' => true, 'id' => (int)$ex['id'], 'reactivado' => true]);
            }
        }
        // Normaliza vacíos a NULL: evita el choque del UNIQUE con '' y el
        // "fantasma" de clientes sin RUT duplicados.
        foreach (['rut', 'telefono', 'email', 'notas'] as $k) {
            if (array_key_exists($k, $d) && $d[$k] === '') { $d[$k] = null; }
        }
        $columnas = implode(', ', array_keys($d));
        $marcas   = implode(', ', array_map(fn($k) => ":$k", array_keys($d)));
        try {
            db()->prepare("INSERT INTO clientes ($columnas) VALUES ($marcas)")->execute($d);
            responder(['ok' => true, 'id' => (int)db()->lastInsertId()]);
        } catch (PDOException $e) {
            $msg = 'No se pudo crear el cliente';
            if (strpos($e->getMessage(), 'Duplicate') !== false || (int)($e->errorInfo[1] ?? 0) === 1062) {
                $msg = 'Ese RUT ya está registrado en otro cliente';
            }
            responder(['ok' => false, 'error' => $msg], 409);
        }
    }

    case 'update': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $d = leer_cuerpo();
        $id = (int)($d['id'] ?? 0);
        if ($id <= 0) {
            responder(['ok' => false, 'error' => 'ID inválido'], 400);
        }
        $datos = leer_cliente($d);
        if (!$datos) {
            responder(['ok' => false, 'error' => 'Nada que actualizar'], 400);
        }
        // El RUT no puede quedar en 2 clientes activos distintos.
        if (!empty($datos['rut'])) {
            $dup = db()->prepare('SELECT id, nombre FROM clientes WHERE rut = ? AND id <> ? AND activo = 1 LIMIT 1');
            $dup->execute([$datos['rut'], $id]);
            $ex = $dup->fetch();
            if ($ex) {
                responder(['ok' => false, 'error' => 'Ese RUT ya es de "' . $ex['nombre'] . '" (id ' . $ex['id'] . ')'], 409);
            }
        }
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($datos)));
        $params = $datos;
        $params[':id'] = $id;
        try {
            db()->prepare("UPDATE clientes SET $set WHERE id = :id")->execute($params);
        } catch (PDOException $e) {
            $msg = 'No se pudo actualizar el cliente';
            if (strpos($e->getMessage(), 'Duplicate') !== false || (int)($e->errorInfo[1] ?? 0) === 1062) {
                $msg = 'Ese RUT ya está registrado en otro cliente';
            }
            responder(['ok' => false, 'error' => $msg], 409);
        }
        responder(['ok' => true]);
    }

    case 'delete': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $id = (int)(leer_cuerpo()['id'] ?? 0);
        db()->prepare('UPDATE clientes SET activo = 0 WHERE id = ?')->execute([$id]);
        responder(['ok' => true]);
    }

    case 'ficha': {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            responder(['ok' => false, 'error' => 'ID inválido'], 400);
        }
        $st = db()->prepare('SELECT id, nombre, rut, telefono, email, notas FROM clientes WHERE id = ? AND activo = 1 LIMIT 1');
        $st->execute([$id]);
        $cliente = $st->fetch();
        if (!$cliente) {
            responder(['ok' => false, 'error' => 'Cliente no encontrado'], 404);
        }
        $ordenes = db()->prepare(
            'SELECT codigo, equipo, falla, estado, estado_pago, total, abono, fecha_ingreso, fecha_entrega
             FROM ordenes WHERE cliente_id = ? OR LOWER(cliente) = LOWER(?)
             ORDER BY id DESC LIMIT 200'
        );
        $ordenes->execute([$id, $cliente['nombre']]);
        $lista = $ordenes->fetchAll();
        $gastado = 0;
        foreach ($lista as $o) {
            $gastado += (int)$o['total'];
        }
        responder(['ok' => true, 'cliente' => $cliente, 'ordenes' => $lista, 'total_gastado' => $gastado]);
    }

    default:
        responder(['ok' => false, 'error' => 'Acción desconocida'], 400);
}