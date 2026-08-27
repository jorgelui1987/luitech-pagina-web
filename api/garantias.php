<?php
/**
 * Garantías: crear a partir de una venta (VT-xxxxxx) u orden (LUH-nnnn),
 * listar con estado calculado, marcar como usada.
 * ?action=list | create {ref_codigo, cliente, producto, meses} | usar POST {id}
 */
declare(strict_types=1);
require __DIR__ . '/config.php';
iniciar_respuesta_json();
exigir_rol(['admin', 'vendedor', 'tecnico']);

$action = $_GET['action'] ?? '';

switch ($action) {

    case 'list': {
        $rows = db()->query('SELECT * FROM garantias ORDER BY fin DESC LIMIT 200')->fetchAll();
        $hoy = date('Y-m-d');
        foreach ($rows as &$g) {
            $g['estado'] = $g['usada'] ? 'Usada' : (($g['fin'] < $hoy) ? 'Vencida' : 'Vigente');
        }
        responder(['ok' => true, 'garantias' => $rows]);
    }

    case 'create': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { responder(['ok' => false, 'error' => 'Método no permitido'], 405); }
        $d = leer_cuerpo();
        $ref  = strtoupper(campo_texto($d, 'ref_codigo', 15) ?? '');
        $cli  = campo_texto($d, 'cliente', 120);
        $prod = campo_texto($d, 'producto', 150);
        $meses= max(1, min(60, (int)($d['meses'] ?? 3)));
        $tipo = str_starts_with($ref, 'LUH') ? 'Orden' : 'Venta';

        if ($cli === null || $prod === null || !preg_match('/^(VT-\d{6}|LUH-\d{3,8})$/', $ref)) {
            responder(['ok' => false, 'error' => 'Código (VT-/LUH-), cliente y producto son obligatorios'], 400);
        }

        // Verificar que la referencia exista
        $tablaRef = $tipo === 'Orden' ? 'ordenes' : 'ventas';
        $st = db()->prepare("SELECT COUNT(*) FROM {$tablaRef} WHERE codigo = ? OR numero = ?");
        $st->execute([$ref, $ref]);
        if (!$st->fetchColumn()) {
            responder(['ok' => false, 'error' => "No existe esa $tipo"], 404);
        }

        $inicio = date('Y-m-d');
        $fin    = date('Y-m-d', strtotime("+$meses months"));
        try {
            db()->prepare('INSERT INTO garantias (ref_tipo, ref_codigo, cliente, producto, meses, inicio, fin)
                           VALUES (?, ?, ?, ?, ?, ?, ?)')
                 ->execute([$tipo, $ref, $cli, $prod, $meses, $inicio, $fin]);
        } catch (Exception $e) {
            responder(['ok' => false, 'error' => 'Error al registrar garantía'], 500);
        }
        log_audit('crear_garantia', "$ref · $cli · $meses meses");
        responder(['ok' => true]);
        break;
    }

    case 'usar':
        exigir_rol(['admin']);
        $id = (int)(leer_cuerpo()['id'] ?? 0);
        db()->prepare('UPDATE garantias SET usada = 1 WHERE id = ?')->execute([$id]);
        log_audit('garantia_usada', "id=$id");
        responder(['ok' => true]);
        break;

    default:
        responder(['ok' => false, 'error' => 'Acción desconocida'], 400);
}
