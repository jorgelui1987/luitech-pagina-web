<?php
/**
 * LUITECH API - Caja diaria (solo administrador autenticado).
 * Acciones (?action=):
 *   estado        GET  -> ¿caja abierta? + totales + movimientos del período
 *   abrir         POST {monto_apertura}   -> nueva sesión (409 si ya hay una)
 *   agregar_mov   POST {tipo,concepto,monto}
 *   cerrar        POST {monto_contado}    -> cierra y entrega diferencia
 *   historial     GET  -> últimas sesiones cerradas
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

iniciar_respuesta_json();
exigir_admin();

$action = $_GET['action'] ?? '';

/** Devuelve la sesión abierta actual o null. */
function sesion_abierta(PDO $pdo): ?array
{
    $st = $pdo->query(
        "SELECT id, abierta_por, monto_apertura, apertura_ts FROM caja_sesiones
         WHERE estado = 'Abierta' ORDER BY id DESC LIMIT 1"
    );
    $s = $st->fetch();
    return $s ?: null;
}

/** Efectivo esperado en gaveta = apertura + ingresos - egresos. */
function efectivo_en_caja(PDO $pdo, int $sesionId): int
{
    $apertura = (int)$pdo->query("SELECT monto_apertura FROM caja_sesiones WHERE id = {$sesionId}")->fetchColumn();
    $ingresos = (int)$pdo->query("SELECT COALESCE(SUM(monto),0) FROM movimientos_caja WHERE sesion_id = {$sesionId} AND tipo = 'Ingreso'")->fetchColumn();
    $egresos  = (int)$pdo->query("SELECT COALESCE(SUM(monto),0) FROM movimientos_caja WHERE sesion_id = {$sesionId} AND tipo = 'Egreso'")->fetchColumn();
    return $apertura + $ingresos - $egresos;
}

switch ($action) {

    /* ----------------------------------------------------------- ESTADO */
    case 'estado': {
        $s = sesion_abierta(db());
        if (!$s) {
            responder(['ok' => true, 'abierta' => false]);
        }
        $sid = (int)$s['id'];
        $mov = db()->prepare('SELECT tipo, concepto, monto, creado_en FROM movimientos_caja WHERE sesion_id = ? ORDER BY id DESC LIMIT 30');
        $mov->execute([$sid]);
        responder([
            'ok'       => true,
            'abierta'  => true,
            'sesion'   => [
                'id'           => $sid,
                'abierta_por'  => $s['abierta_por'],
                'monto_apertura' => (int)$s['monto_apertura'],
                'apertura_ts'  => $s['apertura_ts'],
            ],
            'efectivo_esperado' => efectivo_en_caja($pdo ?? db(), $sid),
            'movimientos' => $mov->fetchAll(),
        ]);
    }

    /* ------------------------------------------------------------ ABRIR */
    case 'abrir': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        if (sesion_abierta(db())) {
            responder(['ok' => false, 'error' => 'Ya hay una caja abierta'], 409);
        }
        $monto = max(0, (int)(leer_cuerpo()['monto_apertura'] ?? 0));
        db()->prepare("INSERT INTO caja_sesiones (abierta_por, monto_apertura, estado) VALUES (?, ?, 'Abierta')")
             ->execute([$_SESSION['admin_name'] ?? 'Mostrador', $monto]);
        responder(['ok' => true, 'id' => (int)db()->lastInsertId()]);
    }

    /* ------------------------------------------------------ AGREGAR MOV */
    case 'agregar_mov': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $s = sesion_abierta(db());
        if (!$s) {
            responder(['ok' => false, 'error' => 'No hay caja abierta'], 409);
        }
        $d = leer_cuerpo();
        $tipo = in_array(($d['tipo'] ?? ''), ['Ingreso','Egreso'], true) ? $d['tipo'] : '';
        $concepto = campo_texto($d, 'concepto', 200);
        $monto = max(1, (int)($d['monto'] ?? 0));
        if ($tipo === '' || $concepto === null || $monto < 1) {
            responder(['ok' => false, 'error' => 'Tipo, concepto y monto son obligatorios'], 400);
        }
        db()->prepare('INSERT INTO movimientos_caja (sesion_id, tipo, concepto, monto) VALUES (?, ?, ?, ?)')
             ->execute([(int)$s['id'], $tipo, $concepto, $monto]);
        responder(['ok' => true]);
    }

    /* ----------------------------------------------------------- CERRAR */
    case 'cerrar': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $s = sesion_abierta(db());
        if (!$s) {
            responder(['ok' => false, 'error' => 'No hay caja abierta'], 409);
        }
        $contado = max(0, (int)(leer_cuerpo()['monto_contado'] ?? -1));
        if ((leer_cuerpo()['monto_contado'] ?? '') === '') {
            responder(['ok' => false, 'error' => 'Indica cuánto dinero contaste'], 400);
        }
        $sid = (int)$s['id'];
        $esperado = efectivo_en_caja(db(), $sid);
        $dif = $contado - $esperado;

        db()->prepare("UPDATE caja_sesiones SET cierre_ts = NOW(), monto_cierre = ?, diferencia = ?, estado = 'Cerrada' WHERE id = ?")
             ->execute([$contado, $dif, $sid]);

        responder(['ok' => true, 'esperado' => $esperado, 'contado' => $contado, 'diferencia' => $dif]);
    }

    /* -------------------------------------------------------- HISTORIAL */
    case 'historial': {
        $rows = db()->query(
            "SELECT id, abierta_por, apertura_ts, cierre_ts, monto_apertura, monto_cierre, diferencia
             FROM caja_sesiones WHERE estado='Cerrada' ORDER BY id DESC LIMIT 10"
        )->fetchAll();
        responder(['ok' => true, 'historial' => $rows]);
    }

    default:
        responder(['ok' => false, 'error' => 'Acción desconocida'], 400);
}
