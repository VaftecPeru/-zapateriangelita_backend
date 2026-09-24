<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>Pago confirmado</title></head>
<body style="margin:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;color:#1a1a1a;">
@php
    $frontend = rtrim(env('FRONTEND_URL', 'https://zapateriangelita.com'), '/');
    $logo = app(\App\Services\BrandingService::class)->logoUrl();
    $paidAt = $order->paid_at
        ? $order->paid_at->timezone('America/Mexico_City')->format('d/m/Y H:i')
        : 'Confirmado';
    $shippingParts = array_filter([
        $order->shipping_address,
        $order->shipping_colony ? 'Col. '.$order->shipping_colony : null,
        $order->shipping_city,
        $order->shipping_municipality,
        $order->shipping_state,
        $order->shipping_postal_code ? 'C.P. '.$order->shipping_postal_code : null,
        $order->shipping_country,
    ]);
@endphp
<div style="max-width:680px;margin:0 auto;padding:28px 14px;">
  <div style="background:#fff;border-radius:18px;overflow:hidden;border:1px solid #eee;">
    <div style="padding:24px;text-align:center;border-bottom:3px solid #e30613;">
      <img src="{{ $logo }}" alt="Zapatería Angelita" style="max-width:210px;width:70%;height:auto;">
    </div>
    <div style="padding:30px;">
      <div style="background:#ecfdf3;border:1px solid #b7ebc6;border-radius:14px;padding:18px;margin-bottom:22px;">
        <div style="font-size:12px;font-weight:800;color:#18753b;text-transform:uppercase;letter-spacing:.08em;">Pago confirmado</div>
        <h1 style="font-size:25px;margin:6px 0 8px;">¡Gracias por tu compra!</h1>
        <p style="margin:0;color:#466454;line-height:1.6;">Openpay confirmó la operación de tu pedido. Tu compra ya está registrada en Zapatería Angelita.</p>
      </div>

      <p style="margin:0 0 20px;color:#666;line-height:1.6;">Hola {{ $order->customer_name ?: ($user?->name ?? 'Cliente') }}. Adjuntamos tu voucher y también dejamos aquí los datos principales de la operación.</p>

      <div style="background:#fff5f5;border:1px solid #ffd9dc;border-radius:12px;padding:16px;margin-bottom:20px;">
        <strong style="color:#e30613;">Pedido {{ $order->code }}</strong><br>
        <span style="font-size:13px;color:#555;">Método: {{ strtoupper($order->payment_method ?: 'OpenPay') }} · Estado: confirmado</span>
        <div style="margin-top:10px;font-size:13px;line-height:1.7;color:#333;">
          <strong>Movimiento:</strong> {{ $order->payment_transaction_id ?: '—' }}<br>
          <strong>Referencia:</strong> {{ $order->payment_reference ?: '—' }}<br>
          <strong>Fecha de pago:</strong> {{ $paidAt }}
        </div>
      </div>

      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead><tr style="background:#fafafa;">
          <th style="padding:9px;text-align:left;border-bottom:1px solid #eee;">Producto</th>
          <th style="padding:9px;text-align:center;border-bottom:1px solid #eee;">Cant.</th>
          <th style="padding:9px;text-align:right;border-bottom:1px solid #eee;">Importe</th>
        </tr></thead>
        <tbody>
          @foreach($order->items as $item)
          <tr>
            <td style="padding:9px;border-bottom:1px solid #eee;">
              <strong>{{ $item->product_name }}</strong>
              @if($item->size || $item->color)
                <div style="color:#777;margin-top:3px;">{{ $item->size ? 'Talla: '.$item->size : '' }}{{ $item->size && $item->color ? ' · ' : '' }}{{ $item->color ? 'Color: '.$item->color : '' }}</div>
              @endif
            </td>
            <td style="padding:9px;text-align:center;border-bottom:1px solid #eee;">{{ $item->quantity }}</td>
            <td style="padding:9px;text-align:right;border-bottom:1px solid #eee;">MXN {{ number_format((float)$item->unit_price * (int)$item->quantity, 2) }}</td>
          </tr>
          @endforeach
        </tbody>
      </table>

      <div style="margin:20px 0 0 auto;max-width:320px;font-size:13px;">
        <div style="display:flex;justify-content:space-between;padding:5px 0;"><span>Subtotal</span><strong>MXN {{ number_format((float)$order->subtotal, 2) }}</strong></div>
        <div style="display:flex;justify-content:space-between;padding:5px 0;"><span>Descuento</span><strong>-MXN {{ number_format((float)$order->discount, 2) }}</strong></div>
        <div style="display:flex;justify-content:space-between;padding:5px 0;"><span>Costo de delivery</span><strong>MXN {{ number_format((float)$order->shipping_cost, 2) }}</strong></div>
        <div style="display:flex;justify-content:space-between;padding:11px 0 0;border-top:2px solid #111;margin-top:6px;font-size:17px;"><span><strong>Total</strong></span><strong style="color:#e30613;">MXN {{ number_format((float)$order->total, 2) }}</strong></div>
      </div>

      <div style="margin-top:24px;padding:16px;border-radius:12px;background:#fafafa;">
        <strong>Datos para envío</strong><br>
        <span style="font-size:13px;color:#666;line-height:1.7;">
          {{ implode(', ', $shippingParts) }}<br>
          Teléfono: {{ $order->shipping_phone ?: '—' }}
        </span>
      </div>

      <div style="text-align:center;margin-top:26px;">
        <a href="{{ $frontend }}/profile/purchases" style="display:inline-block;background:#e30613;color:#fff;text-decoration:none;padding:13px 22px;border-radius:9px;font-weight:800;">Ver mi compra</a>
      </div>

      <p style="margin:24px 0 0;font-size:11px;line-height:1.6;color:#888;">Este correo y el voucher son comprobantes comerciales emitidos por Zapatería Angelita. Tu banco o emisor de tarjeta puede enviar, de forma independiente, su propia notificación de la operación.</p>
    </div>
    <div style="padding:16px;text-align:center;background:#fafafa;color:#888;font-size:11px;">© {{ date('Y') }} Zapatería Angelita</div>
  </div>
</div>
</body></html>