<!doctype html>
<html lang="es"><head><meta charset="utf-8"><title>Contraseña temporal</title></head>
<body style="margin:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;color:#1a1a1a;">
@php $frontend=rtrim(env('FRONTEND_URL','https://zapateriangelita.com'),'/'); $logo=$frontend.'/logo-angelita-horizontal.png'; @endphp
<div style="max-width:620px;margin:auto;padding:28px 14px;"><div style="background:#fff;border-radius:18px;overflow:hidden;border:1px solid #eee;">
<div style="padding:24px;text-align:center;border-bottom:3px solid #e30613;"><img src="{{ $logo }}" alt="Zapatería Angelita" style="max-width:210px;width:70%;height:auto;"></div>
<div style="padding:30px;"><h1 style="font-size:23px;margin:0 0 12px;">Contraseña temporal generada</h1>
<p style="color:#666;line-height:1.7;">Hola {{ $user->name }}. Se restableció el acceso a tu cuenta.</p>
<div style="padding:18px;border:1px solid #ddd;border-radius:12px;background:#fafafa;margin:20px 0;">
<div style="font-size:12px;color:#666;">Usuario</div><strong>{{ $user->email }}</strong>
<div style="font-size:12px;color:#666;margin-top:12px;">Contraseña temporal</div><strong style="font-family:monospace;font-size:17px;">{{ $temporaryPassword }}</strong>
</div>
<p style="font-size:13px;color:#555;line-height:1.7;">Al iniciar sesión deberás crear una contraseña personal. La temporal dejará de ser válida después del cambio.</p>
<div style="text-align:center;"><a href="{{ $frontend }}/login" style="display:inline-block;background:#e30613;color:#fff;text-decoration:none;padding:13px 22px;border-radius:9px;font-weight:800;">Iniciar sesión</a></div>
</div><div style="padding:16px;text-align:center;background:#fafafa;color:#888;font-size:11px;">© {{ date('Y') }} Zapatería Angelita</div>
</div></div></body></html>