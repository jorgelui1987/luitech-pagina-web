<?php
/**
 * Órdenes de Compra a proveedores.
 * ?action=list | detalle?id= | create {proveedor_id,items:[{producto_id?,descripcion,cantidad,costo_unitario}]}
 *              | recibir {id} -> suma stock + registra movimiento + deja costo actualizado en producto
 *              | delete {id} (solo si Pendiente)
 */
declare(strict_types=1);
require __DIR__ . '/config.php';
iniciar_respuesta_json();
exigir_rol(['admin', 'vendedor']);

$action = $_GET['action'] ?? '';

/** Correlativo CO-000001 */
function num_compra(PDO $pdo): string {
    $n = (int)$pdo->query('SELECT COALESCE(MAX(id),0)+1 FROM compras')->fetchColumn();
    return 'CO-' . str_pad((string)$n, 6, '0', STR_PAD_LEFT);
}

switch ($action) {

    case 'list': {
        $rows = db()->query(
            'SELECT c.*, p.nombre AS proveedor FROM compras c
             JOIN proveedores p ON p.id = c.proveedor_id ORDER BY c.id DESC LIMIT 100'
        )->fetchAll();
        responder(['ok' => true, 'compras' => $rows]);
    }

    case 'detalle': {
        $id = (int)($_GET['id'] ?? 0);
        $st = db()->prepare('SELECT c.*, p.nombre AS proveedor FROM compras c JOIN proveedores p ON p.id = c.proveedor_id WHERE c.id = ?');
        $st->execute([$id]);
        $cab = $st->fetch();
        if (!$cab) { responder(['ok' => false, 'error' => 'Compra no encontrada'], 404); }
        $its = db()->prepare('SELECT descripcion, cantidad, costo_unitario FROM compra_items WHERE compra_id = ?');
        $its->execute([$id]);
        responder(['ok' => true, 'compra' => $cab, 'items' => $its->fetchAll()]);
    }

    case 'create': {
        exigir_rol(['admin', 'vendedor']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { responder(['ok' => false, 'error' => 'Método no permitido'], 405); }
        $d = leer_cuerpo();
        $provId = (int)($d['proveedor_id'] ?? 0);
        $items  = $d['items'] ?? [];
        if ($provId <= 0 || !is_array($items) || !count($items)) {
            responder(['ok' => false, 'error' => 'Proveedor e items son obligatorios'], 400);
        }

        $pdo = db();
        $total = 0;
        foreach ($items as $it) {
            $cant = (int)($it['cantidad'] ?? 0);
            $costo = (int)($it['costo_unitario'] ?? -1);
            $desc = trim((string)($it['descripcion'] ?? ''));
            if ($desc === '' || $cant < 1 || $costo < 0) { responder(['ok' => false, 'error' => 'Item inválido'], 400); }
            $total += $cant * $costo;
        }

        $pdo->beginTransaction();
        try {
            $numero = num_compra($pdo);
            $pdo->prepare('INSERT INTO compras (numero, proveedor_id, total) VALUES (?,?,?)')
                 ->execute([$numero, $provId, $total]);
            $cid = (int)$pdo->lastInsertId();
            $stI = $pdo->prepare('INSERT INTO compra_items (compra_id, producto_id, descripcion, cantidad, costo_unitario)
                                  VALUES (?, ?, ?, ?, ?)');
            foreach ($items as $it) {
                $pid = isset($it['producto_id']) && $it['producto_id'] !== '' ? (int)$it['producto_id'] : null;
                $stI->execute([$cid, $pid, trim((string)$it['descripcion']),
                               max(1,(int)$it['cantidad']), max(0,(int)$it['costo_unitario'])]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            responder(['ok' => false, 'error' => 'Error al crear la orden de compra'], 500);
        }
        log_audit('crear_compra', "$numero total=$total");
        responder(['ok' => true, 'numero' => $numero, 'total' => $total]);
    }

    /** Recibir: suma stock por item con producto vinculado + fija su costo */
    case 'recibir': {
        exigir_rol(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { responder(['ok' => false, 'error' => 'Método no permitido'], 405); }
        $id = (int)(leer_cuerpo()['id'] ?? 0);
        $stC = db()->prepare("SELECT numero, estado FROM compras WHERE id = ?");
        $stC->execute([$id]);
        $cab = $stC->fetch();
        if (!$cab) { responder(['ok' => false, 'error' => 'Compra no encontrada'], 404); }
        if ($cab['estado'] === 'Recibida') { responder(['ok' => false, 'error' => 'Ya está recibida'], 409); }

        $its = db()->prepare('SELECT * FROM compra_items WHERE compra_id = ?');
        $its->execute([$id]);

        $pdo = db(); $pdo->beginTransaction();
        try {
            $movS = $pdo->prepare('INSERT INTO movimientos_stock (producto_id, tipo, cantidad, motivo, ref_tipo, ref_id)
                                   VALUES (?, ?, ?, ?, "compra", ?)');
            while ($it = $its->fetch()) {
                if ($it['producto_id']) {
                    $pdo->prepare('UPDATE productos SET stock = stock + ?, precio_costo = ? WHERE id = ?')
                         ->execute([(int)$it['cantidad'], (int)$it['costo_unitario'], (int)$it['producto_id']]);
                    $movS->execute([(int)$it['producto_id'], 'Entrada', (int)$it['cantidad'],
                                    'Recepción OC ' . $cab['numero'], $id]);
                }
            }
            $pdo->prepare("UPDATE compras SET estado='Recibida' WHERE id=?")->execute([$id]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            responder(['ok' => false, 'error' => 'Error al recibir la compra'], 500);
        }
        log_audit('recibir_compra', $cab['numero']);
        responder(['ok' => true]);
    }

    case 'delete': {
        exigir_rol(['admin']);
        $id = (int)(leer_cuerpo()['id'] ?? 0);
        db()->prepare("DELETE FROM compras WHERE id = ? AND estado='Pendiente'")->execute([$id]);
        responder(['ok' => true]);
    }

    default:
        responder(['ok' => false, 'error' => 'Acción desconocida'], 400);
}
