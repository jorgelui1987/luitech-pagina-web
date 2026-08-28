# Plantilla reutilizable: Nueva Orden de Trabajo (Acta de Recepción)

Kit genérico y **sin marcas de Luitech** para copiar a otro proyecto.

## Contenido

| Archivo | Qué es |
|---|---|
| `index.html` | Formulario completo autocontenido (fecha auto, PIN, patrón 3×3 colapsado, chips de accesorios, fotos comprimidas, firma táctil). Funciona solo abriéndolo en el navegador: muestra el JSON que enviarías. |
| `api-ejemplo.php` | Backend de ejemplo en UN solo archivo PHP + SQLite (no necesita MySQL): implementa create, upload_photo, photos, delete_photo y delete con las reglas de seguridad del diseño. |

## Cómo probarlo en 2 minutos

```bash
# Opción A: solo el formulario (sin backend)
#   Abre index.html con doble clic → llena y pulsa "Crear orden".

# Opción B: formulario + backend de ejemplo
php -S 127.0.0.1:8090 -t .           # desde esta carpeta
# abre http://127.0.0.1:8090/index.html
# (en index.html descomenta el bloque "CONECTAR AL BACKEND" y ajusta la URL)
```

## Qué copiar a tu proyecto real

1. El CSS + HTML de los 6 widgets (bloques marcados con comentarios).
2. Las funciones de `index.html`: `fechaLocalHoy`, firma (canvas), patrón
   (geometría 3×3), `comprimirFoto`, `dataUrlABlob`.
3. Las reglas de `api-ejemplo.php`: validar campos, magic bytes PNG, finfo para
   fotos, nombres aleatorios, límites de tamaño/cantidad.
4. Adapta: nombres de columnas, prefijo del código, estados y textos.

## Seguridad mínima antes de producción

- Agrega TU autenticación real (el ejemplo usa un token fijo solo para probar).
- Sirve la carpeta de uploads sin ejecución de scripts.
- Endpoints públicos sin columnas sensibles.