-- migration_2026_07_07_portal_berechtigungen.sql
--
-- Fügt zwei granulare Portal-Rechte für normale Benutzer hinzu:
-- can_manage_breeds (Rassen-Katalog verwalten) und can_manage_tests
-- (Test-Katalog verwalten). Admins dürfen weiterhin unabhängig davon
-- alles (role='admin' schaltet in config/auth.php beide Rechte frei).
-- Idempotent, da ALTER TABLE ... ADD COLUMN sonst bei erneutem Lauf
-- fehlschlägt (Spalte existiert dann schon).

SET @spalte_vorhanden_breeds = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'can_manage_breeds'
);

SET @sql_breeds = IF(
    @spalte_vorhanden_breeds = 0,
    'ALTER TABLE `users` ADD COLUMN `can_manage_breeds` TINYINT(1) NOT NULL DEFAULT 0 AFTER `role`',
    'SELECT 1'
);

PREPARE stmt_breeds FROM @sql_breeds;
EXECUTE stmt_breeds;
DEALLOCATE PREPARE stmt_breeds;

SET @spalte_vorhanden_tests = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'can_manage_tests'
);

SET @sql_tests = IF(
    @spalte_vorhanden_tests = 0,
    'ALTER TABLE `users` ADD COLUMN `can_manage_tests` TINYINT(1) NOT NULL DEFAULT 0 AFTER `can_manage_breeds`',
    'SELECT 1'
);

PREPARE stmt_tests FROM @sql_tests;
EXECUTE stmt_tests;
DEALLOCATE PREPARE stmt_tests;
