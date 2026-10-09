<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanPrecio extends Model
{
    protected $table = 'plan_precios';

    protected $fillable = [
        'plan_key',
        'nombre',
        'precio_mensual',
        'moneda',
        'simbolo',
        'descripcion',
        'activo',
    ];

    protected $casts = [
        'precio_mensual' => 'decimal:2',
        'activo' => 'boolean',
    ];

    /**
     * Obtener todos los planes activos con sus precios.
     * Si la tabla no existe, retorna valores por defecto (CLP, mercado Chile).
     */
    public static function getPlanesActivos()
    {
        try {
            return static::where('activo', true)->get()->keyBy('plan_key');
        } catch (\Exception $e) {
            // Si la tabla no existe, devolver valores por defecto (CLP)
            $defaults = [
                'gratis' => (object)[
                    'plan_key' => 'gratis',
                    'nombre' => 'Gratis',
                    'precio_mensual' => 0,
                    'moneda' => 'CLP',
                    'simbolo' => '$',
                    'descripcion' => 'Para empezar',
                    'precioFormateado' => function() { return '$0'; },
                ],
                'basico' => (object)[
                    'plan_key' => 'basico',
                    'nombre' => 'Básico',
                    'precio_mensual' => 9990,
                    'moneda' => 'CLP',
                    'simbolo' => '$',
                    'descripcion' => 'Para negocios pequeños',
                    'precioFormateado' => function() { return '$9.990'; },
                ],
                'profesional' => (object)[
                    'plan_key' => 'profesional',
                    'nombre' => 'Profesional',
                    'precio_mensual' => 19990,
                    'moneda' => 'CLP',
                    'simbolo' => '$',
                    'descripcion' => 'Para negocios en crecimiento',
                    'precioFormateado' => function() { return '$19.990'; },
                ],
                'empresarial' => (object)[
                    'plan_key' => 'empresarial',
                    'nombre' => 'Empresarial',
                    'precio_mensual' => 39990,
                    'moneda' => 'CLP',
                    'simbolo' => '$',
                    'descripcion' => 'Para grandes tiendas',
                    'precioFormateado' => function() { return '$39.990'; },
                ],
            ];
            return collect($defaults);
        }
    }

    /**
     * Obtener el precio formateado de un plan.
     * CLP sin decimales con punto de miles (es-CL).
     */
    public function precioFormateado()
    {
        if ($this->precio_mensual == 0) {
            return $this->simbolo . '0';
        }
        if (($this->moneda ?? 'CLP') === 'CLP') {
            return $this->simbolo . number_format($this->precio_mensual, 0, ',', '.');
        }
        return $this->simbolo . number_format($this->precio_mensual, $this->precio_mensual == floor($this->precio_mensual) ? 0 : 2);
    }
}
