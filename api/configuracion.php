<?php
/**
 * LUITECH API - Configuraciones globales del sistema (solo administrador).
 * Acciones (?action=):
 *   get       GET                      -> { iva_porcentaje }               (compatibilidad POS)
 *   set       POST {iva_porcentaje}    -> guarda la tasa (0..100)          (compatibilidad POS)
 *   get_all   GET                      -> todas las claves (secretos enmascarados)
 *   set_many  POST {clave: valor, ...} -> guarda claves de la lista blanca
 *   set_logo  POST multipart 'logo'    -> valida imagen y la guarda en uploads/logo/
 *   del_logo  POST                     -> elimina el logo actual
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

iniciar_respuesta_json();
exigir_admin(); exigir_rol_admin();

preparar_configuraciones(); // garantiza la tabla aunque el hosting no ejecute migrate

$action = $_GET['action'] ?? '';

/** Lista blanca de claves editables y sus reglas de validación. */
const CONFIG_CLAVES = [
    'empresa_nombre'        => ['max' => 120],
    'empresa_rut'           => ['max' => 12,  'rut' => true],
    'empresa_giro'          => ['max' => 120],
    'empresa_direccion'     => ['max' => 160],
    'empresa_pais'          => ['max' => 60],
    'empresa_telefono'      => ['max' => 40],
    'empresa_email'         => ['max' => 120],
    'empresa_logo'          => ['max' => 255],
    'moneda'                => ['max' => 20],
    'moneda_simbolo'        => ['max' => 5],
    'zona_horaria'          => ['tz' => true],
    'garantia_dias_default' => ['int' => [0, 365]],
    'terminos_texto'        => ['max' => 500],
    'iva_porcentaje'        => ['int' => [0, 100]],
    'dte_habilitado'        => ['flag' => true],
    'dte_proveedor'         => ['max' => 60],
    'dte_api_key'           => ['secreto' => true],
    'mp_enabled'            => ['flag' => true],
    'mp_public_key'         => ['max' => 120],
    'mp_access_token'       => ['secreto' => true],
    'mp_point_device'       => ['max' => 80],
    'catalogo_margen'       => ['int' => [0, 500]],
    'catalogo_redondeo'     => ['int' => [0, 100000]],
];

const CONFIG_SECRETAS = ['dte_api_key', 'mp_access_token'];

/** Valida y normaliza el valor entrante de una clave. Devuelve [ok, valor|error]. */
function config_validar(string $clave, $valor): array
{
    $regla = CONFIG_CLAVES[$clave] ?? null;
    if ($regla === null) {
        return [false, 'Clave de configuración desconocida'];
    }
    $valor = trim((string)$valor);
    if (isset($regla['secreto'])) {
        return [true, $valor]; // vacío = mantener; 'BORRAR' = limpiar (resuelto en set_many)
    }
    if (isset($regla['flag'])) {
        return [true, (!empty($valor) && $valor !== '0') ? '1' : '0'];
    }
    if (isset($regla['int'])) {
        $n = (int)$valor;
        if ($n < $regla['int'][0] || $n > $regla['int'][1]) {
            return [false, "El valor de {$clave} debe estar entre {$regla['int'][0]} y {$regla['int'][1]}"];
        }
        return [true, (string)$n];
    }
    if (isset($regla['tz']) && $valor !== '') {
        if (!in_array($valor, DateTimeZone::listIdentifiers(), true)) {
            return [false, 'Zona horaria no válida (ej: America/Santiago)'];
        }
    }
    if (isset($regla['rut']) && $valor !== '') {
        if (!validar_rut_chileno($valor)) {
            return [false, 'RUT de la empresa inválido'];
        }
        $valor = strtoupper(preg_replace('/[^0-9kK]/', '', $valor) ?? '');
    }
    if (isset($regla['max']) && mb_strlen($valor) > $regla['max']) {
        return [false, "El valor de {$clave} es demasiado largo (máx. {$regla['max']})"];
    }
    return [true, $valor];
}

/** Enmascara un secreto para no devolverlo completo al navegador. */
function config_enmascarar(string $valor): string
{
    if ($valor === '') {
        return '';
    }
    $cola = strlen($valor) > 4 ? substr($valor, -4) : $valor;
    return '••••' . $cola;
}

switch ($action) {

    case 'get':
        responder(['ok' => true, 'iva_porcentaje' => (int)config_valor(db(), 'iva_porcentaje', '19')]);

    case 'get_all': {
        $salida = [];
        foreach (array_keys(CONFIG_CLAVES) as $clave) {
            $valor = config_valor(db(), $clave, '');
            if (in_array($clave, CONFIG_SECRETAS, true)) {
                $salida[$clave] = config_enmascarar($valor);
                $salida[$clave . '_definido'] = ($valor !== '') ? 1 : 0;
            } else {
                $salida[$clave] = $valor;
            }
        }
        if (!empty($_SERVER['HTTP_HOST'])) {
            $salida['webhook_mp'] = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                . '://' . $_SERVER['HTTP_HOST']
                . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/')
                . '/pagos_mp.php?action=webhook';
        } else {
            $salida['webhook_mp'] = ''; // CLI: no hay host deducible
        }
        $salida['db'] = DB_NAME . '@' . DB_HOST;
        responder(['ok' => true, 'config' => $salida]);
    }

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

    case 'set_many': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $d = leer_cuerpo();
        if (!is_array($d) || count($d) === 0) {
            responder(['ok' => false, 'error' => 'Nada que guardar'], 400);
        }
        $guardadas = 0;
        foreach ($d as $clave => $valor) {
            if (!isset(CONFIG_CLAVES[$clave]) || $clave === 'empresa_logo') {
                continue; // lista blanca: ignora claves desconocidas; el logo va por set_logo
            }
            [$ok, $resultado] = config_validar($clave, $valor);
            if (!$ok) {
                responder(['ok' => false, 'error' => $resultado], 400);
            }
            if (in_array($clave, CONFIG_SECRETAS, true)) {
                if ($resultado === '') {
                    continue; // vacío = mantener el valor actual
                }
                if (str_starts_with($resultado, '•')) {
                    continue; // llegó la máscara: no tocar
                }
                if (strtoupper($resultado) === 'BORRAR') {
                    $resultado = '';
                }
            }
            db()->prepare('INSERT INTO configuraciones (clave, valor) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE valor = VALUES(valor)')
                ->execute([$clave, $resultado]);
            $guardadas++;
        }
        responder(['ok' => true, 'guardadas' => $guardadas]);
    }

    case 'set_logo': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $f = $_FILES['logo'] ?? null;
        if (!is_array($f)) {
            responder(['ok' => false, 'error' => 'No se recibió ningún logo'], 400);
        }
        // Mensajes claros para cada código de error de subida de PHP
        $codigoSubida = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($codigoSubida !== UPLOAD_ERR_OK) {
            $mensajes = [
                UPLOAD_ERR_INI_SIZE   => 'El logo supera el límite del servidor (máx. ' . ini_get('upload_max_filesize') . ')',
                UPLOAD_ERR_FORM_SIZE  => 'El logo supera el límite del formulario',
                UPLOAD_ERR_PARTIAL    => 'La subida se interrumpió a mitad de camino, intenta de nuevo',
                UPLOAD_ERR_NO_FILE    => 'No se recibió ningún logo',
                UPLOAD_ERR_NO_TMP_DIR => 'Falta la carpeta temporal del servidor',
                UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir el archivo',
            ];
            responder(['ok' => false, 'error' => $mensajes[$codigoSubida] ?? ('Error de subida (código ' . $codigoSubida . ')')], 400);
        }
        if ($f['size'] <= 0 || $f['size'] > 5242880) {
            responder(['ok' => false, 'error' => 'El logo debe pesar entre 0 y 5 MB'], 400);
        }
        if (!is_uploaded_file($f['tmp_name'])) {
            responder(['ok' => false, 'error' => 'Subida inválida'], 400);
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $extPermitidas = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extPermitidas[$mime]) || @getimagesize($f['tmp_name']) === false) {
            responder(['ok' => false, 'error' => 'El archivo no es una imagen válida (tipo detectado: ' . ($mime ?: 'desconocido') . '). Formatos aceptados: JPG, PNG o WebP'], 400);
        }

        $baseReal = realpath(__DIR__ . '/../uploads');
        $dir = __DIR__ . '/../uploads/logo';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            responder(['ok' => false, 'error' => 'No se pudo crear la carpeta del logo'], 500);
        }

        // Eliminar el logo anterior (si existe y está dentro de uploads/)
        $anterior = config_valor(db(), 'empresa_logo', '');
        if ($anterior !== '' && $baseReal !== false) {
            $absAnterior = realpath(__DIR__ . '/../' . $anterior);
            if ($absAnterior !== false && str_starts_with($absAnterior, $baseReal . DIRECTORY_SEPARATOR)) {
                @unlink($absAnterior);
            }
        }

        // Nombre aleatorio e impredecible (mismo criterio que firmas y fotos)
        $nombre = 'logo-' . bin2hex(random_bytes(4)) . '.' . $extPermitidas[$mime];
        $relativa = 'uploads/logo/' . $nombre;
        if (!move_uploaded_file($f['tmp_name'], __DIR__ . '/../' . $relativa)) {
            responder(['ok' => false, 'error' => 'No se pudo guardar el logo'], 500);
        }
        db()->prepare('INSERT INTO configuraciones (clave, valor) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE valor = VALUES(valor)')
            ->execute(['empresa_logo', $relativa]);

        // Verificación inmediata en la MISMA conexión + identificación de la BD
        responder(['ok' => true, 'logo' => $relativa,
                   'verificado' => config_valor(db(), 'empresa_logo', ''),
                   'db' => DB_NAME . '@' . DB_HOST]);
    }

    case 'del_logo': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $anterior = config_valor(db(), 'empresa_logo', '');
        $baseReal = realpath(__DIR__ . '/../uploads');
        if ($anterior !== '' && $baseReal !== false) {
            $absAnterior = realpath(__DIR__ . '/../' . $anterior);
            if ($absAnterior !== false && str_starts_with($absAnterior, $baseReal . DIRECTORY_SEPARATOR)) {
                @unlink($absAnterior);
            }
        }
        db()->prepare("UPDATE configuraciones SET valor = '' WHERE clave = 'empresa_logo'")->execute();
        responder(['ok' => true]);
    }

    default:
        responder(['ok' => false, 'error' => 'Acción desconocida'], 400);
}
