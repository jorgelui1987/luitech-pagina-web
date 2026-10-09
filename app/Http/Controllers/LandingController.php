<?php

namespace App\Http\Controllers;

/**
 * Landing pública de la plataforma (dominio principal).
 * El branding es de la PLATAFORMA (LUITECH), no de un tenant:
 * toma los valores del .env (PLATFORM_*) o los valores por defecto.
 *
 * Se usa un controlador en vez de closures en las rutas para que
 * `php artisan optimize` (route:cache) funcione correctamente.
 */
class LandingController extends Controller
{
    public function index()
    {
        if (auth()->check()) {
            return redirect()->route('dashboard');
        }

        $planes = \App\Models\PlanPrecio::getPlanesActivos();

        return view('landing', ['empresa' => self::branding(), 'planes' => $planes]);
    }

    public function planes()
    {
        $planes = \App\Models\PlanPrecio::getPlanesActivos();

        return view('landing', ['empresa' => self::branding(), 'planes' => $planes, 'abrirPlanes' => true]);
    }

    public static function branding(): object
    {
        return new class {
            public string $nombre_tienda;
            public ?string $direccion;
            public ?string $telefono;
            public ?string $email;
            public ?string $logo;

            public function __construct()
            {
                $this->nombre_tienda = env('PLATFORM_BRAND_NAME', 'LUITECH');
                $this->direccion     = env('PLATFORM_DIRECCION', "Bernardo O'Higgins 564, La Serena");
                $this->telefono      = env('PLATFORM_TELEFONO', '+56 9 8220 9690');
                $this->email         = env('PLATFORM_EMAIL', 'contacto@luitech.cl');
                $this->logo          = null;
            }

            // Cualquier otra propiedad que una vista pida devuelve null
            // (evita "Undefined property" → 500 en producción)
            public function __get($name)
            {
                return null;
            }

            public function __isset($name)
            {
                return false;
            }
        };
    }
}
