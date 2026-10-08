@php
    // REGLA SAAS: la pantalla SIEMPRE exige slug. El controlador ya resolvió
    // la tienda (o 404): aquí $empresaPantalla y $slugPantalla vienen con datos.
    $nombreTienda     = $empresaPantalla->nombre_tienda ?? 'LUITECH';
    $direccionTienda  = $empresaPantalla->direccion ?? '';
    $telefonoTienda   = $empresaPantalla->telefono ?? '';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#020617">
    <title>Sala de Espera — {{ $nombreTienda }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="{{ asset('css/public-portal.css') }}" rel="stylesheet">
</head>
<body class="lp-body">
<div class="tv-app">

    <!-- Encabezado: marca + reloj -->
    <header class="tv-top">
        <div class="tv-brand">
            <span class="tv-logo"><i class="fa-solid fa-microchip"></i></span>
            <div>
                <h1 id="tv-name">{{ $nombreTienda }}</h1>
                <p>Sala de espera · Estado de reparaciones en vivo</p>
            </div>
        </div>
        <div class="tv-clock">
            <div class="tv-clock-time" id="tv-clock">--:--:--</div>
            <div class="tv-clock-date" id="tv-date">—</div>
        </div>
    </header>

    <!-- Consulta privada: cada cliente ve SOLO su turno con su código completo -->
    <div class="tv-privacy">
        <div class="tv-privacy-text">
            <h2><i class="fa-solid fa-user-shield"></i> ¿Eres cliente? Consulta SOLO tu equipo aquí</h2>
            <p>Por privacidad, la lista muestra turnos enmascarados (···-A2B4). Ingresa tu código completo de la boleta para ver el detalle de <strong>tu orden únicamente</strong>.</p>
        </div>
        <div class="tv-privacy-form">
            <input id="tv-mi-codigo" autocomplete="off" spellcheck="false" placeholder="Ej: RPT-001024-A2B4">
            <button id="tv-mi-btn" type="button"><i class="fa-solid fa-magnifying-glass"></i> Ver mi turno</button>
        </div>
    </div>
    <p id="tv-mi-resultado" class="tv-privacy-result" hidden></p>

    <!-- Columnas de turnos -->
    <div class="tv-grid">

        <!-- Listos para retiro -->
        <section class="tv-col tv-col-ready">
            <div class="tv-col-head">
                <h2><i class="fa-solid fa-circle-check"></i> LISTO PARA RETIRO</h2>
                <span class="tv-count" id="tv-count-ready">0</span>
            </div>
            <div class="tv-list" id="tv-ready-list"></div>
            <p class="tv-note"><i class="fa-solid fa-circle-exclamation"></i> Presenta tu boleta o código de orden en el mesón para retirar tu equipo.</p>
        </section>

        <!-- En taller / proceso -->
        <section class="tv-col tv-col-process">
            <div class="tv-col-head">
                <h2><i class="fa-solid fa-screwdriver-wrench"></i> EN TALLER / PROCESO</h2>
                <span class="tv-count" id="tv-count-process">0</span>
            </div>
            <div class="tv-list" id="tv-process-list"></div>
            <p class="tv-note"><i class="fa-solid fa-shield-halved"></i> Todas nuestras reparaciones incluyen garantía técnica.</p>
        </section>

        <!-- Consejos + info del local -->
        <aside class="tv-side">
            <div class="tv-tip">
                <div>
                    <span class="tv-tip-chip">Consejo del técnico</span>
                    <h3 class="tv-tip-title" id="tv-tip-title">{{ $consejos[0]['titulo'] ?? '' }}</h3>
                    <p class="tv-tip-desc" id="tv-tip-desc">{{ $consejos[0]['desc'] ?? '' }}</p>
                </div>
                <div class="tv-tip-foot"><i class="fa-solid fa-lightbulb"></i> Consejos rotativos cada 15 segundos</div>
            </div>
            <div class="tv-info">
                @if($direccionTienda)
                    <span><i class="fa-solid fa-location-dot"></i> {{ $direccionTienda }}</span>
                @endif
                @if($telefonoTienda)
                    <span><i class="fa-brands fa-whatsapp"></i> {{ $telefonoTienda }}</span>
                @endif
                @if(!$direccionTienda && !$telefonoTienda)
                    <span><i class="fa-solid fa-store"></i> Servicio técnico especializado</span>
                @endif
            </div>
        </aside>
    </div>

    <!-- Barra inferior + controles -->
    <footer class="tv-foot">
        <span><i class="fa-solid fa-rotate"></i> Actualización automática cada 15 segundos</span>
        <div class="tv-controls">
            <button type="button" id="tv-sound"><i class="fa-solid fa-volume-xmark"></i> Sonido</button>
            <button type="button" id="tv-fs"><i class="fa-solid fa-expand"></i> Pantalla completa</button>
            <a href="{{ route('reparaciones.public-status.search', ['slug' => $slugPantalla]) }}"><i class="fa-solid fa-arrow-right-from-bracket"></i> Salir</a>
        </div>
    </footer>
</div>
<script>
    // REGLA SAAS: la pantalla SIEMPRE tiene slug (la ruta sin slug da 404).
    const DATA_URL = @js(route('public.pantalla.data', ['slug' => $slugPantalla]));
    const MI_TURNO_URL = @js(route('public.pantalla.mi-turno', ['slug' => $slugPantalla]));
    const CONSEJOS = @json($consejos);

    let prevReady = null;   // códigos "listos" vistos (1ª carga = referencia, sin chime)
    let soundOn = false;
    let audioCtx = null;
    let consejoIdx = 0;

    // Escapar contenido dinámico
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));

    // Tono sintetizado (Web Audio API) al pasar un turno a "Listo"
    function chime() {
        try {
            audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
            if (audioCtx.state === 'suspended') audioCtx.resume();
            const nota = (freq, delay, dur) => {
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.type = 'sine';
                osc.frequency.value = freq;
                gain.gain.setValueAtTime(0.18, audioCtx.currentTime + delay);
                gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + delay + dur);
                osc.connect(gain).connect(audioCtx.destination);
                osc.start(audioCtx.currentTime + delay);
                osc.stop(audioCtx.currentTime + delay + dur);
            };
            nota(659.25, 0, 0.45);    // Mi5
            nota(783.99, 0.18, 0.6);  // Sol5
        } catch (e) { /* audio no disponible */ }
    }

    const TAGS_PROCESO = {
        recibido: ['tv-tag-diag', 'fa-clipboard'],
        en_diagnostico: ['tv-tag-diag', 'fa-stethoscope'],
        esperando_repuesto: ['tv-tag-wait', 'fa-hourglass-half'],
        en_reparacion: ['tv-tag-proc', 'fa-screwdriver-wrench'],
    };

    function render(d) {
        document.getElementById('tv-name').textContent = (d.tienda && d.tienda.nombre) || 'LUITECH';

        // Columna "Listo para retiro"
        const readyEl = document.getElementById('tv-ready-list');
        if (!d.listos.length) {
            readyEl.innerHTML = '<div class="tv-empty tv-empty-ok"><i class="fa-solid fa-mug-hot"></i>Todo al día<br>Sin equipos pendientes de retiro</div>';
        } else {
            readyEl.innerHTML = d.listos.map(o => `
                <div class="tv-item tv-item-ready">
                    <div>
                        <div class="tv-item-code">${esc(o.codigo)}</div>
                        <div class="tv-item-sub">${esc(o.equipo)}${o.desde ? ' · listo desde ' + esc(o.desde) : ''}</div>
                    </div>
                    <span class="tv-tag tv-tag-ready"><i class="fa-solid fa-box"></i> Retirar</span>
                </div>`).join('');
        }

        // Columna "En taller"
        const procEl = document.getElementById('tv-process-list');
        if (!d.proceso.length) {
            procEl.innerHTML = '<div class="tv-empty"><i class="fa-solid fa-mug-hot"></i>Todo el taller al día</div>';
        } else {
            procEl.innerHTML = d.proceso.map(o => {
                const [tagCls, icon] = TAGS_PROCESO[o.estado_key] || ['tv-tag-proc', 'fa-screwdriver-wrench'];
                return `
                <div class="tv-item tv-item-process">
                    <div>
                        <div class="tv-item-code">${esc(o.codigo)}</div>
                        <div class="tv-item-sub">${esc(o.equipo)}${o.urgente ? ' · <b style="color:#fca5a5">URGENTE</b>' : ''}</div>
                    </div>
                    <div style="text-align:right">
                        <span class="tv-tag ${tagCls}"><i class="fa-solid ${icon}"></i> ${esc(o.estado)}</span>
                        <div class="tv-mini"><i style="width:${Number(o.avance) || 0}%"></i></div>
                    </div>
                </div>`;
            }).join('');
        }

        document.getElementById('tv-count-ready').textContent = d.counts.listos;
        document.getElementById('tv-count-process').textContent = d.counts.proceso;

        // Chime si hay nuevos turnos listos (y el sonido está activado)
        const nowReady = new Set(d.listos.map(o => o.codigo));
        if (prevReady !== null && soundOn) {
            for (const c of nowReady) {
                if (!prevReady.has(c)) { chime(); break; }
            }
        }
        prevReady = nowReady;
    }
    // Consulta privada "Ver mi turno": cada cliente digita SU código completo
    // y ve SOLO su orden (ruta /r/{codigo}, exige sufijo anti-adivinanza).
    // El resultado se borra solo a los 60 s para que el siguiente cliente
    // no vea datos ajenos.
    let timerPrivado = null;
    function normalizarCodigoTV(valor) {
        const c = String(valor || '').toUpperCase().trim().replace(/\s+/g, '');
        const sinRPT = c.replace(/^RPT-?/, '').replace(/[^A-Z0-9-]/g, '');
        return /^(\d{1,6})(-[A-Z0-9]{4})?$/.test(sinRPT) ? sinRPT : '';
    }
    function pintarPrivado(texto, esOk) {
        const box = document.getElementById('tv-mi-resultado');
        if (!box) return;
        box.hidden = false;
        box.dataset.ok = esOk ? '1' : '0';
        box.textContent = texto;
    }
    async function consultarMiTurno() {
        const input = document.getElementById('tv-mi-codigo');
        if (!input) return;
        const valor = normalizarCodigoTV(input.value);
        if (!valor) {
            pintarPrivado('Ingresa tu código completo de la boleta (ej: RPT-001024-A2B4).', false);
            input.focus();
            return;
        }
        pintarPrivado('Buscando tu turno…', true);
        try {
            const res = await fetch(MI_TURNO_URL + '?codigo=' + encodeURIComponent(valor), { cache: 'no-store' });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.ok) {
                pintarPrivado((data && data.error) || 'No se encontró ese código. Revisa tu boleta.', false);
                return;
            }
            const o = data.orden || {};
            pintarPrivado('Tu turno ' + (o.codigo || valor) + ' (' + (o.equipo || 'tu equipo') + '): ' + (o.estado || 'en seguimiento') +
                ' — avance ' + (o.avance || 0) + '%. Este mensaje se borra solo en 60 s.', true);
            input.value = '';
            if (timerPrivado) clearTimeout(timerPrivado);
            timerPrivado = setTimeout(() => {
                const box = document.getElementById('tv-mi-resultado');
                if (box) { box.hidden = true; box.textContent = ''; }
            }, 60000);
        } catch (e) {
            pintarPrivado('Sin conexión con el taller. Intenta de nuevo.', false);
        }
    }

    async function refresh() {
        try {
            const res = await fetch(DATA_URL, { cache: 'no-store' });
            if (res.ok) render(await res.json());
        } catch (e) { /* reintenta en el próximo ciclo */ }
    }
    refresh();
    setInterval(refresh, 15000);

    // Reloj en tiempo real
    function tick() {
        const n = new Date();
        const p = x => String(x).padStart(2, '0');
        document.getElementById('tv-clock').textContent = p(n.getHours()) + ':' + p(n.getMinutes()) + ':' + p(n.getSeconds());
        document.getElementById('tv-date').textContent = n.toLocaleDateString('es-CL', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    }
    tick();
    setInterval(tick, 1000);

    // Consejos rotativos (15 s) con fundido
    setInterval(() => {
        if (CONSEJOS.length < 2) return;
        consejoIdx = (consejoIdx + 1) % CONSEJOS.length;
        const t = document.getElementById('tv-tip-title');
        const d2 = document.getElementById('tv-tip-desc');
        t.style.opacity = '0';
        d2.style.opacity = '0';
        setTimeout(() => {
            t.textContent = CONSEJOS[consejoIdx].titulo;
            d2.textContent = CONSEJOS[consejoIdx].desc;
            t.style.opacity = '1';
            d2.style.opacity = '1';
        }, 300);
    }, 15000);

    // Activar sonido (requiere interacción del usuario)
    const soundBtn = document.getElementById('tv-sound');
    soundBtn.addEventListener('click', () => {
        soundOn = !soundOn;
        soundBtn.classList.toggle('is-on', soundOn);
        soundBtn.innerHTML = soundOn ? '<i class="fa-solid fa-volume-high"></i> Sonido ON' : '<i class="fa-solid fa-volume-xmark"></i> Sonido';
        if (soundOn) chime();
    });

    // Consulta privada "Ver mi turno" (solo su orden, se auto-borra)
    const btnMi = document.getElementById('tv-mi-btn');
    const inpMi = document.getElementById('tv-mi-codigo');
    if (btnMi) btnMi.addEventListener('click', consultarMiTurno);
    if (inpMi) inpMi.addEventListener('keydown', ev => { if (ev.key === 'Enter') consultarMiTurno(); });

    // Pantalla completa
    document.getElementById('tv-fs').addEventListener('click', () => {
        if (!document.fullscreenElement) {
            if (document.documentElement.requestFullscreen) document.documentElement.requestFullscreen();
        } else if (document.exitFullscreen) {
            document.exitFullscreen();
        }
    });

    // Auto-ocultar controles y cursor cuando la TV está inactiva
    let idleTimer;
    function wake() {
        document.body.classList.remove('tv-idle');
        clearTimeout(idleTimer);
        idleTimer = setTimeout(() => document.body.classList.add('tv-idle'), 4000);
    }
    ['mousemove', 'keydown', 'click', 'touchstart'].forEach(ev => window.addEventListener(ev, wake));
    wake();
</script>
</body>
</html>