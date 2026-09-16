# Checklist técnico de certificación Openpay — Zapatería Angelita

## 1. Ambiente de certificación

Durante la revisión técnica utilizar credenciales del ambiente de pruebas de Openpay y no mezclar llaves de sandbox con el endpoint de producción.

Configuración recomendada en el `.env` del servidor durante la certificación:

```env
OPENPAY_CERTIFICATION_MODE=true
OPENPAY_SANDBOX=true
OPENPAY_MERCHANT_ID=<merchant_id_sandbox>
OPENPAY_PUBLIC_KEY=<pk_sandbox>
OPENPAY_PRIVATE_KEY=<sk_sandbox>
OPENPAY_CURRENCY=MXN
OPENPAY_REDIRECT_URL=https://zapateriangelita.com/checkout
```

No compartir ni almacenar la llave privada en frontend, GitHub, JavaScript o HTML.

Después de modificar `.env` ejecutar:

```bash
php artisan optimize:clear
php artisan config:cache
```

El código 1002 / HTTP 401 de Openpay indica credenciales inválidas o un Merchant ID y llave que no pertenecen al mismo ambiente. En la captura de diagnóstico la tokenización estaba intentando usar `api.openpay.mx`; durante certificación debe utilizarse `sandbox-api.openpay.mx` con las credenciales sandbox correspondientes.

## 2. Requisitos funcionales cubiertos

- Sitio operativo con dominio y HTTPS.
- Productos visibles en la tienda.
- Formulario de pago con tarjeta completo.
- Número de tarjeta limitado a 16 dígitos para la revisión.
- CVV limitado a 4 dígitos.
- Vencimiento en formato MM/AA.
- Mensajes diferenciados para transacción exitosa, tarjeta rechazada, fondos insuficientes y error general.
- Botón de pago bloqueado mientras se procesa la transacción para evitar cobros duplicados.
- Tokenización directa mediante Openpay.js; Zapatería Angelita no almacena PAN ni CVV.
- Device Session ID antifraude habilitado.
- 3D Secure habilitado en cargos mediante `use_3d_secure=true` y `redirect_url` HTTPS.
- Identificación visual de Openpay y marcas Visa, Mastercard y American Express en el formulario.
- Al confirmarse el pago, el pedido se registra como pagado y el cliente es dirigido a Mis Compras.
- Para compradores invitados, la cuenta se crea únicamente después de confirmar el pago y las credenciales se envían al correo registrado.

## 3. Requisitos no aplicables actualmente

- Paynet / pago en efectivo: no se ofrece en el checkout actual.
- SPEI / transferencia: no se ofrece en el checkout actual.
- Almacenamiento de tarjetas: no se almacenan tarjetas, por lo que el límite de tres tarjetas no aplica.
- Meses sin intereses: no está habilitado actualmente.

Si estos métodos se habilitan antes de la cita, deberán agregarse sus pruebas y mensajes específicos.

## 4. Pruebas antes de la cita

1. Abrir la tienda en una ventana incógnita y confirmar HTTPS sin advertencias.
2. Agregar un producto con stock al carrito.
3. Completar checkout y abrir la pasarela.
4. Confirmar en Network que la tokenización apunta a `sandbox-api.openpay.mx` durante certificación.
5. Ejecutar una transacción exitosa con los datos de prueba autorizados por Openpay.
6. Verificar mensaje: `Recibimos tu pago. ¡Gracias por tu compra!`.
7. Confirmar redirección a Mis Compras.
8. Verificar pedido en el panel y correo de credenciales cuando se trate de un cliente nuevo.
9. Probar casos de tarjeta rechazada y fondos insuficientes con tarjetas de prueba oficiales de Openpay.
10. Confirmar que el botón de pago queda deshabilitado mientras la operación está procesándose.
11. Probar el flujo 3D Secure y el retorno seguro a `/checkout`.
12. Revisar que no aparezca PAN completo, CVV ni llave privada en logs, Network de la aplicación o respuestas del backend.

## 5. Cambio a producción después de aprobación

Solo después de que Openpay entregue/active credenciales de producción:

```env
OPENPAY_CERTIFICATION_MODE=false
OPENPAY_SANDBOX=false
OPENPAY_MERCHANT_ID=<merchant_id_produccion>
OPENPAY_PUBLIC_KEY=<pk_produccion>
OPENPAY_PRIVATE_KEY=<sk_produccion>
OPENPAY_CURRENCY=MXN
OPENPAY_REDIRECT_URL=https://zapateriangelita.com/checkout
```

Luego ejecutar nuevamente:

```bash
php artisan optimize:clear
php artisan config:cache
```

Antes de realizar cargos reales, verificar que la llave pública y la privada pertenezcan al mismo Merchant ID de producción.
