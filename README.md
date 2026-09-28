# Zapatería Angelita — Backend

API de producción del e-commerce Zapatería Angelita.

## Stack

- PHP 8.2+
- Laravel 12
- Laravel Sanctum 4
- MySQL/MariaDB
- Openpay México + 3D Secure
- SMTP transaccional
- Cron de Laravel para conciliación de pagos y reintentos de correo
- Microservicio Spring Boot de pagos disponible como integración opcional y desactivado por defecto

## Entornos

Producción:

- API: `https://api.zapateriangelita.com`
- Frontend: `https://zapateriangelita.com`
- Moneda: MXN
- Openpay: producción cuando `OPENPAY_SANDBOX=false`

Nunca subir `.env`, credenciales SMTP, llaves Openpay o secretos de servicios al repositorio.

## Flujo de pago

1. Se crea un pedido con un `checkout_token` cifrado y temporal.
2. Al iniciar el pago se reserva inventario de forma transaccional.
3. Openpay procesa el cargo y 3D Secure cuando corresponde.
4. El backend verifica ID, referencia, importe y moneda antes de marcar un pedido como pagado.
5. Webhook, retorno 3DS y Cron convergen sobre el mismo estado de pedido.
6. Si el pago falla o se cancela, la reserva de stock se libera de forma idempotente.
7. Al confirmar pago se programa voucher/correo y, cuando corresponde, creación de cuenta del cliente.

## Cron de producción

cPanel debe ejecutar una sola vez por minuto:

```bash
/usr/local/bin/php /home/USUARIO/public_html/api.zapateriangelita.com/artisan schedule:run >> /dev/null 2>&1
```

Laravel programa internamente:

- conciliación Openpay cada 5 minutos;
- reintentos de notificaciones de pedido cada minuto;
- reintentos de correos de bienvenida cada minuto.

## Autenticación y rate limiting

El login usa un limitador específico que contabiliza únicamente credenciales rechazadas. Un login exitoso limpia el contador correspondiente.

El backend devuelve `429` con:

- `Retry-After`
- `X-RateLimit-Limit`
- `X-RateLimit-Remaining`
- JSON `retry_after`

CORS expone estas cabeceras al frontend.

Si el hosting opera detrás de un reverse proxy, configure `TRUSTED_PROXIES` únicamente con IP/CIDR conocidos. No use `*` salvo que todo el tráfico pase por un proxy bajo control administrativo.

## Correo transaccional

El envío de bienvenida, voucher y credenciales no debe bloquear el checkout. Los pendientes se persisten para reintento mediante Cron.

Endpoint administrativo de diagnóstico:

```text
GET /api/mail/health
```

No expone contraseñas ni secretos SMTP.

## Desarrollo

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Para pruebas:

```bash
vendor/bin/phpunit --testdox
composer audit --locked
```

## CI

Todo Pull Request hacia `main` y todo push a `main` valida:

- `composer validate`;
- instalación desde el `composer.lock` versionado;
- `composer audit --locked`;
- sintaxis PHP;
- arranque Laravel;
- migraciones desde cero;
- listado de rutas;
- PHPUnit.

CI no ejecuta `composer update --lock`; producción debe reproducir exactamente el lockfile aprobado.

## Deploy cPanel

Orden recomendado:

1. respaldo de código y BD;
2. desplegar backend;
3. ejecutar migraciones;
4. limpiar caché/configuración si existe;
5. verificar Cron;
6. verificar `/api/health`;
7. desplegar frontend;
8. smoke test de login, Google, checkout, Openpay, voucher y panel administrativo.

## Política de ramas

- Desarrollo: ramas `feature/*` o `fix/*`.
- Integración: Pull Request.
- Producción: `main`.
- No realizar push directo a `main`.
- El merge a `main` requiere autorización del titular y CI en verde.

## Recuperación

La restauración de producción requiere coordinar tres elementos de la misma línea base:

1. código backend;
2. código frontend;
3. dump de base de datos compatible con las migraciones de esa versión.

No mezclar un dump histórico con código posterior sin ejecutar y auditar las migraciones intermedias.
