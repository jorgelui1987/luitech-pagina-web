/* ==========================================================================
   LUITECH — proveedores.js · Registro de proveedores y compras de mercadería
   ========================================================================== */
(function () {
  'use strict';

  var api = window.LuitechAPI;
  var $ = function (id) { return document.getElementById(id); };

  function fmt(n) { return '$ ' + Math.max(0, Math.round(Number(n) || 0)).toLocaleString('es-CL'); }

  function mostrarVista(logueado) {
    $('view-nologin').classList.toggle('hidden', logueado);
    $('view-prov').classList.toggle('hidden', !logueado);
    if (logueado) { cargarProveedores(); cargarProductos(); cargarCompras(); }
  }

  /* -------------------------------------------------- PROVEEDORES (CRUD) */
  function cargarProveedores() {
    api('api/proveedores.php?action=list').then(function (res) {
      if (!res.ok) return;
      var tbody = $('prov-body');
      tbody.replaceChildren();

      // Selects dependientes: formulario de compra y filtro del historial
      var selCompra = $('c-prov'), selFiltro = $('fil-prov');
      var compraActual = selCompra.value, filtroActual = selFiltro.value;
      selCompra.replaceChildren(new Option('Proveedor*', ''));
      selFiltro.replaceChildren(new Option('Todos los proveedores', ''));

      if (!res.proveedores.length) {
        var trv = document.createElement('tr');
        var tdv = document.createElement('td');
        tdv.colSpan = 6;
        tdv.className = 'p-4 text-center italic text-slate-500';
        tdv.textContent = 'Aún no hay proveedores. Agrega el primero con el formulario superior.';
        trv.appendChild(tdv);
        tbody.appendChild(trv);
      }

      res.proveedores.forEach(function (p) {
        selCompra.add(new Option(p.nombre, String(p.id)));
        selFiltro.add(new Option(p.nombre, String(p.id)));

        var tr = document.createElement('tr');
        tr.className = 'border-b border-slate-800/40 hover:bg-slate-800/30';

        function td(texto, clase) {
          var c = document.createElement('td');
          c.className = clase || 'p-2 text-slate-300';
          c.textContent = texto;
          return c;
        }

        tr.appendChild(td(p.nombre, 'p-2 font-bold text-white'));
        tr.appendChild(td(p.rut || '—', 'p-2 font-mono text-slate-400'));
        tr.appendChild(td(p.telefono || '—', 'p-2 text-slate-400'));

        var tdCom = document.createElement('td');
        tdCom.className = 'p-2 text-right';
        tdCom.textContent = (parseInt(p.compras_total, 10) > 0)
          ? fmt(p.monto_comprado) + ' (' + p.compras_total + ')' : '—';
        tdCom.className += (parseInt(p.compras_total, 10) > 0 ? ' text-emerald-400 font-bold' : ' text-slate-600');
        tr.appendChild(tdCom);

        tr.appendChild(td(p.ultima_compra ? p.ultima_compra.slice(0, 10) : '—', 'p-2 text-center text-slate-400'));

        var tdAcc = document.createElement('td');
        tdAcc.className = 'p-2 text-center';
        var btnEd = document.createElement('button');
        btnEd.type = 'button'; btnEd.title = 'Editar';
        btnEd.innerHTML = '<i class="fa-solid fa-pen pointer-events-none"></i>';
        btnEd.className = 'w-8 h-8 rounded-lg bg-slate-800 hover:bg-cyan-600 text-slate-300 hover:text-white mx-0.5 transition-all';
        btnEd.addEventListener('click', function () { editarProveedor(p); });
        var btnEl = document.createElement('button');
        btnEl.type = 'button'; btnEl.title = 'Eliminar';
        btnEl.innerHTML = '<i class="fa-solid fa-trash-can pointer-events-none"></i>';
        btnEl.className = 'w-8 h-8 rounded-lg bg-red-950/40 hover:bg-red-900/60 text-red-400 border border-red-900/60 mx-0.5 transition-all';
        btnEl.addEventListener('click', function () { eliminarProveedor(p); });
        tdAcc.appendChild(btnEd); tdAcc.appendChild(btnEl);
        tr.appendChild(tdAcc);

        tbody.appendChild(tr);
      });

      selCompra.value = compraActual;
      selFiltro.value = filtroActual;
    }).catch(function () {});
  }

  function limpiarFormProv() {
    $('form-titulo').innerHTML = '<i class="fa-solid fa-plus mr-1"></i>Nuevo proveedor';
    ['pv-id','pv-nombre','pv-rut','pv-telefono','pv-nota'].forEach(function (i) { $(i).value = ''; });
    $('btn-prov-cancelar').classList.add('hidden');
    $('btn-prov-guardar').disabled = false;
  }

  function editarProveedor(p) {
    window.scrollTo({ top: 0, behavior: 'smooth' });
    $('form-titulo').innerHTML = '<i class="fa-solid fa-pen mr-1"></i>Editando: ' + p.nombre;
    $('pv-id').value = p.id; $('pv-nombre').value = p.nombre;
    $('pv-rut').value = p.rut || ''; $('pv-telefono').value = p.telefono || '';
    $('pv-nota').value = p.nota || '';
    $('btn-prov-cancelar').classList.remove('hidden');
  }

  function guardarProveedor() {
    var id = $('pv-id').value.trim();
    var cuerpo = {
      nombre: $('pv-nombre').value.trim(),
      rut: $('pv-rut').value.trim(),
      telefono: $('pv-telefono').value.trim(),
      nota: $('pv-nota').value.trim()
    };
    if (!cuerpo.nombre) {
      window.mostrarToast('El nombre del proveedor es obligatorio', 'error');
      return;
    }
    var url = 'api/proveedores.php?action=' + (id ? 'update' : 'create');
    if (id) cuerpo.id = parseInt(id, 10);

    var boton = $('btn-prov-guardar');
    boton.disabled = true;
    api(url, { method: 'POST', body: cuerpo }).then(function (res) {
      boton.disabled = false;
      if (!res.ok) { window.mostrarToast(res.error || 'Error al guardar', 'error'); return; }
      window.mostrarToast(id ? 'Proveedor actualizado' : 'Proveedor "' + cuerpo.nombre + '" creado', 'success');
      limpiarFormProv();
      cargarProveedores();
    }).catch(function () {
      boton.disabled = false;
      window.mostrarToast('Error de conexión con el servidor', 'error');
    });
  }

  function eliminarProveedor(p) {
    if (!confirm('¿Eliminar al proveedor "' + p.nombre + '"? (su historial de compras se conserva)')) return;
    api('api/proveedores.php?action=delete', { method: 'POST', body: { id: p.id } })
      .then(function (res) {
        if (!res.ok) { window.mostrarToast(res.error || 'No se pudo eliminar', 'error'); return; }
        window.mostrarToast('"' + p.nombre + '" eliminado', 'success');
        cargarProveedores();
      }).catch(function () {});
  }

  /* --------------------------------------------------------- COMPRAS */
  function cargarProductos() {
    api('api/inventario.php?action=list').then(function (res) {
      if (!res.ok) return;
      var sel = $('c-prod');
      sel.replaceChildren(new Option('Producto*', ''));
      res.productos.forEach(function (p) {
        sel.add(new Option(p.nombre + ' (stock: ' + (p.controlar_stock ? p.stock : 'servicio') + ')', String(p.id)));
      });
    }).catch(function () {});
  }

  function registrarCompra() {
    var provId = parseInt($('c-prov').value, 10) || 0;
    var prodId = parseInt($('c-prod').value, 10) || 0;
    var cant = parseInt($('c-cant').value, 10) || 0;
    var costo = parseInt($('c-costo').value, 10) || 0;
    if (!provId || !prodId || cant < 1) {
      window.mostrarToast('Proveedor, producto y cantidad son obligatorios', 'error');
      return;
    }
    var boton = $('btn-compra');
    boton.disabled = true;
    api('api/proveedores.php?action=registrar_compra', {
      method: 'POST',
      body: {
        proveedor_id: provId, producto_id: prodId, cantidad: cant, costo_unitario: costo,
        descontar_caja: $('c-caja').checked, nota: $('c-nota').value.trim()
      }
    }).then(function (res) {
      boton.disabled = false;
      if (!res.ok) { window.mostrarToast(res.error || 'No se pudo registrar la compra', 'error'); return; }
      window.mostrarToast('Compra registrada: +' + cant + ' unidades · ' + fmt(res.total), 'success');
      if (res.aviso) window.mostrarToast(res.aviso, 'error');
      $('c-cant').value = ''; $('c-costo').value = ''; $('c-nota').value = '';
      cargarCompras();
      cargarProveedores();
      cargarProductos();
    }).catch(function () {
      boton.disabled = false;
      window.mostrarToast('Error de conexión con el servidor', 'error');
    });
  }

  function cargarCompras() {
    var filtro = $('fil-prov').value;
    var url = 'api/proveedores.php?action=compras' + (filtro ? '&proveedor_id=' + encodeURIComponent(filtro) : '');
    api(url).then(function (res) {
      if (!res.ok) return;
      var tbody = $('com-body');
      tbody.replaceChildren();
      $('compras-total').textContent = res.total > 0 ? 'Total comprado: ' + fmt(res.total) : '';

      if (!res.compras.length) {
        var trv = document.createElement('tr');
        var tdv = document.createElement('td');
        tdv.colSpan = 7;
        tdv.className = 'p-4 text-center italic text-slate-500';
        tdv.textContent = 'No hay compras registradas todavía.';
        trv.appendChild(tdv);
        tbody.appendChild(trv);
        return;
      }

      res.compras.forEach(function (c) {
        var tr = document.createElement('tr');
        tr.className = 'border-b border-slate-800/40 hover:bg-slate-800/30';

        function td(texto, clase) {
          var c2 = document.createElement('td');
          c2.className = clase || 'p-2 text-slate-300';
          c2.textContent = texto;
          return c2;
        }

        var f = new Date(String(c.fecha).replace(' ', 'T'));
        tr.appendChild(td(isNaN(f.getTime()) ? c.fecha.slice(0, 10) : f.toLocaleDateString('es-CL'), 'p-2 font-mono text-slate-400'));
        tr.appendChild(td(c.proveedor_nombre, 'p-2 text-white font-semibold'));
        tr.appendChild(td(c.producto_nombre, 'p-2 text-cyan-400'));
        tr.appendChild(td(String(c.cantidad), 'p-2 text-center'));
        tr.appendChild(td(fmt(c.costo_unitario), 'p-2 text-right text-slate-400'));
        tr.appendChild(td(fmt(c.total), 'p-2 text-right text-amber-400 font-bold'));
        tr.appendChild(td(c.pagada_caja ? 'Egreso' : '—', 'p-2 text-center ' + (c.pagada_caja ? 'text-red-400' : 'text-slate-600')));
        tbody.appendChild(tr);
      });
    }).catch(function () {});
  }

  /* ----------------------------------------------------------- ARRANQUE */
  document.addEventListener('DOMContentLoaded', function () {
    $('btn-prov-guardar').addEventListener('click', guardarProveedor);
    $('btn-prov-cancelar').addEventListener('click', limpiarFormProv);
    $('btn-compra').addEventListener('click', registrarCompra);
    $('fil-prov').addEventListener('change', cargarCompras);

    api('api/auth.php?action=me').then(function (res) {
      mostrarVista(!!(res && res.logueado));
    }).catch(function () { mostrarVista(false); });
  });
})();