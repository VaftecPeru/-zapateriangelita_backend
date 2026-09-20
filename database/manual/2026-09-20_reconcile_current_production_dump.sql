START TRANSACTION;

UPDATE products
SET product_code = NULLIF(TRIM(product_code), '')
WHERE product_code IS NOT NULL;

ALTER TABLE products
MODIFY COLUMN price DECIMAL(10,2) NOT NULL,
MODIFY COLUMN discount DECIMAL(5,2) NULL,
MODIFY COLUMN img TEXT NULL,
MODIFY COLUMN stock INT UNSIGNED NOT NULL DEFAULT 0;

SET @duplicate_product_codes := (
    SELECT COUNT(*)
    FROM (
        SELECT product_code
        FROM products
        WHERE product_code IS NOT NULL
        GROUP BY product_code
        HAVING COUNT(*) > 1
    ) AS duplicated_codes
);

SET @has_product_code_unique := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'products'
      AND INDEX_NAME = 'products_product_code_unique'
);

SET @sql := IF(
    @duplicate_product_codes = 0 AND @has_product_code_unique = 0,
    'ALTER TABLE products ADD UNIQUE KEY products_product_code_unique (product_code)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_status_stock_index := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'products'
      AND INDEX_NAME = 'products_status_stock_index'
);

SET @sql := IF(
    @has_status_stock_index = 0,
    'ALTER TABLE products ADD INDEX products_status_stock_index (status, stock)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_address_default_index := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'addresses'
      AND INDEX_NAME = 'addresses_user_default_index'
);

SET @sql := IF(
    @has_address_default_index = 0,
    'ALTER TABLE addresses ADD INDEX addresses_user_default_index (user_id, is_default)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE product_colors
SET hex = CASE LOWER(TRIM(color))
    WHEN 'arena' THEN '#d6b98c'
    WHEN 'café' THEN '#6f4e37'
    WHEN 'cafe' THEN '#6f4e37'
    WHEN 'crema' THEN '#fffdd0'
    WHEN 'marron oscuro' THEN '#4a2c2a'
    WHEN 'marrón oscuro' THEN '#4a2c2a'
    WHEN 'celeste' THEN '#7dd3fc'
    WHEN 'azul cielo' THEN '#7dd3fc'
    WHEN 'turquesa' THEN '#2dd4bf'
    WHEN 'naranja' THEN '#f97316'
    WHEN 'vino' THEN '#7f1d1d'
    WHEN 'dorado' THEN '#d4a017'
    WHEN 'plateado' THEN '#c0c0c0'
    ELSE hex
END
WHERE hex IS NULL OR hex = '#888888';

UPDATE brands
SET slug = LOWER(REPLACE(TRIM(name), ' ', '-'))
WHERE slug IS NULL OR TRIM(slug) = '';

DELETE a_old
FROM addresses a_old
JOIN addresses a_keep
  ON a_old.user_id = a_keep.user_id
 AND (a_old.full_name <=> a_keep.full_name)
 AND (a_old.phone <=> a_keep.phone)
 AND (a_old.country <=> a_keep.country)
 AND (a_old.state <=> a_keep.state)
 AND (a_old.municipality <=> a_keep.municipality)
 AND (a_old.address <=> a_keep.address)
 AND (a_old.city <=> a_keep.city)
 AND (a_old.postal_code <=> a_keep.postal_code)
 AND (a_old.reference <=> a_keep.reference)
 AND (
      a_keep.is_default > a_old.is_default
      OR (a_keep.is_default = a_old.is_default AND a_keep.id > a_old.id)
 );

DELETE pat
FROM personal_access_tokens pat
LEFT JOIN users u
  ON pat.tokenable_type = 'App\\Models\\User'
 AND pat.tokenable_id = u.id
WHERE pat.tokenable_type = 'App\\Models\\User'
  AND u.id IS NULL;

DELETE pat
FROM personal_access_tokens pat
JOIN (
    SELECT id
    FROM (
        SELECT
            id,
            ROW_NUMBER() OVER (
                PARTITION BY tokenable_type, tokenable_id
                ORDER BY id DESC
            ) AS rn
        FROM personal_access_tokens
        WHERE tokenable_type = 'App\\Models\\User'
    ) ranked
    WHERE rn > 20
) old_tokens ON old_tokens.id = pat.id;

SET @migration_batch := COALESCE((SELECT MAX(batch) FROM migrations), 0) + 1;

INSERT INTO migrations (migration, batch)
SELECT '2026_09_19_010000_add_integral_feedback_support', @migration_batch
WHERE NOT EXISTS (
    SELECT 1 FROM migrations
    WHERE migration = '2026_09_19_010000_add_integral_feedback_support'
);

INSERT INTO migrations (migration, batch)
SELECT '2026_09_19_230500_add_superadmin_role_management', @migration_batch
WHERE NOT EXISTS (
    SELECT 1 FROM migrations
    WHERE migration = '2026_09_19_230500_add_superadmin_role_management'
);

INSERT INTO migrations (migration, batch)
SELECT '2026_09_20_010000_fix_users_role_superadmin_enum', @migration_batch
WHERE NOT EXISTS (
    SELECT 1 FROM migrations
    WHERE migration = '2026_09_20_010000_fix_users_role_superadmin_enum'
);

INSERT INTO migrations (migration, batch)
SELECT '2026_09_20_020000_add_delivery_configuration_to_additional_services', @migration_batch
WHERE NOT EXISTS (
    SELECT 1 FROM migrations
    WHERE migration = '2026_09_20_020000_add_delivery_configuration_to_additional_services'
);

INSERT INTO migrations (migration, batch)
SELECT '2026_09_20_030000_add_purchase_email_sent_at_to_orders', @migration_batch
WHERE NOT EXISTS (
    SELECT 1 FROM migrations
    WHERE migration = '2026_09_20_030000_add_purchase_email_sent_at_to_orders'
);

INSERT INTO migrations (migration, batch)
SELECT '2026_09_20_040000_harden_production_data_integrity', @migration_batch
WHERE NOT EXISTS (
    SELECT 1 FROM migrations
    WHERE migration = '2026_09_20_040000_harden_production_data_integrity'
);

COMMIT;

SHOW COLUMNS FROM products LIKE 'price';
SHOW COLUMNS FROM products LIKE 'img';

SELECT COUNT(*) AS duplicate_product_codes
FROM (
    SELECT product_code
    FROM products
    WHERE product_code IS NOT NULL
    GROUP BY product_code
    HAVING COUNT(*) > 1
) duplicated_codes;

SELECT user_id, COUNT(*) AS address_count, SUM(is_default) AS default_count
FROM addresses
GROUP BY user_id
ORDER BY user_id;

SELECT tokenable_id, COUNT(*) AS active_tokens
FROM personal_access_tokens
WHERE tokenable_type = 'App\\Models\\User'
GROUP BY tokenable_id
ORDER BY active_tokens DESC;

SELECT migration, batch
FROM migrations
WHERE migration IN (
    '2026_09_19_010000_add_integral_feedback_support',
    '2026_09_19_230500_add_superadmin_role_management',
    '2026_09_20_010000_fix_users_role_superadmin_enum',
    '2026_09_20_020000_add_delivery_configuration_to_additional_services',
    '2026_09_20_030000_add_purchase_email_sent_at_to_orders',
    '2026_09_20_040000_harden_production_data_integrity'
)
ORDER BY migration;
