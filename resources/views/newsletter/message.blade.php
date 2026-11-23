<!doctype html>
<html lang="es">
<meta charset="utf-8">
<body style="font-family:Arial,sans-serif;color:#171717;background:#f6f5f4;margin:0;padding:24px">
    <main style="max-width:580px;margin:auto;background:#fff;border-top:4px solid #c90023;padding:24px">
        <p style="color:#b5001d;font-weight:bold">Zapatería Angelita</p>
        @if ($welcome)
            <h1>¡Gracias por suscribirte!</h1>
            <p>Tu suscripción quedó registrada. Recibirás un resumen mensual cuando tengamos nuevos productos u ofertas para compartir.</p>
        @else
            <h1>Novedades y ofertas del mes</h1>
            @foreach (['Recién llegados' => $newProducts, 'Ofertas disponibles' => $offers] as $heading => $products)
                @if ($products->isNotEmpty())
                    <h2>{{ $heading }}</h2>
                    <ul>
                        @foreach ($products as $product)
                            <li><a style="color:#a60018" href="{{ $storeUrl }}/producto/{{ $product->id }}">{{ $product->name }}</a></li>
                        @endforeach
                    </ul>
                @endif
            @endforeach
        @endif
        <p><a style="color:#a60018" href="{{ $storeUrl }}/catalogo">Ver catálogo</a></p>
        <p style="font-size:12px"><a href="{{ $unsubscribeUrl }}">Cancelar suscripción</a></p>
    </main>
</body>
</html>
