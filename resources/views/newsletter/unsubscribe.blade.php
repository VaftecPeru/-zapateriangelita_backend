<!doctype html>
<html lang="es">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Newsletter de Zapatería Angelita</title>
<body style="font-family:system-ui;padding:32px;max-width:520px;margin:auto">
    <h1 style="color:#b5001d">Zapatería Angelita</h1>
    @if ($unsubscribed)
        <p>Cancelamos tu suscripción al newsletter.</p>
    @else
        <p>¿Quieres dejar de recibir novedades y ofertas por correo?</p>
        <form method="post">
            @csrf
            <button type="submit">Confirmar baja</button>
        </form>
    @endif
</body>
</html>
