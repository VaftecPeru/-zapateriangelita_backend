<!doctype html>
<html lang="es"><head><meta charset="utf-8"><title>Restablecer contraseña</title></head>
<body style="margin:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;color:#1a1a1a;">
@php
  $frontend = rtrim(env('FRONTEND_URL', 'https://zapateriangelita.com'), '/');
  $logo = app(\\App\\Services\\BrandingService::class)->logoUrl();
  $resetUrl = $frontend . '/reset-password?token=' . rawurlencode($token) . '&email=' . rawurlencode($user->email);
@endphp
<div style="max-width:620px;margin:auto;padding:28px 14px;"><div style="background:#fff;border-radius:18px;overflow:hidden;border:1px solid #eee;">
<div style="padding:24px;text-align:center;border-bottom:3px solid #e30613;"><img src="{{ $logo }}" alt="Zapatería Angelita" style="max-width:210px;width:70%;height:auto;"></div>
<div style="padding:30px;"><h1 style="font-size:23px;margin:0 0 12px;">Restablece tu contraseña</h1>
<p style="color:#666;line-height:1.7;">Hola {{ $user->name }}. Recibimos una solicitud para cambiar la contraseña de tu cuenta.</p>
<div style="text-align:center;margin:26px 0;"><a href="{{ $resetUrl }}" style="display:inline-block;background:#e30613;color:#fff;text-decoration:none;padding:13px 22px;border-radius:9px;font-weight:800;">Crear nueva contraseña</a></div>
<p style="font-size:12px;color:#777;line-height:1.6;">Si no solicitaste este cambio, ignora este mensaje. El enlace expirará según la política de seguridad configurada en la plataforma.</p>
</div><div style="padding:16px;text-align:center;background:#fafafa;color:#888;font-size:11px;">© {{ date('Y') }} Zapatería Angelita</div>
</div></div></body></html>