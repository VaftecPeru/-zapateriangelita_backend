/* ============================================================
   ZAPATERÍA ANGELITA
   MIGRACIÓN MANUAL - COSTO DE DELIVERY DESDE SERVICIOS
   Fecha: 20/09/2026
   ============================================================ */

START TRANSACTION;

/* 1. Agregar código técnico del servicio si no existe */
SET @has_code := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'additional_services'
      AND COLUMN_NAME = 'code'
);

SET @sql := IF(
    @has_code = 0,
    'ALTER TABLE additional_services ADD COLUMN code VARCHAR(50) NULL AFTER name',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

/* 2. Agregar bandera activo/inactivo si no existe */
SET @has_active := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'additional_services'
      AND COLUMN_NAME = 'is_active'
);

SET @sql := IF(
    @has_active = 0,
    'ALTER TABLE additional_services ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER tag',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

/* 3. Crear índice único del código si no existe */
SET @has_code_unique := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'additional_services'
      AND INDEX_NAME = 'additional_services_code_unique'
);

SET @sql := IF(
    @has_code_unique = 0,
    'ALTER TABLE additional_services ADD UNIQUE KEY additional_services_code_unique (code)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

/* 4. Crear índice de estado si no existe */
SET @has_active_index := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'additional_services'
      AND INDEX_NAME = 'additional_services_is_active_index'
);

SET @sql := IF(
    @has_active_index = 0,
    'ALTER TABLE additional_services ADD INDEX additional_services_is_active_index (is_active)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

/* 5. Identificar un servicio de delivery ya existente */
SET @delivery_id := (
    SELECT id
    FROM additional_services
    WHERE LOWER(name) LIKE '%delivery%'
       OR LOWER(name) LIKE '%envio%'
    ORDER BY id
    LIMIT 1
);

/* 6. Si existe, convertirlo en el concepto oficial */
UPDATE additional_services
SET code = 'delivery',
    is_active = 1,
    updated_at = NOW()
WHERE id = @delivery_id
  AND @delivery_id IS NOT NULL;

/* 7. Si no existe, crear el concepto oficial con costo inicial 0 */
INSERT INTO additional_services
    (name, code, description, price, tag, is_active, created_at, updated_at)
SELECT
    'Costo de delivery',
    'delivery',
    'Costo de entrega aplicado automáticamente al total de la compra.',
    0.00,
    'Delivery',
    1,
    NOW(),
    NOW()
WHERE @delivery_id IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM additional_services WHERE code = 'delivery'
  );

COMMIT;

/* ============================================================
   VERIFICACIÓN
   ============================================================ */

SELECT
    id,
    name,
    code,
    price,
    tag,
    is_active,
    updated_at
FROM additional_services
WHERE code = 'delivery';

SHOW COLUMNS FROM additional_services LIKE 'code';
SHOW COLUMNS FROM additional_services LIKE 'is_active';
