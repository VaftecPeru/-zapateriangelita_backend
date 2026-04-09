<!DOCTYPE html>
<html>
<head>
    <title>Bienvenido a Homad</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    
    <div style="text-align: center; margin-bottom: 30px;">
        <h1 style="color: #000;">¡Bienvenido a Homad!</h1>
    </div>

    <p>Hola <strong>{{ $user->name }}</strong>,</p>

    <p>Hemos recibido tus datos y tu solicitud de reserva está en proceso. Para facilitarte el seguimiento, hemos creado automáticamente una cuenta para ti.</p>

    <div style="background-color: #f9f9f9; padding: 20px; border-radius: 8px; margin: 20px 0; border: 1px solid #eee;">
        <h3 style="margin-top: 0;">Tus credenciales de acceso:</h3>
        <p><strong>Correo (Usuario):</strong> {{ $user->email }}</p>
        <p><strong>Contraseña temporal:</strong> <span style="background-color: #e2e8f0; padding: 3px 6px; border-radius: 4px; font-family: monospace;">{{ $password }}</span></p>
    </div>

    <p>Te recomendamos iniciar sesión lo antes posible y cambiar tu contraseña por una de tu preferencia, dirigiéndote a tu perfil.</p>

    <div style="text-align: center; margin: 30px 0;">
        <a href="{{ rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/') }}/login" style="background-color: #000; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold;">Iniciar Sesión Aquí</a>
    </div>

    <p>Si tienes alguna pregunta, no dudes en contactarnos.</p>

    <p style="margin-top: 40px; font-size: 0.9em; color: #666;">
        Saludos cordiales,<br>
        El equipo de Homad
    </p>

</body>
</html>
