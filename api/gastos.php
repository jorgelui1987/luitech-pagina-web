<?php
/**
 * LUITECH API - Gastos + Reporte financiero mensual (admin y encargado; delete solo admin).
 * Acciones (?action=):
 *   list     GET ?mes=YYYY-MM (default actual) -> gastos del mes + resumen financiero
 *   create   POST {concepto,categoria,monto,fecha?}
 *   delete   POST {id}
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

iniciar_respuesta_json();
exigir_admin();
if (($_GET['action'] ?? '') === 'delete') {
    exigir_rol(['admin']); // eliminar gastos: solo el dueño
} else {
    exigir_rol(['admin', 'tecnico']); // registrar gastos: también el encargado
}

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
             ORDER BY fecha DESC, id DESC LIMIT 500"
        );
        $stmt->execute([$mes]);
        $gastos = $stmt->fetchAll();

        $stCant = $pdo->prepare("SELECT COUNT(*) FROM gastos WHERE DATE_FORMAT(fecha,'%Y-%m') = ?");
        $stCant->execute([$mes]);
        $cantidad = (int)$stCant->fetchColumn();

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

        // --- Utilidad real del negocio: taller + POS ---------------------
        // Ingresos taller = abonos cobrados en órdenes del mes (lo que el
        // cliente ya pagó, no el presupuesto). Fecha = entrega si existe,
        // si no la última actualización (cuando se abonó).
        $ingTaller = 0; $nTaller = 0; $costoRep = 0;
        try {
            $stT = $pdo->prepare(
                "SELECT COALESCE(SUM(abono),0) AS ing, COUNT(*) AS n,
                        COALESCE(SUM(costo_repuesto),0) AS costo
                 FROM ordenes WHERE DATE_FORMAT(COALESCE(fecha_entrega,actualizado_en),'%Y-%m') = ?
                   AND abono > 0"
            );
            $stT->execute([$mes]);
            $fT = $stT->fetch() ?: [];
            $ingTaller = (int)($fT['ing'] ?? 0);
            $nTaller   = (int)($fT['n'] ?? 0);
            $costoRep  = (int)($fT['costo'] ?? 0);
        } catch (Throwable $e) { /* tabla sin columnas nuevas: 0 */ }

        // Comisiones de técnicos pagadas en el mes (salen del bolsillo).
        $comPagadas = 0; $nCom = 0;
        try {
            $stC = $pdo->prepare(
                "SELECT COALESCE(SUM(monto),0) AS tot, COUNT(*) AS n FROM comisiones
                 WHERE estado = 'Pagada' AND DATE_FORMAT(COALESCE(fecha_pagada,fecha_generada),'%Y-%m') = ?"
            );
            $stC->execute([$mes]);
            $fC = $stC->fetch() ?: [];
            $comPagadas = (int)($fC['tot'] ?? 0);
            $nCom       = (int)($fC['n'] ?? 0);
        } catch (Throwable $e) { /* sin tabla comisiones: 0 */ }

        // Comisiones pendientes (aún no salen, pero ya se deben).
        $comPend = 0;
        try {
            $stP = $pdo->prepare(
                "SELECT COALESCE(SUM(monto),0) FROM comisiones
                 WHERE estado = 'Pendiente' AND DATE_FORMAT(fecha_generada,'%Y-%m') = ?"
            );
            $stP->execute([$mes]);
            $comPend = (int)$stP->fetchColumn();
        } catch (Throwable $e) { /* sin tabla: 0 */ }

        $ingTotales = $ingresosVentas + $ingTaller;
        $egrTotales = $totalGastos + $comPagadas + $costoRep;
        $utilidad   = $ingTotales - $egrTotales;

        responder([
            'ok'    => true,
            'mes'   => $mes,
            'gastos' => $gastos,
            'resumen' => [
                'ingresos_ventas' => $ingresosVentas,
                'gastos'          => $totalGastos,
                'resultado'       => $ingresosVentas - $totalGastos,
                'gastos_por_categoria' => $cats,
                'cantidad'        => $cantidad,
                // Nuevo: utilidad real consolidada
                'ingresos_taller' => $ingTaller,
                'ordenes_cobradas'=> $nTaller,
                'ingresos_totales'=> $ingTotales,
                'comisiones_pagadas' => $comPagadas,
                'comisiones_n'    => $nCom,
                'comisiones_pendientes' => $comPend,
                'costo_repuestos' => $costoRep,
                'egresos_totales' => $egrTotales,
                'utilidad'        => $utilidad,
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
