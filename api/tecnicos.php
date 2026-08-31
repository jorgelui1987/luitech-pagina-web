<?php
/**
 * LUITECH API - Técnicos y comisiones (solo administrador).
 * Acciones (?action=):
 *   list           GET            -> técnicos activos + comisiones pendientes por técnico
 *   create         POST {nombre,rut?,telefono?,porcentaje_comision?}
 *   update         POST {id,...}
 *   delete         POST {id}      -> baja lógica (activo=0)
 *   comisiones     GET ?estado=   -> lista de comisiones (Pendiente/Pagada/todas)
 *   pagar_comision POST {id}      -> marca Pagada + Egreso en la caja abierta
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

iniciar_respuesta_json();
exigir_admin();

$miTecnicoId = isset($_SESSION['admin_tecnico_id']) ? (int)$_SESSION['admin_tecnico_id'] : 0;
$esTecnico   = rol_actual() === 'tecnico';

$action = $_GET['action'] ?? '';

/** Valida y normaliza los datos de un técnico. */
function leer_tecnico(array $d): array
{
    $out = [];
    if (isset($d['nombre'])) {
        $nombre = trim((string)$d['nombre']);
        if ($nombre === '' || mb_strlen($nombre) > 120) {
            responder(['ok' => false, 'error' => 'Nombre inválido'], 400);
        }
        $out['nombre'] = $nombre;
    }
    if (isset($d['rut'])) {
        $rut = strtoupper(trim((string)$d['rut']));
        if ($rut !== '' && !validar_rut_chileno($rut)) {
            responder(['ok' => false, 'error' => 'RUT del técnico inválido'], 400);
        }
        $out['rut'] = ($rut === '') ? null : preg_replace('/[^0-9kK]/', '', $rut);
    }
    if (isset($d['telefono'])) {
        $out['telefono'] = trim(mb_substr((string)$d['telefono'], 0, 40)) ?: null;
    }
    if (isset($d['porcentaje_comision'])) {
        $pct = (int)$d['porcentaje_comision'];
        if ($pct < 0 || $pct > 100) {
            responder(['ok' => false, 'error' => 'El porcentaje debe estar entre 0 y 100'], 400);
        }
        $out['porcentaje_comision'] = $pct;
    }
    return $out;
}

switch ($action) {

    case 'list':
        $stmt = db()->query(
            'SELECT t.id, t.nombre, t.rut, t.telefono, t.porcentaje_comision,
                    (SELECT COUNT(*) FROM usuarios_admin u WHERE u.tecnico_id = t.id AND u.rol = "tecnico") AS tiene_acceso,
                    (SELECT COUNT(*) FROM comisiones c WHERE c.tecnico_id = t.id AND c.estado = "Pendiente") AS comisiones_pendientes,
                    (SELECT COALESCE(SUM(c.monto),0) FROM comisiones c WHERE c.tecnico_id = t.id AND c.estado = "Pendiente") AS monto_pendiente,
                    (SELECT COUNT(*) FROM comisiones c WHERE c.tecnico_id = t.id AND c.estado = "Pagada") AS comisiones_pagadas,
                    (SELECT COALESCE(SUM(c.monto),0) FROM comisiones c WHERE c.tecnico_id = t.id AND c.estado = "Pagada") AS monto_pagado
             FROM tecnicos t
             WHERE t.activo = 1
             ORDER BY t.nombre'
        );
        responder(['ok' => true, 'tecnicos' => $stmt->fetchAll()]);

    case 'create': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $d = leer_tecnico(leer_cuerpo());
        if (!isset($d['nombre'])) {
            responder(['ok' => false, 'error' => 'El nombre es obligatorio'], 400);
        }
        $d['porcentaje_comision'] = $d['porcentaje_comision'] ?? 30;
        $columnas = implode(', ', array_keys($d));
        $marcas   = implode(', ', array_map(fn($k) => ":$k", array_keys($d)));
        try {
            db()->prepare("INSERT INTO tecnicos ($columnas) VALUES ($marcas)")->execute($d);
            responder(['ok' => true, 'id' => (int)db()->lastInsertId()]);
        } catch (PDOException $e) {
            responder(['ok' => false, 'error' => 'No se pudo crear el técnico'], 500);
        }
    }

    case 'update': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $d = leer_cuerpo();
        $id = (int)($d['id'] ?? 0);
        if ($id <= 0) {
            responder(['ok' => false, 'error' => 'ID inválido'], 400);
        }
        $datos = leer_tecnico($d);
        if (!$datos) {
            responder(['ok' => false, 'error' => 'Nada que actualizar'], 400);
        }
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($datos)));
        $params = $datos;
        $params[':id'] = $id;
        db()->prepare("UPDATE tecnicos SET $set WHERE id = :id")->execute($params);
        responder(['ok' => true]);
    }

    case 'delete': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $id = (int)(leer_cuerpo()['id'] ?? 0);
        db()->prepare('UPDATE tecnicos SET activo = 0 WHERE id = ?')->execute([$id]);
        responder(['ok' => true]);
    }

    case 'comisiones': {
        $estado = in_array(($_GET['estado'] ?? ''), ['Pendiente', 'Pagada', 'Anulada'], true) ? $_GET['estado'] : null;
        $sql = 'SELECT id, orden_codigo, tecnico_nombre, base_margen, porcentaje, monto, estado, fecha_generada, fecha_pagada FROM comisiones';
        $params = [];
        $conds = [];
        if ($esTecnico) {
            // Un técnico solo ve SUS comisiones
            $conds[] = 'tecnico_id = ?';
            $params[] = $miTecnicoId;
        }
        if ($estado !== null) {
            $conds[] = 'estado = ?';
            $params[] = $estado;
        }
        if ($conds) { $sql .= ' WHERE ' . implode(' AND ', $conds); }
        $sql .= ' ORDER BY (estado = "Pendiente") DESC, id DESC LIMIT 200';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $comisiones = $stmt->fetchAll();
        $pendienteTotal = 0;
        $pagadoTotal = 0;
        foreach ($comisiones as $c) {
            if ($c['estado'] === 'Pendiente') {
                $pendienteTotal += (int)$c['monto'];
            }
            if ($c['estado'] === 'Pagada') {
                $pagadoTotal += (int)$c['monto'];
            }
        }
        responder(['ok' => true, 'comisiones' => $comisiones,
                   'pendiente_total' => $pendienteTotal, 'pagado_total' => $pagadoTotal]);
    }

    case 'pagar_comision': {
        exigir_rol_admin(); // solo el dueño paga comisiones
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $id = (int)(leer_cuerpo()['id'] ?? 0);
        if ($id <= 0) {
            responder(['ok' => false, 'error' => 'ID inválido'], 400);
        }
        $st = db()->prepare('SELECT id, orden_codigo, tecnico_nombre, monto, estado FROM comisiones WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $com = $st->fetch();
        if (!$com) {
            responder(['ok' => false, 'error' => 'Comisión no encontrada'], 404);
        }
        if ($com['estado'] !== 'Pendiente') {
            responder(['ok' => false, 'error' => 'Esta comisión ya fue gestionada'], 409);
        }
        $sesion = db()->query("SELECT id FROM caja_sesiones WHERE estado = 'Abierta' ORDER BY id DESC LIMIT 1")->fetch();
        if (!$sesion) {
            responder(['ok' => false, 'error' => 'No hay caja abierta: abre la caja para pagar comisiones'], 409);
        }
        db()->prepare("UPDATE comisiones SET estado = 'Pagada', fecha_pagada = NOW() WHERE id = ?")->execute([$id]);
        db()->prepare('INSERT INTO movimientos_caja (sesion_id, tipo, concepto, monto) VALUES (?, ?, ?, ?)')
            ->execute([(int)$sesion['id'], 'Egreso', 'Comisión técnico ' . $com['tecnico_nombre'] . ' — orden ' . $com['orden_codigo'], (int)$com['monto']]);
        db()->prepare('INSERT INTO orden_bitacora (orden_codigo, tecnico, nota) VALUES (?, ?, ?)')
            ->execute([(string)$com['orden_codigo'], (string)$com['tecnico_nombre'], 'Comisión pagada: $' . $com['monto']]);
        responder(['ok' => true]);
    }

    case 'crear_acceso': {
        // Cuenta de acceso para un técnico (solo administrador)
        exigir_rol_admin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $d = leer_cuerpo();
        $tecnicoId = (int)($d['tecnico_id'] ?? 0);
        $usuario   = trim((string)($d['usuario'] ?? ''));
        $password  = (string)($d['password'] ?? '');
        if ($tecnicoId <= 0 || $usuario === '' || $password === '') {
            responder(['ok' => false, 'error' => 'Técnico, usuario y contraseña son obligatorios'], 400);
        }
        if (!preg_match('/^[a-zA-Z0-9._-]{3,30}$/', $usuario)) {
            responder(['ok' => false, 'error' => 'Usuario inválido (3-30 caracteres: letras, números, punto, guion)'], 400);
        }
        $errorPolicy = validar_politica_password($password);
        if ($errorPolicy !== null) {
            responder(['ok' => false, 'error' => $errorPolicy], 400);
        }
        $st = db()->prepare('SELECT id, nombre FROM tecnicos WHERE id = ? AND activo = 1 LIMIT 1');
        $st->execute([$tecnicoId]);
        $tec = $st->fetch();
        if (!$tec) { responder(['ok' => false, 'error' => 'Técnico no encontrado'], 404); }
        $st2 = db()->prepare('SELECT id FROM usuarios_admin WHERE usuario = ? LIMIT 1');
        $st2->execute([$usuario]);
        if ($st2->fetch()) { responder(['ok' => false, 'error' => 'Ese usuario ya existe'], 409); }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $st3 = db()->prepare("SELECT id FROM usuarios_admin WHERE tecnico_id = ? AND rol = 'tecnico' LIMIT 1");
        $st3->execute([$tecnicoId]);
        $existente = $st3->fetch();
        if ($existente) {
            db()->prepare('UPDATE usuarios_admin SET usuario = ?, password_hash = ? WHERE id = ?')
                 ->execute([$usuario, $hash, (int)$existente['id']]);
        } else {
            db()->prepare("INSERT INTO usuarios_admin (usuario, password_hash, nombre, rol, tecnico_id) VALUES (?, ?, ?, 'tecnico', ?)")
                 ->execute([$usuario, $hash, $tec['nombre'], $tecnicoId]);
        }
        responder(['ok' => true]);
    }

    case 'quitar_acceso': {
        exigir_rol_admin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $tecnicoId = (int)(leer_cuerpo()['tecnico_id'] ?? 0);
        db()->prepare("DELETE FROM usuarios_admin WHERE tecnico_id = ? AND rol = 'tecnico'")->execute([$tecnicoId]);
        responder(['ok' => true]);
    }

    default:
        responder(['ok' => false, 'error' => 'Acción desconocida'], 400);
}