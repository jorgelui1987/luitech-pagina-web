<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = [
        'empresa',
        'subdominio',
        'slug_publico',
        'dominio',
        'email_contacto',
        'telefono_contacto',
        'plan',
        'estado',
        'logo',
        'pais',
        'moneda',
        'simbolo_moneda',
        'impuesto',
        'max_usuarios',
        'max_productos',
        'fecha_expiracion',
        'configuracion_extra',
        'redes_sociales',
        'descripcion_corta',
        'horario_atencion',
    ];

    protected $casts = [
        'fecha_expiracion' => 'datetime',
        'configuracion_extra' => 'array',
        'redes_sociales' => 'array',
        'max_usuarios' => 'integer',
        'max_productos' => 'integer',
        'impuesto' => 'decimal:2',
    ];

    // ─── Relaciones ────────────────────────────────────────────────
    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function configuracion(): HasMany
    {
        return $this->hasMany(Configuracion::class);
    }

    public function clientes(): HasMany
    {
        return $this->hasMany(Cliente::class);
    }

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class);
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class);
    }

    public function reparaciones(): HasMany
    {
        return $this->hasMany(Reparacion::class);
    }

    public function cupones(): HasMany
    {
        return $this->hasMany(Cupon::class);
    }

    public function resenas(): HasMany
    {
        return $this->hasMany(Resena::class);
    }

    // ─── Scopes ────────────────────────────────────────────────────
    public function scopeActivo($query)
    {
        return $query->where('estado', 'activo');
    }

    // ─── Helpers ───────────────────────────────────────────────────
    public function estaActivo(): bool
    {
        return $this->estado === 'activo';
    }

    public function estaSuspendido(): bool
    {
        return $this->estado === 'suspendido';
    }

    public function haExpirado(): bool
    {
        return $this->fecha_expiracion && $this->fecha_expiracion->isPast();
    }

    public function puedeAgregarUsuario(): bool
    {
        return $this->usuarios()->count() < $this->max_usuarios;
    }

    public function puedeAgregarProducto(): bool
    {
        return $this->productos()->count() < $this->max_productos;
    }

    public function getUrlPublicaAttribute(): ?string
    {
        if (!$this->slug_publico) {
            return null;
        }
        return url('/t/' . $this->slug_publico);
    }

    /**
     * URL pública de seguimiento de una orden SIEMPRE con slug de ESTA tienda:
     * /r/{slug}/{numero_orden}. Regla SaaS: sin slug el QR/link genérico
     * (/r/{codigo}) puede mostrar la orden fuera de su tienda en el dominio
     * principal. Si no hay slug, cae al genérico (boletas legacy).
     */
    public function urlSeguimientoOrden(string $numeroOrden): string
    {
        if ($this->slug_publico) {
            return route('reparaciones.public-status.tienda', [
                'slug' => $this->slug_publico,
                'numero_orden' => $numeroOrden,
            ]);
        }

        return route('reparaciones.public-status', $numeroOrden);
    }

    /**
     * URL de la pantalla de sala de espera de ESTA tienda (/pantalla/{slug}).
     * Sin slug no hay URL: la ruta /pantalla sin slug responde 404.
     */
    public function urlPantalla(): ?string
    {
        if (!$this->slug_publico) {
            return null;
        }

        return route('public.pantalla', ['slug' => $this->slug_publico]);
    }

    /**
     * Obtiene el tenant actual basado en el subdominio/dominio.
     */
    public static function current(): ?self
    {
        $host = request()->getHost();
        $subdominio = explode('.', $host)[0] ?? null;

        if (!$subdominio || $subdominio === 'www' || $subdominio === 'localhost' || $subdominio === '127') {
            // Es el panel principal / superadmin
            return null;
        }

        return static::where('subdominio', $subdominio)
            ->orWhere('dominio', $host)
            ->first();
    }

    /**
     * Obtiene el tenant_id actual (para usar en scopes).
     */
    public static function currentId(): ?int
    {
        $tenant = static::current();
        return $tenant?->id;
    }

    /**
     * Genera un slug público único a partir de un texto base.
     * Si el slug ya existe agrega sufijos: -2, -3, ...
     * Se usa para la URL pública de la Sala de Espera: /pantalla/{slug}.
     */
    public static function generarSlugUnico(?string $base): ?string
    {
        $base = trim((string) $base);
        if ($base === '') {
            return null;
        }

        $slug  = \Illuminate\Support\Str::slug($base) ?: 'tienda';
        $unico = $slug;
        $i     = 2;

        while (static::withoutGlobalScopes()->where('slug_publico', $unico)->exists()) {
            $unico = $slug . '-' . $i++;
        }

        return $unico;
    }

    /**
     * Colores de marca de la empresa (guardados en configuracion_extra)
     * con contraste automático:
     *  - primario/secundario: versión SIEMPRE legible (auto-oscurecida si el
     *    color elegido era muy claro) → textos, enlaces e iconos.
     *  - primario_puro/secundario_puro: el color tal cual lo eligió el dueño
     *    → para FONDOS (botones, gradientes, hero).
     *  - texto_sobre_primario: blanco o negro según luminancia (WCAG).
     */
    public function colores(): array
    {
        $extra = $this->configuracion_extra ?? [];

        $puroP = self::normalizarHex($extra['color_primario']   ?? '#0891b2');
        $puroS = self::normalizarHex($extra['color_secundario'] ?? '#3b82f6');

        return [
            'primario'               => self::hexLegible($puroP),
            'secundario'             => self::hexLegible($puroS),
            'primario_puro'          => $puroP,
            'secundario_puro'        => $puroS,
            'texto_sobre_primario'   => self::textoSobre($puroP),
            'texto_sobre_secundario' => self::textoSobre($puroS),
            'primario_rgba'          => self::hexARgba(self::hexLegible($puroP), 0.18),
        ];
    }

    /**
     * Normaliza cualquier entrada a #rrggbb (minúsculas).
     * Formato inválido → color por defecto del tema Luitech.
     */
    public static function normalizarHex(string $hex): string
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = '0891b2';
        }

        // SIEMPRE con numeral: los consumos en vistas necesitan '#rrggbb'
        return '#' . strtolower($hex);
    }

    /**
     * Luminancia relativa WCAG (0 = negro, 1 = blanco).
     */
    public static function luminancia(string $hex): float
    {
        $hex = self::normalizarHex($hex);
        $lin = function ($componente) {
            $c = hexdec($componente) / 255;

            return ($c <= 0.03928) ? $c / 12.92 : pow((($c + 0.055) / 1.055), 2.4);
        };

        return 0.2126 * $lin(substr($hex, 0, 2))
             + 0.7152 * $lin(substr($hex, 2, 2))
             + 0.0722 * $lin(substr($hex, 4, 2));
    }

    /**
     * Color de TEXTO legible sobre un fondo del color dado:
     * fondo claro → texto oscuro · fondo oscuro → texto blanco.
     */
    public static function textoSobre(string $hex): string
    {
        return self::luminancia($hex) > 0.45 ? '#0f172a' : '#ffffff';
    }

    /**
     * Oscurece un hex hasta que su luminancia sea legible sobre blanco
     * (usado para textos/enlaces de marca cuando el dueño elige colores claros).
     */
    public static function hexLegible(string $hex): string
    {
        $hex    = self::normalizarHex($hex);
        $factor = 0.0;

        while (self::luminancia($hex) > 0.45 && $factor < 0.9) {
            $factor += 0.15;
            $hex = self::oscurecerHex($hex, 0.15);
        }

        return $hex;
    }

    /**
     * Oscurece un color hex (para texto/bordes legibles sobre blanco).
     * Factor 0.3 = 30% más oscuro. Formato inválido → color por defecto.
     */
    public static function oscurecerHex(string $hex, float $factor = 0.3): string
    {
        $hex = ltrim(self::normalizarHex($hex), '#');

        return sprintf(
            '#%02x%02x%02x',
            (int) max(0, round(hexdec(substr($hex, 0, 2)) * (1 - $factor))),
            (int) max(0, round(hexdec(substr($hex, 2, 2)) * (1 - $factor))),
            (int) max(0, round(hexdec(substr($hex, 4, 2)) * (1 - $factor)))
        );
    }

    /**
     * Convierte #RRGGBB a rgba(r,g,b,alpha).
     * Formato inválido → color por defecto del tema Luitech.
     */
    public static function hexARgba(string $hex, float $alpha = 1.0): string
    {
        $hex = ltrim(self::normalizarHex($hex), '#');

        return sprintf(
            'rgba(%d,%d,%d,%.2f)',
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
            $alpha
        );
    }
}
