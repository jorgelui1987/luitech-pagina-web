<?php
/**
 * LUITECH — planillas.php
 * Utilidades para importar listados de precios de proveedores:
 *  - Conversión de un link de Google Sheets a su exportación CSV directa.
 *  - Descarga del CSV (cURL con respaldo file_get_contents).
 *  - Parser del formato de grilla del proveedor de pantallas: 2-3 paneles por
 *    fila (item, precio, item, precio…), secciones como subtítulos y
 *    variantes de color entre paréntesis ("5S/5SE BLANCO ($4.000)",
 *    "NEGRO ($8.000)", "7G ORIG(NEGRO $10.000)").
 * Biblioteca pura: sin BD ni efectos secundarios (testeable por separado).
 */

declare(strict_types=1);

/** Colores que el proveedor usa como sufijo del modelo. */
const PLANILLA_COLORES = ['NEGRO', 'NEGRA', 'BLANCO', 'BLANCA', 'BL', 'NG', 'GRIS CLARO', 'GRIS OSCURO', 'GRIS', 'AZUL', 'ROJO', 'DORADO', 'ORO', 'PLATA', 'ROSA', 'ROSADO', 'VERDE', 'CELESTE', 'MORADO', 'AMARILLO'];

/** Extrae el número de un texto de precio: "$27,000" -> 27000, "$10.000" -> 10000. */
function precio_desde_texto(string $t): int
{
    $solo = preg_replace('/[^0-9]/', '', $t);
    return ($solo === '') ? 0 : (int)$solo;
}

/** ¿La celda es SOLO un precio? "$27,000" | "25.000" | "$1,000". */
function es_precio_suelto(string $t): bool
{
    $t = trim($t);
    if ($t === '') return false;
    if ($t[0] === '$') return (bool)preg_match('/^\$\s*\d[\d.,]*\)?$/', $t);
    return (bool)preg_match('/^\(?\s*\d{1,3}(?:[.,]\d{3})+\s*\)?$/', $t);
}

/** ¿El texto trae un precio dentro de paréntesis? "T500/T505(BL $24.000)". */
function tiene_precio_interno(string $t): bool
{
    return (bool)preg_match('/\([^()]*\d[\d.,]{2,}[^()]*\)/', $t);
}

/** Quita la palabra de color final: "5S/5SE BLANCO" -> "5S/5SE". */
function quitar_color_final(string $t): string
{
    $t = trim(preg_replace('/\s+/', ' ', $t) ?? $t);
    foreach ([2, 1] as $n) { // "GRIS CLARO" son dos palabras
        $palabras = explode(' ', $t);
        if (count($palabras) < $n) continue;
        $cola = implode(' ', array_slice($palabras, -$n));
        if (in_array($cola, PLANILLA_COLORES, true)) {
            return trim(implode(' ', array_slice($palabras, 0, -$n)));
        }
    }
    return $t;
}

/**
 * Divide una celda con precio entre paréntesis en [base, variantes].
 *   "T500/T505(BL $24.000)"       -> ["T500/T505", [["BL", 24000]]]
 *   "7G ORIG(NEGRO $10.000)"      -> ["7G ORIG", [["NEGRO", 10000]]]
 *   "5S/5SE BLANCO ($4.000)"      -> ["5S/5SE BLANCO", [["", 4000]]]
 *   "IPAD AIR 3 (BLANCA $85.000)" -> ["IPAD AIR 3", [["BLANCA", 85000]]]
 *   "MACBOOK"                     -> ["MACBOOK", []]
 */
function dividir_celda_con_precio(string $t): array
{
    $t = trim($t);
    if (!preg_match('/\(([^()]*)\)/', $t, $m, PREG_OFFSET_CAPTURE)) return [$t, []];
    $contenido = trim((string)$m[1][0]);
    if (!preg_match('/\d[\d.,]{2,}/', $contenido, $mp)) return [$t, []];
    $base = rtrim(trim(substr($t, 0, (int)$m[1][1])), " \t(");
    $etiqueta = trim(preg_replace('/\$?\s*\d[\d.,]{2,}/', '', $contenido) ?? '');
    return [$base, [['etiqueta' => $etiqueta, 'precio' => precio_desde_texto($mp[0])]]];
}

/** Convierte la sección del listado en el campo "pieza" del catálogo. */
function seccion_a_pieza(string $seccion): string
{
    $s = strtoupper(trim($seccion));
    if ($s === '' || $s === 'PANTALLAS' || $s === 'PANTALLA') return 'Pantalla';
    return mb_substr('Pantalla ' . $s, 0, 60);
}

/** Pieza del panel donde está la columna del item (última sección a la izquierda). */
function pieza_para_columna(array $seccionesCol, int $col): string
{
    $mejor = -1;
    $pieza = 'Pantalla';
    foreach ($seccionesCol as $c => $pz) {
        if ((int)$c <= $col && (int)$c > $mejor) { $mejor = (int)$c; $pieza = (string)$pz; }
    }
    return $pieza;
}

/**
 * Parser del listado de Google Sheets del proveedor de pantallas.
 * Grilla con 2-3 paneles por fila, secciones como subtítulos y variantes de
 * color entre paréntesis. Devuelve items [{modelo, pieza, precio}] sin
 * duplicados (el último gana).
 */
function parsear_planilla_google(string $csv): array
{
    $items = [];
    $guardar = function (string $modelo, string $pieza, int $precio) use (&$items) {
        $modelo = mb_substr(trim($modelo), 0, 80);
        $pieza  = mb_substr(trim($pieza), 0, 60);
        if ($modelo === '' || $pieza === '' || $precio < 1) return;
        $items[mb_strtolower($modelo . '|' . $pieza)] = ['modelo' => $modelo, 'pieza' => $pieza, 'precio' => $precio];
    };

    $seccionesCol = []; // columna => pieza del panel (de las filas de encabezados)
    $lineas = preg_split('/\r\n|\r|\n/', $csv) ?: [];
    foreach ($lineas as $linea) {
        if (trim($linea) === '') continue;
        $celdas = str_getcsv($linea);
        if (!is_array($celdas)) continue;

        // Fila de encabezados: ninguna celda trae precio (suelto ni interno)
        $hayPrecio = false;
        foreach ($celdas as $c) {
            if (es_precio_suelto((string)$c) || tiene_precio_interno((string)$c)) { $hayPrecio = true; break; }
        }
        if (!$hayPrecio) {
            foreach ($celdas as $i => $c) {
                $c = trim((string)$c);
                if (mb_strlen($c) >= 3) $seccionesCol[$i] = seccion_a_pieza($c);
            }
            continue;
        }

        // Fila de items: recorrer la grilla celda por celda
        $pendiente  = null; // [texto, columna] esperando su precio
        $baseLimpia = '';   // "5S/5SE" para la celda siguiente "NEGRO ($8.000)"
        $n = count($celdas);
        for ($i = 0; $i < $n; $i++) {
            $c = trim((string)$celdas[$i]);
            if ($c === '') continue;

            if (es_precio_suelto($c)) {
                if ($pendiente !== null) {
                    $guardar($pendiente[0], pieza_para_columna($seccionesCol, (int)$pendiente[1]), precio_desde_texto($c));
                    $pendiente = null;
                }
                continue;
            }

            [$base, $variantes] = dividir_celda_con_precio($c);
            if (count($variantes) > 0) {
                foreach ($variantes as $v) {
                    $nombre = $base;
                    if ($v['etiqueta'] !== '') {
                        $nombre = trim((($base !== '') ? $base : $baseLimpia) . ' ' . $v['etiqueta']);
                    } elseif ($base !== '' && $baseLimpia !== '' && in_array($base, PLANILLA_COLORES, true)) {
                        $nombre = $baseLimpia . ' ' . $base; // "NEGRO ($8.000)" tras "5S/5SE BLANCO ($4.000)"
                    }
                    $guardar($nombre, pieza_para_columna($seccionesCol, $i), $v['precio']);
                }
                if ($base !== '' && $variantes[0]['etiqueta'] !== '') {
                    $pendiente = [$base, $i]; // el precio de la grilla completa al base
                }
                if ($base !== '' && $variantes[0]['etiqueta'] === '') {
                    $baseLimpia = quitar_color_final($base); // el precio entre paréntesis ya era el del base
                }
                continue;
            }

            // Celda de solo texto: ¿item pendiente o encabezado?
            if ($pendiente !== null) {
                // texto tras un item sin precio entre medio = encabezado en otra columna
                $seccionesCol[$i] = seccion_a_pieza($c);
                continue;
            }
            $siguiente = '';
            for ($j = $i + 1; $j < $n; $j++) {
                $c2 = trim((string)$celdas[$j]);
                if ($c2 !== '') { $siguiente = $c2; break; }
            }
            if ($siguiente !== '' && es_precio_suelto($siguiente)) {
                $pendiente = [$c, $i]; // su precio viene más adelante en la fila
            } else {
                $seccionesCol[$i] = seccion_a_pieza($c); // encabezado (sin precio después)
            }
        }
    }
    return array_values($items);
}

/** Convierte un link de Google Sheets a su exportación CSV directa. */
function url_a_csv_google(string $url): ?string
{
    if (!preg_match('#docs\.google\.com/spreadsheets/d/([a-zA-Z0-9_-]+)#i', $url, $m)) return null;
    $gid = '0';
    if (preg_match('/[?#&]gid=(\d+)/', $url, $mg)) $gid = $mg[1];
    return 'https://docs.google.com/spreadsheets/d/' . $m[1] . '/export?format=csv&gid=' . $gid;
}

/** Descarga un texto/csv remoto (cURL con respaldo file_get_contents). */
function descargar_texto_url(string $url): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_USERAGENT => 'Luitech-PWA/1.0 (+importador de listados)',
        ]);
        $txt = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (is_string($txt) && $code >= 200 && $code < 300) return $txt;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 25, 'follow_location' => 1, 'max_redirects' => 5, 'header' => "User-Agent: Luitech-PWA/1.0\r\n"]]);
    $txt = @file_get_contents($url, false, $ctx);
    return is_string($txt) ? $txt : '';
}