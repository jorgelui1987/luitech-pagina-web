/* LUITECH — clientes.js · Registro de clientes, buscador y ficha con historial */
(function () {
  'use strict';

  var api = window.LuitechAPI;
  var $ = function (id) { return document.getElementById(id); };

  function fmt(n) { return '$ ' + Math.max(0, Math.round(Number(n) || 0)).toLocaleString('es-CL'); }

  function mostrarVista(logueado) {
    $('view-nologin').classList.toggle('hidden', logueado);
    $('view-cli').classList.toggle('hidden', !logueado);
    if (logueado) cargarClientes();
  }

  /* ------------------------------------------------- CLIENTES (CRUD) */
  function cargarClientes() {
    var q = $('cl-buscar').value.trim();
    var url = 'api/clientes.php?action=list' + (q ? '&q=' + encodeURIComponent(q) : '');
    api(url).then(function (res) {
      if (!res.ok) return;
      var tbody = $('cl-body');
      tbody.replaceChildren();
      $('cl-estado').textContent = res.clientes.length > 0 ? res.clientes.length + ' clientes' : '';

      if (!res.clientes.length) {
        var trv = document.createElement('tr');
        var tdv = document.createElement('td');
        tdv.colSpan = 7;
        tdv.className = 'p-4 text-center italic text-slate-500';
        tdv.textContent = 'No hay clientes registrados todavía. Se crean solos al ingresar una orden.';
        trv.appendChild(tdv);
        tbody.appendChild(trv);
        return;
      }

      res.clientes.forEach(function (c) {
        var tr = document.createElement('tr');
        tr.className = 'border-b border-slate-800/40 hover:bg-slate-800/30';

        function td(texto, clase) {
          var c2 = document.createElement('td');
          c2.className = clase || 'p-2 text-slate-300';
          c2.textContent = texto;
          return c2;
        }

        tr.appendChild(td(c.nombre, 'p-2 font-bold text-white'));
        tr.appendChild(td(c.rut || '—', 'p-2 font-mono text-slate-400'));
        tr.appendChild(td(c.telefono || '—', 'p-2 text-slate-400'));

        var tdOrd = document.createElement('td');
        tdOrd.className = 'p-2 text-center text-cyan-400 font-bold';
        tdOrd.textContent = String(c.ordenes_total || 0);
        tr.appendChild(tdOrd);

        var tdGas = document.createElement('td');
        tdGas.className = 'p-2 text-right text-emerald-400 font-bold';
        tdGas.textContent = (parseInt(c.ordenes_total, 10) > 0) ? fmt(c.total_gastado) : '—';
        tr.appendChild(tdGas);

        tr.appendChild(td(c.ultima_orden ? c.ultima_orden.slice(0, 10) : '—', 'p-2 text-center text-slate-400'));

        var tdAcc = document.createElement('td');
        tdAcc.className = 'p-2 text-center whitespace-nowrap';
        var btnOr = document.createElement('button');
        btnOr.type = 'button'; btnOr.title = 'Crear orden para este cliente';
        btnOr.innerHTML = '<i class="fa-solid fa-file-invoice pointer-events-none"></i>';
        btnOr.className = 'w-8 h-8 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white mx-0.5 transition-all';
        btnOr.addEventListener('click', function () { irANuevaOrden(c.nombre); });
        var btnF = document.createElement('button');
        btnF.type = 'button'; btnF.title = 'Ver ficha e historial';
        btnF.innerHTML = '<i class="fa-solid fa-folder-open pointer-events-none"></i>';
        btnF.className = 'w-8 h-8 rounded-lg bg-emerald-950/60 hover:bg-emerald-900 text-emerald-400 border border-emerald-900/60 mx-0.5 transition-all';
        btnF.addEventListener('click', function () { verFicha(c); });
        var btnEd = document.createElement('button');
        btnEd.type = 'button'; btnEd.title = 'Editar';
        btnEd.innerHTML = '<i class="fa-solid fa-pen pointer-events-none"></i>';
        btnEd.className = 'w-8 h-8 rounded-lg bg-slate-800 hover:bg-cyan-600 text-slate-300 hover:text-white mx-0.5 transition-all';
        btnEd.addEventListener('click', function () { editarCliente(c); });
        var btnEl = document.createElement('button');
        btnEl.type = 'button'; btnEl.title = 'Eliminar';
        btnEl.innerHTML = '<i class="fa-solid fa-trash-can pointer-events-none"></i>';
        btnEl.className = 'w-8 h-8 rounded-lg bg-red-950/40 hover:bg-red-900/60 text-red-400 border border-red-900/60 mx-0.5 transition-all';
        btnEl.addEventListener('click', function () { eliminarCliente(c); });
        tdAcc.appendChild(btnF); tdAcc.appendChild(btnEd); tdAcc.appendChild(btnEl);
        tr.appendChild(tdAcc);

        tbody.appendChild(tr);
      });
    }).catch(function () {});
  }

  function limpiarFormCliente() {
    $('form-titulo').innerHTML = '<i class="fa-solid fa-plus mr-1"></i>Nuevo cliente';
    ['cl-id','cl-nombre','cl-rut','cl-telefono','cl-email','cl-notas'].forEach(function (i) { $(i).value = ''; });
    $('btn-cl-cancelar').classList.add('hidden');
    $('btn-cl-orden').classList.add('hidden');
    $('btn-cl-guardar').disabled = false;
  }

  function editarCliente(c) {
    window.scrollTo({ top: 0, behavior: 'smooth' });
    $('form-titulo').innerHTML = '<i class="fa-solid fa-pen mr-1"></i>Editando: ' + c.nombre;
    $('cl-id').value = c.id; $('cl-nombre').value = c.nombre;
    $('cl-rut').value = c.rut || ''; $('cl-telefono').value = c.telefono || '';
    $('cl-email').value = c.email || ''; $('cl-notas').value = c.notas || '';
    $('btn-cl-cancelar').classList.remove('hidden');
  }

  function guardarCliente() {
    var id = $('cl-id').value.trim();
    var cuerpo = {
      nombre: $('cl-nombre').value.trim(),
      rut: $('cl-rut').value.trim(),
      telefono: $('cl-telefono').value.trim(),
      email: $('cl-email').value.trim(),
      notas: $('cl-notas').value.trim()
    };
    if (!cuerpo.nombre) {
      window.mostrarToast('El nombre del cliente es obligatorio', 'error');
      return;
    }
    var url = 'api/clientes.php?action=' + (id ? 'update' : 'create');
    if (id) cuerpo.id = parseInt(id, 10);

    var boton = $('btn-cl-guardar');
    boton.disabled = true;
    api(url, { method: 'POST', body: cuerpo }).then(function (res) {
      boton.disabled = false;
      if (!res.ok) { window.mostrarToast(res.error || 'Error al guardar', 'error'); return; }
      window.mostrarToast(id ? 'Cliente actualizado' : 'Cliente "' + cuerpo.nombre + '" creado', 'success');
      $('btn-cl-orden-nombre').textContent = cuerpo.nombre;
      $('btn-cl-orden').classList.remove('hidden');
      cargarClientes();
    }).catch(function () {
      boton.disabled = false;
      window.mostrarToast('Error de conexión con el servidor', 'error');
    });
  }

  function eliminarCliente(c) {
    if (!confirm('¿Eliminar al cliente "' + c.nombre + '"? (sus órdenes se conservan)')) return;
    api('api/clientes.php?action=delete', { method: 'POST', body: { id: c.id } })
      .then(function (res) {
        if (!res.ok) { window.mostrarToast(res.error || 'No se pudo eliminar', 'error'); return; }
        window.mostrarToast('"' + c.nombre + '" eliminado', 'success');
        cargarClientes();
      }).catch(function () {});
  }

  /** Guarda el nombre del cliente y abre el Panel directo en Nueva Orden. */
  function irANuevaOrden(nombre) {
    try { sessionStorage.setItem('luitech-cliente-orden', nombre); } catch (e) {}
    window.location.href = 'admin.html#nueva-orden';
  }

  /* ----------------------------------------------------------- FICHA */
  function verFicha(c) {
    api('api/clientes.php?action=ficha&id=' + encodeURIComponent(c.id)).then(function (res) {
      if (!res.ok) { window.mostrarToast(res.error || 'No se pudo cargar la ficha', 'error'); return; }
      $('ficha-titulo').innerHTML = '<i class="fa-solid fa-folder-open mr-1"></i>Historial de ' + res.cliente.nombre;
      $('ficha-ordenes').textContent = String(res.ordenes.length);
      $('ficha-gastado').textContent = fmt(res.total_gastado);
      var tbody = $('ficha-body');
      tbody.replaceChildren();

      if (!res.ordenes.length) {
        var trv = document.createElement('tr');
        var tdv = document.createElement('td');
        tdv.colSpan = 5;
        tdv.className = 'p-4 text-center italic text-slate-500';
        tdv.textContent = 'Este cliente todavía no tiene órdenes vinculadas.';
        trv.appendChild(tdv);
        tbody.appendChild(trv);
      }

      res.ordenes.forEach(function (o) {
        var tr = document.createElement('tr');
        tr.className = 'border-b border-slate-800/40';
        function td(texto, clase) {
          var c2 = document.createElement('td');
          c2.className = clase || 'p-2 text-slate-300';
          c2.textContent = texto;
          return c2;
        }
        tr.appendChild(td(o.codigo, 'p-2 font-mono font-bold text-cyan-400'));
        tr.appendChild(td(o.equipo, 'p-2 text-white'));
        tr.appendChild(td(o.estado, 'p-2 text-center'));
        tr.appendChild(td(fmt(o.total), 'p-2 text-right text-amber-400 font-bold'));
        tr.appendChild(td((o.fecha_ingreso || '').slice(0, 10), 'p-2 text-center text-slate-400'));
        tbody.appendChild(tr);
      });

      $('ficha-section').classList.remove('hidden');
      $('ficha-section').scrollIntoView({ behavior: 'smooth' });
    }).catch(function () {});
  }

  /* ----------------------------------------------------------- ARRANQUE */
  document.addEventListener('DOMContentLoaded', function () {
    $('btn-cl-guardar').addEventListener('click', guardarCliente);
    $('btn-cl-cancelar').addEventListener('click', limpiarFormCliente);
    $('btn-cl-orden').addEventListener('click', function () {
      irANuevaOrden($('btn-cl-orden-nombre').textContent);
    });
    var timer = null;
    $('cl-buscar').addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(cargarClientes, 250);
    });

    api('api/auth.php?action=me').then(function (res) {
      mostrarVista(!!(res && res.logueado));
    }).catch(function () { mostrarVista(false); });
  });
})();