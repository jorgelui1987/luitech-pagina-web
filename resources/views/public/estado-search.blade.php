@extends('layouts.public')

@section('title', 'Consulta Express')

@php
    // REGLA SAAS: el portal puede venir con tienda (/estado/{slug}) o genérico.
    // Con tienda, el formulario conserva slugTienda para aislar la búsqueda.
    // OJO: $tiendaPortal puede no venir (errores legacy): isset para no romper.
    $nombrePortal = isset($tiendaPortal) ? ($tiendaPortal->nombre_tienda ?? null) : null;
@endphp

@section('content')
<section class="lp-hero">
    <div class="lp-container lp-hero-grid">
        <div>
            <span class="lp-hero-chip"><i class="fa-solid fa-location-dot"></i> {{ $nombrePortal ?? 'Atención en local' }}</span>
            <h1 class="lp-hero-title">Tu tecnología en manos de <span class="lp-hero-grad">expertos</span>.</h1>
            <p class="lp-hero-text">Consulta el estado de tu reparación en segundos: digita el código de tu boleta y revisa el avance paso a paso de tu equipo, sin llamadas ni esperas.</p>
            <div class="lp-hero-actions">
                <a href="#consulta" class="lp-btn lp-btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Consultar mi orden</a>
                <a href="#como-funciona" class="lp-btn lp-btn-ghost"><i class="fa-solid fa-circle-info"></i> ¿Cómo funciona?</a>
            </div>
        </div>

        <div class="lp-card" id="consulta">
            <span class="lp-card-corner">Rápido</span>
            <h3><i class="fa-solid fa-route"></i> Consulta Express{{ $nombrePortal ? ' · ' . $nombrePortal : '' }}</h3>
            <p>Ingresa el código de reparación que aparece en tu boleta de recepción y mira el avance actual de tu equipo.</p>
            @if(!empty($error))
                <p class="lp-error">{{ $error }}</p>
            @endif
            <form method="GET" action="{{ route('reparaciones.public-status.buscar') }}">
                @if(isset($slugTienda) && $slugTienda)
                    <input type="hidden" name="slugTienda" value="{{ $slugTienda }}">
                @endif
                <label class="lp-label" for="express-code">Código de reparación</label>
                <div class="lp-input-group">
                    <span class="lp-input-prefix">RPT-</span>
                    <input class="lp-input" type="text" id="express-code" name="numero_orden"
                           value="{{ isset($buscado) ? preg_replace('/[^0-9A-Za-z\-]/', '', $buscado) : '' }}"
                           placeholder="001024" inputmode="numeric" autocomplete="off" autofocus required>
                </div>
                <button type="submit" class="lp-btn lp-btn-primary lp-btn-block"><i class="fa-solid fa-search"></i> Consultar estado</button>
                <p class="lp-hint">El código completo se ve así: <strong>RPT-001024</strong>. También puedes pegarlo completo.</p>
            </form>
        </div>
    </div>
</section>
<section id="como-funciona" class="lp-section">
    <div class="lp-container">
        <div class="lp-section-head">
            <span class="lp-section-chip">Transparencia total</span>
            <h2>Seguimiento de reparación</h2>
            <p>Revisa paso a paso el diagnóstico, el proceso de trabajo técnico y las pruebas de calidad de tu dispositivo.</p>
        </div>
        <div class="lp-features">
            <div class="lp-feature">
                <div class="lp-feature-ico"><i class="fa-solid fa-receipt"></i></div>
                <span class="lp-feature-n">PASO 1</span>
                <h4>Deja tu equipo y guarda tu código</h4>
                <p>Al recibir tu equipo entregamos una boleta con el código de la orden. Con ese código consultas el avance cuando quieras.</p>
            </div>
            <div class="lp-feature">
                <div class="lp-feature-ico"><i class="fa-solid fa-stethoscope"></i></div>
                <span class="lp-feature-n">PASO 2</span>
                <h4>Diagnóstico transparente</h4>
                <p>Registramos la falla, el diagnóstico técnico y el presupuesto. Todo queda reflejado en tu consulta en línea.</p>
            </div>
            <div class="lp-feature">
                <div class="lp-feature-ico"><i class="fa-solid fa-bell"></i></div>
                <span class="lp-feature-n">PASO 3</span>
                <h4>Aviso de retiro</h4>
                <p>Cuando tu equipo esté listo te notificamos. Presenta tu boleta o el código de la orden en el mesón para retirarlo.</p>
            </div>
        </div>
    </div>
</section>

<section class="lp-section lp-section-alt">
    <div class="lp-container">
        <div class="lp-section-head">
            <span class="lp-section-chip">Especialidades</span>
            <h2>Lo que reparamos</h2>
        </div>
        <div class="lp-features">
            <div class="lp-feature">
                <div class="lp-feature-ico"><i class="fa-solid fa-mobile-screen-button"></i></div>
                <h4>Pantallas y cristales</h4>
                <p>Reemplazo de pantallas OLED/IPS y vidrios para smartphones y tablets.</p>
            </div>
            <div class="lp-feature">
                <div class="lp-feature-ico"><i class="fa-solid fa-battery-full"></i></div>
                <h4>Baterías y carga</h4>
                <p>Cambio de baterías, puertos de carga y fallas de energía.</p>
            </div>
            <div class="lp-feature">
                <div class="lp-feature-ico"><i class="fa-solid fa-microchip"></i></div>
                <h4>Placas y software</h4>
                <p>Reparación de placa, recuperación de software y sistemas.</p>
            </div>
        </div>
    </div>
</section>
@endsection

@push('scripts')
    // Solo caracteres válidos en el código express
    const lpCode = document.getElementById('express-code');
    if (lpCode) {
        lpCode.addEventListener('input', () => {
            lpCode.value = lpCode.value.replace(/[^0-9A-Za-z-]/g, '').toUpperCase();
        });
    }
@endpush