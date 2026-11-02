<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Restablecer contraseña | Zapatería Angelita</title>
</head>
<body style="margin:0;padding:0;background-color:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#1a1a1a;">
@php
    $frontend = rtrim(env('FRONTEND_URL', 'https://zapateriangelita.com'), '/');
    $logo = 'https://zapateriangelita.com/images/logo-email.png';
    $resetUrl = $frontend . '/reset-password?token='
        . rawurlencode($token)
        . '&email='
        . rawurlencode($user->email);
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#ffffff;">
    <tr>
        <td align="center" style="padding:28px 14px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:620px;background-color:#ffffff;border:1px solid #eeeeee;border-radius:16px;">
                <tr>
                    <td align="center" style="padding:28px 20px;background-color:#ffffff;border-bottom:3px solid #e30613;">
                        <img src="{{ $logo }}" alt="Zapatería Angelita" width="210" style="display:block;width:210px;max-width:100%;height:auto;border:0;margin:0 auto;">
                    </td>
                </tr>
                <tr>
                    <td style="padding:30px;background-color:#ffffff;">
                        <h1 style="font-size:23px;line-height:1.4;margin:0 0 15px;color:#1a1a1a;">Restablece tu contraseña</h1>
                        <p style="font-size:14px;color:#555555;line-height:1.7;margin:0 0 16px;">Hola {{ $user->name }}. Recibimos una solicitud para cambiar la contraseña de tu cuenta.</p>
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:26px auto;">
                            <tr>
                                <td align="center" bgcolor="#e30613" style="background-color:#e30613;border-radius:9px;">
                                    <a href="{{ $resetUrl }}" style="display:inline-block;background-color:#e30613;color:#ffffff;text-decoration:none;padding:14px 24px;font-size:14px;font-weight:bold;border-radius:9px;">Crear nueva contraseña</a>
                                </td>
                            </tr>
                        </table>
                        <p style="font-size:12px;color:#777777;line-height:1.7;margin:20px 0 0;">Si no solicitaste este cambio, ignora este mensaje. El enlace expirará según la política de seguridad configurada en la plataforma.</p>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:18px;background-color:#ffffff;border-top:1px solid #eeeeee;color:#888888;font-size:11px;">© {{ date('Y') }} Zapatería Angelita</td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
