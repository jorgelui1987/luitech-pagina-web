/* ==========================================================================
   LUITECH — admin.js
   Panel de control del taller: login con sesión PHP y CRUD de órdenes.
   ========================================================================== */
(function () {
  'use strict';

  var api = window.LuitechAPI;
  var $ = function (id) { return document.getElementById(id); };
  var empresaCfg = null;   // configuración de empresa (logo, nombre, moneda, términos)
  var clientesCache = [];  // registro de clientes (para teléfonos y autocompletado)
  var simboloMoneda = '$'; // viene de Configuración (moneda_simbolo)
  var mpTimer = null;      // monitoreo del pago por Mercado Pago

  /* --------------------------------------------------------- SESIÓN */
  function mostrarVista(logueado, nombre) {
    $('view-login').classList.toggle('hidden', logueado);
    $('view-panel').classList.toggle('hidden', !logueado);
    if (logueado && nombre) $('admin-nombre').textContent = nombre;
    if (logueado) { cargarTecnicos(); cargarClientesLista(); aplicarClientePendiente(); }
    if (!logueado) $('usuario').focus();
  }

  function iniciarSesion(event) {
    event.preventDefault();
    var boton = $('btn-login');
    boton.disabled = true;

    api('api/auth.php?action=login', {
      method: 'POST',
      body: { usuario: $('usuario').value.trim(), password: $('password').value }
    }).then(function (res) {
      if (!res.ok) {
        window.mostrarToast(res.error || 'No se pudo iniciar sesión', 'error');
        return;
      }
      window.mostrarToast('Bienvenido/a, ' + res.nombre, 'success');
      renderizarTablaAdmin();
      mostrarVista(true, res.nombre);
    }).catch(function () {
      window.mostrarToast('Error de conexión con el servidor', 'error');
    }).finally(function () {
      boton.disabled = false;
      $('password').value = '';
    });
  }

  function cerrarSesion() {
    api('api/auth.php?action=logout', { method: 'POST' }).then(function () {
      mostrarVista(false);
      window.mostrarToast('Sesión cerrada', 'success');
    });
  }

  /* ---------------------------------------------------------- TABLA */
  var OPCIONES_ESTADO = ['Ingresado', 'En Diagnóstico', 'En Reparación', 'Listo para Retiro'];

  function celda(texto, clase) {
    var td = document.createElement('td');
    td.className = clase || 'p-3 text-slate-300';
    td.textContent = texto; // seguro
    return td;
  }

  /** Formatea un monto entero como moneda sin decimales (ej. $ 12.500). */
  function monto(valor) {
    return simboloMoneda + ' ' + Math.max(0, Math.round(Number(valor) || 0)).toLocaleString('es-CL');
  }

  /** Carga la configuración de empresa (logo, nombre, moneda, términos). */
  function cargarConfigAdmin() {
    api('api/configuracion.php?action=get_all').then(function (res) {
      if (!res.ok) return;
      empresaCfg = res.config || {};
      simboloMoneda = (empresaCfg.moneda_simbolo || '$').slice(0, 5);
      var gd = parseInt(empresaCfg.garantia_dias_default, 10);
      if (!isNaN(gd) && gd > 0) $('new-garantia').value = String(gd);
    }).catch(function () {});
  }

  /** Saldo pendiente de una orden (nunca negativo). */
  function saldoDe(o) {
    return Math.max(0, (parseInt(o.total, 10) || 0) - (parseInt(o.abono, 10) || 0));
  }

  /** Chip visual del estado de pago (Pagado / Abonado / Pendiente). */
  function chipPago(estadoPago) {
    var chip = document.createElement('span');
    var clase = estadoPago === 'Pagado' ? 'pagado' : (estadoPago === 'Abonado' ? 'abonado' : 'pendiente');
    chip.className = 'chip-pago ' + clase;
    chip.textContent = estadoPago || 'Pendiente';
    return chip;
  }

  /** Total en vivo del presupuesto de la nueva orden (= precio al cliente). */
  function calcularTotalNueva() {
    var precio = parseInt($('new-precio').value, 10) || 0;
    $('new-total').value = monto(precio);
  }

  function selectEstado(codigo, actual) {
    var select = document.createElement('select');
    select.className = 'bg-slate-900 border border-slate-800 rounded px-2 py-1 text-xs text-slate-200 focus:outline-none focus:border-amber-500';
    OPCIONES_ESTADO.forEach(function (estado) {
      var option = new Option(estado, estado);
      if (estado === actual) option.selected = true;
      select.add(option);
    });
    select.addEventListener('change', function () {
      actualizarOrden({ codigo: codigo, estado: this.value });
    });
    return select;
  }

  function inputAvance(codigo, avance) {
    var contenedor = document.createElement('div');
    contenedor.className = 'flex items-center gap-1';

    var input = document.createElement('input');
    input.type = 'number';
    input.min = '0';
    input.max = '100';
    input.value = String(avance);
    input.className = 'w-12 bg-slate-900 border border-slate-800 rounded px-1.5 py-0.5 text-center text-slate-200';
    input.addEventListener('change', function () {
      actualizarOrden({ codigo: codigo, avance: parseInt(this.value, 10) || 0 });
    });

    contenedor.appendChild(input);
    contenedor.appendChild(Object.assign(document.createElement('span'), { textContent: '%', className: 'text-slate-500' }));
    return contenedor;
  }

  function botonEliminar(codigo) {
    var boton = document.createElement('button');
    boton.type = 'button';
    boton.title = 'Eliminar orden';
    boton.setAttribute('aria-label', 'Eliminar orden ' + codigo);
    boton.className = 'bg-red-950/40 hover:bg-red-900/60 text-red-400 border border-red-900/60 p-2 rounded-lg transition-all';
    boton.innerHTML = '<i class="fa-solid fa-trash-can pointer-events-none"></i>';
    boton.addEventListener('click', function () { eliminarOrden(codigo); });
    return boton;
  }

  function botonVer(codigo) {
    var boton = document.createElement('button');
    boton.type = 'button';
    boton.title = 'Ver acta de recepción (fotos y firma)';
    boton.className = 'bg-slate-800 hover:bg-slate-700 text-cyan-400 border border-slate-700 p-2 rounded-lg transition-all mr-1';
    boton.innerHTML = '<i class="fa-solid fa-clipboard-check pointer-events-none"></i>';
    boton.addEventListener('click', function () { abrirModalOrden(codigo); });
    return boton;
  }

  /* ------------------------------------ ACTA DE RECEPCIÓN (fotos + firma) */
  var MAX_FOTOS_NUEVA = 6;   // al crear la orden (el resto se añade desde el detalle)
  var fotosNueva = [];
  var ordenesCache = [];
  var lienzoFirma = null;
  var ctxFirma = null;
  var firmaHecha = false;

  function prepararLienzoFirma(preservar) {
    if (!lienzoFirma) return;
    var previa = preservar ? lienzoFirma.toDataURL() : null;
    var r = lienzoFirma.getBoundingClientRect();
    if (r.width < 10) return;
    lienzoFirma.width = Math.round(r.width);
    lienzoFirma.height = Math.round(r.height);
    ctxFirma.fillStyle = '#ffffff';
    ctxFirma.fillRect(0, 0, lienzoFirma.width, lienzoFirma.height);
    ctxFirma.lineWidth = 2.5;
    ctxFirma.lineCap = 'round';
    ctxFirma.lineJoin = 'round';
    ctxFirma.strokeStyle = '#0f172a';
    if (previa) {
      var im = new Image();
      im.onload = function () { ctxFirma.drawImage(im, 0, 0); };
      im.src = previa;
    }
  }

  function limpiarFirmaNueva() {
    firmaHecha = false;
    prepararLienzoFirma(false);
  }

  function iniciarFirma() {
    lienzoFirma = $('new-firma');
    if (!lienzoFirma) return;
    ctxFirma = lienzoFirma.getContext('2d');
    var dibujando = false;

    function posicion(e) {
      var r = lienzoFirma.getBoundingClientRect();
      return { x: e.clientX - r.left, y: e.clientY - r.top };
    }

    lienzoFirma.addEventListener('pointerdown', function (e) {
      e.preventDefault();
      lienzoFirma.setPointerCapture(e.pointerId);
      dibujando = true;
      firmaHecha = true;
      var p = posicion(e);
      ctxFirma.beginPath();
      ctxFirma.moveTo(p.x, p.y);
      ctxFirma.lineTo(p.x + 0.1, p.y + 0.1);
      ctxFirma.stroke();
    });
    lienzoFirma.addEventListener('pointermove', function (e) {
      if (!dibujando) return;
      var p = posicion(e);
      ctxFirma.lineTo(p.x, p.y);
      ctxFirma.stroke();
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) {
      lienzoFirma.addEventListener(ev, function () { dibujando = false; });
    });

    $('btn-firma-limpiar').addEventListener('click', limpiarFirmaNueva);
    window.addEventListener('resize', function () { prepararLienzoFirma(true); });
    prepararLienzoFirma(false);
  }

  /** Comprime una imagen en el navegador (máx. 1200px, JPEG 72%) → dataURL. */
  function comprimirFoto(file) {
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        var MAX = 1200;
        var w = img.naturalWidth, h = img.naturalHeight;
        var escala = Math.min(1, MAX / Math.max(w, h));
        w = Math.round(w * escala);
        h = Math.round(h * escala);
        var c = document.createElement('canvas');
        c.width = w;
        c.height = h;
        c.getContext('2d').drawImage(img, 0, 0, w, h);
        URL.revokeObjectURL(url);
        resolve(c.toDataURL('image/jpeg', 0.72));
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(null); };
      img.src = url;
    });
  }

  /** dataURL 'data:image/jpeg;base64,...' → Blob listo para FormData. */
  function dataUrlABlob(dataUrl) {
    var partes = dataUrl.split(',');
    var mime = (partes[0].match(/data:(.*?);/) || [])[1] || 'image/jpeg';
    var bin = atob(partes[1]);
    var bytes = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
    return new Blob([bytes], { type: mime });
  }

  /** Sube una foto ya comprimida a una orden existente (multipart). */
  function subirFotoUnica(codigo, dataUrl) {
    var fd = new FormData();
    fd.append('codigo', codigo);
    fd.append('foto', dataUrlABlob(dataUrl), 'foto.jpg');
    return fetch('api/ordenes.php?action=subir_foto', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    }).then(function (res) { return res.json(); });
  }

  function renderFotosNueva() {
    var g = $('new-fotos-galeria');
    g.replaceChildren();
    fotosNueva.forEach(function (dataUrl, i) {
      var d = document.createElement('div');
      d.className = 'foto-thumb';
      var img = document.createElement('img');
      img.src = dataUrl;
      img.alt = 'Foto de respaldo ' + (i + 1);
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'foto-borrar';
      b.title = 'Quitar foto';
      b.innerHTML = '<i class="fa-solid fa-xmark pointer-events-none"></i>';
      b.addEventListener('click', function () {
        fotosNueva.splice(i, 1);
        renderFotosNueva();
      });
      d.appendChild(img);
      d.appendChild(b);
      g.appendChild(d);
    });
  }

  function desmarcarChips() {
    document.querySelectorAll('#new-accesorios .chip.chip-on').forEach(function (b) {
      b.classList.remove('chip-on');
    });
  }

  function accesoriosSeleccionados() {
    return Array.prototype.slice.call(document.querySelectorAll('#new-accesorios .chip.chip-on'))
      .map(function (b) { return b.getAttribute('data-acc'); });
  }

  /* --------------------------------- PIN NUMÉRICO / PATRÓN DIBUJABLE 3×3 */
  var patronSecuencia = [];       // ej. [1, 4, 7, 8, 9]
  var lienzoPatron = null;
  var patronTrazando = false;

  /** Fecha de hoy en formato YYYY-MM-DD según el huso horario local (no UTC). */
  function fechaLocalHoy() {
    var h = new Date();
    var mes = String(h.getMonth() + 1).padStart(2, '0');
    var dia = String(h.getDate()).padStart(2, '0');
    return h.getFullYear() + '-' + mes + '-' + dia;
  }

  /** Centros de los 9 puntos (numerados 1..9) dentro de un canvas 3×3. */
  function puntosPatronDe(canvas) {
    var lado = Math.min(canvas.width, canvas.height);
    var margen = lado * 0.2;
    var paso = (lado - 2 * margen) / 2;
    var puntos = [];
    for (var fila = 0; fila < 3; fila++) {
      for (var col = 0; col < 3; col++) {
        puntos.push({ n: fila * 3 + col + 1, x: margen + col * paso, y: margen + fila * paso });
      }
    }
    return puntos;
  }

  /** Dibuja puntos 1..9 + la línea de la secuencia (y el trazo en curso). */
  function dibujarPatronEn(canvas, secuencia, puntoActual) {
    var ctx = canvas.getContext('2d');
    var puntos = puntosPatronDe(canvas);
    var lado = Math.min(canvas.width, canvas.height);
    var radio = lado * 0.09;
    var sel = {};
    secuencia.forEach(function (n) { sel[n] = puntos[n - 1]; });

    ctx.clearRect(0, 0, canvas.width, canvas.height);
    if (secuencia.length) {
      ctx.strokeStyle = '#22d3ee';
      ctx.lineWidth = Math.max(3, lado * 0.025);
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.beginPath();
      ctx.moveTo(sel[secuencia[0]].x, sel[secuencia[0]].y);
      for (var i = 1; i < secuencia.length; i++) {
        ctx.lineTo(sel[secuencia[i]].x, sel[secuencia[i]].y);
      }
      if (puntoActual) ctx.lineTo(puntoActual.x, puntoActual.y);
      ctx.stroke();
    }
    puntos.forEach(function (p) {
      ctx.beginPath();
      ctx.arc(p.x, p.y, radio, 0, Math.PI * 2);
      if (sel[p.n]) {
        ctx.fillStyle = '#22d3ee';
        ctx.fill();
      } else {
        ctx.strokeStyle = '#64748b';
        ctx.lineWidth = 2;
        ctx.stroke();
      }
      ctx.fillStyle = sel[p.n] ? '#0f172a' : '#94a3b8';
      ctx.font = 'bold ' + Math.max(9, Math.round(radio)) + 'px sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(String(p.n), p.x, p.y);
    });
  }

  /** Indica si el acordeón del patrón está desplegado (el cuerpo tiene .abierto). */
  function patronAbierto() {
    var cuerpo = $('patron-cuerpo');
    return !!cuerpo && cuerpo.classList.contains('abierto');
  }

  /** Recalcula el tamaño del lienzo del patrón (tras mostrarlo o redimensionar). */
  function calcularPatron() {
    if (!lienzoPatron || !patronAbierto()) return;
    var r = lienzoPatron.getBoundingClientRect();
    if (r.width < 10) return;
    lienzoPatron.width = Math.round(r.width);
    lienzoPatron.height = Math.round(r.width); // cuadrado
    dibujarPatronEn(lienzoPatron, patronSecuencia); // conserva lo ya trazado
  }

  function seguirPatron(e) {
    var r = lienzoPatron.getBoundingClientRect();
    var px = e.clientX - r.left;
    var py = e.clientY - r.top;
    var tolerancia = Math.min(lienzoPatron.width, lienzoPatron.height) * 0.09 * 1.8;
    puntosPatronDe(lienzoPatron).forEach(function (p) {
      if (patronSecuencia.indexOf(p.n) === -1 && Math.hypot(px - p.x, py - p.y) <= tolerancia) {
        patronSecuencia.push(p.n);
      }
    });
    dibujarPatronEn(lienzoPatron, patronSecuencia, { x: px, y: py });
  }

  function iniciarPatron() {
    lienzoPatron = $('new-patron');
    if (!lienzoPatron) return;
    lienzoPatron.addEventListener('pointerdown', function (e) {
      e.preventDefault();
      lienzoPatron.setPointerCapture(e.pointerId);
      patronTrazando = true;
      patronSecuencia = [];
      seguirPatron(e);
    });
    lienzoPatron.addEventListener('pointermove', function (e) {
      if (patronTrazando) seguirPatron(e);
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) {
      lienzoPatron.addEventListener(ev, function () { patronTrazando = false; });
    });
    $('btn-patron-limpiar').addEventListener('click', function () {
      patronSecuencia = [];
      dibujarPatronEn(lienzoPatron, patronSecuencia);
      actualizarBotonPatron();
    });
    window.addEventListener('resize', calcularPatron);
  }

  /** Acordeón: despliega o guarda (colapsa) el cuadro del patrón 3×3. */
  function alternarPatron() {
    if (!lienzoPatron) return;
    var cuerpo = $('patron-cuerpo');
    var btn = $('btn-patron-toggle');
    if (cuerpo.classList.contains('abierto')) {
      cuerpo.classList.remove('abierto'); // se recoge animado (max-height → 0)
    } else {
      cuerpo.classList.add('abierto');    // se despliega animado (max-height → 340px)
      calcularPatron(); // mide el espacio real y dibuja conservando lo trazado
    }
    if (btn) btn.setAttribute('aria-expanded', patronAbierto() ? 'true' : 'false');
    actualizarBotonPatron();
  }

  /** Etiqueta de la cabecera del acordeón: cerrado / abierto / con patrón ya dibujado. */
  function actualizarBotonPatron() {
    var btn = $('btn-patron-toggle');
    if (!btn) return;
    var etiqueta = $('patron-etiqueta');
    var chevron = $('patron-chevron');
    var abierto = patronAbierto();
    var dibujado = patronSecuencia.length >= 2;
    if (etiqueta) {
      if (abierto) {
        etiqueta.innerHTML = '<i class="fa-solid fa-xmark mr-1"></i> Ocultar patrón';
      } else if (dibujado) {
        etiqueta.innerHTML = '<i class="fa-solid fa-check mr-1"></i> Patrón dibujado: ' + patronSecuencia.join('-');
      } else {
        etiqueta.innerHTML = '<i class="fa-solid fa-plus mr-1"></i> Dibujar patrón';
      }
    }
    if (chevron) chevron.classList.toggle('abierto', abierto);
    btn.classList.toggle('text-cyan-400', dibujado);
  }

  /** Serializa PIN y/o patrón: 'PIN: 1234', 'Patrón: 1-4-7' o ambos juntos. */
  function valorPinPatron() {
    var pin = $('new-pin').value.replace(/\D/g, '');
    var patron = patronSecuencia.length >= 2 ? 'Patrón: ' + patronSecuencia.join('-') : null;
    var pinParte = pin !== '' ? 'PIN: ' + pin : null;
    if (pinParte && patron) return pinParte + ' · ' + patron;
    return patron || pinParte; // null si no hay ninguno
  }

  /** Deja PIN y patrón listos para una nueva orden (acordeón colapsado). */
  function limpiarPinPatron() {
    $('new-pin').value = '';
    patronSecuencia = [];
    var cuerpo = $('patron-cuerpo');
    if (cuerpo) cuerpo.classList.remove('abierto');
    var btn = $('btn-patron-toggle');
    if (btn) btn.setAttribute('aria-expanded', 'false');
    actualizarBotonPatron();
  }

  /** Fila del modal: muestra PIN y/o el patrón redibujado visualmente. */
  function filaPinPatron(valor) {
    if (!valor) {
      return parDato('PIN / Patrón', valor);
    }
    var matchPatron = String(valor).match(/Patr[oó]n:\s*([\d-]+)/i);
    var matchPin = String(valor).match(/PIN:\s*(\d+)/i);
    if (!matchPatron && !matchPin) {
      return parDato('PIN / Patrón', valor); // texto libre de órdenes antiguas
    }

    var wrap = document.createElement('div');
    var dt = document.createElement('dt');
    dt.className = 'text-[10px] uppercase tracking-wider text-slate-500 font-bold';
    dt.textContent = 'PIN / Patrón';
    var dd = document.createElement('dd');
    dd.className = 'text-slate-200 font-semibold';

    if (matchPin) {
      var lineaPin = document.createElement('span');
      lineaPin.className = 'block';
      lineaPin.textContent = 'PIN: ' + matchPin[1];
      dd.appendChild(lineaPin);
    }
    if (matchPatron) {
      var seq = matchPatron[1].split('-')
        .map(function (n) { return parseInt(n, 10); })
        .filter(function (n) { return n >= 1 && n <= 9; });
      var cv = document.createElement('canvas');
      cv.width = 96;
      cv.height = 96;
      cv.className = 'block bg-slate-950 border border-slate-800 rounded-lg mt-1';
      dibujarPatronEn(cv, seq);
      var nota = document.createElement('span');
      nota.className = 'block text-[10px] text-slate-500 mt-0.5';
      nota.textContent = 'Secuencia: ' + seq.join(' → ');
      dd.appendChild(cv);
      dd.appendChild(nota);
    }
    wrap.appendChild(dt);
    wrap.appendChild(dd);
    return wrap;
  }

  /* -------------------------------------------------- RESUMEN DEL TALLER */
  /** Tarjeta de métrica (etiqueta + icono + valor). */
  function tarjetaResumen(etiqueta, icono, valor, colorValor) {
    var d = document.createElement('div');
    d.className = 'bg-slate-950/50 border border-slate-800/80 rounded-xl p-3';
    var p1 = document.createElement('p');
    p1.className = 'text-[10px] uppercase tracking-wider text-slate-500 font-bold';
    p1.textContent = etiqueta;
    var icon = document.createElement('i');
    icon.className = 'fa-solid ' + icono + ' text-cyan-400 mr-1';
    p1.insertBefore(icon, p1.firstChild);
    var p2 = document.createElement('p');
    p2.className = 'text-xl font-extrabold ' + colorValor;
    p2.textContent = valor;
    d.appendChild(p1);
    d.appendChild(p2);
    return d;
  }

  /** Métricas calculadas en vivo desde la lista de órdenes. */
  function renderResumen(ordenes) {
    var panel = $('resumen-panel');
    if (!panel) return;
    var hoy = new Date();
    var mesActual = hoy.getFullYear() + '-' + String(hoy.getMonth() + 1).padStart(2, '0');

    var enTaller = 0, listas = 0, entregadasMes = 0;
    var montoPorCobrar = 0, ordenesPorCobrar = 0;
    ordenes.forEach(function (o) {
      if (o.estado === 'Listo para Retiro') { listas++; } else { enTaller++; }
      var saldo = saldoDe(o);
      if (saldo > 0) { ordenesPorCobrar++; montoPorCobrar += saldo; }
      if ((o.fecha_entrega || '').slice(0, 7) === mesActual) entregadasMes++;
    });

    panel.replaceChildren();
    panel.appendChild(tarjetaResumen('En el taller', 'fa-screwdriver-wrench', String(enTaller), 'text-cyan-400'));
    panel.appendChild(tarjetaResumen('Listas para retiro', 'fa-bell', String(listas), 'text-amber-400'));
    panel.appendChild(tarjetaResumen('Por cobrar', 'fa-money-bill-wave', ordenesPorCobrar ? monto(montoPorCobrar) : '$ 0', ordenesPorCobrar ? 'text-red-400' : 'text-slate-500'));
    panel.appendChild(tarjetaResumen('Entregadas este mes', 'fa-box-open', String(entregadasMes), 'text-emerald-400'));
  }

  function renderizarTablaAdmin() {
    api('api/ordenes.php?action=list').then(function (res) {
      if (!res.ok) {
        if (res.error === 'No autorizado') { mostrarVista(false); return; }
        throw new Error(res.error || 'Error al cargar órdenes');
      }
      ordenesCache = res.ordenes;
      renderResumen(ordenesCache);
      renderFilasOrdenes();
    }).catch(function (e) {
      window.mostrarToast(e.message || 'No se pudo conectar con el servidor', 'error');
    });
  }

  /** Aplica los filtros del buscador sobre la lista cacheada de órdenes. */
  function ordenesFiltradas() {
    var q = ($('buscar-orden') ? $('buscar-orden').value.trim().toLowerCase() : '');
    var estado = ($('filtro-estado-orden') ? $('filtro-estado-orden').value : '');
    var mes = ($('filtro-mes-orden') ? $('filtro-mes-orden').value : '');
    var palabras = q ? q.split(/\s+/) : [];
    return (ordenesCache || []).filter(function (o) {
      if (estado && o.estado !== estado) return false;
      if (mes && (o.fecha_ingreso || '').slice(0, 7) !== mes) return false;
      if (palabras.length) {
        var texto = (o.codigo + ' ' + o.cliente + ' ' + o.equipo + ' ' + o.falla + ' ' +
                     (o.cliente_rut || '') + ' ' + (o.tecnico || '')).toLowerCase();
        for (var i = 0; i < palabras.length; i++) {
          if (texto.indexOf(palabras[i]) === -1) return false;
        }
      }
      return true;
    });
  }

  /** Pinta las filas de la tabla aplicando los filtros activos. */
  function renderFilasOrdenes() {
    var tbody = $('admin-table-body');
    tbody.replaceChildren();
    var lista = ordenesFiltradas();

    if (!(ordenesCache || []).length) {
      var vacio = celda('Aún no hay órdenes registradas. Crea la primera con el formulario superior.', 'p-6 text-center text-slate-500 italic');
      vacio.colSpan = 10;
      tbody.appendChild(vacio);
      return;
    }
    if (!lista.length) {
      var sinRes = celda('Sin resultados para la búsqueda (prueba con menos palabras o cambia los filtros).', 'p-6 text-center text-slate-500 italic');
      sinRes.colSpan = 10;
      tbody.appendChild(sinRes);
      return;
    }

    lista.forEach(function (o) {
        var tr = document.createElement('tr');
        tr.className = 'hover:bg-slate-800/30 transition-colors border-b border-slate-800/40';

        var tdCodigo = celda(o.codigo, 'p-3 font-bold text-cyan-400 font-mono');

        tr.appendChild(tdCodigo);
        tr.appendChild(celda(o.cliente));
        tr.appendChild(celda(o.equipo));
        tr.appendChild(celda(o.falla, 'p-3 text-slate-400 max-w-[220px]'));

        var tdEstado = document.createElement('td');
        tdEstado.className = 'p-3';
        tdEstado.appendChild(selectEstado(o.codigo, o.estado));
        tr.appendChild(tdEstado);

        var tdAvance = document.createElement('td');
        tdAvance.className = 'p-3';
        tdAvance.appendChild(inputAvance(o.codigo, o.avance));
        tr.appendChild(tdAvance);

        tr.appendChild(celda(o.tecnico, 'p-3 text-slate-400'));

        // Cobro: total + chip de estado de pago (y saldo si queda pendiente)
        var tdCobro = document.createElement('td');
        tdCobro.className = 'p-3';
        if ((parseInt(o.total, 10) || 0) > 0) {
          var lineaCobro = document.createElement('div');
          lineaCobro.className = 'flex items-center gap-2';
          var importe = document.createElement('span');
          importe.className = 'font-semibold text-slate-200 whitespace-nowrap';
          importe.textContent = monto(o.total);
          lineaCobro.appendChild(importe);
          lineaCobro.appendChild(chipPago(o.estado_pago));
          tdCobro.appendChild(lineaCobro);
          var saldoN = saldoDe(o);
          if (saldoN > 0) {
            var saldoTxt = document.createElement('div');
            saldoTxt.className = 'text-[10px] text-red-400';
            saldoTxt.textContent = 'Saldo: ' + monto(saldoN);
            tdCobro.appendChild(saldoTxt);
          }
        } else {
          var sinCobro = document.createElement('span');
          sinCobro.className = 'text-slate-600';
          sinCobro.textContent = '—';
          tdCobro.appendChild(sinCobro);
        }
        tr.appendChild(tdCobro);

        tr.appendChild(celda(o.fecha_ingreso, 'p-3 text-slate-500 text-xs'));

        var tdAcciones = document.createElement('td');
        tdAcciones.className = 'p-3 text-center';
        tdAcciones.appendChild(botonVer(o.codigo));
        tdAcciones.appendChild(botonEliminar(o.codigo));
        tr.appendChild(tdAcciones);

        tbody.appendChild(tr);
      });
  }

  /* ------------------------------------------------------- ACCIONES CRUD */
  function actualizarOrden(datos) {
    api('api/ordenes.php?action=update', { method: 'POST', body: datos })
      .then(function (res) {
        if (!res.ok) {
          window.mostrarToast(res.error || 'No se pudo actualizar', 'error');
          return;
        }
        window.mostrarToast('Orden ' + datos.codigo + ' actualizada', 'success');
      })
      .catch(function () {
        window.mostrarToast('Error de conexión con el servidor', 'error');
      });
  }

  function eliminarOrden(codigo) {
    if (!confirm('¿Estás seguro de eliminar la orden ' + codigo + '?')) return;
    api('api/ordenes.php?action=delete', { method: 'POST', body: { codigo: codigo } })
      .then(function (res) {
        if (!res.ok) {
          window.mostrarToast(res.error || 'No se pudo eliminar', 'error');
          return;
        }
        window.mostrarToast('Orden ' + codigo + ' eliminada', 'success');
        renderizarTablaAdmin();
      })
      .catch(function () {
        window.mostrarToast('Error de conexión con el servidor', 'error');
      });
  }

  /** Si se viene desde la página de Clientes ("Crear orden para X"),
   *  pre-llena el cliente y baja al formulario de Nueva Orden. */
  function aplicarClientePendiente() {
    var nombre = '';
    try { nombre = sessionStorage.getItem('luitech-cliente-orden') || ''; } catch (e) { return; }
    if (!nombre) return;
    try { sessionStorage.removeItem('luitech-cliente-orden'); } catch (e) {}
    var input = $('new-cliente');
    if (input) input.value = nombre;
    var seccion = $('nueva-orden');
    if (seccion) {
      seccion.scrollIntoView({ behavior: 'smooth' });
      seccion.style.borderColor = '#22d3ee';
      setTimeout(function () { seccion.style.borderColor = ''; }, 3000);
    }
    if (input) input.focus();
  }

  /** Carga los nombres de clientes registrados en el autocompletado de Nueva Orden. */
  function cargarClientesLista() {
    api('api/clientes.php?action=list').then(function (res) {
      if (!res.ok) return;
      clientesCache = res.clientes || [];
      var dl = $('lista-clientes');
      if (!dl) return;
      dl.replaceChildren();
      (res.clientes || []).forEach(function (c) {
        dl.appendChild(new Option(c.nombre));
      });
    }).catch(function () {});
  }

  /** Carga los técnicos activos en el selector de Nueva Orden. */
  function cargarTecnicos() {
    api('api/tecnicos.php?action=list').then(function (res) {
      if (!res.ok) return;
      var sel = $('new-tecnico');
      sel.replaceChildren();
      sel.appendChild(new Option('Por Asignar', ''));
      (res.tecnicos || []).forEach(function (t) {
        sel.appendChild(new Option(t.nombre, String(t.id)));
      });
    }).catch(function () {});
  }

  function agregarOrden(event) {
    event.preventDefault();

    var selTec = $('new-tecnico');
    var cuerpo = {
      cliente: $('new-cliente').value.trim(),
      equipo:  $('new-equipo').value.trim(),
      tipo:    $('new-tipo').value,
      falla:   $('new-falla').value.trim(),
      fecha:   $('new-fecha').value,
      obs_recepcion: $('new-obs').value.trim()
    };
    if (selTec.value !== '') {
      cuerpo.tecnico_id = parseInt(selTec.value, 10);
      cuerpo.tecnico = selTec.options[selTec.selectedIndex].getAttribute('data-nombre') || '';
    }
    var pinFinal = valorPinPatron();
    if (pinFinal) cuerpo.pin_patron = pinFinal;
    var codigo = $('new-codigo').value.trim();
    if (/^\d{3,8}$/.test(codigo)) codigo = 'LUH-' + codigo;
    cuerpo.codigo = codigo; // vacío → la API genera el correlativo

    var accs = accesoriosSeleccionados();
    if (accs.length) cuerpo.accesorios = accs.join(', ');
    if (firmaHecha && lienzoFirma) cuerpo.firma = lienzoFirma.toDataURL('image/png');

    // Presupuesto de la reparación (opcional al ingreso): precio todo incluido
    var precioN = parseInt($('new-precio').value, 10) || 0;
    if (precioN > 0) cuerpo.total = precioN;
    var garantiaN = parseInt($('new-garantia').value, 10) || 0;
    if (garantiaN > 0) cuerpo.garantia_dias = garantiaN;
    var costoRepN = parseInt($('new-costo-repuesto').value, 10) || 0;
    if (costoRepN > 0) cuerpo.costo_repuesto = costoRepN;

    // Aviso: precio sin costo real → ganancia y comisión del técnico infladas
    if (precioN > 0 && costoRepN === 0 &&
        !window.confirm('Precio de ' + monto(precioN) + ' sin "Costo real del repuesto".\n\nLa ganancia de la orden y la comisión del técnico se calcularian INFLADAS.\n\nAceptar = guardar de todos modos (podras definirla en el detalle de la orden)\nCancelar = completar el campo ahora')) {
      return; // el usuario vuelve al formulario para registrar el costo
    }

    if (cuerpo.tecnico === '') delete cuerpo.tecnico;
    if (!cuerpo.fecha) delete cuerpo.fecha;
    if (!cuerpo.obs_recepcion) delete cuerpo.obs_recepcion;

    var fotosASubir = fotosNueva.slice();

    api('api/ordenes.php?action=create', { method: 'POST', body: cuerpo })
      .then(function (res) {
        if (!res.ok) {
          window.mostrarToast(res.error || 'No se pudo crear la orden', 'error');
          return;
        }
        var codigoCreado = res.orden.codigo;
        window.mostrarToast('Orden ' + codigoCreado + ' creada para ' + res.orden.cliente, 'success');

        // Egreso de la compra de la pieza (si se marcó la casilla y hay costo)
        if (costoRepN > 0 && $('new-costo-egreso').checked) {
          api('api/ordenes.php?action=egreso_repuesto', { method: 'POST', body: { codigo: codigoCreado } })
            .then(function (re) {
              if (!re.ok) { window.mostrarToast(re.error || 'No se pudo registrar el egreso de la compra', 'error'); return; }
              window.mostrarToast(re.ya ? 'El egreso de la compra ya estaba registrado en la caja' : 'Egreso de compra registrado: ' + monto(re.monto), 'success');
            }).catch(function () {});
        } else if (costoRepN > 0 && !$('new-costo-egreso').checked) {
          window.mostrarToast('Recuerda: la compra de la pieza (' + monto(costoRepN) + ') aún no está como Egreso en la caja', 'error');
        }

        // Limpiar formulario completo (datos + acta de recepción + presupuesto)
        event.target.reset();
        calcularTotalNueva();
        fotosNueva = [];
        renderFotosNueva();
        limpiarFirmaNueva();
        desmarcarChips();
        limpiarPinPatron();
        renderizarTablaAdmin();

        if (!fotosASubir.length) return;

        // Subir las fotos de respaldo una por una (ya vienen comprimidas)
        var subidas = 0, errores = 0, mensajeError = '';
        var cadena = Promise.resolve();
        fotosASubir.forEach(function (dataUrl) {
          cadena = cadena.then(function () {
            return subirFotoUnica(codigoCreado, dataUrl).then(function (r) {
              if (r && r.ok) { subidas++; }
              else {
                errores++;
                if (!mensajeError && r && r.error) mensajeError = r.error;
              }
            });
          });
        });
        cadena.then(function () {
          if (errores) {
            window.mostrarToast(mensajeError || (errores + ' foto(s) no se pudieron guardar'), 'error');
          }
          if (subidas) {
            window.mostrarToast(subidas + ' foto(s) de respaldo guardada(s)', 'success');
          }
        });
      })
      .catch(function () {
        window.mostrarToast('Error de conexión con el servidor', 'error');
      });
  }

  /** Avanza un turno al siguiente estado y avisa cuando queda listo (demo/sala). */
  function simularAvanceOrden() {
    api('api/ordenes.php?action=list').then(function (res) {
      if (!res.ok || !res.ordenes.length) {
        window.mostrarToast('No hay órdenes disponibles', 'error');
        return;
      }

      var siguientes = {
        'Ingresado':         ['En Diagnóstico', 30],
        'En Diagnóstico':    ['En Reparación', 60],
        'En Reparación':     ['Listo para Retiro', 100]
      };

      var pendientes = res.ordenes.filter(function (o) { return siguientes[o.estado]; });
      if (!pendientes.length) {
        window.mostrarToast('Todas las órdenes ya están listas para retiro', 'success');
        return;
      }

      var orden = pendientes[Math.floor(Math.random() * pendientes.length)];
      var nuevo = siguientes[orden.estado];

      actualizarOrden({ codigo: orden.codigo, estado: nuevo[0], avance: nuevo[1] });

      if (nuevo[0] === 'Listo para Retiro') {
        window.emitirAlertaTurno();
        setTimeout(function () { window.mostrarToast('🎉 ¡Turno ' + orden.codigo + ' Listo para Retiro!', 'success'); }, 600);
      } else {
        setTimeout(function () { window.mostrarToast(orden.codigo + ' pasó a ' + nuevo[0], 'success'); }, 600);
      }

      setTimeout(renderizarTablaAdmin, 800);
    }).catch(function () {
      window.mostrarToast('Error de conexión con el servidor', 'error');
    });
  }

  /* ---------------------------------------------- MODAL: DETALLE DE ORDEN */
  var ordenModalCodigo = '';
  var lienzoFirmaEntrega = null;
  var ctxFirmaEntrega = null;
  var firmaEntregaHecha = false;

  function parDato(etiqueta, valor, ocuparFila) {
    var wrap = document.createElement('div');
    if (ocuparFila) wrap.className = 'col-span-2';
    var dt = document.createElement('dt');
    dt.className = 'text-[10px] uppercase tracking-wider text-slate-500 font-bold';
    dt.textContent = etiqueta;
    var dd = document.createElement('dd');
    dd.className = 'text-slate-200 font-semibold break-words';
    dd.textContent = (valor === null || valor === undefined || valor === '') ? '—' : valor;
    wrap.appendChild(dt);
    wrap.appendChild(dd);
    return wrap;
  }

  function abrirModalOrden(codigo) {
    var o = null;
    ordenesCache.forEach(function (x) { if (x.codigo === codigo) o = x; });
    if (!o) return;

    ordenModalCodigo = codigo;
    $('mo-codigo').textContent = codigo;

    var dl = $('mo-datos');
    dl.replaceChildren();
    dl.appendChild(parDato('Cliente', o.cliente));
    dl.appendChild(parDato('Equipo', o.equipo));
    dl.appendChild(parDato('Tipo', o.tipo));
    dl.appendChild(parDato('Estado', o.estado));
    dl.appendChild(parDato('Avance', String(o.avance) + '%'));
    dl.appendChild(parDato('Técnico', o.tecnico));
    dl.appendChild(parDato('Ingreso', o.fecha_ingreso));
    dl.appendChild(filaPinPatron(o.pin_patron));
    dl.appendChild(parDato('Accesorios', o.accesorios));
    dl.appendChild(parDato('Falla declarada', o.falla, true));
    dl.appendChild(parDato('Observaciones', o.obs_recepcion, true));

    if (o.firma_ingreso) {
      $('mo-firma').src = o.firma_ingreso;
      $('mo-firma').classList.remove('hidden');
      $('mo-firma-vacia').classList.add('hidden');
    } else {
      $('mo-firma').removeAttribute('src');
      $('mo-firma').classList.add('hidden');
      $('mo-firma-vacia').classList.remove('hidden');
    }

    renderCobroModal(o);
    renderEntregaModal(o);
    cargarBitacora(codigo);

    cargarFotosOrden(codigo);
    $('modal-orden').classList.remove('hidden');
    prepararLienzoFirmaEntrega(); // mide el lienzo ya visible
  }

  function cerrarModalOrden() {
    detenerMonitoreoMP(); // deja de consultar si el modal se cierra
    $('modal-orden').classList.add('hidden');
  }

  /* -------------------------------- COBRO / ENTREGA / BITÁCORA (MODAL) */
  /** Devuelve el objeto de la orden abierta en el modal (o null). */
  function ordenActualModal() {
    var o = null;
    ordenesCache.forEach(function (x) { if (x.codigo === ordenModalCodigo) o = x; });
    return o;
  }

  /** Aplica cambios locales a la orden del modal (la tabla se refresca sola). */
  function patchOrdenModal(cambios) {
    ordenesCache.forEach(function (x) { if (x.codigo === ordenModalCodigo) Object.assign(x, cambios); });
  }

  /** Prepara el lienzo de firma de entrega (fondo blanco, trazo oscuro). */
  function prepararLienzoFirmaEntrega() {
    if (!lienzoFirmaEntrega) return;
    var r = lienzoFirmaEntrega.getBoundingClientRect();
    if (r.width < 10) return;
    lienzoFirmaEntrega.width = Math.round(r.width);
    lienzoFirmaEntrega.height = Math.round(r.height);
    ctxFirmaEntrega = lienzoFirmaEntrega.getContext('2d');
    ctxFirmaEntrega.fillStyle = '#ffffff';
    ctxFirmaEntrega.fillRect(0, 0, lienzoFirmaEntrega.width, lienzoFirmaEntrega.height);
    ctxFirmaEntrega.lineWidth = 2.5;
    ctxFirmaEntrega.lineCap = 'round';
    ctxFirmaEntrega.lineJoin = 'round';
    ctxFirmaEntrega.strokeStyle = '#0f172a';
    firmaEntregaHecha = false;
  }

  /** Firma táctil del recibo de entrega (dedo o mouse). */
  function iniciarFirmaEntrega() {
    lienzoFirmaEntrega = $('mo-firma-entrega');
    if (!lienzoFirmaEntrega) return;
    var dibujando = false;
    function posicion(e) {
      var r = lienzoFirmaEntrega.getBoundingClientRect();
      return { x: e.clientX - r.left, y: e.clientY - r.top };
    }
    lienzoFirmaEntrega.addEventListener('pointerdown', function (e) {
      e.preventDefault();
      dibujando = true;
      firmaEntregaHecha = true;
      var p = posicion(e);
      ctxFirmaEntrega.beginPath();
      ctxFirmaEntrega.moveTo(p.x, p.y);
      ctxFirmaEntrega.lineTo(p.x + 0.1, p.y + 0.1);
      ctxFirmaEntrega.stroke();
    });
    lienzoFirmaEntrega.addEventListener('pointermove', function (e) {
      if (!dibujando) return;
      var p = posicion(e);
      ctxFirmaEntrega.lineTo(p.x, p.y);
      ctxFirmaEntrega.stroke();
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) {
      lienzoFirmaEntrega.addEventListener(ev, function () { dibujando = false; });
    });
    $('btn-entrega-limpiar').addEventListener('click', function () {
      prepararLienzoFirmaEntrega();
    });
  }

  /** Enlace ✏️ para definir/corregir el costo real del repuesto de la orden abierta. */
  function enlaceEditarCosto() {
    var enlace = document.createElement('button');
    enlace.type = 'button';
    enlace.className = 'text-[10px] text-cyan-400 underline hover:text-cyan-300 whitespace-nowrap';
    enlace.textContent = '✏️ definir';
    enlace.addEventListener('click', function () {
      var o = ordenActualModal();
      if (!o) return;
      var actual = parseInt(o.costo_repuesto, 10) || 0;
      var valor = window.prompt('Costo real del repuesto ($):\n(lo que te costo la pieza al proveedor — dato interno, el cliente no lo ve)', actual || '');
      if (valor === null) return;
      var nuevo = parseInt(String(valor).replace(/[^\d]/g, ''), 10) || 0;
      api('api/ordenes.php?action=update', { method: 'POST', body: { codigo: ordenModalCodigo, costo_repuesto: nuevo } })
        .then(function (res) {
          if (!res.ok) { window.mostrarToast(res.error || 'No se pudo guardar el costo', 'error'); return; }
          patchOrdenModal({ costo_repuesto: nuevo });
          window.mostrarToast('Costo real del repuesto actualizado', 'success');
          renderCobroModal(ordenActualModal());
          if (nuevo > 0 && window.confirm('¿Registrar también el egreso de la compra ($' + nuevo + ') en la caja?')) {
            api('api/ordenes.php?action=egreso_repuesto', { method: 'POST', body: { codigo: ordenModalCodigo } })
              .then(function (re) {
                if (re.ok) {
                  window.mostrarToast(re.ya ? 'El egreso de la compra ya estaba registrado en la caja' : 'Egreso de compra registrado: ' + monto(re.monto), 'success');
                } else {
                  window.mostrarToast(re.error || 'No se pudo registrar el egreso', 'error');
                }
              }).catch(function () { window.mostrarToast('Error de conexión con el servidor', 'error'); });
          }
        })
        .catch(function () { window.mostrarToast('Error de conexión con el servidor', 'error'); });
    });
    return enlace;
  }

  /** Teléfono registrado del cliente de la orden (por id o por nombre). */
  function telefonoDeCliente(o) {
    var nombre = (o.cliente || '').toLowerCase();
    var cl = (clientesCache || []).find(function (c) {
      return (o.cliente_id && parseInt(c.id, 10) === parseInt(o.cliente_id, 10)) ||
             ((c.nombre || '').toLowerCase() === nombre);
    });
    return cl ? (cl.telefono || '') : '';
  }

  /** Abre WhatsApp con un aviso según el estado de la orden. */
  function avisarClienteWhatsApp() {
    var o = ordenActualModal();
    if (!o) return;
    var soloDigitos = telefonoDeCliente(o).replace(/\D/g, '');
    if (soloDigitos.length < 8) {
      window.mostrarToast('El cliente no tiene teléfono registrado (edítalo en la página de Clientes)', 'error');
      return;
    }
    var frases = {
      'Listo para Retiro': 'tu ' + o.equipo + ' esta LISTO para retirar',
      'En Reparación': 'tu ' + o.equipo + ' esta en reparación',
      'En Diagnóstico': 'tu ' + o.equipo + ' esta en diagnóstico',
      'Ingresado': 'recibimos tu ' + o.equipo + ' y esta en revisión',
      'Entregado': 'la orden de tu ' + o.equipo + ' fue entregada'
    };
    var frase = frases[o.estado] || 'te escribimos por tu orden ' + o.codigo;
    var saldoN = saldoDe(o);
    var extra = saldoN > 0 ? ' Saldo pendiente: ' + monto(saldoN) + '.' : '';
    var msg = 'Hola ' + o.cliente + ', ' + frase + '. Orden ' + o.codigo + '.' + extra + ' — Luitech';
    window.open('https://wa.me/' + soloDigitos + '?text=' + encodeURIComponent(msg), '_blank');
  }

  /** Muestra los equipos que el cliente trajo antes (click para usar). */
  var timerEquipos = null;
  function mostrarEquiposPrevios() {
    var cont = $('equipos-previos');
    if (!cont) return;
    var nombre = $('new-cliente').value.trim().toLowerCase();
    cont.replaceChildren();
    var equipos = [];
    if (nombre.length >= 3) {
      (ordenesCache || []).forEach(function (o) {
        if ((o.cliente || '').toLowerCase() !== nombre) return;
        var eq = (o.equipo || '').trim();
        if (eq && equipos.indexOf(eq) === -1) equipos.push(eq);
      });
    }
    if (!equipos.length) { cont.classList.add('hidden'); return; }
    var lbl = document.createElement('span');
    lbl.className = 'text-slate-500';
    lbl.textContent = 'Trajo antes:';
    cont.appendChild(lbl);
    equipos.forEach(function (eq) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'bg-slate-800 hover:bg-cyan-600 border border-slate-700 text-slate-200 rounded-full px-3 py-1';
      b.textContent = eq;
      b.addEventListener('click', function () {
        $('new-equipo').value = eq;
        cont.classList.add('hidden');
      });
      cont.appendChild(b);
    });
    cont.classList.remove('hidden');
  }

  /** Pinta los montos de cobro de la orden abierta (total / abonado / saldo). */
  function renderCobroModal(o) {
    var cont = $('mo-cobro');
    if (!cont) return;
    cont.replaceChildren();
    var total = parseInt(o.total, 10) || 0;
    var abonado = parseInt(o.abono, 10) || 0;
    var saldo = Math.max(0, total - abonado);
    cont.appendChild(cajaMonto('Total', monto(total), 'text-white'));
    cont.appendChild(cajaMonto('Abonado', monto(abonado), 'text-amber-400'));
    cont.appendChild(cajaMonto('Saldo', monto(saldo), saldo > 0 ? 'text-red-400' : 'text-emerald-400'));

    var fila = document.createElement('div');
    fila.className = 'flex items-center justify-between gap-2';
    fila.style.gridColumn = '1 / -1';
    var izquierda = document.createElement('div');
    izquierda.className = 'flex items-center gap-2';
    izquierda.appendChild(chipPago(o.estado_pago));
    if (o.metodo_pago) {
      var mp = document.createElement('span');
      mp.className = 'text-[10px] text-slate-500';
      mp.textContent = '· ' + o.metodo_pago;
      izquierda.appendChild(mp);
    }
    var derecha = document.createElement('span');
    derecha.className = 'text-[10px] text-slate-500';
    var gd = parseInt(o.garantia_dias, 10) || 0;
    derecha.textContent = gd > 0 ? 'Garantía: ' + gd + ' días' : 'Sin garantía definida';
    fila.appendChild(izquierda);
    fila.appendChild(derecha);
    cont.appendChild(fila);

    // Costo real del repuesto y margen bruto (con aviso y editor si falta)
    var costoRep = parseInt(o.costo_repuesto, 10) || 0;
    if (costoRep > 0) {
      var margenBruto = Math.max(0, total - costoRep);
      var lineaCosto = document.createElement('div');
      lineaCosto.className = 'text-[10px] text-slate-500 flex items-center justify-between gap-2';
      lineaCosto.style.gridColumn = '1 / -1';
      var textoCosto = document.createElement('span');
      textoCosto.textContent = 'Costo repuesto: ' + monto(costoRep) + ' · Margen bruto: ' + monto(margenBruto);
      lineaCosto.appendChild(textoCosto);
      lineaCosto.appendChild(enlaceEditarCosto());
      cont.appendChild(lineaCosto);
    } else if (total > 0) {
      var avisoCosto = document.createElement('div');
      avisoCosto.className = 'text-[10px] text-amber-400 flex items-center justify-between gap-2';
      avisoCosto.style.gridColumn = '1 / -1';
      var textoAviso = document.createElement('span');
      textoAviso.textContent = '⚠ Sin costo real del repuesto: la comisión se calcularía sobre todo el margen.';
      avisoCosto.appendChild(textoAviso);
      avisoCosto.appendChild(enlaceEditarCosto());
      cont.appendChild(avisoCosto);
    }

    // Botón de Mercado Pago: visible solo si hay saldo y MP está habilitado;
    // si no lo está, muestra el aviso con la solución
    var botonMP = $('mo-btn-mp');
    var mpActivo = empresaCfg && empresaCfg.mp_enabled === '1';
    if (botonMP) {
      botonMP.classList.toggle('hidden', !(mpActivo && saldo > 0));
    }
    if (!mpActivo && saldo > 0) {
      var avisoMP = document.createElement('div');
      avisoMP.className = 'text-[10px] text-amber-400';
      avisoMP.style.gridColumn = '1 / -1';
      avisoMP.textContent = 'Mercado Pago no está habilitado: actívalo en Configuración → Mercado Pago.';
      cont.appendChild(avisoMP);
    }
    var panelMP = $('mp-panel');
    if (panelMP && saldo <= 0) panelMP.classList.add('mo-oculto');

    // Botón de Point: solo con MP activo, Device ID configurado y saldo pendiente
    var botonPoint = $('mo-btn-point');
    if (botonPoint) {
      var conDevice = empresaCfg && (empresaCfg.mp_point_device || '').length > 0;
      botonPoint.classList.toggle('hidden', !(mpActivo && conDevice && saldo > 0));
    }

    // Aviso por WhatsApp: visible en cualquier estado (el mensaje se adapta)
    var botonWA = $('mo-btn-wa');
    if (botonWA) {
      botonWA.classList.remove('hidden');
    }
  }

  /** Caja pequeña con etiqueta + valor para el bloque de cobro. */
  function cajaMonto(etiqueta, valorTexto, colorValor) {
    var d = document.createElement('div');
    d.className = 'bg-slate-950 border border-slate-800 rounded-lg p-2 text-center';
    var p1 = document.createElement('p');
    p1.className = 'text-[10px] uppercase tracking-wider text-slate-500 font-bold';
    p1.textContent = etiqueta;
    var p2 = document.createElement('p');
    p2.className = 'font-bold ' + colorValor;
    p2.textContent = valorTexto;
    d.appendChild(p1);
    d.appendChild(p2);
    return d;
  }

  /** Registra abono/cobro total (el estado de pago lo deriva la API). */
  function guardarCobroModal(cobrarTodo) {
    var o = ordenActualModal();
    if (!o) return;
    var total = parseInt(o.total, 10) || 0;
    if (total <= 0) {
      window.mostrarToast('Esta orden todavía no tiene valor de reparación', 'error');
      return;
    }
    var abonoActual = parseInt(o.abono, 10) || 0;
    var nuevoAbono;
    if (cobrarTodo) {
      nuevoAbono = total;
    } else {
      var extra = parseInt($('mo-abono').value, 10) || 0;
      if (extra <= 0) { window.mostrarToast('Ingresa un monto de abono mayor a 0', 'error'); return; }
      nuevoAbono = Math.min(total, abonoActual + extra);
    }
    var metodo = $('mo-metodo').value;
    if (nuevoAbono >= total && !metodo) metodo = 'Efectivo'; // cierre sin medio elegido
    var cuerpo = { codigo: ordenModalCodigo, abono: nuevoAbono };
    if (metodo) cuerpo.metodo_pago = metodo;

    var boton = cobrarTodo ? $('mo-btn-cobro-total') : $('mo-btn-cobro');
    boton.disabled = true;
    api('api/ordenes.php?action=update', { method: 'POST', body: cuerpo })
      .then(function (res) {
        boton.disabled = false;
        if (!res.ok) { window.mostrarToast(res.error || 'No se pudo registrar el cobro', 'error'); return; }
        patchOrdenModal({
          abono: nuevoAbono,
          estado_pago: nuevoAbono >= total ? 'Pagado' : 'Abonado',
          metodo_pago: cuerpo.metodo_pago || o.metodo_pago
        });
        $('mo-abono').value = '';
        renderCobroModal(ordenActualModal());
        renderEntregaModal(ordenActualModal());
        renderizarTablaAdmin();
        window.mostrarToast('Cobro registrado: ' + monto(nuevoAbono) + ' de ' + monto(total), 'success');
        if (res.auto_entregada) {
          patchOrdenModal({ estado: 'Entregado', avance: 100, entregado_a: res.orden.entregado_a, fecha_entrega: res.orden.fecha_entrega });
          window.mostrarToast('Pago completo: equipo ENTREGADO automáticamente + comisión generada', 'success');
        }
        if (res.comision) window.mostrarToast('Comisión de ' + res.comision.tecnico + ': ' + monto(res.comision.monto), 'success');
        if (res.aviso) window.mostrarToast(res.aviso, 'error');
      })
      .catch(function () {
        boton.disabled = false;
        window.mostrarToast('Error de conexión con el servidor', 'error');
      });
  }

  function cargarFotosOrden(codigo) {
    api('api/ordenes.php?action=fotos&codigo=' + encodeURIComponent(codigo)).then(function (res) {
      if (!res.ok) return;
      var cont = $('mo-fotos');
      cont.replaceChildren();
      if (!res.fotos.length) {
        var vacio = document.createElement('p');
        vacio.className = 'text-[11px] text-slate-500 italic';
        vacio.textContent = 'Sin fotos de respaldo todavía.';
        cont.appendChild(vacio);
        return;
      }
      res.fotos.forEach(function (f) {
        var d = document.createElement('div');
        d.className = 'foto-thumb';
        var img = document.createElement('img');
        img.src = f.archivo;
        img.alt = 'Foto de respaldo del equipo';
        img.loading = 'lazy';
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'foto-borrar';
        b.title = 'Eliminar foto';
        b.innerHTML = '<i class="fa-solid fa-trash-can pointer-events-none"></i>';
        b.addEventListener('click', function () {
          api('api/ordenes.php?action=borrar_foto', { method: 'POST', body: { id: f.id } })
            .then(function (r) {
              if (r.ok) {
                cargarFotosOrden(ordenModalCodigo);
              } else {
                window.mostrarToast(r.error || 'No se pudo borrar la foto', 'error');
              }
            })
            .catch(function () {
              window.mostrarToast('Error de conexión con el servidor', 'error');
            });
        });
        d.appendChild(img);
        d.appendChild(b);
        cont.appendChild(d);
      });
    }).catch(function () {
      window.mostrarToast('No se pudieron cargar las fotos', 'error');
    });
  }

  /** Sube al detalle las fotos elegidas (comprimidas, de a 6 por lote). */
  function anadirFotosModal(archivos) {
    var input = $('mo-input-fotos');
    input.value = '';
    if (!ordenModalCodigo) return;
    var lista = Array.prototype.slice.call(archivos || []).slice(0, 6);
    if (!lista.length) return;

    var subidas = 0, errores = 0, mensajeError = '';
    var cadena = Promise.resolve();
    lista.forEach(function (file) {
      cadena = cadena.then(function () {
        if (!file.type || file.type.indexOf('image/') !== 0) { errores++; return; }
        return comprimirFoto(file).then(function (dataUrl) {
          if (!dataUrl) { errores++; return; }
          return subirFotoUnica(ordenModalCodigo, dataUrl).then(function (r) {
            if (r && r.ok) { subidas++; }
            else {
              errores++;
              if (!mensajeError && r && r.error) mensajeError = r.error;
            }
          });
        });
      });
    });
    cadena.then(function () {
      if (subidas) window.mostrarToast(subidas + ' foto(s) añadida(s)', 'success');
      if (errores) window.mostrarToast(mensajeError || (errores + ' foto(s) no se pudieron subir'), 'error');
      cargarFotosOrden(ordenModalCodigo);
    });
  }

  /** Muestra la entrega registrada o el formulario de cierre si corresponde. */
  function renderEntregaModal(o) {
    var info = $('mo-entrega-info');
    var form = $('mo-entrega-form');
    if (!info || !form) return;
    info.replaceChildren();
    if (o.fecha_entrega) {
      form.classList.add('mo-oculto');
      info.classList.remove('mo-oculto');
      var linea1 = document.createElement('p');
      linea1.className = 'font-semibold text-emerald-400';
      linea1.textContent = 'Entregado el ' + String(o.fecha_entrega).slice(0, 16) + ' — retiró: ' + (o.entregado_a || '—');
      var gd = parseInt(o.garantia_dias, 10) || 0;
      var linea2 = document.createElement('p');
      linea2.className = 'text-[11px] text-slate-500';
      linea2.textContent = gd > 0 ? 'Garantía de ' + gd + ' días desde la entrega.' : 'Sin garantía registrada.';
      info.appendChild(linea1);
      info.appendChild(linea2);
    } else if (o.estado === 'Listo para Retiro') {
      info.classList.add('mo-oculto');
      form.classList.remove('mo-oculto');
      prepararLienzoFirmaEntrega();
    } else {
      form.classList.add('mo-oculto');
      info.classList.remove('mo-oculto');
      var aviso = document.createElement('p');
      aviso.className = 'text-[11px] italic text-slate-500';
      aviso.textContent = 'Disponible cuando la orden pase a "Listo para Retiro".';
      info.appendChild(aviso);
    }
  }

  /** Registra la entrega física: quién retira + su firma → recibo imprimible. */
  function confirmarEntrega() {
    var o = ordenActualModal();
    if (!o || o.fecha_entrega) return;
    var retira = $('mo-entregado-a').value.trim();
    if (!retira) { window.mostrarToast('Indica quién retira el equipo', 'error'); return; }
    if (saldoDe(o) > 0) {
      window.mostrarToast('Hay saldo pendiente: registra primero el cobro completo', 'error');
      return;
    }
    if (!firmaEntregaHecha) { window.mostrarToast('Pide la firma de quien retira el equipo', 'error'); return; }
    var boton = $('mo-btn-entrega');
    boton.disabled = true;
    api('api/ordenes.php?action=update', {
      method: 'POST',
      body: {
        codigo: ordenModalCodigo,
        entregar: true,
        estado: 'Entregado',
        entregado_a: retira,
        firma_entrega: lienzoFirmaEntrega ? lienzoFirmaEntrega.toDataURL('image/png') : null
      }
    }).then(function (res) {
      boton.disabled = false;
      if (!res.ok) { window.mostrarToast(res.error || 'No se pudo registrar la entrega', 'error'); return; }
      if (res.orden) patchOrdenModal(res.orden);
      renderEntregaModal(ordenActualModal());
      renderizarTablaAdmin();
      window.mostrarToast('Orden ' + ordenModalCodigo + ' entregada', 'success');
      if (res.comision) {
        window.mostrarToast('Comisión generada: ' + monto(res.comision.monto) + ' — ' + res.comision.tecnico, 'success');
      }
      imprimirRecibo(ordenActualModal());
    }).catch(function () {
      boton.disabled = false;
      window.mostrarToast('Error de conexión con el servidor', 'error');
    });
  }

  /** Lista la bitácora técnica de la orden abierta (más reciente primero). */
  function cargarBitacora(codigo) {
    var lista = $('mo-bitacora');
    if (!lista) return;
    lista.replaceChildren();
    var cargando = document.createElement('li');
    cargando.className = 'italic text-slate-500';
    cargando.textContent = 'Cargando bitácora…';
    lista.appendChild(cargando);
    api('api/ordenes.php?action=bitacora&codigo=' + encodeURIComponent(codigo)).then(function (res) {
      lista.replaceChildren();
      if (!res.ok || !res.bitacora.length) {
        var sinDatos = document.createElement('li');
        sinDatos.className = 'italic text-slate-500';
        sinDatos.textContent = 'Sin notas todavía.';
        lista.appendChild(sinDatos);
        return;
      }
      res.bitacora.forEach(function (e) {
        var li = document.createElement('li');
        var cabezal = document.createElement('p');
        cabezal.className = 'text-[10px] font-semibold text-slate-500';
        cabezal.textContent = e.creado_en + (e.tecnico ? ' · ' + e.tecnico : '');
        var texto = document.createElement('p');
        texto.className = 'text-slate-300';
        texto.textContent = e.nota; // seguro (sin HTML)
        li.appendChild(cabezal);
        li.appendChild(texto);
        lista.appendChild(li);
      });
    }).catch(function () {
      lista.replaceChildren();
      var error = document.createElement('li');
      error.className = 'italic text-slate-500';
      error.textContent = 'No se pudo cargar la bitácora.';
      lista.appendChild(error);
    });
  }

  /** Guarda una nota técnica en la bitácora de la orden abierta. */
  function agregarNota() {
    var input = $('mo-nota');
    var texto = input.value.trim();
    if (!texto) { window.mostrarToast('Escribe la nota primero', 'error'); return; }
    var o = ordenActualModal();
    var boton = $('mo-btn-nota');
    boton.disabled = true;
    api('api/ordenes.php?action=nota', {
      method: 'POST',
      body: { codigo: ordenModalCodigo, nota: texto, tecnico: o ? o.tecnico : '' }
    }).then(function (res) {
      boton.disabled = false;
      if (!res.ok) { window.mostrarToast(res.error || 'No se pudo guardar la nota', 'error'); return; }
      input.value = '';
      cargarBitacora(ordenModalCodigo);
      window.mostrarToast('Nota agregada a la bitácora', 'success');
    }).catch(function () {
      boton.disabled = false;
      window.mostrarToast('Error de conexión con el servidor', 'error');
    });
  }

  /** Escapa texto para incrustarlo en el HTML del recibo. */
  function escapar(texto) {
    return String(texto === null || texto === undefined ? '' : texto)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /** Imprime la ventana emergente esperando a que carguen sus imágenes
   *  (sin esto, el logo salía en blanco: print() corría antes de la carga). */
  function imprimirVentana(ventana) {
    ventana.focus();
    var imagenes = ventana.document.images;
    var pendientes = imagenes.length;
    var impresa = false;
    function ahora() {
      if (impresa) return;
      impresa = true;
      ventana.print();
    }
    if (pendientes === 0) { ahora(); return; }
    Array.prototype.forEach.call(imagenes, function (im) {
      im.addEventListener('load', function () { pendientes--; if (pendientes <= 0) ahora(); });
      im.addEventListener('error', function () { pendientes--; if (pendientes <= 0) ahora(); });
    });
    setTimeout(ahora, 2500); // respaldo si una imagen nunca responde
  }

  /** Genera el link/QR de pago del saldo en Mercado Pago y monitorea hasta
   *  que se confirme; al confirmarse imprime el recibo automáticamente. */
  function cobrarConMP() {
    var o = ordenActualModal();
    if (!o) return;
    if (saldoDe(o) <= 0) { window.mostrarToast('No hay saldo pendiente', 'error'); return; }
    var boton = $('mo-btn-mp');
    boton.disabled = true;
    api('api/pagos_mp.php?action=crear_link', { method: 'POST', body: { codigo: ordenModalCodigo } })
      .then(function (res) {
        boton.disabled = false;
        if (!res.ok) { window.mostrarToast(res.error || 'Mercado Pago rechazó la petición', 'error'); return; }
        $('mp-qr').src = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' + encodeURIComponent(res.init_point);
        var enlace = $('mp-link');
        enlace.href = res.init_point;
        enlace.textContent = res.init_point;
        $('mp-whatsapp').href = 'https://wa.me/?text=' + encodeURIComponent('Paga tu reparación ' + o.codigo + ' (' + monto(res.saldo) + ') aquí: ' + res.init_point);
        $('mp-panel').classList.remove('mo-oculto');
        $('mp-panel').classList.remove('hidden');
        var estadoMP = $('mp-estado');
        if (estadoMP) estadoMP.textContent = 'Esperando el pago… (verificando cada 5 s)';
        iniciarMonitoreoMP();
      }).catch(function (err) {
        boton.disabled = false;
        window.mostrarToast(err && err.message ? err.message : 'Error de conexión con el servidor', 'error');
      });
  }

  /** Consulta cada 5 s si Mercado Pago confirmó el pago; al confirmar,
   *  refresca la orden e imprime el recibo automáticamente. */
  function iniciarMonitoreoMP() {
    detenerMonitoreoMP();
    mpTimer = setInterval(function () {
      api('api/pagos_mp.php?action=verificar&codigo=' + encodeURIComponent(ordenModalCodigo))
        .then(function (res) {
          if (!res.ok) return;
          if (res.pagada) {
            detenerMonitoreoMP();
            patchOrdenModal({ abono: res.abono, estado_pago: 'Pagado', metodo_pago: 'Mercado Pago' });
            var linea = $('mp-estado');
            if (linea) { linea.textContent = '✓ ¡Pago confirmado! Imprimiendo recibo…'; linea.style.color = '#34d399'; }
            renderCobroModal(ordenActualModal());
            renderEntregaModal(ordenActualModal());
            renderizarTablaAdmin();
            window.mostrarToast('¡Pago de Mercado Pago confirmado!', 'success');
            imprimirRecibo(ordenActualModal());
          } else {
            var linea2 = $('mp-estado');
            if (linea2) linea2.textContent = 'Esperando el pago… (' + monto(res.abono) + ' de ' + monto(res.total) + ')';
          }
        }).catch(function () {});
    }, 5000);
  }

  function detenerMonitoreoMP() {
    if (mpTimer) { clearInterval(mpTimer); mpTimer = null; }
  }

  function copiarEnlaceMP() {
    var enlace = $('mp-link');
    if (!enlace || !enlace.href) return;
    if (navigator.clipboard) {
      navigator.clipboard.writeText(enlace.href).then(function () {
        window.mostrarToast('Enlace copiado al portapapeles', 'success');
      }).catch(function () {
        window.mostrarToast('No se pudo copiar: selecciónalo manualmente', 'error');
      });
    } else {
      window.mostrarToast('Tu navegador no permite copiar automáticamente', 'error');
    }
  }

  /** Envía el cobro del saldo al terminal Point y monitorea hasta confirmarse. */
  function cobrarConPoint() {
    var o = ordenActualModal();
    if (!o) return;
    if (saldoDe(o) <= 0) { window.mostrarToast('No hay saldo pendiente', 'error'); return; }
    if (!(empresaCfg && (empresaCfg.mp_point_device || '').length > 0)) {
      window.mostrarToast('Configura el Device ID del terminal Point en Configuración', 'error');
      return;
    }
    var boton = $('mo-btn-point');
    boton.disabled = true;
    detenerMonitoreoMP();
    $('point-panel').classList.remove('mo-oculto');
    var linea = $('point-estado');
    if (linea) { linea.textContent = 'Enviando el cobro al terminal Point…'; linea.style.color = '#fbbf24'; }

    api('api/pagos_mp.php?action=point_cobrar', { method: 'POST', body: { codigo: ordenModalCodigo } })
      .then(function (res) {
        if (!res.ok) {
          boton.disabled = false;
          window.mostrarToast(res.error || 'No se pudo enviar el cobro al Point', 'error');
          return;
        }
        var ordenMPId = res.order_id;
        if (linea) { linea.textContent = 'Acerca la tarjeta al terminal Point…'; }
        mpTimer = setInterval(function () {
          api('api/pagos_mp.php?action=point_estado&order_id=' + encodeURIComponent(ordenMPId))
            .then(function (r) {
              if (!r.ok) return;
              if (r.pagada) {
                detenerMonitoreoMP();
                boton.disabled = false;
                if (linea) { linea.textContent = '✓ ¡Pago aprobado en el Point!'; linea.style.color = '#34d399'; }
                api('api/pagos_mp.php?action=verificar&codigo=' + encodeURIComponent(ordenModalCodigo))
                  .then(function (v) {
                    if (v.ok && v.pagada) {
                      patchOrdenModal({ abono: v.abono, estado_pago: 'Pagado', metodo_pago: 'Mercado Pago' });
                    }
                    renderCobroModal(ordenActualModal());
                    renderEntregaModal(ordenActualModal());
                    renderizarTablaAdmin();
                    window.mostrarToast('¡Pago con Point confirmado!', 'success');
                    imprimirRecibo(ordenActualModal());
                  });
                return;
              }
              if (r.estado === 'error' || r.estado === 'rejected') {
                detenerMonitoreoMP();
                boton.disabled = false;
                if (linea) { linea.textContent = '✗ El terminal rechazó el pago'; linea.style.color = '#f87171'; }
              }
            }).catch(function () {});
        }, 3000);
      }).catch(function (err) {
        boton.disabled = false;
        var motivo = err && err.message ? err.message : 'Error de conexión con el servidor';
        if (linea) { linea.textContent = '✗ ' + motivo; linea.style.color = '#f87171'; }
        window.mostrarToast(motivo, 'error');
      });
  }

  /** Recibo de entrega imprimible (ventana nueva con estilos propios). */
  function imprimirRecibo(o) {
    if (!o) return;
    var total = parseInt(o.total, 10) || 0;
    var abonado = parseInt(o.abono, 10) || 0;
    var saldo = Math.max(0, total - abonado);
    var gd = parseInt(o.garantia_dias, 10) || 0;
    var vence = '—';
    if (gd > 0 && o.fecha_entrega) {
      var f = new Date(String(o.fecha_entrega).replace(' ', 'T'));
      if (!isNaN(f.getTime())) {
        f.setDate(f.getDate() + gd);
        vence = f.getFullYear() + '-' + String(f.getMonth() + 1).padStart(2, '0') + '-' + String(f.getDate()).padStart(2, '0');
      }
    }
    var filaRecibo = function (etiqueta, valor) {
      return '<tr><th>' + escapar(etiqueta) + '</th><td>' + valor + '</td></tr>';
    };
    var html =
      '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Recibo ' + escapar(o.codigo) + '</title>' +
      '<style>' +
      'body{font-family:Arial,Helvetica,sans-serif;color:#0f172a;max-width:420px;margin:24px auto;padding:0 12px;}' +
      'h1{font-size:18px;margin:0;text-align:center;letter-spacing:1px;}' +
      'img.logo{max-width:120px;margin:0 auto 6px;display:block;}' +
      'p.sub{font-size:11px;color:#475569;text-align:center;margin:2px 0 14px;}' +
      'table{width:100%;border-collapse:collapse;font-size:12px;}' +
      'th{text-align:left;padding:6px 8px;background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;width:38%;font-weight:normal;}' +
      'td{padding:6px 8px;border:1px solid #cbd5e1;font-weight:700;}' +
      'img{max-height:70px;margin-top:10px;display:block;}' +
      'p.nota{font-size:10px;color:#64748b;text-align:center;margin-top:14px;}' +
      '</style></head><body>' +
      (empresaCfg && empresaCfg.empresa_logo
        ? '<img class="logo" src="' + escapar(new URL(empresaCfg.empresa_logo, location.href).href) + '">'
        : '') +
      '<h1>' + escapar(empresaCfg && empresaCfg.empresa_nombre ? empresaCfg.empresa_nombre : 'LUITECH — SERVICIO TÉCNICO') + '</h1>' +
      '<p class="sub">Recibo de entrega · Orden ' + escapar(o.codigo) +
      (empresaCfg && empresaCfg.empresa_direccion ? '<br>' + escapar(empresaCfg.empresa_direccion) : '') + '</p>' +
      '<table>' +
      filaRecibo('Cliente', escapar(o.cliente)) +
      filaRecibo('Equipo', escapar(o.equipo)) +
      filaRecibo('Falla / servicio', escapar(o.falla)) +
      filaRecibo('Fecha de ingreso', escapar(o.fecha_ingreso)) +
      filaRecibo('Fecha de entrega', escapar(String(o.fecha_entrega || '—').slice(0, 16))) +
      filaRecibo('Retirado por', escapar(o.entregado_a || '—')) +
      filaRecibo('Total', escapar(monto(total))) +
      filaRecibo('Abonado', escapar(monto(abonado))) +
      filaRecibo('Saldo', escapar(monto(saldo))) +
      filaRecibo('Garantía', gd > 0 ? escapar(gd + ' días (hasta ' + vence + ')') : 'Sin garantía') +
      '</table>' +
      (o.firma_entrega ? '<img src="' + escapar(o.firma_entrega) + '" alt="Firma de retiro">' : '') +
      '<p class="nota">' + escapar(empresaCfg && empresaCfg.terminos_texto ? empresaCfg.terminos_texto : '¡Gracias por confiar en Luitech! Conserve este recibo para hacer efectiva la garantía.') + '</p>' +
      '</body></html>';

    var ventana = window.open('', '_blank', 'width=520,height=720');
    if (!ventana) {
      window.mostrarToast('Permite las ventanas emergentes para imprimir el recibo', 'error');
      return;
    }
    ventana.document.open();
    ventana.document.write(html);
    ventana.document.close();
    imprimirVentana(ventana);
  }

  /* ------------------------------------------------------------ ARRANQUE */
  document.addEventListener('DOMContentLoaded', function () {
    $('form-login').addEventListener('submit', iniciarSesion);
    $('form-nueva-orden').addEventListener('submit', agregarOrden);
    $('btn-logout').addEventListener('click', cerrarSesion);
    $('btn-recargar').addEventListener('click', renderizarTablaAdmin);
    $('buscar-orden').addEventListener('input', renderFilasOrdenes);
    $('filtro-estado-orden').addEventListener('change', renderFilasOrdenes);
    $('filtro-mes-orden').addEventListener('change', renderFilasOrdenes);
    $('mo-btn-wa').addEventListener('click', avisarClienteWhatsApp);
    var timerEquipos = null;
    $('new-cliente').addEventListener('input', function () {
      clearTimeout(timerEquipos);
      timerEquipos = setTimeout(mostrarEquiposPrevios, 300);
    });
    $('btn-simular').addEventListener('click', simularAvanceOrden);

    // Acta de recepción: firma táctil, fotos y accesorios
    iniciarFirma();
    iniciarPatron();

    // Fecha de ingreso: pre-cargada con hoy (sigue siendo editable)
    $('new-fecha').value = fechaLocalHoy();

    // Botón que despliega / guarda el cuadro del patrón
    $('btn-patron-toggle').addEventListener('click', alternarPatron);

    // Presupuesto: total calculado en vivo (= precio al cliente)
    ['new-precio'].forEach(function (id) {
      $(id).addEventListener('input', calcularTotalNueva);
    });

    // Modal de detalle: cobro, entrega, recibo y bitácora
    $('mo-btn-cobro').addEventListener('click', function () { guardarCobroModal(false); });
    $('mo-btn-cobro-total').addEventListener('click', function () { guardarCobroModal(true); });
    $('mo-btn-recibo').addEventListener('click', function () { imprimirRecibo(ordenActualModal()); });
    $('mo-btn-entrega').addEventListener('click', confirmarEntrega);
    $('mo-btn-nota').addEventListener('click', agregarNota);
    $('mo-nota').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); agregarNota(); }
    });
    iniciarFirmaEntrega();
    cargarConfigAdmin();
    cargarTecnicos();
    $('mo-btn-mp').addEventListener('click', cobrarConMP);
    $('mp-copiar').addEventListener('click', copiarEnlaceMP);
    $('mo-btn-point').addEventListener('click', cobrarConPoint);
    $('new-pin').addEventListener('input', function () {
      this.value = this.value.replace(/\D/g, '');
    });
    $('new-fotos').addEventListener('change', function (e) {
      var archivos = Array.prototype.slice.call(e.target.files);
      e.target.value = '';
      var cupo = MAX_FOTOS_NUEVA - fotosNueva.length;
      var elegidos = archivos.slice(0, Math.max(0, cupo));
      if (elegidos.length < archivos.length) {
        window.mostrarToast('Máximo ' + MAX_FOTOS_NUEVA + ' fotos al crear; añade el resto desde el detalle de la orden', 'error');
      }
      var cadena = Promise.resolve();
      elegidos.forEach(function (file) {
        cadena = cadena.then(function () {
          if (!file.type || file.type.indexOf('image/') !== 0) return;
          return comprimirFoto(file).then(function (dataUrl) {
            if (dataUrl) { fotosNueva.push(dataUrl); renderFotosNueva(); }
          });
        });
      });
    });
    document.querySelectorAll('#new-accesorios .chip').forEach(function (b) {
      b.addEventListener('click', function () { b.classList.toggle('chip-on'); });
    });

    // Modal de detalle de orden
    $('btn-orden-cerrar').addEventListener('click', cerrarModalOrden);
    $('mo-input-fotos').addEventListener('change', function (e) { anadirFotosModal(e.target.files); });

    // Cerrar los modales con Escape
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') { cerrarModalOrden(); }
    });

    // Verificar sesión existente al cargar
    api('api/auth.php?action=me').then(function (res) {
      if (res.logueado) {
        renderizarTablaAdmin();
        mostrarVista(true, res.nombre);
      } else {
        mostrarVista(false);
      }
    }).catch(function () {
      mostrarVista(false);
      window.mostrarToast('Sin conexión con el backend (¿MySQL encendido?)', 'error');
    });
  });
})();


