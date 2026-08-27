/* ==========================================================================
   LUITECH — tools.js
   Herramientas del portal: Cotizador, Test de salud y Asistente virtual.
   Contenido dinámico siempre con textContent/Node API (sin innerHTML de datos).
   ========================================================================== */
(function () {
  'use strict';

  var $ = function (id) { return document.getElementById(id); };

  /* ------------------------------------------------------- COTIZADOR */
  var COTIZADOR_DATA = {
    celular: {
      modelos: ['iPhone 13', 'iPhone 14', 'Samsung Galaxy S22', 'Samsung Galaxy S23', 'Xiaomi Redmi Note 12', 'Otro Modelo (Consultar por WhatsApp)'],
      fallas: [
        { nombre: 'Cambio de Pantalla OLED',                    precio: '$55.000 - $75.000', tiempo: '45 minutos' },
        { nombre: 'Cambio de Batería Original',                 precio: '$25.000 - $35.000', tiempo: '30 minutos' },
        { nombre: 'Reparación de Puerto de Carga',              precio: '$18.000 - $25.000', tiempo: '40 minutos' },
        { nombre: 'Otro Problema (Consultar por WhatsApp)',     precio: 'Precio a convenir', tiempo: 'Sujeto a diagnóstico' }
      ]
    },
    notebook: {
      modelos: ['MacBook Air/Pro', 'Asus ROG / TUF', 'HP Pavilion / Victus', 'Lenovo IdeaPad / ThinkPad', 'Otro Modelo (Consultar por WhatsApp)'],
      fallas: [
        { nombre: 'Mantenimiento Térmico Completo',             precio: '$25.000',           tiempo: '1 hora y media' },
        { nombre: 'Instalación de SSD + Sistema',               precio: '$35.000 - $55.000', tiempo: '2 horas' },
        { nombre: 'Reparación de Bisagras / Carcasa',           precio: '$30.000 - $45.000', tiempo: '24 horas' },
        { nombre: 'Otro Problema (Consultar por WhatsApp)',     precio: 'Precio a convenir', tiempo: 'Sujeto a diagnóstico' }
      ]
    },
    pc: {
      modelos: ['PC Gamer Armado', 'PC de Oficina / Torre', 'Otro Modelo (Consultar por WhatsApp)'],
      fallas: [
        { nombre: 'Diagnóstico de Falla de Encendido',          precio: '$15.000',           tiempo: '1 hora' },
        { nombre: 'Limpieza y Cambio de Pasta Térmica',         precio: '$20.000',           tiempo: '1 hora' },
        { nombre: 'Armado Completo de PC Gamer',                precio: '$40.000',           tiempo: '3 horas' },
        { nombre: 'Otro Problema (Consultar por WhatsApp)',     precio: 'Precio a convenir', tiempo: 'Sujeto a diagnóstico' }
      ]
    }
  };

  var CLASE_BTN_ACTIVO = {
    cotizador: 'bg-cyan-500 text-slate-950 font-bold px-5 py-2.5 rounded-xl transition-all flex items-center gap-2 text-sm shadow-lg shadow-cyan-500/10',
    test:      'bg-emerald-500 text-slate-950 font-bold px-5 py-2.5 rounded-xl transition-all flex items-center gap-2 text-sm shadow-lg shadow-emerald-500/10',
    chat:      'bg-blue-500 text-slate-950 font-bold px-5 py-2.5 rounded-xl transition-all flex items-center gap-2 text-sm shadow-lg shadow-blue-500/10'
  };
  var CLASE_BTN_BASE = 'bg-slate-900 hover:bg-slate-800 text-slate-300 font-bold px-5 py-2.5 rounded-xl transition-all flex items-center gap-2 text-sm border border-slate-800';

  function switchTool(tool) {
    ['cotizador', 'test', 'chat'].forEach(function (t) {
      var panel = $('tool-' + t);
      if (panel) panel.classList.toggle('hidden', t !== tool);
      var boton = $('btn-tool-' + t);
      if (boton) boton.className = t === tool ? CLASE_BTN_ACTIVO[t] : CLASE_BTN_BASE;
    });
    if (tool === 'test') reiniciarTest();
  }

  function actualizarModelosCotizador() {
    var tipo = $('cotizar-tipo').value;
    var modeloSelect = $('cotizar-modelo');
    var fallaSelect  = $('cotizar-falla');

    modeloSelect.innerHTML = '';
    COTIZADOR_DATA[tipo].modelos.forEach(function (mod) {
      modeloSelect.add(new Option(mod, mod));
    });

    fallaSelect.innerHTML = '';
    COTIZADOR_DATA[tipo].fallas.forEach(function (falla, idx) {
      fallaSelect.add(new Option(falla.nombre, String(idx)));
    });

    calcularCotizacion();
  }

  function calcularCotizacion() {
    var tipo   = $('cotizar-tipo').value;
    var indice = parseInt($('cotizar-falla').value, 10);
    var falla  = COTIZADOR_DATA[tipo].fallas[indice];
    if (!falla) return;

    $('cotizar-precio').textContent = falla.precio;
    $('cotizar-tiempo').textContent = 'Tiempo estimado: ' + falla.tiempo;
  }

  function enviarCotizacionWhatsApp() {
    var tipo       = $('cotizar-tipo');
    var textoTipo  = tipo.options[tipo.selectedIndex].text;
    var modelo     = $('cotizar-modelo').value;
    var fallaSel   = $('cotizar-falla');
    var nombreFalla= fallaSel.options[fallaSel.selectedIndex].text;
    var precio     = $('cotizar-precio').textContent;
    var tiempo     = $('cotizar-tiempo').textContent.replace('Tiempo estimado: ', '');

    var mensaje =
      '*COTIZACIÓN ONLINE - LUITECH*\n\n' +
      '📱 *Dispositivo:* ' + textoTipo + '\n' +
      '🔧 *Modelo:* ' + modelo + '\n' +
      '⚠️ *Servicio:* ' + nombreFalla + '\n' +
      '💰 *Presupuesto:* ' + precio + '\n' +
      '⏱️ *Tiempo:* ' + tiempo + '\n\n' +
      'Quiero confirmar esta cotización para mi equipo.';

    window.open(
      'https://api.whatsapp.com/send?phone=' + window.LUITECH_WA + '&text=' + encodeURIComponent(mensaje),
      '_blank',
      'noopener'
    );
    window.mostrarToast('Cotización enviada por WhatsApp', 'success');
  }

  /* ------------------------------------------------------ TEST DE SALUD */
  var TEST_PREGUNTAS = [
    { texto: '¿Qué edad aproximada tiene tu dispositivo?',
      opciones: [
        { texto: 'Menos de 1 año',                        score: 25 },
        { texto: 'Entre 1 y 3 años',                      score: 15 },
        { texto: 'Más de 3 años',                         score: 5 }
      ] },
    { texto: '¿Cómo calificarías la duración de la batería o rendimiento térmico?',
      opciones: [
        { texto: 'Excelente, dura todo el día / no se calienta', score: 25 },
        { texto: 'Se descarga rápido / se calienta un poco',     score: 15 },
        { texto: 'Se apaga de la nada / hierve al usarlo',       score: 5 }
      ] },
    { texto: '¿Has notado lentitud al abrir aplicaciones o encender el equipo?',
      opciones: [
        { texto: 'Todo rápido y fluido',                  score: 25 },
        { texto: 'A veces se demora en abrir programas',  score: 15 },
        { texto: 'Constantemente lento / se cuelga',      score: 5 }
      ] },
    { texto: '¿Cuándo fue la última vez que se le hizo una mantención interna?',
      opciones: [
        { texto: 'Hace menos de 6 meses',                 score: 25 },
        { texto: 'Hace más de 1 año',                     score: 15 },
        { texto: 'Nunca se ha abierto',                   score: 5 }
      ] }
  ];

  var testPreguntaActual = 0;
  var testScoreTotal = 0;

  function reiniciarTest() {
    testPreguntaActual = 0;
    testScoreTotal = 0;
    $('test-resultado').classList.add('hidden');
    $('test-preguntas').classList.remove('hidden');
    mostrarPreguntaTest();
  }

  function mostrarPreguntaTest() {
    var pregunta = TEST_PREGUNTAS[testPreguntaActual];
    var contenedor = $('pregunta-container');
    contenedor.replaceChildren();

    var titulo = document.createElement('p');
    titulo.className = 'text-sm font-bold text-slate-200';
    titulo.textContent = 'Pregunta ' + (testPreguntaActual + 1) + '/' + TEST_PREGUNTAS.length + ': ' + pregunta.texto;
    contenedor.appendChild(titulo);

    var opcionesDiv = document.createElement('div');
    opcionesDiv.className = 'grid grid-cols-1 sm:grid-cols-2 gap-2.5';

    pregunta.opciones.forEach(function (op) {
      var boton = document.createElement('button');
      boton.type = 'button';
      boton.className = 'bg-slate-900 hover:bg-slate-800 border border-slate-700 text-slate-200 text-xs font-semibold px-4 py-3 rounded-xl transition-all text-left';
      boton.textContent = op.texto; // seguro
      boton.addEventListener('click', function () { responderPreguntaTest(op.score); });
      opcionesDiv.appendChild(boton);
    });

    contenedor.appendChild(opcionesDiv);
  }

  function responderPreguntaTest(score) {
    testScoreTotal += score;
    testPreguntaActual++;
    if (testPreguntaActual < TEST_PREGUNTAS.length) {
      mostrarPreguntaTest();
    } else {
      mostrarResultadoTest();
    }
  }

  function mostrarResultadoTest() {
    $('test-preguntas').classList.add('hidden');
    var porcentaje = Math.round((testScoreTotal / (TEST_PREGUNTAS.length * 25)) * 100);

    var nivel, recomendacion, color;
    if (porcentaje >= 75) {
      nivel = 'Excelente'; color = 'text-emerald-400';
      recomendacion = 'Tu equipo está en óptimas condiciones. Mantén las actualizaciones al día y realiza una limpieza preventiva cada 12 meses.';
    } else if (porcentaje >= 45) {
      nivel = 'Regular'; color = 'text-cyan-400';
      recomendacion = 'Tu equipo necesita algo de atención. Un mantenimiento térmico y una optimización de sistema le devolverán vida.';
    } else {
      nivel = 'Crítico'; color = 'text-red-400';
      recomendacion = 'Tu equipo presenta señales de desgaste severo. Tráelo a diagnóstico gratuito para evitar una falla mayor.';
    }

    var scoreEl = $('test-score-texto');
    scoreEl.textContent = 'Salud: ' + porcentaje + '% (' + nivel + ')';
    scoreEl.className = 'text-2xl font-black mt-1 ' + color;

    var recEl = $('test-recomendacion');
    recEl.textContent = recomendacion;

    $('test-resultado').classList.remove('hidden');
  }

  /* ---------------------------------------------------- ASISTENTE VIRTUAL */
  var RESPUESTAS_CHAT = {
    carga:    'Entiendo, un problema de carga suele ser el puerto o el IC de carga de la placa. Tenemos micro-soldadura avanzada y repuestos originales. El diagnóstico es gratuito.',
    pantalla: 'Trabajamos pantallas OLED, AMOLED y LCD para todas las marcas. El tiempo estimado es de solo 45 minutos y entregamos garantía escrita.',
    bateria:  'Si tu batería dura poco o se apaga, es momento de un cambio. En celulares tardamos 30 minutos y en notebooks 1 hora. ¡Consúltanos por tu modelo!',
    lento:    'La lentitud se soluciona instalando un disco SSD de alta velocidad y optimizando el sistema operativo. ¡Tu notebook quedará 10 veces más rápido!',
    calienta: 'Si se calienta mucho, necesita un mantenimiento térmico urgente (limpieza de ventiladores y cambio de pasta térmica). Esto evita que se queme el procesador.',
    hola:     '¡Hola! Soy el asistente virtual de Luitech. ¿En qué te puedo ayudar? Puedo contarte sobre fallas de carga, pantallas, baterías, lentitud o calentamiento.'
  };

  function burbujaChat(texto, esBot) {
    var fila = document.createElement('div');
    fila.className = 'flex gap-2.5 max-w-[85%] ' + (esBot ? '' : 'ml-auto justify-end');

    if (esBot) {
      var avatar = document.createElement('div');
      avatar.className = 'bg-blue-950 text-blue-400 h-7 w-7 rounded-full flex items-center justify-center flex-shrink-0 font-bold';
      avatar.textContent = 'L';
      fila.appendChild(avatar);
    }

    var globo = document.createElement('div');
    globo.className = esBot
      ? 'bg-slate-900 border border-slate-800 p-2.5 rounded-xl text-slate-300'
      : 'bg-cyan-950 border border-cyan-800 p-2.5 rounded-xl text-cyan-300 text-right';
    globo.textContent = texto; // ← seguro ante XSS
    fila.appendChild(globo);

    return fila;
  }

  function enviarMensajeChat() {
    var input = $('chat-input');
    var texto = input.value.trim();
    if (!texto) return;

    var chatBox = $('chat-box');

    chatBox.appendChild(burbujaChat(texto, false));
    input.value = '';
    chatBox.scrollTop = chatBox.scrollHeight;

    setTimeout(function () {
      var t = texto.toLowerCase();
      var respuesta;
      if (/hola|buen[oa]s|hey/.test(t))                                   respuesta = RESPUESTAS_CHAT.hola;
      else if (/carg|conector|enchufe|pin/.test(t))                       respuesta = RESPUESTAS_CHAT.carga;
      else if (/pantalla|vidrio|display|lcd|oled/.test(t))                respuesta = RESPUESTAS_CHAT.pantalla;
      else if (/bater[ií]a|bateria|pila|carga r[aá]pid/.test(t))          respuesta = RESPUESTAS_CHAT.bateria;
      else if (/lent[oa]|lentitud|pega|colgado/.test(t))                  respuesta = RESPUESTAS_CHAT.lento;
      else if (/calient|calor|ruido|ventilador/.test(t))                  respuesta = RESPUESTAS_CHAT.calienta;
      else respuesta = 'Entiendo tu consulta. Para un diagnóstico exacto y precio detallado, agenda una revisión gratuita en nuestro local o escríbenos por WhatsApp (botón verde abajo).';

      chatBox.appendChild(burbujaChat(respuesta, true));
      chatBox.scrollTop = chatBox.scrollHeight;
    }, 800);
  }

  /* ------------------------------------------------------------ EXPORTS */
  window.switchTool                 = switchTool;
  window.actualizarModelosCotizador = actualizarModelosCotizador;
  window.calcularCotizacion         = calcularCotizacion;
  window.enviarCotizacionWhatsApp   = enviarCotizacionWhatsApp;
  window.reiniciarTest              = reiniciarTest;
  window.enviarMensajeChat          = enviarMensajeChat;

  document.addEventListener('DOMContentLoaded', actualizarModelosCotizador);
})();

