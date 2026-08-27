<?php
/**
 * LUITECH API - Gastos + Reporte financiero mensual (solo admin).
 * Acciones (?action=):
 *   list     GET ?mes=YYYY-MM (default actual) -> gastos del mes + resumen financiero
 *   create   POST {concepto,categoria,monto,fecha?}
 *   delete   POST {id}
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

iniciar_respuesta_json();
exigir_admin();

$action = $_GET['action'] ?? '';

/** Valida el parámetro de mes o usa el actual. */
function mes_param(): string
{
    $mes = trim($_GET['mes'] ?? '');
    return preg_match('/^\d{4}-\d{2}$/', $mes) ? $mes : date('Y-m');
}

switch ($action) {

    case 'list': {
        $pdo  = db();
        $mes  = mes_param();

        $stmt = $pdo->prepare(
            "SELECT id, concepto, categoria, monto, fecha FROM gastos
             WHERE DATE_FORMAT(fecha, '%Y-%m') = ?
             ORDER BY fecha DESC, id DESC LIMIT 200"
        );
        $stmt->execute([$mes]);
        $gastos = $stmt->fetchAll();

        $stGasto = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM gastos WHERE DATE_FORMAT(fecha,'%Y-%m') = ?");
        $stGasto->execute([$mes]);
        $totalGastos = (int)$stGasto->fetchColumn();

        $porCategoria = $pdo->prepare(
            "SELECT categoria, COALESCE(SUM(monto),0) AS total FROM gastos
             WHERE DATE_FORMAT(fecha,'%Y-%m') = ? GROUP BY categoria ORDER BY total DESC"
        );
        $porCategoria->execute([$mes]);
        $cats = $porCategoria->fetchAll();

        $stVentas = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM ventas WHERE DATE_FORMAT(creado_en,'%Y-%m') = ?");
        $stVentas->execute([$mes]);
        $ingresosVentas = (int)$stVentas->fetchColumn();

        responder([
            'ok'    => true,
            'mes'   => $mes,
            'gastos' => $gastos,
            'resumen' => [
                'ingresos_ventas' => $ingresosVentas,
                'gastos'          => $totalGastos,
                'resultado'       => $ingresosVentas - $totalGastos,
                'gastos_por_categoria' => $cats,
            ],
        ]);
    }

    case 'create': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $d = leer_cuerpo();
        $concepto  = campo_texto($d, 'concepto', 200);
        $categoria = campo_texto($d, 'categoria', 60) ?? 'General';
        $monto     = max(1, (int)($d['monto'] ?? 0));
        $fecha     = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['fecha'] ?? '')) ? $d['fecha'] : date('Y-m-d');

        if ($concepto === null || $monto < 1) {
            responder(['ok' => false, 'error' => 'Concepto y monto son obligatorios'], 400);
        }
        db()->prepare('INSERT INTO gastos (concepto, categoria, monto, fecha) VALUES (?, ?, ?, ?)')
             ->execute([$concepto, $categoria, $monto, $fecha]);
        responder(['ok' => true]);
    }

    case 'delete': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $id = (int)(leer_cuerpo()['id'] ?? 0);
        db()->prepare('DELETE FROM gastos WHERE id = ?')->execute([$id]);
        responder(['ok' => true]);
    }

    default:
        responder(['ok' => false, 'error' => 'Acción desconocida'], 400);
}
