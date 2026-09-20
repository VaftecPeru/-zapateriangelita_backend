# SMTP y correos transaccionales

La aplicación utiliza el mailer SMTP de Laravel. Las credenciales no se guardan en el repositorio y deben configurarse en el servidor mediante variables de entorno.

Variables requeridas en producción:

```env
MAIL_MAILER=smtp
MAIL_HOST=<servidor-smtp>
MAIL_PORT=<puerto>
MAIL_USERNAME=<usuario>
MAIL_PASSWORD=<clave>
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=<correo-remitente>
MAIL_FROM_NAME="Zapatería Angelita"
FRONTEND_URL=https://zapateriangelita.com
```

Usa el host, puerto y cifrado entregados por el proveedor de correo. No publiques la contraseña SMTP en GitHub.

## Flujos auditados

- Registro tradicional: correo de bienvenida.
- Registro por Google: correo de bienvenida adaptado a Google.
- Recuperación de contraseña: enlace al frontend `/reset-password`.
- Contraseña restablecida: confirmación de seguridad.
- Contraseña temporal generada por administración: correo obligatorio con credencial temporal.
- Cambio de contraseña temporal: confirmación de seguridad.
- Compra pagada: voucher por correo para clientes autenticados e invitados.
- Alta automática de cuenta después de compra: correo separado con contraseña temporal.
- Cambio de estado del pedido: notificación al cliente.
- Webhook y conciliación Openpay: voucher idempotente, evitando duplicados mediante `orders.purchase_email_sent_at`.

## Reglas de entrega

Los fallos SMTP se registran sin exponer contraseñas ni credenciales. Un error de correo no revierte un pago confirmado ni una actualización de pedido. El voucher de compra vuelve a quedar disponible para reintento si falla el envío.

El logotipo de los correos se obtiene desde:

`FRONTEND_URL/logo-angelita-horizontal.png`

El frontend publica ese archivo con una ruta estable para evitar depender de nombres hash generados por Vite.
