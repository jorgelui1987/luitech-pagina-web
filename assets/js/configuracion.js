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
      // Indicador del estado de Mercado Pago
      var estadoMP = $('mp-estado-cfg');
      if (estadoMP) {
        if (cfg.mp_enabled === '1') {
          estadoMP.textContent = '✓ Mercado Pago HABILITADO (el botón de cobro aparece en las órdenes con saldo)';
          estadoMP.style.color = '#34d399';
        } else {
          estadoMP.textContent = '✗ Mercado Pago DESHABILITADO: marca la casilla y guarda';
          estadoMP.style.color = '#f87171';
        }
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

    /** Tras una subida con respuesta OK, relee del servidor para confirmar
     *  que realmente quedó guardado (y en qué base de datos). */
    function verificarGuardado(resSubida) {
      api('api/configuracion.php?action=get_all').then(function (res) {
        if (res.ok && res.config && res.config.empresa_logo) {
          window.mostrarToast('Logo actualizado', 'success');
          cargar();
          return;
        }
        var escritoEn = resSubida && resSubida.db ? resSubida.db : '?';
        var leidoDe = (res.ok && res.config && res.config.db) ? res.config.db : '?';
        estadoLogo('✗ Guardó en [' + escritoEn + '] pero al releer desde [' + leidoDe + '] no estaba — AVÍSAME ESTO', '#f87171');
        window.mostrarToast('El servidor respondió OK pero el logo no quedó guardado', 'error');
      }).catch(function () {
        estadoLogo('✗ No se pudo verificar el logo guardado', '#f87171');
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
      verificarGuardado(res);
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

  /** Genera y descarga el respaldo completo (BD + uploads en un ZIP). */
  function descargarRespaldo() {
    var boton = $('btn-respaldo');
    var estado = $('respaldo-estado');
    boton.disabled = true;
    if (estado) estado.textContent = 'Generando respaldo… (puede tardar si hay muchas fotos)';
    fetch('api/respaldo.php?action=descargar', { credentials: 'same-origin' })
      .then(function (res) {
        var tipo = res.headers.get('Content-Type') || '';
        if (tipo.indexOf('application/json') !== -1) {
          return res.json().then(function (j) {
            throw new Error(j.error || 'Error al generar el respaldo');
          });
        }
        if (!res.ok) throw new Error('El servidor respondió HTTP ' + res.status);
        return res.blob().then(function (blob) { return blob; });
      })
      .then(function (blob) {
        var hoy = new Date();
        var nombre = 'respaldo-luitech-' + hoy.getFullYear() + '-' +
          String(hoy.getMonth() + 1).padStart(2, '0') + '-' + String(hoy.getDate()).padStart(2, '0') + '.zip';
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = nombre;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 5000);
        if (estado) {
          estado.textContent = '✓ Respaldo generado y descargado (' + Math.round(blob.size / 1024) + ' KB)';
          estado.style.color = '#34d399';
        }
        window.mostrarToast('Respaldo generado y descargado', 'success');
      })
      .catch(function (err) {
        var mensaje = err && err.message ? err.message : 'Error al generar el respaldo';
        if (estado) { estado.textContent = '✗ ' + mensaje; estado.style.color = '#f87171'; }
        window.mostrarToast(mensaje, 'error');
      })
      .finally(function () { boton.disabled = false; });
  }

  /** Copia la URL del webhook al portapapeles (para pegarla en Mercado Pago). */
  function copiarWebhook() {
    var campo = $('cfg-mp-webhook');
    if (!campo || campo.value === '') {
      window.mostrarToast('La URL se genera sola al abrir esta página; recarga si está vacía', 'error');
      return;
    }
    if (navigator.clipboard) {
      navigator.clipboard.writeText(campo.value).then(function () {
        window.mostrarToast('URL del webhook copiada — pégala en tu cuenta de Mercado Pago', 'success');
      }).catch(function () {
        campo.select();
        if (document.execCommand('copy')) window.mostrarToast('URL copiada', 'success');
      });
    } else {
      campo.select();
      if (document.execCommand('copy')) window.mostrarToast('URL copiada', 'success');
    }
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

  /** Diagnóstico en vivo de Mercado Pago: habilitado, token, cURL y conexión. */
  function diagnosticoMP() {
    var salida = $('mp-diag');
    if (!salida) return;
    salida.style.color = '#94a3b8';
    salida.textContent = 'Consultando Mercado Pago…';
    api('api/pagos_mp.php?action=diagnostico').then(function (res) {
      if (!res.ok) { salida.textContent = '✗ ' + (res.error || 'No se pudo ejecutar el diagnóstico'); return; }
      var d = res.diagnostico || {};
      var lineas = [];
      lineas.push((d.habilitado === 1 ? '✓' : '✗') + ' Habilitado en Configuración');
      lineas.push((d.token_definido === 1 ? '✓' : '✗') + ' Access token guardado' + (d.token_mask ? ' (' + d.token_mask + ')' : ''));
      lineas.push((d.curl === 1 ? '✓' : '✗') + ' cURL disponible en el servidor');
      if (d.token_definido === 1 && d.curl === 1) {
        if (d.token_valido === 1) {
          lineas.push('✓ Conexión con Mercado Pago OK — cuenta: ' + (d.mp_cuenta || 'tu cuenta'));
        } else {
          lineas.push('✗ Conexión con Mercado Pago FALLÓ — ' + (d.mp_error || ('HTTP ' + d.mp_http)));
        }
        // Terminales Point que Mercado Pago ve en esta cuenta (API /terminals/v1)
        if (d.dispositivos_http === 200 && (d.dispositivos || []).length > 0) {
          d.dispositivos.forEach(function (t) {
            var modo = t.modo || 'UNDEFINED';
            if (modo === 'PDV') {
              lineas.push('✓ Terminal ' + t.id + ' — modo PDV (integrado), listo para cobrar');
            } else {
              lineas.push('⚠ Terminal ' + t.id + ' — modo ' + modo + ': actívalo a PDV');
            }
          });
        } else if (d.dispositivos_http === 200) {
          lineas.push('✗ Tu cuenta NO tiene terminales Point registradas: crea la tienda y punto de venta en MP, y vincula el terminal');
        } else if (d.dispositivos_http === 404) {
          lineas.push('✗ MP no reconoce la API de terminales para esta aplicación: agrega el producto "Point" en Tus integraciones');
          if (d.dispositivos_body) lineas.push('   Respuesta MP: ' + d.dispositivos_body);
        } else {
          lineas.push('✗ No se pudo listar los terminales (HTTP ' + d.dispositivos_http + ')' + (d.dispositivos_body ? ': ' + d.dispositivos_body : ''));
        }
      } else {
        lineas.push('— Prueba de conexión omitida (falta token o cURL)');
      }
      lineas.push('Webhook: ' + (d.webhook || '(no deducible)'));
      salida.textContent = lineas.join('\n');
      salida.style.color = (d.token_valido === 1 && d.habilitado === 1) ? '#34d399' : '#f87171';
    }).catch(function () {
      salida.textContent = '✗ Error de conexión al ejecutar el diagnóstico';
      salida.style.color = '#f87171';
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
        mp_enabled: 'cfg-mp_enabled', mp_public_key: 'cfg-mp_public_key',
        mp_access_token: 'cfg-mp_access_token', mp_point_device: 'cfg-mp_point_device'
      }, 'cfg-btn-mp');
    });
    $('cfg-btn-mp-diag').addEventListener('click', diagnosticoMP);
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
    $('cfg-logo-probar').addEventListener('click', probarSubida);
    $('btn-respaldo').addEventListener('click', descargarRespaldo);
    $('cfg-webhook-copiar').addEventListener('click', copiarWebhook);
    $('form-clave').addEventListener('submit', cambiarClave);

    api('api/auth.php?action=me').then(function (res) {
      mostrarVista(!!(res && res.logueado));
    }).catch(function () { mostrarVista(false); });
  });
  /** Sube una imagen de prueba generada por el navegador: si da verde, el
   *  sistema de subida funciona y el problema es el archivo del usuario. */
  function probarSubida() {
    var lienzo = document.createElement('canvas');
    lienzo.width = 160;
    lienzo.height = 40;
    var ctx = lienzo.getContext('2d');
    ctx.fillStyle = '#22d3ee';
    ctx.fillRect(0, 0, 160, 40);
    ctx.fillStyle = '#0f172a';
    ctx.font = 'bold 16px sans-serif';
    ctx.fillText('LOGO TEST', 28, 26);
    lienzo.toBlob(function (blob) {
      if (!blob) {
        estadoLogo('✗ Error: el navegador no pudo generar la imagen de prueba', '#f87171');
        return;
      }
      estadoLogo('Probando subida con imagen de prueba…', '#fbbf24');
      var fd = new FormData();
      fd.append('logo', blob, 'prueba.png');
      fetch('api/configuracion.php?action=set_logo', {
        method: 'POST', body: fd, credentials: 'same-origin'
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (!res.ok) {
          estadoLogo('✗ La prueba falló: ' + (res.error || 'motivo desconocido'), '#f87171');
          window.mostrarToast('La prueba falló: ' + (res.error || ''), 'error');
          return;
        }
        estadoLogo('✓ La subida FUNCIONA (imagen de prueba guardada). El problema es el archivo de tu logo — mira su formato', '#34d399');
        window.mostrarToast('Subida correcta: el problema es el formato de tu archivo', 'error');
        cargar();
      }).catch(function () {
        estadoLogo('✗ La prueba falló: error de conexión con el servidor', '#f87171');
        window.mostrarToast('La prueba falló: error de conexión', 'error');
      });
    }, 'image/png');
  }

  /* ================== RESET DE DATOS (producción) ================== */
  document.addEventListener('DOMContentLoaded', function () {
    var $r = function (id) { return document.getElementById(id); };
    if (!$r('btn-reset-ejecutar')) return;

    // Estado inicial: avisa si hay caja abierta
    api('api/reset.php?action=estado').then(function (res) {
      if (!res.ok) return;
      if (res.caja_abierta) {
        $r('reset-estado').textContent = '⚠ Hay una caja ABIERTA: ciérrala y cuádrala antes de poder resetear.';
      }
    }).catch(function () {});

    $r('btn-reset-ejecutar').addEventListener('click', function () {
      var boton = this;
      var pass = $r('reset-pass').value;
      var conf = ($r('reset-confirmar').value || '').trim().toUpperCase();
      if (conf !== 'RESETEAR') { window.mostrarToast('Escribe RESETEAR en el paso 3', 'error'); return; }
      if (!pass) { window.mostrarToast('Ingresa tu contraseña en el paso 2', 'error'); return; }
      if (!confirm('ÚLTIMA CONFIRMACIÓN:\nSe borrarán los grupos marcados. Tu configuración, logo y usuario NO se tocan.\n¿Continuar?')) return;

      boton.disabled = true;
      api('api/reset.php?action=ejecutar', { method: 'POST', body: {
        password: pass,
        confirmacion: conf,
        demo: $r('g-demo').checked ? 1 : 0,
        grupos: {
          ordenes:     $r('g-ordenes').checked ? 1 : 0,
          ventas:      $r('g-ventas').checked ? 1 : 0,
          caja:        $r('g-caja').checked ? 1 : 0,
          comisiones:  $r('g-comisiones').checked ? 1 : 0,
          clientes:    $r('g-clientes').checked ? 1 : 0,
          gastos:      $r('g-gastos').checked ? 1 : 0,
          stock:       $r('g-stock').checked ? 1 : 0,
          catalogo:    $r('g-catalogo').checked ? 1 : 0,
          proveedores: $r('g-proveedores').checked ? 1 : 0,
          productos:   $r('g-productos').checked ? 1 : 0,
          tecnicos:    $r('g-tecnicos').checked ? 1 : 0
        }
      }}).then(function (res) {
        boton.disabled = false;
        if (!res.ok) { window.mostrarToast(res.error || 'No se pudo resetear', 'error'); return; }
        var partes = [];
        var borrados = res.borrados || {};
        for (var k in borrados) {
          if (borrados[k] > 0) { partes.push(k + ': ' + borrados[k]); }
        }
        var resumen = partes.length ? partes.join(' · ') : 'nada que borrar';
        $r('reset-estado').textContent = '✓ Reset completado (' + resumen + ')' +
          (res.demo ? ' · Órdenes demo re-sembradas: ' + res.demo : '') +
          ' · Respaldo previo: ' + res.respaldo;
        window.mostrarToast('Reset completado — sistema listo para producción', 'success');
        $r('reset-pass').value = ''; $r('reset-confirmar').value = '';
      }).catch(function () {
        boton.disabled = false;
        window.mostrarToast('Error de conexión con el servidor', 'error');
      });
    });
  });

  /* __CONT__ */
})();
