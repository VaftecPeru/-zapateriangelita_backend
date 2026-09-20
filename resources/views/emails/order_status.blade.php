<!doctype html>
<html lang="es"><head><meta charset="utf-8"><title>Actualización de pedido</title></head>
<body style="margin:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;color:#1a1a1a;">
@php
$frontend=rtrim(env('FRONTEND_URL','https://zapateriangelita.com'),'/');
$logo=$frontend.'/logo-angelita-horizontal.png';
$labels=['sin_preparar'=>'Sin preparar','preparado'=>'Preparado','enviado'=>'Enviado','entregado'=>'Entregado','finalizado'=>'Finalizado','cancelado'=>'Cancelado'];
$label=$labels[$order->status] ?? ucfirst((string)$order->status);
@endphp
<div style="max-width:620px;margin:auto;padding:28px 14px;"><div style="background:#fff;border-radius:18px;overflow:hidden;border:1px solid #eee;">
<div style="padding:24px;text-align:center;border-bottom:3px solid #e30613;"><img src="{{ $logo }}" alt="Zapatería Angelita" style="max-width:210px;width:70%;height:auto;"></div>
<div style="padding:30px;"><h1 style="font-size:23px;margin:0 0 12px;">Tu pedido fue actualizado</h1>
<p style="color:#666;line-height:1.7;">Hola {{ $order->customer_name ?: 'Cliente' }}. El pedido <strong>{{ $order->code }}</strong> ahora se encuentra en:</p>
<div style="margin:20px 0;padding:18px;text-align:center;border-radius:12px;background:#fff5f5;border:1px solid #ffd9dc;color:#e30613;font-size:19px;font-weight:900;">{{ $label }}</div>
<div style="text-align:center;"><a href="{{ $frontend }}/profile/purchases" style="display:inline-block;background:#111;color:#fff;text-decoration:none;padding:13px 22px;border-radius:9px;font-weight:800;">Ver mis compras</a></div>
</div><div style="padding:16px;text-align:center;background:#fafafa;color:#888;font-size:11px;">© {{ date('Y') }} Zapatería Angelita</div>
</div></div></body></html>