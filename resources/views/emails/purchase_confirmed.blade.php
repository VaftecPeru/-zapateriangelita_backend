<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Pago confirmado | Zapatería Angelita</title></head>
<body style="margin:0;padding:0;background-color:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#1a1a1a;">
@php
    $frontend = rtrim(env('FRONTEND_URL', 'https://zapateriangelita.com'), '/');
    $logo = 'https://zapateriangelita.com/images/logo-email.png';
    $purchasesUrl = $frontend . '/profile/purchases';
    $paidAt = $order->paid_at ? $order->paid_at->timezone('America/Mexico_City')->format('d/m/Y H:i') : 'Confirmado';
    $shippingParts = array_filter([
        $order->shipping_address,
        $order->shipping_colony ? 'Col. '.$order->shipping_colony : null,
        $order->shipping_city, $order->shipping_municipality, $order->shipping_state,
        $order->shipping_postal_code ? 'C.P. '.$order->shipping_postal_code : null,
        $order->shipping_country,
    ]);
    $customerName = $order->customer_name ?: ($user?->name ?? 'Cliente');
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#ffffff;">
<tr><td align="center" style="padding:28px 14px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:680px;background-color:#ffffff;border:1px solid #eeeeee;border-radius:16px;">
<tr><td align="center" style="padding:28px 20px;background-color:#ffffff;border-bottom:3px solid #e30613;"><img src="{{ $logo }}" alt="Zapatería Angelita" width="210" style="display:block;width:210px;max-width:100%;height:auto;border:0;margin:0 auto;"></td></tr>
<tr><td style="padding:30px;background-color:#ffffff;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#ecfdf3;border:1px solid #b7ebc6;border-radius:10px;"><tr><td style="padding:18px;font-size:13px;line-height:1.8;color:#333333;"><strong style="font-size:12px;color:#18753b;text-transform:uppercase;">Pago confirmado</strong><h1 style="font-size:24px;line-height:1.3;margin:8px 0 10px;">¡Gracias por tu compra!</h1><p style="font-size:14px;line-height:1.7;margin:0;">El pago de tu pedido ha sido confirmado. Tu compra está registrada en Zapatería Angelita.</p></td></tr></table><div style="height:20px;"></div><p style="font-size:14px;color:#555555;line-height:1.7;margin:0 0 16px;">Hola {{ $customerName }}. A continuación encontrarás el resumen de tu compra y los datos de la operación.</p><p style="font-size:12px;color:#777777;line-height:1.7;">También recibirás tu comprobante de pago adjunto cuando esté disponible en la notificación.</p><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#fff5f5;border:1px solid #ffd9dc;border-radius:10px;"><tr><td style="padding:18px;font-size:13px;line-height:1.8;color:#333333;"><strong style="font-size:17px;color:#e30613;">Pedido {{ $order->code }}</strong><p style="margin:8px 0 0;"><strong>Método:</strong> {{ strtoupper($order->payment_method ?: 'OpenPay') }}</p><p style="margin:2px 0;"><strong>Estado:</strong> Confirmado</p><p style="margin:2px 0;"><strong>Movimiento:</strong> {{ $order->payment_transaction_id ?: '—' }}</p><p style="margin:2px 0;"><strong>Referencia:</strong> {{ $order->payment_reference ?: '—' }}</p><p style="margin:2px 0;"><strong>Fecha de pago:</strong> {{ $paidAt }}</p></td></tr></table><h2 style="font-size:17px;margin:28px 0 14px;">Detalle de tu compra</h2><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-size:12px;"><thead><tr style="background-color:#fafafa;"><th align="left" style="padding:11px 7px;border-bottom:1px solid #eee;">Producto</th><th align="center" style="padding:11px 7px;border-bottom:1px solid #eee;">Cant.</th><th align="right" style="padding:11px 7px;border-bottom:1px solid #eee;">Importe</th></tr></thead><tbody>
@foreach($order->items as $item)
<tr><td style="padding:12px 7px;border-bottom:1px solid #eee;"><strong>{{ $item->product_name }}</strong>
@if($item->size || $item->color)<div style="color:#777;margin-top:4px;">{{ $item->size ? 'Talla: '.$item->size : '' }}{{ $item->size && $item->color ? ' · ' : '' }}{{ $item->color ? 'Color: '.$item->color : '' }}</div>@endif
</td><td align="center" style="padding:12px 7px;border-bottom:1px solid #eee;">{{ $item->quantity }}</td><td align="right" style="padding:12px 7px;border-bottom:1px solid #eee;white-space:nowrap;">MXN {{ number_format((float) $item->unit_price * (int) $item->quantity, 2) }}</td></tr>
@endforeach
</tbody></table>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:22px;border-collapse:collapse;font-size:13px;">
<tr><td style="padding:7px 0;">Subtotal</td><td align="right"><strong>MXN {{ number_format((float) $order->subtotal, 2) }}</strong></td></tr>
<tr><td style="padding:7px 0;">Descuento</td><td align="right"><strong>-MXN {{ number_format((float) $order->discount, 2) }}</strong></td></tr>
<tr><td style="padding:7px 0;">Costo de envío</td><td align="right"><strong>MXN {{ number_format((float) $order->shipping_cost, 2) }}</strong></td></tr>
<tr><td style="padding:14px 0;border-top:2px solid #1a1a1a;font-size:18px;"><strong>Total</strong></td><td align="right" style="padding:14px 0;border-top:2px solid #1a1a1a;font-size:18px;color:#e30613;"><strong>MXN {{ number_format((float) $order->total, 2) }}</strong></td></tr></table>
<div style="height:20px;"></div><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#fafafa;border:1px solid #eeeeee;border-radius:10px;"><tr><td style="padding:18px;font-size:13px;line-height:1.8;color:#333333;"><strong style="font-size:15px;">Datos para envío</strong><p style="margin:8px 0 0;">{{ implode(', ', $shippingParts) ?: 'Dirección no registrada' }}</p><p style="margin:5px 0 0;"><strong>Teléfono:</strong> {{ $order->shipping_phone ?: '—' }}</p></td></tr></table><table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:26px auto;"><tr><td align="center" bgcolor="#e30613" style="background-color:#e30613;border-radius:9px;"><a href="{{ $purchasesUrl }}" style="display:inline-block;background-color:#e30613;color:#ffffff;text-decoration:none;padding:14px 24px;font-size:14px;font-weight:bold;border-radius:9px;">Ver mi compra</a></td></tr></table><p style="font-size:11px;line-height:1.7;color:#888888;margin:26px 0 0;">Este correo y el voucher, cuando se adjunte, son comprobantes comerciales emitidos por Zapatería Angelita. Tu banco o emisor de tarjeta puede enviar su propia notificación de la operación.</p>
</td></tr>
<tr><td align="center" style="padding:18px;background-color:#ffffff;border-top:1px solid #eeeeee;color:#888888;font-size:11px;">© {{ date('Y') }} Zapatería Angelita. Todos los derechos reservados.</td></tr>
</table></td></tr></table></body></html>
