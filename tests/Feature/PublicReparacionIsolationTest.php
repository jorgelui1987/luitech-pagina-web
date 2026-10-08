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
 *  - Pantalla de Sala de Espera (/pantalla y /pantalla/data)
 *  - Consulta Express (/r/{numero_orden})
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

    public function test_pantalla_data_sin_empresa_identificada_responde_vacio(): void
    {
        // Sin sesión y con host localhost (dominio principal): la pantalla
        // NO debe "adivinar" la empresa con actividad más reciente.
        $response = $this->get(route('public.pantalla.data'));

        $response->assertOk();
        $data = $response->json();

        $this->assertSame(['listos' => 0, 'proceso' => 0], $data['counts']);
        $this->assertCount(0, $data['listos']);
        $this->assertCount(0, $data['proceso']);
    }

    public function test_pantalla_data_muestra_solo_ordenes_del_usuario_autenticado(): void
    {
        $this->actingAs($this->user1);

        $data = $this->get(route('public.pantalla.data'))->json();
        $codigos = $this->codigosVisibles($data);

        // Privacidad en sala: códigos enmascarados (···-XXXX), nunca completos
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

    public function test_pantalla_data_parametro_tienda_filtra_por_tenant(): void
    {
        $data = $this->get(route('public.pantalla.data', ['tienda' => $this->tenant2->id]))->json();
        $codigos = $this->codigosVisibles($data);

        $this->assertContains(
            \App\Http\Controllers\PublicReparacionController::enmascararCodigo($this->ordenTenant2->numero_orden),
            $codigos
        );
        $this->assertNotContains($this->ordenTenant2->numero_orden, $codigos);
        $this->assertNotContains(
            \App\Http\Controllers\PublicReparacionController::enmascararCodigo($this->ordenTenant1->numero_orden),
            $codigos
        );
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
        $data = $this->get(route('public.pantalla.mi-turno', ['codigo' => 'RPT-000003-A2B4']))->json();

        $this->assertTrue($data['ok']);
        $this->assertSame('RPT-000003-A2B4', $data['orden']['codigo']);
        $this->assertArrayNotHasKey('cliente', $data['orden']);
    }

    public function test_mi_turno_sin_sufijo_no_revela_orden_con_sufijo(): void
    {
        $data = $this->get(route('public.pantalla.mi-turno', ['codigo' => '000003']))->json();

        $this->assertFalse($data['ok']);
    }

    public function test_mi_turno_de_otra_empresa_responde_no_encontrada(): void
    {
        $this->actingAs($this->user2);

        $data = $this->get(route('public.pantalla.mi-turno', ['codigo' => 'RPT-000003-A2B4']))->json();

        $this->assertFalse($data['ok']);
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
        // QR de la boleta: dominio principal (sin subdominio ni sesión) muestra
        // la orden consultada y con la configuración de la empresa dueña.
        $response = $this->get(route('reparaciones.public-status', $this->ordenTenant1->numero_orden));

        $response->assertOk();
        $response->assertSee($this->ordenTenant1->numero_orden, false);
    }

    public function test_pantalla_sin_tienda_muestra_aviso(): void
    {
        $response = $this->get(route('public.pantalla'));

        $response->assertOk();
        $response->assertSee('Pantalla sin tienda asignada', false);
    }

    public function test_pantalla_por_slug_renderiza_con_su_tienda(): void
    {
        $response = $this->get(route('public.pantalla', ['slug' => 'tienda-uno']));

        $response->assertOk();
        $response->assertSee('tienda-uno', false);
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
