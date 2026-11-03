<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Actualización de pedido | Zapatería Angelita</title></head>
<body style="margin:0;padding:0;background-color:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#1a1a1a;">
@php
    $frontend = rtrim(env('FRONTEND_URL', 'https://zapateriangelita.com'), '/');
    $logo = 'https://zapateriangelita.com/images/logo-email.png';
    $labels = ['sin_preparar'=>'Sin preparar','preparado'=>'Preparado','enviado'=>'Enviado','entregado'=>'Entregado','finalizado'=>'Finalizado','cancelado'=>'Cancelado'];
    $label = $labels[$order->status] ?? ucfirst((string) $order->status);
    $purchasesUrl = $frontend . '/profile/purchases';
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#ffffff;">
<tr><td align="center" style="padding:28px 14px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:620px;background-color:#ffffff;border:1px solid #eeeeee;border-radius:16px;">
<tr><td align="center" style="padding:28px 20px;background-color:#ffffff;border-bottom:3px solid #e30613;"><img src="{{ $logo }}" alt="Zapatería Angelita" width="210" style="display:block;width:210px;max-width:100%;height:auto;border:0;margin:0 auto;"></td></tr>
<tr><td style="padding:30px;background-color:#ffffff;">
<h1 style="font-size:23px;line-height:1.4;margin:0 0 15px;color:#1a1a1a;">Tu pedido fue actualizado</h1><p style="font-size:14px;color:#555555;line-height:1.7;margin:0 0 16px;">Hola {{ $order->customer_name ?: 'Cliente' }}. Te informamos que el estado de tu pedido <strong>{{ $order->code }}</strong> ha cambiado.</p><p style="font-size:14px;color:#555555;line-height:1.7;margin:0 0 16px;">Estado actual:</p><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 26px;background-color:#fff5f5;border:1px solid #ffd9dc;border-radius:10px;"><tr><td align="center" style="padding:20px 12px;color:#c80018;font-size:19px;font-weight:bold;">{{ $label }}</td></tr></table><table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:26px auto;"><tr><td align="center" bgcolor="#e30613" style="background-color:#e30613;border-radius:9px;"><a href="{{ $purchasesUrl }}" style="display:inline-block;background-color:#e30613;color:#ffffff;text-decoration:none;padding:14px 24px;font-size:14px;font-weight:bold;border-radius:9px;">Ver mis compras</a></td></tr></table><p style="font-size:12px;color:#777777;line-height:1.7;text-align:center;">Gracias por comprar en Zapatería Angelita.</p>
</td></tr>
<tr><td align="center" style="padding:18px;background-color:#ffffff;border-top:1px solid #eeeeee;color:#888888;font-size:11px;">© {{ date('Y') }} Zapatería Angelita. Todos los derechos reservados.</td></tr>
</table></td></tr></table></body></html>
