-- =====================================================================
-- Rollback zu migration_2026_10_17_rassen_englisch.sql
--
-- Ist das deutsche Feld leer, kommt der englische Wert zurück hinein
-- (so wie vor der Migration). Ein deutscher Wert bleibt, der englische
-- geht dann verloren, steht aber weiter in den Versionen der Rasse.
-- Versionen der Felder *_en bleiben in content_revisions liegen, sie
-- werden ohne die Spalten nur nicht mehr angezeigt.
--
-- Idempotent, ein zweiter Lauf ändert nichts.
-- =====================================================================

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'temperament_en'), 'UPDATE `breeds` SET `temperament` = `temperament_en` WHERE (`temperament` IS NULL OR `temperament` = '''') AND `temperament_en` IS NOT NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'zuchtzweck_en'), 'UPDATE `breeds` SET `zuchtzweck` = `zuchtzweck_en` WHERE (`zuchtzweck` IS NULL OR `zuchtzweck` = '''') AND `zuchtzweck_en` IS NOT NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'zuchtzweck_en'), 'ALTER TABLE `breeds` DROP COLUMN `zuchtzweck_en`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'temperament_en'), 'ALTER TABLE `breeds` DROP COLUMN `temperament_en`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'beschreibung_en'), 'ALTER TABLE `breeds` DROP COLUMN `beschreibung_en`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'name_en'), 'ALTER TABLE `breeds` DROP COLUMN `name_en`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
