<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Bienvenido a Umbral Suites</title>
    <style>
        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f2ede5;
            color: #000000;
            margin: 0;
            padding: 40px 20px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            border: 1px solid #000000;
        }
        .header {
            background-color: #000000;
            color: #ffffff;
            text-align: center;
            padding: 30px 20px;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            letter-spacing: 2px;
            text-transform: uppercase;
            font-weight: 900;
        }
        .content {
            padding: 40px 30px;
        }
        .content h2 {
            font-size: 20px;
            margin-top: 0;
            color: #000000;
            font-weight: 800;
        }
        .content p {
            line-height: 1.6;
            color: #555555;
            font-size: 15px;
        }
        .credentials-box {
            background-color: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            margin: 30px 0;
        }
        .credentials-box p {
            margin: 10px 0;
            font-size: 16px;
            color: #000000;
        }
        .credentials-box strong {
            display: inline-block;
            width: 100px;
            color: #6b7254;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 2px;
            font-weight: 900;
        }
        .button-container {
            text-align: center;
            margin-top: 40px;
        }
        .button {
            display: inline-block;
            background-color: #000000;
            color: #ffffff;
            text-decoration: none;
            padding: 14px 28px;
            border-radius: 8px;
            font-weight: 900;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        .footer {
            text-align: center;
            padding: 20px;
            font-size: 12px;
            color: #999999;
            background-color: #fafbfc;
            border-top: 1px solid #eeeeee;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Umbral Suites</h1>
        </div>
        <div class="content">
            <h2>¡Hola, {{ $user->name }}!</h2>
            <p>Queríamos darte una cálida bienvenida a Umbral Suites. Tu cuenta ha sido creada exitosamente y ahora eres parte de nuestra exclusiva comunidad.</p>
            <p>Para tu comodidad, hemos adjuntado a continuación las credenciales que ingresaste durante el registro. Por favor, mantenlas en un lugar seguro.</p>
            
            <div class="credentials-box">
                <p><strong>Email:</strong> {{ $user->email }}</p>
                <p><strong>Contraseña:</strong> {{ $password }}</p>
            </div>
            
            <div class="button-container">
                <a href="{{ env('FRONTEND_URL', 'http://localhost:5173') }}/login" class="button">Iniciar Sesión</a>
            </div>
            
            <p style="margin-top: 40px; font-size: 13px; color: #777;">Si tú no solicitaste esta cuenta, por favor ignora este correo.</p>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} Umbral Suites. Todos los derechos reservados.
        </div>
    </div>
</body>
</html>
