/* ==========================================================================
   LUITECH — inventario.js · CRUD de productos + alertas de stock bajo
   ========================================================================== */
(function () {
  'use strict';

  var api = window.LuitechAPI;
  var $ = function (id) { return document.getElementById(id); };

  function fmt(n) { return Number(n).toLocaleString('es-CL'); }

  function mostrarVista(logueado) {
    $('view-nologin').classList.toggle('hidden', logueado);
    $('view-inv').classList.toggle('hidden', !logueado);
    if (logueado) cargar();
  }

  function limpiarFormulario() {
    $('form-titulo').innerHTML = '<i class="fa-solid fa-plus mr-1"></i>Nuevo producto';
    ['p-id','p-codigo','p-nombre','p-cat','p-costo','p-venta','p-stock'].forEach(function (i) { $(i).value = ''; });
    $('p-min').value = '3';
    $('p-ctrl').checked = true;
    $('btn-cancelar').classList.add('hidden');
    $('btn-guardar').disabled = false;
  }

  function cargar() {
    api('api/inventario.php?action=list').then(function (res) {
      if (!res.ok) return;

      var bajos = res.productos.filter(function (p) { return p.stock_bajo; });
      var caja = $('alertas-stock');
      if (bajos.length) {
        caja.textContent = '⚠ Stock bajo: ' + bajos.map(function (p) {
          return p.nombre + ' (' + p.stock + ')';
        }).join(' · ');
        caja.classList.remove('hidden');
      } else {
        caja.classList.add('hidden');
      }
      renderTabla(res.productos);
    }).catch(function () {});
  }

  function renderTabla(productos) {
    var tbody = $('inv-body');
    tbody.replaceChildren();

    if (!productos.length) {
      var vacio = document.createElement('td');
      vacio.colSpan = 7;
      vacio.className = 'p-6 text-center text-slate-500 italic';
      vacio.textContent = 'Inventario vacío. Agrega tu primer producto arriba.';
      var trv = document.createElement('tr'); trv.appendChild(vacio); tbody.appendChild(trv);
      return;
    }

    productos.forEach(function (p) {
      var tr = document.createElement('tr');
      tr.className = 'hover:bg-slate-800/30 transition-colors';

      function td(texto, clase) {
        var c = document.createElement('td');
        c.className = clase || 'p-3 text-slate-300';
        c.textContent = texto;
        return c;
      }
      tr.appendChild(td(p.codigo, 'p-3 font-mono font-bold text-cyan-400'));
      tr.appendChild(td(p.nombre, 'p-3 font-semibold text-white'));
      tr.appendChild(td(p.categoria));
      tr.appendChild(td('$' + fmt(p.precio_costo), 'p-3 text-right text-slate-400'));
      tr.appendChild(td('$' + fmt(p.precio_venta), 'p-3 text-right text-emerald-400 font-bold'));

      var tdStock = document.createElement('td');
      tdStock.className = 'p-3 text-center';
      var badge = document.createElement('span');
      badge.className = 'px-2 py-0.5 rounded-full text-xs font-bold border ' +
        (!p.controlar_stock ? 'bg-slate-800 text-slate-400 border-slate-700'
          : (p.stock_bajo ? 'bg-red-950 text-red-400 border-red-900'
                          : 'bg-emerald-950 text-emerald-400 border-emerald-800'));
      badge.textContent = p.controlar_stock ? String(p.stock) : 's/c';
      tdStock.appendChild(badge);
      tr.appendChild(tdStock);

      var tdAcc = document.createElement('td');
      tdAcc.className = 'p-3 text-center';

      var btnEd = document.createElement('button');
      btnEd.type = 'button'; btnEd.title = 'Editar';
      btnEd.innerHTML = '<i class="fa-solid fa-pen pointer-events-none"></i>';
      btnEd.className = 'w-8 h-8 rounded-lg bg-slate-800 hover:bg-cyan-600 text-slate-300 hover:text-white mx-0.5 transition-all';
      btnEd.addEventListener('click', function () { editarProducto(p); });

      var btnEl = document.createElement('button');
      btnEl.type = 'button'; btnEl.title = 'Eliminar';
      btnEl.innerHTML = '<i class="fa-solid fa-trash-can pointer-events-none"></i>';
      btnEl.className = 'w-8 h-8 rounded-lg bg-red-950/40 hover:bg-red-900/60 text-red-400 border border-red-900/60 mx-0.5 transition-all';
      btnEl.addEventListener('click', function () { eliminar(p.id, p.nombre); });

      tdAcc.appendChild(btnEd); tdAcc.appendChild(btnEl);
      tr.appendChild(tdAcc);
      tbody.appendChild(tr);
    });
  }

  /* ------------------------------------------------- FORMULARIO / CRUD */
  function editarProducto(p) {
    window.scrollTo({ top: 0, behavior: 'smooth' });
    $('form-titulo').innerHTML = '<i class="fa-solid fa-pen mr-1"></i>Editando: ' + p.nombre;
    $('p-id').value = p.id; $('p-codigo').value = p.codigo; $('p-nombre').value = p.nombre;
    $('p-cat').value = p.categoria; $('p-costo').value = p.precio_costo; $('p-venta').value = p.precio_venta;
    $('p-stock').value = p.stock; $('p-min').value = (p.controlar_stock ? p.stock_minimo : 0) || 0;
    $('btn-cancelar').classList.remove('hidden');
  }

  function guardar() {
    var id = $('p-id').value.trim();
    var cuerpo = {
      codigo: $('p-codigo').value.trim().toUpperCase(),
      nombre: $('p-nombre').value.trim(),
      categoria: $('p-cat').value.trim() || 'Repuesto',
      precio_costo: parseInt($('p-costo').value, 10) || 0,
      precio_venta: parseInt($('p-venta').value, 10) || 0,
      stock: parseInt($('p-stock').value, 10) || 0,
      stock_minimo: parseInt($('p-min').value, 10) || 0,
      controlar_stock: $('p-ctrl').checked
    };
    if (!cuerpo.codigo || !cuerpo.nombre || cuerpo.precio_venta <= 0) {
      window.mostrarToast('Código, Nombre y Precio de venta son obligatorios', 'error');
      return;
    }

    var url = 'api/inventario.php?action=' + (id ? 'update' : 'create');
    if (id) cuerpo.id = parseInt(id, 10);

    $('btn-guardar').disabled = true;
    api(url, { method: 'POST', body: cuerpo }).then(function (res) {
      if (!res.ok) { window.mostrarToast(res.error || 'Error al guardar', 'error'); return; }
      window.mostrarToast(id ? 'Producto actualizado' : 'Producto "' + cuerpo.nombre + '" creado', 'success');
      limpiarFormulario();
      cargar();
    }).catch(function () {
      window.mostrarToast('Error de conexión con el servidor', 'error');
    }).finally(function () { $('btn-guardar').disabled = false; });
  }

  function eliminar(id, nombre) {
    if (!confirm('¿Eliminar "' + nombre + '" del inventario?')) return;
    api('api/inventario.php?action=delete', { method: 'POST', body: { id: id } })
      .then(function (res) {
        if (!res.ok) { window.mostrarToast(res.error || 'No se pudo eliminar', 'error'); return; }
        window.mostrarToast('"' + nombre + '" eliminado', 'success');
        cargar();
      }).catch(function () {});
  }

  document.addEventListener('DOMContentLoaded', function () {
    $('btn-guardar').addEventListener('click', guardar);
    $('btn-cancelar').addEventListener('click', limpiarFormulario);

    api('api/auth.php?action=me').then(function (res) {
      mostrarVista(!!res.logueado);
    }).catch(function () { mostrarVista(false); });
  });
})();

