<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Bienvenido | Zapatería Angelita</title></head>
<body style="margin:0;padding:0;background-color:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#1a1a1a;">
@php
    $frontend = rtrim(env('FRONTEND_URL', 'https://zapateriangelita.com'), '/');
    $logo = 'https://zapateriangelita.com/images/logo-email.png';
    $loginUrl = $frontend . '/login';
    $isGoogleRegistration = ($registrationMethod ?? null) === 'google';
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#ffffff;">
<tr><td align="center" style="padding:28px 14px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:620px;background-color:#ffffff;border:1px solid #eeeeee;border-radius:16px;">
<tr><td align="center" style="padding:28px 20px;background-color:#ffffff;border-bottom:3px solid #e30613;"><img src="{{ $logo }}" alt="Zapatería Angelita" width="210" style="display:block;width:210px;max-width:100%;height:auto;border:0;margin:0 auto;"></td></tr>
<tr><td style="padding:30px;background-color:#ffffff;">
<h1 style="font-size:23px;line-height:1.4;margin:0 0 15px;color:#1a1a1a;">¡Bienvenido a Zapatería Angelita!</h1><p style="font-size:14px;color:#555555;line-height:1.7;margin:0 0 16px;">Hola <strong>{{ $user->name }}</strong>,</p><p style="font-size:14px;color:#555555;line-height:1.7;margin:0 0 16px;">Tu cuenta ha sido creada correctamente. Ya puedes explorar nuestra tienda, realizar compras y consultar tus pedidos desde tu perfil de cliente.</p><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#fafafa;border:1px solid #eeeeee;border-radius:10px;"><tr><td style="padding:18px;font-size:13px;line-height:1.8;color:#333333;"><p style="font-size:12px;color:#777777;margin:0 0 6px;">Correo electrónico registrado</p><p style="font-size:14px;overflow-wrap:anywhere;"><strong>{{ $user->email }}</strong></p>@if($isGoogleRegistration)<p style="margin:0;"><strong>Registro con Google:</strong> Tu cuenta fue creada utilizando Google. Puedes iniciar sesión mediante la opción «Continuar con Google».</p>@else<p style="margin:0;"><strong>Registro tradicional:</strong> Utiliza el correo electrónico y la contraseña que registraste para iniciar sesión.</p>@endif</td></tr></table><div style="height:18px;"></div><p style="font-size:14px;color:#555555;line-height:1.7;margin:0 0 16px;">Por seguridad, nunca enviamos tu contraseña personal en este correo. Si la olvidaste, puedes restablecerla desde la pantalla de inicio de sesión.</p><table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:26px auto;"><tr><td align="center" bgcolor="#e30613" style="background-color:#e30613;border-radius:9px;"><a href="{{ $loginUrl }}" style="display:inline-block;background-color:#e30613;color:#ffffff;text-decoration:none;padding:14px 24px;font-size:14px;font-weight:bold;border-radius:9px;">Iniciar sesión</a></td></tr></table><p style="font-size:12px;color:#777777;line-height:1.7;">Si no solicitaste la creación de esta cuenta, comunícate con el soporte de Zapatería Angelita.</p><p style="font-size:13px;text-align:center;color:#555555;">Gracias por confiar en nosotros.</p>
</td></tr>
<tr><td align="center" style="padding:18px;background-color:#ffffff;border-top:1px solid #eeeeee;color:#888888;font-size:11px;">© {{ date('Y') }} Zapatería Angelita. Todos los derechos reservados.</td></tr>
</table></td></tr></table></body></html>
