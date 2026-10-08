@extends('layouts.app')
@section('title', 'Configuración')

@section('breadcrumb')
    <ul><li class="breadcrumb-item active">Configuración</li></ul>
@endsection

@section('content')

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius:12px;">
        <i class="fas fa-check-circle me-2"></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius:12px;">
        <i class="fas fa-exclamation-circle me-2"></i>{{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<!-- ── Header ── -->
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h4 class="fw-bold mb-1" style="color:#0f172a;">Configuración del Sistema</h4>
        <p class="text-muted mb-0" style="font-size:13px;">Gestión de la empresa, usuarios y parámetros generales</p>
    </div>
</div>

<!-- ══════════ PESTAÑAS DE CONFIGURACIÓN ══════════ -->
<ul class="nav nav-pills mb-4 gap-2 flex-wrap" id="configTabs" style="border-bottom:2px solid #f3f4f6; padding-bottom:12px;">
    <li class="nav-item">
        <button class="nav-link active px-4" style="border-radius:20px; font-size:13px; font-weight:500;" data-bs-toggle="tab" data-bs-target="#tab-empresa" type="button" role="tab">
            <i class="fas fa-store me-1" style="color:#0891b2;"></i> Empresa
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link px-4" style="border-radius:20px; font-size:13px; font-weight:500;" data-bs-toggle="tab" data-bs-target="#tab-usuarios" type="button" role="tab">
            <i class="fas fa-users me-1" style="color:#06b6d4;"></i> Usuarios
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link px-4" style="border-radius:20px; font-size:13px; font-weight:500;" data-bs-toggle="tab" data-bs-target="#tab-publicidad" type="button" role="tab">
            <i class="fas fa-bullhorn me-1" style="color:#f59e0b;"></i> Publicidad
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link px-4" style="border-radius:20px; font-size:13px; font-weight:500;" data-bs-toggle="tab" data-bs-target="#tab-colores" type="button" role="tab">
            <i class="fas fa-palette me-1" style="color:var(--accent1);"></i> Colores
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link px-4" style="border-radius:20px; font-size:13px; font-weight:500;" data-bs-toggle="tab" data-bs-target="#tab-sistema" type="button" role="tab">
            <i class="fas fa-cog me-1" style="color:#10b981;"></i> Sistema
        </button>
    </li>
</ul>

<div class="tab-content" id="configTabContent">

    <!-- ══════════ TAB: EMPRESA ══════════ -->
    <div class="tab-pane fade show active" id="tab-empresa" role="tabpanel">

<div class="row g-4">
            <div class="col-lg-12">

        <!-- ── Datos de la Empresa ── -->
        <div class="card mb-4">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3"><i class="fas fa-store me-2" style="color:#0891b2;"></i>Datos de la Empresa</h6>

                <form action="{{ route('configuracion.updateEmpresa') }}" method="POST" enctype="multipart/form-data">
                    @csrf

                    <!-- Logo actual -->
                    <div class="text-center mb-3">
                        @if($empresa && $empresa->logo)
                            <img src="{{ asset($empresa->logo) }}" alt="Logo"
                                 style="max-width:120px; max-height:80px; border-radius:8px; object-fit:contain;">
                        @else
                            <div style="width:80px;height:80px;background:linear-gradient(135deg,#0891b2,#3b82f6);
                                        border-radius:14px;display:flex;align-items:center;justify-content:center;margin:0 auto;">
                                <i class="fas fa-store" style="color:#fff;font-size:32px;"></i>
                            </div>
                        @endif
                    </div>

                    <!-- Subir logo -->
                    <div class="mb-3">
                        <label for="logo" class="form-label">Logo de la empresa</label>
                        <input type="file" name="logo" id="logo" class="form-control" accept="image/*">
                        <div class="form-text">PNG, JPG, WEBP. Máx 2MB. Se actualizará al guardar.</div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label for="nombre_tienda" class="form-label">Nombre de tienda <span class="text-danger">*</span></label>
                            <input type="text" name="nombre_tienda" id="nombre_tienda" class="form-control"
                                   value="{{ old('nombre_tienda', $empresa->nombre_tienda ?? 'CRM Celulares') }}" required>
                        </div>
                        <div class="col-md-6">
                            <label for="pais" class="form-label">País</label>
                            <select name="pais" id="pais" class="form-select">
                                <option value="">Seleccionar...</option>
                                @foreach($paises as $cod => $nombre)
                                    <option value="{{ $cod }}" {{ (old('pais', $empresa->pais ?? '') == $cod) ? 'selected' : '' }}>
                                        {{ $nombre }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">Al cambiar de país se ajustará moneda, impuesto y zona horaria.</div>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label for="ruc" class="form-label">RUT</label>
                            <input type="text" name="ruc" id="ruc" class="form-control"
                                   value="{{ old('ruc', $empresa->ruc ?? '') }}" maxlength="20">
                        </div>
                        <div class="col-md-6">
                            <label for="direccion" class="form-label">Dirección</label>
                            <input type="text" name="direccion" id="direccion" class="form-control"
                                   value="{{ old('direccion', $empresa->direccion ?? '') }}" maxlength="500">
                        </div>
                    </div>

                    <!-- ── Facturación Electrónica ── -->
                    <div class="card mb-3" style="border:1px solid #e5e7eb; border-radius:12px; background:#fafafa;">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <h6 class="fw-bold mb-1" style="font-size:14px;">
                                        <i class="fas fa-file-invoice me-2" style="color:#0e7490;"></i>
                                        Facturación Electrónica
                                    </h6>
                                    <p class="text-muted mb-0" style="font-size:12px;">
                                        Emitir DTE (Chile) o Factura DIAN (Colombia) automáticamente al registrar ventas.
                                    </p>
                                </div>
                                <div class="form-check form-switch">
                                    <input type="checkbox" class="form-check-input" name="facturacion_electronica_activa"
                                           id="facturacion_electronica_activa" value="1"
                                           {{ ($empresa->facturacion_electronica_activa ?? false) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="facturacion_electronica_activa" id="label_facturacion">
                                        {{ ($empresa->facturacion_electronica_activa ?? false) ? 'Activada' : 'Desactivada' }}
                                    </label>
                                </div>
                            </div>

                            <!-- Campos que solo se muestran si está activada -->
                            <div id="campos-facturacion" style="display:{{ ($empresa->facturacion_electronica_activa ?? false) ? 'block' : 'none' }};">
                                <hr>

                                <!-- RUT Emisor y Razón Social -->
                                <div class="row g-2 mb-2">
                                    <div class="col-md-6">
                                        <label for="fe_rut_emisor" class="form-label" style="font-size:12px;">RUT Emisor</label>
                                        <input type="text" name="rut_emisor" id="fe_rut_emisor" class="form-control form-control-sm"
                                               value="{{ $empresa->rut_emisor ?? '' }}" placeholder="12.345.678-9">
                                        <div class="form-text" style="font-size:10px;">Tu RUT de empresa, ej: 76.123.456-7</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="fe_razon_social" class="form-label" style="font-size:12px;">Razón Social</label>
                                        <input type="text" name="razon_social" id="fe_razon_social" class="form-control form-control-sm"
                                               value="{{ $empresa->razon_social ?? '' }}" placeholder="Nombre legal de tu empresa">
                                    </div>
                                </div>

                                <!-- Giro y Comuna -->
                                <div class="row g-2 mb-2">
                                    <div class="col-md-6">
                                        <label for="fe_giro" class="form-label" style="font-size:12px;">Giro</label>
                                        <input type="text" name="giro" id="fe_giro" class="form-control form-control-sm"
                                               value="{{ $empresa->giro ?? '' }}" placeholder="Ej: Venta de celulares">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="fe_comuna_ciudad" class="form-label" style="font-size:12px;">Comuna / Ciudad</label>
                                        <input type="text" name="comuna_ciudad" id="fe_comuna_ciudad" class="form-control form-control-sm"
                                               value="{{ $empresa->comuna_ciudad ?? '' }}" placeholder="Ej: Santiago">
                                    </div>
                                </div>

                                <!-- Proveedor DTE -->
                                <div class="row g-2 mb-2">
                                    <div class="col-md-6">
                                        <label for="fe_proveedor_dte" class="form-label" style="font-size:12px;">Proveedor DTE</label>
                                        <select name="proveedor_dte" id="fe_proveedor_dte" class="form-select form-select-sm">
                                            <option value="">Seleccionar...</option>
                                            <option value="acepta" {{ ($empresa->proveedor_dte ?? '') == 'acepta' ? 'selected' : '' }}>Acepta</option>
                                            <option value="fove"   {{ ($empresa->proveedor_dte ?? '') == 'fove' ? 'selected' : '' }}>Fove</option>
                                            <option value="tundra" {{ ($empresa->proveedor_dte ?? '') == 'tundra' ? 'selected' : '' }}>Tundra</option>
                                        </select>
                                        <div class="form-text" style="font-size:10px;">Proveedor autorizado que envía tus facturas al SII</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="fe_dte_certificado" class="form-label" style="font-size:12px;">Certificado Digital (.pfx / .p12)</label>
                                        <input type="file" name="dte_certificado" id="fe_dte_certificado" class="form-control form-control-sm" accept=".pfx,.p12">
                                        @if($empresa->dte_certificado)
                                            <div class="form-text" style="font-size:10px;color:#059669;">
                                                <i class="fas fa-check-circle me-1"></i>Certificado cargado: {{ basename($empresa->dte_certificado) }}
                                            </div>
                                        @else
                                            <div class="form-text" style="font-size:10px;color:#6b7280;">Sube el .pfx que te emitió el SII (máx 2MB)</div>
                                        @endif
                                    </div>
                                </div>

                                <!-- Contraseña del certificado -->
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label for="certificado_password" class="form-label" style="font-size:12px;">Contraseña del certificado</label>
                                        <input type="password" name="certificado_password" id="certificado_password" class="form-control form-control-sm"
                                               placeholder="Clave del archivo .pfx" autocomplete="off">
                                        <div class="form-text" style="font-size:10px;">La contraseña que protege tu certificado digital</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Mercado Pago ── -->
                    <div class="card mb-3" style="border:1px solid #e5e7eb; border-radius:12px; background:#fafafa;">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <h6 class="fw-bold mb-1" style="font-size:14px;">
                                        <i class="fab fa-mercadopago me-2" style="color:#00b1ea;"></i>
                                        Mercado Pago
                                    </h6>
                                    <p class="text-muted mb-0" style="font-size:12px;">
                                        Cobra con QR y la boleta se envía al SII automáticamente.
                                    </p>
                                </div>
                                <div class="form-check form-switch">
                                    <input type="checkbox" class="form-check-input" name="mercadopago_activo"
                                           id="mercadopago_activo" value="1"
                                           {{ ($empresa->mercadopago_activo ?? false) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="mercadopago_activo" id="label_mercadopago">
                                        {{ ($empresa->mercadopago_activo ?? false) ? 'Activado' : 'Desactivado' }}
                                    </label>
                                </div>
                            </div>

                            <!-- Campos que solo se muestran si está activado -->
                            <div id="campos-mercadopago" style="display:{{ ($empresa->mercadopago_activo ?? false) ? 'block' : 'none' }};">
                                <hr>
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label for="mercadopago_public_key" class="form-label" style="font-size:12px;">Public Key</label>
                                        <input type="text" name="mercadopago_public_key" id="mercadopago_public_key" class="form-control form-control-sm"
                                               value="{{ $empresa->mercadopago_public_key ?? '' }}"
                                               placeholder="APP_USR-xxxx-xxxx-xxxx-xxxx">
                                        <div class="form-text" style="font-size:10px;">La encuentras en developers.mercadopago.com</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="mercadopago_access_token" class="form-label" style="font-size:12px;">Access Token</label>
                                        <input type="password" name="mercadopago_access_token" id="mercadopago_access_token" class="form-control form-control-sm"
                                               value="{{ $empresa->mercadopago_access_token ?? '' }}"
                                               placeholder="APP_USR-xxxx-xxxx-xxxx-xxxx" autocomplete="off">
                                        <div class="form-text" style="font-size:10px;">Token secreto para procesar pagos</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="mercadopago_device_id" class="form-label" style="font-size:12px;">Device ID (Point)</label>
                                        <input type="text" name="mercadopago_device_id" id="mercadopago_device_id" class="form-control form-control-sm"
                                               value="{{ $empresa->mercadopago_device_id ?? '' }}"
                                               placeholder="PREFIX-1234-1234-1234">
                                        <div class="form-text" style="font-size:10px;">ID del dispositivo Point (lo encuentras en la app Point)</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label for="telefono" class="form-label">Teléfono</label>
                            <input type="text" name="telefono" id="telefono" class="form-control"
                                   value="{{ old('telefono', $empresa->telefono ?? '') }}">
                        </div>
                        <div class="col-md-6">
                            <label for="whatsapp" class="form-label">WhatsApp</label>
                            <input type="text" name="whatsapp" id="whatsapp" class="form-control"
                                   value="{{ old('whatsapp', $empresa->whatsapp ?? '') }}"
                                   placeholder="+CODIGO_PAIS NÚMERO (ej: +51 999 999 999)">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" name="email" id="email" class="form-control"
                                   value="{{ old('email', $empresa->email ?? '') }}">
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label for="igv" class="form-label">IVA (%)</label>
                            <input type="number" name="igv" id="igv" class="form-control" step="0.01" min="0" max="100"
                                   value="{{ old('igv', $empresa->igv ?? 18) }}" required>
                        </div>
                        <div class="col-md-4">
                            <label for="moneda" class="form-label">Moneda</label>
                            <select name="moneda" id="moneda" class="form-select" required>
                                <option value="">Seleccionar...</option>
                {{-- Las monedas vienen del controlador como $monedas --}}
                                @foreach($monedas as $cod => $datos)
                                    <option value="{{ $cod }}" {{ (old('moneda', $empresa->moneda ?? 'PEN') == $cod) ? 'selected' : '' }}
                                            data-simbolo="{{ $datos['simbolo'] }}">
                                        {{ $cod }} - {{ $datos['pais'] }} ({{ $datos['simbolo'] }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="simbolo_moneda" class="form-label">Símbolo</label>
                            <input type="text" name="simbolo_moneda" id="simbolo_moneda" class="form-control"
                                   value="{{ old('simbolo_moneda', $empresa->simbolo_moneda ?? 'S/.') }}" required readonly
                                   style="background:#f9fafb;cursor:default;">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="terminos_garantia" class="form-label">Términos de garantía</label>
                        <textarea name="terminos_garantia" id="terminos_garantia" class="form-control" rows="2" maxlength="1000"
                                  placeholder="Ej: 30 días de garantía...">{{ old('terminos_garantia', $empresa->terminos_garantia ?? '') }}</textarea>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-save me-2"></i>Guardar Datos
                    </button>
                </form>
            </div>
        </div>

        <!-- ═══════ Plan y Suscripción ═══════ -->
        <div class="card mb-4">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3"><i class="bi bi-star-fill me-2" style="color:#f59e0b;"></i>Mi Plan</h6>
                @php
                    $tenant = auth()->user()->tenant;
                @endphp
                @if($tenant)
                    <div class="text-center mb-3">
                        <span class="badge bg-{{ $badgeColor[$tenant->plan] ?? 'secondary' }}" style="font-size:1rem; padding:8px 20px;">
                            {{ $planes[$tenant->plan] ?? ucfirst($tenant->plan) }}
                        </span>
                    </div>
                    <div style="font-size:13px;">
                        <div class="d-flex justify-content-between py-2" style="border-bottom:1px solid #f3f4f6;">
                            <span class="text-muted">Estado</span>
                            <span class="badge bg-{{ $tenant->estado === 'activo' ? 'success' : 'danger' }}">{{ $tenant->estado }}</span>
                        </div>
                        <div class="d-flex justify-content-between py-2" style="border-bottom:1px solid #f3f4f6;">
                            <span class="text-muted">Usuarios</span>
                            <span class="fw-500">{{ $usuarios->count() }} / {{ $tenant->max_usuarios }}</span>
                        </div>
                        <div class="d-flex justify-content-between py-2" style="border-bottom:1px solid #f3f4f6;">
                            <span class="text-muted">Productos</span>
                            <span class="fw-500">{{ $tenant->productos_count ?? \App\Models\Producto::where('tenant_id', $tenant->id)->count() }} / {{ $tenant->max_productos }}</span>
                        </div>
                        <div class="d-flex justify-content-between py-2" style="border-bottom:1px solid #f3f4f6;">
                            <span class="text-muted">Vencimiento</span>
                            <span class="fw-500">{{ $tenant->fecha_expiracion ? $tenant->fecha_expiracion->format('d/m/Y') : 'Sin fecha' }}</span>
                        </div>
                    </div>
                    <div class="mt-3">
                        <a href="{{ route('planes') }}#precios" target="_blank" class="btn btn-outline-primary btn-sm w-100">
                            <i class="bi bi-arrow-up-circle me-1"></i>Mejorar Plan
                        </a>
                    </div>
                @else
                    <p class="text-muted text-center mb-0">No disponible</p>
                @endif
            </div>
        </div>

        <!-- Zona horaria -->
        <div class="card mb-4">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3"><i class="fas fa-clock me-2" style="color:#0891b2;"></i>Zona Horaria</h6>
                <p class="text-muted" style="font-size:12px;">Configura la zona horaria de tu empresa para que las fechas y horas se muestren correctamente.</p>

                <form action="{{ route('configuracion.updateZonaHoraria') }}" method="POST">
                    @csrf
                    <div class="mb-3">
                        <label for="zona_horaria" class="form-label">Zona horaria</label>
                        <select name="zona_horaria" id="zona_horaria" class="form-select @error('zona_horaria') is-invalid @enderror" required>
                            @foreach($zonasHorarias as $codigo => $descripcion)
                                <option value="{{ $codigo }}" {{ (old('zona_horaria', $empresa->zona_horaria ?? 'America/Lima') == $codigo) ? 'selected' : '' }}>
                                    {{ $descripcion }}
                                </option>
                            @endforeach
                        </select>
                        @error('zona_horaria')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">La hora actual en esta zona: <strong id="horaActual"></strong></div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-save me-2"></i>Guardar Zona Horaria
                    </button>
                </form>
            </div>
        </div>

            </div>
        </div>
    </div>

    <!-- ══════════ TAB: USUARIOS ══════════ -->
    <div class="tab-pane fade" id="tab-usuarios" role="tabpanel">
        <div class="row g-4">
            <div class="col-lg-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <h6 class="fw-bold mb-0">Gestión de Usuarios</h6>
                    <div class="d-flex align-items-center gap-2">
                        <a href="{{ route('two-factor.setup') }}" class="btn btn-outline-secondary btn-sm" style="border-radius:20px;"
                           title="Configurar verificación en dos pasos para tu cuenta">
                            <i class="fas fa-shield-halved me-1" style="color:#0891b2;"></i> Mi 2FA
                        </a>
                        <span style="background:#cffafe;color:#0e7490;border-radius:20px;padding:3px 12px;font-size:12px;">
                            {{ $usuarios->count() }} usuarios
                        </span>
                        <button type="button" class="btn btn-primary btn-sm" style="border-radius:20px;"
                                data-bs-toggle="modal" data-bs-target="#modalNuevoUsuario">
                            <i class="fas fa-user-plus me-1"></i> Nuevo Usuario
                        </button>
                    </div>
                </div>

                <!-- Leyenda de roles -->
                <div class="d-flex gap-3 mb-4" style="font-size:12px;">
                    <span><span style="display:inline-block;width:10px;height:10px;background:#0891b2;border-radius:50%;margin-right:4px;"></span>Admin</span>
                    <span><span style="display:inline-block;width:10px;height:10px;background:#06b6d4;border-radius:50%;margin-right:4px;"></span>Vendedor</span>
                    <span><span style="display:inline-block;width:10px;height:10px;background:#f59e0b;border-radius:50%;margin-right:4px;"></span>Técnico</span>
                </div>

                <div class="row g-3">
                    @foreach($usuarios as $usuario)
                    @php
                        $rolesUsuario = $usuario->rolesEfectivos();
                        $rolColor = ['admin'=>'#0891b2','vendedor'=>'#06b6d4','tecnico'=>'#f59e0b'][$rolesUsuario[0] ?? ''] ?? '#9ca3af';
                        $rolBg    = ['admin'=>'#cffafe','vendedor'=>'#e0f2fe','tecnico'=>'#fef3c7'];
                        $rolTxt   = ['admin'=>'#0e7490','vendedor'=>'#0369a1','tecnico'=>'#92400e'];
                        $inicial  = strtoupper(substr($usuario->name, 0, 1));
                    @endphp
                    <div class="col-12">
                        <div class="p-3 rounded-3 d-flex align-items-center gap-3"
                             style="background:#f9fafb;border:1px solid #f3f4f6;transition:all .2s;">

                            <!-- Avatar -->
                            <div style="width:44px;height:44px;background:{{ $rolColor }};border-radius:12px;
                                        display:flex;align-items:center;justify-content:center;
                                        color:#fff;font-weight:700;font-size:16px;flex-shrink:0;">
                                {{ $inicial }}
                            </div>

                            <!-- Info -->
                            <div class="flex-grow-1" style="min-width:0;">
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <span class="fw-600" style="font-size:14px;font-weight:600;">{{ $usuario->name }}</span>
                                    @foreach($rolesUsuario as $rolUsuario)
                                        <span style="background:{{ $rolBg[$rolUsuario] ?? '#f3f4f6' }};color:{{ $rolTxt[$rolUsuario] ?? '#374151' }};
                                                     border-radius:20px;padding:2px 8px;font-size:11px;">
                                            {{ ucfirst($rolUsuario) }}
                                        </span>
                                    @endforeach
                                    @if($usuario->id === auth()->id())
                                        <span style="background:#d1fae5;color:#065f46;border-radius:20px;padding:2px 8px;font-size:11px;">
                                            Tú
                                        </span>
                                    @endif
                                    @if(!$usuario->activo)
                                        <span style="background:#fee2e2;color:#991b1b;border-radius:20px;padding:2px 8px;font-size:11px;">
                                            Inactivo
                                        </span>
                                    @endif
                                </div>
                                <div style="font-size:12px;color:#9ca3af;margin-top:2px;">
                                    <i class="fas fa-envelope me-1"></i>{{ $usuario->email }}
                                    @if($usuario->telefono)
                                        &nbsp;·&nbsp;<i class="fas fa-phone me-1"></i>{{ $usuario->telefono }}
                                    @endif
                                </div>
                            </div>

                            <!-- Acciones -->
                            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                <!-- Editar -->
                                <button class="btn btn-sm btn-outline-secondary" style="border-radius:8px;padding:4px 10px;"
                                        title="Editar usuario"
                                        onclick="abrirModalEditar({{ $usuario->id }}, '{{ addslashes($usuario->name) }}', '{{ $usuario->email }}', '{{ implode(',', $usuario->rolesEfectivos()) }}', '{{ $usuario->telefono }}', '{{ $usuario->comision_porcentaje }}')">
                                    <i class="fas fa-edit" style="font-size:12px;"></i>
                                </button>

                                @if($usuario->id !== auth()->id())
                                <!-- Toggle activo -->
                                <form action="{{ route('configuracion.toggleUsuario', $usuario) }}" method="POST" style="display:inline;">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="btn btn-sm {{ $usuario->activo ? 'btn-outline-warning' : 'btn-outline-success' }}"
                                            style="border-radius:8px;padding:4px 10px;"
                                            title="{{ $usuario->activo ? 'Desactivar' : 'Activar' }} usuario">
                                        <i class="fas fa-{{ $usuario->activo ? 'ban' : 'check' }}" style="font-size:12px;"></i>
                                    </button>
                                </form>

                                <!-- Eliminar -->
                                <form action="{{ route('configuracion.destroyUsuario', $usuario) }}" method="POST" style="display:inline;"
                                      onsubmit="return confirm('¿Eliminar al usuario {{ addslashes($usuario->name) }}? Esta acción no se puede deshacer.')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                            style="border-radius:8px;padding:4px 10px;"
                                            title="Eliminar usuario">
                                        <i class="fas fa-trash" style="font-size:12px;"></i>
                                    </button>
                                </form>
                                @endif
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- Información de seguridad -->
        <div class="card mt-4">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3"><i class="fas fa-shield-alt me-2" style="color:#0891b2;"></i>Políticas de Acceso</h6>
                <div class="row g-3" style="font-size:13px;">
                    <div class="col-md-4">
                        <div class="p-3 rounded-3" style="background:#f8f5ff;border-left:3px solid #0891b2;">
                            <div class="fw-600 mb-1" style="font-weight:600;color:#0e7490;">Admin</div>
                            <div class="text-muted" style="font-size:12px;">Acceso completo a todos los módulos, configuración y reportes.</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 rounded-3" style="background:#e0f7fa;border-left:3px solid #06b6d4;">
                            <div class="fw-600 mb-1" style="font-weight:600;color:#0369a1;">Vendedor</div>
                            <div class="text-muted" style="font-size:12px;">Clientes, inventario, ventas y consulta de reportes.</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 rounded-3" style="background:#fffbeb;border-left:3px solid #f59e0b;">
                            <div class="fw-600 mb-1" style="font-weight:600;color:#92400e;">Técnico</div>
                            <div class="text-muted" style="font-size:12px;">Gestión de reparaciones y consulta de inventario.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
            </div>
        </div>
    </div>

    <!-- ══════════ TAB: PUBLICIDAD ══════════ -->
    <div class="tab-pane fade" id="tab-publicidad" role="tabpanel">

<!-- ══════════ MENSAJES DE WHATSAPP ══════════ -->
@php
    $extrasMarca = auth()->user()->tenant?->configuracion_extra ?? [];
@endphp
<div class="card mb-4" style="border-radius:16px; border:1px solid #e5e7eb;">
    <div class="card-body p-4">
        <h5 class="fw-bold mb-1" style="color:#0f172a;">
            <i class="fab fa-whatsapp me-2" style="color:#25d366;"></i>Mensajes de WhatsApp
        </h5>
        <p class="text-muted mb-3" style="font-size:12.5px;">
            Personaliza los mensajes automáticos a tus clientes. Variables:
            <code>{cliente}</code> <code>{equipo}</code> <code>{codigo}</code> <code>{falla}</code> <code>{costo}</code> <code>{tienda}</code> <code>{url}</code>.
            Déjalos vacíos para usar los mensajes por defecto.
        </p>

        <form method="POST" action="{{ route('configuracion.whatsapp') }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" style="font-size:12.5px;font-weight:600;">Al recibir el equipo</label>
                    <textarea name="plantilla_recibido" class="form-control" rows="8"
                              placeholder="(vacío = mensaje por defecto)">{{ old('plantilla_recibido', $extrasMarca['plantilla_recibido'] ?? '') }}</textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label" style="font-size:12.5px;font-weight:600;">Cuando el equipo esté listo</label>
                    <textarea name="plantilla_listo" class="form-control" rows="8"
                              placeholder="(vacío = mensaje por defecto)">{{ old('plantilla_listo', $extrasMarca['plantilla_listo'] ?? '') }}</textarea>
                </div>
            </div>

            <button type="submit" class="btn btn-primary mt-3 px-4">
                <i class="fas fa-save me-2"></i>Guardar mensajes
            </button>
        </form>
    </div>
</div>

<!-- ══════════ PROMOCIONES PARA LA PANTALLA TV ══════════ -->
<div class="card mb-4" style="border-radius:16px; border:1px solid #e5e7eb;">
    <div class="card-body p-4">
        <h5 class="fw-bold mb-1" style="color:#0f172a;">
            <i class="fas fa-tv me-2" style="color:#8b5cf6;"></i>Promociones para la pantalla TV
        </h5>
        <p class="text-muted mb-3" style="font-size:12.5px;">
            Una promoción por línea con el formato <code>Título | Mensaje</code>. Rotan en la Sala de Espera. Máximo 8.
        </p>

        <form method="POST" action="{{ route('configuracion.promos') }}">
            @csrf
            <textarea name="promos_texto" class="form-control" rows="5"
                      placeholder="Cristales desde $15.000 | Esta semana, cambio de pantalla con vidrio templado incluido">{{ old('promos_texto', collect($extrasMarca['promos'] ?? [])->map(fn ($p) => ($p['titulo'] ?? '') . ' | ' . ($p['texto'] ?? ''))->implode("\n")) }}</textarea>
            @error('promos_texto')<div class="text-danger small">{{ $message }}</div>@enderror

            <button type="submit" class="btn btn-primary mt-3 px-4">
                <i class="fas fa-save me-2"></i>Guardar promociones
            </button>
        </form>
    </div>
</div>
        <div class="row g-4">
            <div class="col-lg-12">
        <!-- ═══════ Publicidad / Página Pública ═══════ -->
        @php
            $tenantActual = auth()->user()->tenant;
            $slugPublico = $tenantActual?->slug_publico;
            $urlPublica = $slugPublico ? url('/t/' . $slugPublico) : null;
            // Botón "Pantalla en TV": abre la sala de espera de ESTA tienda (/pantalla/{slug}).
            $urlPantallaTv = $tenantActual?->urlPantalla();
        @endphp
        <div class="card mb-4" style="border:2px solid #0891b2;">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3"><i class="fas fa-bullhorn me-2" style="color:#0891b2;"></i>Publicidad y Página Pública</h6>

                @if($urlPublica)
                <div class="p-3 mb-3 text-center" style="background:#f0fdf4;border-radius:12px;border:1px dashed #10b981;">
                    <div style="font-size:12px;color:#065f46;" class="mb-2">
                        <i class="fas fa-globe me-1"></i>Tu página pública está activa
                    </div>
                    <a href="{{ $urlPublica }}" target="_blank" class="btn btn-success btn-sm" style="border-radius:20px;">
                        <i class="fas fa-external-link-alt me-2"></i>Ver mi página pública
                    </a>
                    <div class="form-text mt-2" style="font-size:11px;">
                        Comparte este link: <strong>{{ $urlPublica }}</strong>
                    </div>
                </div>
                @if($urlPantallaTv)
                <div class="p-3 mb-3 text-center" style="background:#eff6ff;border-radius:12px;border:1px dashed #3b82f6;">
                    <div style="font-size:12px;color:#1e40af;" class="mb-2">
                        <i class="fas fa-tv me-1"></i>Sala de espera de tu tienda
                    </div>
                    <a href="{{ $urlPantallaTv }}" target="_blank" rel="noopener" class="btn btn-primary btn-sm" style="border-radius:20px;">
                        <i class="fas fa-tv me-2"></i>Pantalla en TV
                    </a>
                    <div class="form-text mt-2" style="font-size:11px;">
                        Abre tu pantalla (<strong>{{ $urlPantallaTv }}</strong>) y guárdala como página de inicio de la TV. Muestra solo tus órdenes.
                    </div>
                </div>
                @endif
                @else
                <div class="alert alert-warning py-2 px-3" style="font-size:12px;">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    Tu tenant no tiene slug público. Ejecuta <code>php artisan tenant:asignar-slugs</code> en el servidor.
                </div>
                @endif

                <form action="{{ route('configuracion.updatePublicidad') }}" method="POST">
                    @csrf

                    <div class="mb-2 d-flex align-items-center justify-content-between">
                        <span class="form-label mb-0">Activar página pública</span>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="pagina_publica_activa" value="1"
                                   id="paginaPublicaActiva" {{ old('pagina_publica_activa', $empresa->pagina_publica_activa ?? true) ? 'checked' : '' }}>
                            <label class="form-check-label" for="paginaPublicaActiva" style="font-size:12px;">Sí / No</label>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="descripcion_corta" class="form-label">Descripción corta</label>
                        <textarea name="descripcion_corta" id="descripcion_corta" class="form-control" rows="2" maxlength="500"
                                  placeholder="Ej: Expertos en reparación de celulares. Repuestos originales y garantía.">{{ old('descripcion_corta', $empresa->descripcion_corta ?? '') }}</textarea>
                    </div>

                    <div class="mb-3">
                        <label for="horario_atencion" class="form-label">Horario de atención</label>
                        <input type="text" name="horario_atencion" id="horario_atencion" class="form-control" maxlength="255"
                               value="{{ old('horario_atencion', $empresa->horario_atencion ?? '') }}"
                               placeholder="Ej: Lun-Vie 9am-7pm, Sáb 9am-2pm">
                    </div>

                    <div class="mb-3">
                        <label for="mapa_url" class="form-label"><i class="fas fa-map-marker-alt me-1" style="color:#ef4444;"></i>Ubicación en Google Maps</label>
                        <input type="text" name="mapa_url" id="mapa_url" class="form-control" maxlength="1000"
                               value="{{ old('mapa_url', $empresa->mapa_url ?? '') }}"
                               placeholder="Pega el link de Google Maps (ej: https://maps.app.goo.gl/...)">
                        <div class="form-text">
                            Cómo obtenerlo: Abre Google Maps → busca tu dirección → clic en "Compartir" → "Copiar enlace".
                            Se mostrará un mapa interactivo y un botón "Cómo llegar" en tu página pública.
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label for="instagram" class="form-label"><i class="fab fa-instagram me-1" style="color:#e1306c;"></i>Instagram</label>
                            <input type="text" name="instagram" id="instagram" class="form-control" maxlength="255"
                                   value="{{ old('instagram', $empresa->instagram ?? '') }}" placeholder="https://instagram.com/...">
                        </div>
                        <div class="col-md-4">
                            <label for="facebook" class="form-label"><i class="fab fa-facebook me-1" style="color:#1877f2;"></i>Facebook</label>
                            <input type="text" name="facebook" id="facebook" class="form-control" maxlength="255"
                                   value="{{ old('facebook', $empresa->facebook ?? '') }}" placeholder="https://facebook.com/...">
                        </div>
                        <div class="col-md-4">
                            <label for="tiktok" class="form-label"><i class="fab fa-tiktok me-1"></i>TikTok</label>
                            <input type="text" name="tiktok" id="tiktok" class="form-control" maxlength="255"
                                   value="{{ old('tiktok', $empresa->tiktok ?? '') }}" placeholder="https://tiktok.com/...">
                        </div>
                    </div>

                    <hr style="border-top:1px dashed #e5e7eb;">

                    <h6 class="fw-bold mb-3" style="font-size:13px;">
                        <i class="fas fa-ticket-alt me-2" style="color:#10b981;"></i>Cupón de Descuento Automático
                    </h6>

                    <div class="mb-2 d-flex align-items-center justify-content-between">
                        <span class="form-label mb-0">Generar cupón al entregar</span>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="cupon_automatico_al_entregar" value="1"
                                   id="cuponAutomatico" {{ old('cupon_automatico_al_entregar', $empresa->cupon_automatico_al_entregar ?? true) ? 'checked' : '' }}>
                            <label class="form-check-label" for="cuponAutomatico" style="font-size:12px;">Sí / No</label>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label for="cupon_descuento_porcentaje" class="form-label">Descuento (%)</label>
                            <input type="number" name="cupon_descuento_porcentaje" id="cupon_descuento_porcentaje" class="form-control"
                                   min="0" max="100" step="0.5"
                                   value="{{ old('cupon_descuento_porcentaje', $empresa->cupon_descuento_porcentaje ?? 10) }}">
                        </div>
                        <div class="col-md-6">
                            <label for="cupon_dias_validez" class="form-label">Días de validez</label>
                            <input type="number" name="cupon_dias_validez" id="cupon_dias_validez" class="form-control"
                                   min="1" max="365"
                                   value="{{ old('cupon_dias_validez', $empresa->cupon_dias_validez ?? 30) }}">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-save me-2"></i>Guardar Publicidad
                    </button>
                </form>
            </div>
        </div>
            </div>
        </div>
    </div>

    <!-- ══════════ TAB: SISTEMA ══════════ -->
    <div class="tab-pane fade" id="tab-colores" role="tabpanel">

<!-- ══════════ COLORES DE MI EMPRESA ══════════ -->
@php
    $colores = auth()->user()->tenant?->colores() ?? [
        'primario'      => '#0891b2',
        'secundario'    => '#3b82f6',
        'primario_rgba' => 'rgba(8,145,178,.18)',
    ];
@endphp
<div class="card mb-4" style="border-radius:16px; border:1px solid #e5e7eb;">
    <div class="card-body p-4">
        <h5 class="fw-bold mb-1" style="color:#0f172a;">
            <i class="fas fa-palette me-2" style="color:{{ $colores['primario'] }};"></i>Colores de mi empresa
            <span class="ms-2" title="Colores guardados actualmente">
                <span style="display:inline-block;width:20px;height:20px;border-radius:5px;background:{{ $colores['primario'] }};border:1px solid #d1d5db;vertical-align:middle;"></span>
                <span style="display:inline-block;width:20px;height:20px;border-radius:5px;background:{{ $colores['secundario'] }};border:1px solid #d1d5db;vertical-align:middle;"></span>
            </span>
        </h5>
        <p class="text-muted mb-3" style="font-size:12.5px;">
            Se aplican al instante en tu panel y en tu página pública.
        </p>

        <form method="POST" action="{{ route('configuracion.colores') }}">
            @csrf
            <div class="row g-3 align-items-end">
                <div class="col-md-3 col-6">
                    <label class="form-label" style="font-size:12.5px;font-weight:600;">Color principal</label>
                    <input type="color" name="color_primario" id="cpPrimario"
                           class="form-control form-control-color w-100" value="{{ $colores['primario'] }}">
                </div>
                <div class="col-md-3 col-6">
                    <label class="form-label" style="font-size:12.5px;font-weight:600;">Color secundario</label>
                    <input type="color" name="color_secundario" id="cpSecundario"
                           class="form-control form-control-color w-100" value="{{ $colores['secundario'] }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label" style="font-size:12.5px;font-weight:600;">Temas rápidos</label>
                    <div class="d-flex gap-2 flex-wrap">
                        @foreach([
                            ['Luitech', '#0891b2', '#3b82f6'],
                            ['Verde', '#10b981', '#059669'],
                            ['Morado', '#8b5cf6', '#6366f1'],
                            ['Naranja', '#f97316', '#dc2626'],
                            ['Rojo', '#ef4444', '#991b1b'],
                            ['Rosa', '#ec4899', '#8b5cf6'],
                        ] as $tema)
                            <button type="button" class="preset-color border-0" data-p="{{ $tema[1] }}" data-s="{{ $tema[2] }}"
                                    title="{{ $tema[0] }}"
                                    style="width:34px;height:34px;border-radius:50%;cursor:pointer;
                                           background:linear-gradient(135deg,{{ $tema[1] }},{{ $tema[2] }});
                                           border:2px solid #fff;box-shadow:0 0 0 1px #e5e7eb;"></button>
                        @endforeach
                    </div>
                    @error('color_primario')<div class="text-danger small">{{ $message }}</div>@enderror
                    @error('color_secundario')<div class="text-danger small">{{ $message }}</div>@enderror
                </div>
            </div>

            <!-- Vista previa en vivo -->
            <div class="mt-3 p-3 rounded-3 d-flex align-items-center gap-3 flex-wrap"
                 style="background:#f9fafb;border:1px dashed #d1d5db;">
                <div style="width:110px;height:60px;border-radius:12px;background:#0f172a;
                            display:flex;align-items:center;padding:10px;">
                    <div id="previewChip" style="width:28px;height:28px;border-radius:8px;
                            background:linear-gradient(135deg,{{ $colores['primario'] }},{{ $colores['secundario'] }});
                            display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;">
                        <i class="fas fa-mobile-screen"></i>
                    </div>
                </div>
                <span style="font-size:12px;color:#6b7280;">Vista previa →</span>
                <button type="button" id="previewBtn1" class="btn btn-sm"
                        style="background:linear-gradient(135deg,{{ $colores['primario_puro'] }},{{ $colores['secundario_puro'] }});
                               color:{{ $colores['texto_sobre_primario'] }};border-radius:10px;">Botón principal</button>
                <button type="button" id="previewBtn2" class="btn btn-sm"
                        style="background:{{ $colores['primario_rgba'] }};color:{{ $colores['primario'] }};
                               border-radius:10px;">Elemento activo</button>
            </div>

            <button type="submit" class="btn btn-primary mt-3 px-4">
                <i class="fas fa-save me-2"></i>Guardar colores
            </button>
        </form>

        <script>
            var cpPrimario   = document.getElementById('cpPrimario');
            var cpSecundario = document.getElementById('cpSecundario');
            var previewChip  = document.getElementById('previewChip');
            var previewBtn1  = document.getElementById('previewBtn1');
            var previewBtn2  = document.getElementById('previewBtn2');

            // ── Contraste automático (mismas reglas que el servidor) ──────
            function luminanciaHex(hex) {
                var h = hex.replace('#', '');
                if (h.length === 3) h = h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
                var f = function (c) {
                    c = parseInt(c, 16) / 255;
                    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
                };
                return 0.2126 * f(h.substr(0,2)) + 0.7152 * f(h.substr(2,2)) + 0.0722 * f(h.substr(4,2));
            }
            function textoSobre(hex) { return luminanciaHex(hex) > 0.45 ? '#0f172a' : '#ffffff'; }
            function oscurecerHex(hex, factor) {
                var h = hex.replace('#', '');
                if (h.length === 3) h = h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
                var f = 1 - factor;
                var r = Math.round(parseInt(h.substr(0,2),16) * f),
                    g = Math.round(parseInt(h.substr(2,2),16) * f),
                    b = Math.round(parseInt(h.substr(4,2),16) * f);
                return '#' + ('0'+r.toString(16)).slice(-2) + ('0'+g.toString(16)).slice(-2) + ('0'+b.toString(16)).slice(-2);
            }
            function hexLegible(hex) {
                var factor = 0;
                while (luminanciaHex(hex) > 0.45 && factor < 0.9) { factor += 0.15; hex = oscurecerHex(hex, 0.15); }
                return hex;
            }

            function actualizarPreviewColores() {
                var puroP = cpPrimario.value, puroS = cpSecundario.value;
                var legibleP = hexLegible(puroP);
                var texto = textoSobre(puroP);

                previewChip.style.background = 'linear-gradient(135deg,' + puroP + ',' + puroS + ')';
                previewBtn1.style.background = 'linear-gradient(135deg,' + puroP + ',' + puroS + ')';
                previewBtn1.style.color = texto;
                previewBtn2.style.background = legibleP + '26';
                previewBtn2.style.color = legibleP;
            }

            cpPrimario.addEventListener('input', actualizarPreviewColores);
            cpSecundario.addEventListener('input', actualizarPreviewColores);

            document.querySelectorAll('.preset-color').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    cpPrimario.value = btn.dataset.p;
                    cpSecundario.value = btn.dataset.s;
                    actualizarPreviewColores();
                });
            });
        </script>
    </div>
</div>


<div class="tab-pane fade" id="tab-sistema" role="tabpanel">
        <div class="row g-4">
            <div class="col-lg-6">
        <!-- Estadísticas rápidas -->
        <div class="card mb-4">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3">Estadísticas del Sistema</h6>
                {{-- Las estadísticas vienen del controlador como $stats --}}
                @foreach($stats as $s)
                <div class="d-flex align-items-center gap-3 py-2" style="border-bottom:1px solid #f3f4f6; font-size:13px;">
                    <div style="width:32px;height:32px;background:{{ $s['color'] }}18;border-radius:8px;
                                display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-{{ $s['icon'] }}" style="color:{{ $s['color'] }};font-size:13px;"></i>
                    </div>
                    <span class="text-muted flex-grow-1">{{ $s['label'] }}</span>
                    <strong>{{ $s['value'] }}</strong>
                </div>
                @endforeach
            </div>
        </div>
            </div>
            <div class="col-lg-6">
        <!-- Accesos rápidos -->
        <div class="card">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3">Accesos Rápidos</h6>
                <div class="d-grid gap-2">
                    <a href="{{ route('productos.index') }}" class="btn btn-outline-secondary btn-sm text-start" style="border-radius:8px;">
                        <i class="fas fa-box me-2 text-muted"></i>Gestionar Inventario
                    </a>
                    <a href="{{ route('reportes.index') }}" class="btn btn-outline-secondary btn-sm text-start" style="border-radius:8px;">
                        <i class="fas fa-chart-bar me-2 text-muted"></i>Ver Reportes
                    </a>
                    <a href="{{ route('clientes.index') }}" class="btn btn-outline-secondary btn-sm text-start" style="border-radius:8px;">
                        <i class="fas fa-users me-2 text-muted"></i>Ver Clientes
                    </a>
                    <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary btn-sm text-start" style="border-radius:8px;">
                        <i class="fas fa-th-large me-2 text-muted"></i>Ir al Dashboard
                    </a>
                </div>
            </div>
        </div>
            </div>
        </div>
    </div>

</div>

<!-- ══════════ MODAL: Nuevo Usuario ══════════ -->
<div class="modal fade" id="modalNuevoUsuario" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;border:none;">
            <div class="modal-header" style="border-bottom:1px solid #f3f4f6;padding:20px 24px;">
                <h6 class="modal-title fw-bold">
                    <i class="fas fa-user-plus me-2" style="color:#0891b2;"></i>Nuevo Usuario
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('configuracion.storeUsuario') }}" method="POST">
                @csrf
                <div class="modal-body p-4">

                    @if($errors->any())
                        <div class="alert alert-danger" style="border-radius:10px;font-size:13px;">
                            <ul class="mb-0 ps-3">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="row g-3">
                        <div class="col-12">
                            <label for="nuevoNombre" class="form-label">Nombre completo <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="nuevoNombre" class="form-control" value="{{ old('name') }}"
                                   placeholder="Ej: María García" required>
                        </div>
                        <div class="col-12">
                            <label for="nuevoEmail" class="form-label">Correo electrónico <span class="text-danger">*</span></label>
                            <input type="email" name="email" id="nuevoEmail" class="form-control" value="{{ old('email') }}"
                                   placeholder="usuario@tienda.com" required>
                        </div>
                        <div class="col-md-6">
                            <label for="nuevaPassword" class="form-label">Contraseña <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" name="password" id="nuevaPassword" class="form-control" required minlength="8">
                                <button type="button" class="btn btn-outline-secondary" onclick="togglePass('nuevaPassword','eyeNueva')">
                                    <i class="fas fa-eye" id="eyeNueva" style="font-size:13px;"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="confirmPassword" class="form-label">Confirmar contraseña <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" name="password_confirmation" id="confirmPassword" class="form-control" required>
                                <button type="button" class="btn btn-outline-secondary" onclick="togglePass('confirmPassword','eyeConfirm')">
                                    <i class="fas fa-eye" id="eyeConfirm" style="font-size:13px;"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Roles <span class="text-danger">*</span></label>
                            <div class="d-flex flex-wrap gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="roles[]" value="vendedor" id="nuevoRolVendedor" @checked(in_array('vendedor', old('roles', [])))>
                                    <label class="form-check-label" for="nuevoRolVendedor">🛒 Vendedor</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="roles[]" value="tecnico" id="nuevoRolTecnico" @checked(in_array('tecnico', old('roles', [])))>
                                    <label class="form-check-label" for="nuevoRolTecnico">🔧 Técnico</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="roles[]" value="admin" id="nuevoRolAdmin" @checked(in_array('admin', old('roles', [])))>
                                    <label class="form-check-label" for="nuevoRolAdmin">👑 Administrador</label>
                                </div>
                            </div>
                            <small class="text-muted d-block">Marca varios si la persona hace más de un trabajo (ej. Técnico + Vendedor).</small>
                            @error('roles')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label for="nuevoTelefono" class="form-label">Teléfono</label>
                            <input type="text" name="telefono" id="nuevoTelefono" class="form-control" value="{{ old('telefono') }}"
                                   placeholder="+51 999 999 999">
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-top:1px solid #f3f4f6;padding:16px 24px;">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-save me-2"></i>Crear Usuario
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══════════ MODAL: Editar Usuario ══════════ -->
<div class="modal fade" id="modalEditarUsuario" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;border:none;">
            <div class="modal-header" style="border-bottom:1px solid #f3f4f6;padding:20px 24px;">
                <h6 class="modal-title fw-bold">
                    <i class="fas fa-user-edit me-2" style="color:#0891b2;"></i>Editar Usuario
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="formEditarUsuario" action="" method="POST">
                @csrf @method('PUT')
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-12">
                            <label for="editNombre" class="form-label">Nombre completo <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="editNombre" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label for="editEmail" class="form-label">Correo electrónico <span class="text-danger">*</span></label>
                            <input type="email" name="email" id="editEmail" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label for="editPassword" class="form-label">Nueva contraseña</label>
                            <div class="input-group">
                                <input type="password" name="password" id="editPassword" class="form-control" minlength="8"
                                       placeholder="Dejar vacío para no cambiar">
                                <button type="button" class="btn btn-outline-secondary" onclick="togglePass('editPassword','eyeEdit')">
                                    <i class="fas fa-eye" id="eyeEdit" style="font-size:13px;"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="editConfirmPassword" class="form-label">Confirmar contraseña</label>
                            <input type="password" name="password_confirmation" id="editConfirmPassword" class="form-control"
                                   placeholder="Repetir nueva contraseña">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Roles <span class="text-danger">*</span></label>
                            <div class="d-flex flex-wrap gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="roles[]" value="vendedor" id="editRolVendedor">
                                    <label class="form-check-label" for="editRolVendedor">🛒 Vendedor</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="roles[]" value="tecnico" id="editRolTecnico">
                                    <label class="form-check-label" for="editRolTecnico">🔧 Técnico</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="roles[]" value="admin" id="editRolAdmin">
                                    <label class="form-check-label" for="editRolAdmin">👑 Administrador</label>
                                </div>
                            </div>
                            @error('roles')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label for="editTelefono" class="form-label">Teléfono</label>
                            <input type="text" name="telefono" id="editTelefono" class="form-control">
                        </div>
                        <div class="col-12" id="comisionSection" style="display:none;">
                            <label for="editComision" class="form-label">Comisión (%)</label>
                            <div class="input-group">
                                <input type="number" name="comision_porcentaje" id="editComision" class="form-control" step="0.01" min="0" max="100" placeholder="Ej: 30">
                                <span class="input-group-text">%</span>
                            </div>
                            <div class="form-text">Se calcula automáticamente al entregar una reparación: Ganancia × % / 100</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-top:1px solid #f3f4f6;padding:16px 24px;">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-save me-2"></i>Guardar Cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Botón flotante para crear usuario -->
<button class="btn btn-primary rounded-circle shadow-lg position-fixed"
        style="bottom:28px;right:28px;width:56px;height:56px;z-index:1050;"
        data-bs-toggle="modal" data-bs-target="#modalNuevoUsuario">
    <i class="fas fa-user-plus"></i>
</button>

@endsection

@push('scripts')
<script>
// Auto-actualizar símbolo cuando cambia la moneda
document.addEventListener('DOMContentLoaded', function() {
    var monedaSelect = document.querySelector('select[name="moneda"]');
    var simboloInput = document.getElementById('simbolo_moneda');

    if (monedaSelect && simboloInput) {
        monedaSelect.addEventListener('change', function() {
            var selected = monedaSelect.options[monedaSelect.selectedIndex];
            var simbolo = selected.getAttribute('data-simbolo');
            if (simbolo) {
                simboloInput.value = simbolo;
            }
        });
    }

    // Mostrar hora actual en la zona horaria seleccionada
    var zonaSelect = document.querySelector('select[name="zona_horaria"]');
    var horaActual = document.getElementById('horaActual');

    function actualizarHora() {
        if (zonaSelect && horaActual) {
            var zona = zonaSelect.value;
            try {
                var ahora = new Date();
                var hora = ahora.toLocaleTimeString('es-ES', {
                    timeZone: zona,
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                });
                horaActual.textContent = hora;
            } catch (e) {
                horaActual.textContent = '--:--:--';
            }
        }
    }

    if (zonaSelect && horaActual) {
        zonaSelect.addEventListener('change', actualizarHora);
        actualizarHora();
        setInterval(actualizarHora, 1000);
    }

    // ── Facturación Electrónica: mostrar/ocultar campos ──
    var facturacionSwitch = document.getElementById('facturacion_electronica_activa');
    var camposFacturacion = document.getElementById('campos-facturacion');
    var labelFacturacion  = document.getElementById('label_facturacion');

    if (facturacionSwitch && camposFacturacion && labelFacturacion) {
        facturacionSwitch.addEventListener('change', function() {
            var activa = this.checked;
            camposFacturacion.style.display = activa ? 'block' : 'none';
            labelFacturacion.textContent = activa ? 'Activada' : 'Desactivada';
        });
    }

    // ── Mercado Pago: mostrar/ocultar campos ──
    var mercadopagoSwitch = document.getElementById('mercadopago_activo');
    var camposMercadopago = document.getElementById('campos-mercadopago');
    var labelMercadopago  = document.getElementById('label_mercadopago');

    if (mercadopagoSwitch && camposMercadopago && labelMercadopago) {
        mercadopagoSwitch.addEventListener('change', function() {
            var activo = this.checked;
            camposMercadopago.style.display = activo ? 'block' : 'none';
            labelMercadopago.textContent = activo ? 'Activado' : 'Desactivado';
        });
    }
});

function abrirModalEditar(id, nombre, email, rol, telefono, comision) {
    document.getElementById('editNombre').value   = nombre;
    document.getElementById('editEmail').value    = email;
    var seleccionados = (rol || '').split(',');
    ['editRolVendedor', 'editRolTecnico', 'editRolAdmin'].forEach(function (inputId) {
        var cb = document.getElementById(inputId);
        cb.checked = seleccionados.indexOf(cb.value) !== -1;
    });
    document.getElementById('editTelefono').value = telefono || '';
    document.getElementById('editComision').value = comision || '';
    actualizarComisionSection();
    document.getElementById('formEditarUsuario').action = '/configuracion/usuarios/' + id;
    var modal = new bootstrap.Modal(document.getElementById('modalEditarUsuario'));
    modal.show();
}

function actualizarComisionSection() {
    var tecnico = document.getElementById('editRolTecnico');
    document.getElementById('comisionSection').style.display = (tecnico && tecnico.checked) ? 'block' : 'none';
}

['editRolVendedor', 'editRolTecnico', 'editRolAdmin'].forEach(function (inputId) {
    document.getElementById(inputId).addEventListener('change', actualizarComisionSection);
});

function togglePass(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon  = document.getElementById(iconId);
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

// Auto-open modal si hay errores de validación (al crear)
@if($errors->any())
    document.addEventListener('DOMContentLoaded', function() {
        new bootstrap.Modal(document.getElementById('modalNuevoUsuario')).show();
    });
@endif
</script>
@endpush
