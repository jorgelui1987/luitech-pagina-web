<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#020617">
    <title>@yield('title', 'LUITECH · Software para tiendas de celulares y servicio técnico')</title>
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="LUITECH">
    <meta property="og:title" content="LUITECH · Software para tiendas de celulares">
    <meta property="og:description" content="Ventas, inventario, reparaciones con código RPT, sala de espera TV, comisiones, reportes y WhatsApp. Todo en un solo sistema.">
    <meta property="og:image" content="{{ asset('logo-luitech.png') }}">
    <link rel="icon" type="image/png" href="{{ asset('logo-luitech.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="{{ asset('css/public-portal.css') }}" rel="stylesheet">
    @stack('styles')
</head>
<body class="lp-body">
    <div class="lp-toast-wrap" id="lp-toasts" aria-live="polite"></div>
    <header class="lp-header">
        <div class="lp-container lp-header-inner">
            <a href="/" class="lp-logo">
                <span class="lp-logo-chip"><i class="fa-solid fa-microchip"></i></span>
                <span class="lp-logo-text">
                    <span class="lp-brand-name">LUITECH</span>
                    <span class="lp-brand-sub">Software para tiendas</span>
                </span>
            </a>
            <nav class="lp-nav">
                <a href="#funciones" class="lp-nav-link">Funciones</a>
                <a href="#demo" class="lp-nav-link">Demo</a>
                <a href="#planes" class="lp-nav-link">Planes</a>
                <a href="#faq-saas" class="lp-nav-link">Preguntas</a>
                <a href="{{ route('login') }}" class="lp-btn lp-btn-ghost lp-btn-sm"><i class="fa-solid fa-right-to-bracket"></i> Ingresar</a>
                <a href="{{ route('registro.tenant') }}" class="lp-btn lp-btn-primary lp-btn-sm"><i class="fa-solid fa-rocket"></i> Probar gratis</a>
            </nav>
        </div>
    </header>
    <main class="lp-main">
        @yield('content')
    </main>
    <footer class="lp-footer">
        <div class="lp-container lp-footer-grid">
            <div>
                <a href="/" class="lp-logo">
                    <span class="lp-logo-chip"><i class="fa-solid fa-microchip"></i></span>
                    <span class="lp-logo-text">
                        <span class="lp-brand-name">LUITECH</span>
                        <span class="lp-brand-sub">Software para tiendas</span>
                    </span>
                </a>
                <p>El sistema todo-en-uno para tiendas de celulares y servicio técnico: ventas, inventario, reparaciones, comisiones, reportes y WhatsApp.</p>
            </div>
            <div>
                <h4>Producto</h4>
                <p><a href="#funciones">Funciones</a></p>
                <p><a href="#demo">Demo en vivo</a></p>
                <p><a href="{{ route('landing.planes') }}">Planes y precios</a></p>
                <p><a href="{{ route('registro.tenant') }}">Crear cuenta gratis</a></p>
            </div>
            <div>
                <h4>Acceso</h4>
                <p><a href="{{ route('login') }}">Ingresar a mi tienda</a></p>
                <p><a href="{{ route('superadmin.login') }}">Soy administrador</a></p>
                <p><a href="mailto:{{ $empresa->email ?? 'contacto@luitech.cl' }}"><i class="fa-solid fa-envelope" style="color:var(--cyan);margin-right:6px;"></i>{{ $empresa->email ?? 'contacto@luitech.cl' }}</a></p>
            </div>
        </div>
        <div class="lp-footer-base">© {{ date('Y') }} LUITECH — Todos los derechos reservados. · ¿Eres cliente y quieres reparar tu equipo? <a href="#demo" style="color:var(--cyan);font-weight:700;">Ver taller demo</a></div>
    </footer>
    <script>
        function lpToast(message, tipo = 'error') {
            const wrap = document.getElementById('lp-toasts');
            if (!wrap) return;
            const toast = document.createElement('div');
            toast.className = 'lp-toast ' + (tipo === 'success' ? 'lp-toast-success' : 'lp-toast-error');
            toast.innerHTML = '<i class="fa-solid ' + (tipo === 'success' ? 'fa-circle-check' : 'fa-circle-xmark') + '"></i><span></span>';
            toast.querySelector('span').textContent = message;
            wrap.appendChild(toast);
            requestAnimationFrame(() => toast.classList.add('is-show'));
            setTimeout(() => { toast.classList.remove('is-show'); setTimeout(() => toast.remove(), 350); }, 4200);
        }
        if ('IntersectionObserver' in window) {
            const els = document.querySelectorAll('.lp-section-head, .lp-features > *, .lp-pricing > *');
            els.forEach(el => el.classList.add('lp-reveal'));
            const io = new IntersectionObserver((entries) => {
                entries.forEach(en => { if (en.isIntersecting) { en.target.classList.add('lp-visible'); io.unobserve(en.target); } });
            }, { threshold: .12 });
            els.forEach(el => io.observe(el));
        }
        @stack('scripts')
    </script>
</body>
</html>
