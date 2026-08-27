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
        throw new Error('Respuesta inválida del servidor');
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

  // API pública (los onclick del HTML usan estas globales)
  window.LuitechAPI   = api;
  window.mostrarToast = mostrarToast;
  window.toggleMobileMenu = toggleMobileMenu;
  window.emitirAlertaTurno = emitirAlertaTurno;
  window.LUITECH_WA = WHATSAPP_LUITECH;

  document.addEventListener('DOMContentLoaded', ponerAnio);
})();
