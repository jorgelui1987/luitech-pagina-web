<?php
/**
 * LUITECH API - Configuraciones globales del sistema (solo administrador).
 * Acciones (?action=):
 *   get  GET                     -> { iva_porcentaje }
 *   set  POST {iva_porcentaje}   -> guarda la tasa (0..100)
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

iniciar_respuesta_json();
exigir_admin();

$action = $_GET['action'] ?? '';

/** Lee un valor de configuración (o el fallback indicado si no existe). */
function config_valor(PDO $pdo, string $clave, string $fallback): string
{
    $st = $pdo->prepare('SELECT valor FROM configuraciones WHERE clave = ? LIMIT 1');
    $st->execute([$clave]);
    $valor = $st->fetchColumn();
    return ($valor === false || $valor === null) ? $fallback : (string)$valor;
}

switch ($action) {

    case 'get':
        responder(['ok' => true, 'iva_porcentaje' => (int)config_valor(db(), 'iva_porcentaje', '19')]);

    case 'set': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $tasa = (int)(leer_cuerpo()['iva_porcentaje'] ?? -1);
        if ($tasa < 0 || $tasa > 100) {
            responder(['ok' => false, 'error' => 'La tasa de IVA debe estar entre 0 y 100'], 400);
        }
        db()->prepare(
            "INSERT INTO configuraciones (clave, valor) VALUES ('iva_porcentaje', ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
        )->execute([(string)$tasa]);
        responder(['ok' => true, 'iva_porcentaje' => $tasa]);
    }

    default:
        responder(['ok' => false, 'error' => 'Acción desconocida'], 400);
}
