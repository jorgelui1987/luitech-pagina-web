<?php
/**
 * LUITECH API - Punto de Venta (solo administrador autenticado).
 * Acciones (?action=):
 *   create      POST {items:[{producto_id?,descripcion,cantidad,precio_unitario}],
 *                      cliente?,vendedor?,medio_pago?,orden_codigo?}
 *                   -> transacción: cabecera + detalle + descuento de stock
 *   list        GET  -> últimas 50 ventas
 *   resumen_dia GET  -> total del día agrupado por medio de pago
 *   ticket      GET  ?numero=VT-000001 -> datos para boleta imprimible
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

aplicar_zona_horaria();

iniciar_respuesta_json();
exigir_admin();

$action = $_GET['action'] ?? '';

/** Formatea el correlativo VT-000001. */
function correlativo_venta(PDO $pdo): string
{
    $siguiente = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM ventas')->fetchColumn();
    return 'VT-' . str_pad((string)$siguiente, 6, '0', STR_PAD_LEFT);
}

switch ($action) {

    /* ----------------------------------------------------------- CREATE */
    case 'create': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }

        $d     = leer_cuerpo();
        $items = $d['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            responder(['ok' => false, 'error' => 'La venta no tiene items'], 400);
        }
        if (count($items) > 60) {
            responder(['ok' => false, 'error' => 'Demasiados items'], 400);
        }

        // Validación previa de items
        $total = 0;
        foreach ($items as $it) {
            $desc    = trim((string)($it['descripcion'] ?? ''));
            $cant    = (int)($it['cantidad'] ?? 0);
            $precio  = (int)($it['precio_unitario'] ?? -1);
            $prodId  = isset($it['producto_id']) ? (int)$it['producto_id'] : null;
            if ($desc === '' || mb_strlen($desc) > 150 || $cant < 1 || $precio < 0) {
                responder(['ok' => false, 'error' => 'Item inválido en la venta'], 400);
            }
            $total += $cant * $precio;
        }

        $cliente    = campo_texto($d, 'cliente', 120)   ?? 'Publico General';

        // RUT del cliente (opcional; validado con dígito verificador)
        $clienteRut = strtoupper(trim((string)($d['cliente_rut'] ?? '')));
        if ($clienteRut !== '') {
            $clienteRut = preg_replace('/[^0-9kK]/', '', $clienteRut) ?? '';
            if (!validar_rut_chileno($clienteRut)) {
                responder(['ok' => false, 'error' => 'RUT del cliente inválido'], 400);
            }
        } else {
            $clienteRut = null;
        }

        $vendedor   = campo_texto($d, 'vendedor', 80)   ?? 'Mostrador';
        $medioPago  = in_array(($d['medio_pago'] ?? ''), ['Efectivo','Debito','Credito','Transferencia'], true)
                      ? $d['medio_pago'] : 'Efectivo';
        $ordenCod   = strtoupper(trim((string)($d['orden_codigo'] ?? '')));
        $ordenFinal = preg_match('/^LUH-\d{3,8}$/', $ordenCod) ? $ordenCod : null;

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $numero = correlativo_venta($pdo);

            // IVA (Chile): el precio al público ya incluye el IVA. Se desglosa
            // con la tasa vigente guardada en configuraciones y queda congelado
            // en la venta (si la ley cambia, el historial no se recalcula).
            $tasa = (int)($pdo->query(
                "SELECT valor FROM configuraciones WHERE clave = 'iva_porcentaje' LIMIT 1"
            )->fetchColumn() ?: 19);
            $neto = (int)round($total * 100 / (100 + max(0, $tasa)));
            $ivaMonto = $total - $neto;

            $pdo->prepare(
                'INSERT INTO ventas (numero, vendedor, cliente, cliente_rut, total, medio_pago, orden_codigo,
                                     iva_tasa, neto, iva_monto)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$numero, $vendedor, $cliente, $clienteRut, $total, $medioPago, $ordenFinal,
                        max(0, $tasa), $neto, $ivaMonto]);
            $ventaId = (int)$pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                'INSERT INTO venta_items (venta_id, producto_id, descripcion, cantidad, precio_unitario)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stockStmt = $pdo->prepare(
                'UPDATE productos SET stock = stock - ? WHERE id = ? AND controlar_stock = 1 AND stock >= ?'
            );

            foreach ($items as $it) {
                $prodId = isset($it['producto_id']) && $it['producto_id'] !== '' ? (int)$it['producto_id'] : null;
                $cant   = max(1, (int)$it['cantidad']);

                if ($prodId !== null && $prodId > 0) {
                    $stockStmt->execute([$cant, $prodId, $cant]);
                    if ($stockStmt->rowCount() === 0) {
                        throw new RuntimeException('Sin stock suficiente: ' . $it['descripcion']);
                    }
                }
                $itemStmt->execute([
                    $ventaId,
                    $prodId,
                    trim((string)$it['descripcion']),
                    $cant,
                    max(0, (int)$it['precio_unitario']),
                ]);
            }

            $pdo->commit();

            // Venta en EFECTIVO -> entrada automática a la caja abierta (si la hay)
            if ($medioPago === 'Efectivo') {
                try {
                    $sesion = $pdo->query(
                        "SELECT id FROM caja_sesiones WHERE estado = 'Abierta' ORDER BY id DESC LIMIT 1"
                    )->fetch();
                    if ($sesion) {
                        $pdo->prepare('INSERT INTO movimientos_caja (sesion_id, tipo, concepto, monto, venta_id)
                                       VALUES (?, ?, ?, ?, ?)')
                             ->execute([(int)$sesion['id'], 'Ingreso', 'Venta ' . $numero, $total, $ventaId]);
                    }
                } catch (Exception $e) { /* no interrumpe la venta */ }
            }

            responder(['ok' => true, 'numero' => $numero, 'total' => $total,
                       'neto' => $neto, 'iva_monto' => $ivaMonto, 'iva_tasa' => max(0, $tasa)]);
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            responder(['ok' => false, 'error' => $e->getMessage()], 409);
        } catch (Exception $e) {
            $pdo->rollBack();
            responder(['ok' => false, 'error' => 'Error al registrar la venta'], 500);
        }
    }

    /* ------------------------------------------------------------- LIST */
    case 'list': {
        $stmt = db()->query(
            'SELECT v.id, v.numero, v.cliente, v.vendedor, v.total, v.medio_pago, v.orden_codigo, v.creado_en,
                    (SELECT COUNT(*) FROM venta_items vi WHERE vi.venta_id = v.id) AS items
             FROM ventas v ORDER BY v.id DESC LIMIT 50'
        );
        responder(['ok' => true, 'ventas' => $stmt->fetchAll()]);
    }

    /* ------------------------------------------------------ RESUMEN DIA */
    case 'resumen_dia': {
        $porPago = db()->query(
            "SELECT medio_pago, COUNT(*) AS n, COALESCE(SUM(total),0) AS total
             FROM ventas WHERE DATE(creado_en) = CURDATE()
             GROUP BY medio_pago"
        )->fetchAll();
        $totalDia = 0;
        foreach ($porPago as $f) { $totalDia += (int)$f['total']; }
        responder(['ok' => true, 'detalle' => $porPago, 'total_dia' => $totalDia]);
    }

    /* ----------------------------------------------------------- TICKET */
    case 'ticket': {
        $numero = strtoupper(trim($_GET['numero'] ?? ''));
        if (!preg_match('/^VT-\d{6}$/', $numero)) {
            responder(['ok' => false, 'error' => 'Número de venta inválido'], 400);
        }
        $venta = db()->prepare('SELECT * FROM ventas WHERE numero = ? LIMIT 1');
        $venta->execute([$numero]);
        $cab = $venta->fetch();
        if (!$cab) {
            responder(['ok' => false, 'error' => 'Venta no encontrada'], 404);
        }
        $items = db()->prepare(
            'SELECT descripcion, cantidad, precio_unitario FROM venta_items WHERE venta_id = ? ORDER BY id'
        );
        $items->execute([(int)$cab['id']]);
        responder(['ok' => true, 'venta' => $cab, 'items' => $items->fetchAll()]);
    }

    default:
        responder(['ok' => false, 'error' => 'Acción desconocida'], 400);
}

