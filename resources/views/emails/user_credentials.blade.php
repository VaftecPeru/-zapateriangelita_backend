<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Compra confirmada - Zapatería Angelita</title>
</head>
<body style="margin:0;padding:0;background:#f2ede5;font-family:Arial,Helvetica,sans-serif;color:#222;">
    @php
        $frontend = rtrim(env('FRONTEND_URL', 'https://zapateriangelita.com'), '/');
        $logo = $frontend . '/logo-angelita-horizontal.png';
    @endphp
    <div style="max-width:620px;margin:0 auto;padding:32px 18px;">
        <div style="background:#fff;border:1px solid #e8e3dc;border-radius:14px;overflow:hidden;box-shadow:0 8px 28px rgba(0,0,0,.06);">
            <div style="background:#111;color:#fff;text-align:center;padding:26px 20px;">
                <img src="{{ $logo }}" alt="Zapatería Angelita" style="max-width:220px;width:72%;height:auto;background:#fff;border-radius:8px;padding:7px 10px;">
            </div>

            <div style="padding:34px 30px;">
                <h2 style="margin:0 0 12px;font-size:23px;color:#111;">¡Gracias por tu compra, {{ $user->name }}!</h2>
                <p style="margin:0 0 18px;line-height:1.7;color:#555;">
                    Tu pago fue confirmado correctamente. Para que puedas consultar y dar seguimiento a tu compra, creamos automáticamente tu cuenta de cliente.
                </p>

                @if($order)
                    <div style="background:#fff7f7;border:1px solid #ffd9dc;border-radius:10px;padding:16px 18px;margin:20px 0;">
                        <div style="font-size:11px;color:#e30613;font-weight:800;text-transform:uppercase;letter-spacing:1.5px;">Compra confirmada</div>
                        <div style="margin-top:7px;font-size:16px;"><strong>Pedido:</strong> {{ $order->code }}</div>
                        <div style="margin-top:5px;font-size:16px;"><strong>Total:</strong> ${{ number_format((float) $order->total, 2) }}</div>
                    </div>
                @endif

                <div style="background:#f7f7f7;border:1px solid #e7e7e7;border-radius:10px;padding:20px;margin:22px 0;">
                    <div style="font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:12px;color:#555;">Tus credenciales</div>
                    <p style="margin:7px 0;"><strong>Usuario:</strong> {{ $user->email }}</p>
                    <p style="margin:7px 0;"><strong>Contraseña temporal:</strong> <span style="font-family:monospace;background:#fff;border:1px solid #ddd;border-radius:5px;padding:4px 7px;">{{ $password }}</span></p>
                </div>

                <p style="line-height:1.7;color:#555;">
                    Por seguridad, te recomendamos cambiar la contraseña temporal desde tu perfil después de ingresar.
                </p>

                <div style="background:#f5f8ff;border:1px solid #dbe7ff;border-radius:10px;padding:18px;margin:20px 0;color:#35425a;line-height:1.6;">
                    <strong style="color:#111;">Acceso más rápido con Google</strong><br>
                    Después de ingresar a <strong>Mis Compras</strong>, podrás vincular la misma cuenta de Google asociada a este correo. En tus próximos accesos podrás usar Google sin escribir la contraseña.
                </div>

                <div style="text-align:center;margin:28px 0 12px;">
                    <a href="{{ rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/') }}/profile/purchases" style="display:inline-block;background:#e30613;color:#fff;text-decoration:none;padding:14px 26px;border-radius:8px;font-weight:800;">Ver mi compra</a>
                </div>
            </div>

            <div style="padding:18px 24px;text-align:center;background:#fafafa;border-top:1px solid #eee;color:#888;font-size:12px;">
                © {{ date('Y') }} Zapatería Angelita. Todos los derechos reservados.
            </div>
        </div>
    </div>
</body>
</html>
