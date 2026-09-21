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
  /** Normaliza el código del cliente: acepta '1029-K7X2' o 'LUH-1029-K7X2'.
   *  Devuelve la parte después de LUH- ('1029-K7X2') o '' si es inválido. */
  function normalizarCodigo(valor) {
    var c = String(valor || '').toUpperCase().trim().replace(/^LUH-/, '').replace(/[^A-Z0-9\-]/g, '');
    return /^(\d{3,8})(-[A-Z0-9]{4})?$/.test(c) ? c : '';
  }

  function buscarOrdenExpress() {
    var input = $('express-code');
    var valor = normalizarCodigo(input.value);

    if (!valor) {
      window.mostrarToast('Ingresa tu código completo (ej: 1029-K7X2).', 'error');
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
  /* Badge de estado: 'Sin reparación' en ROJO (no verde). Cualquier estado
     desconocido cae a gris, jamás a verde. */
  var CLASES_BADGE = {
    'Listo para Retiro': 'bg-emerald-950 border-emerald-800 text-emerald-400',
    'En Reparación':     'bg-cyan-950 border-cyan-800 text-cyan-400',
    'En Diagnóstico':    'bg-amber-950 border-amber-800 text-amber-400',
    'Ingresado':         'bg-slate-800 border-slate-700 text-slate-300',
    'Entregado':         'bg-slate-800 border-slate-600 text-slate-200',
    'Sin reparación':    'bg-red-950 border-red-700 text-red-300'
  };
  var CLASE_BADGE_DEFAULT = 'bg-slate-800 border-slate-700 text-slate-300';

  /* Etapa activa de la línea de tiempo según el estado (las etapas 3 y 4 se
     llaman por id para no depender del texto del encabezado). */
  var PASO_POR_ESTADO = {
    'Ingresado': 1, 'En Diagnóstico': 2, 'En Reparación': 3,
    'Listo para Retiro': 4, 'Entregado': 4, 'Sin reparación': 2
  };
  var TEXTO_PASO_3 = { 'Sin reparación': 'Sin reparación' };
  var TEXTO_PASO_4 = { 'Sin reparación': 'Retiro en tienda' };

  function cargarDatosEnTracker(o) {
    $('track-ticket-id').textContent   = o.codigo;
    $('track-equipo-desc').textContent = o.equipo;      // sin nombre de cliente ni falla (privacidad)
    $('track-tecnico').textContent     = o.tecnico;
    $('track-fecha').textContent       = formatearFecha(o.fecha_ingreso);

    // Si ya fue entregada (tiene fecha de entrega), eso manda sobre el estado
    var entregada = !!o.fecha_entrega;
    var estadoMostrado = entregada ? 'Entregado' : o.estado;

    var badge = $('track-status-badge');
    badge.className = 'rounded-xl px-5 py-2 flex items-center justify-center gap-2 font-bold text-sm border ' +
      (CLASES_BADGE[estadoMostrado] || CLASE_BADGE_DEFAULT);
    // Punto del badge del color del estado: verde solo para 'Listo para Retiro',
    // rojo para 'Sin reparación', gris para 'Entregado'.
    badge.replaceChildren(
      puntoPulso(estadoMostrado === 'Sin reparación' ? 'red' : (estadoMostrado === 'Entregado' ? 'slate' : 'emerald')),
      document.createTextNode(estadoMostrado));

    // Garantía digital: cuenta regresiva visible para el cliente (si aplica)
    var tg = $('track-garantia');
    if (!tg && badge.parentNode) {
      tg = document.createElement('p');
      tg.id = 'track-garantia';
      badge.parentNode.appendChild(tg);
    }
    if (tg) {
      if (o.garantia_hasta) {
        var dg = parseInt(o.garantia_dias, 10);
        var esNum = !isNaN(dg);
        tg.textContent = esNum
          ? ('Garantía hasta el ' + o.garantia_hasta + ' — ' +
             (dg >= 0 ? 'quedan ' + dg + (dg === 1 ? ' día' : ' días') : 'vencida hace ' + Math.abs(dg) + (Math.abs(dg) === 1 ? ' día' : ' días')))
          : ('Garantía hasta el ' + o.garantia_hasta);
        tg.className = 'text-xs font-bold mt-2 ' +
          (!esNum || dg < 0 ? 'text-red-400' : (dg <= 7 ? 'text-amber-400' : 'text-emerald-400'));
        tg.classList.remove('hidden');
      } else {
        tg.classList.add('hidden');
      }
    }

    // Las etapas se encienden según el ESTADO real de la orden (no solo el %),
    // así nunca quedan desincronizadas del estado que cambia el técnico.
    // 'Sin reparación' se queda en diagnóstico (paso 2) y renombra las
    // etapas 3-4 para no mostrar "Reparación/Listo" como si estuviera arreglado.
    var pasoActivo = PASO_POR_ESTADO[o.estado] || 1;
    if (entregada) pasoActivo = 5;

    var minimos = [10, 30, 60, 90, 100];
    var avanceVisual = Math.max(parseInt(o.avance, 10) || 0, minimos[pasoActivo - 1]);
    // 'Sin reparación' nunca muestra barra llena (no quedó listo para uso).
    if (!entregada && o.estado === 'Sin reparación') avanceVisual = Math.min(avanceVisual, 35);
    $('track-progress-bar').style.width = avanceVisual + '%';

    var lbl3 = document.querySelector('#step-3 + span');
    var lbl4 = document.querySelector('#step-4 + span');
    if (lbl3) {
      if (!lbl3.dataset.orig) lbl3.dataset.orig = lbl3.textContent;
      lbl3.textContent = TEXTO_PASO_3[o.estado] || lbl3.dataset.orig;
    }
    if (lbl4) {
      if (!lbl4.dataset.orig) lbl4.dataset.orig = lbl4.textContent;
      lbl4.textContent = TEXTO_PASO_4[o.estado] || lbl4.dataset.orig;
    }

    // Aviso "Sin reparación": motivo + mensaje del taller + horario de retiro.
    // Es lo que lee el cliente sin WhatsApp al consultar su código.
    // Usa el bloque fijo del HTML (#track-sin-reparacion): visible, en rojo,
    // a ancho completo debajo del tracker (nunca dentro del badge).
    var aviso = $('track-sin-reparacion');
    var avisoTitulo = $('track-sin-titulo');
    var avisoMsg = $('track-sin-mensaje');
    if (aviso) {
      if (!entregada && o.estado === 'Sin reparación') {
        if (avisoTitulo) {
          avisoTitulo.textContent = '⛔ Este equipo no tuvo reparación' +
            (o.motivo_sin_reparacion ? ': ' + o.motivo_sin_reparacion : '');
        }
        if (avisoMsg) {
          avisoMsg.textContent = o.mensaje_publico ||
            'Equipo revisado en laboratorio. Puedes retirar tu equipo en tienda con tu comprobante.';
        }
        aviso.classList.remove('hidden');
      } else {
        aviso.classList.add('hidden');
      }
    }

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

  function puntoPulso(color) {
    // color: 'emerald' (en proceso/listo), 'red' (sin reparación), 'slate' (entregado).
    // El puntito animado antes tenía el verde fijo adentro y por eso 'Sin reparación'
    // se seguía viendo verde aunque el badge ya era rojo.
    var c = (color === 'red') ? 'red' : (color === 'slate' ? 'slate' : 'emerald');
    var span = document.createElement('span');
    span.className = 'relative flex h-2 w-2';
    span.innerHTML =
      '<span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-' + c + '-400 opacity-75"></span>' +
      '<span class="relative inline-flex rounded-full h-2 w-2 bg-' + c + '-500"></span>';
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
        var precarga = normalizarCodigo(new URLSearchParams(location.search).get('c'));
        if (precarga) {
          input.value = precarga;
          buscarOrdenExpress();
        }
      } catch (e) { /* navegadores muy antiguos: queda la consulta manual */ }
    }

    /* Banner de promoción (Configuración → Promoción): público y opcional. */
    api('api/configuracion.php?action=promo').then(function (res) {
      if (!res.ok || !res.promo || !res.promo.visible) return;
      var banner = $('banner-promo');
      var texto = $('banner-promo-texto');
      if (!banner || !texto) return;
      texto.textContent = res.promo.texto; // seguro: textContent
      banner.classList.remove('hidden');
    }).catch(function () {});

    var fecha = $('agenda-fecha');
    if (fecha) fecha.min = new Date().toISOString().slice(0, 10); // no permite fechas pasadas
    if ($('form-agendar')) $('form-agendar').addEventListener('submit', procesarAgenda);
    inicializarMapa();
  });
})();


