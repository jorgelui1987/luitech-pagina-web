<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\Configuracion;
use App\Models\Cupon;
use App\Models\Resena;
use App\Models\Reparacion;
use App\Models\RecordatorioRetiro;
use App\Models\Cliente;
use App\Helpers\WhatsAppHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ComboPublicidadController extends Controller
{
    /**
     * Página pública de la tienda (mini-web).
     */
    public function tiendaPublica(string $slug)
    {
        $tenant = Tenant::where('slug_publico', $slug)->where('estado', 'activo')->firstOrFail();

        $config = Configuracion::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->first();

        if (!$config || !$config->pagina_publica_activa) {
            abort(404);
        }

        // Reseñas públicas (solo publicadas y con calificación >= 4)
        $resenas = collect();
        $promedio = null;
        try {
            $resenas = Resena::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('publicada', true)
                ->orderByDesc('created_at')
                ->limit(10)
                ->get();

            $promedio = Resena::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('publicada', true)
                ->avg('calificacion');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('No se pudieron cargar reseñas: ' . $e->getMessage());
        }

        // Cupones activos (para mostrar en la página)
        $cupones = collect();
        try {
            $cupones = Cupon::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('estado', 'activo')
                ->where(function ($q) {
                    $q->whereNull('fecha_expiracion')->orWhere('fecha_expiracion', '>', now());
                })
                ->orderByDesc('created_at')
                ->limit(5)
                ->get();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('No se pudieron cargar cupones: ' . $e->getMessage());
        }

        // Logo URL
        $logoSrc = null;
        if ($config && $config->logo) {
            $logoPath = str_replace('storage/', '', $config->logo);
            $fullPath = storage_path('app/public/' . $logoPath);
            if (file_exists($fullPath)) {
                $logoSrc = route('storage.serve', ['path' => $logoPath]);
            }
        }

        return view('public.tienda', [
            'tenant' => $tenant, 'config' => $config, 'resenas' => $resenas,
            'promedio' => $promedio, 'cupones' => $cupones, 'logoSrc' => $logoSrc,
            'coloresMarca' => $tenant->colores(),
        ]);
    }

    /**
     * Formulario público para dejar una reseña.
     */
    public function formularioResena(string $slug, ?string $numeroOrden = null)
    {
        $tenant = Tenant::where('slug_publico', $slug)->where('estado', 'activo')->firstOrFail();

        $config = Configuracion::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->first();

        $reparacion = null;
        if ($numeroOrden) {
            $reparacion = Reparacion::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('numero_orden', $numeroOrden)
                ->first();
        }

        return view('public.resena-form', [
            'tenant' => $tenant, 'config' => $config, 'reparacion' => $reparacion,
            'coloresMarca' => $tenant->colores(),
        ]);
    }

    /**
     * Guardar una reseña pública.
     */
    public function guardarResena(Request $request, string $slug)
    {
        $tenant = Tenant::where('slug_publico', $slug)->where('estado', 'activo')->firstOrFail();

        $validated = $request->validate([
            'calificacion' => 'required|integer|min:1|max:5',
            'comentario' => 'nullable|string|max:1000',
            'nombre_publico' => 'nullable|string|max:100',
            'reparacion_id' => 'nullable|integer',
        ]);

        $reparacionId = $validated['reparacion_id'] ?? null;
        $clienteId = null;

        if ($reparacionId) {
            $reparacion = Reparacion::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('id', $reparacionId)
                ->first();
            if ($reparacion) {
                $clienteId = $reparacion->cliente_id;
            }
        }

        Resena::create([
            'tenant_id' => $tenant->id,
            'reparacion_id' => $reparacionId,
            'cliente_id' => $clienteId,
            'calificacion' => $validated['calificacion'],
            'comentario' => $validated['comentario'] ?? null,
            'nombre_publico' => $validated['nombre_publico'] ?? null,
            'publicada' => true,
        ]);

        return redirect()->route('public.tienda', $slug)
            ->with('success', '¡Gracias por tu reseña! Tu opinión nos ayuda a mejorar.');
    }

    /**
     * Validar y aplicar un cupón en una venta.
     */
    public function validarCupon(Request $request)
    {
        $request->validate([
            'codigo' => 'required|string|max:30',
        ]);

        $codigo = strtoupper(trim($request->codigo));

        // Buscar el cupón sin el scope global de tenant para poder filtrar manualmente
        $query = Cupon::withoutGlobalScopes()->where('codigo', $codigo);

        // Si el usuario está autenticado, filtrar por su tenant
        if (auth()->check() && auth()->user()->tenant_id) {
            $query->where('tenant_id', auth()->user()->tenant_id);
        }

        $cupon = $query->first();

        if (!$cupon) {
            return response()->json(['success' => false, 'message' => 'Cupón no encontrado.'], 404);
        }

        if (!$cupon->esValido()) {
            return response()->json(['success' => false, 'message' => 'Este cupón ya fue usado o está expirado.'], 400);
        }

        return response()->json([
            'success' => true,
            'cupon' => [
                'id' => $cupon->id,
                'codigo' => $cupon->codigo,
                'tipo' => $cupon->tipo,
                'valor' => (float) $cupon->valor,
                'descripcion' => $cupon->descripcion,
            ],
        ]);
    }

    /**
     * Generar cupón automático al entregar una reparación.
     */
    public static function generarCuponAlEntregar(Reparacion $reparacion): ?Cupon
    {
        try {
            $config = Configuracion::withoutGlobalScopes()
                ->where('tenant_id', $reparacion->tenant_id)
                ->first();

            if (!$config || !$config->cupon_automatico_al_entregar) {
                return null;
            }

            $porcentaje = (float) ($config->cupon_descuento_porcentaje ?? 10);
            $diasValidez = (int) ($config->cupon_dias_validez ?? 30);

            return Cupon::create([
                'tenant_id' => $reparacion->tenant_id,
                'reparacion_id' => $reparacion->id,
                'codigo' => Cupon::generarCodigo(),
                'tipo' => 'porcentaje',
                'valor' => $porcentaje,
                'descripcion' => "Descuento del {$porcentaje}% en tu próxima visita",
                'fecha_expiracion' => now()->addDays($diasValidez),
                'estado' => 'activo',
                'compartible' => true,
            ]);
        } catch (\Exception $e) {
            // Si falla la creación del cupón (ej: tabla no existe), no romper la entrega
            \Illuminate\Support\Facades\Log::warning('No se pudo generar cupón al entregar: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Enviar recordatorio de retiro por WhatsApp (manual desde el panel).
     */
    public function enviarRecordatorioRetiro(Reparacion $reparacion)
    {
        $reparacion->load('cliente');

        $telefono = $reparacion->cliente?->telefono ?? $reparacion->cliente?->celular ?? null;
        if (!$telefono) {
            return back()->with('error', 'El cliente no tiene teléfono registrado.');
        }

        $config = Configuracion::empresa();
        $nombreTienda = $config?->nombre_tienda ?? 'CRM Celulares';

        $mensaje = "🔔 *{$nombreTienda} - Recordatorio*\n\n" .
            "📋 N° Orden: {$reparacion->numero_orden}\n" .
            "📱 Equipo: {$reparacion->dispositivo} {$reparacion->marca} {$reparacion->modelo}\n\n" .
            "✅ *Su equipo está listo para recoger!*\n" .
            "📍 Lo esperamos en nuestro local. ¡Gracias por su preferencia!";

        $urlEstado = $reparacion->tenant
            ? $reparacion->tenant->urlSeguimientoOrden($reparacion->numero_orden)
            : route('reparaciones.public-status', $reparacion->numero_orden);
        if ($urlEstado) {
            $mensaje .= "\n\n🔗 *Estado en línea:*\n{$urlEstado}";
        }

        // URL de la mini página web
        $tenant = $reparacion->tenant;
        $urlMiniWeb = $tenant?->slug_publico ? url('/t/' . $tenant->slug_publico) : null;
        if ($urlMiniWeb) {
            $mensaje .= "\n\n🌐 *Visita nuestra tienda:*\n{$urlMiniWeb}";
        }

        $whatsappUrl = WhatsAppHelper::generarUrl($telefono, $mensaje);

        // Registrar el recordatorio
        RecordatorioRetiro::create([
            'tenant_id' => $reparacion->tenant_id,
            'reparacion_id' => $reparacion->id,
            'enviado_en' => now(),
            'tipo' => 'manual',
            'telefono' => $telefono,
        ]);

        if ($whatsappUrl) {
            return redirect()->away($whatsappUrl);
        }

        return back()->with('error', 'No se pudo generar el enlace de WhatsApp.');
    }

    /**
     * Vista Kanban de reparaciones (para técnicos).
     */
    public function kanban()
    {
        $reparaciones = Reparacion::with(['cliente', 'tecnico'])
            ->whereIn('estado', ['recibido', 'en_diagnostico', 'esperando_repuesto', 'en_reparacion', 'listo', 'entregado', 'no_reparable'])
            ->orderBy('prioridad', 'desc')
            ->orderBy('fecha_recepcion', 'asc')
            ->get();

        $estados = [
            'recibido' => ['label' => 'Recibido', 'icon' => 'fa-inbox', 'color' => '#ede9fe'],
            'en_diagnostico' => ['label' => 'Diagnóstico', 'icon' => 'fa-search', 'color' => '#e0f2fe'],
            'esperando_repuesto' => ['label' => 'Esperando repuesto', 'icon' => 'fa-clock', 'color' => '#fef9c3'],
            'en_reparacion' => ['label' => 'En reparación', 'icon' => 'fa-wrench', 'color' => '#dbeafe'],
            'listo' => ['label' => 'Listo', 'icon' => 'fa-check', 'color' => '#d1fae5'],
            'entregado' => ['label' => 'Entregado', 'icon' => 'fa-box', 'color' => '#f3f4f6'],
            'no_reparable' => ['label' => 'No reparable', 'icon' => 'fa-times', 'color' => '#fee2e2'],
        ];

        return view('reparaciones.kanban', compact('reparaciones', 'estados'));
    }

    /**
     * Actualizar estado desde Kanban (drag & drop).
     */
    public function kanbanActualizarEstado(Request $request, Reparacion $reparacion)
    {
        $request->validate([
            'estado' => 'required|in:recibido,en_diagnostico,esperando_repuesto,en_reparacion,listo,entregado,no_reparable',
        ]);

        $nuevoEstado = $request->estado;
        $estadoAnterior = $reparacion->estado;

        $data = ['estado' => $nuevoEstado];

        if ($nuevoEstado === 'entregado' && !$reparacion->fecha_entrega) {
            $data['fecha_entrega'] = now();
        }

        $reparacion->update($data);

        $respuesta = $this->notificarCambioEstado($reparacion, $nuevoEstado, $estadoAnterior);
        if ($respuesta) {
            return $respuesta;
        }

        return response()->json(['success' => true]);
    }

    private function notificarCambioEstado(Reparacion $reparacion, string $nuevoEstado, string $estadoAnterior): ?\Illuminate\Http\JsonResponse
    {
        if ($nuevoEstado === $estadoAnterior) {
            return null;
        }

        // Si pasa a "listo", notificar por WhatsApp
        if ($nuevoEstado === 'listo') {
            $reparacion->load('cliente');
            $config = Configuracion::empresa();
            $nombreTienda = $config?->nombre_tienda ?? 'CRM Celulares';
            $cliente = $reparacion->cliente;
            $urlEstado = $reparacion->tenant
                ? $reparacion->tenant->urlSeguimientoOrden($reparacion->numero_orden)
                : route('reparaciones.public-status', $reparacion->numero_orden);

            if ($cliente) {
                $telefono = $cliente->telefono ?? $cliente->celular;
                if ($telefono) {
                    $plantillaListo = $reparacion->tenant?->configuracion_extra['plantilla_listo'] ?? null;
        $mensaje = \App\Helpers\WhatsAppHelper::mensajeListo($reparacion, $nombreTienda, $urlEstado, $plantillaListo);
                    $whatsappUrl = \App\Helpers\WhatsAppHelper::generarUrl($telefono, $mensaje);
                    if ($whatsappUrl) {
                        return response()->json([
                            'success' => true,
                            'whatsapp_url' => $whatsappUrl,
                        ]);
                    }
                }
            }
        }

        // Si pasa a "entregado", generar cupón automático
        if ($nuevoEstado === 'entregado') {
            $cupon = self::generarCuponAlEntregar($reparacion);
            if ($cupon) {
                return response()->json([
                    'success' => true,
                    'cupon' => $cupon->codigo,
                    'message' => "Cupón generado: {$cupon->codigo}",
                ]);
            }
        }

        return null;
    }
}
