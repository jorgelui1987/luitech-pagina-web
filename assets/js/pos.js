/* ==========================================================================
   LUITECH — pos.js  ·  Punto de Venta (catálogo + carrito + cobro + boleta)
   ========================================================================== */
(function () {
  'use strict';

  var api = window.LuitechAPI;
  var $ = function (id) { return document.getElementById(id); };

  var productos = [];
  var carrito = [];
  var ivaTasa = 19; // se carga desde la BD (configuraciones.iva_porcentaje)
  var empresaCfg = null; // datos de la empresa para la boleta
  var posTimer = null;      // monitoreo del cobro Point en el POS
  var esperandoPoint = false;

  function fmt(n) { return Number(n).toLocaleString('es-CL'); }

  /** Escapa texto para incrustarlo en el HTML de la boleta. */
  function esc(t) {
    return String(t === null || t === undefined ? '' : t)
      .replace(/[&<>"]/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
      });
  }

  function mostrarVista(logueado) {
    $('view-nologin').classList.toggle('hidden', logueado);
    $('view-pos').classList.toggle('hidden', !logueado);
    if (!logueado) return;
    cargarProductos();
    cargarResumenDia();
    cargarConfig();
  }

  /* --------------------------------------------------------- CATÁLOGO */
  function cargarProductos() {
    api('api/inventario.php?action=list').then(function (res) {
      if (!res.ok) return;
      productos = res.productos.filter(function (p) { return p.precio_venta > 0; });
      pintarCatalogo($('buscar').value);
    }).catch(function () {});
  }

  function pintarCatalogo(filtro) {
    filtro = (filtro || '').toLowerCase();
    var cont = $('catalogo');
    cont.replaceChildren();

    var lista = productos.filter(function (p) {
      return !filtro || p.nombre.toLowerCase().indexOf(filtro) !== -1 ||
             p.codigo.toLowerCase().indexOf(filtro) !== -1 ||
             (p.barcode && p.barcode.toLowerCase().indexOf(filtro) !== -1);
    });

    if (!lista.length) {
      cont.appendChild(Object.assign(document.createElement('p'), {
        textContent: 'Sin resultados.', className: 'text-slate-500 text-sm italic col-span-2 py-4 text-center'
      }));
      return;
    }

    lista.forEach(function (p) {
      var sinStock = p.controlar_stock && p.stock <= 0;
      var card = document.createElement('button');
      card.type = 'button';
      card.className = 'text-left bg-slate-900 hover:bg-slate-800 border border-slate-800 rounded-xl p-3.5 transition-all ' +
                       (sinStock ? 'opacity-40 cursor-not-allowed' : 'hover:border-cyan-600');

      var nombre = document.createElement('p');
      nombre.className = 'text-sm font-bold text-white leading-tight';
      nombre.textContent = p.nombre;

      var meta = document.createElement('p');
      meta.className = 'text-[10px] text-slate-500 font-mono mt-0.5';
      meta.textContent = p.codigo + ' · ' + p.categoria;

      var linea2 = document.createElement('div');
      linea2.className = 'flex justify-between items-center mt-2';
      var precio = document.createElement('span');
      precio.className = 'text-cyan-400 font-black text-base';
      precio.textContent = '$' + fmt(p.precio_venta);
      var stock = document.createElement('span');
      stock.className = 'text-[10px] font-semibold px-2 py-0.5 rounded-full border ' +
        (!p.controlar_stock ? 'bg-slate-800 text-slate-400 border-slate-700'
          : (p.stock_bajo ? 'bg-red-950 text-red-400 border-red-900'
                          : 'bg-slate-800 text-slate-300 border-slate-700'));
      stock.textContent = p.controlar_stock ? ('stock: ' + p.stock + (p.stock_bajo ? ' ⚠' : '')) : 'servicio';
      linea2.appendChild(precio);
      linea2.appendChild(stock);

      card.appendChild(nombre); card.appendChild(meta); card.appendChild(linea2);
      if (!sinStock) card.addEventListener('click', function () { agregarAlCarrito(p); });
      cont.appendChild(card);
    });
  }

  /** Coincidencia EXACTA por código de barras (EAN) o código interno. */
  function buscarPorCodigoExacto(termino) {
    var t = String(termino || '').trim().toLowerCase();
    if (!t) return null;
    for (var i = 0; i < productos.length; i++) {
      var p = productos[i];
      if ((p.barcode && p.barcode.toLowerCase() === t) || p.codigo.toLowerCase() === t) return p;
    }
    return null;
  }

  /* ---------------------------------------------------------- CARRITO */
  function agregarAlCarrito(p) {
    var existente = null;
    for (var i = 0; i < carrito.length; i++) {
      if (carrito[i].producto_id === p.id) { existente = carrito[i]; break; }
    }
    if (existente) {
      if (p.controlar_stock && existente.cantidad + 1 > p.stock) {
        window.mostrarToast('Sin más stock de: ' + p.nombre, 'error'); return;
      }
      existente.cantidad++;
    } else {
      carrito.push({ producto_id: p.id, descripcion: p.nombre, precio_unitario: p.precio_venta, cantidad: 1 });
    }
    pintarCarrito();
  }

  function totalCarrito() {
    var t = 0;
    carrito.forEach(function (it) { t += it.cantidad * it.precio_unitario; });
    return t;
  }

  /** Desglose fiscal: en Chile el precio al público YA incluye el IVA. */
  function desgloseIVA(total) {
    var tasa = Math.max(0, ivaTasa);
    var neto = Math.round(total * 100 / (100 + tasa));
    return { neto: neto, iva: total - neto };
  }

  /** Configuración: tasa de IVA + datos de la empresa para la boleta.
   *  (La tasa se edita en Configuración; aquí solo se muestra el desglose.) */
  function cargarConfig() {
    api('api/configuracion.php?action=get_all').then(function (res) {
      if (!res.ok) return;
      empresaCfg = res.config || {};
      ivaTasa = parseInt(empresaCfg.iva_porcentaje, 10);
      if (isNaN(ivaTasa) || ivaTasa < 0) ivaTasa = 19;
      actualizarBotonPoint();
      pintarCarrito();
    }).catch(function () {});
  }

  /** Sincroniza el botón Point: SIEMPRE visible, activo solo con carrito.
   *  Si Mercado Pago no está configurado, el servidor responde con el motivo
   *  exacto al pulsarlo (nada de botones ocultos que confundan). */
  function actualizarBotonPoint() {
    var b = $('btn-point');
    if (!b || esperandoPoint) return;
    b.disabled = !carrito.length;
    b.innerHTML = '<i class="fa-solid fa-credit-card mr-2"></i>Cobrar con Mercado Pago';
  }

  function estadoPoint(texto, color) {
    var p = $('pos-point-estado');
    if (!p) return;
    p.textContent = texto || '';
    p.style.color = color || '#fbbf24';
    p.classList.toggle('hidden', !texto);
  }

  function detenerEsperaPoint(mensaje, color) {
    if (posTimer) { clearInterval(posTimer); posTimer = null; }
    esperandoPoint = false;
    actualizarBotonPoint();
    $('btn-cobrar').disabled = !carrito.length;
    estadoPoint(mensaje || '', color);
  }

  /** Envía el total del carrito al terminal Point; al aprobarse registra
   *  la venta con medio "Mercado Pago" e imprime la boleta automáticamente. */
  function cobrarConPoint() {
    if (!carrito.length) return;
    var total = totalCarrito();
    esperandoPoint = true;
    $('btn-point').disabled = true;
    $('btn-point').innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i>Cancelar espera';
    $('btn-cobrar').disabled = true;
    estadoPoint('Enviando el cobro ($' + fmt(total) + ') al terminal Point…');
    api('api/pagos_mp.php?action=point_cobrar_venta', { method: 'POST', body: { monto: total } })
      .then(function (res) {
        if (!res.ok) {
          detenerEsperaPoint('✗ ' + (res.error || 'No se pudo enviar el cobro'), '#f87171');
          window.mostrarToast(res.error || 'No se pudo enviar el cobro al Point', 'error');
          return;
        }
        estadoPoint('Acerca la tarjeta al terminal Point… ($' + fmt(total) + ')');
        var orderId = res.order_id;
        posTimer = setInterval(function () {
          api('api/pagos_mp.php?action=point_estado&order_id=' + encodeURIComponent(orderId))
            .then(function (r) {
              if (!r.ok) return;
              if (r.pagada) {
                detenerEsperaPoint('✓ ¡Pago aprobado en el Point!', '#34d399');
                window.mostrarToast('¡Pago con Point aprobado!', 'success');
                $('venta-pago').value = 'Mercado Pago';
                cobrar(); // registra la venta e imprime la boleta
                return;
              }
              if (r.estado === 'rejected' || r.estado === 'error') {
                detenerEsperaPoint('✗ El terminal rechazó el pago', '#f87171');
                window.mostrarToast('El terminal rechazó el pago', 'error');
              }
            }).catch(function () {});
        }, 3000);
      })
      .catch(function (err) {
        detenerEsperaPoint('✗ ' + (err && err.message ? err.message : 'Error de conexión'), '#f87171');
        window.mostrarToast(err && err.message ? err.message : 'Error de conexión con el servidor', 'error');
      });
  }

  function pintarCarrito() {
    var cont = $('carrito-lista');
    cont.replaceChildren();

    if (!carrito.length) {
      cont.appendChild(Object.assign(document.createElement('p'), {
        textContent: 'Sin items. Toca un producto para agregarlo.',
        className: 'text-slate-500 text-sm italic py-6 text-center'
      }));
      $('btn-cobrar').disabled = true;
      actualizarBotonPoint();
      $('carrito-total').textContent = '$0';
      $('pos-desglose').classList.add('hidden');
      return;
    }

    carrito.forEach(function (it, idx) {
      var fila = document.createElement('div');
      fila.className = 'bg-slate-900 border border-slate-800 rounded-lg px-3 py-2 flex items-center gap-2';

      var info = document.createElement('div');
      info.className = 'flex-grow min-w-0';
      var nom = document.createElement('p');
      nom.className = 'text-xs font-bold text-white truncate';
      nom.textContent = it.descripcion;
      var sub = document.createElement('p');
      sub.className = 'text-[10px] text-slate-500';
      sub.textContent = '$' + fmt(it.precio_unitario) + ' c/u';
      info.appendChild(nom); info.appendChild(sub);

      var menos = document.createElement('button');
      menos.type = 'button'; menos.innerHTML = '<i class="fa-solid fa-minus pointer-events-none"></i>';
      menos.className = 'w-7 h-7 rounded-md bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs';
      menos.addEventListener('click', function () {
        it.cantidad--;
        if (it.cantidad <= 0) carrito.splice(idx, 1);
        pintarCarrito();
      });

      var cant = document.createElement('span');
      cant.className = 'text-sm font-black text-white w-6 text-center';
      cant.textContent = String(it.cantidad);

      var mas = document.createElement('button');
      mas.type = 'button'; mas.innerHTML = '<i class="fa-solid fa-plus pointer-events-none"></i>';
      mas.className = 'w-7 h-7 rounded-md bg-cyan-600 hover:bg-cyan-500 text-white text-xs';
      mas.addEventListener('click', function () {
        var p = productos.find(function (x) { return x.id === it.producto_id; });
        if (p && p.controlar_stock && it.cantidad + 1 > p.stock) {
          window.mostrarToast('Sin más stock de: ' + p.nombre, 'error'); return;
        }
        it.cantidad++; pintarCarrito();
      });

      var tot = document.createElement('span');
      tot.className = 'text-xs font-bold text-emerald-400 w-20 text-right';
      tot.textContent = '$' + fmt(it.cantidad * it.precio_unitario);

      fila.appendChild(info); fila.appendChild(menos); fila.appendChild(cant);
      fila.appendChild(mas); fila.appendChild(tot);
      cont.appendChild(fila);
    });

    $('btn-cobrar').disabled = false;
    actualizarBotonPoint();
    $('carrito-total').textContent = '$' + fmt(totalCarrito());

    var d = desgloseIVA(totalCarrito());
    $('pos-neto').textContent = '$' + fmt(d.neto);
    $('pos-iva-monto').textContent = '$' + fmt(d.iva);
    $('pos-iva-tasa').textContent = ivaTasa + '%';
    $('pos-desglose').classList.remove('hidden');
  }

  /* ----------------------------------------------------- RESUMEN DEL DÍA */
  function cargarResumenDia() {
    api('api/ventas.php?action=resumen_dia').then(function (res) {
      if (res.ok) $('total-dia').textContent = fmt(res.total_dia);
    }).catch(function () {});
  }

  /* ------------------------------------------------------------ COBRAR */
  function cobrar() {
    if (!carrito.length) return;
    var boton = $('btn-cobrar');
    boton.disabled = true;

    api('api/ventas.php?action=create', {
      method: 'POST',
      body: {
        items: carrito.map(function (it) {
          return { producto_id: it.producto_id, descripcion: it.descripcion,
                   cantidad: it.cantidad, precio_unitario: it.precio_unitario };
        }),
        cliente:    $('venta-cliente').value.trim(),
        cliente_rut: $('venta-rut').value.trim().toUpperCase(),
        medio_pago: $('venta-pago').value,
        orden_codigo: $('venta-orden').value.trim().toUpperCase()
      }
    }).then(function (res) {
      if (!res.ok) {
        window.mostrarToast(res.error || 'No se pudo registrar la venta', 'error');
        boton.disabled = false;
        cargarProductos(); // re-sincroniza stocks
        return;
      }
      window.mostrarToast('Venta ' + res.numero + ' registrada · $' + fmt(res.total), 'success');
      imprimirBoleta(res.numero);
      carrito = [];
      pintarCarrito();
      $('venta-cliente').value = '';
      $('venta-rut').value = '';
      $('venta-orden').value = '';
      boton.disabled = true;
      estadoPoint('', '#34d399'); // oculta la línea del Point tras la venta
      cargarResumenDia();
      setTimeout(cargarProductos, 400); // stock fresco tras el descuento
    }).catch(function () {
      window.mostrarToast('Error de conexión con el servidor', 'error');
      boton.disabled = false;
    });
  }

  /** Abre ventana de impresión con la boleta estilo ticket 80mm. */
  function imprimirBoleta(numero) {
    api('api/ventas.php?action=ticket&numero=' + encodeURIComponent(numero)).then(function (res) {
      if (!res.ok) { window.mostrarToast(res.error || 'Boleta no disponible', 'error'); return; }
      var v = res.venta;
      var filas = res.items.map(function (it) {
        return '<tr><td>' + it.cantidad + ' x</td><td style="max-width:110px">' +
               String(it.descripcion).replace(/[<>&]/g, '') + '</td><td align="right">$' +
               fmt(it.cantidad * it.precio_unitario) + '</td></tr>';
      }).join('');
      var fechaHora = new Date(String(v.creado_en).replace(' ', 'T'));
      var cuando = isNaN(fechaHora.getTime()) ? v.creado_en : fechaHora.toLocaleString('es-CL');

      var html = (
        '<html><head><title>' + numero + '</title><style>' +
        '@page{size:80mm auto;margin:0}body{font-family:monospace;font-size:12px;line-height:1.25;padding:3mm 2mm;color:#000;width:74mm;margin:0 auto;-webkit-print-color-adjust:exact;print-color-adjust:exact}' +
        'h2{text-align:center;margin:4px 0;font-size:15px}.c{text-align:center}.d{border-top:1px dashed #000;margin:8px 0;border-bottom:1px dashed #000;padding:8px 0}' +
        'table{width:100%;border-collapse:collapse}td{padding:2px 0;vertical-align:top;font-size:11px}' +
        '.t{font-size:14px;font-weight:bold;text-align:right;margin-top:8px}' +
        'img.logo{max-width:110px;margin:0 auto 4px;display:block}' +
        '</style></head><body>' +
        (empresaCfg && empresaCfg.empresa_logo
          ? '<img class="logo" src="' + esc(new URL(empresaCfg.empresa_logo, location.href).href) + '">'
          : '') +
        '<h2>' + esc(empresaCfg && empresaCfg.empresa_nombre ? empresaCfg.empresa_nombre : 'LUITECH SERVICIO TECNICO') + '</h2>' +
        '<div class="c">' + (empresaCfg && empresaCfg.empresa_rut ? 'RUT ' + esc(empresaCfg.empresa_rut) + '<br>' : '') + esc(empresaCfg && empresaCfg.empresa_direccion ? empresaCfg.empresa_direccion : 'Persa Las Cenizas - Local 13 · B.O\'Higgins 564, La Serena') +
        '<br>' + esc(empresaCfg && empresaCfg.empresa_telefono ? 'WhatsApp ' + empresaCfg.empresa_telefono : 'WhatsApp +56 9 8220 9690') + '</div>' +
        '<div class="d c"><b>' + numero + '</b><br>' + cuando + '<br>Pago: ' + v.medio_pago +
        (v.cliente && v.cliente !== 'Publico General' ? '<br>Cliente: ' + esc(v.cliente) : '') +
        (v.cliente_rut ? '<br>RUT: ' + esc(v.cliente_rut) : '') +
        (v.orden_codigo ? '<br>Orden: ' + v.orden_codigo : '') + '</div>' +
        '<table>' + filas + '</table>' +
        (v.neto !== null && v.iva_tasa !== null && Number(v.iva_tasa) > 0
          ? '<table style="margin-top:6px">' +
            '<tr><td>Subtotal neto</td><td align="right">$' + fmt(v.neto) + '</td></tr>' +
            '<tr><td>IVA (' + v.iva_tasa + '%) incluido</td><td align="right">$' + fmt(v.iva_monto) + '</td></tr>' +
            '</table>'
          : '') +
        '<p class="t">TOTAL $' + fmt(v.total) + '</p>' +
        '<div class="c" style="margin-top:10px">¡Gracias por tu compra!<br>Garantía por escrito en tu orden.</div>' +
        '</body></html>'
      );
      window.imprimirDocumento(html);
    }).catch(function () {});
  }

  /* ----------------------------------------------------------- ARRANQUE */
  document.addEventListener('DOMContentLoaded', function () {
    $('buscar').addEventListener('input', function () { pintarCatalogo(this.value); });
    // Enter en el buscador = coincidencia exacta (barcode o código interno)
    // → agrega directo al carrito. Así un lector láser USB/Bluetooth funciona
    // sin configuración: escribe el código y "aprieta Enter" solo.
    $('buscar').addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      var t = this.value.trim();
      if (!t) return;
      var p = buscarPorCodigoExacto(t);
      if (p) {
        agregarAlCarrito(p);
        this.value = '';
        pintarCatalogo('');
      } else {
        window.mostrarToast('Ningún producto con código "' + t + '"', 'error');
      }
    });
    // Escáner con la cámara: modo continuo para vender varios productos seguidos.
    $('btn-escanear').addEventListener('click', function () {
      window.LuitechScanner.abrir({
        titulo: 'Escanea los productos para agregarlos al carrito',
        continuo: true,
        onDetect: function (texto) {
          var p = buscarPorCodigoExacto(texto);
          if (p) {
            agregarAlCarrito(p);
            window.mostrarToast('Agregado: ' + p.nombre, 'success');
          } else {
            window.mostrarToast('Código "' + texto + '" sin producto asociado. Regístralo en Inventario.', 'error');
          }
        }
      });
    });
    $('btn-limpiar').addEventListener('click', function () { carrito = []; pintarCarrito(); });
    $('btn-cobrar').addEventListener('click', cobrar);
    $('btn-point').addEventListener('click', function () {
      if (esperandoPoint) {
        detenerEsperaPoint('Espera cancelada. Si el terminal sigue mostrando el cobro, cancélalo allí.', '#fbbf24');
        return;
      }
      cobrarConPoint();
    });

    api('api/auth.php?action=me').then(function (res) {
      mostrarVista(!!res.logueado);
    }).catch(function () { mostrarVista(false); });
  });
})();


