/* ==========================================================================
   LUITECH — proveedores.js · Registro de proveedores y compras de mercadería
   ========================================================================== */
(function () {
  'use strict';

  var api = window.LuitechAPI;
  var $ = function (id) { return document.getElementById(id); };

  function fmt(n) { return '$ ' + Math.max(0, Math.round(Number(n) || 0)).toLocaleString('es-CL'); }

  /** Limpia el teléfono a formato wa.me (solo dígitos, con país 56). */
  function telefonoWhatsapp(tel) {
    var d = String(tel || '').replace(/[^0-9]/g, '');
    if (!d) return '';
    if (d.indexOf('56') === 0) return d;
    return '56' + d;
  }

  var empresaCfg = null; // datos de la empresa para la cotización

  /** Escapa texto para incrustarlo en el HTML de la cotización. */
  function esc(t) {
    return String(t === null || t === undefined ? '' : t)
      .replace(/[&<>\"]/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '\"': '&quot;' }[c];
      });
  }

  // Diagnóstico visible de la carga de datos del formulario de compra
  var estadoPartes = { prov: '', prod: '' };
  function refrescarEstadoCompras() {
    var p = $('compras-estado');
    if (!p) return;
    var partes = [];
    if (estadoPartes.prov) partes.push(estadoPartes.prov);
    if (estadoPartes.prod) partes.push(estadoPartes.prod);
    p.textContent = partes.join('   ·   ');
    p.classList.toggle('hidden', partes.length === 0);
  }

  // Diagnóstico visible de la carga de datos del formulario de compra
  var estadoPartes = { prov: '', prod: '' };
  function refrescarEstadoCompras() {
    var p = $('compras-estado');
    if (!p) return;
    var partes = [];
    if (estadoPartes.prov) partes.push(estadoPartes.prov);
    if (estadoPartes.prod) partes.push(estadoPartes.prod);
    p.textContent = partes.join('   ·   ');
    p.classList.toggle('hidden', partes.length === 0);
  }

  /* --------------------------------------------------------- CATÁLOGO */
  var catMargen = 40;
  var catRedondeo = 0; // 0 = sin redondeo; 100/500/1000 = al múltiplo más cercano
  var catEditando = 0;

  function limpiarFormCat() {
    catEditando = 0;
    $('cat-id').value = '';
    $('cat-modelo').value = ''; $('cat-pieza').value = '';
    $('cat-precio').value = ''; $('cat-disp').checked = true;
    $('btn-cat').innerHTML = '<i class="fa-solid fa-plus mr-1"></i>Agregar al catálogo';
    $('btn-cat-cancelar').classList.add('hidden');
    $('btn-cat').disabled = false;
  }

  function editarCatalogoItem(c) {
    window.scrollTo({ top: 0, behavior: 'smooth' });
    catEditando = c.id;
    $('cat-id').value = c.id;
    $('cat-prov').value = String(c.proveedor_id || '');
    $('cat-modelo').value = c.modelo; $('cat-pieza').value = c.pieza;
    $('cat-precio').value = c.precio; $('cat-disp').checked = c.disponible === 1 || c.disponible === '1';
    $('btn-cat').innerHTML = '<i class="fa-solid fa-floppy-disk mr-1"></i>Guardar cambios';
    $('btn-cat-cancelar').classList.remove('hidden');
  }

  function eliminarCatalogo(c) {
    if (!confirm('¿Eliminar "' + c.pieza + ' ' + c.modelo + '" del catálogo de ' + c.proveedor_nombre + '?')) return;
    api('api/proveedores.php?action=catalogo_delete', { method: 'POST', body: { id: c.id } })
      .then(function (res) {
        if (!res.ok) { window.mostrarToast(res.error || 'No se pudo eliminar', 'error'); return; }
        window.mostrarToast('Item eliminado del catálogo', 'success');
        if (catEditando === c.id) limpiarFormCat();
        cargarCatalogo();
      }).catch(function () {});
  }

  function mostrarVista(logueado) {
    $('view-nologin').classList.toggle('hidden', logueado);
    $('view-prov').classList.toggle('hidden', !logueado);
    if (logueado) { cargarProveedores(); cargarProductos(); cargarCompras(); cargarMargen(); }
  }

  /* -------------------------------------------------- PROVEEDORES (CRUD) */
  var listaProveedores = []; // cache para la sección de importación (URL guardada)

  function cargarProveedores() {
    api('api/proveedores.php?action=list').then(function (res) {
      if (!res.ok) return;
      listaProveedores = res.proveedores || [];
      var tbody = $('prov-body');
      tbody.replaceChildren();

      // Selects dependientes: formulario de compra y filtro del historial
      var selCompra = $('c-prov'), selFiltro = $('fil-prov'), selCat = $('cat-prov'), selImp = $('imp-prov');
      var compraActual = selCompra.value, filtroActual = selFiltro.value, catActual = selCat.value, impActual = selImp.value;
      selCompra.replaceChildren(new Option('Proveedor*', ''));
      selFiltro.replaceChildren(new Option('Todos los proveedores', ''));
      selCat.replaceChildren(new Option('— Proveedor —', ''));
      selImp.replaceChildren(new Option('— Proveedor —', ''));

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
        selCat.add(new Option(p.nombre, String(p.id)));
        selImp.add(new Option(p.nombre, String(p.id)));

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

        var waNum = telefonoWhatsapp(p.telefono);
        if (waNum) {
          var btnWa = document.createElement('button');
          btnWa.type = 'button'; btnWa.title = 'Consultar por WhatsApp';
          btnWa.innerHTML = '<i class="fa-brands fa-whatsapp pointer-events-none"></i>';
          btnWa.className = 'w-8 h-8 rounded-lg bg-emerald-950 hover:bg-emerald-800 text-emerald-400 border border-emerald-900 mx-0.5 transition-all';
          btnWa.addEventListener('click', function () {
            window.open('https://wa.me/' + waNum + '?text=' + encodeURIComponent('¡Hola ' + p.nombre + '! ¿Me cotizas disponibilidad y precio?'), '_blank');
          });
          tdAcc.appendChild(btnWa);
        }

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
      selCat.value = catActual;
      selImp.value = impActual;
      estadoPartes.prov = '✓ ' + res.proveedores.length + ' proveedor(es)';
      refrescarEstadoCompras();
    }).catch(function (err) {
      estadoPartes.prov = '✗ Proveedores: ' + (err && err.message ? err.message : 'sin conexión');
      refrescarEstadoCompras();
    });
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
    $('pv-nota').value = p.notas || '';
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
      estadoPartes.prod = '✓ ' + res.productos.length + ' producto(s)';
      refrescarEstadoCompras();
    }).catch(function (err) {
      estadoPartes.prod = '✗ Productos: ' + (err && err.message ? err.message : 'sin conexión');
      refrescarEstadoCompras();
    });
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

  /* ------------------------------------------------- CATÁLOGO (carga/operación) */
  function cargarMargen() {
    api('api/configuracion.php?action=get_all').then(function (res) {
      if (!res.ok) return;
      empresaCfg = res.config || {};
      catMargen = parseInt(empresaCfg.catalogo_margen, 10);
      if (isNaN(catMargen) || catMargen < 0) catMargen = 40;
      $('cat-margen').value = catMargen;
      catRedondeo = parseInt(empresaCfg.catalogo_redondeo, 10);
      if (isNaN(catRedondeo) || catRedondeo < 0) catRedondeo = 0;
      $('cat-redondeo').value = String(catRedondeo);
      cargarCatalogo();
    }).catch(function () { cargarCatalogo(); });
  }

  function guardarMargen() {
    var m = parseInt($('cat-margen').value, 10) || 0;
    var r = parseInt($('cat-redondeo').value, 10) || 0;
    api('api/configuracion.php?action=set_many', { method: 'POST', body: { catalogo_margen: m, catalogo_redondeo: r } })
      .then(function (res) {
        if (!res.ok) { window.mostrarToast(res.error || 'No se pudo guardar', 'error'); return; }
        catMargen = m; catRedondeo = r;
        window.mostrarToast('Cotización guardada: margen ' + m + '%' + (catRedondeo ? ', redondeo $' + catRedondeo : ''), 'success');
        cargarCatalogo();
      }).catch(function () { window.mostrarToast('Error de conexión con el servidor', 'error'); });
  }

  /** Precio de venta automático: costo + margen %, con redondeo opcional. */
  function precioSugerido(costo) {
    var p = Math.round(costo * (1 + catMargen / 100));
    if (catRedondeo > 0) p = Math.round(p / catRedondeo) * catRedondeo;
    return Math.max(0, p);
  }

  function guardarCatalogo() {
    var proveedorId = parseInt($('cat-prov').value, 10) || 0;
    var modelo = $('cat-modelo').value.trim();
    var pieza = $('cat-pieza').value.trim();
    var precio = parseInt($('cat-precio').value, 10) || 0;
    if (!proveedorId || !modelo || !pieza || precio < 1) {
      window.mostrarToast('Proveedor, modelo, pieza y precio son obligatorios', 'error');
      return;
    }
    var cuerpo = {
      proveedor_id: proveedorId, modelo: modelo, pieza: pieza,
      precio: precio, disponible: $('cat-disp').checked
    };
    if (catEditando) cuerpo.id = catEditando;

    var boton = $('btn-cat');
    boton.disabled = true;
    api('api/proveedores.php?action=catalogo_save', { method: 'POST', body: cuerpo })
      .then(function (res) {
        boton.disabled = false;
        if (!res.ok) { window.mostrarToast(res.error || 'No se pudo guardar', 'error'); return; }
        window.mostrarToast(catEditando ? 'Item del catálogo actualizado' : 'Item agregado al catálogo', 'success');
        limpiarFormCat();
        cargarCatalogo();
      }).catch(function () {
        boton.disabled = false;
        window.mostrarToast('Error de conexión con el servidor', 'error');
      });
  }

  /** Abre la cotización imprimible (80mm / PDF desde el diálogo de impresión). */
  function cotizarItem(c) {
    var sugerido = (parseInt(c.precio_venta, 10) > 0) ? parseInt(c.precio_venta, 10) : precioSugerido(c.precio);
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

  function cargarCatalogo() {
    var q = $('cat-buscar').value.trim();
    api('api/proveedores.php?action=catalogo_list' + (q ? '&q=' + encodeURIComponent(q) : ''))
      .then(function (res) {
        if (!res.ok) return;
        var tbody = $('cat-body');
        tbody.replaceChildren();
        $('cat-estado').textContent = res.catalogo.length > 0 ? res.catalogo.length + ' items' : '';

        if (!res.catalogo.length) {
          var trv = document.createElement('tr');
          var tdv = document.createElement('td');
          tdv.colSpan = 8;
          tdv.className = 'p-4 text-center italic text-slate-500';
          tdv.textContent = q ? 'Sin resultados para "' + q + '".' : 'El catálogo está vacío: agrega los precios de tus proveedores.';
          trv.appendChild(tdv);
          tbody.appendChild(trv);
          return;
        }

        res.catalogo.forEach(function (c) {
          var tr = document.createElement('tr');
          tr.className = 'border-b border-slate-800/40 hover:bg-slate-800/30';

          function td(texto, clase) {
            var c2 = document.createElement('td');
            c2.className = clase || 'p-2 text-slate-300';
            c2.textContent = texto;
            return c2;
          }

          tr.appendChild(td(c.pieza, 'p-2 font-bold text-white'));
          tr.appendChild(td(c.modelo, 'p-2 text-cyan-400 font-semibold'));
          tr.appendChild(td(c.proveedor_nombre, 'p-2 text-slate-400'));
          tr.appendChild(td(fmt(c.precio), 'p-2 text-right text-slate-400'));

          // Precio de venta: fijo del negocio (con marca FIJO) o automático
          var tdPrecio = document.createElement('td');
          tdPrecio.className = 'p-2 text-right whitespace-nowrap';
          var precioFijo = parseInt(c.precio_venta, 10) > 0;
          var spanP = document.createElement('span');
          spanP.className = 'text-emerald-400 font-bold';
          spanP.textContent = fmt(precioFijo ? parseInt(c.precio_venta, 10) : precioSugerido(c.precio));
          tdPrecio.appendChild(spanP);
          if (precioFijo) {
            var tag = document.createElement('span');
            tag.className = 'ml-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-500 text-slate-950 align-middle';
            tag.textContent = 'FIJO';
            tdPrecio.appendChild(tag);
          }
          var btnP = document.createElement('button');
          btnP.type = 'button';
          btnP.title = precioFijo ? 'Cambiar tu precio fijo' : 'Fijar tu propio precio';
          btnP.innerHTML = '<i class="fa-solid fa-pen pointer-events-none"></i>';
          btnP.className = 'ml-1 w-6 h-6 rounded bg-slate-800 hover:bg-cyan-600 text-slate-300 text-[10px] transition-all align-middle';
          btnP.addEventListener('click', function () { editarPrecioVenta(c, tdPrecio); });
          tdPrecio.appendChild(btnP);
          if (precioFijo) {
            var btnAuto = document.createElement('button');
            btnAuto.type = 'button';
            btnAuto.title = 'Volver al precio automático';
            btnAuto.innerHTML = '<i class="fa-solid fa-rotate-left pointer-events-none"></i>';
            btnAuto.className = 'ml-1 w-6 h-6 rounded bg-slate-800 hover:bg-red-900 text-slate-300 text-[10px] transition-all align-middle';
            btnAuto.addEventListener('click', function () { fijarPrecioVenta(c.id, 0); });
            tdPrecio.appendChild(btnAuto);
          }
          tr.appendChild(tdPrecio);

          var tdDisp = document.createElement('td');
          tdDisp.className = 'p-2 text-center';
          var chip = document.createElement('span');
          chip.className = 'px-2 py-0.5 rounded-full text-[10px] font-bold border ' +
            (c.disponible ? 'bg-emerald-950 text-emerald-400 border-emerald-800' : 'bg-red-950 text-red-400 border-red-900');
          chip.textContent = c.disponible ? 'Disponible' : 'No disp.';
          tdDisp.appendChild(chip);
          tr.appendChild(tdDisp);

          tr.appendChild(td(c.actualizado_en ? c.actualizado_en.slice(0, 10) : '—', 'p-2 text-center text-slate-500'));

          var tdAcc = document.createElement('td');
          tdAcc.className = 'p-2 text-center whitespace-nowrap';
          var btnCot = document.createElement('button');
          btnCot.type = 'button'; btnCot.title = 'Imprimir cotización (o guardar como PDF)';
          btnCot.innerHTML = '<i class="fa-solid fa-print"></i> Cotizar';
          btnCot.className = 'px-2 h-7 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white font-bold mr-1 transition-all';
          btnCot.addEventListener('click', function () { cotizarItem(c); });
          var btnEd = document.createElement('button');
          btnEd.type = 'button'; btnEd.title = 'Editar';
          btnEd.innerHTML = '<i class="fa-solid fa-pen pointer-events-none"></i>';
          btnEd.className = 'w-7 h-7 rounded-lg bg-slate-800 hover:bg-cyan-600 text-slate-300 hover:text-white mx-0.5 transition-all';
          btnEd.addEventListener('click', function () { editarCatalogoItem(c); });
          var btnEl = document.createElement('button');
          btnEl.type = 'button'; btnEl.title = 'Eliminar';
          btnEl.innerHTML = '<i class="fa-solid fa-trash-can pointer-events-none"></i>';
          btnEl.className = 'w-7 h-7 rounded-lg bg-red-950/40 hover:bg-red-900/60 text-red-400 border border-red-900/60 mx-0.5 transition-all';
          btnEl.addEventListener('click', function () { eliminarCatalogo(c); });
          tdAcc.appendChild(btnCot); tdAcc.appendChild(btnEd); tdAcc.appendChild(btnEl);
          tr.appendChild(tdAcc);

          tbody.appendChild(tr);
        });
      }).catch(function () {});
  }

  /* ------------------------------------------------- IMPORTAR LISTADO */
  var impItems = [];

  /** Lee un archivo .txt o .pdf y carga su texto en el importador.
   *  Los PDF se extraen con pdf.js agrupando el texto por línea visual. */
  function leerArchivoListado(e) {
    var archivo = e.target.files[0];
    if (!archivo) return;
    if (/\.txt$/i.test(archivo.name)) {
      var lectorTxt = new FileReader();
      lectorTxt.onload = function () {
        $('imp-texto').value = String(lectorTxt.result || '');
        analizarListado();
      };
      lectorTxt.readAsText(archivo, 'utf-8');
      return;
    }
    if (!/\.pdf$/i.test(archivo.name)) {
      window.mostrarToast('Formato no soportado: usa PDF o TXT', 'error');
      return;
    }
    if (!window.pdfjsLib) {
      window.mostrarToast('El lector de PDF no cargó: revisa tu conexión e inténtalo de nuevo', 'error');
      return;
    }
    window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    var lector = new FileReader();
    lector.onload = function () {
      window.pdfjsLib.getDocument({ data: lector.result }).promise.then(function (pdf) {
        var paginas = [];
        for (var i = 1; i <= pdf.numPages; i++) {
          paginas.push(pdf.getPage(i).then(function (pg) {
            return pg.getTextContent().then(function (tc) {
              var texto = ''; var ultimaY = null;
              tc.items.forEach(function (it) {
                var y = it.transform ? it.transform[5] : 0;
                if (ultimaY !== null && Math.abs(y - ultimaY) > 2) texto += '\n';
                texto += it.str + ' ';
                ultimaY = y;
              });
              return texto;
            });
          }));
        }
        Promise.all(paginas).then(function (textos) {
          $('imp-texto').value = textos.join('\n');
          analizarListado();
        }).catch(function () {
          window.mostrarToast('No se pudo leer el PDF', 'error');
        });
      }).catch(function () {
        window.mostrarToast('No se pudo abrir el PDF (¿está protegido con contraseña?)', 'error');
      });
    };
    lector.readAsArrayBuffer(archivo);
  }

  function parsearListado(texto) {
    var marcas = ['SAMSUNG','IPHONE','APPLE','HUAWEI','HONOR','XIAOMI','REDMI','POCO','MOTO','VIVO','OPPO','NOKIA','LG','ZTE','NUBIA','TECNO','TCL','REALME','INFINIX','SONY','NINTENDO','MACBOOK','IPAD','LENOVO'];
    var piezasMap = {
      'PANTALLA': 'Pantalla', 'PANTALLAS': 'Pantalla', 'BATERIAS': 'Batería', 'BATERIA': 'Batería',
      'FLEX DE CARGA': 'Flex de carga', 'MAIN FLEX': 'Flex principal', 'FLEX LCD': 'Flex LCD',
      'FLEX MICROFONO': 'Flex micrófono', 'FLEX VOLUMEN': 'Flex volumen', 'FLEX ENCENDIDO': 'Flex encendido',
      'FLEX HUELLA': 'Flex huella', 'FLEX DE BATERIA': 'Flex de batería', 'HUELLA Y ENCENDIDO': 'Huella y encendido',
      'CAMARA TRASERA': 'Cámara trasera', 'CAMARA DELANTERA': 'Cámara delantera', 'VIDRIO CAMARA': 'Vidrio de cámara',
      'TAPAS': 'Tapa', 'CHASIS': 'Chasis', 'BANDEJA SIM': 'Bandeja SIM', 'HUELLA': 'Huella',
      'ENCENDIDO': 'Encendido', 'AURICULAR': 'Auricular', 'DISCO ALTAVOZ': 'Disco altavoz',
      'ANTENAS': 'Antena', 'CONECTOR FPC': 'Conector FPC', 'MOTOR CAMARA' : 'Motor de cámara',
      'HOME Y HUELLA': 'Home y huella', 'PUERTO HDMI': 'Puerto HDMI', 'PIN DE CARGA': 'Pin de carga',
      'PIN': 'Pin de carga', 'TACTIL': 'Táctil', 'SELLO': 'Sello antipolvo', 'GLASS': 'Glass',
      'CONSOLAS': 'Consola', 'CONSOLA': 'Consola'
    };
    var keysPiezas = Object.keys(piezasMap).sort(function (a, b) { return b.length - a.length; });
    var esCalidad = /^(ORIGINAL|ORIG|CM|SM|OLED|INCELL|GLASS|BIG|SMALL|CON|SIN|MARCO|FLEX|AMARILLO|NEGRO|BLANCO|ROJO|AZUL|VERDE|PLUS|PRO|5G|4G|LITE|GAMA|ALTA|TABLET|DIAGNOSTICO)$/;

    var items = []; var saltadas = 0;
    var marca = ''; var piezaCtx = 'Repuesto'; var extra = '';

    texto.split(/\r?\n/).forEach(function (linea) {
      var l = linea.trim();
      if (l === '') return;

      // Encabezado de sección (sin $): define marca y pieza de lo que sigue
      if (l.indexOf('$') === -1) {
        if (l.length <= 55 && /^[A-ZÁÉÍÓÚÑ0-9 ]+$/.test(l)) {
          var cambio = false;
          marcas.forEach(function (b) {
            if (l.indexOf(b) !== -1) {
              marca = (b === 'MOTO') ? 'Motorola' : ((b === 'LG' || b === 'IPAD') ? b : b.charAt(0) + b.slice(1).toLowerCase());
              l = l.replace(b, ' ');
              cambio = true;
            }
          });
          keysPiezas.forEach(function (k) {
            if (l.indexOf(k) !== -1) { piezaCtx = piezasMap[k]; l = l.replace(k, ' '); cambio = true; }
          });
          if (cambio) {
            extra = l.split(/\s+/).filter(function (w) { return w !== '' && !esCalidad.test(w); }).join(' ');
          }
        }
        return;
      }

      // Línea con precios: escanea los pares "descripción $ precio" (soporta
      // items separados por ")" y líneas de 2 columnas con varios precios)
      var re = /([^\$]*?)\$\s*([\d][\d.,]*)\s*\)?\s*/g;
      var m; var agregados = 0;
      while ((m = re.exec(l)) !== null) {
        var desc = m[1].replace(/^[\s\-–•*·>#()\[\]]+/, '').replace(/[\s\-–•*·:()\[\],.]+$/, '').trim();
        var precio = parseInt(m[2].replace(/[.,]/g, ''), 10);
        if (!precio || precio < 100) continue;
        if (desc.length < 2) continue;
        var pieza = piezaCtx;
        keysPiezas.forEach(function (k) {
          if (pieza === piezaCtx && desc.toUpperCase().indexOf(k) !== -1) pieza = piezasMap[k];
        });
        var modelo = (marca + ' ' + extra + ' ' + desc).replace(/\s+/g, ' ').trim();
        if (modelo.length < 2) continue;
        items.push({ modelo: modelo, pieza: pieza, precio: precio });
        agregados++;
      }
      if (agregados === 0) saltadas++;
    });
    return { items: items, saltadas: saltadas };
  }

  function analizarListado() {
    var provId = parseInt($('imp-prov').value, 10) || 0;
    if (!provId) { window.mostrarToast('Elige el proveedor primero', 'error'); return; }
    var r = parsearListado($('imp-texto').value);
    impItems = r.items;
    $('imp-preview').classList.remove('hidden');
    $('imp-buscar').value = '';
    pintarPreview(r.saltadas);
  }

  /** Tabla del preview con filtro de búsqueda (para revisar sin scroll). */
  function pintarPreview(saltadas) {
    var filtro = ($('imp-buscar').value || '').trim().toLowerCase();
    var tb = $('imp-body');
    tb.replaceChildren();

    if (!impItems.length) {
      $('imp-resumen').textContent = '✗ No se pudo leer ningún item con precio' +
        (saltadas ? ' (' + saltadas + ' líneas saltadas)' : '') + '. Revisa el formato: una línea por item, precio al final.';
      $('btn-imp-confirmar').classList.add('hidden');
      return;
    }

    var visibles = impItems;
    if (filtro) {
      var palabras = filtro.split(/\s+/);
      var sinonimos = { redmi: ['xiaomi', 'poco'], xiaomi: ['redmi', 'poco'], poco: ['xiaomi', 'redmi'] };
      visibles = impItems.filter(function (it) {
        var t = (it.modelo + ' ' + it.pieza).toLowerCase();
        return palabras.every(function (w) {
          if (t.indexOf(w) !== -1) return true;
          var alts = sinonimos[w] || [];
          for (var i = 0; i < alts.length; i++) { if (t.indexOf(alts[i]) !== -1) return true; }
          return false;
        });
      });
    }

    var cats = {};
    impItems.forEach(function (it) { cats[it.pieza] = (cats[it.pieza] || 0) + 1; });
    var resumen = '✓ ' + impItems.length + ' items en ' + Object.keys(cats).length + ' categorías' +
      (saltadas ? ' · ' + saltadas + ' líneas saltadas (sin precio)' : '');
    if (filtro) {
      resumen += ' · ' + visibles.length + ' coinciden con "' + filtro + '"' + (visibles.length > 100 ? ' (mostrando 100)' : '');
    } else if (impItems.length > 40) {
      resumen += ' · mostrando 40 — usa el filtro para buscar uno';
    }
    $('imp-resumen').textContent = resumen;

    visibles.slice(0, 100).forEach(function (it) {
      var tr = document.createElement('tr');
      tr.className = 'border-b border-slate-800/40';
      [it.pieza, it.modelo, fmt(it.precio)].forEach(function (t, i) {
        var c2 = document.createElement('td');
        c2.className = 'p-1.5 ' + (i === 2 ? 'text-right text-amber-400 font-bold' : 'text-slate-300');
        c2.textContent = t;
        tr.appendChild(c2);
      });
      tb.appendChild(tr);
    });
    $('btn-imp-confirmar').classList.remove('hidden');
  }

  function confirmarImportacion() {
    var provId = parseInt($('imp-prov').value, 10) || 0;
    if (!provId || !impItems.length) return;
    var boton = $('btn-imp-confirmar');
    boton.disabled = true;
    api('api/proveedores.php?action=catalogo_importar', {
      method: 'POST',
      body: { proveedor_id: provId, items: impItems, marcar_no_disp: $('imp-nodisp').checked }
    }).then(function (res) {
      boton.disabled = false;
      if (!res.ok) { window.mostrarToast(res.error || 'No se pudo importar', 'error'); return; }
      window.mostrarToast('Importación: ' + res.insertados + ' nuevos, ' + res.actualizados + ' actualizados', 'success');
      $('imp-preview').classList.add('hidden');
      $('imp-texto').value = '';
      cargarCatalogo();
    }).catch(function () {
      boton.disabled = false;
      window.mostrarToast('Error de conexión con el servidor', 'error');
    });
  }

  /* ------------------------------------------ SINCRONIZACIÓN GOOGLE SHEETS */
  function proveedorSeleccionadoImport() {
    var id = parseInt($('imp-prov').value, 10) || 0;
    for (var i = 0; i < listaProveedores.length; i++) {
      if (parseInt(listaProveedores[i].id, 10) === id) return listaProveedores[i];
    }
    return null;
  }

  function urlAlCambiarProveedor() {
    var p = proveedorSeleccionadoImport();
    $('imp-url').value = (p && p.url_listado) ? p.url_listado : '';
  }

  function guardarUrlListado() {
    var p = proveedorSeleccionadoImport();
    if (!p) { window.mostrarToast('Elige el proveedor primero', 'error'); return; }
    var url = $('imp-url').value.trim();
    api('api/proveedores.php?action=update', { method: 'POST', body: { id: p.id, url_listado: url } })
      .then(function (res) {
        if (!res.ok) { window.mostrarToast(res.error || 'No se pudo guardar la URL', 'error'); return; }
        p.url_listado = url || null;
        window.mostrarToast('URL de planilla guardada para ' + p.nombre, 'success');
      }).catch(function () { window.mostrarToast('Error de conexión con el servidor', 'error'); });
  }

  function sincronizarPlanilla() {
    var p = proveedorSeleccionadoImport();
    if (!p) { window.mostrarToast('Elige el proveedor primero', 'error'); return; }
    var urlNueva = $('imp-url').value.trim();
    if (urlNueva) p.url_listado = urlNueva; // el servidor la guarda junto con la sincronización
    var boton = $('btn-imp-sync');
    boton.disabled = true;
    boton.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i>Sincronizando…';
    api('api/proveedores.php?action=catalogo_sincronizar', {
      method: 'POST',
      body: { proveedor_id: p.id, url: urlNueva, marcar_no_disp: $('imp-nodisp').checked }
    }).then(function (res) {
      boton.disabled = false;
      boton.innerHTML = '<i class="fa-solid fa-rotate mr-1"></i>Sincronizar ahora';
      if (!res.ok) { window.mostrarToast(res.error || 'No se pudo sincronizar', 'error'); return; }
      window.mostrarToast('Planilla sincronizada: ' + res.insertados + ' nuevos, ' + res.actualizados + ' actualizados (' + res.total + ' items)', 'success');
      cargarCatalogo();
    }).catch(function () {
      boton.disabled = false;
      boton.innerHTML = '<i class="fa-solid fa-rotate mr-1"></i>Sincronizar ahora';
      window.mostrarToast('Error de conexión con el servidor', 'error');
    });
  }

  /* ------------------------------------------------- PRECIO DE VENTA FIJO */
  /** Guarda el precio de venta propio (0 = volver al automático). */
  function fijarPrecioVenta(id, valor) {
    api('api/proveedores.php?action=catalogo_fijar_precio', { method: 'POST', body: { id: id, precio_venta: valor } })
      .then(function (res) {
        if (!res.ok) { window.mostrarToast(res.error || 'No se pudo fijar el precio', 'error'); return; }
        window.mostrarToast(valor > 0 ? 'Precio propio fijado: ' + fmt(valor) : 'Vuelto al precio automático', 'success');
        cargarCatalogo();
      }).catch(function () { window.mostrarToast('Error de conexión con el servidor', 'error'); });
  }

  /** Edición en línea: convierte la celda del precio en un input pequeño. */
  function editarPrecioVenta(c, celda) {
    if (celda.querySelector('input')) return;
    var fijo = parseInt(c.precio_venta, 10) > 0;
    var valorActual = fijo ? parseInt(c.precio_venta, 10) : precioSugerido(c.precio);
    celda.replaceChildren();

    var input = document.createElement('input');
    input.type = 'number';
    input.min = '0';
    input.value = String(valorActual);
    input.className = 'w-20 bg-slate-900 border border-slate-700 rounded px-1 py-0.5 text-right text-xs text-white focus:outline-none focus:border-cyan-500';

    var btnOk = document.createElement('button');
    btnOk.type = 'button'; btnOk.title = 'Guardar precio';
    btnOk.innerHTML = '<i class="fa-solid fa-check pointer-events-none"></i>';
    btnOk.className = 'ml-1 w-6 h-6 rounded bg-emerald-600 hover:bg-emerald-500 text-white text-[10px] transition-all align-middle';

    var btnNo = document.createElement('button');
    btnNo.type = 'button'; btnNo.title = 'Cancelar';
    btnNo.innerHTML = '<i class="fa-solid fa-xmark pointer-events-none"></i>';
    btnNo.className = 'ml-1 w-6 h-6 rounded bg-slate-700 hover:bg-slate-600 text-slate-200 text-[10px] transition-all align-middle';

    function guardar() {
      var v = parseInt(input.value, 10) || 0;
      fijarPrecioVenta(c.id, v);
    }
    btnOk.addEventListener('click', guardar);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') guardar(); });
    btnNo.addEventListener('click', function () { cargarCatalogo(); });

    celda.appendChild(input);
    celda.appendChild(btnOk);
    celda.appendChild(btnNo);
    input.focus();
  }

  /** Borra TODO el catálogo del proveedor elegido (para reimportar limpio). */
  function vaciarCatalogo() {
    var p = proveedorSeleccionadoImport();
    if (!p) { window.mostrarToast('Elige el proveedor primero', 'error'); return; }
    if (!confirm('¿BORRAR TODOS los items del catálogo de "' + p.nombre + '"?\n\nSirve para empezar limpio y volver a sincronizar con el formato correcto. NO se puede deshacer.')) return;
    api('api/proveedores.php?action=catalogo_vaciar', { method: 'POST', body: { proveedor_id: p.id } })
      .then(function (res) {
        if (!res.ok) { window.mostrarToast(res.error || 'No se pudo vaciar', 'error'); return; }
        window.mostrarToast('Catálogo de ' + p.nombre + ' vaciado (' + (res.borrados || 0) + ' items). Ahora sincroniza o importa de nuevo.', 'success');
        cargarCatalogo();
      }).catch(function () { window.mostrarToast('Error de conexión con el servidor', 'error'); });
  }

  /* ----------------------------------------------------------- ARRANQUE */
  document.addEventListener('DOMContentLoaded', function () {
    $('btn-prov-guardar').addEventListener('click', guardarProveedor);
    $('btn-prov-cancelar').addEventListener('click', limpiarFormProv);
    $('btn-compra').addEventListener('click', registrarCompra);
    $('fil-prov').addEventListener('change', cargarCompras);
    $('btn-cat').addEventListener('click', guardarCatalogo);
    $('btn-cat-cancelar').addEventListener('click', limpiarFormCat);
    $('btn-cat-margen').addEventListener('click', guardarMargen);
    $('btn-imp-prev').addEventListener('click', analizarListado);
    $('btn-imp-confirmar').addEventListener('click', confirmarImportacion);
    $('imp-archivo').addEventListener('change', leerArchivoListado);
    $('imp-prov').addEventListener('change', urlAlCambiarProveedor);
    $('imp-buscar').addEventListener('input', pintarPreview);
    $('btn-imp-url').addEventListener('click', guardarUrlListado);
    $('btn-imp-sync').addEventListener('click', sincronizarPlanilla);
    $('btn-imp-vaciar').addEventListener('click', vaciarCatalogo);
    var busquedaTimer = null;
    $('cat-buscar').addEventListener('input', function () {
      clearTimeout(busquedaTimer);
      busquedaTimer = setTimeout(cargarCatalogo, 300);
    });
    if (window.innerWidth >= 768) $('cat-buscar').focus(); // en el teléfono el teclado molesta al abrir

    api('api/auth.php?action=me').then(function (res) {
      mostrarVista(!!(res && res.logueado));
    }).catch(function () { mostrarVista(false); });
  });
})();