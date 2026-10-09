@extends('layouts.saas')

@section('title', 'LUITECH · Software para tiendas de celulares y servicio técnico')

@php
    // Planes SaaS desde BD (editables en /superadmin/planes-precios), fallback a CLP.
    $planesSaaS = $planes ?? \App\Models\PlanPrecio::getPlanesActivos();
    $precio = function($key, $def) use ($planesSaaS) {
        $p = $planesSaaS[$key] ?? null;
        if (!$p) return $def;
        if (is_object($p) && method_exists($p, 'precioFormateado')) return $p->precioFormateado();
        if (is_object($p) && isset($p->precioFormateado) && is_callable($p->precioFormateado)) return call_user_func($p->precioFormateado);
        return $def;
    };
@endphp

@section('content')
<section class="lp-hero">
    <div class="lp-container lp-hero-grid">
        <div>
            <span class="lp-hero-chip"><i class="fa-solid fa-microchip"></i> Software para tiendas · Chile</span>
            <h1 class="lp-hero-title">Tu tienda de celulares, <span class="lp-hero-grad">gestionada sola</span>.</h1>
            <p class="lp-hero-text">Ventas, inventario, reparaciones con código RPT y TV de sala, comisiones, reportes y WhatsApp. Todo en un solo sistema, sin planillas ni cuadernos.</p>
            <div class="lp-hero-actions">
                <a href="{{ route('registro.tenant') }}" class="lp-btn lp-btn-primary"><i class="fa-solid fa-rocket"></i> Probar gratis</a>
                <a href="#demo" class="lp-btn lp-btn-ghost"><i class="fa-solid fa-eye"></i> Ver demo en vivo</a>
            </div>
        </div>
        <div class="lp-card">
            <span class="lp-card-corner">Gratis</span>
            <h3><i class="fa-solid fa-rocket"></i> Crea tu tienda en 2 minutos</h3>
            <p>Sin tarjeta. Sin instalación. Entra con tu correo y empieza a vender hoy.</p>
            <div style="display:flex;flex-direction:column;gap:10px;font-size:13.5px;color:var(--muted);margin-bottom:18px;">
                <span><i class="fa-solid fa-check" style="color:var(--emerald);margin-right:8px;"></i> Hasta 3 usuarios y 50 productos gratis</span>
                <span><i class="fa-solid fa-check" style="color:var(--emerald);margin-right:8px;"></i> Reparaciones con seguimiento RPT + QR</span>
                <span><i class="fa-solid fa-check" style="color:var(--emerald);margin-right:8px;"></i> Mini-web pública de tu tienda /t/tu-tienda</span>
                <span><i class="fa-solid fa-check" style="color:var(--emerald);margin-right:8px;"></i> TV sala de espera + avisos WhatsApp</span>
            </div>
            <a href="{{ route('registro.tenant') }}" class="lp-btn lp-btn-primary lp-btn-block"><i class="fa-solid fa-user-plus"></i> Registrar mi tienda gratis</a>
            <p class="lp-hint">¿Ya tienes cuenta? <a href="{{ route('login') }}" style="color:var(--cyan);font-weight:700;">Ingresa aquí</a></p>
        </div>
    </div>
</section>
<section class="lp-strip" aria-label="Beneficios">
    <div class="lp-container lp-strip-inner">
        <span class="lp-strip-item"><i class="fa-solid fa-bolt"></i> Activo en 2 minutos</span>
        <span class="lp-strip-item"><i class="fa-solid fa-shield-halved"></i> Datos aislados por tienda</span>
        <span class="lp-strip-item"><i class="fa-brands fa-whatsapp"></i> Avisos por WhatsApp</span>
        <span class="lp-strip-item"><i class="fa-solid fa-xmark"></i> Sin contratos forzosos</span>
    </div>
</section>

<section id="funciones" class="lp-section">
    <div class="lp-container">
        <div class="lp-section-head">
            <span class="lp-section-chip">Todo incluido</span>
            <h2>Un sistema para toda tu tienda</h2>
            <p>Lo que hoy haces en 4 cuadernos y 3 apps, aqui en un solo panel.</p>
        </div>
        <div class="lp-features">
            <div class="lp-feature">
                <div class="lp-feature-ico"><i class="fa-solid fa-cash-register"></i></div>
                <h4>Ventas e inventario</h4>
                <p>Vende en segundos, controla stock, alertas de stock bajo y tickets con IVA chileno.</p>
            </div>
            <div class="lp-feature">
                <div class="lp-feature-ico"><i class="fa-solid fa-screwdriver-wrench"></i></div>
                <h4>Reparaciones RPT</h4>
                <p>Ordenes con fotos, firma digital y seguimiento con codigo + QR.</p>
            </div>
            <div class="lp-feature">
                <div class="lp-feature-ico"><i class="fa-solid fa-globe"></i></div>
                <h4>Mini-web de tu tienda</h4>
                <p>Cada tienda tiene su pagina /t/tu-tienda con cotizador y reservas.</p>
            </div>
            <div class="lp-feature">
                <div class="lp-feature-ico"><i class="fa-solid fa-tv"></i></div>
                <h4>TV sala de espera</h4>
                <p>Pantalla /pantalla/tu-tienda con turnos en vivo y codigos enmascarados.</p>
            </div>
            <div class="lp-feature">
                <div class="lp-feature-ico"><i class="fa-solid fa-hand-holding-dollar"></i></div>
                <h4>Comisiones y caja</h4>
                <p>Comisiones por vendedor, caja diaria, gastos fijos y cierre de turno.</p>
            </div>
            <div class="lp-feature">
                <div class="lp-feature-ico"><i class="fa-solid fa-chart-line"></i></div>
                <h4>Reportes y Excel</h4>
                <p>Reportes financieros, exportacion a Excel y respaldo de tu info.</p>
            </div>
        </div>
    </div>
</section>

<section id="demo" class="lp-section lp-section-alt">
    <div class="lp-container">
        <div class="lp-section-head">
            <span class="lp-section-chip">Demo viva</span>
            <h2>Mira lo que vera tu cliente</h2>
            <p>Nuestro propio taller usa este mismo sistema. Asi se vera tu tienda.</p>
        </div>
        <div class="lp-features">
            <div class="lp-feature">
                <div class="lp-feature-ico"><i class="fa-solid fa-globe"></i></div>
                <h4>Mini-web publica</h4>
                <p>Cotizador online, agendamiento por WhatsApp y resenas.</p>
            </div>
            <div class="lp-feature">
                <div class="lp-feature-ico"><i class="fa-solid fa-magnifying-glass"></i></div>
                <h4>Seguimiento RPT</h4>
                <p>Tu cliente pega su codigo RPT-001024 y ve el avance sin llamarte.</p>
            </div>
            <div class="lp-feature">
                <div class="lp-feature-ico"><i class="fa-solid fa-tv"></i></div>
                <h4>TV sala de espera</h4>
                <p>Turnos en vivo en tu local, sin mostrar datos privados.</p>
            </div>
        </div>
    </div>
</section>

<section id="planes" class="lp-section">
    <div class="lp-container">
        <div class="lp-section-head">
            <span class="lp-section-chip">Planes y precios</span>
            <h2>Empieza gratis y crece a tu ritmo</h2>
            <p>Sin contratos forzosos. Precios en pesos chilenos.</p>
        </div>
        <div class="lp-pricing">
            <div class="lp-plan">
                <span class="lp-plan-name">Gratis</span>
                <div class="lp-plan-price">{{ $precio('gratis', '$0') }} <small>/mes · para siempre</small></div>
                <p class="lp-plan-desc">Para probar y empezar a vender hoy.</p>
                <ul>
                    <li><i class="fa-solid fa-check"></i> Hasta 3 usuarios</li>
                    <li><i class="fa-solid fa-check"></i> Hasta 50 productos</li>
                    <li><i class="fa-solid fa-check"></i> Ventas + reparaciones basicas</li>
                    <li><i class="fa-solid fa-check"></i> Mini-web /t/tu-tienda</li>
                </ul>
                <a href="{{ route('registro.tenant') }}" class="lp-btn lp-btn-ghost lp-btn-block">Comenzar gratis</a>
            </div>
            <div class="lp-plan lp-plan-pop is-popular">
                <span class="lp-plan-badge">Mas popular</span>
                <span class="lp-plan-name">Profesional</span>
                <div class="lp-plan-price">{{ $precio('profesional', '$19.990') }} <small>/mes</small></div>
                <p class="lp-plan-desc">Para tiendas en crecimiento.</p>
                <ul>
                    <li><i class="fa-solid fa-check"></i> Hasta 15 usuarios</li>
                    <li><i class="fa-solid fa-check"></i> Hasta 1.000 productos</li>
                    <li><i class="fa-solid fa-check"></i> Todo: ventas, RPT, TV sala, WhatsApp</li>
                    <li><i class="fa-solid fa-check"></i> Reportes avanzados + Excel</li>
                    <li><i class="fa-solid fa-check"></i> Soporte prioritario</li>
                </ul>
                <a href="{{ route('registro.tenant') }}" class="lp-btn lp-btn-primary lp-btn-block">Probar 14 dias gratis</a>
            </div>
            <div class="lp-plan">
                <span class="lp-plan-name">Empresarial</span>
                <div class="lp-plan-price">{{ $precio('empresarial', '$39.990') }} <small>/mes</small></div>
                <p class="lp-plan-desc">Para cadenas y alto volumen.</p>
                <ul>
                    <li><i class="fa-solid fa-check"></i> Usuarios y productos ilimitados</li>
                    <li><i class="fa-solid fa-check"></i> Sucursales multiples</li>
                    <li><i class="fa-solid fa-check"></i> Soporte 24/7 + capacitacion</li>
                </ul>
                <a href="{{ route('registro.tenant') }}" class="lp-btn lp-btn-ghost lp-btn-block">Hablar con ventas</a>
            </div>
        </div>
        <p class="lp-hint lp-plans-note">Tambien tenemos el plan Basico ({{ $precio('basico', '$9.990') }}/mes · hasta 5 usuarios y 200 productos) — lo puedes elegir al registrarte. <a href="{{ route('planes') }}" style="color:var(--cyan);font-weight:700;">Ver comparativa completa</a></p>
    </div>
</section>

<section id="faq-saas" class="lp-section lp-section-alt">
    <div class="lp-container">
        <div class="lp-section-head">
            <span class="lp-section-chip">Preguntas frecuentes</span>
            <h2>Antes de crear tu tienda</h2>
            <p>Lo que mas nos preguntan los duenos antes de probar.</p>
        </div>
        <div class="lp-faq">
            <div class="lp-card lp-tool" data-group="faq-saas">
                <button type="button" class="lp-tool-head" aria-expanded="false" aria-controls="faq-s1" data-open-text="Ver respuesta">
                    <span class="lp-tool-title"><i class="fa-solid fa-circle-question"></i> Necesito instalar algo o pagar para empezar?</span>
                    <span class="lp-tool-toggle"><i class="fa-solid fa-chevron-down"></i></span>
                </button>
                <div class="lp-tool-body" id="faq-s1"><p>No. Es 100% web. El plan Gratis no pide tarjeta y lo activas en 2 minutos desde /registro.</p></div>
            </div>
            <div class="lp-card lp-tool" data-group="faq-saas">
                <button type="button" class="lp-tool-head" aria-expanded="false" aria-controls="faq-s2" data-open-text="Ver respuesta">
                    <span class="lp-tool-title"><i class="fa-solid fa-circle-question"></i> Mis datos se mezclan con otras tiendas?</span>
                    <span class="lp-tool-toggle"><i class="fa-solid fa-chevron-down"></i></span>
                </button>
                <div class="lp-tool-body" id="faq-s2"><p>No. Cada tienda es un tenant aislado por slug (/t/tu-tienda, /r/tu-tienda/...). Tus clientes solo ven tu tienda.</p></div>
            </div>
            <div class="lp-card lp-tool" data-group="faq-saas">
                <button type="button" class="lp-tool-head" aria-expanded="false" aria-controls="faq-s3" data-open-text="Ver respuesta">
                    <span class="lp-tool-title"><i class="fa-solid fa-circle-question"></i> Que ve mi cliente final?</span>
                    <span class="lp-tool-toggle"><i class="fa-solid fa-chevron-down"></i></span>
                </button>
                <div class="lp-tool-body" id="faq-s3"><p>Tu mini-web /t/tu-tienda con tu logo y colores, consulta RPT con QR, TV de sala y avisos por WhatsApp. Nada de planes.</p></div>
            </div>
            <div class="lp-card lp-tool" data-group="faq-saas">
                <button type="button" class="lp-tool-head" aria-expanded="false" aria-controls="faq-s4" data-open-text="Ver respuesta">
                    <span class="lp-tool-title"><i class="fa-solid fa-circle-question"></i> Puedo cancelar cuando quiera?</span>
                    <span class="lp-tool-toggle"><i class="fa-solid fa-chevron-down"></i></span>
                </button>
                <div class="lp-tool-body" id="faq-s4"><p>Si, sin contratos forzosos. Puedes bajar al plan Gratis y tus datos quedan guardados.</p></div>
            </div>
            <div class="lp-card lp-tool" data-group="faq-saas">
                <button type="button" class="lp-tool-head" aria-expanded="false" aria-controls="faq-s5" data-open-text="Ver respuesta">
                    <span class="lp-tool-title"><i class="fa-solid fa-circle-question"></i> Funciona con IVA chileno?</span>
                    <span class="lp-tool-toggle"><i class="fa-solid fa-chevron-down"></i></span>
                </button>
                <div class="lp-tool-body" id="faq-s5"><p>Si. Tickets con neto/IVA desglosado, RUT validado y zona horaria America/Santiago.</p></div>
            </div>
        </div>
    </div>
</section>

<section class="lp-section">
    <div class="lp-container">
        <div class="lp-card" style="max-width:760px;margin:0 auto;text-align:center;padding:38px 30px;">
            <h3 style="font-size:24px;font-weight:900;justify-content:center;"><i class="fa-solid fa-rocket" style="color:var(--cyan);"></i> Listo para ordenar tu tienda?</h3>
            <p style="color:var(--muted);font-size:14.5px;line-height:1.6;margin:10px 0 22px;">Crea tu cuenta gratis hoy y recibe tu primera reparacion con codigo RPT en minutos.</p>
            <div class="lp-hero-actions" style="justify-content:center;">
                <a href="{{ route('registro.tenant') }}" class="lp-btn lp-btn-primary"><i class="fa-solid fa-user-plus"></i> Registrar mi tienda gratis</a>
                <a href="{{ route('login') }}" class="lp-btn lp-btn-ghost"><i class="fa-solid fa-right-to-bracket"></i> Ya tengo cuenta</a>
            </div>
        </div>
    </div>
</section>
@endsection

@push('scripts')
    const toolCardsSaaS = document.querySelectorAll('[data-group="faq-saas"]');
    const abrirToolSaaS = (card, abrir) => {
        const head = card.querySelector('.lp-tool-head');
        const body = document.getElementById(head.getAttribute('aria-controls'));
        if (!head || !body) return;
        card.classList.toggle('is-open', abrir);
        head.setAttribute('aria-expanded', abrir ? 'true' : 'false');
    };
    toolCardsSaaS.forEach(card => {
        card.querySelector('.lp-tool-head').addEventListener('click', () => {
            const abrir = !card.classList.contains('is-open');
            toolCardsSaaS.forEach(c => { if (c !== card) abrirToolSaaS(c, false); });
            abrirToolSaaS(card, abrir);
        });
    });
@endpush

