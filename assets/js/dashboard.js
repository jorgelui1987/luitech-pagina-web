/* LUITECH — dashboard.js */
(function () {
  'use strict';

  var api = window.LuitechAPI;
  function fmt(n) { return Number(n).toLocaleString('es-CL'); }
  var $ = function (id) { return document.getElementById(id); };

  window.Lui.guard('Dashboard', function () {
    api('api/dashboard.php?action=metricas').then(function (res) {
      if (!res.ok) return;

      $('m-ventas-hoy').textContent = fmt(res.hoy.total_ventas);
      $('m-nventas').textContent = res.hoy.n_ventas;
      $('m-ventas-mes').textContent = fmt(res.mes.ventas);
      $('m-gastos-mes').textContent = fmt(res.mes.gastos);

      // Órdenes por estado con barras
      var total = 0, lista = $('ord-lista');
      lista.replaceChildren();
      Object.keys(res.ordenes_por_estado).forEach(function (k) { total += res.ordenes_por_estado[k]; });
      $('m-ordenes-total').textContent = String(total);

      var iconos = {
        'Ingresado': 'fa-inbox text-slate-400',
        'En Diagnóstico': 'fa-stethoscope text-amber-400',
        'En Reparación': 'fa-screwdriver-wrench text-cyan-400',
        'Listo para Retiro': 'fa-box-open text-emerald-400'
      };

      Object.keys(res.ordenes_por_estado || res.ordenes_por_estado).forEach(function (estado) {
        var n = res.ordenes_por_estado[estado];
        var pct = total ? Math.round(n * 100 / total) : 0;

        var fila = document.createElement('div');
        fila.className = 'flex items-center justify-between text-xs';
        var izq = document.createElement('span');
        izq.className = 'text-slate-300 font-semibold flex items-center gap-2';
        izq.innerHTML = '<i class="fa-solid ' + (iconos[estado] || '') + '"></i>' + estado;
        var der = document.createElement('span');
        der.className = 'font-black text-white';
        der.textContent = n + ' (' + pct + '%)';
        fila.appendChild(izq); fila.appendChild(der);

        var barra = document.createElement('div');
        barra.className = 'barra-h w-full';
        var fill = document.createElement('div');
        fill.style.width = pct + '%';
        barra.appendChild(fill);
        lista.appendChild(fila);
        lista.appendChild(barra);
      });

      // Top productos
      var topList = $('top-productos');
      topList.replaceChildren();
      if (!(res.top_productos_mes || []).length) {
        topList.appendChild(Object.assign(document.createElement('p'), {
          textContent: 'Sin ventas registradas este mes.',
          className: 'text-slate-500 text-xs italic'
        }));
      } else {
        res.top_productos_mes.forEach(function (p, i) {
          var max = res.top_productos_mes[0].monto || 1;
          var fila2 = document.createElement('div');
          var nombre = document.createElement('div');
          nombre.className = 'flex justify-between text-xs mb-0.5';
          nombre.innerHTML = '<span class="font-semibold text-slate-200">' + (i + 1) + '. ' +
                             String(p.descripcion).replace(/[<>&]/g, '') + '</span>' +
                             '<span class="text-emerald-400 font-bold">$' + fmt(p.monto) + '</span>';
          var barra2 = document.createElement('div');
          barra2.className = 'barra-h w-full';
          var f2 = document.createElement('div');
          f2.style.width = Math.max(4, Math.round(p.monto * 100 / max)) + '%';
          barra2.appendChild(f2);
          fila2.appendChild(nombre); fila2.appendChild(barra2);
          fila2.classList.add('mb-2');
          topList.appendChild(fila2);
        });
      }

      // Stock bajo
      $('m-bajo-n').textContent = String(res.stock_bajo.cantidad);
      var ulBajo = $('stock-bajo'); ulBajo.replaceChildren();
      if (!res.stock_bajo.items.length) {
        ulBajo.appendChild(Object.assign(document.createElement('li'),
          { textContent: 'Todo el inventario sobre mínimo ✓', className: 'text-emerald-400' }));
      } else {
        res.stock_bajo.items.forEach(function (p) {
          ulBajo.appendChild(Object.assign(document.createElement('li'), {
            innerHTML: '<i class="fa-solid fa-caret-right text-red-400 mr-1"></i>' +
              String(p.nombre).replace(/[<>&]/g, '') + ' — <b class="text-red-300">' + p.stock + '</b> (mín: ' + p.stock_minimo + ')'
          }));
        });
      }

      // Garantías próximas a vencer
      var ulGar = $('gar-proximas'); ulGar.replaceChildren();
      if (!(res.garantias_proximas || []).length) {
        ulGar.appendChild(Object.assign(document.createElement('li'),
          { textContent: 'Ninguna garantía vence en los próximos 14 días', className: 'text-slate-500' }));
      } else {
        res.garantias_proximas.forEach(function (g) {
          ulGar.appendChild(Object.assign(document.createElement('li'), {
            innerHTML: '<i class="fa-regular fa-calendar text-cyan-400 mr-1"></i><b class="text-white">' +
              String(g.ref_codigo).replace(/[<>&]/g, '') + '</b> — ' + String(g.producto).replace(/[<>&]/g, '') +
              ' · vence <b class="text-amber-300">' + g.fin + '</b>'
          }));
        });
      }
    }).catch(function () {});
  });
})();
