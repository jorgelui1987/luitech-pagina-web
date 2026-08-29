<?php
/**
 * LUITECH API - Integración con Mercado Pago (opcional).
 * Se activa en Configuración → Mercado Pago (mp_enabled + Access Token).
 * Acciones (?action=):
 *   crear_link         POST {codigo}  -> link/QR de pago del saldo de una orden
 *   point_dispositivos GET            -> lista los terminales Point de la cuenta
 *   point_cobrar       POST {codigo}  -> intento de cobro en el terminal Point
 *   point_estado       GET ?id=       -> estado de un intento Point
 *   webhook            POST (público) -> notificaciones de pago de Mercado Pago
 * Sin cuenta/token configurado, todo responde 409 y nada se rompe.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

iniciar_respuesta_json();

$action = $_GET['action'] ?? '';

/** Configuración de Mercado Pago leída desde la BD. */
function mp_config(): array
{
    return [
        'enabled' => config_valor(db(), 'mp_enabled', '0') === '1',
        'token'   => config_valor(db(), 'mp_access_token', ''),
        'device'  => config_valor(db(), 'mp_point_device', ''),
    ];
}

/** Llamada JSON a la API de Mercado Pago. Devuelve [código_http, datos]. */
function mp_api(string $metodo, string $ruta, ?array $cuerpo, string $token): array
{
    $ch = curl_init('https://api.mercadopago.com' . $ruta);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => $cuerpo === null ? null : json_encode($cuerpo),
    ]);
    $respuesta = curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $datos = is_string($respuesta) ? json_decode($respuesta, true) : null;
    return [$codigo, is_array($datos) ? $datos : []];
}

/** URL pública del webhook, deducida de la petición actual ('' si no hay host). */
function mp_webhook_url(): string
{
    if (empty($_SERVER['HTTP_HOST'])) {
        return '';
    }
    $esquema = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $esquema . '://' . $_SERVER['HTTP_HOST'] . $dir . '/pagos_mp.php?action=webhook';
}

/** Aplica un pago aprobado a la orden: abono, estado, caja y bitácora.
 *  Idempotente: si el id de pago ya quedó en la bitácora, no repite. */
function mp_aplicar_pago_orden(PDO $pdo, string $codigo, int $monto, string $pagoId): void
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM orden_bitacora WHERE nota LIKE ?');
    $st->execute(['%[MP ' . $pagoId . ']%']);
    if ((int)$st->fetchColumn() > 0) {
        return;
    }
    $orden = $pdo->prepare('SELECT total, abono FROM ordenes WHERE codigo = ?');
    $orden->execute([$codigo]);
    $fila = $orden->fetch();
    if (!$fila) {
        return;
    }
    $total = (int)$fila['total'];
    $abonoActual = (int)$fila['abono'];
    $nuevoAbono = min($total, $abonoActual + $monto);
    if ($nuevoAbono <= $abonoActual) {
        return; // ya estaba cubierto
    }
    $pdo->prepare('UPDATE ordenes SET abono = ?, metodo_pago = ? WHERE codigo = ?')
        ->execute([$nuevoAbono, 'Mercado Pago', $codigo]);
    $estadoPago = ($total > 0 && $nuevoAbono >= $total) ? 'Pagado' : 'Abonado';
    $pdo->prepare('UPDATE ordenes SET estado_pago = ? WHERE codigo = ?')->execute([$estadoPago, $codigo]);

    $sesion = $pdo->query("SELECT id FROM caja_sesiones WHERE estado='Abierta' ORDER BY id DESC LIMIT 1")->fetch();
    if ($sesion) {
        $pdo->prepare('INSERT INTO movimientos_caja (sesion_id, tipo, concepto, monto) VALUES (?, ?, ?, ?)')
            ->execute([(int)$sesion['id'], 'Ingreso', 'Cobro orden ' . $codigo . ' (Mercado Pago)', $nuevoAbono - $abonoActual]);
    }
    $pdo->prepare('INSERT INTO orden_bitacora (orden_codigo, tecnico, nota) VALUES (?, ?, ?)')
        ->execute([$codigo, 'Mercado Pago', 'Pago vía Mercado Pago: $' . ($nuevoAbono - $abonoActual) . ' [MP ' . $pagoId . ']']);
}

switch ($action) {

    case 'crear_link': {
        exigir_admin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $cfg = mp_config();
        if (!$cfg['enabled'] || $cfg['token'] === '') {
            responder(['ok' => false, 'error' => 'Mercado Pago no está habilitado en Configuración'], 409);
        }
        $codigo = strtoupper(trim((string)(leer_cuerpo()['codigo'] ?? '')));
        if (preg_match('/^LUH-\d{3,8}$/', $codigo) !== 1) {
            responder(['ok' => false, 'error' => 'Código inválido'], 400);
        }
        $st = db()->prepare('SELECT cliente, total, abono FROM ordenes WHERE codigo = ?');
        $st->execute([$codigo]);
        $orden = $st->fetch();
        if (!$orden) {
            responder(['ok' => false, 'error' => 'Orden no encontrada'], 404);
        }
        $saldo = max(0, (int)$orden['total'] - (int)$orden['abono']);
        if ($saldo < 1) {
            responder(['ok' => false, 'error' => 'Esta orden no tiene saldo pendiente'], 409);
        }
        $prefBody = [
            'items' => [[
                'title'       => 'Orden ' . $codigo . ' — ' . (string)$orden['cliente'],
                'quantity'    => 1,
                'unit_price'  => (float)$saldo,
                'currency_id' => 'CLP',
            ]],
            'external_reference' => $codigo,
        ];
        $webhook = mp_webhook_url();
        if ($webhook !== '') {
            $prefBody['notification_url'] = $webhook;
        }
        [$codigoHttp, $pref] = mp_api('POST', '/checkout/preferences', $prefBody, $cfg['token']);
        if ($codigoHttp >= 200 && $codigoHttp < 300 && !empty($pref['init_point'])) {
            responder(['ok' => true, 'init_point' => $pref['init_point'], 'saldo' => $saldo]);
        }
        responder(['ok' => false, 'error' => 'Mercado Pago rechazó la petición (¿token válido?)'], 502);
    }

    case 'point_dispositivos': {
        exigir_admin();
        $cfg = mp_config();
        if (!$cfg['enabled'] || $cfg['token'] === '') {
            responder(['ok' => false, 'error' => 'Mercado Pago no está habilitado'], 409);
        }
        [$codigoHttp, $datos] = mp_api('GET', '/point-integration-api/devices', null, $cfg['token']);
        responder(['ok' => $codigoHttp >= 200 && $codigoHttp < 300, 'dispositivos' => $datos]);
    }

    case 'point_cobrar': {
        exigir_admin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $cfg = mp_config();
        if (!$cfg['enabled'] || $cfg['token'] === '') {
            responder(['ok' => false, 'error' => 'Mercado Pago no está habilitado'], 409);
        }
        $codigo = strtoupper(trim((string)(leer_cuerpo()['codigo'] ?? '')));
        if (preg_match('/^LUH-\d{3,8}$/', $codigo) !== 1) {
            responder(['ok' => false, 'error' => 'Código inválido'], 400);
        }
        $st = db()->prepare('SELECT total, abono FROM ordenes WHERE codigo = ?');
        $st->execute([$codigo]);
        $orden = $st->fetch();
        if (!$orden) {
            responder(['ok' => false, 'error' => 'Orden no encontrada'], 404);
        }
        $saldo = max(0, (int)$orden['total'] - (int)$orden['abono']);
        if ($saldo < 1) {
            responder(['ok' => false, 'error' => 'Esta orden no tiene saldo pendiente'], 409);
        }
        [$codigoHttp, $intent] = mp_api('POST', '/point-integration-api/payment-intents', [
            'amount'          => $saldo,
            'description'     => 'Orden ' . $codigo,
            'payment'         => ['transaction_amount' => $saldo, 'payment_method_reference' => 'MPE'],
            'additional_info' => ['external_reference' => $codigo, 'print_on_terminal' => true],
        ], $cfg['token']);
        if ($codigoHttp >= 200 && $codigoHttp < 300 && !empty($intent['id'])) {
            responder(['ok' => true, 'intent_id' => $intent['id'], 'estado' => $intent['status'] ?? '']);
        }
        responder(['ok' => false, 'error' => 'Mercado Pago rechazó el intento (¿terminal en modo integración?)'], 502);
    }

    case 'point_estado': {
        exigir_admin();
        $cfg = mp_config();
        if (!$cfg['enabled'] || $cfg['token'] === '') {
            responder(['ok' => false, 'error' => 'Mercado Pago no está habilitado'], 409);
        }
        $intentId = trim((string)($_GET['id'] ?? ''));
        if (preg_match('/^[A-Za-z0-9\-]{8,80}$/', $intentId) !== 1) {
            responder(['ok' => false, 'error' => 'ID de intento inválido'], 400);
        }
        [$codigoHttp, $intent] = mp_api('GET', '/point-integration-api/payment-intents/' . rawurlencode($intentId), null, $cfg['token']);
        $estado = (string)($intent['status'] ?? '');
        $referencia = (string)($intent['additional_info']['external_reference'] ?? '');
        if ($estado === 'approved' && preg_match('/^LUH-\d{3,8}$/', $referencia) === 1) {
            mp_aplicar_pago_orden(db(), $referencia, (int)($intent['amount'] ?? 0), (string)($intent['payment']['id'] ?? $intentId));
        }
        responder(['ok' => $codigoHttp >= 200 && $codigoHttp < 300, 'intento' => $intent]);
    }

    case 'webhook': {
        // PÚBLICO: Mercado Pago avisa aquí. No exige sesión y siempre responde 200
        // (para que MP no reintente infinito). La validación real es consultar el
        // pago con el token del comercio guardado en Configuración.
        $d = leer_cuerpo();
        $pagoId = (string)($d['data']['id'] ?? $d['id'] ?? $_GET['data.id'] ?? '');
        if ($pagoId === '' || preg_match('/^\d{5,20}$/', $pagoId) !== 1) {
            responder(['ok' => true]);
        }
        $cfg = mp_config();
        if (!$cfg['enabled'] || $cfg['token'] === '') {
            responder(['ok' => true]);
        }
        [$codigoHttp, $pago] = mp_api('GET', '/v1/payments/' . $pagoId, null, $cfg['token']);
        $estado = (string)($pago['status'] ?? '');
        $referencia = (string)($pago['external_reference'] ?? '');
        $monto = (int)round((float)($pago['transaction_amount'] ?? 0));
        if ($codigoHttp === 200 && $estado === 'approved'
            && preg_match('/^LUH-\d{3,8}$/', $referencia) === 1 && $monto > 0) {
            mp_aplicar_pago_orden(db(), $referencia, $monto, $pagoId);
        }
        responder(['ok' => true]);
    }

    default:
        responder(['ok' => false, 'error' => 'Acción desconocida'], 400);
}