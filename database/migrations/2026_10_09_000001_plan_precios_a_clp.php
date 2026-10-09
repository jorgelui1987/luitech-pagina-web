<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Planes SaaS a CLP (mercado Chile).
     * Corrige BDs sembradas con PEN/S/ (Peru).
     */
    public function up()
    {
        if (!Schema::hasTable('plan_precios')) {
            return;
        }

        $planes = [
            'gratis'      => ['precio' => 0,     'moneda' => 'CLP', 'simbolo' => '$', 'descripcion' => 'Para empezar'],
            'basico'      => ['precio' => 9990,  'moneda' => 'CLP', 'simbolo' => '$', 'descripcion' => 'Para negocios pequeños'],
            'profesional' => ['precio' => 19990, 'moneda' => 'CLP', 'simbolo' => '$', 'descripcion' => 'Para negocios en crecimiento'],
            'empresarial' => ['precio' => 39990, 'moneda' => 'CLP', 'simbolo' => '$', 'descripcion' => 'Para grandes tiendas'],
        ];

        foreach ($planes as $key => $d) {
            DB::table('plan_precios')->where('plan_key', $key)->update([
                'precio_mensual' => $d['precio'],
                'moneda'         => $d['moneda'],
                'simbolo'        => $d['simbolo'],
                'descripcion'    => $d['descripcion'],
                'updated_at'     => now(),
            ]);
        }
    }

    public function down()
    {
        // Sin reversa: los precios se gestionan desde /superadmin/planes-precios.
    }
};
