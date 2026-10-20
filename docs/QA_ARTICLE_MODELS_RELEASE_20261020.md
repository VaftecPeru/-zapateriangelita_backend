# Angelita V3: verificación de liberación
Fecha: 2026-10-20. El cambio está limitado a develop hasta autorización de merge a main.

CONTRATO DE DATOS
products.product_code: código general.
products.model: modelo general opcional.
product_variant_stocks.article_model: modelo editable por color, independiente del código general.
product_variant_stocks.sku: SKU editable y único para cada color + talla, opcional por compatibilidad histórica.
product_variant_stocks.stock: inventario por color/talla.
Reimportar no elimina el SKU previo si el Excel no suministra SKU.
No publicar productos sin precio/inventario válido.

CRITERIOS DE CALIDAD
1. Backend PHPUnit sobre base QA aislada y CI MariaDB 10.11 en GitHub.
2. Frontend build Vite, pruebas UI (incluida interacción real DOM) y ESLint.
3. Comprobar registro/edición, SKU duplicado, variante repetida, cantidad inválida, modelos diferentes por color, producto simple y datos legados.
4. Archivo XLSX maestro: 239 filas excluidas, 3 borradores, 12 variantes, 44 unidades, 0 publicados.
5. UAT real en navegador, móvil/escritorio, imágenes por color, flujo checkout y Openpay sandbox antes de producción.

MIGRACIÓN NUEVA — NO APLICADA EN CPANEL
database/migrations/2026_10_20_160000_add_article_model_and_sku_to_variant_stocks.php
Columnas nuevas nullable article_model VARCHAR(120), sku VARCHAR(120), índice UNIQUE sku.
En cPanel sin terminal: respaldos completos verificables, revisar esquema en phpMyAdmin ANTES de ejecutar cambio manual,
y registrar migración únicamente tras comprobar ejecución real. No repetir ALTER ni registro ni cambiar la BD por anticipado.
No extraer frontend nuevo mientras backend y esquema productivos no estén alineados.

DESPLIEGUE FUTURO (REQUIERE AUTORIZACIÓN EXPLÍCITA)
Respaldos SQL y archivos -> preflight DDL MariaDB -> migración -> backend -> smoke API -> frontend dist main -> UAT/rollback.
Preservar .env, vendor, storage, uploads, permisos y .htaccess; no generar ZIP sin commit main final.

OBSERVACIONES DE QA
- Se evita borrar y recrear filas existentes al editar modelos, SKU o precio; se mantienen IDs.
- Modelos/SKU heredados se preservan cuando clientes antiguos no envían esos atributos.
- Se valida la estructura completa, sin omitir silenciosamente variantes duplicadas ni stock inválido.
- Se mantuvo soporte de producto simple con una talla y stock histórico.
