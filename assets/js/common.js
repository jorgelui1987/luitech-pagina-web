/* ==========================================================================
   LUITECH — common.js
   Utilidades compartidas: toast seguro (sin XSS), menú móvil,
   alerta sonora y helper para la API.
   ========================================================================== */
(function () {
  'use strict';

  var WHATSAPP_LUITECH = '56982209690';

  /** Atajo por id */
  function $(id) {
    return document.getElementById(id);
  }

  /** Wrapper de fetch + JSON con manejo de errores */
  function api(url, opciones) {
    opciones = opciones || {};
    return fetch(url, {
      method: opciones.method || 'GET',
      headers: opciones.body ? { 'Content-Type': 'application/json' } : undefined,
      body: opciones.body ? JSON.stringify(opciones.body) : undefined,
      credentials: 'same-origin'
    }).then(function (res) {
      return res.json().catch(function () {
        throw new Error('Respuesta inválida del servidor (HTTP ' + res.status + ' en ' + url + ')');
      });
    });
  }

  /**
   * Notificación interna. Segura: el mensaje se inserta con textContent,
   * nunca con innerHTML (evita XSS).
   */
  function mostrarToast(mensaje, tipo) {
    tipo = tipo || 'error';
    var contenedor = $('toast-container');
    if (!contenedor) return;

    var toast = document.createElement('div');
    toast.className = 'flex items-center gap-3 px-5 py-4 rounded-xl shadow-xl border text-sm font-semibold ' +
      'transition-all duration-300 transform translate-y-5 opacity-0 max-w-md w-full ' +
      (tipo === 'error'
        ? 'bg-red-950 border-red-800 text-red-300'
        : 'bg-emerald-950 border-emerald-800 text-emerald-300');

    var icono = document.createElement('i');
    icono.className = 'text-lg ' + (tipo === 'error'
      ? 'fa-solid fa-circle-xmark text-red-400'
      : 'fa-solid fa-circle-check text-emerald-400');

    var texto = document.createElement('span');
    texto.textContent = mensaje; // ← seguro

    toast.appendChild(icono);
    toast.appendChild(texto);
    contenedor.appendChild(toast);

    setTimeout(function () {
      toast.classList.remove('translate-y-5', 'opacity-0');
    }, 50);

    setTimeout(function () {
      toast.classList.add('translate-y-5', 'opacity-0');
      setTimeout(function () { toast.remove(); }, 300);
    }, 4000);
  }

  /** Menú móvil accesible */
  function toggleMobileMenu() {
    var menu = $('mobile-menu');
    var icono = $('mobile-menu-icon');
    if (!menu || !icono) return;
    var oculto = menu.classList.contains('hidden');
    menu.classList.toggle('hidden', !oculto);
    icono.className = oculto ? 'fa-solid fa-xmark' : 'fa-solid fa-bars';
    var boton = icono.closest('button');
    if (boton) boton.setAttribute('aria-expanded', oculto ? 'true' : 'false');
  }

  /** Año dinámico del footer */
  function ponerAnio() {
    document.querySelectorAll('.anio-dinamico').forEach(function (el) {
      el.textContent = String(new Date().getFullYear());
    });
  }

  /** Tono sintetizado de aviso (Web Audio API), reutilizando un contexto */
  var _ctxAudio = null;
  function emitirAlertaTurno() {
    try {
      var AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) return;
      if (!_ctxAudio || _ctxAudio.state === 'closed') _ctxAudio = new AudioCtx();
      var ctx = _ctxAudio;

      [659.25, 783.99].forEach(function (freq, i) {
        var osc  = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(freq, ctx.currentTime + i * 0.15);
        gain.gain.setValueAtTime(0.15, ctx.currentTime + i * 0.15);
        gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + i * 0.15 + 0.5);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start(ctx.currentTime + i * 0.15);
        osc.stop(ctx.currentTime + i * 0.15 + 0.5);
      });
    } catch (e) {
      console.warn('Audio no permitido aún por interacción del usuario.', e);
    }
  }

  /**
   * Impresión SIN ventanas: el documento se escribe en un iframe oculto
   * reutilizable y se imprime desde ahí. Nunca abre pestañas ni ventanas
   * nuevas: solo aparece el diálogo de impresión del navegador.
   * Espera a que carguen las imágenes (logo/firma) antes de imprimir.
   */
  function imprimirDocumento(html) {
    var marco = document.getElementById('luitech-print-frame');
    if (!marco) {
      marco = document.createElement('iframe');
      marco.id = 'luitech-print-frame';
      marco.style.position = 'fixed';
      marco.style.right = '0';
      marco.style.bottom = '0';
      marco.style.width = '0';
      marco.style.height = '0';
      marco.style.border = '0';
      document.body.appendChild(marco);
    }
    var doc = marco.contentWindow.document;
    doc.open();
    doc.write(html);
    doc.close();
    var imagenes = doc.images;
    var pendientes = imagenes.length;
    var lanzada = false;
    function imprimir() {
      if (lanzada) return;
      lanzada = true;
      try { marco.contentWindow.focus(); } catch (e) {}
      marco.contentWindow.print();
    }
    if (pendientes === 0) { setTimeout(imprimir, 50); return; }
    Array.prototype.forEach.call(imagenes, function (im) {
      im.addEventListener('load', function () { pendientes--; if (pendientes <= 0) imprimir(); });
      im.addEventListener('error', function () { pendientes--; if (pendientes <= 0) imprimir(); });
    });
    setTimeout(imprimir, 2500); // respaldo si una imagen nunca responde
  }

  /**
   * Pestaña externa REUTILIZABLE: todas las aperturas con el mismo nombre
   * de destino reutilizan la misma pestaña (WhatsApp, Mercado Pago) en
   * lugar de acumular una nueva por cada clic.
   */
  function abrirExterno(url) {
    var w = window.open(url, 'luitech-ext');
    if (w && w.focus) { w.focus(); }
    return w;
  }

  // API pública (los onclick del HTML usan estas globales)
  window.LuitechAPI   = api;
  window.mostrarToast = mostrarToast;
  window.imprimirDocumento = imprimirDocumento;
  window.abrirExterno = abrirExterno;
  window.LUITECH_WA = WHATSAPP_LUITECH;

  /* ================== PWA: service worker + botón instalar ================== */
  if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js?v=v4').catch(function () {});
    });
  }

  var eventoInstalar = null;
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    eventoInstalar = e;
    var btn = document.getElementById('btn-pwa-install');
    if (btn) { btn.classList.remove('hidden'); }
  });
  window.luitechInstalarApp = function () {
    if (!eventoInstalar) {
      window.mostrarToast('Usa el menú del navegador → "Instalar app" o "Añadir a pantalla de inicio"', 'error');
      return;
    }
    eventoInstalar.prompt();
    eventoInstalar.userChoice.then(function () {
      eventoInstalar = null;
      var btn = document.getElementById('btn-pwa-install');
      if (btn) { btn.classList.add('hidden'); }
    });
  };
  window.addEventListener('appinstalled', function () {
    var btn = document.getElementById('btn-pwa-install');
    if (btn) { btn.classList.add('hidden'); }
    window.mostrarToast('Luitech instalada en tu dispositivo ✓', 'success');
  });

  /* ================== Escáner de códigos de barras con la cámara ==============
     Overlay dinámico reutilizable + lector ZXing bajo demanda (CDN).
     Uso: window.LuitechScanner.abrir({ titulo, continuo, onDetect(texto, cerrar) })
     Requiere HTTPS (o localhost) para el permiso de cámara. */
  var _scanOverlay = null, _scanVideo = null, _scanTitulo = null,
      _scanEstado = null, _scanContador = null;
  var _lectorZxing = null, _scanCallback = null, _scanContinuo = false;
  var _scanUltimo = '', _scanUltimoTs = 0, _scanTotal = 0;

  /** Descarga ZXing la primera vez que se usa el escáner (no pesa al arrancar). */
  function escanerCargarZxing() {
    if (window.ZXing) return Promise.resolve();
    return new Promise(function (resolver, rechazar) {
      var s = document.createElement('script');
      s.src = 'https://cdn.jsdelivr.net/npm/@zxing/library@0.21.3/umd/index.min.js';
      s.onload = function () { resolver(); };
      s.onerror = function () { rechazar(new Error('No se pudo descargar el lector de códigos (revisa tu conexión)')); };
      document.head.appendChild(s);
    });
  }

  /** Crea una sola vez el overlay a pantalla completa (estilos inline: no
   *  dependen del build de Tailwind). */
  function escanerConstruirOverlay() {
    if (_scanOverlay) return;
    _scanOverlay = document.createElement('div');
    _scanOverlay.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(2,6,23,.97);' +
      'display:none;flex-direction:column;align-items:center;justify-content:center;gap:14px;padding:20px';
    _scanTitulo = document.createElement('p');
    _scanTitulo.style.cssText = 'color:#fff;font-weight:700;font-size:14px;text-align:center;margin:0';
    var visor = document.createElement('div');
    visor.style.cssText = 'position:relative;width:100%;max-width:420px;aspect-ratio:4/3;background:#000;' +
      'border:2px solid #06b6d4;border-radius:16px;overflow:hidden';
    _scanVideo = document.createElement('video');
    _scanVideo.setAttribute('playsinline', 'playsinline');
    _scanVideo.setAttribute('muted', 'muted');
    _scanVideo.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block';
    var marco = document.createElement('div');
    marco.style.cssText = 'position:absolute;inset:14%;border:2px dashed rgba(34,211,238,.6);' +
      'border-radius:10px;pointer-events:none';
    visor.appendChild(_scanVideo);
    visor.appendChild(marco);
    _scanEstado = document.createElement('p');
    _scanEstado.style.cssText = 'color:#67e8f9;font-size:12px;font-weight:600;text-align:center;margin:0;min-height:16px';
    var fila = document.createElement('div');
    fila.style.cssText = 'display:flex;gap:12px;align-items:center';
    _scanContador = document.createElement('span');
    _scanContador.style.cssText = 'color:#22d3ee;font-weight:700;font-size:12px;display:none';
    var btnCerrar = document.createElement('button');
    btnCerrar.type = 'button';
    btnCerrar.textContent = 'Cerrar escáner';
    btnCerrar.style.cssText = 'background:#155e75;color:#a5f3fc;border:0;border-radius:10px;' +
      'padding:10px 18px;font-weight:700;font-size:13px;cursor:pointer';
    btnCerrar.addEventListener('click', function () { escanerCerrar(); });
    fila.appendChild(_scanContador);
    fila.appendChild(btnCerrar);
    _scanOverlay.appendChild(_scanTitulo);
    _scanOverlay.appendChild(visor);
    _scanOverlay.appendChild(_scanEstado);
    _scanOverlay.appendChild(fila);
    document.body.appendChild(_scanOverlay);
  }

  /** Detiene cámara y lector, y oculta el overlay. */
  function escanerCerrar() {
    if (_lectorZxing) { try { _lectorZxing.reset(); } catch (e) {} _lectorZxing = null; }
    if (_scanVideo) { try { _scanVideo.pause(); _scanVideo.srcObject = null; } catch (e) {} }
    if (_scanOverlay) _scanOverlay.style.display = 'none';
    _scanCallback = null;
    _scanContinuo = false;
    if (_scanEstado) { _scanEstado.textContent = ''; _scanEstado.style.color = '#67e8f9'; }
    if (_scanContador) _scanContador.style.display = 'none';
  }

  /**
   * Abre el escáner. onDetect(texto, cerrar) recibe el código leído.
   * En modo continuo la cámara sigue abierta para vender/leer varios seguidos.
   */
  function escanerAbrir(opciones) {
    opciones = opciones || {};
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      // Sin getUserMedia: casi siempre es HTTP (la cámara exige HTTPS) o un
      // navegador antiguo. Se explica EN PANTALLA, no solo en el toast.
      escanerConstruirOverlay();
      _scanTitulo.textContent = opciones.titulo || 'Escáner de códigos de barras';
      _scanEstado.style.color = '#fca5a5';
      _scanEstado.textContent = (window.isSecureContext === false)
        ? '📷 La cámara exige conexión segura: abre el sitio con https:// (ahora estás por ' + location.protocol.replace(':', '') + ') y vuelve a abrir el escáner.'
        : '📷 Este navegador no permite usar la cámara: actualiza Chrome o Safari y reintenta.';
      _scanOverlay.style.display = 'flex';
      return;
    }
    escanerConstruirOverlay();
    escanerCerrar(); // si estaba abierto: detiene la cámara y evita lectores duplicados
    _scanTitulo.textContent = opciones.titulo || 'Apunta la cámara al código de barras';
    _scanEstado.style.color = '#67e8f9';
    _scanEstado.textContent = 'Encendiendo cámara…';
    _scanContinuo = !!opciones.continuo;
    _scanTotal = 0;
    _scanContador.style.display = 'none';
    _scanCallback = opciones.onDetect || null;
    _scanUltimo = '';
    _scanUltimoTs = 0;
    _scanOverlay.style.display = 'flex';

    escanerCargarZxing().then(function () {
      if (!_scanOverlay || _scanOverlay.style.display === 'none') return; // lo cerraron mientras cargaba
      var ZX = window.ZXing;
      _lectorZxing = new ZX.BrowserMultiFormatReader();
      return _lectorZxing.decodeFromConstraints(
        { video: { facingMode: { ideal: 'environment' } } }, // cámara trasera
        _scanVideo,
        function (resultado) {
          if (!resultado || !_scanOverlay || _scanOverlay.style.display === 'none') return;
          var texto = String(resultado.getText() || '').trim();
          if (!texto) return;
          var ahora = Date.now();
          if (texto === _scanUltimo && ahora - _scanUltimoTs < 1600) return; // anti-rebote
          _scanUltimo = texto;
          _scanUltimoTs = ahora;
          if (navigator.vibrate) { try { navigator.vibrate(80); } catch (e) {} }
          if (_scanContinuo) {
            _scanTotal++;
            _scanContador.textContent = 'Escaneados: ' + _scanTotal;
            _scanContador.style.display = 'inline';
            _scanEstado.textContent = 'Leído: ' + texto;
          }
          if (_scanCallback) _scanCallback(texto, escanerCerrar);
          if (!_scanContinuo) escanerCerrar();
        }
      );
    }).catch(function (err) {
      if (!_scanOverlay || _scanOverlay.style.display === 'none') return;
      _scanEstado.style.color = '#fca5a5';
      var nombre = err && err.name;
      if (nombre === 'NotAllowedError' || nombre === 'PermissionDeniedError') {
        // Chrome recuerda el "No" y ya no vuelve a preguntar: hay que
        // re-habilitarlo desde el candado de la barra de direcciones.
        _scanEstado.textContent = 'Permiso de cámara DENEGADO. Toca el candado 🔒 o el ⓘ junto a la dirección del sitio → Permisos → Cámara → Permitir, y abre el escáner otra vez. (iPhone: Ajustes → Safari → Cámara → Permitir)';
      } else if (nombre === 'NotReadableError' || nombre === 'TrackStartError') {
        _scanEstado.textContent = 'La cámara está ocupada por otra aplicación. Ciérrala y vuelve a abrir el escáner.';
      } else if (nombre === 'NotFoundError' || nombre === 'OverconstrainedError') {
        _scanEstado.textContent = 'No se encontró una cámara compatible en este dispositivo.';
      } else {
        _scanEstado.textContent = 'No se pudo abrir la cámara. ' + ((err && err.message) || '');
      }
    });
  }

  window.LuitechScanner = { abrir: escanerAbrir, cerrar: escanerCerrar };

  document.addEventListener('DOMContentLoaded', ponerAnio);
})();
