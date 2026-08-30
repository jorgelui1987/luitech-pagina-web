<?php
/**
 * LUITECH API - Inventario (solo administrador autenticado).
 * Acciones (?action=):
 *   list    GET            -> productos activos (incluye alertas de stock bajo)
 *   create  POST {codigo,nombre,categoria,precio_costo,precio_venta,stock,stock_minimo,controlar_stock}
 *   update  POST {id,...campos opcionales}
 *   delete  POST {id}      -> baja lógica (activo=0)
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

iniciar_respuesta_json();
exigir_admin();
preparar_proveedores(db()); // tablas de proveedores auto-reparables

$action = $_GET['action'] ?? '';

/** Valida y normaliza los datos de un producto recibidos por POST. */
function leer_producto(array $d): array
{
    $out = [];
    if (isset($d['codigo'])) {
        $codigo = strtoupper(trim((string)$d['codigo']));
        if ($codigo === '' || strlen($codigo) > 30) {
            responder(['ok' => false, 'error' => 'Código inválido'], 400);
        }
        $out['codigo'] = $codigo;
    }
    if (isset($d['nombre'])) {
        $nombre = trim((string)$d['nombre']);
        if ($nombre === '' || mb_strlen($nombre) > 120) {
            responder(['ok' => false, 'error' => 'Nombre inválido'], 400);
        }
        $out['nombre'] = $nombre;
    }
    if (isset($d['categoria'])) {
        $out['categoria'] = trim(mb_substr((string)$d['categoria'], 0, 60)) ?: 'Repuesto';
    }
    if (isset($d['proveedor'])) {
        $out['proveedor'] = trim(mb_substr((string)$d['proveedor'], 0, 120)) ?: null;
    }
    if (isset($d['proveedor_id'])) {
        $pid = (int)$d['proveedor_id'];
        $out['proveedor_id'] = ($pid > 0) ? $pid : null;
    }
    foreach (['precio_costo', 'precio_venta', 'stock', 'stock_minimo'] as $campoNumerico) {
        if (array_key_exists($campoNumerico, $d)) {
            $out[$campoNumerico] = max(0, (int)$d[$campoNumerico]);
        }
    }
    if (isset($d['controlar_stock'])) {
        $out['controlar_stock'] = !empty($d['controlar_stock']) ? 1 : 0;
    }
    return $out;
}

switch ($action) {

    case 'list':
        $stmt = db()->query(
            'SELECT pr.id, pr.codigo, pr.nombre, pr.categoria, pr.proveedor, pr.proveedor_id,
                    COALESCE(pv.nombre, pr.proveedor) AS proveedor_nombre,
                    pr.precio_costo, pr.precio_venta,
                    pr.stock, pr.stock_minimo, pr.controlar_stock
             FROM productos pr
             LEFT JOIN proveedores pv ON pv.id = pr.proveedor_id
             WHERE pr.activo = 1 ORDER BY pr.nombre'
        );
        $productos = $stmt->fetchAll();
        foreach ($productos as &$p) {
            $p['stock_bajo'] = ((int)$p['controlar_stock'] === 1 && (int)$p['stock'] <= (int)$p['stock_minimo']) ? 1 : 0;
            // controlar_stock SE MANTIENE en la respuesta: el inventario y el POS
            // lo necesitan para distinguir productos físicos (con stock) de servicios
        }
        responder(['ok' => true, 'productos' => $productos]);

    case 'create': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $d = leer_producto(leer_cuerpo());
        if (!isset($d['codigo'], $d['nombre'])) {
            responder(['ok' => false, 'error' => 'Código y Nombre son obligatorios'], 400);
        }
        $sql = 'INSERT INTO productos (codigo, nombre, categoria, proveedor, proveedor_id, precio_costo, precio_venta, stock, stock_minimo, controlar_stock)
                VALUES (:codigo, :nombre, :categoria, :proveedor, :proveedor_id, :costo, :venta, :stock, :minimo, :ctrl)';
        $st = db()->prepare($sql);
        try {
            $st->execute([
                ':codigo' => $d['codigo'],
                ':nombre' => $d['nombre'],
                ':categoria'   => $d['categoria']   ?? 'Repuesto',
                ':proveedor'   => $d['proveedor']   ?? null,
                ':proveedor_id' => $d['proveedor_id'] ?? null,
                ':costo'       => $d['precio_costo'] ?? 0,
                ':venta'       => $d['precio_venta'] ?? 0,
                ':stock'       => $d['stock']         ?? 0,
                ':minimo'      => $d['stock_minimo']  ?? 3,
                ':ctrl'        => $d['controlar_stock'] ?? 1,
            ]);
            responder(['ok' => true, 'id' => (int)db()->lastInsertId()]);
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000) {
                responder(['ok' => false, 'error' => 'Ya existe un producto con ese código'], 409);
            }
            responder(['ok' => false, 'error' => 'No se pudo crear el producto'], 500);
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
        $datos = leer_producto($d);
        if (!$datos) {
            responder(['ok' => false, 'error' => 'Nada que actualizar'], 400);
        }
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($datos)));
        $params = $datos;
        $params[':id'] = $id;
        $st = db()->prepare("UPDATE productos SET $set WHERE id = :id");
        try {
            $st->execute($params);
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000) {
                responder(['ok' => false, 'error' => 'Otro producto ya usa ese código'], 409);
            }
            responder(['ok' => false, 'error' => 'No se pudo actualizar'], 500);
        }
        responder(['ok' => true]);
    }

    case 'delete': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $id = (int)(leer_cuerpo()['id'] ?? 0);
        db()->prepare('UPDATE productos SET activo = 0 WHERE id = ?')->execute([$id]);
        responder(['ok' => true]);
    }

    default:
        responder(['ok' => false, 'error' => 'Acción desconocida'], 400);
}
