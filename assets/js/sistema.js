/* LUITECH — sistema.js */
(function () {
  'use strict';
  var api = window.LuitechAPI;
  var $ = function (id) { return document.getElementById(id); };
  function mostrarVista(logueado) {
    $('view-nologin').classList.toggle('hidden', logueado);
    $('view-sys').classList.toggle('hidden', !logueado);
    if (logueado) { cargarCfg(); cargarUsuarios(); cargarAuditoria(); }
  }
  var CFG_KEYS = [
    ['negocio_nombre','Nombre del negocio'], ['negocio_rut','RUT'],
    ['negocio_direccion','Direccion'], ['negocio_whatsapp','WhatsApp'],
    ['boleta_pie','Texto pie de boleta'], ['comision_pct','% Comision']
  ];
  function cargarCfg() {
    var grid = $('cfg-grid'); grid.replaceChildren();
    api('api/sistema.php?action=cfg_get').then(function (res) {
      var vals = {};
      (res.items || []).forEach(function (it) { vals[it.k] = it.v; });
      CFG_KEYS.forEach(function (par) {
        var wrap = document.createElement('div');
        var lab = document.createElement('label');
        lab.className = 'block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-0.5';
        lab.textContent = par[1];
        var inp = document.createElement('input');
        inp.type = 'text'; inp.id = 'cfg-' + par[0];
        inp.className = 'w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-2 text-white text-xs focus:outline-none focus:border-cyan-500';
        inp.value = vals[par[0]] || '';
        wrap.appendChild(lab); wrap.appendChild(inp); grid.appendChild(wrap);
      });
    }).catch(function () {});
  }
  function cargarUsuarios() {
    api('api/sistema.php?action=usuarios').then(function (res) {
      var div = $('usuarios-tabla'); div.replaceChildren();
      var tbl = document.createElement('table');
      tbl.className = 'w-full text-left text-sm';
      var thead = document.createElement('thead');
      thead.className = 'bg-slate-900/80 border-b border-slate-800 text-[11px] uppercase tracking-wider text-slate-400';
      var trh = document.createElement('tr');
      ['Usuario','Nombre','Rol','Acciones'].forEach(function (c) {
        var th = document.createElement('th'); th.className = 'p-2.5'; th.textContent = c; trh.appendChild(th);
      });
      thead.appendChild(trh); tbl.appendChild(thead);
      var tbody = document.createElement('tbody');
      tbody.className = 'divide-y divide-slate-800/50';
      (res.usuarios || []).forEach(function (u) {
        var tr = document.createElement('tr'); tr.className = 'hover:bg-slate-800/30';
        [u.usuario, u.nombre || '-', u.rol].forEach(function (v) {
          var td = document.createElement('td'); td.className = 'p-2.5'; td.textContent = v; tr.appendChild(td);
        });
        var tdB = document.createElement('td'); tdB.className = 'p-2.5 text-center';
        var btn = document.createElement('button');
        btn.innerHTML = '<i class="fa-solid fa-trash-can pointer-events-none"></i>';
        btn.className = 'w-8 h-8 rounded-lg bg-red-950/40 hover:bg-red-900/60 text-red-400 transition-all';
        btn.addEventListener('click', function () {
          if (!confirm('Eliminar ' + u.usuario + '?')) return;
          api('api/sistema.php?action=usuario_delete', { method: 'POST', body: { id: u.id } })
            .then(function (r2) { if (r2.ok) cargarUsuarios(); });
        });
        tdB.appendChild(btn); tr.appendChild(tdB);
        tbody.appendChild(tr);
      });
      tbl.appendChild(tbody); div.appendChild(tbl);
    }).catch(function () {});
  }
  function cargarAuditoria() {
    api('api/sistema.php?action=auditoria_list').then(function (res) {
      var div = $('auditoria-lista'); div.replaceChildren();
      (res.eventos || []).forEach(function (ev) {
        var fila = document.createElement('div');
        fila.className = 'bg-slate-900/70 border border-slate-800 rounded-lg px-3 py-1.5 flex gap-2 flex-wrap text-xs';
        fila.textContent = ev.creado_en + ' · ' + ev.usuario + ' · ' + ev.accion + ' · ' + (ev.detalle || '');
        div.appendChild(fila);
      });
      if (!(res.eventos || []).length) {
        div.appendChild(Object.assign(document.createElement('p'),
          { textContent: 'Sin eventos aún.', className: 'text-slate-500 italic' }));
      }
    }).catch(function () {});
  }
  document.addEventListener('DOMContentLoaded', function () {
    $('btn-cfg-save').addEventListener('click', function () {
      var body = {};
      CFG_KEYS.forEach(function (p) { body[p[0]] = $('cfg-' + p[0]).value || ''; });
      api('api/sistema.php?action=cfg_set', { method: 'POST', body: body })
        .then(function (res) { if (res.ok) window.mostrarToast('Config guardada', 'success'); });
    });
    $('form-usuario').addEventListener('submit', function (ev) {
      ev.preventDefault();
      api('api/sistema.php?action=usuario_create', {
        method: 'POST',
        body: { usuario: $('u-usuario').value.trim(), password: $('u-pass').value,
                nombre: $('u-nombre').value.trim(), rol: $('u-rol').value }
      }).then(function (res) {
        if (!res.ok) { window.mostrarToast(res.error || 'Error', 'error'); return; }
        window.mostrarToast('Usuario creado', 'success');
        $('u-usuario').value = ''; $('u-pass').value = ''; $('u-nombre').value = '';
        cargarUsuarios();
      });
    });
    $('file-restore').addEventListener('change', function () {
      var file = this.files[0];
      if (!file) return;
      if (!confirm('Restaurar BORRA todos los datos. Continuar?')) { this.value = ''; return; }
      var reader = new FileReader();
      reader.onload = function () {
        try {
          var payload = JSON.parse(reader.result);
          api('api/sistema.php?action=restore', { method: 'POST', body: payload })
            .then(function (res) {
              if (res.ok) { window.mostrarToast('Restaurado', 'success'); cargarAuditoria(); }
              else window.mostrarToast(res.error || 'Error', 'error');
            });
        } catch (e) { window.mostrarToast('JSON invalido', 'error'); }
      };
      reader.readAsText(file);
      this.value = '';
    });
    api('api/auth.php?action=me').then(function (res) {
      mostrarVista(!!res.logueado);
    }).catch(function () { mostrarVista(false); });
  });
})();

