/* LUITECH — kanban.js: columnas por estado con drag & drop + flechas */
(function () {
  'use strict';
  var api = window.LuitechAPI;
  var $ = function (id) { return document.getElementById(id); };

  var ESTADOS = ['Ingresado', 'En Diagnóstico', 'En Reparación', 'Listo para Retiro'];
  var AVANZA = { 'Ingresado': 'En Diagnóstico', 'En Diagnóstico': 'En Reparación', 'En Reparación': 'Listo para Retiro' };
  var RETROCEDE = { 'En Diagnóstico': 'Ingresado', 'En Reparación': 'En Diagnóstico', 'Listo para Retiro': 'En Reparación' };
  var ORDENES = [];

  function cambiarEstado(codigo, nuevoEstado, avance) {
    api('api/ordenes.php?action=update', { method: 'POST', body: { codigo: codigo, estado: nuevoEstado, avance: avance } })
      .then(function (r) {
        if (!r.ok) { window.mostrarToast(r.error || 'Error al actualizar', 'error'); return; }
        window.mostrarToast(codigo + ' → ' + nuevoEstado, 'success');
        if (nuevoEstado === 'Listo para Retiro') window.emitirAlertaTurno();
        cargar();
      }).catch(function () {});
  }

  function card(o) {
    var c = document.createElement('div');
    c.className = 'bg-slate-900 border border-slate-700 rounded-lg p-2.5 cursor-grab active:cursor-grabbing select-none';
    c.draggable = true;
    c.dataset.codigo = o.codigo;
    var cod = document.createElement('p');
    cod.className = 'text-xs font-black text-cyan-400 font-mono'; cod.textContent = o.codigo;
    var cli = document.createElement('p');
    cli.className = 'text-[10px] text-slate-300 truncate'; cli.textContent = o.cliente;
    var eq = document.createElement('p');
    eq.className = 'text-[10px] text-slate-500 truncate mb-1.5'; eq.textContent = o.equipo;
    c.appendChild(cod); c.appendChild(cli); c.appendChild(eq);

    var fila = document.createElement('div');
    fila.className = 'flex justify-between items-center text-[9px] text-slate-400';

    var idx = ESTADOS.indexOf(o.estado);
    if (idx > 0) {
      var bIzq = document.createElement('button');
      bIzq.innerHTML = '<i class="fa-solid fa-arrow-left"></i>'; bIzq.title = RETROCEDE[o.estado];
      bIzq.className = 'w-5 h-5 rounded bg-slate-800 hover:bg-slate-600 transition-all';
      bIzq.addEventListener('click', function () { cambiarEstado(o.codigo, RETROCEDE[o.estado], Math.max(10, (AVANZA && 0))); cargar(); });
      fila.appendChild(bIzq);
    } else { fila.appendChild(document.createElement('span')); }

    var pct = document.createElement('span');
    pct.textContent = o.avance + '%'; pct.className = 'font-bold text-cyan-400';
    fila.appendChild(pct);

    if (idx < ESTADOS.length - 1) {
      var bDer = document.createElement('button');
      bDer.innerHTML = '<i class="fa-solid fa-arrow-right"></i>';
      bDer.title = AVANZA[o.estado] || '';
      bDer.className = 'w-5 h-5 rounded bg-cyan-700 hover:bg-cyan-500 text-white transition-all';
      bDer.addEventListener('click', function () {
        var av = { 'Ingresado': 30, 'En Diagnóstico': 60, 'En Reparación': 100 }[AVANZA[o.estado]] || o.avance;
        cambiarEstado(o.codigo, AVANZA[o.estado], av);
        if (AVANZA[o.estado] === 'Listo para Retiro') window.emitirAlertaTurno();
      });
      fila.appendChild(bDer);
    } else { fila.appendChild(document.createElement('span')); }
    c.appendChild(fila);

    // Drag & drop
    c.addEventListener('dragstart', function (ev) {
      ev.dataTransfer.setData('text/plain', o.codigo);
      ev.dataTransfer.effectAllowed = 'move';
    });
    return c;
  }

  function pintarKanban() {
    var cont = $('kanban'); cont.replaceChildren();
    ESTADOS.forEach(function (estado) {
      var col = document.createElement('div');
      col.className = 'bg-slate-950 border border-slate-800 rounded-xl p-3 space-y-2 min-h-[200px]';
      col.dataset.estado = estado;
      col.addEventListener('dragover', function (ev) { ev.preventDefault(); col.classList.add('border-cyan-600'); });
      col.addEventListener('dragleave', function () { col.classList.remove('border-cyan-600'); });
      col.addEventListener('drop', function (ev) {
        ev.preventDefault(); col.classList.remove('border-cyan-600');
        var codigo = ev.dataTransfer.getData('text/plain');
        if (!codigo || !ESTADOS.includes(estado)) return;
        if (estado === 'Listo para Retiro') window.emitirAlertaTurno();
        cambiarEstado(codigo, estado, estado === 'Listo para Retiro' ? 100 : ({'En Diagnóstico':30,'En Reparación':60}[estado] ?? 10));
      });

      var h = document.createElement('h2');
      h.className = 'text-xs font-black uppercase tracking-wider flex items-center gap-1.5 pb-2 border-b border-slate-800';
      var colores = ['text-slate-400','text-amber-400','text-cyan-400','text-emerald-400'];
      h.style.color = { Ingresado:'#94a3b8','En Diagnóstico':'#fbbf24','En Reparación':'#22d3ee','Listo para Retiro':'#34d399' }[estado];
      h.innerHTML = '<i class="fa-solid ' + ({Ingresado:'fa-inbox','En Diagnóstico':'fa-stethoscope','En Reparación':'fa-screwdriver-wrench','Listo para Retiro':'fa-box-open'}[estado]) +
                    '"></i> ' + estado;
      col.appendChild(h);

      ORDENES.filter(function (o) { return o.estado === estado; })
             .forEach(function (o) { col.appendChild(card(o)); });

      cont.appendChild(col);
    });
  }

  function cargar() {
    api('api/ordenes.php?action=list').then(function (res) {
      if (!res.ok) return;
      ORDENES = res.ordenes;
      pintarKanban();
    }).catch(function () {});
  }

  window.Lui.guard('Tablero Kanban', function () { cargar(); });
})();
