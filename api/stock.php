<?php
/**
 * Bitácora de movimientos de stock: listado histórico por producto y ajustes manuales.
 * ?action=list[&producto_id=] | ajuste POST {producto_id,tipo,motivo,cantidad}
 * tipo: Entrada (+stock) | Salida (-stock) | Ajuste (define stock final)
 */
declare(strict_types=1);
require __DIR__ . '/config.php';
iniciar_respuesta_json();
exigir_rol(['admin', 'vendedor']);

$action = $_GET['action'] ?? '';

switch ($action) {

    case 'list': {
        $pid = (int)($_GET['producto_id'] ?? 0);
        $sql = "SELECT m.*, p.nombre AS producto FROM movimientos_stock m
                JOIN productos p ON p.id = m.producto_id";
        if ($pid > 0) { $sql .= " WHERE m.producto_id = $pid"; }
        $sql .= ' ORDER BY m.id DESC LIMIT 150';
        responder(['ok' => true, 'movimientos' => db()->query($sql)->fetchAll()]);
    }

    case 'ajuste': {
        exigir_rol(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { responder(['ok' => false, 'error' => 'Método no permitido'], 405); }
        $d = leer_cuerpo();
        $pid   = (int)($d['producto_id'] ?? 0);
        $tipo  = in_array(($d['tipo'] ?? ''), ['Entrada','Salida','Ajuste'], true) ? $d['tipo'] : '';
        $cant  = max(0, (int)($d['cantidad'] ?? -1));
        $motivo= campo_texto($d, 'motivo', 200) ?? '';
        if ($pid <= 0 || $tipo === '' || $motivo === '') {
            responder(['ok' => false, 'error' => 'Producto, tipo y motivo son obligatorios'], 400);
        }

        $pdo = db();
        $stProd = $pdo->prepare('SELECT nombre, stock, controlar_stock FROM productos WHERE id = ?');
        $stProd->execute([$pid]);
        $prod = $stProd->fetch();
        if (!$prod) { responder(['ok' => false, 'error' => 'Producto no encontrado'], 404); }

        $nuevoStock = (int)$prod['stock'];
        if ($tipo === 'Entrada')      { $nuevoStock += $cant; }
        elseif ($tipo === 'Salida')   { if ($cant > $nuevoStock && (int)$prod['controlar_stock']) {
                                          responder(['ok' => false, 'error' => 'No hay suficiente stock (' . $prod['stock'] . ')'], 409); }
                                        $nuevoStock -= $cant; }
        else /* Ajuste */             { $nuevoStock = $cant; }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE productos SET stock = ? WHERE id = ?')->execute([$nuevoStock, $pid]);
            $pdo->prepare('INSERT INTO movimientos_stock (producto_id, tipo, cantidad, motivo) VALUES (?, ?, ?, ?)')
                 ->execute([$pid, $tipo, $tipo === 'Ajuste' ? $nuevoStock : $cant, $motivo]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            responder(['ok' => false, 'error' => 'Error al registrar el movimiento'], 500);
        }
        log_audit("mov_stock_$tipo", "{$prod['nombre']} → stock final $nuevoStock ($motivo)");
        responder(['ok' => true, 'stock_final' => $nuevoStock]);
    }

    default:
        responder(['ok' => false, 'error' => 'Acción desconocida'], 400);
}
