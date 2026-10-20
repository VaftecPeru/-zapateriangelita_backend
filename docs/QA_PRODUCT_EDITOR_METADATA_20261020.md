# Angelita — Metadatos del producto y publicación segura (20/10/2026)

## Alcance implementado

- `products.gender`: mujer, hombre, ninos, unisex. Opcional, editable desde React.
- `products.barcode`: VARCHAR(120), único si se informa, valores vacíos se guardan como NULL.
- `products.collection_name`: VARCHAR(120), nombre libre por producto. No constituye un CRUD de colecciones.
- `products.seo_title`: VARCHAR(255), `products.seo_description`: VARCHAR(500), ambos editables; el frontend público aún debe consumirlos en etiquetas SEO si se desea SEO dinámico.
- `products.product_type`: simple o variable; NULL en filas heredadas para no cambiar su semántica automáticamente.
- POST `/api/products`: respeta `is_active=0` para crear borradores con precio positivo y sin publicación automática. Si el parámetro se omite, conserva la conducta previa.
- PUT `/api/products/{id}`: respeta publicación explícita y edición de metadatos sin reponer stock ni alterar los SKU.
- Publicación de productos simples: validación de stock maestro y ausencia de variantes/colores/tallas. Los importados con existencias sin asignar mantienen el bloqueo previo.

## Evidencia UAT aislada — No implica producción

- API Laravel (SQLite aislada): `php artisan test`, **154 passed / 1177 assertions**. En Windows es necesario definir `OPENSSL_CONF` con el archivo real de PHP para las pruebas RSA Google; de lo contrario fallan por entorno.
- Casos nuevos `ProductCommercialMetadataTest`: 8 pruebas de CRUD, borrador, publicación, catálogos, código de barras único y productos simples (61 aserciones en la versión intermedia, ampliada al agregar 2 casos).
- Front React: `npm run build`, `npm run test:ui`, `npm run lint` correctos; `npm audit --omit=dev` sin vulnerabilidades reportadas.
- Chrome local: desktop 1536x1024 y mobile 390x844; `scrollWidth=390`, 3 variantes; carga de género, código de barras, título SEO y colección verificada. Capturas en `C:\Users\VAFTEC\Codex-Workspace\Angelita_UAT_Metadata_Desktop_20261020.png` y `Angelita_UAT_Metadata_Mobile_20261020.png`.
- UAT con sesión administrativa real y pago de prueba en hosting **pendiente de la ventana de despliegue autorizada**.

## Preflight de cPanel sin terminal — NO ejecutar antes de autorizar main

1. Exportar y verificar respaldo SQL completo de `zapateriangelita_bd`, y respaldo de directorios frontend/backend. No respaldar `.env` en carpetas públicas.
2. Confirmar DB del `.env` del backend y seleccionar exactamente esa base en phpMyAdmin.
3. Consultar columnas existentes:

```sql
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
  AND COLUMN_NAME IN ('gender','barcode','collection_name','seo_title','seo_description','product_type');
```

4. Solo si las seis columnas **no existen**, aplicar en phpMyAdmin:

```sql
ALTER TABLE products
  ADD COLUMN product_type VARCHAR(16) NULL,
  ADD COLUMN gender VARCHAR(12) NULL,
  ADD COLUMN barcode VARCHAR(120) NULL,
  ADD COLUMN collection_name VARCHAR(120) NULL,
  ADD COLUMN seo_title VARCHAR(255) NULL,
  ADD COLUMN seo_description VARCHAR(500) NULL,
  ADD UNIQUE INDEX products_barcode_unique (barcode);
```

   Si alguna ya existe, detener y preparar ALTER individual revisando índices; no repetir el bloque anterior ni modificar columnas existentes a ciegas. Validar índice con `SHOW INDEX FROM products WHERE Key_name='products_barcode_unique';`.
5. Tras verificar tabla e índice y **solo** cuando no exista registro, regularizar `migrations`:

```sql
INSERT INTO migrations (migration, batch)
SELECT '2026_10_20_220000_add_commercial_metadata_to_products', current_batch.next_batch
FROM (SELECT COALESCE(MAX(batch), 0) + 1 AS next_batch FROM migrations) AS current_batch
WHERE NOT EXISTS (
    SELECT 1 FROM migrations
    WHERE migration = '2026_10_20_220000_add_commercial_metadata_to_products'
);
```

6. Aplicar backend `main` aprobado, preservando `.env`, `vendor`, `storage` y archivos cargados; luego desplegar `dist` del frontend aprobado en `public_html/` (sin carpeta anidada), sin tocar `public_html/api.zapateriangelita.com/` con el ZIP frontend.
7. Smoke tests producción con cuenta administrativa autorizada: registrar borrador con precio positivo, confirmar no visible; editar campos y colección; publicar producto con variantes y SKU; registrar producto simple; importar V3 sin cambiar stock y probar cesta/checkout en modo de prueba. No probar pagos reales.

## Reversa / contingencia

Conservar respaldos y versiones de despliegue anteriores. Si se requiere revertir código, volver al backend/frontend anteriores **sin eliminar las seis columnas** (son aditivas y nullable). No restaurar la base completa sobre nuevos pedidos sin reconciliación transaccional. Nunca subir `.env` ni bases QA a GitHub.
