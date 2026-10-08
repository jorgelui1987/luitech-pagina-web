<?php

namespace App\Http\Controllers;

use App\Models\Reparacion;
use App\Models\Tenant;
use App\Models\Configuracion;
use App\Models\Cliente;
use App\Models\User;
use Illuminate\Http\Request;

class PublicReparacionController extends Controller
{
    /**
     * Portal de búsqueda (Consulta Express) con tienda opcional: /estado/{slug?}
     * Con slug muestra el portal rotulado de ESA tienda; sin slug, el genérico.
     * El formulario conserva el slug para que la búsqueda quede aislada.
     */
    public function portal(?string $slug = null)
    {
        $tienda = null;
        if ($slug !== null) {
            $tenantId = $this->resolverTenantPorSlug($slug);
            $tienda = Configuracion::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
        }

        return view('public.estado-search', [
            'slugTienda' => $slug,
            'tiendaPortal' => $tienda,
        ]);
    }

    /**
     * Consulta aislada por tienda: /r/{slug}/{numero_orden} (QR nuevo de la boleta).
     * El slug manda: si la orden no es de ESA tienda responde "no encontrada"
     * sin revelar su existencia. Nunca consulta sin empresa.
     */
    public function statusPorTienda(string $slug, string $numero_orden)
    {
        $tenantId = $this->resolverTenantPorSlug($slug);

        $valorOriginal = strtoupper(trim($numero_orden));
        $candidatos = array_values(array_unique([
            $this->normalizarNumeroOrden($valorOriginal),
            $valorOriginal,
        ]));

        $reparacion = Reparacion::withoutGlobalScopes()
            ->whereIn('numero_orden', $candidatos)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$reparacion) {
            return view('public.estado-search', [
                'error'   => 'La orden ' . $candidatos[0] . ' no fue encontrada en esta tienda. Verifica el código de tu boleta e intenta nuevamente.',
                'buscado' => $candidatos[0],
                'slugTienda' => $slug,
            ]);
        }

        return $this->mostrarEstado($reparacion);
    }

    /**
     * Vista pública para que el cliente escanee el QR
     * y vea el estado de su reparación, condiciones y garantía.
     * Sin código de orden muestra el portal de búsqueda (Consulta Express).
     */
    public function status($numero_orden = null)
    {
        // Si viene por query string (búsqueda desde el portal público)
        if (!$numero_orden && request()->filled('numero_orden')) {
            $numero_orden = request('numero_orden');
        }

        // Sin código: mostrar el portal de búsqueda en lugar de un 404.
        // Si el formulario trae slugTienda, se conserva para aislar la búsqueda.
        if (!$numero_orden) {
            return $this->portal(request('slugTienda'));
        }

        // Normalizar y buscar (acepta "1024", "RPT-001024", "rpt001024", etc.)
        $valorOriginal = strtoupper(trim($numero_orden));
        $candidatos = array_values(array_unique([
            $this->normalizarNumeroOrden($valorOriginal),
            $valorOriginal,
        ]));

        $reparacion = Reparacion::withoutGlobalScopes()
            ->whereIn('numero_orden', $candidatos)
            ->first();

        // No encontrada: portal de búsqueda con mensaje amigable
        if (!$reparacion) {
            // Si el portal trae tienda (búsqueda aislada), se valida contra ella
            $slugBusqueda = request('slugTienda');
            if (is_string($slugBusqueda) && $slugBusqueda !== '') {
                try {
                    $this->resolverTenantPorSlug($slugBusqueda);
                } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
                    $slugBusqueda = null;
                }
            } else {
                $slugBusqueda = null;
            }

            // Búsqueda aislada por tienda: la orden debe ser de ESA tienda
            if ($slugBusqueda !== null) {
                return view('public.estado-search', [
                    'error'   => 'La orden ' . $candidatos[0] . ' no fue encontrada en esta tienda. Verifica el código de tu boleta e intenta nuevamente.',
                    'buscado' => request('numero_orden', $candidatos[0]),
                    'slugTienda' => $slugBusqueda,
                ]);
            }

            return view('public.estado-search', [
                'error'   => 'La orden ' . $candidatos[0] . ' no fue encontrada. Verifica el código de tu boleta e intenta nuevamente.',
                'buscado' => request('numero_orden', $candidatos[0]),
            ]);
        }

        // Aislamiento entre empresas: si la consulta se hace desde el portal de
        // una empresa concreta (slug del formulario, subdominio de la tienda o
        // sesión de su personal), solo se pueden ver órdenes de ESA empresa.
        // Si la orden pertenece a otra empresa, se informa "no encontrada"
        // (sin revelar su existencia).
        $slugBusqueda = request('slugTienda');
        $tenantPortal = null;
        if (is_string($slugBusqueda) && $slugBusqueda !== '') {
            try {
                $tenantPortal = $this->resolverTenantPorSlug($slugBusqueda);
            } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
                $tenantPortal = $this->resolverTenantPortal();
            }
        } else {
            $tenantPortal = $this->resolverTenantPortal();
        }
        if ($tenantPortal !== null && (int) $reparacion->tenant_id !== $tenantPortal) {
            return view('public.estado-search', [
                'error'   => 'La orden ' . $candidatos[0] . ' no fue encontrada. Verifica el código de tu boleta e intenta nuevamente.',
                'buscado' => request('numero_orden', $candidatos[0]),
            ]);
        }

        return $this->mostrarEstado($reparacion);
    }

    /**
     * Render compartido del estado de una orden ya localizada y autorizada.
     */
    private function mostrarEstado(Reparacion $reparacion)
    {
        // Cargar relaciones SIN TenantScope para evitar que el scope
        // filtre por el tenant del usuario autenticado (que puede ser diferente
        // al tenant de la reparación cuando se accede desde el QR público)
        $reparacion->setRelation('cliente', Cliente::withoutGlobalScopes()->find($reparacion->cliente_id));
        $reparacion->setRelation('tecnico', User::withoutGlobalScopes()->find($reparacion->tecnico_id));

        // Obtener configuración del tenant SIN TenantScope
        $empresa = Configuracion::withoutGlobalScopes()
            ->where('tenant_id', $reparacion->tenant_id)
            ->first();

        // Si no hay configuración, crear un objeto con valores por defecto
        if (!$empresa) {
            $empresa = (object) [
                'nombre_tienda'     => 'CRM Celulares',
                'ruc'               => '',
                'direccion'         => '',
                'telefono'          => '',
                'email'             => '',
                'logo'              => null,
                'terminos_garantia' => '',
            ];
        }

        // Colores de marca del taller dueño de la orden (para la vista pública)
        $coloresMarca = \App\Models\Tenant::find($reparacion->tenant_id)?->colores();

        return view('reparaciones.public-status', compact('reparacion', 'empresa', 'coloresMarca'));
    }

    /**
     * Normaliza el código de orden ingresado: "1024" → "RPT-001024".
     */
    /**
     * Normaliza el código de orden ingresado, aceptando el formato nuevo
     * con sufijo anti-adivinanza y el antiguo sin sufijo:
     * "1024" → "RPT-001024" · "1024-X7K4" → "RPT-001024-X7K4"
     * "rpt001024x7k4" → "RPT-001024-X7K4"
     */
    private function normalizarNumeroOrden(string $valor): string
    {
        $limpio = strtoupper(preg_replace('/[^A-Z0-9]/', '', $valor) ?? '');
        $limpio = preg_replace('/^RPT/', '', $limpio);

        if (!preg_match('/^(\d{1,6})([A-Z0-9]*)$/', $limpio, $m)) {
            return strtoupper(trim($valor));
        }

        $base   = str_pad($m[1], 6, '0', STR_PAD_LEFT);
        $sufijo = $m[2] ?? '';

        return $sufijo !== ''
            ? "RPT-{$base}-{$sufijo}"
            : 'RPT-' . $base;
    }

    /**
     * Modo Sala de Espera (TV): pantalla completa con los turnos del taller.
     * SIEMPRE exige slug (/pantalla/mitienda) y muestra SOLO esa empresa.
     * La ruta sin slug responde 404 (ver routes/web.php): nunca se "adivina"
     * empresa por ?tienda=, sesión o actividad reciente.
     */
    public function pantalla(Request $request, string $slug)
    {
        $consejos = [
            ['titulo' => 'Cuida tu batería', 'desc' => 'Evita que tu celular se descargue por debajo del 20% o se cargue por encima del 80% de forma habitual: extenderás la vida útil de tu batería.'],
            ['titulo' => 'La limpieza salva vidas', 'desc' => 'Los notebooks acumulan polvo en sus ventiladores. Un mantenimiento térmico cada 12 meses evita fallas graves en procesador y gráfica.'],
            ['titulo' => 'Respalda tus archivos', 'desc' => 'Ningún disco duro es eterno. Mantén una copia de seguridad de tus fotos y documentos en la nube o en un disco externo.'],
            ['titulo' => 'Pantallas protegidas', 'desc' => 'El vidrio templado o hidrogel absorbe gran parte del impacto en caídas directas. Pregunta por el tuyo en el mesón.'],
            ['titulo' => 'Cargadores certificados', 'desc' => 'Los cargadores de baja calidad entregan voltajes inestables que dañan el puerto de carga y la placa de tu equipo.'],
            ['titulo' => 'Humedad: actúa rápido', 'desc' => 'Si tu equipo se moja, apágalo de inmediato y no intentes cargarlo. Tráelo cuanto antes: el tiempo es clave para salvar la placa.'],
        ];

        $tenantId = $this->resolverTenantPorSlug($slug);

        // Promociones del taller (Configuración → Promociones para la pantalla TV):
        // se muestran primero en la rotación de la sala de espera.
        if ($tenantId) {
            $promos = \App\Models\Tenant::find($tenantId)?->configuracion_extra['promos'] ?? [];
            foreach ($promos as $promo) {
                if (!empty($promo['titulo'])) {
                    array_unshift($consejos, [
                        'titulo' => $promo['titulo'],
                        'desc'   => $promo['texto'] ?? '',
                    ]);
                }
            }
        }

        $empresa = $tenantId
            ? Configuracion::withoutGlobalScopes()->where('tenant_id', $tenantId)->first()
            : null;

        return view('public.pantalla', [
            'consejos'        => $consejos,
            'empresaPantalla' => $empresa,
            'slugPantalla'    => $slug,
        ]);
    }

    /**
     * Consulta privada de la sala ("Ver mi turno"): con el código COMPLETO de la
     * boleta devuelve SOLO esa orden (equipo genérico + estado + avance).
     * Sin sufijo anti-adivinanza responde "no encontrada": nadie puede barrer
     * órdenes ajenas desde la TV. Mismo aislamiento por empresa que status().
     */
    public function miTurno(Request $request, string $slug)
    {
        $valor = strtoupper(trim((string) ($request->query('codigo', ''))));
        if ($valor === '') {
            return response()->json(['ok' => false, 'error' => 'Ingresa tu código completo de la boleta.'], 422);
        }
        $normalizado = $this->normalizarNumeroOrden($valor);
        // Exigir sufijo anti-adivinanza para órdenes nuevas (formato RPT-000003-A2B4)
        $tieneSufijo = (bool) preg_match('/^RPT-\d{6}-[A-Z0-9]{4}$/', $normalizado);
        $esAntigua = (bool) preg_match('/^RPT-\d{6}$/', $normalizado);

        $reparacion = Reparacion::withoutGlobalScopes()
            ->where('numero_orden', $normalizado)
            ->first();

        // Orden nueva sin sufijo: no revelar nada (como el test de status())
        if (!$tieneSufijo && !$esAntigua) {
            return response()->json(['ok' => false, 'error' => 'No se encontró ese código. Revisa tu boleta.'], 404);
        }
        // Base sin sufijo no expone orden con sufijo
        if ($esAntigua && $reparacion === null) {
            // Buscar si existe la orden con sufijo para esa base: no revelar
            $base = substr($normalizado, 4);
            $existeConSufijo = Reparacion::withoutGlobalScopes()
                ->where('numero_orden', 'like', 'RPT-' . $base . '-%')
                ->exists();
            if ($existeConSufijo) {
                return response()->json(['ok' => false, 'error' => 'No se encontró ese código. Revisa tu boleta.'], 404);
            }
        }
        if (!$reparacion) {
            return response()->json(['ok' => false, 'error' => 'No se encontró ese código. Revisa tu boleta.'], 404);
        }

        // Aislamiento por empresa: el slug de la pantalla manda (sin revelar
        // existencia). OJO: resolverTenantPorSlug hace abort(404) HTML si el
        // slug no existe; aquí debe ser JSON para no romper el fetch de la TV.
        try {
            $tenantEsperado = $this->resolverTenantPorSlug($slug);
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            return response()->json(['ok' => false, 'error' => 'No se encontró ese código. Revisa tu boleta.'], 404);
        }
        if ($tenantEsperado !== null && (int) $reparacion->tenant_id !== $tenantEsperado) {
            return response()->json(['ok' => false, 'error' => 'No se encontró ese código. Revisa tu boleta.'], 404);
        }

        $labels = [
            'recibido' => 'Recibido', 'en_diagnostico' => 'En diagnóstico',
            'esperando_repuesto' => 'Esperando repuesto', 'en_reparacion' => 'En reparación',
            'listo' => 'Listo para retiro', 'entregado' => 'Entregado',
            'no_reparable' => 'No reparable',
        ];
        $avances = [
            'recibido' => 10, 'en_diagnostico' => 30, 'esperando_repuesto' => 45,
            'en_reparacion' => 65, 'listo' => 85, 'entregado' => 100, 'no_reparable' => 65,
        ];
        $tiposGenericos = [
            'celular' => 'Celular', 'tablet' => 'Tablet',
            'portatil' => 'Portátil', 'notebook' => 'Portátil', 'otros' => 'Equipo',
        ];
        $tipoGenerico = $tiposGenericos[strtolower((string) ($reparacion->tipo_dispositivo ?? ''))]
            ?? ($reparacion->dispositivo ? 'Equipo' : 'Equipo en servicio');

        return response()->json([
            'ok' => true,
            'orden' => [
                'codigo' => $reparacion->numero_orden,
                'equipo' => $tipoGenerico,
                'estado' => $labels[$reparacion->estado] ?? ucfirst((string) $reparacion->estado),
                'avance' => $avances[$reparacion->estado] ?? 50,
            ],
        ]);
    }

    /**
     * Enmascara un código para la pantalla compartida: RPT-001024-A2B4 → ···-A2B4.
     * Sin el código completo nadie puede consultar la orden ajena en /r/{codigo}.
     */
    public static function enmascararCodigo(string $codigo): string
    {
        $codigo = strtoupper(trim($codigo));
        $partes = explode('-', $codigo);
        $sufijo = end($partes);
        if (is_string($sufijo) && preg_match('/^[A-Z0-9]{4}$/', $sufijo) && count($partes) >= 3) {
            return '···-' . $sufijo;
        }

        return '···-' . substr($codigo, -4);
    }

    /**
     * Datos en vivo del modo TV (consultado por la pantalla cada 15 s).
     * SIEMPRE exige slug (/pantalla/data/mitienda): solo esa empresa.
     * Sin slug la ruta ni siquiera existe (404 en routes/web.php).
     */
    public function pantallaData(Request $request, string $slug)
    {
        $tenantId = $this->resolverTenantPorSlug($slug);

        $estadosActivos = ['recibido', 'en_diagnostico', 'esperando_repuesto', 'en_reparacion', 'listo'];

        $ordenes = Reparacion::withoutGlobalScopes()
            ->whereIn('estado', $estadosActivos)
            ->where('tenant_id', $tenantId)
            ->orderByDesc('updated_at')
            ->limit(60)
            ->get();

        $avances = [
            'recibido' => 10, 'en_diagnostico' => 30, 'esperando_repuesto' => 45,
            'en_reparacion' => 65, 'listo' => 85,
        ];
        $labels = [
            'recibido' => 'Recibido', 'en_diagnostico' => 'En diagnóstico',
            'esperando_repuesto' => 'Esperando repuesto', 'en_reparacion' => 'En reparación',
        ];

        $listos = [];
        $proceso = [];

        foreach ($ordenes as $r) {
            // Privacidad en sala (pantalla compartida sin login): NO se envía el
            // código completo (es la llave de la consulta privada /r/{codigo}) ni
            // el detalle del equipo (marca/modelo). Solo viaja código enmascarado
            // (···-A2B4) + tipo genérico + estado, para que cada cliente reconozca
            // su turno sin ver datos ajenos ni poder consultar órdenes de otros.
            $mask = self::enmascararCodigo((string) ($r->numero_orden ?? ''));
            $tiposGenericos = [
                'celular' => 'Celular', 'tablet' => 'Tablet',
                'portatil' => 'Portátil', 'notebook' => 'Portátil',
                'otros' => 'Equipo',
            ];
            $tipoGenerico = $tiposGenericos[strtolower((string) ($r->tipo_dispositivo ?? ''))]
                ?? ($r->dispositivo ? 'Equipo' : 'Equipo en servicio');

            $item = [
                'codigo' => $mask, // compat: la vista muestra este campo
                'codigo_mask' => $mask,
                'equipo' => $tipoGenerico, // solo tipo genérico, sin marca/modelo
                'urgente' => false, // no se revela prioridad ajena en sala
                'avance' => $avances[$r->estado] ?? 50,
                'estado_key' => $r->estado,
            ];

            if ($r->estado === 'listo') {
                $item['desde'] = optional($r->updated_at)->format('H:i');
                $listos[] = $item;
            } else {
                $item['estado'] = $labels[$r->estado] ?? ucfirst($r->estado);
                $proceso[] = $item;
            }
        }

        $empresa = $tenantId
            ? Configuracion::withoutGlobalScopes()->where('tenant_id', $tenantId)->first()
            : null;

        return response()->json([
            'tienda' => [
                'nombre'    => $empresa->nombre_tienda ?? 'Luitech Servicio Técnico',
                'direccion' => $empresa->direccion ?? '',
                'telefono'  => $empresa->telefono ?? '',
            ],
            'listos'    => $listos,
            'proceso'   => $proceso,
            'counts'    => ['listos' => count($listos), 'proceso' => count($proceso)],
            'timestamp' => now()->format('H:i:s'),
        ]);
    }

    /**
     * @deprecated El ?tienda= se eliminó por enumerable y la sesión del técnico
     * no debe decidir qué ve la TV compartida. La pantalla exige slug y ya no
     * llama a este método; se conserva vacío para no romper firmas externas.
     */
    private function resolverTenantPantalla(Request $request): ?int
    {
        return null;
    }

    /**
     * Tenant del portal desde el que se hace una consulta pública:
     *  1) usuario autenticado (personal de una empresa) → su tenant;
     *  2) subdominio/dominio actual (Tenant::current()).
     * Devuelve null cuando no hay forma de identificar la empresa
     * (acceso por dominio principal), caso típico del QR de la boleta.
     */
    private function resolverTenantPortal(): ?int
    {
        if (auth()->check() && auth()->user()->tenant_id) {
            return (int) auth()->user()->tenant_id;
        }

        return Tenant::current()?->id;
    }

    /**
     * Resuelve el tenant por slug público de la tienda (/pantalla/mitienda).
     */
    private function resolverTenantPorSlug(string $slug): ?int
    {
        $tenant = Tenant::where('slug_publico', $slug)->first();

        if (!$tenant || $tenant->estado !== 'activo') {
            abort(404);
        }

        return (int) $tenant->id;
    }
}
