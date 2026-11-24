<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Newsletter | Zapatería Angelita</title>
</head>
<body style="margin:0;background:#f6f5f4;color:#171717;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f5f4;">
    <tr><td align="center" style="padding:28px 14px;">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#fff;border:1px solid #eee;border-radius:16px;">
            <tr><td align="center" style="padding:26px 20px;border-bottom:3px solid #e30613;">
                <img src="{{ app(\App\Services\BrandingService::class)->logoUrl() }}" alt="Zapatería Angelita" width="210" style="display:block;max-width:100%;height:auto;border:0;">
            </td></tr>
            <tr><td style="padding:28px;">
                @if ($welcome)
                    <h1 style="font-size:23px;margin:0 0 15px;">¡Gracias por suscribirte!</h1>
                    <p style="font-size:14px;line-height:1.7;color:#555;">Tu suscripción quedó confirmada. Recibirás un resumen mensual de Zapatería Angelita cuando tengamos novedades u ofertas para compartir.</p>
                @else
                    <h1 style="font-size:23px;margin:0 0 15px;">Novedades y ofertas del mes</h1>
                    @foreach (['Recién llegados' => $newProducts, 'Ofertas disponibles' => $offers] as $heading => $products)
                        @if ($products->isNotEmpty())
                            <h2 style="font-size:17px;">{{ $heading }}</h2>
                            <ul>
                                @foreach ($products as $product)
                                    <li><a style="color:#a60018;" href="{{ $storeUrl }}/producto/{{ $product->id }}">{{ $product->name }}</a></li>
                                @endforeach
                            </ul>
                        @endif
                    @endforeach
                @endif
                <table role="presentation" align="center" cellspacing="0" cellpadding="0" style="margin:24px auto 8px;">
                    <tr><td bgcolor="#c90023" style="border-radius:9px;">
                        <a href="{{ $storeUrl }}/catalogo" style="display:inline-block;padding:13px 25px;color:#fff;font-size:14px;font-weight:bold;text-decoration:none;">Ver catálogo</a>
                    </td></tr>
                </table>
            </td></tr>
            <tr><td align="center" style="padding:15px;background:#f8f8f8;color:#555;font-size:12px;">
                © {{ date('Y') }} Zapatería Angelita · <a style="color:#8a0010;" href="{{ $unsubscribeUrl }}">Cancelar suscripción</a>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
