/* ==========================================================================
   LUITECH — configuracion.js · Ajustes globales (empresa, IVA, MP, seguridad)
   ========================================================================== */
(function () {
  'use strict';

  var api = window.LuitechAPI;
  var $ = function (id) { return document.getElementById(id); };
  var SECRETAS = ['dte_api_key', 'mp_access_token'];

  function mostrarVista(logueado) {
    $('view-nologin').classList.toggle('hidden', logueado);
    $('view-config').classList.toggle('hidden', !logueado);
    if (logueado) cargar();
  }

  /** Estado visible del logo (para que siempre sepas si cargó o por qué falló). */
  function estadoLogo(texto, color) {
    var estado = $('cfg-logo-estado');
    if (!estado) return;
    estado.textContent = texto;
    estado.style.color = color || '#94a3b8';
  }

  /** Llena todos los formularios con la configuración guardada. */
  function cargar() {
    api('api/configuracion.php?action=get_all').then(function (res) {
      if (!res.ok) return;
      var cfg = res.config || {};
      Object.keys(cfg).forEach(function (clave) {
        var el = $('cfg-' + clave);
        if (!el) return;
        if (el.type === 'checkbox') {
          el.checked = cfg[clave] === '1';
          return;
        }
        if (SECRETAS.indexOf(clave) !== -1) {
          el.value = '';
          el.placeholder = cfg[clave + '_definido'] ? '•••• guardada (vacío = mantener)' : 'No definida';
          return;
        }
        el.value = cfg[clave];
      });
      if (cfg.empresa_logo) {
        $('cfg-logo-img').src = cfg.empresa_logo;
        $('cfg-logo-img').classList.remove('hidden');
        $('cfg-logo-vacio').classList.add('hidden');
      } else {
        $('cfg-logo-img').removeAttribute('src');
        $('cfg-logo-img').classList.add('hidden');
        $('cfg-logo-vacio').classList.remove('hidden');
      }
      // Indicador claro de si el logo quedó guardado en el servidor
      if (cfg.empresa_logo) {
        estadoLogo('✓ Logo cargado: ' + String(cfg.empresa_logo).split('/').pop(), '#34d399');
      } else {
        estadoLogo('✗ Sin logo subido (la boleta saldrá sin logo)', '#f87171');
      }
      var webhook = $('cfg-mp-webhook');
      if (webhook) webhook.value = cfg.webhook_mp || '';
    }).catch(function () {});
  }

  /** Guarda una sección: mapeo {clave_bd: id_input} → action=set_many. */
  function guardarSeccion(mapeo, botonId) {
    var cuerpo = {};
    Object.keys(mapeo).forEach(function (clave) {
      var el = $(mapeo[clave]);
      if (!el) return;
      if (el.type === 'checkbox') { cuerpo[clave] = el.checked ? '1' : '0'; return; }
      var valor = el.value.trim();
      if (SECRETAS.indexOf(clave) !== -1) {
        if (valor !== '') cuerpo[clave] = valor; // vacío = mantener
        return;
      }
      cuerpo[clave] = valor;
    });
    var boton = $(botonId);
    boton.disabled = true;
    api('api/configuracion.php?action=set_many', { method: 'POST', body: cuerpo })
      .then(function (res) {
        boton.disabled = false;
        if (!res.ok) { window.mostrarToast(res.error || 'No se pudo guardar', 'error'); return; }
        window.mostrarToast('Configuración guardada', 'success');
        cargar(); // refresca máscaras y vista previa
      }).catch(function () {
        boton.disabled = false;
        window.mostrarToast('Error de conexión con el servidor', 'error');
      });
  }

  /** Redimensiona el logo en el navegador (máx. 600px, PNG) antes de enviarlo:
   *  así siempre cabe en los límites de subida del servidor, sea cual sea su
   *  tamaño original. Conserva la transparencia de los PNG. */
  function procesarLogo(archivo) {
    return new Promise(function (resolve, reject) {
      var lector = new FileReader();
      lector.onerror = function () { reject(new Error('No se pudo leer el archivo')); };
      lector.onload = function () {
        var img = new Image();
        img.onload = function () {
          var MAX = 600;
          var escala = Math.min(1, MAX / Math.max(img.naturalWidth, img.naturalHeight));
          var ancho = Math.max(1, Math.round(img.naturalWidth * escala));
          var alto = Math.max(1, Math.round(img.naturalHeight * escala));
          var lienzo = document.createElement('canvas');
          lienzo.width = ancho;
          lienzo.height = alto;
          lienzo.getContext('2d').drawImage(img, 0, 0, ancho, alto);
          lienzo.toBlob(function (blob) {
            if (blob) { resolve(blob); } else { reject(new Error('No se pudo procesar la imagen')); }
          }, 'image/png');
        };
        img.onerror = function () { reject(new Error('El archivo no es una imagen válida')); };
        img.src = lector.result;
      };
      lector.readAsDataURL(archivo);
    });
  }

  /** Sube el logo con dos estrategias y diagnóstico visible:
   *  1) redimensiona en el navegador (max 600px PNG);
   *  2) si el navegador no pudo procesarlo (SVG/HEIC/etc.), envía el original
   *     y el servidor dirá si es válido. Todo queda anotado en pantalla. */
  function subirLogo(archivo) {
    if (!archivo) return;
    var peso = Math.round(archivo.size / 1024);
    estadoLogo('Subiendo: ' + archivo.name + ' (' + peso + ' KB' + (archivo.type ? ', ' + archivo.type : ', tipo desconocido') + ')…', '#fbbf24');

    function terminadoOk() {
      window.mostrarToast('Logo actualizado', 'success');
      cargar(); // refresca indicador (verde) y vista previa
    }
    function terminadoError(mensaje) {
      estadoLogo('✗ Error: ' + mensaje, '#f87171');
      window.mostrarToast(mensaje, 'error');
    }
    function enviar(archivoOBlob, nombre) {
      var fd = new FormData();
      fd.append('logo', archivoOBlob, nombre);
      return fetch('api/configuracion.php?action=set_logo', {
        method: 'POST', body: fd, credentials: 'same-origin'
      }).then(function (r) { return r.json(); })
        .catch(function () { throw new Error('Error de conexión con el servidor'); });
    }

    procesarLogo(archivo).then(function (blob) {
      return enviar(blob, 'logo.png');
    }).catch(function (errProceso) {
      if (archivo.size <= 5 * 1024 * 1024) {
        estadoLogo('Reintentando con el archivo original…', '#fbbf24');
        return enviar(archivo, archivo.name || 'logo');
      }
      throw errProceso;
    }).then(function (res) {
      if (!res || !res.ok) {
        terminadoError((res && res.error) || 'No se pudo subir el logo');
        return;
      }
      terminadoOk();
    }).catch(function (err) {
      terminadoError(err && err.message ? err.message : 'Error de conexión con el servidor');
    });
  }

  function quitarLogo() {
    api('api/configuracion.php?action=del_logo', { method: 'POST' })
      .then(function (res) {
        if (!res.ok) { window.mostrarToast(res.error || 'No se pudo quitar el logo', 'error'); return; }
        window.mostrarToast('Logo eliminado', 'success');
        cargar();
      }).catch(function () {});
  }

  function cambiarClave(event) {
    event.preventDefault();
    var actual = $('clave-actual').value;
    var nueva = $('clave-nueva').value;
    var repetida = $('clave-repetida').value;
    if (nueva !== repetida) {
      window.mostrarToast('La nueva contraseña y su repetición no coinciden', 'error');
      return;
    }
    if (nueva.length < 8) {
      window.mostrarToast('La nueva contraseña debe tener al menos 8 caracteres', 'error');
      return;
    }
    var boton = $('btn-clave-guardar');
    boton.disabled = true;
    api('api/auth.php?action=cambiar_clave', {
      method: 'POST',
      body: { clave_actual: actual, nueva: nueva, repetida: repetida }
    }).then(function (res) {
      boton.disabled = false;
      if (!res.ok) { window.mostrarToast(res.error || 'No se pudo cambiar la contraseña', 'error'); return; }
      window.mostrarToast('Contraseña actualizada', 'success');
      $('clave-actual').value = '';
      $('clave-nueva').value = '';
      $('clave-repetida').value = '';
    }).catch(function () {
      boton.disabled = false;
      window.mostrarToast('Error de conexión con el servidor', 'error');
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    $('cfg-btn-empresa').addEventListener('click', function () {
      guardarSeccion({
        empresa_nombre: 'cfg-empresa_nombre', empresa_rut: 'cfg-empresa_rut',
        empresa_giro: 'cfg-empresa_giro', empresa_direccion: 'cfg-empresa_direccion',
        empresa_pais: 'cfg-empresa_pais', empresa_telefono: 'cfg-empresa_telefono',
        empresa_email: 'cfg-empresa_email'
      }, 'cfg-btn-empresa');
    });
    $('cfg-btn-fact').addEventListener('click', function () {
      guardarSeccion({
        iva_porcentaje: 'cfg-iva_porcentaje', garantia_dias_default: 'cfg-garantia_dias_default',
        dte_habilitado: 'cfg-dte_habilitado', dte_proveedor: 'cfg-dte_proveedor',
        dte_api_key: 'cfg-dte_api_key'
      }, 'cfg-btn-fact');
    });
    $('cfg-btn-mp').addEventListener('click', function () {
      guardarSeccion({
        mp_enabled: 'cfg-mp_enabled', mp_access_token: 'cfg-mp_access_token',
        mp_point_device: 'cfg-mp_point_device'
      }, 'cfg-btn-mp');
    });
    $('cfg-btn-loc').addEventListener('click', function () {
      guardarSeccion({
        moneda: 'cfg-moneda', moneda_simbolo: 'cfg-moneda_simbolo',
        zona_horaria: 'cfg-zona_horaria'
      }, 'cfg-btn-loc');
    });
    $('cfg-btn-term').addEventListener('click', function () {
      guardarSeccion({ terminos_texto: 'cfg-terminos_texto' }, 'cfg-btn-term');
    });
    $('cfg-logo-file').addEventListener('change', function () {
      var archivo = this.files && this.files[0];
      this.value = '';
      subirLogo(archivo);
    });
    $('cfg-logo-quitar').addEventListener('click', quitarLogo);
    $('form-clave').addEventListener('submit', cambiarClave);

    api('api/auth.php?action=me').then(function (res) {
      mostrarVista(!!(res && res.logueado));
    }).catch(function () { mostrarVista(false); });
  });
})();
