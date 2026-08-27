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

/* ---------------- Pestañas disponibles + arranque ---------------- */
function renderClientes(){ renderPersonas("cliente"); }
function renderProveedores(){ renderPersonas("proveedor"); }

var TABS_DISPONIBLES = {
  clientes: renderClientes,
  proveedores: renderProveedores,
  stock: renderStock,
  compras: renderCompras
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

