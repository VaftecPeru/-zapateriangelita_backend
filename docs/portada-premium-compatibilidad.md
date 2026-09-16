# Compatibilidad — portada premium Angelita

Fecha: 2026-09-16

## Alcance revisado

La renovación de la portada es exclusivamente visual y conserva los contratos actuales del frontend con Laravel:

- `GET /api/products`
- `GET /api/public/categories`
- `GET /api/public/subcategories`
- `POST /api/leads`
- autenticación con Sanctum
- carrito, checkout, pedidos y pagos Openpay

## Base de datos

No se requieren migraciones, cambios de tablas, seeders ni ajustes de datos. Los modelos y respuestas consumidos por la portada permanecen sin modificaciones.

## Riesgo operativo

Bajo. No se cambian rutas, controladores, validaciones, autenticación, pagos ni persistencia. La rama del backend documenta la revisión de compatibilidad para mantener trazabilidad del despliegue coordinado.
