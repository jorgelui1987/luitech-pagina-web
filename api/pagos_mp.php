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

preparar_configuraciones(); // garantiza la tabla aunque el hosting no ejecute migrate

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

/** Llamada JSON a la API de Mercado Pago. Devuelve [código_http, datos].
 *  Si cURL no existe o no hay salida a internet, devuelve código 0 con el error.
 *  $idempotencia: para POST, clave de idempotencia (recomendada por MP en /v1/orders). */
function mp_api(string $metodo, string $ruta, ?array $cuerpo, string $token, ?string $idempotencia = null): array
{
    if (!function_exists('curl_init')) {
        return [0, ['curl_error' => 'El servidor no tiene la extensión cURL habilitada']];
    }
    $cabeceras = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ];
    if ($idempotencia !== null) {
        $cabeceras[] = 'X-Idempotency-Key: ' . $idempotencia;
    }
    $ch = curl_init('https://api.mercadopago.com' . $ruta);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => $cabeceras,
        CURLOPT_POSTFIELDS     => $cuerpo === null ? null : json_encode($cuerpo),
    ]);
    $respuesta = curl_exec($ch);
    $errorCurl = curl_error($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($respuesta === false || $codigo === 0) {
        return [0, ['curl_error' => ($errorCurl !== '' ? $errorCurl : 'sin respuesta del servidor')]];
    }
    $datos = json_decode((string)$respuesta, true);
    return [$codigo, is_array($datos) ? $datos : []];
}

/** Arma el texto de error legible desde la respuesta de Mercado Pago. */
function mp_error_detalle(array $r): string
{
    $detalle = '';
    foreach (['message', 'error', 'curl_error'] as $campo) {
        if (!empty($r[$campo])) {
            $detalle .= ($detalle === '' ? '' : ' | ') . (is_array($r[$campo]) ? json_encode($r[$campo], JSON_UNESCAPED_UNICODE) : (string)$r[$campo]);
        }
    }
    if (!empty($r['errors']) && is_array($r['errors'])) {
        foreach ($r['errors'] as $e) {
            if (!is_array($e)) continue;
            $detalle .= ($detalle === '' ? '' : ' | ')
                . ($e['code'] ?? '')
                . (isset($e['message']) ? ': ' . $e['message'] : '')
                . (isset($e['detail']) ? ' — ' . (is_array($e['detail']) ? json_encode($e['detail'], JSON_UNESCAPED_UNICODE) : $e['detail']) : '');
        }
    }
    if (!empty($r['cause'])) {
        $detalle .= ($detalle === '' ? '' : ' | ') . json_encode($r['cause'], JSON_UNESCAPED_UNICODE);
    }
    if ($detalle === '') {
        $detalle = substr(json_encode($r, JSON_UNESCAPED_UNICODE), 0, 300); // cuerpo completo si no hay campos conocidos
    }
    return $detalle;
}

/** Crea la orden de cobro en /v1/orders probando las variantes del schema
 *  (la documentación de MP contradice su propio ejemplo sobre el tipo de
 *  transactions.payments). Devuelve [http, respuesta, variante_usada]. */
function mp_crear_orden_point(float $monto, string $referencia, string $descripcion, string $device, string $token): array
{
    $variantes = [
        'array-entero' => ['payments' => [['amount' => (int)$monto]]],
        'array-texto'  => ['payments' => [['amount' => (string)$monto]]],
        'objeto-texto' => ['payments' => ['amount' => (string)$monto]],
    ];
    $codigoHttp = 0;
    $ordenMP = [];
    $usada = '';
    foreach ($variantes as $nombre => $pagos) {
        $payload = [
            'type'               => 'point',
            'external_reference' => $referencia,
            'description'        => $descripcion,
            'transactions'       => $pagos,
            'config'             => ['point' => ['terminal_id' => $device]],
        ];
        [$codigoHttp, $ordenMP] = mp_api('POST', '/v1/orders', $payload, $token, 'luitech-' . $referencia . '-' . $nombre . '-' . time());
        if ($codigoHttp >= 200 && $codigoHttp < 300 && !empty($ordenMP['id'])) {
            return [$codigoHttp, $ordenMP, $nombre];
        }
        if ($codigoHttp !== 400) {
            break; // 403/409/401/etc: no es problema de formato, no reintentar variantes
        }
        $usada = $nombre;
    }
    return [$codigoHttp, $ordenMP, $usada];
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
    // Pago completo → entrega automática del equipo (con comisión del técnico)
    auto_entregar_si_pagada($pdo, $codigo);
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
        if (!empty($_SERVER['HTTP_HOST'])) {
            $esquema = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $dirApi  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
            $raiz    = substr($dirApi, 0, (int)strrpos($dirApi, '/'));
            $volver  = $esquema . '://' . $_SERVER['HTTP_HOST'] . $raiz . '/admin.html';
            $prefBody['back_urls'] = ['success' => $volver, 'pending' => $volver, 'failure' => $volver];
        }
        [$codigoHttp, $pref] = mp_api('POST', '/checkout/preferences', $prefBody, $cfg['token']);
        if ($codigoHttp >= 200 && $codigoHttp < 300 && !empty($pref['init_point'])) {
            responder(['ok' => true, 'init_point' => $pref['init_point'], 'saldo' => $saldo]);
        }
        if ($codigoHttp === 0) {
            responder(['ok' => false, 'error' => 'Sin salida a internet desde el servidor: ' . ($pref['curl_error'] ?? 'desconocido')], 502);
        }
        responder(['ok' => false, 'error' => 'Mercado Pago rechazó la petición (HTTP ' . $codigoHttp . (isset($pref['message']) ? ': ' . ($pref['message'] ?? '') : '') . ' — ¿token válido?)'], 502);
    }

    case 'point_dispositivos': {
        // Lista los dispositivos Point de la cuenta (API Point Orders)
        exigir_admin();
        $cfg = mp_config();
        if (!$cfg['enabled'] || $cfg['token'] === '') {
            responder(['ok' => false, 'error' => 'Mercado Pago no está habilitado'], 409);
        }
        [$codigoHttp, $datos] = mp_api('GET', '/point/integration-api/devices', null, $cfg['token']);
        responder(['ok' => $codigoHttp >= 200 && $codigoHttp < 300, 'http' => $codigoHttp,
                   'dispositivos' => $datos,
                   'body' => $codigoHttp !== 200 ? substr(json_encode($datos, JSON_UNESCAPED_UNICODE), 0, 250) : '']);
    }

    case 'point_pdv': {
        // Activa el modo PDV (integrado) en el terminal configurado
        exigir_admin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $cfg = mp_config();
        if (!$cfg['enabled'] || $cfg['token'] === '') {
            responder(['ok' => false, 'error' => 'Mercado Pago no está habilitado'], 409);
        }
        $deviceId = trim((string)(leer_cuerpo()['device_id'] ?? ($cfg['device'] ?? '')));
        if ($deviceId === '') {
            responder(['ok' => false, 'error' => 'Falta el Device ID del terminal'], 400);
        }
        [$codigoHttp, $resultado] = mp_api('PATCH', '/terminals/v1/setup', [
            'terminals' => [['id' => $deviceId, 'operating_mode' => 'PDV']],
        ], $cfg['token']);
        $ok = $codigoHttp >= 200 && $codigoHttp < 300;
        responder(['ok' => $ok, 'http' => $codigoHttp,
                   'detalle' => $ok ? ($resultado['terminals'][0] ?? '') : substr(json_encode($resultado, JSON_UNESCAPED_UNICODE), 0, 250)]);
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
        $st = db()->prepare('SELECT total, abono, cliente FROM ordenes WHERE codigo = ?');
        $st->execute([$codigo]);
        $orden = $st->fetch();
        if (!$orden) {
            responder(['ok' => false, 'error' => 'Orden no encontrada'], 404);
        }
        $saldo = max(0, (int)$orden['total'] - (int)$orden['abono']);
        if ($saldo < 1) {
            responder(['ok' => false, 'error' => 'Esta orden no tiene saldo pendiente'], 409);
        }
        // Crea la orden de cobro en el terminal (API Orders /v1/orders)
        [$codigoHttp, $ordenMP, $usada] = mp_crear_orden_point(
            (float)$saldo,
            $codigo,
            mb_substr('Orden ' . $codigo . ' - ' . (string)$orden['cliente'], 0, 100),
            $cfg['device'],
            $cfg['token']
        );
        if ($codigoHttp >= 200 && $codigoHttp < 300 && !empty($ordenMP['id'])) {
            responder(['ok' => true, 'order_id' => (string)$ordenMP['id'], 'monto' => $saldo, 'variante' => $usada]);
        }
        $detalle = mp_error_detalle($ordenMP);
        if ($usada !== '') {
            $detalle .= ' | (variantes probadas, última: ' . $usada . ')';
        }
        responder(['ok' => false, 'error' => 'Mercado Pago rechazó el intento (HTTP ' . $codigoHttp . ')' . ($detalle !== '' ? ': ' . $detalle : '')], 502);
    }

    case 'point_cobrar_venta': {
        // Envía el TOTAL del carrito del POS al terminal Point.
        // external_reference "POS-..." (no LUH): el webhook la ignora y la venta
        // la registra el POS al confirmarse el pago (evita doble ingreso a caja).
        exigir_admin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $cfg = mp_config();
        if (!$cfg['enabled'] || $cfg['token'] === '') {
            responder(['ok' => false, 'error' => 'Mercado Pago no está habilitado'], 409);
        }
        if ($cfg['device'] === '') {
            responder(['ok' => false, 'error' => 'Configura el Device ID del terminal Point'], 409);
        }
        $monto = (int)round((float)(leer_cuerpo()['monto'] ?? 0));
        if ($monto < 1) {
            responder(['ok' => false, 'error' => 'Monto inválido'], 400);
        }
        $referencia = 'POS-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        [$codigoHttp, $ordenMP, $usada] = mp_crear_orden_point(
            (float)$monto,
            $referencia,
            'Venta mostrador POS',
            $cfg['device'],
            $cfg['token']
        );
        if ($codigoHttp >= 200 && $codigoHttp < 300 && !empty($ordenMP['id'])) {
            responder(['ok' => true, 'order_id' => (string)$ordenMP['id'], 'monto' => $monto, 'referencia' => $referencia, 'variante' => $usada]);
        }
        $detalle = mp_error_detalle($ordenMP);
        if ($usada !== '') {
            $detalle .= ' | (variantes probadas, última: ' . $usada . ')';
        }
        responder(['ok' => false, 'error' => 'Mercado Pago rechazó el intento (HTTP ' . $codigoHttp . ')' . ($detalle !== '' ? ': ' . $detalle : '')], 502);
    }

    case 'point_estado': {
        exigir_admin();
        $cfg = mp_config();
        if (!$cfg['enabled'] || $cfg['token'] === '') {
            responder(['ok' => false, 'error' => 'Mercado Pago no está habilitado'], 409);
        }
        $orderId = trim((string)($_GET['order_id'] ?? ''));
        if ($orderId === '') {
            responder(['ok' => false, 'error' => 'Falta el ID de la orden de Mercado Pago'], 400);
        }
        [$codigoHttp, $ordenMP] = mp_api('GET', '/v1/orders/' . rawurlencode($orderId), null, $cfg['token']);
        if ($codigoHttp !== 200) {
            responder(['ok' => false, 'error' => 'No se pudo consultar el estado (HTTP ' . $codigoHttp . ')'], 502);
        }
        $status = strtolower((string)($ordenMP['status'] ?? ''));
        $isPaid = in_array($status, ['processed', 'closed', 'accredited', 'approved'], true);
        $referencia = (string)($ordenMP['external_reference'] ?? '');
        // Monto pagado: total_paid_amount o la suma de las transacciones
        $montoPagado = (int)round((float)($ordenMP['total_paid_amount'] ?? 0));
        if ($montoPagado === 0 && isset($ordenMP['transactions']['payments']) && is_array($ordenMP['transactions']['payments'])) {
            foreach ($ordenMP['transactions']['payments'] as $pago) {
                $montoPagado += (int)round((float)($pago['paid_amount'] ?? 0));
            }
        }
        if ($isPaid && preg_match('/^LUH-\d{3,8}$/', $referencia) === 1 && $montoPagado > 0) {
            mp_aplicar_pago_orden(db(), $referencia, $montoPagado, 'mp-order-' . $orderId);
        }
        responder(['ok' => true, 'estado' => $status, 'pagada' => $isPaid, 'monto' => $montoPagado]);
    }

    case 'webhook': {
        // PÚBLICO: Mercado Pago avisa aquí. No exige sesión y siempre responde 200
        // (para que MP no reintente infinito). La validación real es consultar el
        // pago/intento con el token del comercio guardado en Configuración.
        $d = leer_cuerpo();
        $pagoId = (string)($d['data']['id'] ?? $d['id'] ?? $_GET['data.id'] ?? '');
        $tipo   = (string)($d['type'] ?? $d['topic'] ?? $_GET['type'] ?? 'payment');
        if ($pagoId === '') {
            responder(['ok' => true]);
        }
        $cfg = mp_config();
        if (!$cfg['enabled'] || $cfg['token'] === '') {
            responder(['ok' => true]);
        }

        // Notificaciones del terminal Point (intento de pago integrado)
        if ($tipo === 'point_integration_webhook') {
            if (preg_match('/^[A-Za-z0-9\-]{8,120}$/', $pagoId) !== 1) {
                responder(['ok' => true]);
            }
            [$codigoHttpI, $intent] = mp_api('GET', '/point/integration-api/payment-intents/' . rawurlencode($pagoId), null, $cfg['token']);
            $referencia  = (string)($intent['additional_info']['external_reference'] ?? '');
            $montoIntent = (int)round((float)($intent['amount'] ?? ($intent['payment']['transaction_amount'] ?? 0)));
            $aprobado    = (($intent['status'] ?? '') === 'approved' || (($intent['payment']['status'] ?? '') === 'approved'));
            if ($aprobado && preg_match('/^LUH-\d{3,8}$/', $referencia) === 1 && $montoIntent > 0) {
                mp_aplicar_pago_orden(db(), $referencia, $montoIntent, (string)($intent['payment']['id'] ?? $pagoId));
            }
            responder(['ok' => true]);
        }

        // Notificaciones de orden (terminal Point en modo PDV)
        if ($tipo === 'order' || str_starts_with((string)($d['action'] ?? ''), 'order.')) {
            $referencia  = (string)($d['data']['external_reference'] ?? '');
            $montoOrden  = (int)round((float)($d['data']['total_paid_amount'] ?? 0));
            $estadoOrden = (string)($d['data']['status'] ?? '');
            $aprobado    = in_array($estadoOrden, ['processed', 'accredited'], true);
            if ($aprobado && preg_match('/^LUH-\d{3,8}$/', $referencia) === 1 && $montoOrden > 0) {
                $pagoIdOrden = (string)($d['data']['transactions']['payments'][0]['id'] ?? $pagoId);
                mp_aplicar_pago_orden(db(), $referencia, $montoOrden, 'order-' . $pagoId);
            }
            responder(['ok' => true]);
        }

        // Pagos normales (link/QR)
        if (preg_match('/^\d{5,20}$/', $pagoId) !== 1) {
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

    case 'diagnostico': {
        // Prueba en vivo de todo lo que Mercado Pago necesita para funcionar
        exigir_admin();
        $cfg = mp_config();
        $diag = [
            'habilitado'    => $cfg['enabled'] ? 1 : 0,
            'token_definido'=> $cfg['token'] !== '' ? 1 : 0,
            'token_mask'    => $cfg['token'] !== '' ? (substr($cfg['token'], 0, 8) . '…••••' . substr($cfg['token'], -4)) : '',
            'curl'          => function_exists('curl_init') ? 1 : 0,
            'https'         => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 1 : 0,
            'webhook'       => mp_webhook_url(),
        ];
        if ($cfg['token'] !== '' && $diag['curl'] === 1) {
            [$codigoHttp, $yo] = mp_api('GET', '/users/me', null, $cfg['token']);
            $diag['mp_http']   = $codigoHttp;
            $diag['mp_cuenta'] = (string)($yo['nickname'] ?? ($yo['email'] ?? ''));
            if ($codigoHttp === 200) {
                $diag['token_valido'] = 1;
            } elseif ($codigoHttp === 401) {
                $diag['token_valido'] = 0;
                $diag['mp_error'] = 'Token inválido o expirado';
            } elseif ($codigoHttp === 0) {
                $diag['token_valido'] = 0;
                $diag['mp_error'] = 'Sin salida a internet: ' . ($yo['curl_error'] ?? 'desconocido');
            } else {
                $diag['token_valido'] = 0;
                $diag['mp_error'] = 'HTTP ' . $codigoHttp;
            }

            [$codigoHttp, $datos] = mp_api('GET', '/terminals/v1/list?limit=50&offset=0', null, $cfg['token']);
            $diag['dispositivos_http'] = $codigoHttp;
            $diag['dispositivos_body'] = ($codigoHttp !== 200)
                ? substr(json_encode($datos, JSON_UNESCAPED_UNICODE), 0, 250) : '';
            $lista = [];
            $crudos = ($datos['data']['terminals'] ?? []);
            foreach ((is_array($crudos) ? $crudos : []) as $t) {
                if (is_array($t) && !empty($t['id'])) {
                    $lista[] = [
                        'id'    => (string)$t['id'],
                        'modo'  => (string)($t['operating_mode'] ?? 'UNDEFINED'),
                        'pos'   => (string)($t['pos_id'] ?? ''),
                    ];
                }
            }
            $diag['dispositivos'] = $lista;
            if ($codigoHttp === 0) { $diag['dispositivos_error'] = (string)($datos['curl_error'] ?? ''); }
        }
        responder(['ok' => true, 'diagnostico' => $diag]);
    }

    case 'verificar': {
        // El panel consulta cada pocos segundos: si el pago ya entró (por el
        // webhook o buscando directamente en Mercado Pago), responde pagada.
        exigir_admin();
        $codigo = strtoupper(trim((string)($_GET['codigo'] ?? '')));
        if (preg_match('/^LUH-\d{3,8}$/', $codigo) !== 1) {
            responder(['ok' => false, 'error' => 'Código inválido'], 400);
        }
        $orden = db()->prepare('SELECT total, abono, estado_pago FROM ordenes WHERE codigo = ? LIMIT 1');
        $orden->execute([$codigo]);
        $o = $orden->fetch();
        if (!$o) {
            responder(['ok' => false, 'error' => 'Orden no encontrada'], 404);
        }
        $total = (int)$o['total'];
        $abono = (int)$o['abono'];
        $pagada = ($total > 0 && $abono >= $total);

        $cfg = mp_config();
        if (!$pagada && $cfg['enabled'] && $cfg['token'] !== '') {
            // Busca pagos aprobados de esta orden que el webhook no haya aplicado
            [$codigoHttp, $busqueda] = mp_api('GET',
                '/v1/payments/search?sort=date_created&criteria=external_reference&external_reference=' . rawurlencode($codigo),
                null, $cfg['token']);
            foreach (($busqueda['results'] ?? []) as $pago) {
                if (($pago['status'] ?? '') === 'approved' && (int)round((float)($pago['transaction_amount'] ?? 0)) > 0) {
                    mp_aplicar_pago_orden(db(), $codigo, (int)round((float)$pago['transaction_amount']), (string)$pago['id']);
                }
            }
            $orden->execute([$codigo]);
            $o = $orden->fetch();
            $total = (int)$o['total'];
            $abono = (int)$o['abono'];
            $pagada = ($total > 0 && $abono >= $total);
        }
        responder(['ok' => true, 'pagada' => $pagada, 'total' => $total,
                   'abono' => $abono, 'estado_pago' => (string)($o['estado_pago'] ?? '')]);
    }

    default:
        responder(['ok' => false, 'error' => 'Acción desconocida'], 400);
}