/* ==========================================================================
   LUITECH — pos.js  ·  Punto de Venta (catálogo + carrito + cobro + boleta)
   ========================================================================== */
(function () {
  'use strict';

  var api = window.LuitechAPI;
  var $ = function (id) { return document.getElementById(id); };

  var productos = [];
  var carrito = [];

  function fmt(n) { return Number(n).toLocaleString('es-CL'); }

  function mostrarVista(logueado) {
    $('view-nologin').classList.toggle('hidden', logueado);
    $('view-pos').classList.toggle('hidden', !logueado);
    if (!logueado) return;
    cargarProductos();
    cargarResumenDia();
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
             p.codigo.toLowerCase().indexOf(filtro) !== -1;
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

  function pintarCarrito() {
    var cont = $('carrito-lista');
    cont.replaceChildren();

    if (!carrito.length) {
      cont.appendChild(Object.assign(document.createElement('p'), {
        textContent: 'Sin items. Toca un producto para agregarlo.',
        className: 'text-slate-500 text-sm italic py-6 text-center'
      }));
      $('btn-cobrar').disabled = true;
      $('carrito-total').textContent = '$0';
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
    $('carrito-total').textContent = '$' + fmt(totalCarrito());
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
      $('venta-orden').value = '';
      boton.disabled = true;
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

      var w = window.open('', '_blank', 'width=380,height=640');
      if (!w) { window.mostrarToast('Permite las ventanas emergentes para imprimir', 'error'); return; }
      w.document.write(
        '<html><head><title>' + numero + '</title><style>' +
        '@page{margin:0}body{font-family:monospace;font-size:12px;padding:14px;color:#000}' +
        'h2{text-align:center;margin:4px 0;font-size:15px}.c{text-align:center}.d{border-top:1px dashed #000;margin:8px 0;border-bottom:1px dashed #000;padding:8px 0}' +
        'table{width:100%;border-collapse:collapse}td{padding:2px 0;vertical-align:top;font-size:11px}' +
        '.t{font-size:14px;font-weight:bold;text-align:right;margin-top:8px}' +
        '</style></head><body>' +
        '<h2>LUITECH SERVICIO TECNICO</h2>' +
        '<div class="c">Persa Las Cenizas - Local 13<br>B.O\'Higgins 564, La Serena<br>WhatsApp +56 9 8220 9690</div>' +
        '<div class="d c"><b>' + numero + '</b><br>' + cuando + '<br>Pago: ' + v.medio_pago +
        (v.orden_codigo ? '<br>Orden: ' + v.orden_codigo : '') + '</div>' +
        '<table>' + filas + '</table>' +
        '<p class="t">TOTAL $' + fmt(v.total) + '</p>' +
        '<div class="c" style="margin-top:10px">¡Gracias por tu compra!<br>Garantía por escrito en tu orden.</div>' +
        '</body></html>'
      );
      w.document.close();
      w.focus();
      w.print();
    }).catch(function () {});
  }

  /* ----------------------------------------------------------- ARRANQUE */
  document.addEventListener('DOMContentLoaded', function () {
    $('buscar').addEventListener('input', function () { pintarCatalogo(this.value); });
    $('btn-limpiar').addEventListener('click', function () { carrito = []; pintarCarrito(); });
    $('btn-cobrar').addEventListener('click', cobrar);

    api('api/auth.php?action=me').then(function (res) {
      mostrarVista(!!res.logueado);
    }).catch(function () { mostrarVista(false); });
  });
})();


