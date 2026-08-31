<?php
/**
 * LUITECH API — Reset de datos para puesta en producción (solo administrador).
 * Borra datos transaccionales POR GRUPOS (casillas) y NUNCA toca: usuario,
 * contraseña, configuración (logo/empresa/IVA/Mercado Pago) ni la estructura.
 * Candados: contraseña + palabra RESETEAR + caja cerrada + respaldo < 5 min.
 * Acciones: estado (GET) · ejecutar (POST)
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

iniciar_respuesta_json();
exigir_admin();

$action = $_GET['action'] ?? '';
$dirBackups = realpath(__DIR__ . '/../backups') ?: (__DIR__ . '/../backups');

/** Borra una tabla completa y devuelve cuántas filas tenía (-1 si falla). */
function tabla_borrar(PDO $pdo, string $t): int
{
    try {
        $n = (int)$pdo->query("SELECT COUNT(1) c FROM `$t`")->fetch()['c'];
        $pdo->exec("DELETE FROM `$t`");
        return $n;
    } catch (Throwable $e) {
        return -1;
    }
}

/** Devuelve el nombre del respaldo más reciente si es menor a $segundos. */
function respaldo_reciente(string $dir, int $segundos = 300): ?string
{
    $ultimo = null;
    $mtime = 0;
    foreach ((glob($dir . '/*.zip') ?: []) as $f) {
        $m = @filemtime($f);
        if ($m !== false && $m > $mtime) { $mtime = $m; $ultimo = basename($f); }
    }
    if ($ultimo === null || (time() - $mtime) > $segundos) { return null; }
    return $ultimo;
}

switch ($action) {

    case 'estado': {
        $abiertas = (int)db()->query("SELECT COUNT(1) c FROM caja_sesiones WHERE cierre_ts IS NULL")->fetch()['c'];
        responder([
            'ok' => true,
            'caja_abierta' => $abiertas > 0,
            'respaldo_reciente' => respaldo_reciente($dirBackups) !== null,
        ]);
    }

    case 'ejecutar': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $d = leer_cuerpo();

        // Candado 1: palabra de confirmación
        if (trim((string)($d['confirmacion'] ?? '')) !== 'RESETEAR') {
            responder(['ok' => false, 'error' => 'Escribe RESETEAR en el cuadro de confirmación'], 400);
        }

        // Candado 2: contraseña real del administrador
        $pass = (string)($d['password'] ?? '');
        $st = db()->prepare('SELECT password_hash FROM usuarios_admin WHERE id = ? LIMIT 1');
        $st->execute([$_SESSION['admin_id']]);
        $admin = $st->fetch();
        if (!$admin || $pass === '' || !password_verify($pass, $admin['password_hash'])) {
            usleep(300000);
            responder(['ok' => false, 'error' => 'Contraseña incorrecta'], 401);
        }

        // Candado 3: caja cerrada
        $abiertas = (int)db()->query("SELECT COUNT(1) c FROM caja_sesiones WHERE cierre_ts IS NULL")->fetch()['c'];
        if ($abiertas > 0) {
            responder(['ok' => false, 'error' => 'Hay una caja ABIERTA: ciérrala y cuádrala antes de resetear'], 409);
        }

        // Candado 4: respaldo reciente (menos de 5 minutos)
        $respaldo = respaldo_reciente($dirBackups);
        if ($respaldo === null) {
            responder(['ok' => false, 'error' => 'Primero genera el RESPALDO DE SEGURIDAD (paso 1). El reset exige un respaldo de menos de 5 minutos.'], 409);
        }

        $g = (array)($d['grupos'] ?? []);
        $pdo = db();
        $pdo->beginTransaction();
        $borrados = [];
        $demo = 0;
        try {
            if (!empty($g['ordenes'])) {
                foreach (['firma_ingreso', 'firma_entrega'] as $col) {
                    try {
                        $rutas = $pdo->query("SELECT `$col` FROM ordenes WHERE `$col` <> ''")->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($rutas as $r) {
                            $f = dirname(__DIR__) . '/' . ltrim((string)$r, '/');
                            if (is_file($f)) { @unlink($f); }
                        }
                    } catch (Throwable $e) { /* columna ausente */ }
                }
                try {
                    $rutas = $pdo->query("SELECT ruta FROM orden_fotos")->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($rutas as $r) {
                        $f = dirname(__DIR__) . '/' . ltrim((string)$r, '/');
                        if (is_file($f)) { @unlink($f); }
                    }
                } catch (Throwable $e) { /* tabla ausente */ }
                $borrados['bitácoras']     = tabla_borrar($pdo, 'orden_bitacora');
                $borrados['fotos órdenes'] = tabla_borrar($pdo, 'orden_fotos');
                $borrados['órdenes']       = tabla_borrar($pdo, 'ordenes');
            }
            if (!empty($g['ventas']))      { $borrados['ventas'] = tabla_borrar($pdo, 'ventas'); }
            if (!empty($g['caja'])) {
                $borrados['movimientos de caja'] = tabla_borrar($pdo, 'movimientos_caja');
                $borrados['sesiones de caja']    = tabla_borrar($pdo, 'caja_sesiones');
            }
            if (!empty($g['comisiones']))  { $borrados['comisiones'] = tabla_borrar($pdo, 'comisiones'); }
            if (!empty($g['gastos']))      { $borrados['gastos'] = tabla_borrar($pdo, 'gastos'); }
            if (!empty($g['stock']))       { $borrados['entradas de stock'] = tabla_borrar($pdo, 'entradas_stock'); }
            if (!empty($g['catalogo']))    { $borrados['catálogo'] = tabla_borrar($pdo, 'catalogo_proveedores'); }
            if (!empty($g['proveedores'])) { $borrados['proveedores'] = tabla_borrar($pdo, 'proveedores'); }
            if (!empty($g['productos']))   { $borrados['productos'] = tabla_borrar($pdo, 'productos'); }
            if (!empty($g['clientes'])) {
                try { $pdo->exec('UPDATE ordenes SET cliente_id = NULL'); } catch (Throwable $e) { /* sin FK */ }
                $borrados['clientes'] = tabla_borrar($pdo, 'clientes');
            }
            if (!empty($g['tecnicos'])) {
                try { $pdo->exec('UPDATE ordenes SET tecnico_id = NULL'); } catch (Throwable $e) { /* sin FK */ }
                $borrados['técnicos'] = tabla_borrar($pdo, 'tecnicos');
            }
            if (!empty($d['demo']) && !empty($g['ordenes'])) {
                $semillas = [
                    ['LUH-1024', 'Carlos Mendoza',    'iPhone 13 Pro',          'Celular',     'Cambio de Pantalla OLED',          'Listo para Retiro', 100, 'Sebastián R.', '2026-07-16'],
                    ['LUH-1025', 'María Paz Rojas',   'Notebook Asus ROG',      'PC/Notebook', 'Mantenimiento térmico y limpieza', 'En Reparación',      60, 'Alexis M.',    '2026-07-16'],
                    ['LUH-1026', 'Juan Pablo Cortés', 'Samsung S22 Ultra',      'Celular',     'Cambio de puerto de carga',        'En Diagnóstico',     30, 'Sebastián R.', '2026-07-17'],
                    ['LUH-1027', 'Valentina Silva',   'PC de Escritorio Gamer', 'PC/Notebook', 'Instalación de Sistema y SSD',     'Listo para Retiro', 100, 'Alexis M.',    '2026-07-15'],
                    ['LUH-1028', 'Pedro Aguilera',    'Xiaomi Redmi Note 11',   'Celular',     'Cambio de batería',                'Ingresado',          10, 'Por Asignar',  '2026-07-17'],
                ];
                $st = $pdo->prepare('INSERT INTO ordenes (codigo, cliente, equipo, tipo, falla, estado, avance, tecnico, fecha_ingreso) VALUES (?,?,?,?,?,?,?,?,?)');
                foreach ($semillas as $s) { $st->execute($s); }
                $demo = count($semillas);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            responder(['ok' => false, 'error' => 'Error durante el reset (NO se aplicó): ' . $e->getMessage()], 500);
        }

        responder(['ok' => true, 'borrados' => $borrados, 'demo' => $demo, 'respaldo' => $respaldo]);
    }

    default:
        responder(['ok' => false, 'error' => 'Acción desconocida'], 400);
}
