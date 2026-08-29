# Módulo: Orden de Trabajo con Acta de Recepción

> Diseño portátil del flujo "Nueva Orden de Trabajo" (fecha automática, PIN/patrón,
> accesorios, fotos de respaldo comprimidas y firma del cliente) para replicar en
> cualquier proyecto. Extraído de la implementación real de Luitech (commit 89c79c3).
> Ampliado con: cobro de la reparación (repuestos + mano de obra, abonos/saldo),
> bitácora técnica por orden, entrega con firma + recibo imprimible, integración
> con la caja diaria y métricas del taller.

## 1. Concepto (independiente de tecnología)

```
ENTRADA (formulario)              BACKEND                        SALIDA
Datos de texto     ──JSON──►     Validar y guardar ──────►     Fila en tabla orders
Firma dibujada     ──base64──►   Validar PNG → archivo ──►     Ruta en columna
Fotos              ──multipart─► Validar bytes → archivo ──►   Tabla de adjuntos
Evidencia visual   ◄────────     Listar rutas ◄─────────────   Modal de detalle
```

**Regla de oro:** los datos viven en la BD (solo rutas), los archivos en una carpeta
protegida, la firma viaja **dentro del JSON** y las fotos viajan **por separado**
(multipart, una a una, después de crear la orden).

## 2. Modelo de datos (portable)

```sql
CREATE TABLE orders (
    id           INTEGER PRIMARY KEY AUTO_INCREMENT,
    code         VARCHAR(12) UNIQUE NOT NULL,   -- correlativo auto (prefijo configurable)
    client       VARCHAR(120) NOT NULL,
    device       VARCHAR(120) NOT NULL,
    type         VARCHAR(20)  NOT NULL,
    issue        TEXT NOT NULL,
    status       VARCHAR(20)  DEFAULT 'Received',
    progress     TINYINT      DEFAULT 10,       -- 0..100
    technician   VARCHAR(80)  DEFAULT 'Por asignar',
    pin_pattern  VARCHAR(50)  NULL,             -- 'PIN: 1234' · 'Patrón: 1-4-7'
    accessories  VARCHAR(255) NULL,             -- 'Cargador, Funda'
    intake_notes VARCHAR(250) NULL,             -- estado físico al recibir
    intake_sign  VARCHAR(255) NULL,             -- ruta del PNG de la firma
    intake_date  DATE NOT NULL,                 -- fecha del día, pre-cargada
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE order_files (
    id         INTEGER PRIMARY KEY,
    order_code VARCHAR(12) NOT NULL REFERENCES orders(code) ON DELETE CASCADE,
    file_path  VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## 3. Contrato de API (6 endpoints)

| Endpoint        | Método       | Entrada                     | Salida                    |
|-----------------|--------------|-----------------------------|---------------------------|
| `create`        | POST JSON    | campos + `signature` (dataURL PNG) | orden + ruta de firma |
| `upload_photo`  | POST multipart | `code` + archivo          | id + ruta                 |
| `photos?code=`  | GET          | código                      | lista de rutas            |
| `delete_photo`  | POST JSON    | id                          | ok                        |
| `update`        | POST JSON    | estado/avance/...           | orden                     |
| `nota`          | POST JSON    | código + nota (≤500)        | ok                        |
| `bitacora?code=`| GET          | código                      | bitácora técnica          |
| `delete`        | POST         | código                      | ok + borra archivos       |

## 4. Fórmulas de los componentes

### Fecha pre-cargada
- Componentes locales (`getFullYear/getMonth/getDate`), NUNCA `toISOString()` (UTC
  da "ayer" en Chile después de las 21:00). Editable para ingresos retroactivos.

### Patrón 3×3 (canvas cuadrado de lado L)
- Margen = `0.2×L`; puntos numerados 1..9 con paso `(L − 0.4L)/2`
- Radio del punto = `0.09×L`
- Detección del trazo: punto se enciende si distancia puntero→centro ≤ `0.09×L×1.8`
- Serialización: `Patrón: n-n-n` (mínimo 2 puntos) — texto simple en VARCHAR(50)

### Firma (canvas)
- Canvas blanco, `touch-action:none`, eventos `pointerdown/move/up`
- Guardar = `canvas.toDataURL('image/png')` → base64 dentro del JSON del create
- Servidor: límite ~600 KB, validar cabecera PNG (`\x89PNG\r\n\x1a\n`) y decodificar

### Fotos
- `<input type="file" accept="image/*" capture="environment" multiple>`
- Compresión en el navegador: lado mayor 1200 px, JPEG calidad 0.72 (4 MB → ~300 KB)
- Envío `FormData` SEPARADO del JSON, tras crear la orden

## 5. Seguridad portable

- Autenticación admin en todos los endpoints de escritura/lectura de evidencia.
- Imagen válida = verificar bytes reales (finfo/file-type), no la extensión.
- Máx. 5 MB y máx. 12 fotos/orden; nombres aleatorios (`randomBytes`).
- Carpeta uploads SIN ejecución de scripts (Apache: RemoveHandler + Require all denied).
- Endpoints públicos sin columnas sensibles (PIN, firma, accesorios).
- UI sin XSS: insertar datos con textContent, nunca innerHTML del usuario.

## 6. Checklist de implementación en otro proyecto

1. Crear las 2 tablas
2. Endpoints create/update/delete (JSON) con validación
3. Guardado de firma (dataURL → magic bytes → archivo)
4. Fotos multipart + validación de bytes + nombres aleatorios
5. Configurar uploads sin ejecución de scripts
6. Formulario con los 6 widgets
7. Canvas firma + canvas patrón (geometría del punto 4)
8. Modal de detalle (redibujado del patrón + galería)
9. Endpoints públicos sin columnas sensibles

Adaptable: nombres, prefijo de código, estados y textos.
Se copia tal cual: geometría del patrón, compresión, validación de imágenes y el
flujo JSON-para-texto / multipart-para-archivos.

## 7. Mapa de la implementación real (este proyecto)

```
admin.html              → estructura del formulario + modal + estilos propios
assets/js/admin.js      → firma, patrón, compresión de fotos, modal, cobro/entrega/bitácora
api/ordenes.php         → create/list/update/delete/nota/bitacora/fotos/subir_foto/borrar_foto
api/config.php          → sesiones admin, JSON, PDO
api/caja.php            → caja diaria (los cobros de órdenes ingresan aquí automáticamente)
uploads/.htaccess       → escudo anti-ejecución
database/migrate.php    → migración idempotente (acta + cobro + bitácora)
```
<!--FIN-->