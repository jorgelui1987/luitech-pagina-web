/* ==========================================================================
   LUITECH — cotizador.js · Búsqueda instantánea de precios por proveedor
   Resultados agrupados por categoría (Incell / OLED / Originales…) para
   responder al cliente de un vistazo. Igual en PC y teléfono.
   ========================================================================== */
(function () {
  'use strict';

  var api = window.LuitechAPI;
  var $ = function (id) { return document.getElementById(id); };
  var empresaCfg = null;
  var catMargen = 40;
  var catRedondeo = 0;
  var timer = null;
  var seleccion = {};   // id -> { c: item, cant: X, desc: Y } items de la cotización
  var ultimos = { catalogo: [], q: '' };
  var listaProveedoresCot = []; // para consultar por WhatsApp cuando no hay resultados

  function fmt(n) { return '$ ' + Math.max(0, Math.round(Number(n) || 0)).toLocaleString('es-CL'); }

  /** Limpia el teléfono a formato wa.me (solo dígitos, con país 56). */
  function telefonoWhatsapp(tel) {
    var d = String(tel || '').replace(/[^0-9]/g, '');
    if (!d) return '';
    if (d.indexOf('56') === 0) return d;
    return '56' + d;
  }
  function esc(t) {
    return String(t === null || t === undefined ? '' : t).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function mostrarVista(logueado) {
    $('view-nologin').classList.toggle('hidden', logueado);
    $('view-cot').classList.toggle('hidden', !logueado);
    if (logueado) { cargarConfig(); cargarProveedoresCot(); $('cot-buscar').focus(); }
  }

  /** Carga los proveedores en el filtro (para buscar por proveedor exacto). */
  function cargarProveedoresCot() {
    api('api/proveedores.php?action=list').then(function (res) {
      if (!res.ok) return;
      listaProveedoresCot = res.proveedores || [];
      var sel = $('cot-prov');
      var actual = sel.value;
      sel.replaceChildren(new Option('Todos los proveedores', ''));
      (res.proveedores || []).forEach(function (p) {
        sel.add(new Option(p.nombre, String(p.id)));
      });
      sel.value = actual;
    }).catch(function () {});
  }

  function cargarConfig() {
    api('api/configuracion.php?action=get_all').then(function (res) {
      if (!res.ok) { buscar(); return; }
      empresaCfg = res.config || {};
      catMargen = parseInt(empresaCfg.catalogo_margen, 10);
      if (isNaN(catMargen) || catMargen < 0) catMargen = 40;
      catRedondeo = parseInt(empresaCfg.catalogo_redondeo, 10);
      if (isNaN(catRedondeo) || catRedondeo < 0) catRedondeo = 0;
      buscar();
    }).catch(function () { buscar(); });
  }

  /** Precio de venta automático: costo + margen %, con redondeo opcional. */
  function precioSugerido(costo) {
    var p = Math.round(costo * (1 + catMargen / 100));
    if (catRedondeo > 0) p = Math.round(p / catRedondeo) * catRedondeo;
    return Math.max(0, p);
  }

  /** Precio efectivo mostrado: el FIJO del negocio si existe; si no, el
   *  PRECIO DEL PROVEEDOR tal cual. El margen queda solo como sugerencia
   *  al fijar tu precio final con el lápiz. */
  function precioDe(c) {
    var fijo = parseInt(c.precio_venta, 10);
    return (fijo > 0) ? fijo : Math.max(0, parseInt(c.precio, 10) || 0);
  }

  function buscar() {
    var q = $('cot-buscar').value.trim();
    var prov = $('cot-prov').value;
    api('api/proveedores.php?action=catalogo_list' + (q ? '&q=' + encodeURIComponent(q) : '') +
        (prov ? '&proveedor_id=' + encodeURIComponent(prov) : ''))
      .then(function (res) {
        if (!res.ok) return;
        ultimos.catalogo = res.catalogo || [];
        ultimos.q = q;
        render(ultimos.catalogo, q);
      }).catch(function () {});
  }

  /** Resultados AGRUPADOS por categoría: INCELL / OLED / ORIGINALES… con el
   *  precio "desde" de cada grupo para comparar de un vistazo. */
  function render(catalogo, q) {
    var cont = $('cot-resultados');
    cont.replaceChildren();
    $('cot-estado').textContent = catalogo.length ? catalogo.length + ' items' : '';

    if (!catalogo.length) {
      var pv = document.createElement('p');
      pv.className = 'text-slate-500 italic text-center py-10 text-sm';
      pv.textContent = q ? 'Sin resultados para "' + q + '".' : 'Escribe arriba: ej. "pantalla iphone 13" o "a54".';
      cont.appendChild(pv);

      // ¿No está? Consultar directo por WhatsApp al proveedor
      if (q) {
        var conWa = [];
        listaProveedoresCot.forEach(function (p) {
          var num = telefonoWhatsapp(p.telefono);
          if (num) conWa.push({ nombre: p.nombre, num: num });
        });
        var cajaWa = document.createElement('div');
        cajaWa.className = 'bg-slate-950 border border-slate-800 rounded-2xl p-4';
        var tw = document.createElement('p');
        tw.className = 'text-xs font-bold text-white mb-2';
        tw.textContent = '¿No está en la lista? Consúltale directo por WhatsApp:';
        cajaWa.appendChild(tw);
        if (conWa.length) {
          conWa.forEach(function (p) {
            var a = document.createElement('a');
            a.href = 'https://wa.me/' + p.num + '?text=' + encodeURIComponent('¡Hola ' + p.nombre + '! ¿Tienen: ' + q + '? ¿Me cotizas precio y disponibilidad?');
            a.target = '_blank';
            a.rel = 'noopener';
            a.className = 'inline-flex items-center gap-2 bg-emerald-950 hover:bg-emerald-800 border border-emerald-800 text-emerald-300 font-bold text-xs px-3 py-2 rounded-xl mr-2 mb-2 transition-all';
            a.innerHTML = '<i class="fa-brands fa-whatsapp text-base pointer-events-none"></i>' + esc(p.nombre);
            cajaWa.appendChild(a);
          });
        } else {
          var aviso = document.createElement('p');
          aviso.className = 'text-[11px] text-slate-500';
          aviso.textContent = 'Guarda el WhatsApp de tus proveedores en Proveedores → campo "Teléfono / WhatsApp" para consultar desde aquí.';
          cajaWa.appendChild(aviso);
        }
        cont.appendChild(cajaWa);
      }
      return;
    }

    var grupos = {};
    catalogo.forEach(function (c) {
      var k = c.pieza || 'Catálogo';
      (grupos[k] = grupos[k] || []).push(c);
    });
    var claves = Object.keys(grupos);
    claves.sort(function (a, b) { return minPrecio(grupos[a]) - minPrecio(grupos[b]); });

    claves.forEach(function (k) {
      var items = grupos[k];
      items.sort(function (a, b) { return precioDe(a) - precioDe(b); });

      var caja = document.createElement('section');
      caja.className = 'bg-slate-950 border border-slate-800 rounded-2xl overflow-hidden';

      var cab = document.createElement('div');
      cab.className = 'flex justify-between items-center px-4 py-2.5 bg-slate-900/80 border-b border-slate-800';
      var tt = document.createElement('h3');
      tt.className = 'text-xs font-black uppercase tracking-wider text-white';
      tt.textContent = k;
      var desde = document.createElement('span');
      desde.className = 'text-[11px] font-bold text-emerald-400';
      desde.textContent = 'desde ' + fmt(minPrecio(items)) + ' · ' + items.length;
      cab.appendChild(tt);
      cab.appendChild(desde);
      caja.appendChild(cab);

      var lista = document.createElement('ul');
      items.forEach(function (c) {
        lista.appendChild(filaItem(c));
      });
      caja.appendChild(lista);
      cont.appendChild(caja);
    });
  }

  function minPrecio(arr) {
    var m = Infinity;
    arr.forEach(function (c) { var p = precioDe(c); if (p < m) m = p; });
    return m;
  }

  /** Cotización imprimible (80mm / PDF): usa el precio fijo si existe. */
  function cotizar(c) {
    var sugerido = precioDe(c);
    var validez = new Date(Date.now() + 7 * 86400000).toLocaleDateString('es-CL');
    var hoy = new Date().toLocaleDateString('es-CL');
    var cfg = empresaCfg || {};
    var html = (
      '<html><head><title>Cotizacion ' + esc(c.modelo) + '</title><style>' +
      '@page{size:80mm auto;margin:0}body{font-family:monospace;font-size:12px;line-height:1.25;padding:3mm 2mm;color:#000;width:74mm;margin:0 auto;-webkit-print-color-adjust:exact;print-color-adjust:exact}' +
      'h2{text-align:center;margin:4px 0;font-size:15px}.c{text-align:center}.d{border-top:1px dashed #000;margin:8px 0;border-bottom:1px dashed #000;padding:8px 0}' +
      'img.logo{max-width:110px;margin:0 auto 4px;display:block}' +
      '.t{font-size:15px;font-weight:bold;text-align:right;margin-top:8px}' +
      '.pieza{font-size:14px;font-weight:bold;margin:8px 0}' +
      '</style></head><body>' +
      (cfg.empresa_logo
        ? '<img class="logo" src="' + esc(new URL(cfg.empresa_logo, location.href).href) + '">'
        : '') +
      '<h2>' + esc(cfg.empresa_nombre || 'LUITECH SERVICIO TECNICO') + '</h2>' +
      '<div class="c">' + (cfg.empresa_rut ? 'RUT ' + esc(cfg.empresa_rut) + '<br>' : '') + esc(cfg.empresa_direccion || '') +
      (cfg.empresa_telefono ? '<br>WhatsApp ' + esc(cfg.empresa_telefono) : '') + '</div>' +
      '<div class="d c"><b>COTIZACION</b><br>' + hoy + '<br>Validez hasta: ' + validez + '</div>' +
      '<p class="pieza">' + esc(c.pieza) + '</p>' +
      '<p>' + esc(c.modelo) + '</p>' +
      '<p class="t">TOTAL $' + fmt(sugerido) + '</p>' +
      '<p class="c" style="margin-top:8px;font-size:11px">Precio de la pieza. La instalacion y la garantia<br>se confirman al momento de la reparacion.<br>Presupuesto valido hasta la fecha indicada.</p>' +
      '<p class="c" style="margin-top:6px">¡Gracias por preferirnos!</p>' +
      '</body></html>'
    );
    window.imprimirDocumento(html);
  }

  /* -------------------------------------- ARMAR LA COTIZACIÓN (cant. y %) */
  /** Fila de item con cantidad (−/+), % de descuento y total de la línea. */
  function filaItem(c) {
    var s = seleccion[c.id] || (seleccion[c.id] = { c: c, cant: 0, desc: 0 });
    var unit = precioDe(c); // precio final: TU precio fijo si lo definiste, si no el del proveedor
    var costoProv = Math.max(0, parseInt(c.precio, 10) || 0);
    var fijoVal = parseInt(c.precio_venta, 10);

    var li = document.createElement('li');
    li.className = 'flex flex-wrap items-center gap-2 px-3 py-2.5 border-b border-slate-800/40 last:border-0';

    function botonCantidad(texto, titulo, delta) {
      var b = document.createElement('button');
      b.type = 'button';
      b.title = titulo;
      b.textContent = texto;
      b.className = 'w-7 h-7 rounded-lg border border-slate-600 bg-slate-900 hover:bg-slate-800 text-cyan-400 font-black text-sm leading-none';
      b.addEventListener('click', function () {
        s.cant = Math.max(0, s.cant + delta);
        qty.value = String(s.cant);
        actualizarLinea();
      });
      return b;
    }

    li.appendChild(botonCantidad('−', 'Quitar uno', -1));

    var qty = document.createElement('input');
    qty.type = 'number';
    qty.min = '0';
    qty.value = String(s.cant);
    qty.title = 'Cantidad';
    qty.className = 'w-11 h-7 text-center rounded-lg border border-slate-600 bg-slate-900 text-white text-sm font-bold focus:outline-none focus:border-cyan-500';
    qty.addEventListener('input', function () {
      var v = parseInt(qty.value, 10);
      s.cant = (isNaN(v) || v < 0) ? 0 : v;
      actualizarLinea();
    });
    li.appendChild(qty);

    li.appendChild(botonCantidad('+', 'Agregar uno', 1));

    var info = document.createElement('div');
    info.className = 'flex-1';
    info.style.minWidth = '140px';
    var m = document.createElement('p');
    m.className = 'text-sm font-bold text-cyan-400 leading-tight';
    m.textContent = c.modelo;
    var sub = document.createElement('p');
    sub.className = 'text-[10px] text-slate-500';
    var detalle = (c.proveedor_nombre || '') + ' · costo ' + fmt(costoProv) + (c.disponible ? '' : ' · NO DISPONIBLE');
    if (fijoVal > 0) detalle += ' · TU PRECIO ' + fmt(fijoVal);
    sub.textContent = detalle;
    info.appendChild(m);
    info.appendChild(sub);
    li.appendChild(info);

    var btnPrecio = document.createElement('button');
    btnPrecio.type = 'button';
    btnPrecio.title = fijoVal > 0 ? 'Cambiar tu precio final' : 'Fijar tu precio final (sugerido con margen: ' + fmt(precioSugerido(costoProv)) + ' · costo: ' + fmt(costoProv) + ')';
    btnPrecio.innerHTML = '<i class="fa-solid fa-pen pointer-events-none"></i>';
    btnPrecio.className = 'w-6 h-6 rounded bg-slate-800 hover:bg-cyan-600 text-slate-300 text-[10px] transition-all';
    btnPrecio.addEventListener('click', function () {
      var txt = prompt('Tu precio final para ' + c.modelo + '\n\nCosto proveedor: ' + fmt(costoProv) + '\nSugerido con margen ' + catMargen + '%: ' + fmt(precioSugerido(costoProv)) + '\n\nEscribe tu precio (vacío o 0 = volver al precio del proveedor):', fijoVal > 0 ? String(fijoVal) : '');
      if (txt === null) return;
      fijarPrecioVenta(c.id, parseInt(txt, 10) || 0, c);
    });
    li.appendChild(btnPrecio);

    var sel = document.createElement('select');
    sel.title = 'Descuento % para esta línea';
    sel.className = 'bg-slate-900 border border-slate-700 rounded-lg px-1 py-1 text-xs text-white focus:outline-none focus:border-emerald-500';
    [0, 5, 10, 15, 20, 25, 30, 35, 40].forEach(function (d) {
      var o = document.createElement('option');
      o.value = String(d);
      o.textContent = d + ' %';
      if (s.desc === d) o.selected = true;
      sel.appendChild(o);
    });
    sel.addEventListener('change', function () {
      s.desc = parseInt(sel.value, 10) || 0;
      actualizarLinea();
    });
    li.appendChild(sel);

    var tot = document.createElement('span');
    tot.className = 'text-sm font-black text-emerald-400 whitespace-nowrap w-20 text-right';
    li.appendChild(tot);

    function actualizarLinea() {
      var v = Math.round(unit * s.cant * (1 - s.desc / 100));
      tot.textContent = s.cant > 0 ? fmt(v) : '—';
      actualizarTotal();
    }
    actualizarLinea();
    return li;
  }

  /** Barra inferior: total general de la cotización + piezas. */
  function actualizarTotal() {
    var barra = $('cot-total');
    if (!barra) return;
    var total = 0;
    var piezas = 0;
    Object.keys(seleccion).forEach(function (k) {
      var s = seleccion[k];
      if (s.cant > 0) {
        total += Math.round(precioDe(s.c) * s.cant * (1 - s.desc / 100));
        piezas += s.cant;
      }
    });
    barra.classList.toggle('hidden', piezas === 0);
    $('cot-total-monto').textContent = fmt(total) + (piezas > 0 ? '  (' + piezas + (piezas === 1 ? ' pieza)' : ' piezas)') : '');
  }

  /** Imprime la cotización completa con lo seleccionado (80mm / PDF). */
  function imprimirSeleccion() {
    var lineas = [];
    Object.keys(seleccion).forEach(function (k) {
      if (seleccion[k].cant > 0) lineas.push(seleccion[k]);
    });
    if (!lineas.length) {
      window.mostrarToast('Ponle cantidad con el botón + a los items que vas a cotizar', 'error');
      return;
    }
    lineas.sort(function (a, b) {
      return (a.c.pieza + a.c.modelo).localeCompare(b.c.pieza + b.c.modelo);
    });
    var totalGeneral = 0;
    var cuerpo = '';
    var piezaActual = '';
    lineas.forEach(function (s) {
      var subtotal = Math.round(precioDe(s.c) * s.cant * (1 - s.desc / 100));
      totalGeneral += subtotal;
      if (s.c.pieza !== piezaActual) {
        piezaActual = s.c.pieza;
        cuerpo += '<p style="font-weight:bold;margin:6px 0 2px">' + esc(piezaActual) + '</p>';
      }
      cuerpo += '<table style="width:100%;border-collapse:collapse"><tr>' +
        '<td style="padding:1px 0">' + s.cant + ' x ' + esc(s.c.modelo) + '</td>' +
        '<td align="right">$' + fmt(subtotal) + '</td></tr></table>';
    });
    var validez = new Date(Date.now() + 7 * 86400000).toLocaleDateString('es-CL');
    var hoy = new Date().toLocaleDateString('es-CL');
    var cfg = empresaCfg || {};
    var html = (
      '<html><head><title>Cotizacion</title><style>' +
      '@page{size:80mm auto;margin:0}body{font-family:monospace;font-size:12px;line-height:1.25;padding:3mm 2mm;color:#000;width:74mm;margin:0 auto;-webkit-print-color-adjust:exact;print-color-adjust:exact}' +
      'h2{text-align:center;margin:4px 0;font-size:15px}.c{text-align:center}.d{border-top:1px dashed #000;margin:8px 0;border-bottom:1px dashed #000;padding:8px 0}' +
      'img.logo{max-width:110px;margin:0 auto 4px;display:block}' +
      '.t{font-size:15px;font-weight:bold;text-align:right;margin-top:8px}' +
      '</style></head><body>' +
      (cfg.empresa_logo
        ? '<img class="logo" src="' + esc(new URL(cfg.empresa_logo, location.href).href) + '">'
        : '') +
      '<h2>' + esc(cfg.empresa_nombre || 'LUITECH SERVICIO TECNICO') + '</h2>' +
      '<div class="c">' + (cfg.empresa_rut ? 'RUT ' + esc(cfg.empresa_rut) + '<br>' : '') + esc(cfg.empresa_direccion || '') +
      (cfg.empresa_telefono ? '<br>WhatsApp ' + esc(cfg.empresa_telefono) : '') + '</div>' +
      '<div class="d c"><b>COTIZACION</b><br>' + hoy + '<br>Validez hasta: ' + validez + '</div>' +
      cuerpo +
      '<p class="t">TOTAL $' + fmt(totalGeneral) + '</p>' +
      '<p class="c" style="margin-top:8px;font-size:11px">Precios de las piezas. La instalacion y la garantia<br>se confirman al momento de la reparacion.<br>Presupuesto valido hasta la fecha indicada.</p>' +
      '<p class="c" style="margin-top:6px">¡Gracias por preferirnos!</p>' +
      '</body></html>'
    );
    window.imprimirDocumento(html);
  }

  /** Deja la cotización en cero. */
  function limpiarSeleccion() {
    seleccion = {};
    render(ultimos.catalogo, ultimos.q);
  }

  /** Guarda TU precio final para un item (0 = volver al precio del proveedor).
   *  Si le das un precio, el item se agrega solo a la cotización (cant. 1). */
  function fijarPrecioVenta(id, valor, c) {
    api('api/proveedores.php?action=catalogo_fijar_precio', { method: 'POST', body: { id: id, precio_venta: valor } })
      .then(function (res) {
        if (!res.ok) { window.mostrarToast(res.error || 'No se pudo fijar el precio', 'error'); return; }
        if (c && valor > 0) {
          var s = seleccion[id] || (seleccion[id] = { c: c, cant: 0, desc: 0 });
          if (s.cant < 1) s.cant = 1; // queda listo para imprimir/cotizar
        }
        window.mostrarToast(valor > 0 ? 'Tu precio final: ' + fmt(valor) + ' — item en la cotización' : 'Vuelto al precio del proveedor', 'success');
        render(ultimos.catalogo, ultimos.q);
      }).catch(function () { window.mostrarToast('Error de conexión con el servidor', 'error'); });
  }

  /* ----------------------------------------------------------- ARRANQUE */
  document.addEventListener('DOMContentLoaded', function () {
    $('cot-buscar').addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(buscar, 250);
    });
    $('cot-buscar').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { clearTimeout(timer); buscar(); }
    });
    $('cot-prov').addEventListener('change', buscar);
    $('btn-cot-limpiar').addEventListener('click', limpiarSeleccion);
    $('btn-cot-imprimir').addEventListener('click', imprimirSeleccion);
    api('api/auth.php?action=me').then(function (res) {
      mostrarVista(!!(res && res.logueado));
    }).catch(function () { mostrarVista(false); });
  });
})();