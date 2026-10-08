@php
    $empresa = \App\Models\Configuracion::empresa();

    // URL correcta del logo
    $logoSrc = null;
    if ($empresa && $empresa->logo) {
        $logoPath = str_replace('storage/', '', $empresa->logo);
        if (file_exists(storage_path('app/public/' . $logoPath))) {
            $logoSrc = route('storage.serve', ['path' => $logoPath]);
        } else {
            $logoSrc = asset($empresa->logo);
        }
    }

    $estadoLabel = str_replace('_', ' ', ucfirst($reparacion->estado));
    $tipoDispositivo = ['celular'=>'Celular','tablet'=>'Tablet','portatil'=>'Portátil','otros'=>'Otros'];
    $tipoDispositivoLabel = $tipoDispositivo[$reparacion->tipo_dispositivo] ?? ($reparacion->tipo_dispositivo ?? '—');
    $nombreImpuesto = ($empresa?->pais ?? '') === 'CL' ? 'IVA' : 'IGV';
    $impuestoPct = $reparacion->porcentajeImpuesto();
    $qrUrl = $reparacion->tenant ? $reparacion->tenant->urlSeguimientoOrden($reparacion->numero_orden) : route('reparaciones.public-status', $reparacion->numero_orden);

    // Una sola firma: priorizar la de entrega
    $firmaMostrar = $reparacion->firma_entrega ?: $reparacion->firma_recepcion;

    // Colores de marca del taller (para el ticket)
    $tMarca = $reparacion->tenant?->colores() ?? ['primario' => '#0891b2', 'secundario' => '#3b82f6'];
    $marcaOscuro = \App\Models\Tenant::oscurecerHex($tMarca['primario'], 0.35);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Orden {{ $reparacion->numero_orden }} — 2 por hoja carta</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;background:#525659;padding:16px;display:flex;flex-direction:column;align-items:center}
.aviso{background:#ffd54f;color:#333;padding:8px 16px;border-radius:8px;font-size:13px;text-align:center;max-width:800px;margin-bottom:14px}

/* ═══ HOJA CARTA VERTICAL (216 × 279.4 mm) = 2 ÓRDENES DE MEDIA CARTA ═══
   Cada orden mide 216 × 139.4 mm. Se imprimen las 2 copias juntas
   (Cliente arriba, Local abajo) + línea de corte al medio.
   NUNCA se reintroducen medias hojas en la impresora → sin escalados. */
.boleta{width:216mm;height:139.4mm;background:#fff;box-shadow:0 4px 24px rgba(0,0,0,.45);padding:5mm 6mm;display:flex;flex-direction:column;overflow:hidden;page-break-inside:avoid;break-inside:avoid}

/* Línea de corte punteada entre las dos órdenes */
.linea-corte{width:216mm;height:0;border-top:1.5px dashed #b5b5b5;position:relative;flex-shrink:0}
.linea-corte .tijera{position:absolute;left:2mm;top:-9px;background:#525659;color:#ddd;font-size:12px;line-height:1;padding:0 2mm}

/* ═══ ENCABEZADO ═══ */
.encabezado{display:flex;align-items:center;justify-content:space-between;border-bottom:2.5px solid {{ $marcaOscuro }};padding-bottom:2.5mm}
.logo{width:38mm;max-height:14mm;object-fit:contain}
.tienda-bloque{text-align:left;margin-left:4mm}
.tienda-nombre{font-size:15px;font-weight:800;color:#111}
.fiscal-linea{font-size:8px;color:#444;line-height:1.35}
.doc-bloque{text-align:right}
.doc-tipo{display:inline-block;background:{{ $marcaOscuro }};color:#fff;font-size:8px;font-weight:700;letter-spacing:2px;padding:1mm 4mm;border-radius:3px}
.doc-numero{font-size:16px;font-weight:800;margin-top:.5mm;letter-spacing:.5px}
.ejemplar{display:inline-block;font-size:7px;font-weight:700;letter-spacing:1.5px;color:{{ $marcaOscuro }};border:1px solid {{ $marcaOscuro }};border-radius:3px;padding:.4mm 2mm;margin-top:1mm;text-transform:uppercase}

/* ═══ CUERPO EN 2 COLUMNAS ═══ */
.cuerpo{display:flex;gap:5mm;flex:1;margin-top:2.5mm;min-height:0}
.col-izq{flex:1.5;min-width:0}
.col-der{flex:1;display:flex;flex-direction:column}

.datos-grid{font-size:8.5px;color:#333;margin-bottom:1.5mm;line-height:1.45}
.datos-grid b{font-size:7px;text-transform:uppercase;color:#888;letter-spacing:.5px}

.seccion{font-size:7.5px;font-weight:800;text-transform:uppercase;letter-spacing:1.5px;color:{{ $marcaOscuro }};margin-bottom:1mm;border-bottom:1px solid #dde1e6;padding-bottom:.8mm}

table{width:100%;border-collapse:collapse;font-size:9px}
th{background:{{ $marcaOscuro }};color:#fff;text-align:left;padding:1.2mm 1.5mm;font-size:7px;text-transform:uppercase;letter-spacing:.5px}
td{padding:1.2mm 1.5mm;border-bottom:.5px solid #e8eaed;vertical-align:top}
td.lbl{font-size:7px;font-weight:700;text-transform:uppercase;color:#888;width:30%}
td.val{font-weight:600}

.bx{font-size:8.5px;line-height:1.45;color:#222;word-break:break-word}

.tot-fila{display:flex;justify-content:space-between;font-size:9.5px;padding:.9mm 2mm;color:#333}
.tot-final{display:flex;justify-content:space-between;background:#1e3a5f;color:#fff;font-size:14px;font-weight:800;padding:1.8mm 3mm;border-radius:4px;margin-top:1mm}

.garantia-box{font-size:7.5px;color:#555;line-height:1.4;background:#fdf9ee;border:1px solid #eadfb8;border-radius:4px;padding:1.5mm 2mm;margin-top:auto}
.notas{font-size:8px;color:#555;margin-top:1.5mm}

/* ═══ PIE ═══ */
.pie{display:flex;justify-content:space-between;align-items:center;margin-top:2.5mm;padding-top:2mm;border-top:1px solid #dde1e6;font-size:7.5px;color:#666}
.qr{width:11mm;height:11mm}
.miniweb{font-weight:700;color:#0000EE;word-break:break-all;font-size:8px}
.firma-zona{display:flex;gap:10mm;margin-right:2mm}
.firma{text-align:center;width:30mm}
.firma .linea{border-top:1px solid #333;margin-top:5mm}
.firma img{max-width:100%;max-height:10mm;object-fit:contain}
.firma .lbl{font-size:6.5px;color:#777;margin-top:.8mm;text-transform:uppercase;letter-spacing:.5px}

.btn-print{position:fixed;bottom:24px;right:24px;background:#0070e0;color:#fff;border:none;border-radius:30px;padding:14px 28px;font-size:15px;font-weight:700;cursor:pointer;box-shadow:0 4px 16px rgba(0,112,224,.4)}
@media print{
 body{display:block;background:#fff;padding:0;margin:0}
 .aviso,.btn-print{display:none!important}
 .boleta{box-shadow:none}
 /* Al imprimir la tijera desaparece; la línea punteada queda como guía de corte */
 .linea-corte .tijera{background:#fff;color:#fff}
 @page{size:216mm 279.4mm;margin:0}
}
</style>
</head>
<body>

<div class="aviso">📄 Formato <b>CARTA VERTICAL</b> · <b>2 órdenes idénticas por hoja</b> (media carta c/u: 21.6 × 13.94 cm): <b>Ejemplar Cliente</b> arriba y <b>Ejemplar Local</b> abajo. Imprime UNA vez, corta por la línea punteada. <b>No reintroduzcas medias hojas</b> en la impresora.</div>

@foreach(['Ejemplar Cliente', 'Ejemplar Local'] as $copiaLabel)
<div class="boleta">

    <!-- ═══ ENCABEZADO ═══ -->
    <div class="encabezado">
        <div style="display:flex;align-items:center">
            @if($logoSrc)<img src="{{ $logoSrc }}" alt="" class="logo">@endif
            <div class="tienda-bloque">
                <div class="tienda-nombre">{{ $empresa?->nombre_tienda ?? 'CRM Celulares' }}</div>
                <div class="fiscal-linea">
                    @if($empresa?->rut_emisor ?? $empresa?->ruc){{ ($empresa?->pais ?? '') === 'CL' ? 'R.U.T.' : 'R.U.C.' }}: {{ $empresa->rut_emisor ?? $empresa->ruc }}@endif
                    @if($empresa?->giro) · {{ $empresa->giro }}@endif
                    @if($empresa?->direccion) · {{ $empresa->direccion }} @if($empresa?->comuna_ciudad){{ ', '.$empresa->comuna_ciudad }} @endif @endif
                </div>
            </div>
        </div>
        <div class="doc-bloque">
            <span class="doc-tipo">ORDEN DE SERVICIO</span>
            <div class="doc-numero">N° {{ $reparacion->numero_orden }}</div>
            <span class="ejemplar">{{ $copiaLabel }}</span>
        </div>
    </div>

    <!-- ═══ CUERPO EN 2 COLUMNAS ═══ -->
    <div class="cuerpo">

        <div class="col-izq">
            <div class="datos-grid">
                <b>CLIENTE:</b> {{ $reparacion->cliente?->nombre_completo ?? '—' }}
                @if($reparacion->cliente?->telefono)&nbsp;&nbsp;<b>TEL:</b> {{ $reparacion->cliente->telefono }}@endif
                &nbsp;&nbsp;<b>RECEPCIÓN:</b> {{ optional($reparacion->fecha_recepcion)->format('d/m/Y H:i') }}
                &nbsp;&nbsp;<b>TÉCNICO:</b> {{ $reparacion->tecnico->name ?? '—' }}
            </div>

            <div class="seccion">Equipo recibido</div>
            <table>
                <tbody>
                    <tr><td class="lbl">Tipo</td><td>{{ $tipoDispositivoLabel }}</td></tr>
                    <tr><td class="lbl">Marca / Modelo</td><td>{{ trim(($reparacion->marca ?: '—').' '.($reparacion->modelo ?: '')) }}</td></tr>
                    @if($reparacion->imei)<tr><td class="lbl">IMEI</td><td>{{ $reparacion->imei }}</td></tr>@endif
                    @if($reparacion->color)<tr><td class="lbl">Color</td><td>{{ $reparacion->color }}</td></tr>@endif
                    @if($reparacion->fecha_estimada)<tr><td class="lbl">Entrega estimada</td><td>{{ $reparacion->fecha_estimada->format('d/m/Y') }}</td></tr>@endif
                    @if($reparacion->fecha_entrega)<tr><td class="lbl">Entregado</td><td>{{ $reparacion->fecha_entrega->format('d/m/Y') }}</td></tr>@endif
                </tbody>
            </table>

            <div class="seccion" style="margin-top:2mm">Falla reportada</div>
            <div class="bx">{{ $reparacion->falla_reportada ?: '—' }}</div>

            @if($reparacion->diagnostico)
            <div class="seccion" style="margin-top:2mm">Diagnóstico</div>
            <div class="bx">{{ $reparacion->diagnostico }}</div>
            @endif

            @if($reparacion->solucion)
            <div class="seccion" style="margin-top:2mm">Solución</div>
            <div class="bx">{{ $reparacion->solucion }}</div>
            @endif

            @if($reparacion->notas)
            <div class="notas"><b>Notas:</b> {{ $reparacion->notas }}</div>
            @endif
        </div>

        <div class="col-der">
            <div class="datos-grid" style="text-align:right"><b>ESTADO:</b> {{ $estadoLabel }}</div>

            @if($reparacion->presupuesto > 0 || $reparacion->costo_final > 0 || $reparacion->abono > 0 || $reparacion->total > 0)
            <div class="tot-fila"><span>Presupuesto</span><span>{{ $empresa?->simbolo_moneda ?? '$' }} {{ number_format($reparacion->presupuesto, 2) }}</span></div>
            @if($reparacion->costo_final > 0)
            <div class="tot-fila"><span>Costo final</span><span>{{ $empresa?->simbolo_moneda ?? '$' }} {{ number_format($reparacion->costo_final, 2) }}</span></div>
            @endif
            @if($reparacion->abono > 0)
            <div class="tot-fila"><span>Abono</span><span>-{{ $empresa?->simbolo_moneda ?? '$' }} {{ number_format($reparacion->abono, 2) }}</span></div>
            @endif
            @if($reparacion->impuesto > 0)
            @php $netoRep = round((float) $reparacion->total - (float) $reparacion->impuesto, 2); @endphp
            <div class="tot-fila"><span>NETO</span><span>{{ $empresa?->simbolo_moneda ?? '$' }} {{ number_format($netoRep, 2) }}</span></div>
            <div class="tot-fila"><span>{{ $nombreImpuesto }} ({{ $impuestoPct }}%)</span><span>{{ $empresa?->simbolo_moneda ?? '$' }} {{ number_format($reparacion->impuesto, 2) }}</span></div>
            @endif
            @if($reparacion->total > 0)
            <div class="tot-final"><span>TOTAL A PAGAR</span><span>{{ $empresa?->simbolo_moneda ?? '$' }} {{ number_format($reparacion->total, 2) }}</span></div>
            @endif
            @endif

            @if($reparacion->garantia)
            <div class="garantia-box">
                <b>🛡️ GARANTÍA:</b> {{ $reparacion->dias_garantia }} días.
                @if($empresa?->terminos_garantia){{ $empresa->terminos_garantia }}@endif
            </div>
            @endif
        </div>
    </div>

    <!-- ═══ PIE ═══ -->
    <div class="pie">
        @if($urlMiniWeb)
        <div>🌐 Visita nuestra tienda online:<br><span class="miniweb">{{ $urlMiniWeb }}</span></div>
        @else
        <div></div>
        @endif
        <div style="text-align:center">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=80x80&data={{ urlencode($qrUrl) }}" alt="QR" class="qr">
            <div style="font-size:6.5px;font-weight:700;">Escanea para ver estado</div>
        </div>
        <div style="text-align:center">
            @if($empresa?->telefono)📞 {{ $empresa->telefono }}@endif
            @if($empresa?->instagram) · 📷 {{ $empresa->instagram }}@endif
        </div>
        <div class="firma-zona">
            <div class="firma">
                @if($firmaMostrar)
                <img src="{{ asset('storage/'.$firmaMostrar) }}" alt="Firma">
                @else
                <div class="linea"></div>
                @endif
                <div class="lbl">Firma cliente</div>
            </div>
            <div class="firma"><div class="linea"></div><div class="lbl">Sello / Recepción</div></div>
        </div>
    </div>

</div>
@if(!$loop->last)
<div class="linea-corte"><span class="tijera">✂</span></div>
@endif
@endforeach

<button class="btn-print" onclick="window.print()">🖨️ Imprimir 2 órdenes en 1 hoja carta</button>

<script>window.onload=function(){window.print()};window.onafterprint=function(){window.close()};</script>
</body>
</html>