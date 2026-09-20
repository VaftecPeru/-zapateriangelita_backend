<!doctype html>
<html lang="es"><head><meta charset="utf-8"><title>Contraseña actualizada</title></head>
<body style="margin:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;color:#1a1a1a;">
@php $frontend=rtrim(env('FRONTEND_URL','https://zapateriangelita.com'),'/'); $logo = app(\App\Services\BrandingService::class)->logoUrl(); @endphp
<div style="max-width:620px;margin:auto;padding:28px 14px;"><div style="background:#fff;border-radius:18px;overflow:hidden;border:1px solid #eee;">
<div style="padding:24px;text-align:center;border-bottom:3px solid #e30613;"><img src="{{ $logo }}" alt="Zapatería Angelita" style="max-width:210px;width:70%;height:auto;"></div>
<div style="padding:30px;"><h1 style="font-size:23px;margin:0 0 12px;">Tu contraseña fue actualizada</h1>
<p style="color:#666;line-height:1.7;">Hola {{ $user->name }}. Te confirmamos que la contraseña de tu cuenta fue modificada correctamente.</p>
<p style="font-size:13px;color:#555;line-height:1.7;">{{ $context === 'temporary_password_changed' ? 'La contraseña temporal ya dejó de ser válida.' : 'Ya puedes iniciar sesión con tu nueva contraseña.' }}</p>
<div style="padding:15px;margin:22px 0;background:#fff5f5;border:1px solid #ffd9dc;border-radius:10px;font-size:12px;color:#7a1b1b;">Si tú no realizaste este cambio, utiliza la opción “Olvidé mi contraseña” inmediatamente.</div>
<div style="text-align:center;"><a href="{{ $frontend }}/login" style="display:inline-block;background:#111;color:#fff;text-decoration:none;padding:13px 22px;border-radius:9px;font-weight:800;">Ir al inicio de sesión</a></div>
</div><div style="padding:16px;text-align:center;background:#fafafa;color:#888;font-size:11px;">© {{ date('Y') }} Zapatería Angelita</div>
</div></div></body></html>