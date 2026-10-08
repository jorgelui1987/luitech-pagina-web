<!DOCTYPE html>
<html lang="es" @if(isset($coloresMarca) && is_array($coloresMarca)) style="--cyan: {{ $coloresMarca['primario'] }}; --cyan-strong: {{ $coloresMarca['primario'] }}; --on-brand: {{ $coloresMarca['texto_sobre_primario'] }}; --grad: linear-gradient(90deg, {{ $coloresMarca['primario_puro'] }}, {{ $coloresMarca['secundario_puro'] }}); --grad-chip: linear-gradient(135deg, {{ $coloresMarca['primario_puro'] }}, {{ $coloresMarca['secundario_puro'] }});" @endif>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#020617">
    <title>@yield('title', 'Consulta tu reparación') — {{ ($empresa->nombre_tienda ?? null) ?: 'Luitech' }}</title>

    <!-- Favicon -->
    @php $faviconPublico = (($tenant ?? null)->logo ?? null); @endphp
    <link rel="icon" type="image/png" href="{{ $faviconPublico ? route('storage.serve', ['path' => preg_replace('#^storage/#', '', ltrim($faviconPublico, '/'))]) : asset('logo-luitech.png') }}">
    <link rel="apple-touch-icon" href="{{ $faviconPublico ? route('storage.serve', ['path' => preg_replace('#^storage/#', '', ltrim($faviconPublico, '/'))]) : asset('logo-luitech.png') }}">
    <!-- Vista previa al compartir (Open Graph) -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ ($empresa->nombre_tienda ?? null) ?: 'LUITECH' }}">
    <meta property="og:title" content="{{ ($empresa->nombre_tienda ?? null) ?: 'LUITECH' }} · Servicio Técnico de Celulares y Computadores">
    <meta property="og:description" content="Reparación de celulares, tablets y PC con garantía escrita. Cotiza online, sigue tu reparación en tiempo real y agenda por WhatsApp.">
    <meta property="og:image" content="{{ asset('logo-luitech.png') }}">
    <meta name="twitter:card" content="summary">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <!-- Inter (fuente del portal público) -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <!-- Font Awesome 6 (misma versión que el panel interno) -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <!-- Tema visual del portal público (modelo portado de la web, CSS propio) -->
    <link href="{{ asset('css/public-portal.css') }}" rel="stylesheet">
    @stack('styles')
</head>
@php
    $brandName = ($empresa->nombre_tienda ?? null) ?: 'LUITECH';
    $brandSub  = (($empresa->direccion ?? '') !== '') ? $empresa->direccion : 'Servicio Técnico';
    $waDigits  = preg_replace('/\D/', '', (string) ($empresa->telefono ?? ''));
    $waNumber  = $waDigits !== '' ? (str_starts_with($waDigits, '56') ? $waDigits : '56' . $waDigits) : null;
    // Enlace de la Sala de Espera: REGLA SAAS, siempre con slug de ESA tienda.
    // Sin slug la ruta /pantalla responde 404: nunca se adivina empresa.
    // OJO: $tenant puede no venir (landing genérica): todo con ?? para no romper.
    $pantallaSlug = (($tenant ?? null)->slug_publico ?? null) ?: (\App\Models\Tenant::current()?->slug_publico ?? null);
    $pantallaUrl  = $pantallaSlug ? route('public.pantalla', ['slug' => $pantallaSlug]) : url('/estado');
    $consultaUrl  = $pantallaSlug
        ? route('reparaciones.public-status.search', ['slug' => $pantallaSlug])
        : route('reparaciones.public-status.search');
@endphp
<body class="lp-body">

    <!-- Header principal -->
    <header class="lp-header">
        <div class="lp-container lp-header-inner">
            <a class="lp-logo" href="{{ $consultaUrl }}">
                @if(!empty($empresa->logo))
                    <img class="lp-logo-img" src="{{ route('storage.serve', ['path' => preg_replace('#^storage/#', '', ltrim($empresa->logo, '/'))]) }}" alt="{{ $brandName }}">
                @else
                    <span class="lp-logo-chip"><i class="fa-solid fa-microchip"></i></span>
                @endif
                <span class="lp-logo-text">
                    <span class="lp-brand-name">{{ $brandName }}</span>
                    <span class="lp-brand-sub">{{ $brandSub }}</span>
                </span>
            </a>
            <nav class="lp-nav">
                <a class="lp-nav-link" href="{{ $consultaUrl }}">Consulta Express</a>
                <a class="lp-btn lp-btn-ghost lp-btn-sm" href="{{ $pantallaUrl }}" target="_blank" rel="noopener" title="Abrir pantalla de sala de espera">
                    <i class="fa-solid fa-tv"></i> Sala de Espera
                </a>
            </nav>
        </div>
    </header>

    <!-- Notificaciones flotantes -->
    <div id="lp-toasts" class="lp-toast-wrap" aria-live="polite"></div>

    <!-- Botón flotante de WhatsApp -->
    @if($waNumber)
        <a class="lp-wa-float" href="https://wa.me/{{ $waNumber }}?text={{ rawurlencode('Hola, tengo una consulta sobre mi equipo') }}" target="_blank" rel="noopener" aria-label="Chatea con nosotros por WhatsApp">
            <i class="fa-brands fa-whatsapp"></i>
        </a>
    @endif

    <main class="lp-main">
        @yield('content')
    </main>
    <!-- Footer general -->
    <footer class="lp-footer">
        <div class="lp-container lp-footer-grid">
            <div>
                <a class="lp-logo" href="{{ url('/estado') }}">
                    @if(!empty($empresa->logo))
                        <img class="lp-logo-img" src="{{ route('storage.serve', ['path' => preg_replace('#^storage/#', '', ltrim($empresa->logo, '/'))]) }}" alt="{{ $brandName }}">
                    @else
                        <span class="lp-logo-chip"><i class="fa-solid fa-microchip"></i></span>
                    @endif
                    <span class="lp-logo-text">
                        <span class="lp-brand-name">{{ $brandName }}</span>
                        <span class="lp-brand-sub">{{ $brandSub }}</span>
                    </span>
                </a>
                <p>Soluciones de soporte técnico eficientes, confiables y garantizadas para celulares, tablets, notebooks y PC.</p>
            </div>
            <div>
                <h4>Enlaces</h4>
                <p><a href="{{ $consultaUrl }}">Consulta Express</a></p>
                <p><a href="{{ $pantallaUrl }}" target="_blank" rel="noopener">Pantalla de Sala de Espera</a></p>
            </div>
            <div>
                <h4>Contacto</h4>
                @if(!empty($empresa->direccion))
                    <p><i class="fa-solid fa-location-dot" style="color:var(--cyan);margin-right:6px;"></i>{{ $empresa->direccion }}</p>
                @endif
                @php
                    $waDigits = preg_replace('/\D/', '', (string) ($empresa->telefono ?? ''));
                    $waNumber = $waDigits !== '' ? (str_starts_with($waDigits, '56') ? $waDigits : '56' . $waDigits) : null;
                @endphp
                @if($waNumber)
                    <p><i class="fa-brands fa-whatsapp" style="color:var(--cyan);margin-right:6px;"></i><a class="lp-wa" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener">WhatsApp: +{{ $waNumber }}</a></p>
                @elseif(!empty($empresa->telefono))
                    <p><i class="fa-solid fa-phone" style="color:var(--cyan);margin-right:6px;"></i>{{ $empresa->telefono }}</p>
                @endif
                @if(!empty($empresa->email))
                    <p><i class="fa-solid fa-envelope" style="color:var(--cyan);margin-right:6px;"></i>{{ $empresa->email }}</p>
                @endif
            </div>
        </div>
        <div class="lp-footer-base">© {{ date('Y') }} {{ $brandName }} — Todos los derechos reservados.</div>
    </footer>

    @stack('external-scripts')

    <script>
        // Sistema de notificaciones (toasts) del portal público
        function lpToast(message, tipo = 'error') {
            const wrap = document.getElementById('lp-toasts');
            if (!wrap) return;
            const toast = document.createElement('div');
            toast.className = 'lp-toast ' + (tipo === 'success' ? 'lp-toast-success' : 'lp-toast-error');
            toast.innerHTML = '<i class="fa-solid ' + (tipo === 'success' ? 'fa-circle-check' : 'fa-circle-xmark') + '"></i><span></span>';
            toast.querySelector('span').textContent = message;
            wrap.appendChild(toast);
            requestAnimationFrame(() => toast.classList.add('is-show'));
            setTimeout(() => {
                toast.classList.remove('is-show');
                setTimeout(() => toast.remove(), 350);
            }, 4200);
        }

        // Revelado suave de secciones al hacer scroll
        if ('IntersectionObserver' in window) {
            const lpRevealEls = document.querySelectorAll('.lp-section-head, .lp-features > *, .lp-quote-result, .lp-salud-resultado, .lp-pricing > *');
            lpRevealEls.forEach(el => el.classList.add('lp-reveal'));
            const lpIO = new IntersectionObserver((entries) => {
                entries.forEach(en => { if (en.isIntersecting) { en.target.classList.add('lp-visible'); lpIO.unobserve(en.target); } });
            }, { threshold: .12 });
            lpRevealEls.forEach(el => lpIO.observe(el));
        }

        @if(!empty($error))
            document.addEventListener('DOMContentLoaded', () => lpToast({!! json_encode($error) !!}, 'error'));
        @endif

        @stack('scripts')
    </script>
</body>
</html>