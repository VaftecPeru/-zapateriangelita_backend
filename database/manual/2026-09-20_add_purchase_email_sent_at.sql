SET @has_purchase_email_sent_at := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'purchase_email_sent_at'
);

SET @sql := IF(
    @has_purchase_email_sent_at = 0,
    'ALTER TABLE orders ADD COLUMN purchase_email_sent_at TIMESTAMP NULL AFTER paid_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_purchase_email_index := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND INDEX_NAME = 'orders_purchase_email_sent_at_index'
);

SET @sql := IF(
    @has_purchase_email_index = 0,
    'ALTER TABLE orders ADD INDEX orders_purchase_email_sent_at_index (purchase_email_sent_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SHOW COLUMNS FROM orders LIKE 'purchase_email_sent_at';
