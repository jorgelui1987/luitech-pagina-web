<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\VentaController;
use App\Http\Controllers\ReparacionController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\AyudaController;
use App\Http\Controllers\ComboPublicidadController;
use App\Http\Controllers\AuditoriaController;
use App\Http\Controllers\MercadoPagoController;
use Illuminate\Support\Facades\Storage;

// ── PANEL SUPERADMIN (SIN tenant) ──────────────────────────────────────────
Route::prefix('superadmin')->name('superadmin.')->group(function () {
    Route::get('/login', [SuperAdminController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [SuperAdminController::class, 'login'])->name('login.post')->middleware('throttle:10,1');
    Route::post('/logout', [SuperAdminController::class, 'logout'])->name('logout');

    Route::middleware(['auth', 'check.superadmin', 'check.tenant'])->group(function () {
        Route::get('/dashboard', [SuperAdminController::class, 'dashboard'])->name('dashboard');
        Route::get('/tenants', [SuperAdminController::class, 'tenants'])->name('tenants');
        Route::get('/tenants/crear', [SuperAdminController::class, 'createTenant'])->name('tenants.create');
        Route::post('/tenants', [SuperAdminController::class, 'storeTenant'])->name('tenants.store');
        Route::get('/tenants/{id}/editar', [SuperAdminController::class, 'editTenant'])->name('tenants.edit');
        Route::put('/tenants/{id}', [SuperAdminController::class, 'updateTenant'])->name('tenants.update');
        Route::post('/tenants/{id}/toggle', [SuperAdminController::class, 'toggleTenant'])->name('tenants.toggle');
        Route::delete('/tenants/{id}', [SuperAdminController::class, 'destroyTenant'])->name('tenants.destroy');
        Route::get('/tenants/{id}/login-as', [SuperAdminController::class, 'loginAsTenant'])->name('tenants.login-as');
        Route::get('/tenants/{id}/usuarios', [SuperAdminController::class, 'tenantUsers'])->name('tenants.users');
        Route::post('/usuarios/{usuario}/cambiar-password', [SuperAdminController::class, 'changeUserPassword'])->name('usuarios.change-password')->middleware('throttle:10,1');

        // Backups (solo listar y descargar; restaurar/resetear queda en el panel admin)
        Route::get('/backups', [SuperAdminController::class, 'backups'])->name('backups');
        Route::get('/backups/descargar/{nombre}', [SuperAdminController::class, 'descargarBackup'])->name('backups.descargar');

        // Gestión de precios de planes
        Route::get('/planes-precios', [SuperAdminController::class, 'planPreciosIndex'])->name('planes-precios');
        Route::put('/planes-precios/{planPrecio}', [SuperAdminController::class, 'planPreciosUpdate'])->name('planes-precios.update');
    });
});

// ── RECUPERACIÓN DE EMERGENCIA SUPERADMIN (sin autenticación) ─────────
// Desactivada por defecto: solo funciona si la variable de entorno
// SUPERADMIN_RECOVERY_TOKEN está definida y coincide con el token en la URL.
Route::get('/recuperar-superadmin/{token}', [\App\Http\Controllers\SuperAdminRecoveryController::class, 'show'])
    ->name('superadmin.recuperar')->middleware('throttle:10,1');
Route::post('/recuperar-superadmin/{token}', [\App\Http\Controllers\SuperAdminRecoveryController::class, 'reset'])
    ->name('superadmin.recuperar.post')->middleware('throttle:5,1');

// ── PWA: Manifest e iconos dinámicos (sin autenticación) ──────────────
Route::get('/manifest.json', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::get('/pwa/icon/{size}', [PwaController::class, 'icon'])->where('size', '192|512')->name('pwa.icon');

// ── RUTA PÚBLICA PARA QR DE REPARACIONES (sin autenticación) ──────────────
Route::get('/r/{numero_orden}', [\App\Http\Controllers\PublicReparacionController::class, 'status'])
    ->name('reparaciones.public-status');
Route::get('/estado', [\App\Http\Controllers\PublicReparacionController::class, 'status'])
    ->name('reparaciones.public-status.search');

// ── MODO SALA DE ESPERA (pantalla TV, sin autenticación) ───────────────────
// OJO: las rutas fijas van ANTES que las de {slug?}: si no, "mi-turno" se
// interpretaría como slug de tienda y la consulta privada daría 404.
Route::get('/pantalla/mi-turno/{slug?}', [\App\Http\Controllers\PublicReparacionController::class, 'miTurno'])
    ->name('public.pantalla.mi-turno')->middleware('throttle:30,1');
Route::get('/pantalla/data/{slug?}', [\App\Http\Controllers\PublicReparacionController::class, 'pantallaData'])
    ->name('public.pantalla.data');
Route::get('/pantalla/{slug?}', [\App\Http\Controllers\PublicReparacionController::class, 'pantalla'])
    ->name('public.pantalla')
    ->where('slug', '^(?!mi-turno$|data$).*$');

// ── PÁGINA PÚBLICA DE LA TIENDA (mini-web) ────────────────────────────────
Route::get('/t/{slug}', [ComboPublicidadController::class, 'tiendaPublica'])->name('public.tienda');
Route::get('/t/{slug}/resena', [ComboPublicidadController::class, 'formularioResena'])->name('public.resena.form');
Route::get('/t/{slug}/resena/{numeroOrden}', [ComboPublicidadController::class, 'formularioResena'])->name('public.resena.form.orden');
Route::post('/t/{slug}/resena', [ComboPublicidadController::class, 'guardarResena'])->name('public.resena.store');

// ── API PÚBLICA PARA VALIDAR CUPONES ──────────────────────────────────────
Route::post('/api/cupon/validar', [ComboPublicidadController::class, 'validarCupon'])->name('api.cupon.validar');

// ── RUTA PARA SERVIR ARCHIVOS DE STORAGE (sin symlink) ──────────────
// Solo archivos públicos permitidos (imágenes, PDFs, etc.)
Route::get('/storage/{path}', function ($path) {
    // Prevenir path traversal
    $path = str_replace(['..', '\\'], ['', '/'], $path);

    $fullPath = storage_path('app/public/' . $path);
    if (!file_exists($fullPath) || !is_file($fullPath)) {
        abort(404);
    }

    // Solo permitir extensiones seguras
    $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    $permitidas = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf', 'txt', 'csv', 'xlsx', 'doc', 'docx'];

    if (!in_array($extension, $permitidas)) {
        abort(403, 'Tipo de archivo no permitido.');
    }

    return response()->file($fullPath);
})->where('path', '.*')->name('storage.serve');

// ── HEALTH CHECK PARA DOCKER ──────────────────────────────────────────
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// ── WEBHOOK MERCADO PAGO (público, sin autenticación) ─────────────────
Route::post('/webhooks/mercadopago', [MercadoPagoController::class, 'webhook'])
    ->name('webhooks.mercadopago');

// ── RUTAS PÚBLICAS (Landing page de la plataforma) ─────────────────────────
// El branding es de la PLATAFORMA (LUITECH): ver LandingController::branding().
// Controlador en vez de closures para que php artisan optimize funcione.
use App\Http\Controllers\LandingController;

Route::get('/', [LandingController::class, 'index'])->name('landing');

Route::get('/planes', [LandingController::class, 'planes'])
    ->name('planes')
    ->withoutMiddleware([\App\Http\Middleware\CheckTenantStatus::class]);

Route::get('/registro', [SuperAdminController::class, 'showRegistroTenant'])->name('registro.tenant');
Route::post('/registro', [SuperAdminController::class, 'registrarTenant'])->name('registro.tenant.store');

// ── AUTENTICACIÓN DE TENANT ────────────────────────────────────────────────
Route::middleware(['tenant'])->group(function () {

    // Autenticación (sin check.tenant para evitar bucles)
    Route::middleware('guest')->group(function () {
        Route::get('/login',  [LoginController::class, 'showLoginForm'])->name('login');
        Route::post('/login', [LoginController::class, 'login'])->name('login.post')->middleware('throttle:5,1');

        // Rutas 2FA challenge (usuario aún no autenticado)
        Route::get('/two-factor-challenge', [\App\Http\Controllers\Auth\TwoFactorController::class, 'showChallenge'])
            ->name('two-factor.challenge')->middleware('throttle:5,1');
        Route::post('/two-factor-challenge', [\App\Http\Controllers\Auth\TwoFactorController::class, 'verifyChallenge'])
            ->name('two-factor.verify-challenge')->middleware('throttle:5,1');
    });

    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    // ── Rutas protegidas (requieren autenticación y verificación de tenant) ──
    Route::middleware(['auth', 'check.tenant'])->group(function () {

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // Creación de usuarios por el admin de la empresa (multi-rol)
        Route::get('/register', [RegisterController::class, 'showRegistrationForm'])->name('register');
        Route::post('/register', [RegisterController::class, 'register'])->name('register.post')->middleware('throttle:10,1');

        // 2FA Management (usuario autenticado)
        Route::get('/configuracion/2fa', [\App\Http\Controllers\Auth\TwoFactorController::class, 'registro'])->name('two-factor.setup');
        Route::post('/configuracion/2fa/generar', [\App\Http\Controllers\Auth\TwoFactorController::class, 'generar'])->name('two-factor.generar');
        Route::post('/configuracion/2fa/confirmar', [\App\Http\Controllers\Auth\TwoFactorController::class, 'confirmar'])->name('two-factor.confirmar');
        Route::post('/configuracion/2fa/desactivar', [\App\Http\Controllers\Auth\TwoFactorController::class, 'desactivar'])->name('two-factor.desactivar');

        // Manual de Ayuda
        Route::get('/ayuda', [AyudaController::class, 'index'])->name('ayuda.index');

        // Cierre de Caja (admin y vendedor)
        Route::middleware('check.ventas')->group(function () {
            Route::get('/caja', [\App\Http\Controllers\CierreCajaController::class, 'index'])->name('caja.index');
            Route::get('/caja/abrir', [\App\Http\Controllers\CierreCajaController::class, 'crear'])->name('caja.abrir');
            Route::post('/caja/abrir', [\App\Http\Controllers\CierreCajaController::class, 'abrir'])->name('caja.guardar');
            Route::get('/caja/cerrar', [\App\Http\Controllers\CierreCajaController::class, 'cerrar'])->name('caja.cerrar');
            Route::post('/caja/cerrar', [\App\Http\Controllers\CierreCajaController::class, 'guardarCierre'])->name('caja.guardarCierre');
            Route::get('/caja/{cierre}', [\App\Http\Controllers\CierreCajaController::class, 'show'])->name('caja.show');
            Route::get('/caja/{cierre}/ticket', [\App\Http\Controllers\CierreCajaController::class, 'ticket'])->name('caja.ticket');
        });

        // Clientes (solo admin puede eliminar)
        Route::resource('clientes', ClienteController::class)->except(['destroy']);
        Route::delete('/clientes/{cliente}', [ClienteController::class, 'destroy'])
            ->name('clientes.destroy')->middleware('check.delete');

        // Productos (solo admin puede eliminar)
        Route::get('/productos/codigos-barras', [\App\Http\Controllers\ProductoController::class, 'codigosBarras'])->name('productos.codigos-barras');
        Route::post('/productos/guardar-codigo-barras', [\App\Http\Controllers\ProductoController::class, 'guardarCodigoBarras'])->name('productos.guardar-codigo-barras');
        Route::post('/productos/eliminar-codigo-barras', [\App\Http\Controllers\ProductoController::class, 'eliminarCodigoBarras'])->name('productos.eliminar-codigo-barras');
        Route::resource('productos', ProductoController::class)->except(['destroy']);
        Route::delete('/productos/{producto}', [ProductoController::class, 'destroy'])
            ->name('productos.destroy')->middleware('check.delete');
        Route::post('/productos/marca-ajax', [\App\Http\Controllers\ProductoController::class, 'storeMarcaAjax'])->name('productos.marca-ajax');
        Route::post('/productos/categoria-ajax', [\App\Http\Controllers\ProductoController::class, 'storeCategoriaAjax'])->name('productos.categoria-ajax');

        // Proveedores (sin route model binding para evitar TenantScope)
        Route::get('/proveedores', [\App\Http\Controllers\ProveedorController::class, 'index'])->name('proveedores.index');
        Route::get('/proveedores/crear', [\App\Http\Controllers\ProveedorController::class, 'create'])->name('proveedores.create');
        Route::post('/proveedores', [\App\Http\Controllers\ProveedorController::class, 'store'])->name('proveedores.store');
        Route::get('/proveedores/{id}', [\App\Http\Controllers\ProveedorController::class, 'show'])->name('proveedores.show');
        Route::get('/proveedores/{id}/editar', [\App\Http\Controllers\ProveedorController::class, 'edit'])->name('proveedores.edit');
        Route::put('/proveedores/{id}', [\App\Http\Controllers\ProveedorController::class, 'update'])->name('proveedores.update');
        Route::delete('/proveedores/{id}', [\App\Http\Controllers\ProveedorController::class, 'destroy'])->name('proveedores.destroy')->middleware('check.delete');
        Route::match(['post', 'patch'], '/proveedores/{id}/toggle', [\App\Http\Controllers\ProveedorController::class, 'toggle'])->name('proveedores.toggle');

        // Órdenes de Compra
        Route::middleware('check.admin')->group(function () {
            Route::get('/compras', [\App\Http\Controllers\OrdenCompraController::class, 'index'])->name('compras.index');
            Route::get('/compras/crear', [\App\Http\Controllers\OrdenCompraController::class, 'create'])->name('compras.create');
            Route::post('/compras', [\App\Http\Controllers\OrdenCompraController::class, 'store'])->name('compras.store');
            Route::get('/compras/{ordenCompra}', [\App\Http\Controllers\OrdenCompraController::class, 'show'])->name('compras.show');
            Route::get('/compras/{ordenCompra}/editar', [\App\Http\Controllers\OrdenCompraController::class, 'edit'])->name('compras.edit');
            Route::put('/compras/{ordenCompra}', [\App\Http\Controllers\OrdenCompraController::class, 'update'])->name('compras.update');
            Route::delete('/compras/{ordenCompra}', [\App\Http\Controllers\OrdenCompraController::class, 'destroy'])->name('compras.destroy');
            Route::post('/compras/{ordenCompra}/estado', [\App\Http\Controllers\OrdenCompraController::class, 'cambiarEstado'])->name('compras.estado');
        });

        // Movimientos de Stock, Ajustes y Alertas (solo admin)
        Route::middleware('check.admin')->group(function () {
            Route::get('/stock/movimientos', [\App\Http\Controllers\StockMovimientoController::class, 'index'])->name('stock.movimientos');
            Route::get('/stock/ajuste', [\App\Http\Controllers\StockMovimientoController::class, 'ajusteForm'])->name('stock.ajuste');
            Route::post('/stock/ajuste', [\App\Http\Controllers\StockMovimientoController::class, 'ajusteStore'])->name('stock.ajuste.store');
            Route::get('/stock/producto/{producto}', [\App\Http\Controllers\StockMovimientoController::class, 'porProducto'])->name('stock.producto');
            Route::get('/stock/bajo', [\App\Http\Controllers\StockBajoController::class, 'index'])->name('stock.bajo');
            Route::get('/stock/bajo/{producto}/whatsapp', [\App\Http\Controllers\StockBajoController::class, 'notificacionWhatsApp'])->name('stock.bajo.whatsapp');
            Route::get('/api/stock/bajo/contador', [\App\Http\Controllers\StockBajoController::class, 'apiContador'])->name('api.stock.bajo');

            // Exportación e Importación
            Route::get('/exportar/productos/csv', [\App\Http\Controllers\ExportacionController::class, 'productosExcel'])->name('productos.exportar.csv');
            Route::get('/exportar/productos/html', [\App\Http\Controllers\ExportacionController::class, 'productosPDF'])->name('productos.exportar.html');
            Route::get('/exportar/productos/plantilla', [\App\Http\Controllers\ExportacionController::class, 'productosPlantillaImportacion'])->name('productos.plantilla');
            Route::get('/importar/productos', [\App\Http\Controllers\ImportacionController::class, 'importarForm'])->name('productos.importar');
            Route::post('/importar/productos', [\App\Http\Controllers\ImportacionController::class, 'importarStore'])->name('productos.importar.store');
        });

        // Ventas (solo admin y vendedor)
        Route::middleware('check.ventas')->group(function () {
            Route::resource('ventas', VentaController::class)->except(['edit', 'update', 'destroy']);
            Route::patch('/ventas/{venta}/cancelar', [VentaController::class, 'cancelar'])->name('ventas.cancelar');
            Route::get('/ventas/{venta}/estado', [VentaController::class, 'estado'])->name('ventas.estado');
            Route::get('/ventas/{venta}/ticket', [VentaController::class, 'printTicket'])->name('ventas.ticket');
            Route::get('/ventas/{venta}/ticket-carta', [VentaController::class, 'printTicketCarta'])->name('ventas.ticket-carta');
            Route::get('/ventas/{venta}/whatsapp', [VentaController::class, 'enviarWhatsApp'])->name('ventas.whatsapp');
            Route::post('/ventas/{venta}/mercadopago', [MercadoPagoController::class, 'generarPago'])->name('ventas.mercadopago');
            Route::post('/ventas/{venta}/point', [MercadoPagoController::class, 'cobrarPoint'])->name('ventas.point');
            Route::post('/reparaciones/{reparacion}/mercadopago', [MercadoPagoController::class, 'generarPagoReparacion'])->name('reparaciones.mercadopago');
            Route::post('/reparaciones/{reparacion}/point', [MercadoPagoController::class, 'cobrarPointReparacion'])->name('reparaciones.point');
        });

        // Devoluciones (solo admin y vendedor)
        Route::middleware('check.ventas')->group(function () {
            Route::get('/devoluciones', [\App\Http\Controllers\DevolucionController::class, 'index'])->name('devoluciones.index');
            Route::get('/devoluciones/crear', [\App\Http\Controllers\DevolucionController::class, 'create'])->name('devoluciones.create');
            Route::post('/devoluciones', [\App\Http\Controllers\DevolucionController::class, 'store'])->name('devoluciones.store');
            Route::get('/devoluciones/{devolucion}', [\App\Http\Controllers\DevolucionController::class, 'show'])->name('devoluciones.show');
            Route::patch('/devoluciones/{devolucion}/anular', [\App\Http\Controllers\DevolucionController::class, 'anular'])->name('devoluciones.anular');
            Route::get('/api/devoluciones/venta/{ventaId}', [\App\Http\Controllers\DevolucionController::class, 'getVentaDetalles'])->name('devoluciones.api.venta');
        });

        // Garantías (solo admin y vendedor)
        Route::middleware('check.ventas')->group(function () {
            Route::get('/garantias', [\App\Http\Controllers\GarantiaController::class, 'index'])->name('garantias.index');
            Route::get('/garantias/crear', [\App\Http\Controllers\GarantiaController::class, 'create'])->name('garantias.create');
            Route::post('/garantias', [\App\Http\Controllers\GarantiaController::class, 'store'])->name('garantias.store');
            Route::get('/garantias/{garantia}', [\App\Http\Controllers\GarantiaController::class, 'show'])->name('garantias.show');
            Route::patch('/garantias/{garantia}/anular', [\App\Http\Controllers\GarantiaController::class, 'anular'])->name('garantias.anular');
            Route::get('/api/garantias/venta/{ventaId}', [\App\Http\Controllers\GarantiaController::class, 'getVentaDetalles'])->name('garantias.api.venta');
        });

        // Reparaciones (solo admin y técnico)
        Route::middleware('check.reparaciones')->group(function () {
            // Kanban (vista de tablero) - IMPORTANTE: antes del resource
            Route::get('/reparaciones/kanban', [ComboPublicidadController::class, 'kanban'])->name('reparaciones.kanban');
            Route::post('/reparaciones/{reparacion}/kanban-estado', [ComboPublicidadController::class, 'kanbanActualizarEstado'])->name('reparaciones.kanban.estado');
            Route::get('/reparaciones/{reparacion}/recordatorio-retiro', [ComboPublicidadController::class, 'enviarRecordatorioRetiro'])->name('reparaciones.recordatorio-retiro');

            // Registro rápido de cliente desde el formulario de reparación (AJAX)
            Route::post('/reparaciones/cliente-rapido', [ReparacionController::class, 'storeClienteRapido'])->name('reparaciones.cliente-rapido');

            Route::resource('reparaciones', ReparacionController::class)->parameters(['reparaciones' => 'reparacion']);
            Route::get('/reparaciones/{reparacion}/ticket', [ReparacionController::class, 'printTicket'])->name('reparaciones.ticket');
            Route::get('/reparaciones/{reparacion}/ticket-carta', [ReparacionController::class, 'printTicketCarta'])->name('reparaciones.ticket-carta');
            Route::get('/reparaciones/{reparacion}/whatsapp', [ReparacionController::class, 'enviarWhatsApp'])->name('reparaciones.whatsapp');
            Route::get('/reparaciones/{reparacion}/estado-pago', [ReparacionController::class, 'estadoPago'])->name('reparaciones.estado-pago');
            Route::post('/reparaciones/{reparacion}/reembolsar', [ReparacionController::class, 'reembolsar'])->name('reparaciones.reembolsar');

            // Firmas y fotos (AJAX)
            Route::post('/reparaciones/{reparacion}/firma', [ReparacionController::class, 'subirFirma'])->name('reparaciones.firma');
            Route::post('/reparaciones/{reparacion}/fotos', [ReparacionController::class, 'subirFoto'])->name('reparaciones.fotos.subir');
            Route::delete('/reparaciones/fotos/{foto}', [ReparacionController::class, 'eliminarFoto'])->name('reparaciones.fotos.eliminar');
        });

        // Reportes (solo admin)
        Route::middleware('check.admin')->group(function () {
            Route::get('/reportes', [ReporteController::class, 'index'])->name('reportes.index');
        });

        // Laboratorio Digital (contenido de la portada — solo admin)
        Route::middleware('check.admin')->group(function () {
            Route::get('/laboratorio', [\App\Http\Controllers\LaboratorioController::class, 'index'])->name('laboratorio.admin');
            Route::post('/laboratorio/precios/guardar', [\App\Http\Controllers\LaboratorioController::class, 'guardarPrecios'])->name('laboratorio.precios.guardar');
            Route::post('/laboratorio/precios/agregar', [\App\Http\Controllers\LaboratorioController::class, 'agregarPrecio'])->name('laboratorio.precios.agregar');
            Route::delete('/laboratorio/precios/{precio}', [\App\Http\Controllers\LaboratorioController::class, 'eliminarPrecio'])->name('laboratorio.precios.eliminar');
            Route::post('/laboratorio/faq/guardar', [\App\Http\Controllers\LaboratorioController::class, 'guardarFaq'])->name('laboratorio.faq.guardar');
            Route::post('/laboratorio/faq/agregar', [\App\Http\Controllers\LaboratorioController::class, 'agregarFaq'])->name('laboratorio.faq.agregar');
            Route::delete('/laboratorio/faq/{faq}', [\App\Http\Controllers\LaboratorioController::class, 'eliminarFaq'])->name('laboratorio.faq.eliminar');
        });

        // Estado Financiero (solo admin)
        Route::middleware('check.admin')->group(function () {
            Route::get('/financiero', [\App\Http\Controllers\FinancieroController::class, 'index'])->name('financiero.index');
            Route::get('/financiero/estado-resultados', [\App\Http\Controllers\FinancieroController::class, 'estadoResultados'])->name('financiero.estado-resultados');
            Route::get('/financiero/balance-general', [\App\Http\Controllers\FinancieroController::class, 'balanceGeneral'])->name('financiero.balance-general');
            Route::get('/financiero/flujo-caja', [\App\Http\Controllers\FinancieroController::class, 'flujoCaja'])->name('financiero.flujo-caja');
            Route::get('/financiero/indicadores', [\App\Http\Controllers\FinancieroController::class, 'indicadores'])->name('financiero.indicadores');
        });

        // Configuración (solo admin)
        Route::middleware('check.admin')->group(function () {
            Route::get('/configuracion', [ConfiguracionController::class, 'index'])->name('configuracion.index');
            Route::post('/configuracion/empresa', [ConfiguracionController::class, 'updateEmpresa'])->name('configuracion.updateEmpresa');
            Route::post('/configuracion/publicidad', [ConfiguracionController::class, 'updatePublicidad'])->name('configuracion.updatePublicidad');
            Route::post('/configuracion/zona-horaria', [ConfiguracionController::class, 'updateZonaHoraria'])->name('configuracion.updateZonaHoraria');
            Route::post('/configuracion/usuarios', [ConfiguracionController::class, 'storeUsuario'])->name('configuracion.storeUsuario');
Route::post('/configuracion/colores', [ConfiguracionController::class, 'updateColores'])->name('configuracion.colores');
Route::post('/configuracion/whatsapp', [ConfiguracionController::class, 'updateWhatsApp'])->name('configuracion.whatsapp');
Route::post('/configuracion/promos', [ConfiguracionController::class, 'updatePromos'])->name('configuracion.promos');
            Route::patch('/configuracion/usuarios/{usuario}/toggle', [ConfiguracionController::class, 'toggleUsuario'])->name('configuracion.toggleUsuario');
            Route::put('/configuracion/usuarios/{usuario}', [ConfiguracionController::class, 'updateUsuario'])->name('configuracion.updateUsuario');
            Route::delete('/configuracion/usuarios/{usuario}', [ConfiguracionController::class, 'destroyUsuario'])->name('configuracion.destroyUsuario');
        });

        // Backup & Restauración (solo admin)
        Route::middleware('check.admin')->group(function () {
            Route::get('/backup',                       [BackupController::class, 'index'])->name('backup.index');
            Route::post('/backup/crear',                [BackupController::class, 'crear'])->name('backup.crear');
            Route::get('/backup/descargar/{nombre}',    [BackupController::class, 'descargar'])->name('backup.descargar');
            Route::delete('/backup/eliminar/{nombre}',  [BackupController::class, 'eliminar'])->name('backup.eliminar');
            Route::post('/backup/restaurar',            [BackupController::class, 'restaurar'])->name('backup.restaurar');
            Route::post('/backup/resetear',             [BackupController::class, 'resetear'])->name('backup.resetear');
        });

        // Auditoría (solo admin)
        Route::middleware('check.admin')->group(function () {
            Route::get('/auditoria', [AuditoriaController::class, 'index'])->name('auditoria.index');
        });

        // Comisiones de técnicos (solo admin)
        Route::middleware('check.admin')->group(function () {
            Route::get('/comisiones', [\App\Http\Controllers\ComisionController::class, 'index'])->name('comisiones.index');
            Route::post('/comisiones/{reparacion}/pagar', [\App\Http\Controllers\ComisionController::class, 'pagar'])->name('comisiones.pagar');
            Route::post('/comisiones/tecnico/{tecnico}/pagar-todo', [\App\Http\Controllers\ComisionController::class, 'pagarTodo'])->name('comisiones.pagar-todo');
        });

        // Gastos Fijos (solo admin)
        Route::middleware('check.admin')->group(function () {
            Route::get('/gastos', [\App\Http\Controllers\GastoFijoController::class, 'index'])->name('gastos.index');
            Route::post('/gastos', [\App\Http\Controllers\GastoFijoController::class, 'store'])->name('gastos.store');
            Route::put('/gastos/{gasto}', [\App\Http\Controllers\GastoFijoController::class, 'update'])->name('gastos.update');
            Route::patch('/gastos/{gasto}/toggle', [\App\Http\Controllers\GastoFijoController::class, 'toggle'])->name('gastos.toggle');
            Route::delete('/gastos/{gasto}', [\App\Http\Controllers\GastoFijoController::class, 'destroy'])->name('gastos.destroy');
        });

        // API interna para búsqueda de clientes (autocompletado en formularios)
        Route::get('/api/clientes/buscar', function () {
            $clientes = \App\Models\Cliente::where('activo', true)
                ->when(request('q'), fn($q, $buscar) =>
                    $q->where(function ($sub) use ($buscar) {
                        $sub->where('nombre', 'like', "%$buscar%")
                            ->orWhere('apellido', 'like', "%$buscar%")
                            ->orWhere('telefono', 'like', "%$buscar%")
                            ->orWhere('celular', 'like', "%$buscar%")
                            ->orWhere('dni', 'like', "%$buscar%");
                    })
                )
                ->orderBy('nombre')
                ->orderBy('apellido')
                ->limit(15)
                ->get(['id', 'nombre', 'apellido', 'telefono', 'celular', 'dni']);

            return response()->json($clientes);
        })->name('api.clientes.buscar');

        // API interna para búsqueda de productos (para el formulario de ventas)
        Route::get('/api/productos/buscar', function () {
            $productos = \App\Models\Producto::with(['marca'])
                ->where('activo', true)
                ->where('stock', '>', 0)
                ->when(request('q'), fn($q, $buscar) =>
                    $q->where('nombre', 'like', "%$buscar%")
                      ->orWhere('codigo', 'like', "%$buscar%")
                      ->orWhere('codigo_barras', 'like', "%$buscar%")
                )
                ->limit(10)
                ->get(['id', 'nombre', 'codigo', 'codigo_barras', 'precio_venta', 'stock', 'marca_id']);

            return response()->json($productos);
        })->name('api.productos.buscar');

        // API interna para datos del dashboard (AJAX)
        Route::get('/api/dashboard/ventas-semana', function () {
            $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
            $fechaExpr = $driver === 'pgsql'
                ? "TO_CHAR(fecha_venta, 'YYYY-MM-DD')"
                : 'DATE(fecha_venta)';

            $datos = \App\Models\Venta::select(
                    \Illuminate\Support\Facades\DB::raw("$fechaExpr as fecha"),
                    \Illuminate\Support\Facades\DB::raw('SUM(total) as total')
                )
                ->where('estado', 'completada')
                ->where('fecha_venta', '>=', \Carbon\Carbon::now()->subDays(6)->startOfDay())
                ->groupBy(\Illuminate\Support\Facades\DB::raw($fechaExpr))
                ->orderBy('fecha')
                ->get();

            return response()->json($datos);
        })->name('api.dashboard.ventas');
    });
});
