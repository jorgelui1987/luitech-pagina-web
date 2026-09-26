/* LUITECH registro tactil sin RUT: 3 pasos */
(function () {
'use strict';
function $(id) { return document.getElementById(id); }
var estado = { des: 'ninguno', patron: [], pin: '' };
function ir(n) {
  var ids = ['paso1', 'paso2', 'paso3', 'paso-ok'];
  ids.forEach(function (p, i) {
    var show = (n <= 3) ? ((i + 1) === n) : (p === 'paso-ok');
    $(p).classList.toggle('activo', show);
  });
  $('bar1').className = 'flex-1 h-2 rounded-full ' + (n >= 1 ? 'bg-cyan-500' : 'bg-slate-800');
  $('bar2').className = 'flex-1 h-2 rounded-full ' + (n >= 2 ? 'bg-cyan-500' : 'bg-slate-800');
  $('bar3').className = 'flex-1 h-2 rounded-full ' + (n >= 3 ? 'bg-cyan-500' : 'bg-slate-800');
  window.scrollTo(0, 0);
}
function toast(m, t) { if (window.mostrarToast) window.mostrarToast(m, t || 'error'); else alert(m); }
function post(url, body) {
  return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).then(function (r) { return r.json(); });
}
function limpiar() {
  $('rg-nombre').value = ''; $('rg-apellido').value = ''; $('rg-tel').value = ''; $('rg-email').value = '';
  var dp = $('rg-des-pin'); if (dp) dp.value = '';
  estado = { des: 'ninguno', patron: [], pin: '' };
  $('pin-pantalla').textContent = '....';
  $('rg-acepta').checked = false;
  document.querySelectorAll('#patron-grid .patron-dot').forEach(function (d) { d.classList.remove('on'); });
  ir(1);
}

function init() {
  var timerTel = null;
  $('rg-tel').addEventListener('input', function () {
    clearTimeout(timerTel);
    timerTel = setTimeout(function () {
      var t = $('rg-tel').value.trim();
      if (t.replace(/\D/g, '').length < 9) { $('rg-dup').classList.add('hidden'); return; }
      fetch('api/registro.php?action=check&telefono=' + encodeURIComponent(t))
        .then(function (r) { return r.json(); }).then(function (res) {
          if (res && res.existe) {
            $('rg-dup').textContent = 'Ese WhatsApp ya esta registrado (' + res.mascara + '). Si eres tu, sigue: reutilizaremos tu ficha.';
            $('rg-dup').classList.remove('hidden');
          } else { $('rg-dup').classList.add('hidden'); }
        }).catch(function () {});
    }, 500);
  });
  $('rg-sig1').addEventListener('click', function () {
    if ($('rg-nombre').value.trim().length < 2) { toast('Escribe tu nombre'); return; }
    if ($('rg-tel').value.replace(/\D/g, '').length < 9) { toast('Escribe tu WhatsApp (9 digitos)'); return; }
    ir(2);
  });
  document.querySelectorAll('.des-btn').forEach(function (b) {
    b.addEventListener('click', function () {
      estado.des = b.getAttribute('data-des');
      document.querySelectorAll('.des-btn').forEach(function (x) { x.classList.remove('bg-cyan-950'); });
      b.classList.add('bg-cyan-950');
      $('box-pin').classList.toggle('hidden', estado.des !== 'pin');
      $('box-patron').classList.toggle('hidden', estado.des !== 'patron');
      $('box-sinclave').classList.toggle('hidden', estado.des !== 'sin_clave');
    });
  });
  var g = $('patron-grid');
  for (var i = 1; i <= 9; i++) {
    (function (n) {
      var d = document.createElement('button');
      d.className = 'patron-dot'; d.textContent = n; d.type = 'button';
      d.addEventListener('click', function () {
        if (estado.patron.indexOf(n) !== -1) return;
        estado.patron.push(n); d.classList.add('on');
        $('patron-estado').textContent = estado.patron.length + ' puntos';
      });
      g.appendChild(d);
    })(i);
  }
  $('patron-borrar').addEventListener('click', function () {
    estado.patron = [];
    g.querySelectorAll('.patron-dot').forEach(function (d) { d.classList.remove('on'); });
    $('patron-estado').textContent = 'Toca 4 o mas puntos';
  });
  $('rg-atras2').addEventListener('click', function () { ir(1); });
  $('rg-sig2').addEventListener('click', function () {
    if (estado.des === 'pin' && $('rg-des-pin').value.trim() === '') { toast('Escribe tu PIN del equipo o elige otra opcion'); return; }
    if (estado.des === 'patron' && estado.patron.length < 4) { toast('Dibuja tu patron (minimo 4 puntos)'); return; }
    ir(3);
  });
  document.querySelectorAll('[data-n]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (estado.pin.length >= 4) return;
      estado.pin += b.getAttribute('data-n');
      $('pin-pantalla').textContent = (estado.pin + '....').slice(0, 4);
    });
  });
  $('pin-borrar').addEventListener('click', function () {
    estado.pin = estado.pin.slice(0, -1);
    $('pin-pantalla').textContent = (estado.pin + '....').slice(0, 4);
  });
  $('pin-ok').addEventListener('click', function () {
    if (estado.pin.length !== 4) { toast('Tu PIN debe tener 4 digitos'); return; }
    toast('PIN listo. Marca la autorizacion y termina.', 'success');
  });
  $('rg-enviar').addEventListener('click', function () {
    var desClave = '';
    if (estado.des === 'pin') desClave = $('rg-des-pin').value.trim();
    if (estado.des === 'patron') desClave = 'PATRON:' + estado.patron.join('-');
    if (estado.pin.length !== 4) { toast('Crea tu PIN de 4 digitos'); return; }
    if (!$('rg-acepta').checked) { toast('Marca la autorizacion'); return; }
    post('api/registro.php?action=register', {
      nombre: $('rg-nombre').value.trim(),
      apellido: $('rg-apellido').value.trim(),
      telefono: $('rg-tel').value.trim(),
      email: $('rg-email').value.trim(),
      pin_retiro: estado.pin,
      desbloqueo_tipo: estado.des,
      desbloqueo_clave: desClave,
      acepta_privacidad: $('rg-acepta').checked ? 1 : 0
    }).then(function (res) {
      if (!res || !res.ok) { toast((res && res.error) || 'No se pudo registrar'); return; }
      $('ok-nombre').textContent = String(res.nombre || '').split(' ')[0];
      $('ok-codigo').textContent = res.codigo_cli;
      ir(4);
      var n = 15;
      var iv = setInterval(function () {
        n--; $('ok-cuenta').textContent = n;
        if (n <= 0) { clearInterval(iv); limpiar(); }
      }, 1000);
    }).catch(function () { toast('Sin conexion. Avisa en mostrador.'); });
  });
  $('rg-atras3').addEventListener('click', function () { ir(2); });
  $('ok-nuevo').addEventListener('click', limpiar);
}
document.addEventListener('DOMContentLoaded', init);
})();
