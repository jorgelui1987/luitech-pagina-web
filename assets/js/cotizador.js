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

  function fmt(n) { return '$ ' + Math.max(0, Math.round(Number(n) || 0)).toLocaleString('es-CL'); }
  function esc(t) {
    return String(t === null || t === undefined ? '').replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function mostrarVista(logueado) {
    $('view-nologin').classList.toggle('hidden', logueado);
    $('view-cot').classList.toggle('hidden', !logueado);
    if (logueado) { cargarConfig(); $('cot-buscar').focus(); }
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

  /** Precio efectivo del item: el fijo del negocio si existe, o el automático. */
  function precioDe(c) {
    var fijo = parseInt(c.precio_venta, 10);
    return (fijo > 0) ? fijo : precioSugerido(c.precio);
  }

  function buscar() {
    var q = $('cot-buscar').value.trim();
    api('api/proveedores.php?action=catalogo_list' + (q ? '&q=' + encodeURIComponent(q) : ''))
      .then(function (res) {
        if (!res.ok) return;
        render(res.catalogo || [], q);
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
        var li = document.createElement('li');
        li.className = 'flex items-center gap-3 px-4 py-2.5 border-b border-slate-800/40 last:border-0';

        var info = document.createElement('div');
        info.className = 'flex-1 min-w-0';
        var m = document.createElement('p');
        m.className = 'text-sm font-bold text-cyan-400 truncate';
        m.textContent = c.modelo;
        var sub = document.createElement('p');
        sub.className = 'text-[10px] text-slate-500';
        sub.textContent = (c.proveedor_nombre || '') + (c.disponible ? '' : ' · NO DISPONIBLE');
        info.appendChild(m);
        info.appendChild(sub);

        var p = document.createElement('span');
        p.className = 'text-base font-black text-emerald-400 whitespace-nowrap';
        p.textContent = fmt(precioDe(c));

        var b = document.createElement('button');
        b.type = 'button';
        b.title = 'Imprimir cotización (o guardar como PDF)';
        b.innerHTML = '<i class="fa-solid fa-print pointer-events-none"></i>';
        b.className = 'px-3 h-8 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white font-bold text-xs transition-all';
        b.addEventListener('click', function () { cotizar(c); });

        li.appendChild(info);
        li.appendChild(p);
        li.appendChild(b);
        lista.appendChild(li);
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

  /* ----------------------------------------------------------- ARRANQUE */
  document.addEventListener('DOMContentLoaded', function () {
    $('cot-buscar').addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(buscar, 250);
    });
    $('cot-buscar').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { clearTimeout(timer); buscar(); }
    });
    api('api/auth.php?action=me').then(function (res) {
      mostrarVista(!!(res && res.logueado));
    }).catch(function () { mostrarVista(false); });
  });
})();