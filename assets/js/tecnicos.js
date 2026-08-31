/* ==========================================================================
   LUITECH — tecnicos.js · Registro de técnicos y liquidación de comisiones
   ========================================================================== */
(function () {
  'use strict';

  var api = window.LuitechAPI;
  var $ = function (id) { return document.getElementById(id); };

  function fmt(n) { return '$ ' + Math.max(0, Math.round(Number(n) || 0)).toLocaleString('es-CL'); }
  var esRolTecnico = false;

  function mostrarVista(logueado) {
    $('view-nologin').classList.toggle('hidden', logueado);
    $('view-tec').classList.toggle('hidden', !logueado);
    if (logueado) { cargarTecnicos(); cargarComisiones(); }
  }

  /* --------------------------------------------------- TÉCNICOS (CRUD) */
  function cargarTecnicos() {
    api('api/tecnicos.php?action=list').then(function (res) {
      if (!res.ok) return;
      var tbody = $('tec-body');
      tbody.replaceChildren();

      if (!res.tecnicos.length) {
        var trv = document.createElement('tr');
        var tdv = document.createElement('td');
        tdv.colSpan = 6;
        tdv.className = 'p-4 text-center italic text-slate-500';
        tdv.textContent = 'Aún no hay técnicos. Agrega el primero con el formulario superior.';
        trv.appendChild(tdv);
        tbody.appendChild(trv);
        return;
      }

      res.tecnicos.forEach(function (t) {
        var tr = document.createElement('tr');
        tr.className = 'border-b border-slate-800/40 hover:bg-slate-800/30';

        function td(texto, clase) {
          var c = document.createElement('td');
          c.className = clase || 'p-2 text-slate-300';
          c.textContent = texto;
          return c;
        }

        tr.appendChild(td(t.nombre, 'p-2 font-bold text-white'));
        tr.appendChild(td(t.rut || '—', 'p-2 font-mono text-slate-400'));
        tr.appendChild(td(t.porcentaje_comision + '%', 'p-2 text-center text-cyan-400 font-bold'));

        var tdPen = document.createElement('td');
        tdPen.className = 'p-2 text-right';
        tdPen.textContent = (parseInt(t.comisiones_pendientes, 10) > 0)
          ? fmt(t.monto_pendiente) + ' (' + t.comisiones_pendientes + ')'
          : '—';
        tdPen.className += (parseInt(t.comisiones_pendientes, 10) > 0 ? ' text-amber-400 font-bold' : ' text-slate-600');
        tr.appendChild(tdPen);

        // Total pagado histórico al técnico (comisiones en estado Pagada)
        var tdPag = document.createElement('td');
        tdPag.className = 'p-2 text-right';
        tdPag.textContent = (parseInt(t.comisiones_pagadas, 10) > 0)
          ? fmt(t.monto_pagado) + ' (' + t.comisiones_pagadas + ')'
          : '—';
        tdPag.className += (parseInt(t.comisiones_pagadas, 10) > 0 ? ' text-emerald-400 font-bold' : ' text-slate-600');
        tr.appendChild(tdPag);

        var tdAcc = document.createElement('td');
        tdAcc.className = 'p-2 text-center';
        if (!esRolTecnico) {
            var btnKey = document.createElement('button');
            btnKey.type = 'button';
            btnKey.title = t.tiene_acceso > 0 ? 'Quitar acceso al sistema' : 'Crear acceso al sistema (usuario + contraseña)';
            btnKey.innerHTML = '<i class="fa-solid ' + (t.tiene_acceso > 0 ? 'fa-unlock' : 'fa-key') + ' pointer-events-none"></i>';
            btnKey.className = 'w-8 h-8 rounded-lg mx-0.5 transition-all ' + (t.tiene_acceso > 0
              ? 'bg-emerald-950 hover:bg-red-900/60 text-emerald-400 hover:text-red-300 border border-emerald-800'
              : 'bg-slate-800 hover:bg-cyan-600 text-slate-300 hover:text-white');
            btnKey.addEventListener('click', function () { gestionarAcceso(t); });
            tdAcc.appendChild(btnKey);
        }
        var btnEd = document.createElement('button');
        btnEd.type = 'button';
        btnEd.title = 'Editar';
        btnEd.innerHTML = '<i class="fa-solid fa-pen pointer-events-none"></i>';
        btnEd.className = 'w-8 h-8 rounded-lg bg-slate-800 hover:bg-cyan-600 text-slate-300 hover:text-white mx-0.5 transition-all';
        btnEd.addEventListener('click', function () { editarTecnico(t); });
        var btnEl = document.createElement('button');
        btnEl.type = 'button';
        btnEl.title = 'Eliminar';
        btnEl.innerHTML = '<i class="fa-solid fa-trash-can pointer-events-none"></i>';
        btnEl.className = 'w-8 h-8 rounded-lg bg-red-950/40 hover:bg-red-900/60 text-red-400 border border-red-900/60 mx-0.5 transition-all';
        btnEl.addEventListener('click', function () { eliminarTecnico(t); });
        tdAcc.appendChild(btnEd);
        tdAcc.appendChild(btnEl);
        tr.appendChild(tdAcc);

        tbody.appendChild(tr);
      });
    }).catch(function () {});
  }

  function limpiarFormTecnico() {
    $('form-titulo').innerHTML = '<i class="fa-solid fa-plus mr-1"></i>Nuevo técnico';
    ['t-id', 't-nombre', 't-rut', 't-telefono'].forEach(function (i) { $(i).value = ''; });
    $('t-pct').value = '30';
    $('btn-tec-cancelar').classList.add('hidden');
    $('btn-tec-guardar').disabled = false;
  }

  function editarTecnico(t) {
    window.scrollTo({ top: 0, behavior: 'smooth' });
    $('form-titulo').innerHTML = '<i class="fa-solid fa-pen mr-1"></i>Editando: ' + t.nombre;
    $('t-id').value = t.id;
    $('t-nombre').value = t.nombre;
    $('t-rut').value = t.rut || '';
    $('t-telefono').value = t.telefono || '';
    $('t-pct').value = t.porcentaje_comision;
    $('btn-tec-cancelar').classList.remove('hidden');
  }

  function guardarTecnico() {
    var id = $('t-id').value.trim();
    var cuerpo = {
      nombre: $('t-nombre').value.trim(),
      rut: $('t-rut').value.trim(),
      telefono: $('t-telefono').value.trim(),
      porcentaje_comision: parseInt($('t-pct').value, 10) || 0
    };
    if (!cuerpo.nombre) {
      window.mostrarToast('El nombre del técnico es obligatorio', 'error');
      return;
    }
    var url = 'api/tecnicos.php?action=' + (id ? 'update' : 'create');
    if (id) cuerpo.id = parseInt(id, 10);

    var boton = $('btn-tec-guardar');
    boton.disabled = true;
    api(url, { method: 'POST', body: cuerpo }).then(function (res) {
      boton.disabled = false;
      if (!res.ok) { window.mostrarToast(res.error || 'Error al guardar', 'error'); return; }
      window.mostrarToast(id ? 'Técnico actualizado' : 'Técnico "' + cuerpo.nombre + '" creado', 'success');
      limpiarFormTecnico();
      cargarTecnicos();
    }).catch(function () {
      boton.disabled = false;
      window.mostrarToast('Error de conexión con el servidor', 'error');
    });
  }

  function gestionarAcceso(t) {
    if (t.tiene_acceso > 0) {
      if (!confirm('¿QUITAR el acceso al sistema de "' + t.nombre + '"? Ya no podrá iniciar sesión.')) return;
      api('api/tecnicos.php?action=quitar_acceso', { method: 'POST', body: { tecnico_id: t.id } })
        .then(function (res) {
          if (!res.ok) { window.mostrarToast(res.error || 'No se pudo quitar', 'error'); return; }
          window.mostrarToast('Acceso quitado', 'success');
          cargarTecnicos();
        }).catch(function () {});
      return;
    }
    var usuario = prompt('Usuario de acceso para "' + t.nombre + '":\n(letras, números, punto o guion)');
    if (usuario === null) return;
    usuario = (usuario || '').trim();
    if (!/^[a-zA-Z0-9._-]{3,30}$/.test(usuario)) { window.mostrarToast('Usuario inválido (3-30: letras, números, punto, guion)', 'error'); return; }
    var password = prompt('Contraseña (mínimo 10, con MAYÚSCULA, minúscula, número y carácter especial):\nEj: Taller2026$Luitech');
    if (password === null) return;
    api('api/tecnicos.php?action=crear_acceso', { method: 'POST', body: { tecnico_id: t.id, usuario: usuario, password: password } })
      .then(function (res) {
        if (!res.ok) { window.mostrarToast(res.error || 'No se pudo crear el acceso', 'error'); return; }
        window.mostrarToast('Acceso creado para "' + t.nombre + '"', 'success');
        cargarTecnicos();
      }).catch(function () {});
  }

  function eliminarTecnico(t) {
    if (!confirm('¿Eliminar al técnico "' + t.nombre + '"? (sus comisiones históricas se conservan)')) return;
    api('api/tecnicos.php?action=delete', { method: 'POST', body: { id: t.id } })
      .then(function (res) {
        if (!res.ok) { window.mostrarToast(res.error || 'No se pudo eliminar', 'error'); return; }
        window.mostrarToast('"' + t.nombre + '" eliminado', 'success');
        cargarTecnicos();
      }).catch(function () {});
  }

  /* ------------------------------------------------- COMISIONES */
  function cargarComisiones() {
    var estado = $('fil-estado').value;
    var url = 'api/tecnicos.php?action=comisiones' + (estado ? '&estado=' + encodeURIComponent(estado) : '');
    api(url).then(function (res) {
      if (!res.ok) return;
      var tbody = $('com-body');
      tbody.replaceChildren();

      $('com-total').textContent = [
        (res.pendiente_total > 0 ? 'Pendiente: ' + fmt(res.pendiente_total) : ''),
        (res.pagado_total > 0 ? 'Pagado: ' + fmt(res.pagado_total) : '')
      ].filter(Boolean).join('  ·  ');

      if (!res.comisiones.length) {
        var trv = document.createElement('tr');
        var tdv = document.createElement('td');
        tdv.colSpan = 7;
        tdv.className = 'p-4 text-center italic text-slate-500';
        tdv.textContent = 'No hay comisiones en este estado.';
        trv.appendChild(tdv);
        tbody.appendChild(trv);
        return;
      }

      res.comisiones.forEach(function (c) {
        var tr = document.createElement('tr');
        tr.className = 'border-b border-slate-800/40 hover:bg-slate-800/30';

        function td(texto, clase) {
          var c2 = document.createElement('td');
          c2.className = clase || 'p-2 text-slate-300';
          c2.textContent = texto;
          return c2;
        }

        tr.appendChild(td(c.orden_codigo, 'p-2 font-mono font-bold text-cyan-400'));
        tr.appendChild(td(c.tecnico_nombre, 'p-2 text-white font-semibold'));
        tr.appendChild(td(fmt(c.base_margen), 'p-2 text-right text-slate-400'));
        tr.appendChild(td(c.porcentaje + '%', 'p-2 text-center text-slate-400'));
        tr.appendChild(td(fmt(c.monto), 'p-2 text-right text-amber-400 font-bold'));

        var tdEst = document.createElement('td');
        tdEst.className = 'p-2 text-center';
        var chip = document.createElement('span');
        chip.className = 'chip-est ' + (c.estado === 'Pendiente' ? 'pendiente' : 'pagada');
        chip.textContent = c.estado;
        tdEst.appendChild(chip);
        if (c.estado === 'Pagada' && c.fecha_pagada) {
          var f = new Date(String(c.fecha_pagada).replace(' ', 'T'));
          var cuando = isNaN(f.getTime()) ? c.fecha_pagada : f.toLocaleDateString('es-CL');
          var fch = document.createElement('div');
          fch.className = 'text-[9px] text-slate-500 mt-0.5';
          fch.textContent = 'pagada el ' + cuando;
          tdEst.appendChild(fch);
        }
        tr.appendChild(tdEst);

        var tdAcc = document.createElement('td');
        tdAcc.className = 'p-2 text-center';
        if (c.estado === 'Pendiente' && !esRolTecnico) {
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'btn-pagar';
          btn.innerHTML = '<i class="fa-solid fa-hand-holding-dollar mr-1"></i>Pagar';
          btn.addEventListener('click', function () {
            if (!confirm('¿Registrar el pago de la comisión de ' + fmt(c.monto) + ' a ' + c.tecnico_nombre + '?')) return;
            btn.disabled = true;
            api('api/tecnicos.php?action=pagar_comision', { method: 'POST', body: { id: c.id } })
              .then(function (res) {
                btn.disabled = false;
                if (!res.ok) { window.mostrarToast(res.error || 'No se pudo pagar', 'error'); return; }
                window.mostrarToast('Comisión pagada y registrada como Egreso en la caja', 'success');
                cargarComisiones();
                cargarTecnicos();
              }).catch(function () {
                btn.disabled = false;
                window.mostrarToast('Error de conexión con el servidor', 'error');
              });
          });
          tdAcc.appendChild(btn);
        } else {
          tdAcc.textContent = '—';
          tdAcc.className += ' text-slate-600';
        }
        tr.appendChild(tdAcc);

        tbody.appendChild(tr);
      });
    }).catch(function () {});
  }

  document.addEventListener('DOMContentLoaded', function () {
    $('btn-tec-guardar').addEventListener('click', guardarTecnico);
    $('btn-tec-cancelar').addEventListener('click', limpiarFormTecnico);
    $('fil-estado').addEventListener('change', cargarComisiones);

    api('api/auth.php?action=me').then(function (res) {
      if (res && res.logueado && res.rol === 'tecnico') {
        esRolTecnico = true;
        document.body.classList.add('rol-tecnico');
      }
      mostrarVista(!!(res && res.logueado));
    }).catch(function () { mostrarVista(false); });
  });
})();
