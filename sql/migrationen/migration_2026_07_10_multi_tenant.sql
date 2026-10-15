-- =====================================================================
-- Migration 2026-07-10: Multi-Tenant-Fähigkeit
-- 
-- Fügt Mandantenfähigkeit (Multi-Tenancy) zur Anwendung hinzu.
-- Strategie: Shared Database + Shared Schema mit tenant_id Spalten.
--
-- Kompatibel mit: MySQL 5.7+ / MariaDB 10.3+
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. Neue Tabelle: tenants
-- Verwaltet alle Mandanten der Anwendung
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tenants` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(100) NOT NULL,           -- Anzeigename des Mandanten
    `subdomain`   VARCHAR(50)  NOT NULL,           -- Subdomain für Identifikation (z.B. "kunde1")
    `is_active`   TINYINT(1)   NOT NULL DEFAULT 1, -- Mandanten können deaktiviert werden
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_subdomain` (`subdomain`),
    KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Default-Tenant erstellen (für bestehende Daten)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `tenants` (`id`, `name`, `subdomain`, `is_active`, `created_at`)
VALUES (1, 'Default', 'default', 1, NOW());

-- ---------------------------------------------------------------------
-- 3. users Tabelle erweitern
-- ---------------------------------------------------------------------
ALTER TABLE `users` 
    ADD COLUMN `tenant_id` INT UNSIGNED NULL AFTER `id`,
    ADD INDEX `idx_tenant_id` (`tenant_id`);

-- Bestehende Users dem Default-Tenant zuordnen
UPDATE `users` SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;

-- Constraint hinzufügen (nachdem alle Werte gesetzt sind)
ALTER TABLE `users` 
    ADD CONSTRAINT `fk_users_tenant` 
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT;

-- Unique Constraint ändern: username muss pro Tenant eindeutig sein
ALTER TABLE `users` 
    DROP INDEX `uq_username`,
    ADD UNIQUE KEY `uq_tenant_username` (`tenant_id`, `username`);

-- ---------------------------------------------------------------------
-- 4. dogs Tabelle erweitern
-- ---------------------------------------------------------------------
ALTER TABLE `dogs` 
    ADD COLUMN `tenant_id` INT UNSIGNED NULL AFTER `id`,
    ADD INDEX `idx_tenant_id` (`tenant_id`);

UPDATE `dogs` SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;

ALTER TABLE `dogs` 
    ADD CONSTRAINT `fk_dogs_tenant` 
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT;

-- ---------------------------------------------------------------------
-- 5. halter Tabelle erweitern
-- ---------------------------------------------------------------------
ALTER TABLE `halter` 
    ADD COLUMN `tenant_id` INT UNSIGNED NULL AFTER `id`,
    ADD INDEX `idx_tenant_id` (`tenant_id`);

UPDATE `halter` SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;

ALTER TABLE `halter` 
    ADD CONSTRAINT `fk_halter_tenant` 
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT;

-- ---------------------------------------------------------------------
-- 6. zuechter Tabelle erweitern
-- ---------------------------------------------------------------------
ALTER TABLE `zuechter` 
    ADD COLUMN `tenant_id` INT UNSIGNED NULL AFTER `id`,
    ADD INDEX `idx_tenant_id` (`tenant_id`);

UPDATE `zuechter` SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;

ALTER TABLE `zuechter` 
    ADD CONSTRAINT `fk_zuechter_tenant` 
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT;

-- ---------------------------------------------------------------------
-- 7. tests Tabelle erweitern
-- ---------------------------------------------------------------------
ALTER TABLE `tests` 
    ADD COLUMN `tenant_id` INT UNSIGNED NULL AFTER `id`,
    ADD INDEX `idx_tenant_id` (`tenant_id`);

UPDATE `tests` SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;

ALTER TABLE `tests` 
    ADD CONSTRAINT `fk_tests_tenant` 
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT;

-- ---------------------------------------------------------------------
-- 8. Optional: breeds, tags, activities als tenant-spezifisch machen
-- 
-- HINWEIS: Diese Tabellen können auch global bleiben (ohne tenant_id),
-- wenn sie als geteilte Katalogdaten für alle Mandanten dienen sollen.
-- Für strikte Mandanten-Trennung kommentieren Sie die folgenden Blöcke ein.
-- ---------------------------------------------------------------------

-- -- breeds Tabelle erweitern (für strikte Trennung)
-- ALTER TABLE `breeds` 
--     ADD COLUMN `tenant_id` INT UNSIGNED NULL AFTER `id`,
--     ADD INDEX `idx_tenant_id` (`tenant_id`);
-- 
-- UPDATE `breeds` SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;
-- 
-- ALTER TABLE `breeds` 
--     ADD CONSTRAINT `fk_breeds_tenant` 
--     FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT;

-- -- tags Tabelle erweitern (für strikte Trennung)
-- ALTER TABLE `tags` 
--     ADD COLUMN `tenant_id` INT UNSIGNED NULL AFTER `id`,
--     ADD INDEX `idx_tenant_id` (`tenant_id`);
-- 
-- UPDATE `tags` SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;
-- 
-- ALTER TABLE `tags` 
--     ADD CONSTRAINT `fk_tags_tenant` 
--     FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT;

-- -- activities Tabelle erweitern (für strikte Trennung)
-- ALTER TABLE `activities` 
--     ADD COLUMN `tenant_id` INT UNSIGNED NULL AFTER `id`,
--     ADD INDEX `idx_tenant_id` (`tenant_id`);
-- 
-- UPDATE `activities` SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;
-- 
-- ALTER TABLE `activities` 
--     ADD CONSTRAINT `fk_activities_tenant` 
--     FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT;

-- ---------------------------------------------------------------------
-- 9. tenant_id Spalten zu NOT NULL machen (nach Migration)
-- ---------------------------------------------------------------------
ALTER TABLE `users` MODIFY COLUMN `tenant_id` INT UNSIGNED NOT NULL;
ALTER TABLE `dogs` MODIFY COLUMN `tenant_id` INT UNSIGNED NOT NULL;
ALTER TABLE `halter` MODIFY COLUMN `tenant_id` INT UNSIGNED NOT NULL;
ALTER TABLE `zuechter` MODIFY COLUMN `tenant_id` INT UNSIGNED NOT NULL;
ALTER TABLE `tests` MODIFY COLUMN `tenant_id` INT UNSIGNED NOT NULL;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- MIGRATION ABGESCHLOSSEN
-- 
-- Nächste Schritte:
-- 1. config/tenant.php erstellen für Tenant-Resolving
-- 2. config/auth.php für Tenant-Kontext in Session erweitern
-- 3. Model-Schicht für Tenant-Scoping anpassen
-- 4. Admin-Views für Tenant-Management erweitern
-- =====================================================================
