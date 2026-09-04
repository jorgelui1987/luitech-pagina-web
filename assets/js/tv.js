/* ==========================================================================
   LUITECH — tv.js
   Modo Sala de Espera: turnos en vivo desde la API, reloj y consejos.
   ========================================================================== */
(function () {
  'use strict';

  var api = window.LuitechAPI;
  var $ = function (id) { return document.getElementById(id); };

  var CONSEJOS_TECNICOS = [
    { titulo: 'Cuida tu batería',
      desc: 'Evita que tu celular se descargue por debajo del 20% o cargue sobre el 80% habitualmente para extender la vida útil de tu batería.' },
    { titulo: 'La limpieza salva vidas',
      desc: 'Los notebooks acumulan polvo en sus ventiladores. Un mantenimiento térmico cada 12 meses evita fallas graves en procesador y gráfica.' },
    { titulo: 'Respalda tus archivos',
      desc: 'Ningún disco duro es eterno. Mantén una copia de seguridad de tus fotos y documentos importantes en la nube o disco externo.' },
    { titulo: 'Pantallas Protegidas',
      desc: 'El uso de láminas de vidrio templado o hidrogel absorbe el 80% del impacto en caídas directas. ¡Pregunta por la tuya en el mesón!' }
  ];

  var consejoIndex = 0;
  var codigosListosPrevios = [];

  /** Clave de sala (Configuración → Pantalla TV) leída de la URL: tv.html?clave=XXXX */
  var CLAVE_SALA = (function () {
    try { return String(new URLSearchParams(location.search).get('clave') || ''); }
    catch (e) { return ''; }
  })();

  /* -------------------------------------------------------------- RELOJ */
  function tickReloj() {
    var ahora = new Date();
    $('tv-clock').textContent = ahora.toLocaleTimeString('es-CL', { hour12: false });

    var dias  = ['DOMINGO','LUNES','MARTES','MIÉRCOLES','JUEVES','VIERNES','SÁBADO'];
    var meses = ['ENERO','FEBRERO','MARZO','ABRIL','MAYO','JUNIO','JULIO','AGOSTO','SEPTIEMBRE','OCTUBRE','NOVIEMBRE','DICIEMBRE'];
    $('tv-date').textContent =
      dias[ahora.getDay()] + ' ' + ahora.getDate() + ' ' + meses[ahora.getMonth()] + ' • LA SERENA';
  }

  /* --------------------------------------------------------- TURNOS LIVE */
  function filaTurno(o) {
    var fila = document.createElement('div');
    fila.className = 'bg-slate-950/70 border border-slate-800 p-4 rounded-xl flex justify-between items-center gap-3';

    var izq = document.createElement('div');

    var codigo = document.createElement('span');
    codigo.className = 'text-lg font-black text-white tracking-widest';
    codigo.textContent = o.codigo;

    var equipo = document.createElement('p');
    equipo.className = 'text-xs text-slate-400';
    equipo.textContent = o.equipo; // seguro

    izq.appendChild(codigo);
    izq.appendChild(equipo);

    var listo = o.estado === 'Listo para Retiro';
    var der = document.createElement('span');
    der.className = 'text-xs font-bold px-3 py-1.5 rounded-lg border whitespace-nowrap flex items-center gap-1.5 ' +
      (listo ? 'bg-emerald-950 text-emerald-400 border-emerald-800'
             : 'bg-cyan-950 text-cyan-400 border-cyan-800');

    var icono = document.createElement('i');
    icono.className = listo
      ? 'fa-solid fa-box-open mr-1'
      : (o.estado === 'En Reparación' ? 'fa-solid fa-screwdriver-wrench animate-pulse' : 'fa-solid fa-stethoscope');
    der.appendChild(icono);
    der.appendChild(document.createTextNode(listo ? ' Retirar' : o.estado));

    fila.appendChild(izq);
    fila.appendChild(der);
    return fila;
  }

  /** Aviso visible cuando la pantalla se abrió sin clave o con clave errada. */
  function avisoClaveInvalida() {
    var listo = $('tv-ready-list');
    var proceso = $('tv-process-list');
    [listo, proceso].forEach(function (cont) {
      if (!cont) return;
      cont.replaceChildren(Object.assign(document.createElement('p'), {
        textContent: CLAVE_SALA === ''
          ? 'Esta pantalla se abrió SIN clave: la clave viaja en la dirección. Ábrela como tv.html?clave=TU-CLAVE (cópiala desde Configuración → Pantalla TV).'
          : 'Clave incorrecta en la dirección: ábrela como tv.html?clave=TU-CLAVE (la copias desde Configuración → Pantalla TV).',
        className: 'text-amber-400 text-sm font-bold py-2'
      }));
    });
    var cr = $('tv-count-ready');
    var cp = $('tv-count-process');
    if (cr) cr.textContent = '0';
    if (cp) cp.textContent = '0';
  }

  function cargarDatosTV() {
    api('api/ordenes.php?action=resumen&clave=' + encodeURIComponent(CLAVE_SALA)).then(function (res) {
      if (!res.ok) {
        if (res.error && String(res.error).indexOf('clave') !== -1) avisoClaveInvalida();
        return;
      }

      var listaListos  = [];
      var listaProceso = [];

      res.ordenes.forEach(function (o) {
        if (o.estado === 'Listo para Retiro') listaListos.push(o);
        else listaProceso.push(o);
      });

      var readyList = $('tv-ready-list');
      var procList  = $('tv-process-list');
      readyList.replaceChildren();
      procList.replaceChildren();

      listaListos.forEach(function (o)  { readyList.appendChild(filaTurno(o)); });
      listaProceso.forEach(function (o) { procList.appendChild(filaTurno(o)); });

      if (!listaListos.length) {
        readyList.appendChild(Object.assign(document.createElement('p'), {
          textContent: 'Sin turnos listos por ahora.', className: 'text-slate-500 text-sm italic py-2'
        }));
      }
      if (!listaProceso.length) {
        procList.appendChild(Object.assign(document.createElement('p'), {
          textContent: 'Sin equipos en proceso.', className: 'text-slate-500 text-sm italic py-2'
        }));
      }

      $('tv-count-ready').textContent   = String(listaListos.length);
      $('tv-count-process').textContent = String(listaProceso.length);

      // Alerta sonora si apareció un turno nuevo "Listo" desde la carga anterior
      var codigosActuales = listaListos.map(function (o) { return o.codigo; });
      var nuevos = codigosActuales.filter(function (c) { return codigosListosPrevios.indexOf(c) === -1; });
      if (codigosListosPrevios.length > 0 && nuevos.length > 0) {
        window.emitirAlertaTurno();
      }
      codigosListosPrevios = codigosActuales;
    }).catch(function () {
      console.warn('TV sin conexión con la API; reintentando…');
    });
  }

  /* --------------------------------------------------------- CONSEJOS */
  function iniciarCarruselConsejos() {
    setInterval(function () {
      consejoIndex = (consejoIndex + 1) % CONSEJOS_TECNICOS.length;
      var consejo = CONSEJOS_TECNICOS[consejoIndex];
      var titulo = $('tv-advice-title');
      var desc   = $('tv-advice-desc');

      titulo.style.opacity = '0';
      desc.style.opacity = '0';
      setTimeout(function () {
        titulo.textContent = consejo.titulo;
        desc.textContent   = consejo.desc;
        titulo.style.opacity = '1';
        desc.style.opacity = '1';
      }, 300);
    }, 15000);
  }

  /* ----------------------------------------------------------- ARRANQUE */
  document.addEventListener('DOMContentLoaded', function () {
    tickReloj();
    setInterval(tickReloj, 1000);

    cargarDatosTV();
    setInterval(cargarDatosTV, 15000);

    iniciarCarruselConsejos();
  });
})();

