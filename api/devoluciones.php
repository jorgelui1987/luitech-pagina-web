<?php
/**
 * Devoluciones: registra devolución de una venta, devuelve stock (si el item
 * lo tenía) y deja movimiento en caja (egreso) si la venta fue en efectivo.
 * ?action=list | create {venta_num?, producto_id?, descripcion, cantidad, monto, motivo}
 */
declare(strict_types=1);
require __DIR__ . '/config.php';
iniciar_respuesta_json();
exigir_rol(['admin', 'vendedor']);

$action = $_GET['action'] ?? '';

switch ($action) {

    case 'list':
        responder(['ok' => true, 'devoluciones' => db()->query('SELECT * FROM devoluciones ORDER BY id DESC LIMIT 100')->fetchAll()]);
        break;

    case 'create': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { responder(['ok' => false, 'error' => 'Método no permitido'], 405); }
        $d = leer_cuerpo();
        $ventaNum   = strtoupper(campo_texto($d, 'venta_num', 15) ?? '');
        $desc       = campo_texto($d, 'descripcion', 150);
        $cant       = max(0, (int)($d['cantidad'] ?? 1));
        $monto      = max(0, (int)($d['monto'] ?? 0));
        $motivo     = campo_texto($d, 'motivo', 200) ?? '';
        $prodId     = isset($d['producto_id']) && $d['producto_id'] !== '' ? (int)$d['producto_id'] : null;
        $esVenta    = preg_match('/^VT-\d{6}$/', $ventaNum);

        if (!$esVenta || $desc === null || ($monto < 1 && !$esVenta)) {
            responder(['ok' => false, 'error' => 'Número VT-xxxxxx, descripción y monto son requeridos para ventas'], 400);
        }

        // ¿La venta original existía? ¿fue efectivo?
        $venta = db()->prepare('SELECT id, medio_pago FROM ventas WHERE numero = ?');
        $venta->execute([$ventaNum]);
        $origen = $venta->fetch();

        try {
            db()->prepare('INSERT INTO devoluciones (venta_num, descripcion, cantidad, monto, motivo, producto_id)
                           VALUES (?, ?, ?, ?, ?, ?)')
                 ->execute([$ventaNum, $desc, $cant, $monto, $motivo, $prodId]);

            if ($prodId && $esVenta) {
                db()->prepare('UPDATE productos SET stock = stock + ? WHERE id = ?')->execute([$cant, $prodId]);
                $sesionAb = db()->query("SELECT id FROM caja_sesiones WHERE estado='Abierta' LIMIT 1")->fetch();
                if ($sesionAb) {
                    db()->prepare('INSERT INTO movimientos_stock (producto_id,tipo,cantidad,motivo,ref_tipo)
                                   VALUES (?, "Entrada", ?, ?, "devolucion")')->execute([$prodId, $cant, 'Devolución']);
                    db()->prepare('INSERT INTO movimientos_caja (sesion_id,tipo,concepto,monto) VALUES (?, "Egreso", ?, ?)')
                         ->execute([(int)$sesionAb['id'], 'Devolución ' . $ventaNum, $monto]);
                }
            }
        } catch (Exception $e) {
            responder(['ok' => false, 'error' => 'Error al registrar la devolución'], 500);
        }
        log_audit('devolucion', "$ventaNum · $desc · \$=$monto");
        responder(['ok' => true, 'stock_devuelto' => ($prodId && $esVenta)]);
        break;
    }

    default:
        responder(['ok' => false, 'error' => 'Acción desconocida'], 400);
}
