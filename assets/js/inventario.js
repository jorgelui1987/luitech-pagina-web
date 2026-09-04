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
    ['p-id','p-codigo','p-barcode','p-nombre','p-cat','p-prov','p-costo','p-venta','p-stock'].forEach(function (i) { $(i).value = ''; });
    $('p-min').value = '3';
    $('p-ctrl').checked = true;
    $('btn-cancelar').classList.add('hidden');
    $('btn-guardar').disabled = false;
  }

  function cargar() {
    api('api/inventario.php?action=list').then(function (res) {
      if (!res.ok) return;

      // Llena el selector de proveedores del formulario (desde el registro de proveedores)
      api('api/proveedores.php?action=list').then(function (rp) {
        if (!rp.ok) return;
        var sel = $('p-prov');
        var actual = sel.value;
        sel.replaceChildren(new Option('— Proveedor —', ''));
        rp.proveedores.forEach(function (pv) {
          sel.add(new Option(pv.nombre, String(pv.id)));
        });
        sel.value = actual;
      }).catch(function () {});

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
      // Resumen de inversión: costo × stock de lo que hay en estante (solo
      // productos físicos; los servicios sin stock no inmovilizan dinero).
      var inversion = 0, ventaPot = 0, unidades = 0, sinCosto = 0;
      res.productos.forEach(function (p) {
        var stock = parseInt(p.stock, 10) || 0;
        if (stock <= 0 || !p.controlar_stock) return;
        unidades += stock;
        var costo = parseInt(p.precio_costo, 10) || 0;
        var venta = parseInt(p.precio_venta, 10) || 0;
        if (costo > 0) { inversion += costo * stock; } else { sinCosto++; }
        if (venta > 0) ventaPot += venta * stock;
      });
      var cajaInv = $('resumen-inversion');
      if (cajaInv) {
        if (res.productos.length) cajaInv.classList.remove('hidden'); else cajaInv.classList.add('hidden');
        $('inv-total').textContent = '$' + fmt(inversion);
        $('inv-venta').textContent = '$' + fmt(ventaPot);
        $('inv-ganancia').textContent = '$' + fmt(Math.max(0, ventaPot - inversion));
        $('inv-unidades').textContent = 'Unidades en estante: ' + unidades;
        var avisoInv = $('inv-aviso');
        if (sinCosto > 0) {
          avisoInv.textContent = '⚠ ' + sinCosto + ' producto(s) con stock sin costo definido: no cuentan en la inversión (edítalos y llena el costo)';
          avisoInv.classList.remove('hidden');
        } else {
          avisoInv.classList.add('hidden');
        }
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
      tr.appendChild(td(p.proveedor_nombre || p.proveedor || '—', 'p-3 text-slate-400'));
      tr.appendChild(td('$' + fmt(p.precio_costo), 'p-3 text-right text-slate-400'));
      tr.appendChild(td('$' + fmt(p.precio_venta), 'p-3 text-right text-emerald-400 font-bold'));

      var tdStock = document.createElement('td');
      tdStock.className = 'p-3 text-center';
      var badge = document.createElement('span');
      if (!p.controlar_stock) {
        badge.className = 'px-2 py-0.5 rounded-full text-xs font-bold border bg-slate-800 text-slate-300 border-slate-600';
        badge.textContent = 'Servicio';
        badge.title = 'Servicio: no tiene stock que controlar (descontable ilimitado en el POS)';
      } else {
        badge.className = 'px-2 py-0.5 rounded-full text-xs font-bold border ' +
          (p.stock_bajo ? 'bg-red-950 text-red-400 border-red-900'
                        : 'bg-emerald-950 text-emerald-400 border-emerald-800');
        badge.textContent = String(p.stock);
        badge.title = 'Stock disponible (mínimo: ' + (p.stock_minimo || 0) + ')';
      }
      tdStock.appendChild(badge);
      tr.appendChild(tdStock);

      var tdAcc = document.createElement('td');
      tdAcc.className = 'p-3 text-center';

      var btnEd = document.createElement('button');
      btnEd.type = 'button'; btnEd.title = 'Editar';
      btnEd.innerHTML = '<i class="fa-solid fa-pen pointer-events-none"></i>';
      btnEd.className = 'w-8 h-8 rounded-lg bg-slate-800 hover:bg-cyan-600 text-slate-300 hover:text-white mx-0.5 transition-all';
      btnEd.addEventListener('click', function () { editarProducto(p); });

      var btnEt = document.createElement('button');
      btnEt.type = 'button'; btnEt.title = 'Imprimir etiqueta con código de barras';
      btnEt.innerHTML = '<i class="fa-solid fa-tag pointer-events-none"></i>';
      btnEt.className = 'w-8 h-8 rounded-lg bg-amber-500 hover:bg-amber-400 text-slate-950 mx-0.5 transition-all';
      btnEt.addEventListener('click', function () { imprimirEtiqueta(p); });

      var btnEl = document.createElement('button');
      btnEl.type = 'button'; btnEl.title = 'Eliminar';
      btnEl.innerHTML = '<i class="fa-solid fa-trash-can pointer-events-none"></i>';
      btnEl.className = 'w-8 h-8 rounded-lg bg-red-950/40 hover:bg-red-900/60 text-red-400 border border-red-900/60 mx-0.5 transition-all';
      btnEl.addEventListener('click', function () { eliminar(p.id, p.nombre); });

      tdAcc.appendChild(btnEd); tdAcc.appendChild(btnEt); tdAcc.appendChild(btnEl);
      tr.appendChild(tdAcc);
      tbody.appendChild(tr);
    });
  }

  /* ------------------------------------------------- FORMULARIO / CRUD */
  function editarProducto(p) {
    window.scrollTo({ top: 0, behavior: 'smooth' });
    $('form-titulo').innerHTML = '<i class="fa-solid fa-pen mr-1"></i>Editando: ' + p.nombre;
    $('p-id').value = p.id; $('p-codigo').value = p.codigo; $('p-barcode').value = p.barcode || ''; $('p-nombre').value = p.nombre;
    $('p-cat').value = p.categoria; $('p-prov').value = p.proveedor_id || '';
    $('p-costo').value = p.precio_costo; $('p-venta').value = p.precio_venta;
    $('p-stock').value = p.stock; $('p-min').value = (p.controlar_stock ? p.stock_minimo : 0) || 0;
    $('btn-cancelar').classList.remove('hidden');
  }

  function guardar() {
    var id = $('p-id').value.trim();
    var cuerpo = {
      codigo: $('p-codigo').value.trim().toUpperCase(),
      barcode: $('p-barcode').value.trim().toUpperCase(),
      nombre: $('p-nombre').value.trim(),
      categoria: $('p-cat').value.trim() || 'Repuesto',
      proveedor_id: parseInt($('p-prov').value, 10) || 0,
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

  /* ------------------------------------------------------- ETIQUETAS */
  /** Carga JsBarcode desde el proyecto (vendor): sin depender de internet ni
   *  de la lista blanca CSP de scripts externos. */
  function etiquetaCargarJsBarcode() {
    if (window.JsBarcode) return Promise.resolve();
    return new Promise(function (resolver, rechazar) {
      var s = document.createElement('script');
      s.src = 'assets/js/vendor/JsBarcode.all.min.js';
      s.onload = function () { resolver(); };
      s.onerror = function () { rechazar(new Error('No se pudo cargar el generador de etiquetas')); };
      document.head.appendChild(s);
    });
  }

  /** Etiquetas para papel térmico adhesivo de 80mm: tira continua con todas
   *  las copias separadas por línea de corte punteada (SIN saltos de página,
   *  así no se va papel en blanco; se corta a tijera). Barcode EAN13 si son
   *  13 dígitos, Code 128 en los demás casos. Sirve para los productos SIN
   *  código de fábrica: se pega al producto/estante y el POS la lee. */
  function imprimirEtiqueta(p) {
    var valor = String(p.barcode || p.codigo || '').trim();
    if (!valor) { window.mostrarToast('El producto no tiene código', 'error'); return; }
    var cantidad = parseInt(prompt('¿Cuántas etiquetas imprimir?', '1'), 10);
    if (isNaN(cantidad) || cantidad < 1) return;
    if (cantidad > 100) cantidad = 100;
    etiquetaCargarJsBarcode().then(function () {
      var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      try {
        window.JsBarcode(svg, valor, { format: 'auto', width: 2, height: 46, displayValue: true, fontSize: 12, margin: 2, background: '#ffffff', lineColor: '#000000' });
      } catch (e) {
        window.JsBarcode(svg, valor, { format: 'CODE128', width: 2, height: 46, displayValue: true, fontSize: 12, margin: 2, background: '#ffffff', lineColor: '#000000' });
      }
      var svgTexto = new XMLSerializer().serializeToString(svg);
      var urlImg = 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(svgTexto)));
      var nombre = String(p.nombre).replace(/[<>&]/g, '');
      var copias = '';
      for (var i = 0; i < cantidad; i++) {
        if (i > 0) copias += '<div class="corte"></div>'; // línea de corte entre etiquetas
        copias += '<div class="etq"><p class="n">' + nombre + '</p>' +
          '<img src="' + urlImg + '" alt="">' +
          (parseInt(p.precio_venta, 10) > 0 ? '<p class="p">$' + fmt(p.precio_venta) + '</p>' : '') +
          '</div>';
      }
      // Papel térmico de 80mm: tira continua (página de alto automático, sin
      // saltos de página) con línea de corte punteada entre cada etiqueta.
      var html = '<html><head><title>Etiquetas ' + p.codigo + '</title><style>' +
        '@page{size:80mm auto;margin:0}body{margin:0;font-family:Arial,Helvetica,sans-serif;color:#000;width:76mm}' +
        '.etq{width:76mm;padding:2mm 2mm 1mm;box-sizing:border-box;text-align:center;page-break-inside:avoid}' +
        '.etq .n{margin:0 0 1mm;font-size:11px;font-weight:bold;white-space:nowrap;overflow:hidden}' +
        '.etq img{height:14mm;max-width:70mm;display:block;margin:0 auto}' +
        '.etq .p{margin:1mm 0 0;font-size:16px;font-weight:bold;line-height:1.15}' +
        '.corte{border-top:1px dashed #000;margin:2mm 0}' +
        '</style></head><body>' + copias + '</body></html>';
      window.imprimirDocumento(html);
    }).catch(function (e) {
      window.mostrarToast(e.message || 'No se pudo generar la etiqueta', 'error');
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    $('btn-guardar').addEventListener('click', guardar);
    $('btn-cancelar').addEventListener('click', limpiarFormulario);

    // Escáner de cámara para capturar el código de barras del producto
    // (de fábrica o interno); se cierra solo al leer.
    $('btn-scan-barcode').addEventListener('click', function () {
      window.LuitechScanner.abrir({
        titulo: 'Escanea el código de barras del producto',
        continuo: false,
        onDetect: function (texto) { $('p-barcode').value = texto; }
      });
    });

    api('api/auth.php?action=me').then(function (res) {
      mostrarVista(!!res.logueado);
    }).catch(function () { mostrarVista(false); });
  });
})();

