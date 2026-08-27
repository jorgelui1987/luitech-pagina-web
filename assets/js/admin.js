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
      fecha:   $('new-fecha').value
    };
    var codigo = $('new-codigo').value.trim();
    if (/^\d{3,8}$/.test(codigo)) codigo = 'LUH-' + codigo;
    cuerpo.codigo = codigo; // vacío → la API genera el correlativo

    if (cuerpo.tecnico === '') delete cuerpo.tecnico;
    if (!cuerpo.fecha) delete cuerpo.fecha;

    api('api/ordenes.php?action=create', { method: 'POST', body: cuerpo })
      .then(function (res) {
        if (!res.ok) {
          window.mostrarToast(res.error || 'No se pudo crear la orden', 'error');
          return;
        }
        window.mostrarToast('Orden ' + res.orden.codigo + ' creada para ' + res.orden.cliente, 'success');
        event.target.reset();
        renderizarTablaAdmin();
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

  /* ----------------------------------------------------------- ARRANQUE */
  document.addEventListener('DOMContentLoaded', function () {
    $('form-login').addEventListener('submit', iniciarSesion);
    $('form-nueva-orden').addEventListener('submit', agregarOrden);
    $('btn-logout').addEventListener('click', cerrarSesion);
    $('btn-recargar').addEventListener('click', renderizarTablaAdmin);
    $('btn-simular').addEventListener('click', simularAvanceOrden);

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

