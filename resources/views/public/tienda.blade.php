<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $config->nombre_tienda ?? $tenant->empresa ?? 'Tienda' }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet" integrity="sha384-4LISF5TTJX/fLmGSxO53rV4miRxdg84mZsxmO8Rx5jGtp/LbrixFETvWa5a6sESd" crossorigin="anonymous">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e3a8a;
            --accent: #06b6d4;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #0f172a;
            --light: #f8fafc;
            --border: #e2e8f0;
        }
        @if(isset($coloresMarca) && is_array($coloresMarca))
        /* Colores de marca del taller (configurables en el panel) */
        :root {
            --primary: {{ $coloresMarca['primario'] }};
            --primary-puro: {{ $coloresMarca['primario_puro'] }};
            --accent: {{ $coloresMarca['secundario_puro'] }};
            --brand-soft: {{ $coloresMarca['primario_rgba'] }};
            --on-brand: {{ $coloresMarca['texto_sobre_primario'] }};
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-puro), var(--accent)) !important;
            border-color: var(--accent) !important;
            color: var(--on-brand) !important;
        }
        .icon-circle {
            background: var(--brand-soft) !important;
            color: var(--primary) !important;
        }
        .footer .brand { color: var(--primary) !important; }
        @endif
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: var(--light);
            color: var(--dark);
            line-height: 1.6;
        }
        /* ── Hero ── */
        .hero {
            background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 40%, var(--primary) 100%);
            color: #fff;
            padding: 80px 0 100px;
            position: relative;
            overflow: hidden;
        }
        .hero::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(6,182,212,.15) 0%, transparent 70%);
            border-radius: 50%;
        }
        .hero::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(16,185,129,.1) 0%, transparent 70%);
            border-radius: 50%;
        }
        .hero .container { position: relative; z-index: 1; }
        .hero .logo {
            max-height: 90px;
            max-width: 220px;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0,0,0,.3);
            background: #fff;
            padding: 8px;
        }
        .hero h1 {
            font-weight: 800;
            font-size: 2.5rem;
            letter-spacing: -0.5px;
            text-shadow: 0 2px 20px rgba(0,0,0,.2);
        }
        .hero .lead {
            font-size: 1.1rem;
            opacity: .9;
            max-width: 600px;
            margin: 0 auto;
        }
        .hero .rating-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,.2);
            border-radius: 50px;
            padding: 6px 16px;
            font-size: .9rem;
        }
        .hero .social-row { display: flex; justify-content: center; gap: 10px; flex-wrap: wrap; }
        .btn-whatsapp {
            background: #25D366;
            color: #fff;
            border-radius: 50px;
            padding: 12px 28px;
            font-weight: 600;
            border: none;
            transition: all .3s;
            box-shadow: 0 4px 15px rgba(37,211,102,.3);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-whatsapp:hover { background: #1da851; color: #fff; transform: translateY(-2px); box-shadow: 0 6px 20px rgba(37,211,102,.4); }
        .btn-whatsapp svg { width: 20px; height: 20px; fill: currentColor; }
        .social-btn {
            width: 48px; height: 48px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 20px;
            transition: all .3s;
            box-shadow: 0 4px 12px rgba(0,0,0,.2);
        }
        .social-btn:hover { transform: translateY(-3px) scale(1.05); color: #fff; }
        .social-btn svg { width: 22px; height: 22px; fill: currentColor; }
        .social-btn.instagram { background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888); }
        .social-btn.facebook { background: #1877f2; }
        .social-btn.tiktok { background: #000; }
        .social-btn.whatsapp { background: #25D366; }

        /* ── Cards ── */
        .card-modern {
            background: #fff;
            border-radius: 20px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 20px rgba(0,0,0,.05);
            transition: all .3s;
            overflow: hidden;
        }
        .card-modern:hover { box-shadow: 0 8px 30px rgba(0,0,0,.1); transform: translateY(-2px); }
        .card-header-modern {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .card-header-modern .icon-circle {
            width: 40px; height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .card-header-modern .icon-circle svg { width: 20px; height: 20px; fill: currentColor; }
        .card-body-modern { padding: 24px; }

        /* ── Info items (Burbujas redondas pastel) ── */
        .info-list { display: grid; grid-template-columns: 1fr 1fr; gap: 16px 20px; }
        .info-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 4px 0;
            transition: all .3s;
        }
        .info-item:hover { transform: translateX(4px); }
        .info-item .icon {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }
        .info-item .icon svg { width: 18px; height: 18px; fill: currentColor; }
        .info-item .icon.direccion { background: #fee2e2; color: #ef4444; }
        .info-item .icon.telefono { background: #d1fae5; color: #10b981; }
        .info-item .icon.horario { background: #fef3c7; color: #f59e0b; }
        .info-item .icon.email { background: #e0f2fe; color: #06b6d4; }
        .info-item .label { font-size: .72rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: .8px; }
        .info-item .value { font-weight: 600; font-size: .92rem; color: var(--dark); }

        /* ── Reseñas ── */
        .resena-card {
            background: #ecfeff;
            border-left: 4px solid #0891b2;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
        }
        .star { color: var(--warning); font-size: 18px; }
        .star-empty { color: #d1d5db; font-size: 18px; }

        /* ── Cupones ── */
        .cupon-card {
            border: 2px dashed var(--success);
            background: #f0fdf4;
            border-radius: 12px;
            padding: 16px;
            text-align: center;
            margin-bottom: 12px;
        }
        .cupon-card .codigo {
            font-size: 1.2rem;
            font-weight: 800;
            letter-spacing: 3px;
            color: var(--dark);
            background: #fff;
            border: 1px dashed var(--success);
            border-radius: 8px;
            padding: 6px 12px;
            display: inline-block;
        }

        /* ── Mapa ── */
        .mapa-container {
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid var(--border);
            box-shadow: 0 4px 20px rgba(0,0,0,.08);
        }
        .mapa-container iframe {
            width: 100%;
            height: 300px;
            border: none;
            display: block;
        }
        .btn-como-llegar {
            background: var(--primary);
            color: #fff;
            border-radius: 50px;
            padding: 10px 24px;
            font-weight: 600;
            border: none;
            transition: all .3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-como-llegar svg { width: 16px; height: 16px; fill: currentColor; }
        .btn-como-llegar:hover { background: var(--primary-dark); color: #fff; transform: translateY(-2px); }

        /* ── Seguimiento ── */
        .tracking-form {
            background: linear-gradient(135deg, #eef2ff 0%, #f0fdf4 100%);
            border-radius: 16px;
            padding: 20px;
            border: 1px solid var(--border);
        }

        /* ── Footer ── */
        .footer {
            background: var(--dark);
            color: #94a3b8;
            padding: 30px 0;
            text-align: center;
        }
        .footer .brand { color: #fff; font-weight: 700; font-size: 1.1rem; }

        /* ── Animaciones ── */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade { animation: fadeInUp .6s ease-out both; }
        .animate-delay-1 { animation-delay: .1s; }
        .animate-delay-2 { animation-delay: .2s; }
        .animate-delay-3 { animation-delay: .3s; }

        @media (max-width: 768px) {
            .hero { padding: 50px 0 70px; }
            .hero h1 { font-size: 1.8rem; }
            .info-list { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <!-- ══════════ HERO ══════════ -->
    <div class="hero text-center">
        <div class="container">
            @if($logoSrc)
                <img src="{{ $logoSrc }}" alt="Logo" class="logo mb-4">
            @else
                <div style="font-size:70px;" class="mb-3">📱</div>
            @endif
            <h1 class="fw-bold mb-2">{{ $config->nombre_tienda ?? $tenant->empresa ?? 'Mi Tienda' }}</h1>
            @if($config->descripcion_corta)
                <p class="lead mb-4">{{ $config->descripcion_corta }}</p>
            @endif
            @if($promedio)
                <div class="rating-badge mb-4">
                    <span>
                        @for($i=1; $i<=5; $i++)
                            <span class="{{ $i <= round($promedio) ? 'star' : 'star-empty' }}" style="font-size:16px;">★</span>
                        @endfor
                    </span>
                    <strong>{{ number_format($promedio, 1) }}/5</strong>
                    <span style="opacity:.7;">· {{ $resenas->count() }} reseñas</span>
                </div>
            @endif
            <div class="social-row">
                @if($config->whatsapp)
                    <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $config->whatsapp) }}" target="_blank" class="btn btn-whatsapp">
                        <svg viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                        WhatsApp
                    </a>
                @endif
                @if($config->instagram)
                    <a href="{{ $config->instagram }}" target="_blank" class="social-btn instagram" title="Instagram">
                        <svg viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zm0 10.162a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
                    </a>
                @endif
                @if($config->facebook)
                    <a href="{{ $config->facebook }}" target="_blank" class="social-btn facebook" title="Facebook">
                        <svg viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                    </a>
                @endif
                @if($config->tiktok)
                    <a href="{{ $config->tiktok }}" target="_blank" class="social-btn tiktok" title="TikTok">
                        <svg viewBox="0 0 24 24"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>
                    </a>
                @endif
            </div>
        </div>
    </div>

    <!-- ══════════ CONTENIDO ══════════ -->
    <div class="container py-5" style="margin-top:-40px;">
        <div class="row g-4">
            <!-- ── Columna principal ── -->
            <div class="col-lg-8">
                <!-- Servicios -->
                <div class="card-modern mb-4 animate-fade">
                    <div class="card-header-modern">
                        <div class="icon-circle" style="background:#dbeafe;color:var(--primary);">
                            <svg viewBox="0 0 24 24"><path d="M22.7 19l-9.1-9.1c.9-2.3.4-5-1.5-6.9-2-2-5-2.4-7.4-1.3L9 6 6 9 1.6 4.7C.4 7.1.9 10.1 2.9 12.1c1.9 1.9 4.6 2.4 6.9 1.5l9.1 9.1c.4.4 1 .4 1.4 0l2.3-2.3c.5-.4.5-1 .1-1.4z"/></svg>
                        </div>
                        <h5 class="fw-bold mb-0">Nuestros servicios</h5>
                    </div>
                    <div class="card-body-modern">
                        <div class="row g-3 text-center">
                            <div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:var(--light);border:1px solid var(--border);"><div style="font-size:26px;">📱</div><div class="fw-bold" style="font-size:13px;">Cambio de pantalla</div><div class="text-muted" style="font-size:12px;">El mismo día</div></div></div>
                            <div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:var(--light);border:1px solid var(--border);"><div style="font-size:26px;">🔋</div><div class="fw-bold" style="font-size:13px;">Batería</div><div class="text-muted" style="font-size:12px;">Con garantía</div></div></div>
                            <div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:var(--light);border:1px solid var(--border);"><div style="font-size:26px;">💦</div><div class="fw-bold" style="font-size:13px;">Placa / Agua</div><div class="text-muted" style="font-size:12px;">Diagnóstico gratis</div></div></div>
                            <div class="col-6 col-md-3"><div class="p-3 rounded-3 h-100" style="background:var(--light);border:1px solid var(--border);"><div style="font-size:26px;">🔓</div><div class="fw-bold" style="font-size:13px;">Liberación</div><div class="text-muted" style="font-size:12px;">Todas las operadoras</div></div></div>
                        </div>
                        @if(!empty($whatsappUrl ?? null))
                        <a href="{{ $whatsappUrl }}" target="_blank" class="btn btn-success w-100 mt-3 fw-bold" style="border-radius:10px;">Cotizar mi reparación por WhatsApp</a>
                        @endif
                    </div>
                </div>
                <!-- Catalogo real -->
                @if(!empty($productos) && $productos->count() > 0)
                <div class="card-modern mb-4 animate-fade">
                    <div class="card-header-modern">
                        <div class="icon-circle" style="background:#fef9c3;color:#d97706;">
                            <svg viewBox="0 0 24 24"><path d="M20 4H4v2h16V4zm1 10v-2l-1-5H4l-1 5v2h1v6h10v-6h4v2h1zm-9 4H6v-4h6v4zm0-6H6v-2h6v2zm8 6h-4v-4h4v4zm0-6h-4v-2h4v2z"/></svg>
                        </div>
                        <h5 class="fw-bold mb-0">Catálogo</h5>
                    </div>
                    <div class="card-body-modern">
                        <div class="row g-3">
                            @foreach($productos as $prod)
                            <div class="col-6 col-md-3">
                                <div class="rounded-3 h-100 d-flex flex-column" style="border:1px solid var(--border);overflow:hidden;">
                                    <div class="p-3 flex-grow-1">
                                        <div class="fw-bold" style="font-size:13px;">{{ $prod->nombre }}</div>
                                        <div class="fw-bold mt-1" style="color:var(--primary);font-size:17px;">{{ $config->simbolo_moneda ?? 'S/' }} {{ number_format($prod->precio_venta, 2) }}</div>
                                        <div class="text-success" style="font-size:12px;">En stock ({{ $prod->stock }})</div>
                                    </div>
                                    @if(!empty($whatsappUrl ?? null))
                                    <a href="https://wa.me/{{ preg_replace('/\D/', '', (string)($config->whatsapp ?? $config->telefono ?? '')) }}?text={{ urlencode('Hola, me interesa: ' . $prod->nombre . ' ¿Sigue disponible?') }}" target="_blank" class="btn btn-sm btn-success m-2 mt-0 fw-bold">Pedir por WhatsApp</a>
                                    @endif
                                </div>
                            </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                @endif
                <!-- Información -->
                <div class="card-modern mb-4 animate-fade">
                    <div class="card-header-modern">
                        <div class="icon-circle" style="background:#eef2ff;color:var(--primary);">
                            <svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 100 20 10 10 0 000-20zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>
                        </div>
                        <h5 class="fw-bold mb-0">Información</h5>
                    </div>
                    <div class="card-body-modern">
                        <div class="info-list">
                            @if($config->direccion)
                            <div class="info-item">
                                <div class="icon direccion">
                                    <svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 110-5 2.5 2.5 0 010 5z"/></svg>
                                </div>
                                <div>
                                    <div class="label">Dirección</div>
                                    <div class="value">{{ $config->direccion }}</div>
                                </div>
                            </div>
                            @endif
                            @if($config->telefono)
                            <div class="info-item">
                                <div class="icon telefono">
                                    <svg viewBox="0 0 24 24"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>
                                </div>
                                <div>
                                    <div class="label">Teléfono</div>
                                    <div class="value">{{ $config->telefono }}</div>
                                </div>
                            </div>
                            @endif
                            @if($config->horario_atencion)
                            <div class="info-item">
                                <div class="icon horario">
                                    <svg viewBox="0 0 24 24"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>
                                </div>
                                <div>
                                    <div class="label">Horario</div>
                                    <div class="value">{{ $config->horario_atencion }}</div>
                                </div>
                            </div>
                            @endif
                            @if($config->email)
                            <div class="info-item">
                                <div class="icon email">
                                    <svg viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
                                </div>
                                <div>
                                    <div class="label">Email</div>
                                    <div class="value">{{ $config->email }}</div>
                                </div>
                            </div>
                            @endif
                        </div>
                    </div>
                </div>

                <!-- Mapa / Ubicación -->
                @php
                    // Convertir link de Google Maps a formato embed si es necesario
                    $mapaEmbed = null;
                    $mapaLink = $config->mapa_url ?? null;

                    if ($mapaLink) {
                        if (str_contains($mapaLink, '/maps/embed') || str_contains($mapaLink, 'output=embed')) {
                            $mapaEmbed = $mapaLink;
                        } elseif (str_contains($mapaLink, 'maps.app.goo.gl') || str_contains($mapaLink, 'google.com/maps') || str_contains($mapaLink, 'goo.gl/maps')) {
                            if ($config->direccion) {
                                $mapaEmbed = 'https://maps.google.com/maps?q=' . urlencode($config->direccion) . '&z=16&output=embed';
                            }
                        } else {
                            $mapaEmbed = $mapaLink;
                        }
                    } elseif ($config->direccion) {
                        $mapaEmbed = 'https://maps.google.com/maps?q=' . urlencode($config->direccion) . '&z=16&output=embed';
                        $mapaLink = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($config->direccion);
                    }
                @endphp

                @if($mapaEmbed)
                <div class="card-modern mb-4 animate-fade animate-delay-1">
                    <div class="card-header-modern">
                        <div class="icon-circle" style="background:#fee2e2;color:var(--danger);">
                            <svg viewBox="0 0 24 24"><path d="M20.5 3l-.16.03L15 5.1 9 3 3.36 4.9c-.21.07-.36.25-.36.48V20.5c0 .28.22.5.5.5l.16-.03L9 18.9l6 2.1 5.64-1.9c.21-.07.36-.25.36-.48V3.5c0-.28-.22-.5-.5-.5zM15 19l-6-2.11V5l6 2.11V19z"/></svg>
                        </div>
                        <h5 class="fw-bold mb-0">Ubicación</h5>
                    </div>
                    <div class="card-body-modern">
                        <div class="mapa-container mb-3">
                            <iframe
                                src="{{ $mapaEmbed }}"
                                loading="lazy"
                                allowfullscreen
                                referrerpolicy="no-referrer-when-downgrade"
                                title="Mapa de {{ $config->nombre_tienda ?? 'la tienda' }}">
                            </iframe>
                        </div>
                        <div class="text-center">
                            <a href="{{ $mapaLink }}" target="_blank" class="btn-como-llegar">
                                <svg viewBox="0 0 24 24"><path d="M6.5 8.5L12 3l5.5 5.5H13V21h-2V8.5H6.5z"/></svg>
                                Cómo llegar
                            </a>
                        </div>
                    </div>
                </div>
                @endif

                <!-- Reseñas -->
                @if($resenas->isNotEmpty())
                <div class="card-modern mb-4 animate-fade animate-delay-2">
                    <div class="card-header-modern">
                        <div class="icon-circle" style="background:#fef3c7;color:var(--warning);">
                            <svg viewBox="0 0 24 24"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
                        </div>
                        <h5 class="fw-bold mb-0">Reseñas de Clientes</h5>
                    </div>
                    <div class="card-body-modern">
                        @foreach($resenas as $resena)
                        <div class="resena-card">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <strong>{{ $resena->nombre_publico ?? 'Cliente' }}</strong>
                                    <div class="mt-1">
                                        @for($i=1; $i<=5; $i++)
                                            <span class="{{ $i <= $resena->calificacion ? 'star' : 'star-empty' }}" style="font-size:16px;">★</span>
                                        @endfor
                                    </div>
                                </div>
                                <small class="text-muted">{{ $resena->created_at->format('d/m/Y') }}</small>
                            </div>
                            @if($resena->comentario)
                                <p class="mb-0 mt-2" style="font-size:14px;">{{ $resena->comentario }}</p>
                            @endif
                            @if($resena->respuesta_admin)
                                <div class="mt-2 p-2" style="background:#e0f2fe;border-radius:8px;font-size:13px;">
                                    <strong class="text-primary">Respuesta:</strong> {{ $resena->respuesta_admin }}
                                </div>
                            @endif
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif

                <!-- Seguimiento de Reparación -->
                <div class="card-modern mb-4 animate-fade animate-delay-3">
                    <div class="card-header-modern">
                        <div class="icon-circle" style="background:#e0f2fe;color:var(--accent);">
                            <svg viewBox="0 0 24 24"><path d="M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4zM6 18.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zm13.5-9l1.96 2.5H17V9.5h2.5zm-1.5 9c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg>
                        </div>
                        <h5 class="fw-bold mb-0">Seguimiento de Reparación</h5>
                    </div>
                    <div class="card-body-modern">
                        <p class="text-muted" style="font-size:14px;">Ingresa tu número de orden para ver el estado de tu equipo.</p>
                        <div class="tracking-form">
                            <form action="{{ route('reparaciones.public-status.buscar') }}" method="GET" class="d-flex gap-2">
                                <input type="hidden" name="slugTienda" value="{{ $tenant->slug_publico }}">
                                <input type="text" name="numero_orden" class="form-control"
                                       placeholder="N° de orden (ej: RPT-000002-2K38)"
                                       value="{{ request('numero_orden') }}" required>
                                <button type="submit" class="btn btn-primary" style="white-space:nowrap;border-radius:10px;">
                                    <svg viewBox="0 0 24 24" style="width:16px;height:16px;fill:currentColor;margin-right:4px;"><path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
                                    Buscar
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- ¿Reparaste con nosotros? -->
                <div class="card-modern mb-4 animate-fade animate-delay-3">
                    <div class="card-header-modern">
                        <div class="icon-circle" style="background:#cffafe;color:#0891b2;">
                            <svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                        </div>
                        <h5 class="fw-bold mb-0">¿Reparaste con nosotros?</h5>
                    </div>
                    <div class="card-body-modern">
                        <p class="text-muted" style="font-size:14px;">Cuéntanos tu experiencia y ayuda a otros clientes.</p>
                        <a href="{{ route('public.resena.form', $tenant->slug_publico) }}" class="btn btn-primary w-100" style="border-radius:10px;">
                            <svg viewBox="0 0 24 24" style="width:16px;height:16px;fill:currentColor;margin-right:6px;"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
                            Dejar una reseña
                        </a>
                    </div>
                </div>
            </div>

            <!-- ── Columna lateral ── -->
            <div class="col-lg-4">
                <!-- Cupones -->
                @if($cupones->isNotEmpty())
                <div class="card-modern mb-4 animate-fade animate-delay-1">
                    <div class="card-header-modern">
                        <div class="icon-circle" style="background:#d1fae5;color:var(--success);">
                            <svg viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2v4c1.1 0 1.99.9 1.99 2s-.89 2-2 2v4c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2v-4c-1.1 0-2-.9-2-2s.9-2 2-2V6c0-1.1-.9-2-2-2zm-3 14H7v-2h10v2zm0-5H7v-2h10v2zm0-5H7V6h10v2z"/></svg>
                        </div>
                        <h5 class="fw-bold mb-0">Cupones Activos</h5>
                    </div>
                    <div class="card-body-modern">
                        @foreach($cupones as $cupon)
                        <div class="cupon-card">
                            <div class="text-muted small mb-1">Código</div>
                            <div class="codigo mb-2">{{ $cupon->codigo }}</div>
                            <div class="text-success fw-bold">{{ $cupon->valor }}% de descuento</div>
                            <div class="text-muted small mt-1">{{ $cupon->descripcion }}</div>
                            @if($cupon->fecha_expiracion)
                                <div class="text-danger small mt-1">Vence: {{ $cupon->fecha_expiracion->format('d/m/Y') }}</div>
                            @endif
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>

    <!-- ══════════ FOOTER ══════════ -->
    <footer class="footer">
        <div class="container">
            <div class="brand mb-2">{{ $config->nombre_tienda ?? $tenant->empresa ?? 'Mi Tienda' }}</div>
            @if($config->direccion)
                <p class="small mb-1">{{ $config->direccion }}</p>
            @endif
            @if($config->telefono)
                <p class="small mb-1">{{ $config->telefono }}</p>
            @endif
            <p class="small mb-0 mt-3">© {{ date('Y') }} {{ $config->nombre_tienda ?? $tenant->empresa ?? 'Mi Tienda' }}. Todos los derechos reservados.</p>
        </div>
    </footer>
</body>
</html>
