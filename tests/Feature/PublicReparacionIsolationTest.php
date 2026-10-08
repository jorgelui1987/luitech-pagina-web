<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Cliente;
use App\Models\Reparacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifica el aislamiento entre empresas en las rutas públicas:
 *  - Pantalla de Sala de Espera (/pantalla/{slug} y /pantalla/data/{slug})
 *  - Consulta Express (/r/{numero_orden} y /r/{slug}/{numero_orden})
 * REGLA SAAS: toda ruta pública sin login lleva la empresa en la URL (slug).
 */
class PublicReparacionIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant1;
    private Tenant $tenant2;
    private User $user1;
    private User $user2;
    private Reparacion $ordenTenant1;
    private Reparacion $ordenTenant2;
    private Reparacion $ordenConSufijo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant1 = Tenant::create([
            'empresa'        => 'Tienda Uno',
            'subdominio'     => 'tienda1',
            'slug_publico'   => 'tienda-uno',
            'email_contacto' => 'tienda1@test.com',
            'plan'           => 'basico',
            'estado'         => 'activo',
        ]);

        $this->tenant2 = Tenant::create([
            'empresa'        => 'Tienda Dos',
            'subdominio'     => 'tienda2',
            'slug_publico'   => 'tienda-dos',
            'email_contacto' => 'tienda2@test.com',
            'plan'           => 'basico',
            'estado'         => 'activo',
        ]);

        $this->user1 = User::create([
            'name'       => 'Admin Uno',
            'email'      => 'admin1@test.com',
            'password'   => bcrypt('password123'),
            'rol'        => 'admin',
            'activo'     => true,
            'tenant_id'  => $this->tenant1->id,
        ]);

        $this->user2 = User::create([
            'name'       => 'Admin Dos',
            'email'      => 'admin2@test.com',
            'password'   => bcrypt('password123'),
            'rol'        => 'admin',
            'activo'     => true,
            'tenant_id'  => $this->tenant2->id,
        ]);

        $cliente1 = Cliente::create([
            'nombre'    => 'Cliente Uno',
            'apellido'  => 'Test',
            'telefono'  => '111111111',
            'tenant_id' => $this->tenant1->id,
        ]);

        $cliente2 = Cliente::create([
            'nombre'    => 'Cliente Dos',
            'apellido'  => 'Test',
            'telefono'  => '222222222',
            'tenant_id' => $this->tenant2->id,
        ]);

        $this->ordenTenant1 = Reparacion::create([
            'numero_orden'     => 'RPT-000001',
            'cliente_id'       => $cliente1->id,
            'dispositivo'      => 'iPhone 12',
            'falla_reportada'  => 'No enciende',
            'estado'           => 'en_reparacion',
            'prioridad'        => 'media',
            'fecha_recepcion'  => now(),
            'tenant_id'        => $this->tenant1->id,
        ]);

        $this->ordenTenant2 = Reparacion::create([
            'numero_orden'     => 'RPT-000002',
            'cliente_id'       => $cliente2->id,
            'dispositivo'      => 'Galaxy S21',
            'falla_reportada'  => 'Pantalla rota',
            'estado'           => 'listo',
            'prioridad'        => 'media',
            'fecha_recepcion'  => now(),
            'tenant_id'        => $this->tenant2->id,
        ]);

        // Orden nueva con sufijo anti-adivinanza (formato nuevo)
        $this->ordenConSufijo = Reparacion::create([
            'numero_orden'     => 'RPT-000003-A2B4',
            'cliente_id'       => $cliente1->id,
            'dispositivo'      => 'Xiaomi Redmi Note 12',
            'falla_reportada'  => 'No carga',
            'estado'           => 'en_reparacion',
            'prioridad'        => 'media',
            'fecha_recepcion'  => now(),
            'tenant_id'        => $this->tenant1->id,
        ]);
    }

    private function codigosVisibles(array $data): array
    {
        return collect($data['listos'])
            ->merge($data['proceso'])
            ->pluck('codigo')
            ->all();
    }

    private function detalleEnmascarado(array $data): array
    {
        return collect($data['listos'])->merge($data['proceso'])->all();
    }

    // ── TESTS ──

    public function test_pantalla_sin_slug_responde_404(): void
    {
        // REGLA SAAS: /pantalla sin slug NO adivina empresa (ni por ?tienda=,
        // ni sesión, ni actividad): responde 404 con la instrucción.
        $response = $this->get('/pantalla');

        $response->assertNotFound();
    }

    public function test_pantalla_data_sin_slug_responde_404(): void
    {
        $response = $this->get('/pantalla/data');

        $response->assertNotFound();
    }

    public function test_pantalla_data_por_slug_filtra_por_tenant(): void
    {
        $data = $this->get(route('public.pantalla.data', ['slug' => 'tienda-uno']))->json();
        $codigos = $this->codigosVisibles($data);

        $this->assertContains(
            \App\Http\Controllers\PublicReparacionController::enmascararCodigo($this->ordenTenant1->numero_orden),
            $codigos
        );
        $this->assertNotContains($this->ordenTenant1->numero_orden, $codigos);
        $this->assertNotContains(
            \App\Http\Controllers\PublicReparacionController::enmascararCodigo($this->ordenTenant2->numero_orden),
            $codigos
        );
    }

    public function test_pantalla_data_sesion_de_otro_tecnico_no_cambia_la_tienda(): void
    {
        // La TV es compartida: la sesión abierta del técnico NO decide qué ve.
        // Solo el slug manda: tienda-uno muestra solo tienda-uno.
        $this->actingAs($this->user2);

        $data = $this->get(route('public.pantalla.data', ['slug' => 'tienda-uno']))->json();
        $codigos = $this->codigosVisibles($data);

        $this->assertContains(
            \App\Http\Controllers\PublicReparacionController::enmascararCodigo($this->ordenTenant1->numero_orden),
            $codigos
        );
        $this->assertNotContains(
            \App\Http\Controllers\PublicReparacionController::enmascararCodigo($this->ordenTenant2->numero_orden),
            $codigos
        );
    }

    public function test_pantalla_data_no_expone_detalle_de_equipo_ajeno(): void
    {
        $data = $this->get(route('public.pantalla.data', ['slug' => 'tienda-uno']))->json();
        $items = $this->detalleEnmascarado($data);

        $this->assertNotEmpty($items);
        foreach ($items as $item) {
            // Solo tipo genérico ("Celular", "Equipo"...), jamás marca/modelo
            $this->assertStringNotContainsString('iPhone', (string) ($item['equipo'] ?? ''));
            $this->assertStringNotContainsString('Xiaomi', (string) ($item['equipo'] ?? ''));
            $this->assertStringStartsWith('···-', (string) ($item['codigo'] ?? ''));
        }
    }

    public function test_mi_turno_devuelve_solo_la_orden_consultada(): void
    {
        $data = $this->get(route('public.pantalla.mi-turno', ['slug' => 'tienda-uno', 'codigo' => 'RPT-000003-A2B4']))->json();

        $this->assertTrue($data['ok']);
        $this->assertSame('RPT-000003-A2B4', $data['orden']['codigo']);
        $this->assertArrayNotHasKey('cliente', $data['orden']);
    }

    public function test_mi_turno_sin_sufijo_no_revela_orden_con_sufijo(): void
    {
        $data = $this->get(route('public.pantalla.mi-turno', ['slug' => 'tienda-uno', 'codigo' => '000003']))->json();

        $this->assertFalse($data['ok']);
    }

    public function test_mi_turno_de_otra_empresa_responde_no_encontrada(): void
    {
        $this->actingAs($this->user2);

        $data = $this->get(route('public.pantalla.mi-turno', ['slug' => 'tienda-dos', 'codigo' => 'RPT-000003-A2B4']))->json();

        $this->assertFalse($data['ok']);
    }

    public function test_mi_turno_sin_slug_responde_404(): void
    {
        $response = $this->get('/pantalla/mi-turno?codigo=RPT-000003-A2B4');

        $response->assertNotFound();
    }

    public function test_consulta_por_tienda_muestra_solo_su_orden(): void
    {
        $response = $this->get(route('reparaciones.public-status.tienda', [
            'slug' => 'tienda-uno',
            'numero_orden' => $this->ordenTenant1->numero_orden,
        ]));

        $response->assertOk();
        $response->assertSee($this->ordenTenant1->numero_orden, false);
    }

    public function test_consulta_por_tienda_no_muestra_orden_de_otra_empresa(): void
    {
        $response = $this->get(route('reparaciones.public-status.tienda', [
            'slug' => 'tienda-dos',
            'numero_orden' => $this->ordenTenant1->numero_orden,
        ]));

        $response->assertOk();
        $response->assertSee('no fue encontrada', false);
    }

    public function test_portal_por_tienda_conserva_el_slug(): void
    {
        $response = $this->get(route('reparaciones.public-status.search', ['slug' => 'tienda-uno']));

        $response->assertOk();
        $response->assertSee('name="slugTienda" value="tienda-uno"', false);
    }

    public function test_busqueda_aislada_por_tienda_no_muestra_orden_ajena(): void
    {
        // Cliente en el portal de tienda-dos busca una orden de tienda-uno
        $response = $this->get(route('reparaciones.public-status.search', [
            'slug' => 'tienda-dos',
            'numero_orden' => $this->ordenTenant1->numero_orden,
            'slugTienda' => 'tienda-dos',
        ]));

        $response->assertOk();
        $response->assertSee('no fue encontrada', false);
    }

    public function test_consulta_desde_subdominio_no_muestra_ordenes_de_otra_empresa(): void
    {
        // Orden de la tienda 1 consultada desde el portal de la tienda 2
        $response = $this->get('http://tienda2.localhost/r/' . $this->ordenTenant1->numero_orden);

        $response->assertOk();
        $response->assertSee('no fue encontrada', false);
    }

    public function test_consulta_desde_sesion_de_otra_tienda_no_muestra_la_orden(): void
    {
        $this->actingAs($this->user2);

        $response = $this->get(route('reparaciones.public-status', $this->ordenTenant1->numero_orden));

        $response->assertOk();
        $response->assertSee('no fue encontrada', false);
    }

    public function test_consulta_en_dominio_principal_muestra_la_orden_de_su_empresa(): void
    {
        // QR de la boleta legacy (sin slug): dominio principal (sin subdominio
        // ni sesión) muestra la orden consultada con su tienda.
        $response = $this->get(route('reparaciones.public-status', $this->ordenTenant1->numero_orden));

        $response->assertOk();
        $response->assertSee($this->ordenTenant1->numero_orden, false);
    }

    public function test_pantalla_por_slug_renderiza_con_su_tienda(): void
    {
        $response = $this->get(route('public.pantalla', ['slug' => 'tienda-uno']));

        $response->assertOk();
        $response->assertSee('tienda-uno', false);
    }

    public function test_consulta_en_dominio_principal_sigue_funcionando_legacy(): void
    {
        // Compatibilidad con QR/boletas antiguas sin slug: dominio principal
        // (sin subdominio ni sesión) muestra la orden con su tienda.
        // (Duplicado intencional del legacy: si se endurece /r/ genérico,
        // este test marca qué boletas antiguas se verían afectadas.)
        $response = $this->get(route('reparaciones.public-status', $this->ordenTenant1->numero_orden));

        $response->assertOk();
        $response->assertSee($this->ordenTenant1->numero_orden, false);
    }

    public function test_qr_nuevo_apunta_a_ruta_con_slug(): void
    {
        $url = $this->tenant1->urlSeguimientoOrden($this->ordenTenant1->numero_orden);

        $this->assertStringContainsString('/r/tienda-uno/' . $this->ordenTenant1->numero_orden, $url);
    }

    public function test_pantalla_de_cada_tienda_tiene_su_url(): void
    {
        $this->assertStringContainsString(
            '/pantalla/tienda-uno',
            (string) $this->tenant1->urlPantalla()
        );
        $this->assertStringContainsString(
            '/pantalla/tienda-dos',
            (string) $this->tenant2->urlPantalla()
        );
    }

    public function test_codigo_con_sufijo_acepta_formatos_flexibles(): void
    {
        // Formatos que el cliente puede escribir: completo, sin RPT-, sin guiones
        foreach (['RPT-000003-A2B4', '000003-A2B4', 'rpt000003a2b4', 'RPT000003A2B4'] as $formato) {
            $response = $this->get(route('reparaciones.public-status', $formato));

            $response->assertOk();
            $response->assertSee('RPT-000003-A2B4', false);
        }
    }

    public function test_codigo_sin_sufijo_no_encuentra_orden_con_sufijo(): void
    {
        // Protección anti-adivinanza: la base sola no expone la orden
        $response = $this->get(route('reparaciones.public-status', '000003'));

        $response->assertOk();
        $response->assertSee('no fue encontrada', false);
    }

    public function test_orden_antigua_sin_sufijo_sigue_consultable(): void
    {
        $response = $this->get(route('reparaciones.public-status', '000001'));

        $response->assertOk();
        $response->assertSee($this->ordenTenant1->numero_orden, false);
    }
}
