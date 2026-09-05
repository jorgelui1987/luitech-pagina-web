/* ==========================================================================
   LUITECH — inventario.js · CRUD de productos + alertas de stock bajo
   ========================================================================== */
(function () {
  'use strict';

  var api = window.LuitechAPI;
  var $ = function (id) { return document.getElementById(id); };

  function fmt(n) { return Number(n).toLocaleString('es-CL'); }

  /* ------- Categorías autoguardadas -------
     La lista de sugerencias sale SIEMPRE de los productos guardados en la
     base de datos: se arma y se limpia sola (corrige o renombra la categoría
     de un producto y la errada desaparece de la lista). En localStorage solo
     se recuerda la ÚLTIMA usada para que venga pre-escrita en el formulario. */
  var CLAVE_ULT = 'luitech-inv-ultima-cat';
  var productosCache = []; // última lista de productos (para chips y renombrar)

  // Lista vieja acumulada en localStorage (versión anterior): ya no se usa,
  // las sugerencias ahora salen de los productos reales.
  try { localStorage.removeItem('luitech-inv-cats'); } catch (e) { /* nada */ }

  function recordarUltimaCat(cat) {
    try { localStorage.setItem(CLAVE_ULT, String(cat || '').trim()); } catch (e) { /* modo privado */ }
  }

  function leerUltimaCat() {
    try { return localStorage.getItem(CLAVE_ULT) || ''; } catch (e) { return ''; }
  }

  /** Agrupa las categorías de los productos sin distinguir mayúsculas/minúsculas
   *  (así "accesorio" y "Accesorio" cuentan como una) → [{nombre, n}] alfabético. */
  function contarCats(productos) {
    var grupos = {}, orden = [];
    (productos || []).forEach(function (p) {
      var cat = String(p.categoria || '').trim();
      if (!cat) return;
      var k = cat.toLowerCase();
      if (!grupos[k]) { grupos[k] = { nombre: cat, n: 0 }; orden.push(k); }
      grupos[k].n++;
    });
    return orden.map(function (k) { return grupos[k]; })
                .sort(function (a, b) { return a.nombre.localeCompare(b.nombre, 'es'); });
  }

  /** Sugerencias del campo Categoría (datalist) desde las categorías en uso. */
  function llenarCats(grupos) {
    var dl = $('lista-cats');
    if (!dl) return;
    dl.replaceChildren();
    (grupos || []).forEach(function (g) { dl.appendChild(new Option(g.nombre)); });
  }

  /** Chips bajo el formulario: clic escribe la categoría en el formulario y el
   *  lápiz la cambia en TODOS los productos que la usan (corrige o elimina un
   *  error de tipeo en un solo paso). */
  function pintarChips(grupos) {
    var caja = $('cats-guardadas');
    if (!caja) return;
    caja.replaceChildren();
    if (!(grupos || []).length) { caja.classList.add('hidden'); return; }
    caja.classList.remove('hidden');

    var titulo = document.createElement('span');
    titulo.className = 'text-slate-500 font-semibold';
    titulo.textContent = 'Categorías en uso:';
    caja.appendChild(titulo);

    grupos.forEach(function (g) {
      var chip = document.createElement('span');
      chip.className = 'inline-flex items-center gap-1.5 bg-slate-900 border border-slate-700 text-slate-200 rounded-full pl-2.5 pr-1.5 py-1';

      var usar = document.createElement('button');
      usar.type = 'button';
      usar.className = 'font-semibold hover:text-cyan-400 transition-colors';
      usar.textContent = g.nombre + ' (' + g.n + ')';
      usar.title = 'Escribir "' + g.nombre + '" en el formulario';
      usar.addEventListener('click', function () { $('p-cat').value = g.nombre; });

      var editar = document.createElement('button');
      editar.type = 'button';
      editar.title = 'Renombrar "' + g.nombre + '" en todos sus productos (corrige el error o la junta con otra categoría)';
      editar.innerHTML = '<i class="fa-solid fa-pen text-[9px] pointer-events-none"></i>';
      editar.className = 'w-5 h-5 rounded-full bg-slate-800 hover:bg-amber-500 text-slate-400 hover:text-slate-950 flex items-center justify-center transition-colors';
      editar.addEventListener('click', function () { renombrarCat(g.nombre); });

      chip.appendChild(usar);
      chip.appendChild(editar);
      caja.appendChild(chip);
    });
  }

  /** Cambia una categoría en todos los productos que la usan. Es la forma de
   *  "eliminar" una categoría errada: se renombra (o se junta con otra) y
   *  desaparece de las sugerencias sola. */
  function renombrarCat(vieja) {
    var nueva = prompt('Cambiar la categoría "' + vieja + '" por:', vieja);
    if (nueva === null) return; // cancelado
    nueva = nueva.trim();
    if (!nueva) { window.mostrarToast('El nuevo nombre no puede quedar vacío', 'error'); return; }
    if (nueva.toLowerCase() === vieja.toLowerCase()) return; // sin cambios

    var afectados = productosCache.filter(function (p) {
      return String(p.categoria || '').trim().toLowerCase() === vieja.toLowerCase();
    });
    if (!afectados.length) return;
    if (!confirm('Se cambiará "' + vieja + '" por "' + nueva + '" en ' + afectados.length + ' producto(s).')) return;

    var hechos = 0, errores = 0, i = 0;
    (function siguiente() {
      if (i >= afectados.length) {
        if (errores) {
          window.mostrarToast('Categoría cambiada en ' + hechos + ' producto(s); ' + errores + ' fallaron. Revisa la tabla.', 'error');
        } else {
          window.mostrarToast('Categoría "' + nueva + '" aplicada a ' + hechos + ' producto(s)', 'success');
        }
        if (leerUltimaCat().toLowerCase() === vieja.toLowerCase()) recordarUltimaCat(nueva);
        cargar(); // refresca tabla, sugerencias y chips
        return;
      }
      var p = afectados[i++];
      api('api/inventario.php?action=update', { method: 'POST', body: { id: p.id, categoria: nueva } })
        .then(function (res) { if (res.ok) { hechos++; } else { errores++; } })
        .catch(function () { errores++; })
        .finally(siguiente);
    })();
  }

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
    // La última categoría usada ya viene escrita (ej. "Accesorio")
    $('p-cat').value = leerUltimaCat();
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

      // Sugerencias y chips de categorías: se reconstruyen de los productos
      // reales (así una categoría corregida desaparece sola de la lista).
      productosCache = res.productos;
      var gruposCats = contarCats(res.productos);
      llenarCats(gruposCats);
      pintarChips(gruposCats);

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
        cajaInv.classList.remove('hidden'); // SIEMPRE visible (con $0 si el inventario está vacío)
        $('inv-total').textContent = '$' + fmt(inversion);
        $('inv-venta').textContent = '$' + fmt(ventaPot);
        $('inv-ganancia').textContent = '$' + fmt(Math.max(0, ventaPot - inversion));
        $('inv-unidades').textContent = 'Unidades en estante: ' + unidades;
        var avisoInv = $('inv-aviso');
        if (sinCosto > 0) {
          avisoInv.textContent = '⚠ ' + sinCosto + ' producto(s) con stock sin costo definido: no cuentan en la inversión (edítalos y llena el costo)';
          avisoInv.classList.remove('hidden');
        } else if (!res.productos.length) {
          avisoInv.textContent = 'La inversión se calcula con el costo × stock de cada producto. Agrega tu primer producto arriba para verla en acción.';
          avisoInv.classList.remove('hidden');
        } else {
          avisoInv.classList.add('hidden');
        }
      }
      renderInv();
    }).catch(function () {});
  }

  /** Buscador: filtra productosCache mientras escribes (cada palabra debe
   *  coincidir en código, nombre, categoría, código de barras o proveedor;
   *  sin distinguir mayúsculas/minúsculas). La tabla se repinta sola. */
  function renderInv() {
    var q = ($('inv-buscar') ? $('inv-buscar').value : '').toLowerCase().trim();
    var palabras = q ? q.split(/\s+/) : [];
    var lista = productosCache.filter(function (p) {
      var texto = ((p.codigo || '') + ' ' + (p.nombre || '') + ' ' + (p.categoria || '') + ' ' +
                   (p.barcode || '') + ' ' + (p.proveedor_nombre || p.proveedor || '')).toLowerCase();
      for (var i = 0; i < palabras.length; i++) {
        if (texto.indexOf(palabras[i]) === -1) return false;
      }
      return true;
    });

    var contador = $('inv-contador');
    if (contador) {
      if (palabras.length) {
        contador.textContent = lista.length + ' de ' + productosCache.length + ' producto(s)';
        contador.classList.remove('hidden');
      } else {
        contador.classList.add('hidden');
      }
    }
    renderTabla(lista, palabras.length > 0);
  }

  function renderTabla(productos, buscando) {
    var tbody = $('inv-body');
    tbody.replaceChildren();

    if (!productos.length) {
      var vacio = document.createElement('td');
      vacio.colSpan = 8; // la tabla tiene 8 columnas (Código…Acciones)
      vacio.className = 'p-6 text-center text-slate-500 italic';
      vacio.textContent = buscando
        ? 'Ningún producto coincide con la búsqueda.'
        : 'Inventario vacío. Agrega tu primer producto arriba.';
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
      recordarUltimaCat(cuerpo.categoria); // viene pre-escrita en el siguiente producto
      window.mostrarToast(id ? 'Producto actualizado' : 'Producto "' + cuerpo.nombre + '" creado', 'success');
      limpiarFormulario();
      if (!id) { // producto recién creado: quita el filtro para que se vea en la tabla
        var buscador = $('inv-buscar');
        if (buscador) buscador.value = '';
      }
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

    // Buscador en vivo: la tabla se filtra mientras escribes
    $('inv-buscar').addEventListener('input', renderInv);

    // La última categoría usada ya viene escrita desde el arranque
    $('p-cat').value = leerUltimaCat();

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

