/* ==========================================================================
   LUITECH — shell.js · Sidebar ERP + helpers genéricos para módulos
   ========================================================================== */
window.Lui = (function () {
  'use strict';

  var ROL = 'admin';
  var $ = function (id) { return document.getElementById(id); };
  var api = function () { return window.LuitechAPI.apply(null, arguments); };

  var MENU = [
    ['PRINCIPAL', [
      ['Dashboard', 'dashboard.html', 'fa-gauge-high'],
      ['Órdenes / Panel', 'admin.html', 'fa-clipboard-list']
    ]],
    ['VENTAS', [
      ['Punto de Venta', 'pos.html', 'fa-cash-register'],
      ['Devoluciones', 'devoluciones.html', 'fa-rotate-left'],
      ['Garantías', 'garantias.html', 'fa-shield-halved']
    ]],
    ['GESTIÓN', [
      ['Inventario', 'inventario.html', 'fa-boxes-stacked'],
      ['Mov. de Stock', 'movimientos.html', 'fa-arrows-split-up-and-left'],
      ['Clientes', 'clientes.html', 'fa-users'],
      ['Proveedores', 'proveedores.html', 'fa-truck-field'],
      ['O. de Compra', 'compras.html', 'fa-file-invoice-dollar']
    ]],
    ['REPARACIONES', [
      ['Tablero Kanban', 'kanban.html', 'fa-table-columns']
    ]],
    ['FINANZAS', [
      ['Caja & Gastos', 'finanzas.html', 'fa-cash-register'],
      ['Estado Financiero', 'estado.html', 'fa-chart-line'],
      ['Comisiones', 'comisiones.html', 'fa-hand-holding-dollar']
    ]],
    ['SISTEMA', [
      ['Config & Usuarios', 'sistema.html', 'fa-gear'],
      ['Manual de Ayuda', 'ayuda.html', 'fa-circle-question']
    ]]
  ];
  var SOLO_ADMIN = {
    'GESTIÓN': true, 'SISTEMA': true,
    'Devoluciones': true
  };

  function crearEl(tag, clase, texto) {
    var e = document.createElement(tag);
    if (clase) e.className = clase;
    if (texto !== undefined) e.textContent = texto;
    return e;
  }

  function initSidebar(activo) {
    var aside = $('sidebar');
    if (!aside) return;

    // Icono logout arriba a la derecha (dentro del sidebar como enlace final)
    api('api/auth.php?action=me').then(function (res) {
      ROL = res.rol || 'admin';
      pintar(ROL);
    }).catch(function () { window.location.href = 'admin.html'; });

    function pintar(rol) {
      aside.replaceChildren();
      var logo = document.createElement('div');
      logo.className = 'mb-4';
      var img = document.createElement('img');
      img.src = 'assets/img/logo-luitech.png'; img.alt = '';
      img.className = 'w-10 h-10 rounded-lg object-cover mb-2';
      img.width = 40; img.height = 40;
      var nombre = crearEl('p', 'text-lg font-black tracking-wider bg-clip-text text-transparent bg-gradient-to-r from-cyan-400 to-blue-500', 'LUITECH');
      logo.appendChild(img); logo.appendChild(nombre);
      aside.appendChild(logo);

      MENU.forEach(function (grupo) {
        var visible = grupo[1].filter(function (it) {
          return rol === 'admin' || !(SOLO_ADMIN[grupo[0]] || SOLO_ADMIN[it[0]]);
        });
        if (!visible.length) return;
        aside.appendChild(crearEl('h3', 'text-slate-500 text-[9px] font-extrabold uppercase tracking-widest mt-3 mb-1 px-2', grupo[0]));
        visible.forEach(function (it) {
          var a = document.createElement('a');
          a.href = it[1];
          if (it[0] === activo) a.classList.add('activo');
          a.innerHTML = '<i class="fa-solid ' + it[2] + ' w-4 mr-2"></i>' + it[0];
          aside.appendChild(a);
        });
      });

      var salir = document.createElement('a');
      salir.href = '#'; salir.className = 'mt-auto text-red-400 hover:bg-red-900/30';
      salir.innerHTML = '<i class="fa-solid fa-right-from-bracket w-4 mr-2"></i>Cerrar sesión';
      salir.addEventListener('click', function (ev) {
        ev.preventDefault();
        api('api/auth.php?action=logout', { method: 'POST' }).then(function () { location.href = 'admin.html'; });
      });
      aside.appendChild(salir);
    }
  }

  /** Guard genérico por página: verifica sesión y opcionalmente rol. */
  function guard(onOk, roles) {
    api('api/auth.php?action=me').then(function (res) {
      if (!res.logueado) { window.location.href = 'admin.html'; return; }
      ROL = res.rol || 'admin';
      if (roles && roles.indexOf(ROL) === -1) {
        $('contenedor').innerHTML =
          '<div class="bg-red-950/40 border border-red-900/60 rounded-xl p-6 text-center text-sm text-red-300">' +
          'Tu rol (' + ROL + ') no permite ver este módulo.</div>';
        initSidebar.call(null, '');
        return;
      }
      onOk();
      initSidebar('');
    }).catch(function () { window.location.href = 'admin.html'; });
  }

  /** Tabla genérica: filas array de objetos + columnas [{k,label,fmt}] */
  function tabla(tbodyId, filas, columnas) {
    var tb = $(tbodyId);
    tb.replaceChildren();
    filas.forEach(function (f) {
      var tr = document.createElement('tr');
      tr.className = 'hover:bg-slate-800/30 transition-colors';
      columnas.forEach(function (c) {
        var td = document.createElement('td');
        td.className = c.clase || 'p-2.5';
        var val = typeof c.fmt === 'function' ? c.fmt(f[c.k], f) : (f[c.k] ?? '');
        td.textContent = val;
        tr.appendChild(td);
      });
      tb.appendChild(tr);
    });
  }

  return { $: $, fmt: function (n) { return Number(n).toLocaleString('es-CL'); },
           h: crearEl, guard: guard, tabla: tabla };
})();
