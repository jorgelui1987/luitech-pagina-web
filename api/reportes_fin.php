<?php
/**
 * Reportes financieros extendidos y comisiones.
 *   ?action=estado&mes=YYYY-MM  -> Ingresos, costo de ventas, margen, gastos fijos/variables, resultado
 *   ?action=comisiones&mes=YYYY-MM -> ventas por vendedor × % configurado
 */
declare(strict_types=1);
require __DIR__ . '/config.php';
iniciar_respuesta_json();
exigir_rol(['admin']);

$action = $_GET['action'] ?? '';
$mes    = preg_match('/^\d{4}-\d{2}$/', ($_GET['mes'] ?? '')) ? $_GET['mes'] : date('Y-m');

switch ($action) {

    case 'estado': {
        $pdo = db();

        // Ventas e ingresos
        $ventas = $pdo->query("SELECT COALESCE(SUM(total),0) FROM ventas WHERE DATE_FORMAT(creado_en,'%Y-%m') = '$mes'")->fetchColumn();
        $numVentas = $pdo->query("SELECT COUNT(*) FROM ventas WHERE DATE_FORMAT(creado_en,'%Y-%m') = '$mes'")->fetchColumn();

        // Costo de lo vendido (precio_costo del producto × cantidad)
        $costoVentas = (int)$pdo->query(
            "SELECT COALESCE(SUM(vi.cantidad * COALESCE(p.precio_costo,0)),0)
             FROM venta_items vi JOIN ventas v ON v.id = vi.venta_id
             LEFT JOIN productos p ON p.id = vi.producto_id
             WHERE DATE_FORMAT(v.creado_en,'%Y-%m') = '$mes'"
        )->fetchColumn();
        $margenBruto = (int)$ventas - $costoVentas;

        // Gastos variables (los registrados) y fijos
        $gastosVar = (int)$pdo->query("SELECT COALESCE(SUM(monto),0) FROM gastos WHERE DATE_FORMAT(fecha,'%Y-%m')='$mes'")->fetchColumn();
        $gastosFijos = (int)$pdo->query('SELECT COALESCE(SUM(monto),0) FROM gastos_fijos')->fetchColumn();
        $gastosTot = $gastosVar + $gastosFijos;

        $repGastosCat = $pdo->query("SELECT categoria, COALESCE(SUM(monto),0) AS total FROM gastos
                                     WHERE DATE_FORMAT(fecha,'%Y-%m')='$mes' GROUP BY categoria ORDER BY total DESC")->fetchAll();

        $pagos = $pdo->query("SELECT medio_pago, COUNT(*) n, COALESCE(SUM(total),0) total FROM ventas
                              WHERE DATE_FORMAT(creado_en,'%Y-%m')='$mes' GROUP BY medio_pago")->fetchAll();

        responder([
            'ok' => true, 'mes' => $mes,
            'ingresos' => ['total' => (int)$ventas, 'n_ventas' => (int)$numVentas],
            'costo_ventas' => $costoVentas,
            'margen_bruto' => $margenBruto,
            'gastos' => ['variables' => $gastosVar, 'fijos' => $gastosFijos, 'total' => $gastosTot,
                         'por_categoria' => $repGastosCat],
            'resultado_neto' => $margenBruto - $gastosTot,
            'pagos' => $pagos,
        ]);
    }

    /* ------------------------------------------------------- COMISIONES */
    case 'comisiones': {
        $pctConfig = db()->query("SELECT v FROM configuracion WHERE k='comision_pct'")->fetchColumn();
        $pct = is_string($pctConfig) && $pctConfig !== '' ? max(0, min(50, (int)$pctConfig)) : 5;

        $rows = db()->query(
            "SELECT vendedor, COUNT(*) AS n_ventas, COALESCE(SUM(total),0) AS total_vendido
             FROM ventas WHERE DATE_FORMAT(creado_en,'%Y-%m') = '$mes'
             GROUP BY vendedor ORDER BY total_vendido DESC"
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['pct'] = $pct;
            $r['comision'] = intdiv((int)$r['total_vendido'] * $pct, 100);
        }
        responder(['ok' => true, 'mes' => $mes, 'pct_configurado' => $pct, 'comisiones' => $rows]);
    }

    default:
        responder(['ok' => false, 'error' => 'Acción desconocida'], 400);
}
