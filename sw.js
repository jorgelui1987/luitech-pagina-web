/* LUITECH — Service Worker (PWA)
   - Interfaz (páginas, CSS, JS, imágenes): red primero + caché de respaldo
   - API (/api/): SIEMPRE red — los datos del taller nunca se cachean
   Al publicar una actualización, sube la constante CACHE (ej: luitech-v2). */
var CACHE = 'luitech-v1';
var NUCLEO = [
  'admin.html', 'pos.html', 'inventario.html', 'proveedores.html',
  'clientes.html', 'tecnicos.html', 'finanzas.html', 'configuracion.html',
  'assets/css/styles.css', 'assets/js/common.js', 'assets/js/admin.js',
  'assets/img/icon-192.png', 'assets/img/icon-512.png', 'assets/img/logo-luitech.png'
];

self.addEventListener('install', function (e) {
  e.waitUntil(
    caches.open(CACHE)
      .then(function (c) { return c.addAll(NUCLEO).catch(function () {}); })
      .then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (e) {
  e.waitUntil(
    caches.keys().then(function (claves) {
      return Promise.all(claves.filter(function (k) { return k !== CACHE; })
        .map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (e) {
  var url = new URL(e.request.url);
  if (url.origin !== location.origin) { return; }        // CDNs y Mercado Pago: directo
  if (url.pathname.indexOf('/api/') !== -1) { return; }  // datos del taller: SIEMPRE red
  if (e.request.method !== 'GET') { return; }

  // Páginas: red primero (siempre frescas), caché como respaldo sin conexión
  if (e.request.mode === 'navigate' || url.pathname.indexOf('.html') !== -1) {
    e.respondWith(
      fetch(e.request).then(function (res) {
        var copia = res.clone();
        caches.open(CACHE).then(function (c) { c.put(e.request, copia); });
        return res;
      }).catch(function () {
        return caches.match(e.request).then(function (r) { return r || caches.match('admin.html'); });
      })
    );
    return;
  }

  // Estáticos (con versión ?v=: al cambiar la versión se descargan solos)
  e.respondWith(
    caches.match(e.request).then(function (r) {
      return r || fetch(e.request).then(function (res) {
        var copia = res.clone();
        caches.open(CACHE).then(function (c) { c.put(e.request, copia); });
        return res;
      });
    })
  );
});
