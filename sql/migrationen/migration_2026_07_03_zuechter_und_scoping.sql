-- =====================================================================
-- migration_2026_07_03_zuechter_und_scoping.sql
--
-- 1) Neue Tabelle `zuechter` (Züchter), gleiche Form wie `halter`,
--    ebenfalls user-scoped über created_by.
-- 2) `dogs.zuechter_id` — optionale Verknüpfung "von welchem Züchter
--    stammt dieser Hund", analog zu dogs.halter_id.
-- 3) Portal-Umstellung: Hunde/Halter/Züchter sind ab jetzt strikt
--    privat pro Nutzer (Admin sieht alles, User nur eigene). Das ist
--    reine Code-Logik (siehe models/Dog.php, models/Halter.php,
--    models/Zuechter.php + die jeweiligen Seiten) — hier auf DB-Ebene
--    nur die neue Tabelle + Spalte, keine Datenänderung nötig, da
--    created_by auf dogs/halter bereits existiert.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `zuechter` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(150) NOT NULL,
    `telefon`     VARCHAR(50)  NULL,
    `email`       VARCHAR(150) NULL,
    `adresse`     VARCHAR(255) NULL,
    `notizen`     TEXT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`  INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    KEY `idx_zuechter_name` (`name`),
    KEY `idx_zuechter_created_by` (`created_by`),
    CONSTRAINT `fk_zuechter_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_zuechter_id := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dogs' AND COLUMN_NAME = 'zuechter_id'
);
SET @sql_zuechter_id := IF(@has_zuechter_id = 0,
    'ALTER TABLE `dogs` ADD COLUMN `zuechter_id` INT UNSIGNED NULL AFTER `halter_id`',
    'SELECT 1'
);
PREPARE stmt FROM @sql_zuechter_id; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_fk_zuechter := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dogs' AND CONSTRAINT_NAME = 'fk_dogs_zuechter'
);
SET @sql_fk_zuechter := IF(@has_fk_zuechter = 0,
    'ALTER TABLE `dogs` ADD CONSTRAINT `fk_dogs_zuechter` FOREIGN KEY (`zuechter_id`) REFERENCES `zuechter` (`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql_fk_zuechter; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_dogzuechter := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dogs' AND INDEX_NAME = 'idx_dog_zuechter'
);
SET @sql_idx_dogzuechter := IF(@idx_dogzuechter = 0,
    'ALTER TABLE `dogs` ADD INDEX `idx_dog_zuechter` (`zuechter_id`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql_idx_dogzuechter; EXECUTE stmt; DEALLOCATE PREPARE stmt;
