# Zapatería Angelita Payment Service

Microservicio Spring Boot responsable de la orquestación transaccional de Openpay y del post-pago inmediato.

## Responsabilidades

- creación de charges Openpay;
- 3D Secure y verificación;
- reconciliación idempotente;
- webhooks Openpay;
- estados de intento de pago;
- callback firmado hacia Laravel;
- generación de voucher PDF;
- correo SMTP de compra;
- correo SMTP de credenciales para cuentas creadas después del pago.

Laravel conserva ownership de catálogo, inventario, pedidos y usuarios. El microservicio **no escribe en las tablas Laravel**.

## Variables requeridas

```env
SERVER_PORT=8081
PAYMENT_DB_URL=jdbc:mysql://127.0.0.1:3306/angelita_payment?useSSL=true&serverTimezone=UTC
PAYMENT_DB_USERNAME=
PAYMENT_DB_PASSWORD=
PAYMENT_SERVICE_SHARED_SECRET=<32+ caracteres aleatorios>
LARAVEL_API_URL=https://api.zapateriangelita.com

OPENPAY_BASE_URL=https://sandbox-api.openpay.mx
OPENPAY_MERCHANT_ID=
OPENPAY_PRIVATE_KEY=

MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
PAYMENT_NOTIFICATIONS_ENABLED=true
```

El mismo `PAYMENT_SERVICE_SHARED_SECRET` debe configurarse en Laravel.

## Migración progresiva

1. Desplegar este servicio con su base independiente.
2. Verificar `/actuator/health`.
3. Registrar en Openpay el webhook:
   `https://<payment-domain>/api/v1/webhooks/openpay`.
4. Mantener en Laravel `PAYMENT_SERVICE_ENABLED=false` durante smoke tests.
5. Cambiar a `PAYMENT_SERVICE_ENABLED=true` únicamente después de validar sandbox.
6. El frontend no requiere modificación: continúa consumiendo los endpoints Laravel existentes.

## Base de datos

Flyway crea automáticamente `payment_attempts`. No se requiere migración de la base principal de Laravel.
