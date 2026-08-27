<?php
/**
 * Métricas agregadas para el Dashboard.
 * ?action=metricas
 */
declare(strict_types=1);
require __DIR__ . '/config.php';
iniciar_respuesta_json();
exigir_rol(['admin', 'vendedor', 'tecnico']);

$pdo = db();
$hoy = date('Y-m-d');
$mes = date('Y-m');

$ventasHoy      = (int)$pdo->query("SELECT COALESCE(SUM(total),0) FROM ventas WHERE DATE(creado_en)=CURDATE()")->fetchColumn();
$nVentasHoy     = (int)$pdo->query("SELECT COUNT(*) FROM ventas WHERE DATE(creado_en)=CURDATE()")->fetchColumn();
$ventasMes      = (int)$pdo->query("SELECT COALESCE(SUM(total),0) FROM ventas WHERE DATE_FORMAT(creado_en,'%Y-%m')='$mes'")->fetchColumn();
$gastosMes      = (int)$pdo->query("SELECT COALESCE(SUM(monto),0) FROM gastos WHERE DATE_FORMAT(fecha,'%Y-%m')='$mes'")->fetchColumn();

$ordEstados = [];
foreach (['Ingresado','En Diagnóstico','En Reparación','Listo para Retiro'] as $e) {
    $st = $pdo->prepare('SELECT COUNT(*) FROM ordenes WHERE estado = ?');
    $st->execute([$e]);
    $ordEstados[$e] = (int)$st->fetchColumn();
}

$top = $pdo->query(
    "SELECT vi.descripcion, SUM(vi.cantidad) AS q, SUM(vi.cantidad*vi.precio_unitario) AS monto
     FROM venta_items vi JOIN ventas v ON v.id = vi.venta_id
     WHERE DATE_FORMAT(v.creado_en,'%Y-%m') = '$mes'
     GROUP BY vi.descripcion ORDER BY monto DESC LIMIT 5"
)->fetchAll();

$bajoSt = $pdo->query("SELECT nombre, stock, stock_minimo FROM productos
                       WHERE activo=1 AND controlar_stock=1 AND stock <= stock_minimo ORDER BY stock LIMIT 6")->fetchAll();
$bajoCount = (int)$pdo->query("SELECT COUNT(*) FROM productos WHERE activo=1 AND controlar_stock=1 AND stock <= stock_minimo")->fetchColumn();

$garantiasProximas = $pdo->query(
    "SELECT ref_codigo, cliente, producto, fin FROM garantias
     WHERE usada = 0 AND fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
     ORDER BY fin LIMIT 6"
)->fetchAll();

responder([
    'ok' => true,
    'hoy' => ['total_ventas' => $ventasHoy, 'n_ventas' => $nVentasHoy],
    'mes' => ['ventas' => $ventasMes, 'gastos' => $gastosMes],
    'ordenes_por_estado' => $ordEstados,
    'top_productos_mes' => $top,
    'stock_bajo' => ['cantidad' => $bajoCount, 'items' => $bajoSt],
    'garantias_proximas' => $garantiasProximas,
]);
