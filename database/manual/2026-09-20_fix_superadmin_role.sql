/* ============================================================
   ZAPATERÍA ANGELITA
   CORRECCIÓN DEFINITIVA DE ROLES: SUPERADMIN
   Fecha: 20/09/2026

   Diagnóstico:
   En producción users.role quedó como ENUM('admin','user').
   Por eso phpMyAdmin solo muestra admin/user y no acepta superadmin.

   Este script:
   1) No elimina usuarios.
   2) Amplía el ENUM a user/admin/superadmin.
   3) Deja soporte@vaftec.com.pe como superadmin.
   4) Mantiene vaftec@gmail.com como admin si existe aparte.
   5) Crea la tabla de auditoría si falta.
   ============================================================ */

START TRANSACTION;

/* 1. Sanear cualquier valor histórico no reconocido */
UPDATE users
SET role = 'user',
    updated_at = NOW()
WHERE role IS NULL
   OR role NOT IN ('user', 'admin');

/* 2. CORRECCIÓN CLAVE: ampliar el catálogo real del campo role */
ALTER TABLE users
MODIFY COLUMN role ENUM('user','admin','superadmin')
NOT NULL DEFAULT 'user';

/* 3. Tabla de auditoría de cambios de rol */
CREATE TABLE IF NOT EXISTS user_role_audits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    old_role VARCHAR(30) NOT NULL,
    new_role VARCHAR(30) NOT NULL,
    ip_address VARCHAR(64) NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY user_role_audits_user_created_index (user_id, created_at),
    KEY user_role_audits_actor_created_index (actor_user_id, created_at),
    CONSTRAINT user_role_audits_user_id_foreign
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT user_role_audits_actor_user_id_foreign
        FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* 4. Detectar cuentas */
SET @support_id := (
    SELECT id
    FROM users
    WHERE LOWER(TRIM(email)) = 'soporte@vaftec.com.pe'
    ORDER BY id
    LIMIT 1
);

SET @legacy_id := (
    SELECT id
    FROM users
    WHERE LOWER(TRIM(email)) = 'vaftec@gmail.com'
    ORDER BY id
    LIMIT 1
);

/* 5. Si soporte existe, queda como SUPERADMIN */
UPDATE users
SET email = 'soporte@vaftec.com.pe',
    role = 'superadmin',
    updated_at = NOW()
WHERE id = @support_id
  AND @support_id IS NOT NULL;

/* 6. Si también existe vaftec@gmail.com como cuenta distinta, queda ADMIN */
UPDATE users
SET email = 'vaftec@gmail.com',
    role = 'admin',
    updated_at = NOW()
WHERE id = @legacy_id
  AND @legacy_id IS NOT NULL
  AND @support_id IS NOT NULL
  AND @legacy_id <> @support_id;

/* 7. Si soporte no existe pero vaftec@gmail.com sí, reutilizar esa cuenta */
UPDATE users
SET email = 'soporte@vaftec.com.pe',
    role = 'superadmin',
    updated_at = NOW()
WHERE id = @legacy_id
  AND @legacy_id IS NOT NULL
  AND @support_id IS NULL;

COMMIT;

/* ============================================================
   VERIFICACIÓN
   ============================================================ */

/* A. Confirmar definición del campo: debe incluir superadmin */
SHOW COLUMNS FROM users LIKE 'role';

/* B. Confirmar las cuentas administrativas */
SELECT id, name, email, role, updated_at
FROM users
WHERE LOWER(TRIM(email)) IN (
    'soporte@vaftec.com.pe',
    'vaftec@gmail.com'
)
ORDER BY id;

/* C. Resultado de control: debe ser 1 para soporte */
SELECT
    CASE
        WHEN EXISTS (
            SELECT 1
            FROM users
            WHERE LOWER(TRIM(email)) = 'soporte@vaftec.com.pe'
              AND role = 'superadmin'
        )
        THEN 1 ELSE 0
    END AS soporte_es_superadmin;
