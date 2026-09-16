-- Zapatería Angelita
-- Ejecutar UNA sola vez desde phpMyAdmin cuando no haya acceso a terminal.
-- Antes de ejecutar, realizar respaldo de la tabla users.

ALTER TABLE `users`
    ADD COLUMN `google_sub` VARCHAR(255) NULL AFTER `email`,
    ADD COLUMN `google_email` VARCHAR(255) NULL AFTER `google_sub`,
    ADD COLUMN `google_linked_at` TIMESTAMP NULL DEFAULT NULL AFTER `google_email`,
    ADD UNIQUE KEY `users_google_sub_unique` (`google_sub`),
    ADD KEY `users_google_email_index` (`google_email`);
