@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')

@php
    $marca = auth()->user()->tenant?->colores() ?? [
        'primario'      => '#0891b2',
        'secundario'    => '#3b82f6',
        'primario_rgba' => 'rgba(8,145,178,.18)',
    ];
@endphp

{{-- ── Encabezado ──────────────────────────────────────────────────── --}}
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h4 class="mb-1 fw-700" style="font-weight:700;">Panel de Control</h4>
        <p class="text-muted mb-0" style="font-size:13px;">
            {{ now()->isoFormat('dddd, D [de] MMMM [de] YYYY') }}
            @if(auth()->user()->esTecnico())
                · <span style="color:#f59e0b;">Técnico</span>
            @elseif(auth()->user()->esVendedor())
                · <span style="color:#06b6d4;">Vendedor</span>
            @else
                · <span style="color:#0891b2;">Administrador</span>
            @endif
        </p>
    </div>
</div>

{{-- ── Accesos Rápidos (solo PC, ≥992px) ───────────────────────────── --}}
<div class="d-none d-lg-block">
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3">
            <a href="{{ route('ventas.create') }}" class="btn btn-success w-100 py-2" style="font-weight:700;">
                <i class="fas fa-cash-register me-1"></i> Nueva Venta
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="{{ route('reparaciones.create') }}" class="btn btn-warning w-100 py-2" style="font-weight:700;">
                <i class="fas fa-tools me-1"></i> Nueva Reparación
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="{{ route('caja.abrir') }}" class="btn btn-info w-100 py-2" style="font-weight:700;">
                <i class="fas fa-cash-register me-1"></i> Abrir Caja
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="{{ route('productos.create') }}" class="btn btn-primary w-100 py-2" style="font-weight:700;">
                <i class="fas fa-box-open me-1"></i> Nuevo Producto
            </a>
        </div>
        @php $urlPantallaTvDash = auth()->user()->tenant?->urlPantalla(); @endphp
        @if($urlPantallaTvDash)
        <div class="col-6 col-md-3">
            <a href="{{ $urlPantallaTvDash }}" target="_blank" rel="noopener" class="btn btn-dark w-100 py-2" style="font-weight:700;">
                <i class="fas fa-tv me-1"></i> Pantalla en TV
            </a>
        </div>
        @endif
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════════════ --}}
{{-- 👑 ADMIN: KPI Cards globales ─────────────────────────────────------ --}}
{{-- ═══════════════════════════════════════════════════════════════════ --}}
@if(auth()->user()->esAdmin() || auth()->user()->esSuperAdmin())
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="kpi-card bg-grad-purple">
            <div class="kpi-icon"><i class="fas fa-dollar-sign"></i></div>
            <div class="kpi-value">{{ formatoMonedaEntero($ventasHoy) }}</div>
            <div class="kpi-label">Ganancia de Hoy</div>
            <span class="kpi-badge">
                <i class="fas fa-calendar-day fa-xs"></i>
                {{ formatoMonedaEntero($ventasMes) }} este mes
            </span>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card bg-grad-pink">
            <div class="kpi-icon"><i class="fas fa-users"></i></div>
            <div class="kpi-value">{{ number_format($totalClientes) }}</div>
            <div class="kpi-label">Clientes Registrados</div>
            <span class="kpi-badge">
                <i class="fas fa-user-plus fa-xs"></i>
                +{{ $clientesNuevosMes }} este mes
            </span>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card bg-grad-cyan">
            <div class="kpi-icon"><i class="fas fa-box"></i></div>
            <div class="kpi-value">{{ number_format($totalProductos) }}</div>
            <div class="kpi-label">Productos en Stock</div>
            <span class="kpi-badge">
                @if($stockBajo > 0)
                    <i class="fas fa-exclamation-triangle fa-xs"></i>
                    {{ $stockBajo }} con stock bajo
                @else
                    <i class="fas fa-check fa-xs"></i>
                    Stock óptimo
                @endif
            </span>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card bg-grad-green">
            <div class="kpi-icon"><i class="fas fa-tools"></i></div>
            <div class="kpi-value">{{ $reparacionesPendientes }}</div>
            <div class="kpi-label">Reparaciones Activas</div>
            <span class="kpi-badge">
                <i class="fas fa-check-circle fa-xs"></i>
                {{ $reparacionesListas }} listas para entregar
            </span>
        </div>
    </div>
</div>
@endif

{{-- ═══════════════════════════════════════════════════════════════════ --}}
{{-- 🛒 VENDEDOR: KPI Cards de sus ventas ─────────────────────────------ --}}
{{-- ═══════════════════════════════════════════════════════════════════ --}}
@if(auth()->user()->esVendedor())
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="kpi-card bg-grad-purple">
            <div class="kpi-icon"><i class="fas fa-dollar-sign"></i></div>
            <div class="kpi-value">{{ formatoMonedaEntero($ventasHoy) }}</div>
            <div class="kpi-label">Mis Ventas de Hoy</div>
            <span class="kpi-badge">
                <i class="fas fa-calendar-day fa-xs"></i>
                {{ formatoMonedaEntero($ventasMes) }} este mes
            </span>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card bg-grad-pink">
            <div class="kpi-icon"><i class="fas fa-shopping-cart"></i></div>
            <div class="kpi-value">{{ $misVentasHoy }}</div>
            <div class="kpi-label">Ventas Realizadas Hoy</div>
            <span class="kpi-badge">
                <i class="fas fa-chart-line fa-xs"></i>
                {{ $misVentasMes }} este mes
            </span>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card bg-grad-cyan">
            <div class="kpi-icon"><i class="fas fa-trend-up"></i></div>
            <div class="kpi-value">{{ number_format(abs($crecimientoVentas), 1) }}%</div>
            <div class="kpi-label">Crecimiento Mensual</div>
            <span class="kpi-badge">
                @if($crecimientoVentas >= 0)
                    <i class="fas fa-arrow-up fa-xs"></i> vs mes anterior
                @else
                    <i class="fas fa-arrow-down fa-xs"></i> vs mes anterior
                @endif
            </span>
        </div>
    </div>
</div>
@endif

{{-- ═══════════════════════════════════════════════════════════════════ --}}
{{-- 🔧 TÉCNICO: KPI Cards de sus reparaciones y comisiones ─────────---- --}}
{{-- ═══════════════════════════════════════════════════════════════════ --}}
@if(auth()->user()->esTecnico())
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="kpi-card bg-grad-green">
            <div class="kpi-icon"><i class="fas fa-tools"></i></div>
            <div class="kpi-value">{{ $misReparacionesActivas }}</div>
            <div class="kpi-label">Mis Reparaciones Activas</div>
            <span class="kpi-badge">
                <i class="fas fa-check-circle fa-xs"></i>
                {{ $misReparacionesListas }} listas para entregar
            </span>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card bg-grad-cyan">
            <div class="kpi-icon"><i class="fas fa-clipboard-check"></i></div>
            <div class="kpi-value">{{ $misEntregadasMes }}</div>
            <div class="kpi-label">Entregadas este Mes</div>
            <span class="kpi-badge">
                <i class="fas fa-calendar-day fa-xs"></i>
                Reparaciones completadas
            </span>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card bg-grad-orange">
            <div class="kpi-icon"><i class="fas fa-clock"></i></div>
            <div class="kpi-value">{{ \App\Helpers\FormatoHelper::moneda($misComisionesPendientes) }}</div>
            <div class="kpi-label">Comisiones Pendientes</div>
            <span class="kpi-badge">
                <i class="fas fa-info-circle fa-xs"></i>
                Por cobrar
            </span>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card bg-grad-green">
            <div class="kpi-icon"><i class="fas fa-money-bill-wave"></i></div>
            <div class="kpi-value">{{ \App\Helpers\FormatoHelper::moneda($misComisionesPagadas) }}</div>
            <div class="kpi-label">Comisiones Cobradas</div>
            <span class="kpi-badge">
                <i class="fas fa-check-circle fa-xs"></i>
                Ya pagadas
            </span>
        </div>
    </div>
</div>

{{-- Tarjeta de mi porcentaje de comisión (solo lectura) --}}
<div class="card mb-4" style="border:1px solid #fef3c7; background:linear-gradient(135deg,#fffbeb,#fef3c7);">
    <div class="card-body p-4 d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div class="d-flex align-items-center gap-3">
            <div style="width:48px;height:48px;background:#f59e0b;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fas fa-percentage" style="color:#fff;font-size:20px;"></i>
            </div>
            <div>
                <div style="font-size:13px;color:#92400e;font-weight:500;">Mi porcentaje de comisión</div>
                <div style="font-size:24px;font-weight:700;color:#78350f;">
                    {{ $miComisionPorcentaje !== null ? number_format($miComisionPorcentaje, 1) . '%' : 'No asignado' }}
                </div>
            </div>
        </div>
        <div style="font-size:12px;color:#92400e;max-width:300px;text-align:right;">
            <i class="fas fa-info-circle me-1"></i>
            Se calcula automáticamente al entregar una reparación: Ganancia × % / 100
        </div>
    </div>
</div>
@endif

{{-- ═══════════════════════════════════════════════════════════════════ --}}
{{-- Gráficas (ADMIN: globales, VENDEDOR: sus ventas) ─────────────────-- --}}
{{-- ═══════════════════════════════════════════════════════════════════ --}}
@if(auth()->user()->esAdmin() || auth()->user()->esSuperAdmin() || auth()->user()->esVendedor())
@if($diasSemana->isNotEmpty())
<div class="row g-3 mb-4">
    {{-- Ventas por día --}}
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-body p-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div>
                        <h6 class="mb-0 fw-600" style="font-weight:600;">
                            @if(auth()->user()->esVendedor())Mis Ventas@else Ventas @endif — Últimos 7 días
                        </h6>
                        <span class="text-muted" style="font-size:12px;">Ingresos diarios</span>
                    </div>
                    @if($crecimientoVentas >= 0)
                        <span class="badge" style="background:#d1fae5; color:#065f46; font-size:12px; border-radius:20px; padding:5px 12px;">
                            <i class="fas fa-arrow-up fa-xs"></i> {{ number_format(abs($crecimientoVentas), 1) }}% vs mes anterior
                        </span>
                    @else
                        <span class="badge" style="background:#fee2e2; color:#991b1b; font-size:12px; border-radius:20px; padding:5px 12px;">
                            <i class="fas fa-arrow-down fa-xs"></i> {{ number_format(abs($crecimientoVentas), 1) }}% vs mes anterior
                        </span>
                    @endif
                </div>
                <canvas id="chartVentasDias" height="90"></canvas>
            </div>
        </div>
    </div>

    {{-- Top Productos --}}
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-body p-4">
                <h6 class="mb-1 fw-600" style="font-weight:600;">Top Productos</h6>
                <p class="text-muted mb-3" style="font-size:12px;">Más vendidos este mes</p>

                @forelse($topProductos as $i => $prod)
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div style="width:28px; height:28px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; color:#fff;
                            background: {{ [0 => "linear-gradient(135deg,{$marca['primario_puro']},{$marca['secundario_puro']})", 'linear-gradient(135deg,#3b82f6,#2563eb)', 'linear-gradient(135deg,#06b6d4,#0284c7)', 'linear-gradient(135deg,#10b981,#059669)', 'linear-gradient(135deg,#f59e0b,#d97706)'][$i] }};">
                            {{ $i + 1 }}
                        </div>
                        <div class="flex-1" style="min-width:0; flex:1;">
                            <div style="font-size:13px; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                {{ $prod->nombre }}
                            </div>
                            <div style="font-size:11px; color:#9ca3af;">{{ $prod->total_vendido }} unidades</div>
                        </div>
                        <div style="font-size:13px; font-weight:600; color:#0f172a; white-space:nowrap;">
                            {{ \App\Helpers\FormatoHelper::moneda($prod->ingresos) }}
                        </div>
                    </div>
                @empty
                    <div class="text-center text-muted py-4" style="font-size:13px;">
                        <i class="fas fa-box-open fa-2x mb-2 d-block opacity-50"></i>
                        Sin ventas este mes
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endif
@endif

{{-- ═══════════════════════════════════════════════════════════════════ --}}
{{-- Tablas inferiores ──────────────────────────────────────────────── --}}
{{-- ═══════════════════════════════════════════════════════════════════ --}}

{{-- ADMIN: Últimas ventas + Reparaciones recientes --}}
@if(auth()->user()->esAdmin() || auth()->user()->esSuperAdmin())
<div class="row g-3">
    {{-- Últimas ventas (PRIMERO - más prominente) --}}
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h6 class="mb-0 fw-600" style="font-weight:600;">Últimas Ventas</h6>
                    <a href="{{ route('ventas.index') }}" class="btn btn-sm btn-outline-primary" style="font-size:12px;">
                        Ver todas <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>N° Venta</th>
                                <th>Cliente</th>
                                <th>Método</th>
                                <th>Total</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($ultimasVentas as $venta)
                            <tr>
                                <td>
                                    <a href="{{ route('ventas.show', $venta) }}"
                                       style="color:var(--accent1); font-weight:500; font-size:13px; text-decoration:none;">
                                        {{ $venta->numero_venta }}
                                    </a>
                                    <div style="font-size:11px; color:#9ca3af;">
                                        {{ $venta->fecha_venta->diffForHumans() }}
                                    </div>
                                </td>
                                <td style="font-size:13px;">
                                    {{ $venta->cliente->nombre_completo ?? '—' }}
                                </td>
                                <td>
                                    <span style="font-size:12px; text-transform:capitalize;">
                                        {{ $venta->metodo_pago }}
                                    </span>
                                </td>
                                <td>
                                    <span style="font-size:13px; font-weight:600;">
                                        {{ \App\Helpers\FormatoHelper::moneda($venta->total) }}
                                    </span>
                                </td>
                                <td>
                                    @php
                                        $colores = ['completada'=>'success','pendiente'=>'warning','cancelada'=>'danger','devuelta'=>'secondary'];
                                        $color = $colores[$venta->estado] ?? 'secondary';
                                    @endphp
                                    <span class="badge bg-{{ $color }}-subtle text-{{ $color }}"
                                          style="font-size:11px; border-radius:20px; padding:4px 10px;">
                                        {{ ucfirst($venta->estado) }}
                                    </span>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4" style="font-size:13px;">
                                    <i class="fas fa-shopping-cart fa-2x mb-2 d-block opacity-40"></i>
                                    Sin ventas registradas
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Reparaciones recientes (SEGUNDO) --}}
    <div class="col-lg-4">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h6 class="mb-0 fw-600" style="font-weight:600;">Reparaciones Recientes</h6>
                    <a href="{{ route('reparaciones.index') }}" class="btn btn-sm btn-outline-primary" style="font-size:12px;">
                        Ver todas <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>

                @forelse($ultimasReparaciones as $rep)
                <div class="d-flex align-items-start gap-3 mb-3 pb-3 border-bottom">
                    @php
                        $bgEstado = [
                            'recibido'           => '#cffafe',
                            'en_diagnostico'     => '#e0f2fe',
                            'esperando_repuesto' => '#fef9c3',
                            'en_reparacion'      => '#dbeafe',
                            'listo'              => '#d1fae5',
                            'entregado'          => '#f3f4f6',
                            'no_reparable'       => '#fee2e2',
                        ];
                        $icEstado = [
                            'recibido'           => 'fa-inbox',
                            'en_diagnostico'     => 'fa-search',
                            'esperando_repuesto' => 'fa-clock',
                            'en_reparacion'      => 'fa-wrench',
                            'listo'              => 'fa-check',
                            'entregado'          => 'fa-box',
                            'no_reparable'       => 'fa-times',
                        ];
                        $bg = $bgEstado[$rep->estado] ?? '#f3f4f6';
                        $ic = $icEstado[$rep->estado] ?? 'fa-tools';
                    @endphp
                    <div style="width:36px; height:36px; border-radius:10px; background:{{ $bg }};
                                display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <i class="fas {{ $ic }}" style="font-size:14px; color:#6b7280;"></i>
                    </div>
                    <div class="flex-1" style="min-width:0;">
                        <div style="font-size:13px; font-weight:500;">
                            {{ $rep->dispositivo }}
                            @if($rep->marca) <span style="color:#9ca3af;">— {{ $rep->marca }}</span> @endif
                        </div>
                        <div style="font-size:11px; color:#9ca3af;">
                            {{ $rep->cliente->nombre_completo ?? '—' }} · {{ $rep->numero_orden }}
                        </div>
                    </div>
                    <a href="{{ route('reparaciones.show', $rep) }}"
                       class="btn btn-sm" style="font-size:11px; padding:3px 8px; border-radius:6px;
                       background:#f3f4f6; color:#374151; text-decoration:none;">
                        Ver
                    </a>
                </div>
                @empty
                <div class="text-center text-muted py-4" style="font-size:13px;">
                    <i class="fas fa-tools fa-2x mb-2 d-block opacity-40"></i>
                    Sin reparaciones registradas
                </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endif

{{-- VENDEDOR: Solo sus últimas ventas --}}
@if(auth()->user()->esVendedor())
<div class="row g-3">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h6 class="mb-0 fw-600" style="font-weight:600;">Mis Últimas Ventas</h6>
                    <a href="{{ route('ventas.index') }}" class="btn btn-sm btn-outline-primary" style="font-size:12px;">
                        Ver todas <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>N° Venta</th>
                                <th>Cliente</th>
                                <th>Método</th>
                                <th>Total</th>
                                <th>Fecha</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($ultimasVentas as $venta)
                            <tr>
                                <td>
                                    <a href="{{ route('ventas.show', $venta) }}"
                                       style="color:var(--accent3); font-weight:500; font-size:13px; text-decoration:none;">
                                        {{ $venta->numero_venta }}
                                    </a>
                                </td>
                                <td style="font-size:13px;">
                                    {{ $venta->cliente->nombre_completo ?? '—' }}
                                </td>
                                <td>
                                    <span style="font-size:12px; text-transform:capitalize;">
                                        {{ $venta->metodo_pago }}
                                    </span>
                                </td>
                                <td>
                                    <span style="font-size:13px; font-weight:600;">
                                        {{ \App\Helpers\FormatoHelper::moneda($venta->total) }}
                                    </span>
                                </td>
                                <td style="font-size:12px; color:#9ca3af;">
                                    {{ $venta->fecha_venta->format('d/m/Y H:i') }}
                                </td>
                                <td>
                                    @php
                                        $colores = ['completada'=>'success','pendiente'=>'warning','cancelada'=>'danger','devuelta'=>'secondary'];
                                        $color = $colores[$venta->estado] ?? 'secondary';
                                    @endphp
                                    <span class="badge bg-{{ $color }}-subtle text-{{ $color }}"
                                          style="font-size:11px; border-radius:20px; padding:4px 10px;">
                                        {{ ucfirst($venta->estado) }}
                                    </span>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4" style="font-size:13px;">
                                    <i class="fas fa-shopping-cart fa-2x mb-2 d-block opacity-40"></i>
                                    No has realizado ventas aún
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

{{-- TÉCNICO: Solo sus últimas reparaciones --}}
@if(auth()->user()->esTecnico())
<div class="row g-3">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h6 class="mb-0 fw-600" style="font-weight:600;">Mis Reparaciones Recientes</h6>
                    <a href="{{ route('reparaciones.index') }}" class="btn btn-sm btn-outline-primary" style="font-size:12px;">
                        Ver todas <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>

                @forelse($ultimasReparaciones as $rep)
                <div class="d-flex align-items-start gap-3 mb-3 pb-3 border-bottom">
                    @php
                        $bgEstado = [
                            'recibido'           => '#cffafe',
                            'en_diagnostico'     => '#e0f2fe',
                            'esperando_repuesto' => '#fef9c3',
                            'en_reparacion'      => '#dbeafe',
                            'listo'              => '#d1fae5',
                            'entregado'          => '#f3f4f6',
                            'no_reparable'       => '#fee2e2',
                        ];
                        $icEstado = [
                            'recibido'           => 'fa-inbox',
                            'en_diagnostico'     => 'fa-search',
                            'esperando_repuesto' => 'fa-clock',
                            'en_reparacion'      => 'fa-wrench',
                            'listo'              => 'fa-check',
                            'entregado'          => 'fa-box',
                            'no_reparable'       => 'fa-times',
                        ];
                        $bg = $bgEstado[$rep->estado] ?? '#f3f4f6';
                        $ic = $icEstado[$rep->estado] ?? 'fa-tools';
                    @endphp
                    <div style="width:36px; height:36px; border-radius:10px; background:{{ $bg }};
                                display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <i class="fas {{ $ic }}" style="font-size:14px; color:#6b7280;"></i>
                    </div>
                    <div class="flex-1" style="min-width:0;">
                        <div style="font-size:13px; font-weight:500;">
                            {{ $rep->dispositivo }}
                            @if($rep->marca) <span style="color:#9ca3af;">— {{ $rep->marca }}</span> @endif
                        </div>
                        <div style="font-size:11px; color:#9ca3af;">
                            {{ $rep->cliente->nombre_completo ?? '—' }} · {{ $rep->numero_orden }}
                        </div>
                    </div>
                    <div class="text-end" style="flex-shrink:0;">
                        @if(!$rep->comision_pagada && $rep->estado === 'entregado')
                            <span style="background:#fee2e2;color:#991b1b;border-radius:20px;padding:3px 10px;font-size:11px;">
                                Comisión pendiente
                            </span>
                        @elseif($rep->comision_pagada)
                            <span style="background:#d1fae5;color:#065f46;border-radius:20px;padding:3px 10px;font-size:11px;">
                                Comisión pagada
                            </span>
                        @endif
                        <a href="{{ route('reparaciones.show', $rep) }}"
                           class="btn btn-sm mt-1" style="font-size:11px; padding:3px 8px; border-radius:6px;
                           background:#f3f4f6; color:#374151; text-decoration:none;">
                            Ver
                        </a>
                    </div>
                </div>
                @empty
                <div class="text-center text-muted py-4" style="font-size:13px;">
                    <i class="fas fa-tools fa-2x mb-2 d-block opacity-40"></i>
                    No tienes reparaciones asignadas
                </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endif

@endsection

@push('scripts')
@if($diasSemana->isNotEmpty())
<script>
// ── Gráfica de ventas por día ───────────────────────────────────────────
const diasLabels  = @json($diasSemana->pluck('fecha'));
const diasTotales = @json($diasSemana->pluck('total'));

const ctxDias = document.getElementById('chartVentasDias').getContext('2d');

const brandStyles = getComputedStyle(document.documentElement);
const brandP = (brandStyles.getPropertyValue('--accent1') || '').trim() || '#0891b2';
const brandS = (brandStyles.getPropertyValue('--accent2') || '').trim() || '#3b82f6';

const gradientFill = ctxDias.createLinearGradient(0, 0, 0, 200);
gradientFill.addColorStop(0, brandP + '4D');
gradientFill.addColorStop(1, brandS + '08');

new Chart(ctxDias, {
    type: 'line',
    data: {
        labels: diasLabels,
        datasets: [{
            label: 'Ventas',
            data: diasTotales,
            borderColor: brandP,
            backgroundColor: gradientFill,
            borderWidth: 2.5,
            fill: true,
            tension: 0.4,
            pointBackgroundColor: brandP,
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointRadius: 5,
            pointHoverRadius: 7,
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#0f172a',
                titleFont: { family: 'Poppins', size: 12 },
                bodyFont: { family: 'Poppins', size: 13 },
                callbacks: {
                    label: ctx => ' ' + ctx.parsed.y.toLocaleString('es-PE', {minimumFractionDigits: 2})
                }
            }
        },
        scales: {
            x: {
                grid: { display: false },
                ticks: { font: { family: 'Poppins', size: 11 }, color: '#9ca3af' }
            },
            y: {
                grid: { color: '#f3f4f6' },
                ticks: {
                    font: { family: 'Poppins', size: 11 },
                    color: '#9ca3af',
                    callback: v => v.toLocaleString('es-PE')
                }
            }
        }
    }
});
</script>
@endif
@endpush
