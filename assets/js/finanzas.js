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
    if (logueado) { refrescarCaja(); refrescarGastos(); cargarTienda(); }
  }

  /* ============================================ ¿CÓMO ESTÁ MI TIENDA? */
  /** Tarjeta compacta del resumen de tienda (etiqueta + valor + detalle). */
  function tarjetaTienda(etiqueta, icono, valor, sub, clase) {
    var card = document.createElement('div');
    card.className = 'bg-slate-900/80 border border-slate-800 rounded-xl p-3';
    var l = document.createElement('p');
    l.className = 'text-[10px] uppercase tracking-wider text-slate-500 font-bold';
    l.innerHTML = '<i class="fa-solid ' + icono + ' mr-1"></i>' + etiqueta;
    var v = document.createElement('p');
    v.className = 'text-xl font-black mt-1 ' + (clase || 'text-slate-200');
    v.textContent = valor;
    var s = document.createElement('p');
    s.className = 'text-[10px] text-slate-500 mt-0.5';
    s.textContent = sub || '';
    card.appendChild(l); card.appendChild(v); card.appendChild(s);
    return card;
  }

  /** Pantallazo único del estado del negocio: caja, ventas del día, órdenes,
   *  por cobrar, entregas del mes, gastos, estantería y garantías. Usa solo
   *  APIs que ya existen (lectura) — funciona igual para dueño y encargado. */
  function cargarTienda() {
    var grid = $('tienda-grid');
    if (!grid) return;
    grid.replaceChildren();
    grid.appendChild(tarjetaTienda('Cargando…', 'fa-spinner fa-spin', '…', ''));

    Promise.all([
      api('api/caja.php?action=estado'),
      api('api/ventas.php?action=resumen_dia'),
      api('api/ordenes.php?action=list'),
      api('api/gastos.php?action=list'),
      api('api/ordenes.php?action=estanteria'),
      api('api/ordenes.php?action=garantias')
    ]).then(function (rs) {
      var caja = rs[0], ventas = rs[1], ordenes = rs[2], gastos = rs[3], est = rs[4], gar = rs[5];
      grid.replaceChildren();

      // 1) CAJA: abierta (cuadrada o pendiente de arqueo) o cerrada
      if (caja.abierta) {
        var clsCaja = 'text-emerald-400';
        var subCaja = 'Fondo $' + fmt(caja.sesion.monto_apertura) +
                      (caja.sesion.abierta_dia ? ' · desde el ' + caja.sesion.abierta_dia : ' · de hoy');
        if ((caja.sesion.dias_abierta || 0) >= 1) { clsCaja = 'text-red-400'; subCaja = '⚠ sin arquear desde el ' + caja.sesion.abierta_dia; }
        grid.appendChild(tarjetaTienda('Caja abierta', 'fa-cash-register', fmt(caja.efectivo_esperado), subCaja, clsCaja));
      } else {
        grid.appendChild(tarjetaTienda('Caja', 'fa-cash-register', 'Cerrada', 'Ábrela para registrar el efectivo', 'text-slate-400'));
      }

      // 2) VENTAS DE HOY (POS)
      var nVentas = 0;
      (ventas.detalle || []).forEach(function (d) { nVentas += parseInt(d.n, 10) || 0; });
      grid.appendChild(tarjetaTienda('Ventas de hoy (POS)', 'fa-cart-shopping', fmt(ventas.total_dia || 0),
        nVentas + (nVentas === 1 ? ' venta' : ' ventas'), 'text-cyan-400'));

      // 3-5) ÓRDENES: en taller, por cobrar y entregadas del mes
      var lista = ordenes.ordenes || [];
      var enTaller = 0, listas = 0, porCobrar = 0, nPorCobrar = 0, entregadasMes = 0;
      var mesHoy = new Date().toISOString().slice(0, 7);
      lista.forEach(function (o) {
        if (o.estado !== 'Entregado') {
          enTaller++;
          if (o.estado === 'Listo para Retiro') listas++;
          var saldo = (parseInt(o.total, 10) || 0) - (parseInt(o.abono, 10) || 0);
          if (saldo > 0) { porCobrar += saldo; nPorCobrar++; }
        }
        if (o.estado === 'Entregado' && (o.fecha_entrega || '').slice(0, 7) === mesHoy) entregadasMes++;
      });
      grid.appendChild(tarjetaTienda('Órdenes en el taller', 'fa-screwdriver-wrench', String(enTaller),
        listas + ' listas para retiro', 'text-cyan-400'));
      grid.appendChild(tarjetaTienda('Por cobrar (taller)', 'fa-money-bill-wave', fmt(porCobrar),
        nPorCobrar + (nPorCobrar === 1 ? ' orden con saldo' : ' órdenes con saldo'), porCobrar > 0 ? 'text-red-400' : 'text-slate-500'));
      grid.appendChild(tarjetaTienda('Entregadas este mes', 'fa-box-open', String(entregadasMes),
        'reparaciones completadas', 'text-emerald-400'));

      // 6) GASTOS DEL MES
      var gastosMes = (gastos.resumen && gastos.resumen.gastos) || 0;
      grid.appendChild(tarjetaTienda('Gastos del mes', 'fa-receipt', fmt(gastosMes),
        'registrados en Finanzas', gastosMes > 0 ? 'text-amber-400' : 'text-slate-500'));

      // 7) ESTANTERÍA: equipos listos sin retirar
      var nEst = (est.ordenes || []).length;
      grid.appendChild(tarjetaTienda('En estantería', 'fa-boxes-stacked', String(nEst),
        nEst > 0 ? '¡avisar a los clientes!' : 'nada esperando', nEst > 0 ? 'text-amber-400' : 'text-slate-500'));

      // 8) GARANTÍAS POR VENCER (≤ 7 días)
      var nGar = (gar.ordenes || []).length;
      grid.appendChild(tarjetaTienda('Garantías por vencer', 'fa-shield-halved', String(nGar),
        nGar > 0 ? 'contactar para renovar' : 'nada por vencer', nGar > 0 ? 'text-violet-400' : 'text-slate-500'));

      var fecha = $('tienda-fecha');
      if (fecha) fecha.textContent = new Date().toLocaleDateString('es-CL', { weekday: 'long', day: 'numeric', month: 'long' });
    }).catch(function () {
      grid.replaceChildren();
      grid.appendChild(tarjetaTienda('Sin conexión', 'fa-triangle-exclamation', '—', 'Revisa que MySQL esté encendido', 'text-red-400'));
    });
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

      // Aviso anti-olvido: caja abierta desde un día anterior sin arquear
      var aviso = $('caja-aviso');
      if (aviso) {
        if ((res.sesion.dias_abierta || 0) >= 1) {
          aviso.textContent = '⚠️ ' + (res.aviso || ('Caja abierta desde el ' + (res.sesion.abierta_dia || '') + ' sin arquear.'));
          aviso.classList.remove('hidden');
        } else {
          aviso.textContent = '';
          aviso.classList.add('hidden');
        }
      }

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

  /* --------------------------------------------- ARQUEO POR DENOMINACIONES */
  var DENOMS = [20000, 10000, 5000, 2000, 1000, 500, 100, 50, 10];

  function renderArqueo() {
    var grid = $('denom-grid');
    grid.replaceChildren();
    DENOMS.forEach(function (v) {
      var wrap = document.createElement('label');
      wrap.className = 'flex items-center gap-2 bg-slate-900 border border-slate-800 rounded-lg px-2.5 py-1.5';
      var lbl = document.createElement('span');
      lbl.className = 'text-xs font-bold text-slate-300 w-16';
      lbl.textContent = '$' + fmt(v);
      var inp = document.createElement('input');
      inp.type = 'number'; inp.min = '0'; inp.value = '0';
      inp.dataset.valor = String(v);
      inp.className = 'denom-input w-full bg-slate-800 border border-slate-700 rounded px-2 py-1 text-white text-right text-xs focus:outline-none focus:border-cyan-500';
      inp.addEventListener('input', sumarArqueo);
      wrap.appendChild(lbl); wrap.appendChild(inp);
      grid.appendChild(wrap);
    });
  }

  function totalArqueo() {
    var total = 0;
    document.querySelectorAll('.denom-input').forEach(function (inp) {
      total += (parseInt(inp.dataset.valor, 10) || 0) * (parseInt(inp.value, 10) || 0);
    });
    return total;
  }

  function sumarArqueo() {
    var total = totalArqueo();
    $('denom-total').textContent = fmt(total);
    $('caja-contado').value = total; // el arqueo manda: sincroniza el monto del cierre
  }

  function limpiarArqueo() {
    document.querySelectorAll('.denom-input').forEach(function (inp) { inp.value = '0'; });
    sumarArqueo();
  }

  function alternarArqueo() {
    var box = $('caja-denominaciones');
    box.classList.toggle('hidden');
    if (!box.classList.contains('hidden') && !$('denom-grid').children.length) { renderArqueo(); }
  }

  /** Ticket 80mm del arqueo: esperado, desglose contado y diferencia. */
  function imprimirArqueo(res, denoms, hayArqueo) {
    var s = res.sesion || {};
    var dif = parseInt(res.diferencia, 10);
    var filas = '';
    Object.keys(denoms).sort(function (a, b) { return b - a; }).forEach(function (v) {
      filas += '<tr><td>' + denoms[v] + ' x</td><td align="right">$' + fmt(parseInt(v, 10)) +
               '</td><td align="right">$' + fmt(parseInt(v, 10) * denoms[v]) + '</td></tr>';
    });
    var html = (
      '<html><head><title>Arqueo de caja</title><style>' +
      '@page{size:80mm auto;margin:0}body{font-family:monospace;font-size:12px;line-height:1.25;padding:3mm 2mm;color:#000;width:74mm;margin:0 auto;-webkit-print-color-adjust:exact;print-color-adjust:exact}' +
      'h2{text-align:center;margin:4px 0;font-size:15px}.c{text-align:center}.d{border-top:1px dashed #000;margin:8px 0;border-bottom:1px dashed #000;padding:8px 0}' +
      'table{width:100%;border-collapse:collapse}td{padding:2px 0;font-size:11px}' +
      '.t{font-size:14px;font-weight:bold;text-align:right;margin-top:8px}' +
      '</style></head><body>' +
      '<h2>ARQUEO DE CAJA</h2>' +
      '<div class="c">' + (s.abierta_por ? 'Abierta por: ' + esc(s.abierta_por) + '<br>' : '') +
      (s.dia ? 'Apertura: ' + esc(s.dia) : '') + '</div>' +
      '<div class="d"><table>' +
      '<tr><td>Fondo de apertura</td><td align="right">$' + fmt(s.monto_apertura || 0) + '</td></tr>' +
      '<tr><td><b>Efectivo esperado</b></td><td align="right"><b>$' + fmt(res.esperado) + '</b></td></tr>' +
      '</table></div>' +
      (hayArqueo
        ? '<div class="d"><p class="c" style="margin:0 0 4px"><b>Conteo por denominaciones</b></p><table>' + filas +
          '<tr><td colspan="2"><b>TOTAL CONTADO</b></td><td align="right"><b>$' + fmt(res.contado) + '</b></td></tr></table></div>'
        : '<div class="d c">Contado sin desglose</div>') +
      '<p class="t">DIFERENCIA: ' + (dif === 0 ? '$0 — CAJA CUADRADA' : (dif > 0 ? '+$' + fmt(dif) : '-$' + fmt(Math.abs(dif)))) + '</p>' +
      (dif !== 0 ? '<div class="c">Revisar faltante/sobrante con el dueño</div>' : '') +
      '<div class="c" style="margin-top:10px">Firma del cierre: ______________</div>' +
      '</body></html>'
    );
    window.imprimirDocumento(html);
  }

  function cerrarCaja() {
    var contado = $('caja-contado').value;
    if (contado === '') {
      window.mostrarToast('Primero cuenta el dinero e ingresa el monto (o usa el arqueo por denominaciones)', 'error');
      $('caja-contado').focus();
      return;
    }
    var conf = confirm('¿Cerrar la caja del día con $' + fmt(parseInt(contado, 10)) + ' contados?');
    if (!conf) return;

    // Arqueo por denominaciones (si la grilla está en uso): el contado real
    // es la suma de billetes/monedas y queda guardado + impreso en el ticket.
    var denoms = {};
    var hayArqueo = false;
    document.querySelectorAll('.denom-input').forEach(function (inp) {
      var cant = parseInt(inp.value, 10) || 0;
      if (cant > 0) { denoms[inp.dataset.valor] = cant; hayArqueo = true; }
    });

    var cuerpo = { monto_contado: parseInt(contado, 10) };
    if (hayArqueo) { cuerpo.denominaciones = denoms; }

    api('api/caja.php?action=cerrar', { method: 'POST', body: cuerpo })
      .then(function (res) {
        if (!res.ok) { window.mostrarToast(res.error || 'No se pudo cerrar', 'error'); return; }
        var dif = parseInt(res.diferencia, 10);
        var msg = dif === 0
          ? '✅ Caja cuadrada perfectamente ($' + fmt(res.esperado) + ')'
          : (dif > 0 ? '⚠ Sobran $' + fmt(dif) : '⚠ Faltan $' + fmt(Math.abs(dif)));
        alert('CAJA CERRADA\n\nEsperado: $' + fmt(res.esperado) + '\nContado: $' + fmt(res.contado) +
              '\nDiferencia: ' + (dif >= 0 ? '+' : '') + '$' + fmt(dif) + '\n\n' + msg.replace(/<[^>]*>/g, ''));
        imprimirArqueo(res, denoms, hayArqueo);
        limpiarArqueo();
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

  var gastosCache = [];
  var ultimoResumen = {};
  var ultimoMes = mesActual;

  /** Escapa texto para incrustarlo en el HTML del reporte impreso. */
  function esc(t) {
    return String(t === null || t === undefined ? '' : t)
      .replace(/[&<>"]/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
      });
  }

  /** Dibuja la tabla aplicando buscador (cada palabra debe coincidir) + categoría. */
  function renderGastos() {
    var q = ($('gastos-buscar') ? $('gastos-buscar').value : '').toLowerCase().trim();
    var palabras = q ? q.split(/\s+/) : [];
    var cat = $('gastos-filtro-cat') ? $('gastos-filtro-cat').value : '';
    var tbody = $('gastos-body');
    tbody.replaceChildren();
    var visibles = 0;

    gastosCache.forEach(function (g) {
      if (cat && (g.categoria || 'General') !== cat) return;
      var texto = ((g.concepto || '') + ' ' + (g.categoria || '')).toLowerCase();
      for (var i = 0; i < palabras.length; i++) {
        if (texto.indexOf(palabras[i]) === -1) return;
      }
      visibles++;
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
      btnX.className = 'btn-del-gasto w-7 h-7 rounded-md bg-slate-800 hover:bg-red-600 text-slate-400 hover:text-white transition-all';
      btnX.addEventListener('click', function () {
        if (!confirm('¿Eliminar gasto "' + g.concepto + '"?')) return;
        api('api/gastos.php?action=delete', { method: 'POST', body: { id: g.id } })
          .then(function (r2) { if (r2.ok) { refrescarGastos(); } });
      });
      tdB.appendChild(btnX);
      tr.appendChild(tdB);
      tbody.appendChild(tr);
    });

    if (!visibles) {
      var v = document.createElement('tr');
      var c = document.createElement('td');
      c.colSpan = 5; c.className = 'p-4 text-center text-slate-500 italic';
      c.textContent = gastosCache.length ? 'Sin resultados para la búsqueda.' : 'Sin gastos registrados este mes.';
      v.appendChild(c); tbody.appendChild(v);
    }
  }

  /** Imprime el reporte del mes (tícket 80mm / PDF desde el diálogo). */
  function imprimirReporte() {
    var r = ultimoResumen || {};
    var filas = '<tr><td><b>Ingresos taller (' + (parseInt(r.ordenes_cobradas, 10) || 0) + ' órdenes)</b></td><td align="right"><b>$' + fmt(r.ingresos_taller || 0) + '</b></td></tr>';
    filas += '<tr><td>Ventas POS</td><td align="right">$' + fmt(r.ingresos_ventas || 0) + '</td></tr>';
    filas += '<tr><td><b>INGRESOS TOTALES</b></td><td align="right"><b>$' + fmt(r.ingresos_totales || 0) + '</b></td></tr>';
    filas += '<tr><td>Gastos negocio</td><td align="right">$' + fmt(r.gastos || 0) + '</td></tr>';
    filas += '<tr><td>Comisiones pagadas (' + (parseInt(r.comisiones_n, 10) || 0) + ')</td><td align="right">$' + fmt(r.comisiones_pagadas || 0) + '</td></tr>';
    filas += '<tr><td>Costo repuestos</td><td align="right">$' + fmt(r.costo_repuestos || 0) + '</td></tr>';
    filas += '<tr><td><b>EGRESOS TOTALES</b></td><td align="right"><b>$' + fmt(r.egresos_totales || 0) + '</b></td></tr>';
    var util = parseInt(r.utilidad, 10) || 0;
    filas += '<tr><td style="border-top:1px dashed #000"><b>UTILIDAD DEL MES</b></td>' +
             '<td align="right" style="border-top:1px dashed #000"><b>' + (util < 0 ? '-$' + fmt(Math.abs(util)) : '$' + fmt(util)) + '</b></td></tr>';
    if ((parseInt(r.comisiones_pendientes, 10) || 0) > 0) {
      filas += '<tr><td>Pendiente pagar técnicos</td><td align="right">$' + fmt(r.comisiones_pendientes || 0) + '</td></tr>';
    }
    var cats = r.gastos_por_categoria || [];
    if (!cats.length) {
      filas += '<tr><td>Sin gastos categorizados</td><td align="right">$0</td></tr>';
    }
    cats.forEach(function (cat) {
      filas += '<tr><td>' + esc(cat.categoria) + '</td><td align="right">$' + fmt(cat.total) + '</td></tr>';
    });
    filas += '<tr><td>Detalle gastos negocio</td><td align="right"></td></tr>';

    api('api/configuracion.php?action=get_all').then(function (cfgRes) {
      var cfg = (cfgRes && cfgRes.config) ? cfgRes.config : {};
      var hoy = new Date().toLocaleDateString('es-CL');
      var html =
        '<html><head><title>Reporte ' + esc(labelMes(ultimoMes)) + '</title><style>' +
        '@page{margin:0}body{font-family:monospace;font-size:12px;padding:14px;color:#000}' +
        'h2{text-align:center;margin:4px 0;font-size:15px}.c{text-align:center}.d{border-top:1px dashed #000;margin:8px 0;border-bottom:1px dashed #000;padding:8px 0}' +
        'table{width:100%;border-collapse:collapse}td{padding:2px 0;vertical-align:top;font-size:11px}' +
        'img.logo{max-width:110px;margin:0 auto 4px;display:block}' +
        '</style></head><body>' +
        (cfg.empresa_logo
          ? '<img class="logo" src="' + esc(new URL(cfg.empresa_logo, location.href).href) + '">'
          : '') +
        '<h2>' + esc(cfg.empresa_nombre || 'LUITECH SERVICIO TECNICO') + '</h2>' +
        '<div class="c">' + esc(cfg.empresa_direccion || '') +
        (cfg.empresa_telefono ? '<br>WhatsApp ' + esc(cfg.empresa_telefono) : '') + '</div>' +
        '<div class="d c"><b>REPORTE ' + esc(labelMes(ultimoMes)) + '</b><br>Emitido: ' + hoy + '</div>' +
        '<table>' + filas + '</table>' +
        '<p class="c" style="margin-top:10px">Documento generado por el sistema Luitech</p>' +
        '</body></html>';
      window.imprimirDocumento(html);
    }).catch(function () {});
  }

  function refrescarGastos() {
    api('api/gastos.php?action=list&mes=' + mesActual).then(function (res) {
      if (!res.ok) return;
      $('mes-label').textContent = labelMes(res.mes);
      ultimoMes = res.mes;
      gastosCache = res.gastos || [];
      ultimoResumen = res.resumen || {};

      // Barra del acordeón: total + cantidad sin necesidad de abrir nada
      var barra = $('gastos-barra');
      if (barra) {
        var n = (ultimoResumen.cantidad !== undefined) ? ultimoResumen.cantidad : gastosCache.length;
        barra.textContent = labelMes(res.mes) + ': $' + fmt(ultimoResumen.gastos || 0) + ' en ' + n + ' gastos';
      }

      // Opciones del filtro de categoría (cada una con su total)
      var sel = $('gastos-filtro-cat');
      if (sel) {
        var previa = sel.value;
        sel.replaceChildren();
        var op0 = document.createElement('option');
        op0.value = ''; op0.textContent = 'Todas las categorías';
        sel.appendChild(op0);
        (ultimoResumen.gastos_por_categoria || []).forEach(function (cat) {
          var o = document.createElement('option');
          o.value = cat.categoria;
          o.textContent = cat.categoria + ' ($' + fmt(cat.total) + ')';
          sel.appendChild(o);
        });
        sel.value = previa;
        if (sel.selectedIndex === -1) { sel.value = ''; }
      }

      renderGastos();

      var r = res.resumen || {};
      // Compat: los IDs rep-ingresos/rep-gastos/rep-resultado/rep-cat ya no
      // existen en el HTML (se reemplazaron por utilidad-box). Se pintan solo
      // si existen para no romper el resto del reporte.
      var setT0 = function (id, v) { var e = $(id); if (e) e.textContent = v; };
      setT0('rep-ingresos', fmt(r.ingresos_ventas || 0));
      setT0('rep-gastos', fmt(r.gastos || 0));
      var resultado = parseInt(r.resultado, 10) || 0;
      var el = $('rep-resultado');
      if (el) {
        el.textContent = '$' + fmt(resultado);
        el.className = 'text-3xl font-black mt-1 ' + (resultado >= 0 ? 'text-emerald-400' : 'text-red-400');
      }

      // Utilidad real del negocio: taller + POS − gastos − comisiones − repuestos
      var ingT = parseInt(r.ingresos_taller, 10) || 0;
      var ingP = parseInt(r.ingresos_ventas, 10) || 0;
      var egrT = parseInt(r.egresos_totales, 10) || 0;
      var util = parseInt(r.utilidad, 10) || 0;
      var setT = function (id, v) { var e = $(id); if (e) e.textContent = v; };
      setT('rep-ing-taller', '$' + fmt(ingT));
      setT('rep-ing-pos', '$' + fmt(ingP));
      setT('rep-egr', '$' + fmt(egrT));
      var elU = $('rep-utilidad');
      if (elU) {
        elU.textContent = (util < 0 ? '-$' : '$') + fmt(Math.abs(util));
        elU.className = 'text-2xl font-black mt-1 ' + (util >= 0 ? 'text-emerald-400' : 'text-red-400');
      }
      setT('rep-ing-taller-sub', (parseInt(r.ordenes_cobradas, 10) || 0) + ' órdenes cobradas');
      setT('rep-egr-sub', 'gastos $' + fmt(r.gastos || 0) + ' + comis. $' + fmt(r.comisiones_pagadas || 0) + ' + reptos. $' + fmt(r.costo_repuestos || 0));
      setT('rep-utilidad-sub', (parseInt(r.comisiones_pendientes, 10) || 0) > 0
        ? 'pendiente pagar $' + fmt(r.comisiones_pendientes || 0) + ' a técnicos'
        : 'ingresos $' + fmt((parseInt(r.ingresos_totales, 10) || 0)) + ' − egresos $' + fmt(egrT));

      var ulCat = $('rep-cat');
      if (ulCat) {
      ulCat.replaceChildren();
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
      }
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
    $('btn-arqueo').addEventListener('click', alternarArqueo);
    $('btn-arqueo-limpiar').addEventListener('click', limpiarArqueo);
    $('form-gasto').addEventListener('submit', guardarGasto);

    // Acordeón de gastos + filtros + impresión del reporte
    $('btn-gastos-toggle').addEventListener('click', function () {
      var cuerpo = $('gastos-cuerpo');
      var abierto = cuerpo.classList.toggle('abierto');
      $('gastos-chevron').classList.toggle('abierto', abierto);
      this.setAttribute('aria-expanded', abierto ? 'true' : 'false');
    });
    $('gastos-buscar').addEventListener('input', renderGastos);
    $('gastos-filtro-cat').addEventListener('change', renderGastos);
    $('btn-reporte-print').addEventListener('click', imprimirReporte);

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


