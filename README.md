# Luitech — Sitio Web Profesional

Sitio del taller de servicio técnico **Luitech** (La Serena, Chile), con portal de cliente,
seguimiento real de órdenes, panel administrativo protegido y pantalla de sala de espera.

## 🗂️ Estructura del proyecto

```
luitech-pagina-web-main/
├── index.html                  → Portal público (clientes)
├── admin.html                  → Panel de control del taller (requiere login)
├── tv.html                     → Pantalla de sala de espera (Modo TV)
│
├── api/                        → Backend PHP
│   ├── config.php              → Conexión PDO a MySQL y utilidades
│   ├── auth.php                → Login / logout / sesión (bcrypt + bloqueo anti fuerza bruta)
│   └── ordenes.php             → CRUD de órdenes (track público, list/create/update/delete admin)
│
├── assets/
│   ├── css/input.css           → Fuente Tailwind (edítalo para cambiar estilos)
│   ├── css/styles.css          → CSS compilado y minificado (NO editar a mano)
│   ├── js/common.js            → Utilidades compartidas (toasts seguros, menú, alerta sonora)
│   ├── js/portal.js            → Consulta express, tracker, agenda WhatsApp, mapa Leaflet
│   ├── js/tools.js             → Cotizador, test de salud y asistente virtual
│   ├── js/admin.js             → Login y gestión de órdenes del panel
│   ├── js/tv.js                → Turnos en vivo, reloj y consejos rotativos
│   └── img/                    → logo-luitech.png y favicon.svg
│
├── database/luitech.sql        → Esquema + datos iniciales (importar en MySQL)
├── robots.txt  ·  manifest.json  ·  .htaccess  ·  tailwind.config.js
```

## 🚀 Puesta en marcha (Laragon)

1. **Importar la base de datos**
   - Abre phpMyAdmin (`http://localhost/phpmyadmin`) o HeidiSQL.
   - Importa `database/luitech.sql` (crea la BD `luitech`, tablas y datos demo).

2. **Credenciales del panel** (`http://localhost/luitech-pagina-web-main/admin.html`)
   - Usuario: `admin`
   - Contraseña: `luitech2026`
   - ⚠️ **Cámbiala**: genera un nuevo hash con `php -r "echo password_hash('TU_NUEVA_CLAVE', PASSWORD_BCRYPT);"`
     y actualiza la fila en la tabla `usuarios_admin`.

3. **Verifica que MySQL esté encendido** en Laragon. Sin él, el seguimiento no responde.

## 🎨 Regenerar el CSS (solo si cambias clases o input.css)

```bash
npx tailwindcss -c tailwind.config.js -i ./assets/css/input.css -o ./assets/css/styles.css --minify
```

(Requiere Node.js. Las 3 páginas ya enlazan `styles.css` compilado.)

## ✅ Qué se corrigió respecto a la versión anterior

| Problema anterior | Solución |
|---|---|
| Tailwind por CDN (no apto para producción) | CSS compilado localmente (~×10 más ligero) |
| Órdenes guardadas en localStorage (datos falsos) | API PHP + MySQL: los clientes ven estados reales |
| Panel Admin visible sin contraseña | Página dedicada `admin.html` con login bcrypt + bloqueo |
| Vulnerabilidad XSS (innerHTML con datos del usuario) | Contenido dinámico insertado con textContent/Node API |
| Códigos demo y contraseña Wi-Fi públicos | Eliminados del portal; Wi-Fi solo en pantalla física |
| Sin favicon, Open Graph ni SEO local | Favicon SVG, OG/Twitter, canonical y JSON-LD LocalBusiness |
| Formulario sin validaciones | Teléfono chileno válido, fecha mínima = hoy, mensajes claros |
| Archivo monolítico de 1.695 líneas | HTML/CSS/JS separados por página y módulo |

## 🔧 Pendientes recomendados antes de publicar

- Reemplazar `https://www.luitech.cl` en `index.html` (canonical + og:image) por tu dominio real.
- Definir URLs reales de redes sociales en el footer.
- Crear imagen de 1200×630 px para mejor vista previa en WhatsApp.
- Publicar con HTTPS.
