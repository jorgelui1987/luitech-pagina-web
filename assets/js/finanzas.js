/* ==========================================================================
   LUITECH — finanzas.js · Caja diaria + Gastos + Reporte mensual
   ========================================================================== */
(function () {
  'use strict';

  var api = window.LuitechAPI;
  var $ = function (id) { return document.getElementById(id); };

  function fmt(n) { return Number(n).toLocaleString('es-CL'); }
  function fechaLocal(v) {
    var d = new Date(String(v).replace(' ', 'T'));
    return isNaN(d.getTime()) ? String(v) : d.toLocaleString('es-CL', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
  }

  function mostrarVista(logueado) {
    $('view-nologin').classList.toggle('hidden', logueado);
    $('view-fin').classList.toggle('hidden', !logueado);
    if (logueado) { refrescarCaja(); refrescarGastos(); }
  }

  /* ============================================================ CAJA DÍA */
  function refrescarCaja() {
    api('api/caja.php?action=estado').then(function (res) {
      if (!res.ok) return;

      if (!res.abierta) {
        $('caja-cerrada').classList.remove('hidden');
        $('caja-abierta').classList.add('hidden');
        return;
      }

      $('caja-cerrada').classList.add('hidden');
      $('caja-abierta').classList.remove('hidden');
      $('caja-apertura').textContent = fmt(res.sesion.monto_apertura);
      $('caja-esperado').textContent = fmt(res.efectivo_esperado);
      $('caja-quien').textContent = res.sesion.abierta_por;
      $('caja-desde').textContent = 'Desde ' + fechaLocal(res.sesion.apertura_ts);

      var lista = $('mov-lista');
      lista.replaceChildren();
      (res.movimientos || []).forEach(function (m) {
        var fila = document.createElement('div');
        fila.className = 'bg-slate-900/70 border border-slate-800 rounded-lg px-3 py-1.5 flex items-center gap-3 text-xs';

        var icono = document.createElement('i');
        icono.className = m.tipo === 'Ingreso'
          ? 'fa-solid fa-arrow-down text-emerald-400'
          : 'fa-solid fa-arrow-up text-red-400';
        fila.appendChild(icono);

        var texto = document.createElement('span');
        texto.className = 'flex-grow min-w-0 truncate text-slate-300';
        texto.textContent = (m.tipo === 'Egreso' ? '− ' : '') + m.concepto +
                            ' · ' + fechaLocal(m.creado_en);
        fila.appendChild(texto);

        var montoEl = document.createElement('span');
        montoEl.className = 'font-bold ' + (m.tipo === 'Ingreso' ? 'text-emerald-400' : 'text-red-400');
        montoEl.textContent = (m.tipo === 'Egreso' ? '−$' : '+$') + fmt(m.monto);
        fila.appendChild(montoEl);

        lista.appendChild(fila);
      });

      if (!(res.movimientos || []).length) {
        lista.appendChild(Object.assign(document.createElement('p'), {
          textContent: 'Sin movimientos en esta sesión.',
          className: 'text-slate-500 text-xs italic text-center py-3'
        }));
      }
    }).catch(function () {});
  }

  function abrirCaja() {
    var boton = $('btn-abrir-caja');
    boton.disabled = true;
    api('api/caja.php?action=abrir', {
      method: 'POST',
      body: { monto_apertura: parseInt($('caja-apertura-monto').value, 10) || 0 }
    }).then(function (res) {
      if (!res.ok) { window.mostrarToast(res.error || 'No se pudo abrir', 'error'); return; }
      window.mostrarToast('Caja abierta', 'success');
      refrescarCaja();
    }).catch(function () {
      window.mostrarToast('Error de conexión', 'error');
    }).finally(function () { boton.disabled = false; });
  }

  function agregarMovimiento(ev) {
    ev.preventDefault();
    api('api/caja.php?action=agregar_mov', {
      method: 'POST',
      body: {
        tipo: $('mov-tipo').value,
        concepto: $('mov-concepto').value.trim(),
        monto: parseInt($('mov-monto').value, 10) || 0
      }
    }).then(function (res) {
      if (!res.ok) { window.mostrarToast(res.error || 'No se pudo registrar', 'error'); return; }
      window.mostrarToast('Movimiento registrado', 'success');
      $('mov-concepto').value = ''; $('mov-monto').value = '';
      refrescarCaja();
    }).catch(function () {});
  }

  function cerrarCaja() {
    var contado = $('caja-contado').value;
    if (contado === '') {
      window.mostrarToast('Primero cuenta el dinero e ingresa el monto', 'error');
      $('caja-contado').focus();
      return;
    }
    var conf = confirm('¿Cerrar la caja del día con $' + fmt(parseInt(contado, 10)) + ' contados?');
    if (!conf) return;

    api('api/caja.php?action=cerrar', { method: 'POST', body: { monto_contado: parseInt(contado, 10) } })
      .then(function (res) {
        if (!res.ok) { window.mostrarToast(res.error || 'No se pudo cerrar', 'error'); return; }
        var dif = parseInt(res.diferencia, 10);
        var msg = dif === 0
          ? '✅ Caja cuadrada perfectamente ($' + fmt(res.esperado) + ')'
          : (dif > 0 ? '⚠ Sobran $' + fmt(dif) : '⚠ Faltan $' + fmt(Math.abs(dif)));
        alert('CAJA CERRADA\n\nEsperado: $' + fmt(res.esperado) + '\nContado: $' + fmt(res.contado) +
              '\nDiferencia: ' + (dif >= 0 ? '+' : '') + '$' + fmt(dif) + '\n\n' + msg.replace(/<[^>]*>/g, ''));
        $('caja-contado').value = '';
        refrescarCaja();
      }).catch(function () {});
  }

  /* ============================================================== GASTOS */
  var mesActual = new Date().toISOString().slice(0, 7); // YYYY-MM

  function labelMes(ym) {
    var p = ym.split('-');
    var meses = ['ENERO','FEBRERO','MARZO','ABRIL','MAYO','JUNIO','JULIO','AGOSTO','SEPTIEMBRE','OCTUBRE','NOVIEMBRE','DICIEMBRE'];
    return meses[parseInt(p[1],10)-1] + ' ' + p[0];
  }

  function refrescarGastos() {
    api('api/gastos.php?action=list&mes=' + mesActual).then(function (res) {
      if (!res.ok) return;
      $('mes-label').textContent = labelMes(res.mes);

      var tbody = $('gastos-body');
      tbody.replaceChildren();
      (res.gastos || []).forEach(function (g) {
        var tr = document.createElement('tr');
        tr.className = 'hover:bg-slate-800/30 transition-colors';
        function td(texto, clase) {
          var c = document.createElement('td'); c.className = clase; c.textContent = texto; return c;
        }
        tr.appendChild(td(g.fecha, 'p-2.5 text-slate-400 text-xs'));
        tr.appendChild(td(g.concepto, 'p-2.5 text-white'));
        tr.appendChild(td(g.categoria, 'p-2.5 text-slate-400'));
        tr.appendChild(td('$' + fmt(g.monto), 'p-2.5 text-right text-red-400 font-bold'));

        var tdB = document.createElement('td');
        tdB.className = 'p-2.5 text-center';
        var btnX = document.createElement('button');
        btnX.type = 'button'; btnX.title = 'Eliminar'; btnX.innerHTML = '<i class="fa-solid fa-xmark pointer-events-none"></i>';
        btnX.className = 'w-7 h-7 rounded-md bg-slate-800 hover:bg-red-600 text-slate-400 hover:text-white transition-all';
        btnX.addEventListener('click', function () {
          if (!confirm('¿Eliminar gasto "' + g.concepto + '"?')) return;
          api('api/gastos.php?action=delete', { method: 'POST', body: { id: g.id } })
            .then(function (r2) { if (r2.ok) { refrescarGastos(); } });
        });
        tdB.appendChild(btnX);
        tr.appendChild(tdB);
        tbody.appendChild(tr);
      });

      if (!(res.gastos || []).length) {
        var v = document.createElement('tr');
        var c = document.createElement('td');
        c.colSpan = 5; c.className = 'p-4 text-center text-slate-500 italic';
        c.textContent = 'Sin gastos registrados este mes.';
        v.appendChild(c); tbody.appendChild(v);
      }

      var r = res.resumen || {};
      $('rep-ingresos').textContent = fmt(r.ingresos_ventas || 0);
      $('rep-gastos').textContent = fmt(r.gastos || 0);
      var resultado = parseInt(r.resultado, 10);
      var el = $('rep-resultado');
      el.textContent = '$' + fmt(resultado);
      el.className = 'text-3xl font-black mt-1 ' + (resultado >= 0 ? 'text-emerald-400' : 'text-red-400');

      var ulCat = $('rep-cat'); ulCat.replaceChildren();
      var cats = (r.gastos_por_categoria || []);
      if (!cats.length) {
        ulCat.appendChild(Object.assign(document.createElement('li'), { textContent: '—', className: 'text-slate-500' }));
      }
      cats.forEach(function (cat) {
        var li = document.createElement('li');
        li.className = 'flex justify-between';
        var n = document.createElement('span'); n.className='text-slate-400'; n.textContent = cat.categoria;
        var vv = document.createElement('span'); vv.className='font-bold'; vv.textContent = '$'+fmt(cat.total);
        li.appendChild(n); li.appendChild(vv);
        ulCat.appendChild(li);
      });
    }).catch(function () {});
  }

  function guardarGasto(ev) {
    ev.preventDefault();
    api('api/gastos.php?action=create', {
      method: 'POST',
      body: {
        concepto: $('g-concepto').value.trim(),
        categoria: $('g-cat').value.trim(),
        monto: parseInt($('g-monto').value, 10) || 0,
        fecha: $('g-fecha').value
      }
    }).then(function (res) {
      if (!res.ok) { window.mostrarToast(res.error || 'No se pudo registrar el gasto', 'error'); return; }
      window.mostrarToast('Gasto registrado', 'success');
      $('g-concepto').value = ''; $('g-cat').value = ''; $('g-monto').value = '';
      refrescarGastos();
    }).catch(function () {});
  }

  /* ------------------------------------------------------------ ARRANQUE */
  document.addEventListener('DOMContentLoaded', function () {
    $('btn-abrir-caja').addEventListener('click', abrirCaja);
    $('form-mov').addEventListener('submit', agregarMovimiento);
    $('btn-cerrar-caja').addEventListener('click', cerrarCaja);
    $('form-gasto').addEventListener('submit', guardarGasto);

    $('mes-prev').addEventListener('click', function () {
      var p = mesActual.split('-').map(Number);
      p[1]--; if (p[1] < 1) { p[1] = 12; p[0]--; }
      mesActual = p[0] + '-' + String(p[1]).padStart(2, '0');
      refrescarGastos();
    });
    $('mes-next').addEventListener('click', function () {
      var p = mesActual.split('-').map(Number);
      p[1]++; if (p[1] > 12) { p[1] = 1; p[0]++; }
      mesActual = p[0] + '-' + String(p[1]).padStart(2, '0');
      refrescarGastos();
    });
    $('g-fecha').value = new Date().toISOString().slice(0, 10);

    api('api/auth.php?action=me').then(function (res) {
      mostrarVista(!!res.logueado);
    }).catch(function () { mostrarVista(false); });
  });
})();


