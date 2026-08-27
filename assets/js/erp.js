/* LUITECH — erp.js · Gestión unificada (clientes, proveedores, stock, compras, devoluciones, garantías) */
(function () {
'use strict';
var api = window.LuitechAPI;
var $ = function (id) { return document.getElementById(id); };
function fmt(n){ return Number(n).toLocaleString('es-CL'); }
function h(tag, cls, txt){ var e=document.createElement(tag); if(cls)e.className=cls; if(txt!==undefined)e.textContent=txt; return e; }

var TABS = ['clientes','proveedores','stock','compras','devoluciones','garantias'];
var TITULOS = { clientes:'Clientes', proveedores:'Proveedores', stock:'Movimientos de Stock',
                compras:'Órdenes de Compra', devoluciones:'Devoluciones', garantias:'Garantías' };
var tabActual = 'clientes';

/* ---------------- Utilidades de tabla ---------------- */
function tablaSimple(cols, filas, cont){
  cont.replaceChildren();
  var wrap = h('div','overflow-x-auto');
  var table = h('table','w-full text-left text-sm');
  var thead = h('thead','bg-slate-900/80 border-b border-slate-800 text-[11px] uppercase tracking-wider text-slate-400');
  var trh = h('tr');
  cols.forEach(c => trh.appendChild(h('th','p-2.5',c)));
  thead.appendChild(trh); table.appendChild(thead);
  var tbody = h('tbody','divide-y divide-slate-800/50'); table.appendChild(tbody);
  wrap.appendChild(table);

  filas.forEach(f => {
    var tr = h('tr','hover:bg-slate-800/30 transition-colors');
    cols.forEach(colKey => {
      tr.appendChild(h('td','p-2.5', f[colKey] !== undefined ? String(f[colKey]) : ''));
    });
    if (f._acciones) f._acciones.forEach(a => {
      tr.lastChild ? null : null;
    });
    tbody.appendChild(tr);
  });
  cont.appendChild(wrap);
  return tbody;
}

function btnIcono(icon, titulo, clase, fn){
  var b = document.createElement('button');
  b.type='button'; b.title=titulo; b.innerHTML='<i class="fa-solid '+icon+' pointer-events-none"></i>';
  b.className=clase;
  b.addEventListener('click', fn);
  return b;
}

/* ---------------- TABS: personas (clientes/proveedores) ---------------- */
function renderPersonas(tipo){
  var body = $('tab-body'); body.replaceChildren();
  var form = $('tab-form');
  form.classList.remove('hidden');
  form.replaceChildren();

  var campos = tipo === 'cliente'
    ? [['rut','RUT*'],['nombre','Nombre*'],['telefono','Teléfono'],['email','Email'],['direccion','Dirección']]
    : [['nombre','Nombre*'],['contacto','Contacto'],['telefono','Teléfono'],['email','Email'],['notas','Notas']];
  var ids = {};

  campos.forEach(function(f, i){
    var wrap = h('div','');
    var input = h('input','w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-2 text-white text-xs focus:outline-none focus:border-amber-500');
    input.placeholder = f[1]; input.id = 'per-' + f[0];
    if (i === 0) input.required = true;
    if (f[0] === 'email') { input.type='email'; }
    ids[f[0]] = input; wrap.appendChild(input);
    form.appendChild(wrap);
  });
  var btn = h('button','bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold rounded-lg px-4 py-2 col-span-1 md:col-start-4','Guardar ' + TITULOS[tipo].replace(/s$/,''));
  btn.addEventListener('click', function(){ guardarPersona(tipo, ids); });
  form.appendChild(btn);

  api(apiUrl(tipo) + '?action=list').then(function(res){
    if (!res.ok) return;
    var filas = res.items.map(function(x){ return {
      rut: x.rut || '', nombre: x.nombre, contacto: x.contacto || x.telefono || '',
      email: x.email || '',
      _acciones: null,
      _obj: x
    };});
    var cont = h('div','overflow-x-auto');
    var table = h('table','w-full text-left text-sm');
    var thead = h('thead','bg-slate-900/80 border-b border-slate-800 text-[11px] uppercase tracking-wider text-slate-400');
    var trh = h('tr');
    ['RUT/Nombre' && (tipo==='cliente'?'RUT':''),'Nombre','Contacto/Teléfono','Email'].slice(0).forEach(function(c,i){
      trh.appendChild(h('th','p-2.5', tipo==='cliente' ? ['RUT','Nombre','Teléfono','Email'][i] : ['Nombre','Contacto','Teléfono','Notas'][i]));
    });
    trh.appendChild(h('th','p-2.5 text-center','Acciones'));
    thead.appendChild(trh); table.appendChild(thead);
    var tbody = h('tbody','divide-y divide-slate-800/50'); table.appendChild(tbody);
    filas.forEach(function(f){
      var tr = h('tr','hover:bg-slate-800/30 transition-colors');
      var vals = tipo==='cliente' ? [f._obj.rut||'', f._obj.nombre, f._obj.telefono||'', f._obj.email||'']
                                 : [f._obj.nombre, f._obj.contacto||'', f._obj.telefono||'', f._obj.notas||''];
      vals.forEach(v => tr.appendChild(h('td','p-2.5', v)));
      var tdB = h('td','p-2.5 text-center whitespace-nowrap');
      tdB.appendChild(btnIcono('fa-pen','Editar','w-8 h-8 rounded-lg bg-slate-800 hover:bg-cyan-600 text-slate-300 mx-0.5', function(){ editarPersona(tipo,f._obj,ids); }));
      tdB.appendChild(btnIcono('fa-trash-can','Eliminar','w-8 h-8 rounded-lg bg-red-950/40 hover:bg-red-900/60 text-red-400 mx-0.5', function(){
        if (!confirm('¿Eliminar?')) return;
        api(apiUrl(tipo)+'?action=delete',{method:'POST',body:{id:f._obj.id}}).then(function(r2){ if(r2.ok) renderPersonas(tipo); });
      }));
      tr.appendChild(tdB);
      tbody.appendChild(tr);
    });
    cont.appendChild(table);
    body.appendChild(cont);
  }).catch(function(){});
}

function apiUrl(tipo){ return tipo === 'proveedor' ? 'api/catalogo.php?tipo=proveedor' : 'api/catalogo.php?tipo=cliente'; }

function guardarPersona(tipo, ids){
  var body = {};
  Object.keys(ids).forEach(function(k){ body[k] = ids[k].value.trim(); });
  api(apiUrl(tipo) + '?action=create', { method:'POST', body: body })
   .then(function(res){
     if (!res.ok){ window.mostrarToast(res.error || 'Error al guardar','error'); return; }
     window.mostrarToast('Guardado ✓','success');
     Object.keys(ids).forEach(function(k){ ids[k].value=''; });
     renderPersonas(tipo);
   }).catch(function(){});
}

function editarPersona(tipo, obj, ids){
  window.scrollTo({top:0,behavior:'smooth'});
  Object.keys(ids).forEach(function(k){ if(obj[k]!==undefined) ids[k].value = obj[k]||''; });
  // Para edición usamos update: guardamos id en dataset del botón… simplificación:
  window.mostrarToast('Modifica los campos y crea una entrada corregida (ediciones rápidas)', 'success');
}

/* ---------------- Helpers compartidos de pestañas ERP ---------------- */
function esconderForm() {
  var f = $('tab-form');
  f.classList.add('hidden');
  f.replaceChildren();
}
function inputSmall(id, placeholder, tipo, valor) {
  var i = h('input', 'w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-2 text-white text-xs focus:outline-none focus:border-amber-500');
  i.id = id || '';
  i.placeholder = placeholder || '';
  if (tipo) i.type = tipo;
  if (valor !== undefined) i.value = valor;
  return i;
}
function selectSmall(id, opciones) {
  var s = h('select', 'w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-2 text-white text-xs focus:outline-none focus:border-amber-500');
  s.id = id || '';
  (opciones || []).forEach(function (o) {
    var op = h('option', '', o[1]); op.value = o[0]; s.appendChild(op);
  });
  return s;
}
function tablaLista(columnas, filas, renderFila) {
  var wrap = h('div', 'overflow-x-auto');
  var table = h('table', 'w-full text-left text-sm');
  var thead = h('thead', 'bg-slate-900/80 border-b border-slate-800 text-[11px] uppercase tracking-wider text-slate-400');
  var trh = h('tr');
  columnas.forEach(function (c) { trh.appendChild(h('th', 'p-2.5', c)); });
  thead.appendChild(trh); table.appendChild(thead);
  var tbody = h('tbody', 'divide-y divide-slate-800/50'); table.appendChild(tbody);
  filas.forEach(function (f) { tbody.appendChild(renderFila(f)); });
  wrap.appendChild(table);
  return wrap;
}
function badgeEstado(texto) {
  var map = {
    Vigente:   'bg-emerald-950 border-emerald-800 text-emerald-400',
    Recibida:  'bg-emerald-950 border-emerald-800 text-emerald-400',
    Pendiente: 'bg-amber-950 border-amber-800 text-amber-400',
    Usada:     'bg-slate-900 border-slate-700 text-slate-400',
    Vencida:   'bg-slate-900 border-slate-700 text-slate-400'
  };
  return h('span', 'inline-block border rounded px-2 py-0.5 text-[10px] font-bold ' + (map[texto] || map.Vencida), texto);
}
function esAdminActual() {
  return (window.Lui && window.Lui.rol ? window.Lui.rol() : 'admin') === 'admin';
}
function productosCache(cb) {
  api('api/inventario.php?action=list').then(function (res) {
    cb(res.ok ? (res.productos || []) : []);
  }).catch(function () { cb([]); });
}

/* ---------------- TAB: movimientos de stock ---------------- */
function renderStock() {
  esconderForm();
  var body = $('tab-body'); body.replaceChildren();

  if (esAdminActual()) {
    var form = h('div', 'grid grid-cols-2 md:grid-cols-5 gap-2 mb-4 bg-slate-950 border border-slate-800 rounded-xl p-4');
    form.appendChild(selectSmall('stk-prod', [['', 'Producto…']]));
    form.appendChild(selectSmall('stk-tipo', [['Entrada', 'Entrada (+)'], ['Salida', 'Salida (−)'], ['Ajuste', 'Ajuste (=)']]));
    var cant = inputSmall('stk-cant', 'Cantidad', 'number', '1'); cant.min = 0; form.appendChild(cant);
    form.appendChild(inputSmall('stk-mot', 'Motivo'));
    var b = h('button', 'bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold rounded-lg px-4 py-2 text-xs', 'Registrar movimiento');
    b.type = 'button';
    b.addEventListener('click', function () {
      var payload = {
        producto_id: $('stk-prod').value,
        tipo: $('stk-tipo').value,
        cantidad: parseInt($('stk-cant').value, 10) || 0,
        motivo: $('stk-mot').value.trim()
      };
      if (!payload.producto_id) { window.mostrarToast('Elige un producto', 'error'); return; }
      if (!payload.motivo)      { window.mostrarToast('Escribe el motivo', 'error'); return; }
      api('api/stock.php?action=ajuste', { method: 'POST', body: payload }).then(function (res) {
        if (!res.ok) { window.mostrarToast(res.error || 'No se pudo registrar', 'error'); return; }
        window.mostrarToast('Stock actualizado → ' + res.stock_final, 'success');
        renderStock();
      }).catch(function () {});
    });
    form.appendChild(b);
    body.appendChild(form);
  }

  var cont = h('div', '');
  body.appendChild(cont);
  api('api/stock.php?action=list').then(function (res) {
    cont.replaceChildren(tablaLista(['Fecha', 'Producto', 'Tipo', 'Cantidad', 'Motivo'], res.movimientos || [], function (m) {
      var tr = h('tr', 'hover:bg-slate-800/30 transition-colors');
      tr.appendChild(h('td', 'p-2.5 text-slate-400', (m.creado_en || '').replace('T', ' ').slice(0, 16)));
      tr.appendChild(h('td', 'p-2.5', m.producto || ('#' + m.producto_id)));
      var tdT = h('td', 'p-2.5');
      tdT.appendChild(h('span', 'font-bold ' + (m.tipo === 'Entrada' ? 'text-emerald-400' : m.tipo === 'Salida' ? 'text-red-400' : 'text-cyan-400'), m.tipo));
      tr.appendChild(tdT);
      tr.appendChild(h('td', 'p-2.5', String(m.cantidad)));
      tr.appendChild(h('td', 'p-2.5 text-slate-300', m.motivo || ''));
      return tr;
    }));
  }).catch(function () {});

  productosCache(function (prods) {
    var sel = $('stk-prod');
    if (!sel) return;
    prods.forEach(function (p) {
      var op = h('option', '', p.nombre + ' (stock ' + p.stock + ')');
      op.value = p.id; sel.appendChild(op);
    });
  });
}

function comprasFormulario(body, recargar) {
  var wrapForm = h('div', 'bg-slate-950 border border-slate-800 rounded-xl p-4 mb-4');
  wrapForm.appendChild(h('h3', 'text-xs font-bold text-cyan-400 mb-3', 'Nueva Orden de Compra'));
  var provSel = selectSmall('oc-prov', [['', 'Cargando proveedores…']]);
  provSel.classList.add('mb-3', 'max-w-sm');
  wrapForm.appendChild(provSel);
  api('api/catalogo.php?tipo=proveedor&action=list').then(function (res) {
    if (!res.ok) return;
    provSel.replaceChildren();
    var op0 = h('option', '', '— Elige proveedor —'); op0.value = '';
    provSel.appendChild(op0);
    (res.items || []).forEach(function (p) {
      var op = h('option', '', p.nombre); op.value = p.id;
      provSel.appendChild(op);
    });
  }).catch(function () {});

  var itemsBox = h('div', 'space-y-2 mb-3');
  function agregarFilaItem() {
    var row = h('div', 'grid grid-cols-2 md:grid-cols-5 gap-2 items-center');
    var prodSel = selectSmall('', [['', '— Sin producto vinculado —']]);
    productosCache(function (prods) {
      prods.forEach(function (p) {
        var op = h('option', '', p.nombre); op.value = p.id;
        op.setAttribute('data-nombre', p.nombre);
        prodSel.appendChild(op);
      });
    });
    var desc = inputSmall('', 'Descripción del ítem');
    var cant = inputSmall('', 'Cant.', 'number', '1'); cant.min = 1;
    var cost = inputSmall('', 'Costo unitario', 'number', '0'); cost.min = 0;
    row.appendChild(prodSel); row.appendChild(desc); row.appendChild(cant); row.appendChild(cost);
    var tdDel = h('div', 'text-center');
    tdDel.appendChild(btnIcono('fa-xmark', 'Quitar ítem', 'w-8 h-8 rounded-lg bg-red-950/40 hover:bg-red-900/60 text-red-400', function () { row.remove(); }));
    row.appendChild(tdDel);
    itemsBox.appendChild(row);
  }
  agregarFilaItem();
  var btnAdd = h('button', 'text-xs text-cyan-400 hover:text-cyan-300 font-bold mr-4', '+ Agregar ítem');
  btnAdd.type = 'button';
  btnAdd.addEventListener('click', agregarFilaItem);
  var btnSave = h('button', 'bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold rounded-lg px-4 py-2 text-xs', 'Crear orden');
  btnSave.type = 'button';
  btnSave.addEventListener('click', function () {
    var provId = parseInt($('oc-prov').value, 10) || 0;
    var items = [];
    Array.prototype.forEach.call(itemsBox.children, function (row) {
      var els = row.querySelectorAll('select, input');
      var prodId = els[0].value, nombreProd = '';
      if (prodId && els[0].selectedOptions[0]) nombreProd = els[0].selectedOptions[0].getAttribute('data-nombre') || '';
      var item = {
        descripcion: els[1].value.trim() || nombreProd,
        cantidad: parseInt(els[2].value, 10) || 0,
        costo_unitario: parseInt(els[3].value, 10) || 0
      };
      if (prodId) item.producto_id = prodId;
      if (item.descripcion && item.cantidad >= 1 && item.costo_unitario >= 0) items.push(item);
    });
    if (!provId) { window.mostrarToast('Elige un proveedor', 'error'); return; }
    if (!items.length) { window.mostrarToast('Agrega al menos un ítem válido', 'error'); return; }
    api('api/compras.php?action=create', { method: 'POST', body: { proveedor_id: provId, items: items } })
      .then(function (res) {
        if (!res.ok) { window.mostrarToast(res.error || 'No se pudo crear', 'error'); return; }
        window.mostrarToast('Orden ' + res.numero + ' creada ✓', 'success');
        recargar();
      }).catch(function () {});
  });
  var btnRow = h('div', 'flex items-center gap-3');
  btnRow.appendChild(btnAdd); btnRow.appendChild(btnSave);
  wrapForm.appendChild(itemsBox); wrapForm.appendChild(btnRow);
  body.appendChild(wrapForm);
}

function comprasTabla(cont, recargar) {
  var esAdmin = esAdminActual();
  var detalleBox = h('div', 'mb-4');
  var list = h('div', '');
  cont.appendChild(detalleBox); cont.appendChild(list);
  api('api/compras.php?action=list').then(function (res) {
    list.replaceChildren(tablaLista(['Nº', 'Proveedor', 'Total', 'Estado', 'Fecha', 'Acciones'], res.compras || [], function (c) {
      var tr = h('tr', 'hover:bg-slate-800/30 transition-colors');
      tr.appendChild(h('td', 'p-2.5 font-bold text-cyan-400', c.numero));
      tr.appendChild(h('td', 'p-2.5', c.proveedor || ''));
      tr.appendChild(h('td', 'p-2.5', '$' + fmt(c.total)));
      var tdE = h('td', 'p-2.5'); tdE.appendChild(badgeEstado(c.estado)); tr.appendChild(tdE);
      tr.appendChild(h('td', 'p-2.5 text-slate-400', (c.creado_en || '').replace('T', ' ').slice(0, 10)));
      var tdA = h('td', 'p-2.5 text-center whitespace-nowrap');
      tdA.appendChild(btnIcono('fa-eye', 'Ver detalle', 'w-8 h-8 rounded-lg bg-slate-800 hover:bg-cyan-600 text-slate-300 mx-0.5', function () {
        api('api/compras.php?action=detalle&id=' + c.id).then(function (d) {
          if (!d.ok) { window.mostrarToast(d.error || 'Sin detalle', 'error'); return; }
          detalleBox.replaceChildren();
          var box = h('div', 'bg-slate-950 border border-slate-800 rounded-xl p-4');
          box.appendChild(h('h4', 'text-xs font-bold text-cyan-400 mb-2', 'Detalle ' + d.compra.numero + ' · ' + d.compra.proveedor + ' · $' + fmt(d.compra.total)));
          (d.items || []).forEach(function (it) {
            box.appendChild(h('p', 'text-xs text-slate-300', it.cantidad + ' × ' + it.descripcion + ' — $' + fmt(it.costo_unitario)));
          });
          detalleBox.appendChild(box);
        }).catch(function () {});
      }));
      if (esAdmin && c.estado === 'Pendiente') {
        tdA.appendChild(btnIcono('fa-truck-ramp-box', 'Recibir (suma stock)', 'w-8 h-8 rounded-lg bg-emerald-950/60 hover:bg-emerald-900/70 text-emerald-400 mx-0.5', function () {
          if (!confirm('¿Recibir ' + c.numero + ' y sumar stock?')) return;
          api('api/compras.php?action=recibir', { method: 'POST', body: { id: c.id } }).then(function (r2) {
            if (!r2.ok) { window.mostrarToast(r2.error || 'No se pudo recibir', 'error'); return; }
            window.mostrarToast('Compra recibida ✓', 'success');
            recargar();
          }).catch(function () {});
        }));
        tdA.appendChild(btnIcono('fa-trash-can', 'Eliminar', 'w-8 h-8 rounded-lg bg-red-950/40 hover:bg-red-900/60 text-red-400 mx-0.5', function () {
          if (!confirm('¿Eliminar ' + c.numero + '?')) return;
          api('api/compras.php?action=delete', { method: 'POST', body: { id: c.id } }).then(function (r2) {
            if (r2.ok) recargar(); else window.mostrarToast(r2.error || 'No se pudo eliminar', 'error');
          }).catch(function () {});
        }));
      }
      tr.appendChild(tdA);
      return tr;
    }));
  }).catch(function () {});
}

function renderCompras() {
  esconderForm();
  var body = $('tab-body'); body.replaceChildren();
  comprasFormulario(body, renderCompras);
  comprasTabla(body, renderCompras);
}

/* ---------------- TAB: devoluciones ---------------- */
function renderDevoluciones() {
  esconderForm();
  var body = $('tab-body'); body.replaceChildren();

  var wrapForm = h('div', 'bg-slate-950 border border-slate-800 rounded-xl p-4 mb-4');
  wrapForm.appendChild(h('h3', 'text-xs font-bold text-cyan-400 mb-3', 'Registrar devolución de venta'));
  var grid = h('div', 'grid grid-cols-2 md:grid-cols-6 gap-2 mb-3');
  grid.appendChild(inputSmall('dev-venta', 'Nº venta (VT-000000)'));
  grid.appendChild(selectSmall('dev-prod', [['', 'Producto (opcional)']]));
  grid.appendChild(inputSmall('dev-desc', 'Descripción'));
  var cant = inputSmall('dev-cant', 'Cantidad', 'number', '1'); cant.min = 1; grid.appendChild(cant);
  var monto = inputSmall('dev-monto', 'Monto $', 'number', '0'); monto.min = 0; grid.appendChild(monto);
  grid.appendChild(inputSmall('dev-motivo', 'Motivo'));
  wrapForm.appendChild(grid);
  var btn = h('button', 'bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold rounded-lg px-4 py-2 text-xs', 'Registrar devolución');
  btn.type = 'button';
  btn.addEventListener('click', function () {
    var payload = {
      venta_num: $('dev-venta').value.trim().toUpperCase(),
      descripcion: $('dev-desc').value.trim(),
      cantidad: parseInt($('dev-cant').value, 10) || 1,
      monto: parseInt($('dev-monto').value, 10) || 0,
      motivo: $('dev-motivo').value.trim()
    };
    if ($('dev-prod').value) payload.producto_id = $('dev-prod').value;
    if (!/^VT-\d{6}$/.test(payload.venta_num)) { window.mostrarToast('Formato de venta: VT-000000', 'error'); return; }
    if (!payload.descripcion) { window.mostrarToast('Describe lo devuelto', 'error'); return; }
    api('api/devoluciones.php?action=create', { method: 'POST', body: payload }).then(function (res) {
      if (!res.ok) { window.mostrarToast(res.error || 'No se pudo registrar', 'error'); return; }
      window.mostrarToast('Devolución registrada ✓', 'success');
      renderDevoluciones();
    }).catch(function () {});
  });
  wrapForm.appendChild(btn);
  body.appendChild(wrapForm);

  productosCache(function (prods) {
    var sel = $('dev-prod');
    if (!sel) return;
    prods.forEach(function (p) {
      var op = h('option', '', p.nombre); op.value = p.id; sel.appendChild(op);
    });
  });

  var cont = h('div', '');
  body.appendChild(cont);
  api('api/devoluciones.php?action=list').then(function (res) {
    cont.replaceChildren(tablaLista(['Fecha', 'Venta', 'Descripción', 'Cant.', 'Monto', 'Motivo'], res.devoluciones || [], function (d) {
      var tr = h('tr', 'hover:bg-slate-800/30 transition-colors');
      tr.appendChild(h('td', 'p-2.5 text-slate-400', (d.creado_en || '').replace('T', ' ').slice(0, 16)));
      tr.appendChild(h('td', 'p-2.5 font-bold text-cyan-400', d.venta_num || ''));
      tr.appendChild(h('td', 'p-2.5', d.descripcion || ''));
      tr.appendChild(h('td', 'p-2.5', String(d.cantidad)));
      tr.appendChild(h('td', 'p-2.5', '$' + fmt(d.monto)));
      tr.appendChild(h('td', 'p-2.5 text-slate-300', d.motivo || ''));
      return tr;
    }));
  }).catch(function () {});
}

/* ---------------- TAB: garantías ---------------- */
function renderGarantias() {
  esconderForm();
  var body = $('tab-body'); body.replaceChildren();

  var wrapForm = h('div', 'bg-slate-950 border border-slate-800 rounded-xl p-4 mb-4');
  wrapForm.appendChild(h('h3', 'text-xs font-bold text-cyan-400 mb-3', 'Nueva garantía (venta VT-… u orden LUH-…)'));
  var grid = h('div', 'grid grid-cols-2 md:grid-cols-4 gap-2 mb-3');
  grid.appendChild(inputSmall('gar-ref', 'Código (VT-000000 / LUH-1024)'));
  grid.appendChild(inputSmall('gar-cli', 'Cliente'));
  grid.appendChild(inputSmall('gar-prod', 'Producto'));
  var meses = inputSmall('gar-meses', 'Meses', 'number', '3'); meses.min = 1; meses.max = 60; grid.appendChild(meses);
  wrapForm.appendChild(grid);
  var btn = h('button', 'bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold rounded-lg px-4 py-2 text-xs', 'Crear garantía');
  btn.type = 'button';
  btn.addEventListener('click', function () {
    var payload = {
      ref_codigo: $('gar-ref').value.trim().toUpperCase(),
      cliente: $('gar-cli').value.trim(),
      producto: $('gar-prod').value.trim(),
      meses: parseInt($('gar-meses').value, 10) || 3
    };
    if (!payload.cliente || !payload.producto) { window.mostrarToast('Cliente y producto son obligatorios', 'error'); return; }
    api('api/garantias.php?action=create', { method: 'POST', body: payload }).then(function (res) {
      if (!res.ok) { window.mostrarToast(res.error || 'No se pudo crear', 'error'); return; }
      window.mostrarToast('Garantía creada ✓', 'success');
      renderGarantias();
    }).catch(function () {});
  });
  wrapForm.appendChild(btn);
  body.appendChild(wrapForm);

  var cont = h('div', '');
  body.appendChild(cont);
  api('api/garantias.php?action=list').then(function (res) {
    cont.replaceChildren(tablaLista(['Ref.', 'Cliente', 'Producto', 'Inicio', 'Fin', 'Estado', 'Acciones'], res.garantias || [], function (g) {
      var tr = h('tr', 'hover:bg-slate-800/30 transition-colors');
      tr.appendChild(h('td', 'p-2.5 font-bold text-cyan-400', g.ref_codigo || ''));
      tr.appendChild(h('td', 'p-2.5', g.cliente || ''));
      tr.appendChild(h('td', 'p-2.5', g.producto || ''));
      tr.appendChild(h('td', 'p-2.5 text-slate-400', g.inicio || ''));
      tr.appendChild(h('td', 'p-2.5 text-slate-400', g.fin || ''));
      var tdE = h('td', 'p-2.5'); tdE.appendChild(badgeEstado(g.estado || '')); tr.appendChild(tdE);
      var tdA = h('td', 'p-2.5 text-center');
      if (esAdminActual() && !g.usada && g.estado === 'Vigente') {
        tdA.appendChild(btnIcono('fa-check', 'Marcar como usada', 'w-8 h-8 rounded-lg bg-emerald-950/60 hover:bg-emerald-900/70 text-emerald-400', function () {
          if (!confirm('¿Marcar garantía de ' + g.ref_codigo + ' como usada?')) return;
          api('api/garantias.php?action=usar', { method: 'POST', body: { id: g.id } }).then(function (r2) {
            if (!r2.ok) { window.mostrarToast(r2.error || 'No se pudo marcar', 'error'); return; }
            window.mostrarToast('Garantía usada ✓', 'success');
            renderGarantias();
          }).catch(function () {});
        }));
      }
      tr.appendChild(tdA);
      return tr;
    }));
  }).catch(function () {});
}

/* ---------------- Pestañas disponibles + arranque ---------------- */
function renderClientes(){ renderPersonas("cliente"); }
function renderProveedores(){ renderPersonas("proveedor"); }

var TABS_DISPONIBLES = {
  clientes: renderClientes,
  proveedores: renderProveedores,
  stock: renderStock,
  compras: renderCompras,
  devoluciones: renderDevoluciones,
  garantias: renderGarantias
};

function cambiarTab(tab){
  tabActual = tab;
  var btns = $("tab-btns"); btns.replaceChildren();
  Object.keys(TABS_DISPONIBLES).forEach(function (k) {
    var b = h("button", "px-4 py-2 rounded-lg text-xs font-bold transition-all " +
              (k === tabActual ? "bg-cyan-500 text-slate-950" : "bg-slate-900 text-slate-300 border border-slate-800 hover:border-slate-600"),
              TITULOS[k]);
    b.addEventListener("click", function () { cambiarTab(k); });
    btns.appendChild(b);
  });
  TABS_DISPONIBLES[tabActual]();
}

window.Lui.guard("", function () {
  var hash = (location.hash || "#clientes").replace("#", "");
  cambiarTab(TABS_DISPONIBLES[hash] ? hash : "clientes");
});
})();

