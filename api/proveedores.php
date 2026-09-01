<?php
/**
 * LUITECH API - Proveedores y compras de mercadería (solo administrador).
 * Acciones (?action=):
 *   list             GET                -> proveedores activos + totales comprados
 *   create           POST {...}         -> crea proveedor
 *   update           POST {id,...}      -> actualiza
 *   delete           POST {id}          -> baja lógica (activo=0)
 *   registrar_compra POST {proveedor_id, producto_id, cantidad, costo_unitario,
 *                         descontar_caja?, nota?}
 *                                       -> suma stock, actualiza costo, egresa de caja
 *   compras          GET ?proveedor_id= -> historial de compras
 *   catalogo_importar    POST {proveedor_id, items[], marcar_no_disp?} -> importa listado pegado
 *   catalogo_sincronizar POST {proveedor_id, url?, marcar_no_disp?}  -> descarga la planilla
 *                        de Google Sheets guardada en el proveedor, la interpreta
 *                        (grilla con secciones y variantes) y la importa al catálogo
 */

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/planillas.php'; // parser de planillas de proveedores (Google Sheets)

iniciar_respuesta_json();
exigir_admin(); exigir_rol_admin();
preparar_proveedores(db()); // tablas auto-reparables (hostings sin migrate)

$action = $_GET['action'] ?? '';

/** Valida y normaliza los datos de un proveedor. */
function leer_proveedor(array $d): array
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
            responder(['ok' => false, 'error' => 'RUT del proveedor inválido'], 400);
        }
        $out['rut'] = ($rut === '') ? null : preg_replace('/[^0-9kK]/', '', $rut);
    }
    if (isset($d['telefono'])) {
        $out['telefono'] = trim(mb_substr((string)$d['telefono'], 0, 40)) ?: null;
    }
    if (isset($d['nota'])) {
        $out['notas'] = trim(mb_substr((string)$d['nota'], 0, 255)) ?: null;
    }
    if (isset($d['url_listado'])) {
        $url = trim((string)$d['url_listado']);
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            responder(['ok' => false, 'error' => 'La URL del listado debe empezar por http(s)://'], 400);
        }
        $out['url_listado'] = ($url === '') ? null : mb_substr($url, 0, 500);
    }
    return $out;
}

/** Núcleo de importación: upsert por proveedor+modelo+pieza dentro de una
 *  transacción. Devuelve [insertados, actualizados]. Lanza PDOException. */
function importar_items_catalogo(int $proveedorId, array $items, bool $marcarNoDisp): array
{
    db()->beginTransaction();
    try {
        if ($marcarNoDisp) {
            db()->prepare('UPDATE catalogo_proveedores SET disponible = 0 WHERE proveedor_id = ?')
                ->execute([$proveedorId]);
        }
        $stSel = db()->prepare('SELECT id FROM catalogo_proveedores WHERE proveedor_id = ? AND LOWER(modelo) = LOWER(?) AND LOWER(pieza) = LOWER(?) LIMIT 1');
        $stIns = db()->prepare('INSERT INTO catalogo_proveedores (proveedor_id, modelo, pieza, precio, disponible) VALUES (?, ?, ?, ?, 1)');
        $stUpd = db()->prepare('UPDATE catalogo_proveedores SET precio = ?, disponible = 1, actualizado_en = NOW() WHERE id = ?');
        $insertados = 0;
        $actualizados = 0;
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $modelo = mb_substr(trim((string)($it['modelo'] ?? '')), 0, 80);
            $pieza  = mb_substr(trim((string)($it['pieza'] ?? '')), 0, 60);
            $precio = max(0, (int)($it['precio'] ?? 0));
            if ($modelo === '' || $pieza === '' || $precio < 1) continue;
            $stSel->execute([$proveedorId, $modelo, $pieza]);
            $existe = $stSel->fetchColumn();
            if ($existe) {
                $stUpd->execute([$precio, (int)$existe]);
                $actualizados++;
            } else {
                $stIns->execute([$proveedorId, $modelo, $pieza, $precio]);
                $insertados++;
            }
        }
        db()->commit();
        return [$insertados, $actualizados];
    } catch (PDOException $e) {
        db()->rollBack();
        throw $e;
    }
}

switch ($action) {

    case 'list':
        $stmt = db()->query(
            'SELECT p.id, p.nombre, p.rut, p.telefono, p.notas, p.url_listado,
                    (SELECT COUNT(*) FROM entradas_stock c WHERE c.proveedor_id = p.id) AS compras_total,
                    (SELECT COALESCE(SUM(c.total),0) FROM entradas_stock c WHERE c.proveedor_id = p.id) AS monto_comprado,
                    (SELECT MAX(c.fecha) FROM entradas_stock c WHERE c.proveedor_id = p.id) AS ultima_compra
             FROM proveedores p
             WHERE p.activo = 1
             ORDER BY p.nombre'
        );
        responder(['ok' => true, 'proveedores' => $stmt->fetchAll()]);

    case 'create': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $d = leer_proveedor(leer_cuerpo());
        if (!isset($d['nombre'])) {
            responder(['ok' => false, 'error' => 'El nombre es obligatorio'], 400);
        }
        $columnas = implode(', ', array_keys($d));
        $marcas   = implode(', ', array_map(fn($k) => ":$k", array_keys($d)));
        try {
            db()->prepare("INSERT INTO proveedores ($columnas) VALUES ($marcas)")->execute($d);
            responder(['ok' => true, 'id' => (int)db()->lastInsertId()]);
        } catch (PDOException $e) {
            responder(['ok' => false, 'error' => 'No se pudo crear el proveedor'], 500);
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
        $datos = leer_proveedor($d);
        if (!$datos) {
            responder(['ok' => false, 'error' => 'Nada que actualizar'], 400);
        }
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($datos)));
        $params = $datos;
        $params[':id'] = $id;
        db()->prepare("UPDATE proveedores SET $set WHERE id = :id")->execute($params);
        responder(['ok' => true]);
    }

    case 'delete': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $id = (int)(leer_cuerpo()['id'] ?? 0);
        db()->prepare('UPDATE proveedores SET activo = 0 WHERE id = ?')->execute([$id]);
        responder(['ok' => true]);
    }

    case 'registrar_compra': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $d           = leer_cuerpo();
        $proveedorId = (int)($d['proveedor_id'] ?? 0);
        $productoId  = (int)($d['producto_id'] ?? 0);
        $cantidad    = max(1, (int)($d['cantidad'] ?? 0));
        $costoUnit   = max(0, (int)($d['costo_unitario'] ?? 0));
        $descontar   = !empty($d['descontar_caja']);
        if ($proveedorId <= 0 || $productoId <= 0) {
            responder(['ok' => false, 'error' => 'Proveedor y producto son obligatorios'], 400);
        }
        $stP = db()->prepare('SELECT nombre FROM proveedores WHERE id = ? AND activo = 1 LIMIT 1');
        $stP->execute([$proveedorId]);
        $provNombre = $stP->fetchColumn();
        if ($provNombre === false) {
            responder(['ok' => false, 'error' => 'El proveedor no existe'], 400);
        }
        $stPr = db()->prepare('SELECT nombre FROM productos WHERE id = ? LIMIT 1');
        $stPr->execute([$productoId]);
        $prodNombre = $stPr->fetchColumn();
        if ($prodNombre === false) {
            responder(['ok' => false, 'error' => 'El producto no existe'], 400);
        }
        $total = $cantidad * $costoUnit;

        db()->beginTransaction();
        try {
            db()->prepare('INSERT INTO entradas_stock (proveedor_id, producto_id, cantidad, costo_unitario, total, pagada_caja, nota) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$proveedorId, $productoId, $cantidad, $costoUnit, $total, $descontar ? 1 : 0,
                           campo_texto($d, 'nota', 255)]);
            db()->prepare('UPDATE productos SET stock = stock + ?, precio_costo = ? WHERE id = ?')
                ->execute([$cantidad, $costoUnit, $productoId]);
            $aviso = null;
            if ($descontar) {
                $sesion = db()->query("SELECT id FROM caja_sesiones WHERE estado = 'Abierta' ORDER BY id DESC LIMIT 1")->fetch();
                if ($sesion) {
                    db()->prepare('INSERT INTO movimientos_caja (sesion_id, tipo, concepto, monto) VALUES (?, ?, ?, ?)')
                        ->execute([(int)$sesion['id'], 'Egreso',
                                   'Compra a proveedor ' . $provNombre . ' — ' . $prodNombre . ' x' . $cantidad, $total]);
                } else {
                    $aviso = 'Compra registrada y stock sumado, pero no hay caja abierta: el egreso no se registró';
                }
            }
            db()->commit();
            responder(['ok' => true, 'total' => $total, 'aviso' => $aviso]);
        } catch (PDOException $e) {
            db()->rollBack();
            responder(['ok' => false, 'error' => 'No se pudo registrar la compra'], 500);
        }
    }

    case 'compras': {
        $proveedorId = (int)($_GET['proveedor_id'] ?? 0);
        $sql = 'SELECT c.id, c.cantidad, c.costo_unitario, c.total, c.pagada_caja, c.nota, c.fecha,
                       p.nombre AS proveedor_nombre, pd.nombre AS producto_nombre
                FROM entradas_stock c
                JOIN proveedores p ON p.id = c.proveedor_id
                JOIN productos pd ON pd.id = c.producto_id';
        $params = [];
        if ($proveedorId > 0) {
            $sql .= ' WHERE c.proveedor_id = ?';
            $params[] = $proveedorId;
        }
        $sql .= ' ORDER BY c.id DESC LIMIT 200';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $compras = $stmt->fetchAll();
        $total = 0;
        foreach ($compras as $c) {
            $total += (int)$c['total'];
        }
        responder(['ok' => true, 'compras' => $compras, 'total' => $total]);
    }

    case 'catalogo_list': {
        // Búsqueda por palabras: cada palabra escrita debe estar presente
        // (modelo, pieza o proveedor). Ej: "pantalla iphone 15" -> solo
        // pantallas del iPhone 15; "iphone 15" -> todas las piezas de ese modelo.
        $q = trim((string)($_GET['q'] ?? ''));
        $sql = 'SELECT c.id, c.modelo, c.pieza, c.precio, c.precio_venta, c.disponible, c.actualizado_en,
                       p.nombre AS proveedor_nombre
                FROM catalogo_proveedores c
                JOIN proveedores p ON p.id = c.proveedor_id';
        $params = [];
        $conds = [];
        foreach (preg_split('/\s+/', mb_strtolower($q)) as $palabra) {
            if ($palabra === '') continue;
            $conds[] = '(LOWER(c.modelo) LIKE ? OR LOWER(c.pieza) LIKE ? OR LOWER(p.nombre) LIKE ?)';
            $params[] = '%' . $palabra . '%';
            $params[] = '%' . $palabra . '%';
            $params[] = '%' . $palabra . '%';
        }
        if (count($conds) > 0) {
            $sql .= ' WHERE ' . implode(' AND ', $conds);
        }
        $sql .= ' ORDER BY c.disponible DESC, c.precio ASC, c.modelo ASC LIMIT 300';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        responder(['ok' => true, 'catalogo' => $stmt->fetchAll()]);
    }

    case 'catalogo_save': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $d        = leer_cuerpo();
        $id       = (int)($d['id'] ?? 0);
        $proveedorId = (int)($d['proveedor_id'] ?? 0);
        $modelo   = campo_texto($d, 'modelo', 80);
        $pieza    = campo_texto($d, 'pieza', 60);
        $precio   = max(0, (int)($d['precio'] ?? 0));
        $disponible = !empty($d['disponible']);
        $precioVenta = null;
        if (isset($d['precio_venta'])) {
            $pv = max(0, (int)$d['precio_venta']);
            $precioVenta = ($pv > 0) ? $pv : null; // 0/vacío = volver al automático
        }
        if ($proveedorId <= 0 || $modelo === null || $pieza === null || $precio < 1) {
            responder(['ok' => false, 'error' => 'Proveedor, modelo, pieza y precio son obligatorios'], 400);
        }
        $stP = db()->prepare('SELECT nombre FROM proveedores WHERE id = ? AND activo = 1 LIMIT 1');
        $stP->execute([$proveedorId]);
        if ($stP->fetchColumn() === false) {
            responder(['ok' => false, 'error' => 'El proveedor no existe'], 400);
        }
        if ($id > 0) {
            db()->prepare('UPDATE catalogo_proveedores SET proveedor_id = ?, modelo = ?, pieza = ?, precio = ?, precio_venta = ?, disponible = ?, actualizado_en = NOW() WHERE id = ?')
                ->execute([$proveedorId, $modelo, $pieza, $precio, $precioVenta, $disponible ? 1 : 0, $id]);
            responder(['ok' => true, 'id' => $id]);
        }
        db()->prepare('INSERT INTO catalogo_proveedores (proveedor_id, modelo, pieza, precio, precio_venta, disponible) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$proveedorId, $modelo, $pieza, $precio, $precioVenta, $disponible ? 1 : 0]);
        responder(['ok' => true, 'id' => (int)db()->lastInsertId()]);
    }

    case 'catalogo_importar': {
        // Importa un listado pegado por el dueño: items con {modelo, pieza, precio}.
        // Upsert por proveedor+modelo+pieza (actualiza precio de los existentes,
        // inserta los nuevos) y opcionalmente marca "No disponible" lo que no vino.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $d = leer_cuerpo();
        $proveedorId = (int)($d['proveedor_id'] ?? 0);
        $items = (isset($d['items']) && is_array($d['items'])) ? $d['items'] : [];
        $marcarNoDisp = !empty($d['marcar_no_disp']);
        if ($proveedorId <= 0 || count($items) === 0) {
            responder(['ok' => false, 'error' => 'Proveedor y lista de items son obligatorios'], 400);
        }
        $stP = db()->prepare('SELECT nombre FROM proveedores WHERE id = ? AND activo = 1 LIMIT 1');
        $stP->execute([$proveedorId]);
        if ($stP->fetchColumn() === false) {
            responder(['ok' => false, 'error' => 'El proveedor no existe'], 400);
        }
        try {
            [$insertados, $actualizados] = importar_items_catalogo($proveedorId, $items, $marcarNoDisp);
            responder(['ok' => true, 'insertados' => $insertados, 'actualizados' => $actualizados]);
        } catch (PDOException $e) {
            responder(['ok' => false, 'error' => 'No se pudo importar el listado'], 500);
        }
    }

    case 'catalogo_delete': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $id = (int)(leer_cuerpo()['id'] ?? 0);
        if ($id <= 0) {
            responder(['ok' => false, 'error' => 'ID inválido'], 400);
        }
        db()->prepare('DELETE FROM catalogo_proveedores WHERE id = ?')->execute([$id]);
        responder(['ok' => true]);
    }

    case 'catalogo_sincronizar': {
        // Descarga la planilla de Google Sheets del proveedor (URL guardada o
        // enviada ahora), la interpreta con el parser de grillas y la importa.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $d = leer_cuerpo();
        $proveedorId = (int)($d['proveedor_id'] ?? 0);
        $marcarNoDisp = !isset($d['marcar_no_disp']) ? true : !empty($d['marcar_no_disp']);
        if ($proveedorId <= 0) {
            responder(['ok' => false, 'error' => 'Proveedor obligatorio'], 400);
        }
        $stP = db()->prepare('SELECT nombre, url_listado FROM proveedores WHERE id = ? AND activo = 1 LIMIT 1');
        $stP->execute([$proveedorId]);
        $prov = $stP->fetch();
        if (!$prov) {
            responder(['ok' => false, 'error' => 'El proveedor no existe'], 400);
        }
        $url = trim((string)($d['url'] ?? ''));
        if ($url !== '') {
            if (!preg_match('#^https?://#i', $url)) {
                responder(['ok' => false, 'error' => 'La URL debe empezar por https://'], 400);
            }
            db()->prepare('UPDATE proveedores SET url_listado = ? WHERE id = ?')
                ->execute([mb_substr($url, 0, 500), $proveedorId]);
        } else {
            $url = trim((string)($prov['url_listado'] ?? ''));
        }
        if ($url === '') {
            responder(['ok' => false, 'error' => 'Este proveedor no tiene URL de planilla guardada. Pégala en el campo de URL y reintenta.'], 400);
        }
        $csvUrl = url_a_csv_google($url);
        if ($csvUrl === null) {
            responder(['ok' => false, 'error' => 'La URL no es de una planilla de Google Sheets'], 400);
        }
        $csv = descargar_texto_url($csvUrl);
        if (trim($csv) === '' || stripos($csv, '<!DOCTYPE') !== false || stripos($csv, '<html') !== false) {
            responder(['ok' => false, 'error' => 'No se pudo leer la planilla. Verifica que esté compartida como «Cualquiera con el link»'], 400);
        }
        $items = parsear_planilla_google($csv);
        if (count($items) === 0) {
            responder(['ok' => false, 'error' => 'La planilla no tiene items con precio que se puedan leer'], 400);
        }
        try {
            [$insertados, $actualizados] = importar_items_catalogo($proveedorId, $items, $marcarNoDisp);
            responder(['ok' => true, 'insertados' => $insertados, 'actualizados' => $actualizados, 'total' => count($items)]);
        } catch (PDOException $e) {
            responder(['ok' => false, 'error' => 'No se pudo guardar el listado'], 500);
        }
    }

    case 'catalogo_fijar_precio': {
        // Fija (o libera) el precio de venta propio de un item del catálogo.
        // precio_venta 0 = volver al precio sugerido automático.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responder(['ok' => false, 'error' => 'Método no permitido'], 405);
        }
        $d  = leer_cuerpo();
        $id = (int)($d['id'] ?? 0);
        if ($id <= 0) {
            responder(['ok' => false, 'error' => 'ID inválido'], 400);
        }
        $pv = max(0, (int)($d['precio_venta'] ?? 0));
        db()->prepare('UPDATE catalogo_proveedores SET precio_venta = ?, actualizado_en = NOW() WHERE id = ?')
            ->execute([$pv > 0 ? $pv : null, $id]);
        responder(['ok' => true]);
    }

    default:
        responder(['ok' => false, 'error' => 'Acción desconocida'], 400);
}