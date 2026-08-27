/* ==========================================================================
   LUITECH — admin.js
   Panel de control del taller: login con sesión PHP y CRUD de órdenes.
   ========================================================================== */
(function () {
  'use strict';

  var api = window.LuitechAPI;
  var $ = function (id) { return document.getElementById(id); };

  /* --------------------------------------------------------- SESIÓN */
  function mostrarVista(logueado, nombre) {
    $('view-login').classList.toggle('hidden', logueado);
    $('view-panel').classList.toggle('hidden', !logueado);
    if (logueado && nombre) $('admin-nombre').textContent = nombre;
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

  function renderizarTablaAdmin() {
    var tbody = $('admin-table-body');
    tbody.replaceChildren();

    api('api/ordenes.php?action=list').then(function (res) {
      if (!res.ok) {
        if (res.error === 'No autorizado') { mostrarVista(false); return; }
        throw new Error(res.error || 'Error al cargar órdenes');
      }

      if (!res.ordenes.length) {
        var vacio = celda('Aún no hay órdenes registradas. Crea la primera con el formulario superior.', 'p-6 text-center text-slate-500 italic');
        vacio.colSpan = 8;
        tbody.appendChild(vacio);
        return;
      }

      ordenesCache = res.ordenes;

      res.ordenes.forEach(function (o) {
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
        tr.appendChild(celda(o.fecha_ingreso, 'p-3 text-slate-500 text-xs'));

        var tdAcciones = document.createElement('td');
        tdAcciones.className = 'p-3 text-center';
        tdAcciones.appendChild(botonVer(o.codigo));
        tdAcciones.appendChild(botonEliminar(o.codigo));
        tr.appendChild(tdAcciones);

        tbody.appendChild(tr);
      });
    }).catch(function (e) {
      window.mostrarToast(e.message || 'No se pudo conectar con el servidor', 'error');
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

  function agregarOrden(event) {
    event.preventDefault();

    var cuerpo = {
      cliente: $('new-cliente').value.trim(),
      equipo:  $('new-equipo').value.trim(),
      tipo:    $('new-tipo').value,
      falla:   $('new-falla').value.trim(),
      tecnico: $('new-tecnico').value.trim(),
      fecha:   $('new-fecha').value,
      pin_patron: $('new-pin').value.trim(),
      obs_recepcion: $('new-obs').value.trim()
    };
    var codigo = $('new-codigo').value.trim();
    if (/^\d{3,8}$/.test(codigo)) codigo = 'LUH-' + codigo;
    cuerpo.codigo = codigo; // vacío → la API genera el correlativo

    var accs = accesoriosSeleccionados();
    if (accs.length) cuerpo.accesorios = accs.join(', ');
    if (firmaHecha && lienzoFirma) cuerpo.firma = lienzoFirma.toDataURL('image/png');

    if (cuerpo.tecnico === '') delete cuerpo.tecnico;
    if (!cuerpo.fecha) delete cuerpo.fecha;
    if (!cuerpo.pin_patron) delete cuerpo.pin_patron;
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

        // Limpiar formulario completo (datos + acta de recepción)
        event.target.reset();
        fotosNueva = [];
        renderFotosNueva();
        limpiarFirmaNueva();
        desmarcarChips();
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
    dl.appendChild(parDato('PIN / Patrón', o.pin_patron));
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

    cargarFotosOrden(codigo);
    $('modal-orden').classList.remove('hidden');
  }

  function cerrarModalOrden() { $('modal-orden').classList.add('hidden'); }

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

  /* ------------------------------------------------------ CAMBIAR CLAVE */
  function abrirModalClave() {
    ['clave-actual','clave-nueva','clave-repetida'].forEach(function (i) { $(i).value = ''; });
    $('modal-clave').classList.remove('hidden');
    $('clave-actual').focus();
  }
  function cerrarModalClave() { $('modal-clave').classList.add('hidden'); }

  function guardarNuevaClave(ev) {
    ev.preventDefault();
    var actual = $('clave-actual').value;
    var nueva  = $('clave-nueva').value;
    var repite = $('clave-repetida').value;

    if (nueva !== repite) { window.mostrarToast('La nueva contraseña y su repetición no coinciden', 'error'); return; }
    if (nueva.length < 8) { window.mostrarToast('La nueva contraseña debe tener al menos 8 caracteres', 'error'); return; }

    var boton = $('btn-clave-guardar');
    boton.disabled = true;

    api('api/auth.php?action=cambiar_clave', {
      method: 'POST',
      body: { clave_actual: actual, nueva: nueva, repetida: repite }
    }).then(function (res) {
      if (!res.ok) { window.mostrarToast(res.error || 'No se pudo cambiar', 'error'); return; }
      cerrarModalClave();
      window.mostrarToast('Contraseña actualizada ✓', 'success');
    }).catch(function () {
      window.mostrarToast('Error de conexión con el servidor', 'error');
    }).finally(function () { boton.disabled = false; });
  }

  /* ------------------------------------------------------------ ARRANQUE */
  document.addEventListener('DOMContentLoaded', function () {
    $('form-login').addEventListener('submit', iniciarSesion);
    $('form-nueva-orden').addEventListener('submit', agregarOrden);
    $('btn-logout').addEventListener('click', cerrarSesion);
    $('btn-recargar').addEventListener('click', renderizarTablaAdmin);
    $('btn-simular').addEventListener('click', simularAvanceOrden);
    $('btn-clave').addEventListener('click', abrirModalClave);
    $('btn-clave-cerrar').addEventListener('click', cerrarModalClave);
    $('form-clave').addEventListener('submit', guardarNuevaClave);

    // Acta de recepción: firma táctil, fotos y accesorios
    iniciarFirma();
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
      if (ev.key === 'Escape') { cerrarModalClave(); cerrarModalOrden(); }
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


