/* ==========================================================================
   LUITECH — portal.js
   Portal cliente: consulta express, tracker, agenda WhatsApp y mapa Leaflet.
   Todo el contenido dinámico se inserta con textContent (seguro ante XSS).
   ========================================================================== */
(function () {
  'use strict';

  var api = window.LuitechAPI;
  var $ = function (id) { return document.getElementById(id); };

  /* --------------------------------------------------- CONSULTA EXPRESS */
  function buscarOrdenExpress() {
    var input = $('express-code');
    var valor = input.value.trim();

    if (!/^\d{3,8}$/.test(valor)) {
      window.mostrarToast('Ingresa solo el número de tu código (ej: 1024).', 'error');
      input.focus();
      return;
    }

    var codigo = 'LUH-' + valor;

    api('api/ordenes.php?action=track&codigo=' + encodeURIComponent(codigo))
      .then(function (res) {
        if (!res.ok) {
          window.mostrarToast(res.error || 'Orden no encontrada.', 'error');
          return;
        }
        try {
          cargarDatosEnTracker(res.orden);
          window.mostrarToast('¡Orden encontrada! Código: ' + codigo, 'success');
          $('seguimiento').scrollIntoView({ behavior: 'smooth' });
        } catch (errRender) {
          // Error al pintar la orden (no de conexión): muestra el motivo exacto
          window.mostrarToast('La orden existe pero hubo un error al mostrarla: ' +
            (errRender && errRender.message ? errRender.message : ''), 'error');
          if (window.console && console.error) {
            console.error('[Consulta Express] Error al renderizar el tracker:', errRender);
          }
        }
      })
      .catch(function (err) {
        var detalle = err && err.message ? err.message : 'sin respuesta del servidor';
        window.mostrarToast('No se pudo conectar: ' + detalle, 'error');
        if (window.console && console.error) {
          console.error('[Consulta Express] Falló la petición a api/ordenes.php?action=track — revisa que estés navegando por la dirección correcta del sitio y que Laragon (Apache + MySQL) esté iniciado.', err);
        }
      });
  }
  window.buscarOrdenExpress = buscarOrdenExpress;

  /** Convierte 'YYYY-MM-DD' en 'DD/MM/YYYY' (si el formato no cuadra, lo deja igual). */
  function formatearFecha(fecha) {
    var partes = String(fecha || '').split('-');
    return partes.length === 3 ? partes[2] + '/' + partes[1] + '/' + partes[0] : String(fecha || '');
  }

  /* ------------------------------------------------------------ TRACKER */
  var CLASES_BADGE = {
    'Listo para Retiro': 'bg-emerald-950 border-emerald-800 text-emerald-400',
    'En Reparación':     'bg-cyan-950 border-cyan-800 text-cyan-400',
    'En Diagnóstico':    'bg-amber-950 border-amber-800 text-amber-400',
    'Ingresado':         'bg-slate-800 border-slate-700 text-slate-300',
    'Entregado':         'bg-emerald-950 border-emerald-800 text-emerald-400'
  };

  function cargarDatosEnTracker(o) {
    $('track-ticket-id').textContent   = o.codigo;
    $('track-equipo-desc').textContent = o.equipo;              // sin nombre de cliente (privacidad)
    $('track-tecnico').textContent     = o.tecnico;
    $('track-falla').textContent       = o.falla;
    $('track-fecha').textContent       = formatearFecha(o.fecha_ingreso);

    // Si ya fue entregada (tiene fecha de entrega), eso manda sobre el estado
    var entregada = !!o.fecha_entrega;
    var estadoMostrado = entregada ? 'Entregado' : o.estado;

    var badge = $('track-status-badge');
    badge.className = 'rounded-xl px-5 py-2 flex items-center justify-center gap-2 font-bold text-sm border ' +
      (CLASES_BADGE[estadoMostrado] || CLASES_BADGE['Ingresado']);
    badge.replaceChildren(puntoPulso(), document.createTextNode(estadoMostrado));

    // Las etapas se encienden según el ESTADO real de la orden (no solo el %),
    // así nunca quedan desincronizadas del estado que cambia el técnico.
    var pasoActivo = ({ 'Ingresado': 1, 'En Diagnóstico': 2, 'En Reparación': 3, 'Listo para Retiro': 4 })[o.estado] || 1;
    if (entregada) pasoActivo = 5;

    var minimos = [10, 30, 60, 90, 100];
    var avanceVisual = Math.max(parseInt(o.avance, 10) || 0, minimos[pasoActivo - 1]);
    $('track-progress-bar').style.width = avanceVisual + '%';

    for (var i = 1; i <= 5; i++) {
      var paso = $('step-' + i);
      if (!paso) continue;
      var activo = i <= pasoActivo;
      paso.className = 'w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold shadow-lg transition-all duration-500 ' +
        (activo
          ? (i === 5 ? 'bg-emerald-500 text-slate-950 shadow-emerald-500/20' : 'bg-cyan-500 text-slate-950 shadow-cyan-500/20')
          : 'bg-slate-800 text-slate-500');
    }
  }

  function puntoPulso() {
    var span = document.createElement('span');
    span.className = 'relative flex h-2 w-2';
    span.innerHTML =
      '<span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>' +
      '<span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>';
    return span;
  }

  /* ------------------------------------------------------------- AGENDA */
  function validarTelefonoChile(telefono) {
    var limpio = telefono.replace(/[\s\-().]/g, '');
    return /^(\+?56)?[23456789]\d{8}$/.test(limpio);
  }

  function procesarAgenda(event) {
    event.preventDefault();

    var nombre   = $('agenda-nombre').value.trim();
    var telefono = $('agenda-telefono').value.trim();
    var tipo     = $('agenda-tipo').value;
    var modelo   = $('agenda-modelo').value.trim();
    var fecha    = $('agenda-fecha').value;
    var falla    = $('agenda-falla').value.trim();
    var errorBox = $('agenda-error');

    var errores = [];
    if (nombre.length < 3)               errores.push('el nombre completo es obligatorio');
    if (!validarTelefonoChile(telefono)) errores.push('el teléfono debe ser chileno válido (ej: +56 9 1234 5678)');
    if (modelo.length < 2)               errores.push('indica marca y modelo');
    if (!fecha)                          errores.push('selecciona una fecha');
    else if (fecha < new Date().toISOString().slice(0, 10)) errores.push('la fecha no puede ser pasada');
    if (falla.length < 10)               errores.push('describe la falla con al menos 10 caracteres');

    if (errores.length) {
      errorBox.textContent = 'Revisa el formulario: ' + errores.join('; ') + '.';
      errorBox.classList.remove('hidden');
      return;
    }
    errorBox.classList.add('hidden');

    var mensaje =
      '*NUEVA SOLICITUD DE DIAGNÓSTICO - LUITECH*\n\n' +
      '👤 *Cliente:* ' + nombre + '\n' +
      '📞 *Teléfono:* ' + telefono + '\n' +
      '💻 *Equipo:* ' + tipo + ' - ' + modelo + '\n' +
      '📅 *Fecha Solicitada:* ' + fecha + '\n' +
      '🔧 *Falla/Requerimiento:* ' + falla;

    window.open(
      'https://api.whatsapp.com/send?phone=' + window.LUITECH_WA +
      '&text=' + encodeURIComponent(mensaje),
      '_blank',
      'noopener'
    );

    $('form-agendar').reset();
    $('booking-success').classList.remove('hidden');
    window.mostrarToast('¡Agenda enviada por WhatsApp!', 'success');
    setTimeout(function () { $('booking-success').classList.add('hidden'); }, 6000);
  }
  window.procesarAgenda = procesarAgenda;

  /* ---------------------------------------------------------------- MAPA */
  function inicializarMapa() {
    if (!$('map') || typeof L === 'undefined') return;
    try {
      var coords = [-29.9024, -71.2482]; // B. O'Higgins 564, La Serena

      var map = L.map('map', {
        center: coords,
        zoom: 17,
        zoomControl: true,
        scrollWheelZoom: false
      });

      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
      }).addTo(map);

      $('map').classList.add('dark-map');

      L.marker(coords).addTo(map).bindPopup(
        '<div style="font-family:\'DM Sans\',sans-serif;text-align:center;padding:4px;">' +
        '<b style="color:#22d3ee;font-size:14px;font-weight:800;">LUITECH</b><br>' +
        '<span style="font-size:12px;color:#f1f5f9;font-weight:600;">Persa Las Cenizas, Local 13</span><br>' +
        '<span style="font-size:11px;color:#94a3b8;">Calle Bernardo O\'Higgins 564<br>La Serena, Coquimbo</span>' +
        '</div>'
      ).openPopup();
    } catch (error) {
      console.error('Error al montar el mapa interactivo:', error);
    }
  }

  /* ----------------------------------------------------------- ARRANQUE */
  document.addEventListener('DOMContentLoaded', function () {
    var input = $('express-code');
    if (input) {
      input.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') buscarOrdenExpress();
      });
      /* Link/QR con el código precargado (tallerluitech.fun/?c=1024): el cliente
         toca el enlace o escanea el QR de su boleta y el tracker se muestra solo. */
      try {
        var precarga = new URLSearchParams(location.search).get('c');
        var digitos = String(precarga || '').replace(/\D/g, '');
        if (digitos && /^\d{3,8}$/.test(digitos)) {
          input.value = digitos;
          buscarOrdenExpress();
        }
      } catch (e) { /* navegadores muy antiguos: queda la consulta manual */ }
    }
    var fecha = $('agenda-fecha');
    if (fecha) fecha.min = new Date().toISOString().slice(0, 10); // no permite fechas pasadas
    if ($('form-agendar')) $('form-agendar').addEventListener('submit', procesarAgenda);
    inicializarMapa();
  });
})();


