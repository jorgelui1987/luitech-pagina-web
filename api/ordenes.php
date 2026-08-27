<?php
/**
 * LUITECH API - Órdenes de trabajo.
 * Acciones (GET param ?action=):
 *   track  (GET)  ?codigo=LUH-1024   Público: consulta individual (sin datos personales)
 *   resumen(GET)                     Público: lista ligera para pantalla TV de sala
 *   list   (GET)                     Solo admin: todas las órdenes completas
 *   create (POST)                    Solo admin: nueva orden
 *   update (POST)                    Solo admin: cambia estado/avance u otros campos
 *   delete (POST)                    Solo admin: elimina orden
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

iniciar_respuesta_json();

const ESTADOS_VALIDOS = ['Ingresado', 'En Diagnóstico', 'En Reparación', 'Listo para Retiro'];

$action = $_GET['action'] ?? '';

switch ($action) {

    /* ------------------------------------------------------------ TRACK */
    case 'track': {
        $codigo = strtoupper(trim($_GET['codigo'] ?? ''));
        if (!preg_match('/^LUH-\d{3,8}$/', $codigo)) {
            responder(['ok' => false, 'error' => 'Formato de código inválido (ej: LUH-1024)'], 400);
        }

        $stmt = db()->prepare(
            'SELECT codigo, equipo, falla, estado, avance, tecnico, fecha_ingreso
             FROM ordenes WHERE codigo = ? LIMIT 1'
        );
        $stmt->execute([$codigo]);
        $orden = $stmt->fetch();

        if (!$orden) {
            responder(['ok' => false, 'error' => 'Orden no encontrada'], 404);
        }

        // Nota: se omite deliberadamente el nombre del cliente (privacidad pública)
        responder(['ok' => true, 'orden' => $orden]);
    }

    /* ---------------------------------------------------------- RESUMEN */
    case 'resumen': {
        $stmt = db()->query(
            "SELECT codigo, equipo, estado, avance FROM ordenes
             WHERE estado IN ('Listo para Retiro','En Reparación','En Diagnóstico')
             ORDER BY FIELD(estado,'Listo para Retiro','En Reparación','En Diagnóstico'), id DESC"
        );
        responder(['ok' => true, 'ordenes' => $stmt->fetchAll()]);
    }


    /* ------------------------------------------------------------- LIST */
    case 'list': {
        exigir_admin();
        $stmt = db()->query(
            'SELECT id, codigo, cliente, equipo, tipo, falla, estado, avance, tecnico, fecha_ingreso
             FROM ordenes ORDER BY id DESC'
        );
        responder(['ok' => true, 'ordenes' => $stmt->fetchAll()]);
    }

    /* ----------------------------------------------------------- CREATE */
    case 'create': {
        exigir_admin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }

        $d = leer_cuerpo();

        $cliente = campo_texto($d, 'cliente', 120);
        $equipo  = campo_texto($d, 'equipo', 120);
        $falla   = campo_texto($d, 'falla', 1000);
        $tecnico = campo_texto($d, 'tecnico', 80) ?? 'Por Asignar';
        $tipo    = in_array(($d['tipo'] ?? ''), ['Celular', 'PC/Notebook', 'Otro'], true) ? $d['tipo'] : 'Otro';

        if ($cliente === null || $equipo === null || $falla === null) {
            responder(['ok' => false, 'error' => 'Faltan campos obligatorios (cliente, equipo, falla)'], 400);
        }

        // Código: autogenerado como siguiente correlativo (LUH-nnnn) si no viene uno válido
        $codigo = strtoupper(trim((string)($d['codigo'] ?? '')));
        if ($codigo !== '' && !preg_match('/^LUH-\d{3,8}$/', $codigo)) {
            responder(['ok' => false, 'error' => 'El código debe tener formato LUH-número (ej: LUH-1029)'], 400);
        }
        if ($codigo === '') {
            $siguiente = (int)(db()->query(
                "SELECT COALESCE(MAX(CAST(SUBSTRING(codigo, 5) AS UNSIGNED)), 1023) AS m FROM ordenes"
            )->fetch()['m'] ?? 1023) + 1;
            $codigo = 'LUH-' . $siguiente;
        }

        try {
            $fecha    = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['fecha'] ?? '')) ? $d['fecha'] : date('Y-m-d');
            $estadoIn = in_array(($d['estado'] ?? ''), ESTADOS_VALIDOS, true) ? $d['estado'] : 'Ingresado';
            $avanceIn = max(0, min(100, (int)($d['avance'] ?? 10)));

            $stmt = db()->prepare(
                'INSERT INTO ordenes (codigo, cliente, equipo, tipo, falla, estado, avance, tecnico, fecha_ingreso)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$codigo, $cliente, $equipo, $tipo, $falla, $estadoIn, $avanceIn, $tecnico, $fecha]);

            responder(['ok' => true, 'orden' => [
                'codigo' => $codigo, 'cliente' => $cliente, 'equipo' => $equipo,
                'tipo' => $tipo, 'falla' => $falla, 'estado' => $estadoIn,
                'avance' => $avanceIn, 'tecnico' => $tecnico, 'fecha_ingreso' => $fecha,
            ]]);
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000) {
                responder(['ok' => false, 'error' => 'Ya existe una orden con ese código'], 409);
            }
            throw $e;
        }
    }

    /* ----------------------------------------------------------- UPDATE */
    case 'update': {
        exigir_admin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }

        $d      = leer_cuerpo();
        $codigo = strtoupper(trim((string)($d['codigo'] ?? '')));
        if (!preg_match('/^LUH-\d{3,8}$/', $codigo)) {
            responder(['ok' => false, 'error' => 'Código inválido'], 400);
        }

        $set    = [];
        $params = [];

        if (isset($d['estado'])) {
            if (!in_array($d['estado'], ESTADOS_VALIDOS, true)) {
                responder(['ok' => false, 'error' => 'Estado inválido'], 400);
            }
            $set[]    = 'estado = ?';
            $params[] = $d['estado'];
        }
        if (isset($d['avance'])) {
            $set[]    = 'avance = ?';
            $params[] = max(0, min(100, (int)$d['avance']));
        }
        if (isset($d['falla'])) {
            $falla = campo_texto($d, 'falla', 1000);
            if ($falla === null) {
                responder(['ok' => false, 'error' => 'Detalle inválido'], 400);
            }
            $set[]    = 'falla = ?';
            $params[] = $falla;
        }
        if (isset($d['tecnico'])) {
            $set[]    = 'tecnico = ?';
            $params[] = campo_texto($d, 'tecnico', 80) ?? 'Por Asignar';
        }

        if (!$set) {
            responder(['ok' => false, 'error' => 'Nada que actualizar'], 400);
        }

        $params[] = $codigo;
        $stmt = db()->prepare('UPDATE ordenes SET ' . implode(', ', $set) . ' WHERE codigo = ?');
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            $existe = db()->prepare('SELECT 1 FROM ordenes WHERE codigo = ?');
            $existe->execute([$codigo]);
            if (!$existe->fetch()) {
                responder(['ok' => false, 'error' => 'Orden no encontrada'], 404);
            }
        }

        $stmt2 = db()->prepare('SELECT codigo, estado, avance, tecnico, falla FROM ordenes WHERE codigo = ?');
        $stmt2->execute([$codigo]);
        responder(['ok' => true, 'orden' => $stmt2->fetch()]);
    }

    /* ----------------------------------------------------------- DELETE */
    case 'delete': {
        exigir_admin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $d      = leer_cuerpo();
        $codigo = strtoupper(trim((string)($d['codigo'] ?? '')));
        if (!preg_match('/^LUH-\d{3,8}$/', $codigo)) {
            responder(['ok' => false, 'error' => 'Código inválido'], 400);
        }
        $stmt = db()->prepare('DELETE FROM ordenes WHERE codigo = ?');
        $stmt->execute([$codigo]);
        if ($stmt->rowCount() === 0) {
            responder(['ok' => false, 'error' => 'Orden no encontrada'], 404);
        }
        responder(['ok' => true]);
    }

    default:
        responder(['ok' => false, 'error' => 'Acción desconocida'], 400);
}


