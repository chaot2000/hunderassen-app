-- Rollback-Skript für Multi-Tenancy Migration
-- Führt dies nur aus, wenn bei der Migration Probleme aufgetreten sind
-- und du die Änderungen rückgängig machen möchtest.

-- WARNUNG: Dies löscht die tenants-Tabelle und alle tenant_id-Spalten
-- Stelle sicher, dass du ein Backup hast!

-- Fremdschlüssel-Constraints entfernen (falls vorhanden)
ALTER TABLE users DROP FOREIGN KEY IF EXISTS users_tenant_id_foreign;
ALTER TABLE dogs DROP FOREIGN KEY IF EXISTS dogs_tenant_id_foreign;
ALTER TABLE halter DROP FOREIGN KEY IF EXISTS halter_tenant_id_foreign;
ALTER TABLE zuechter DROP FOREIGN KEY IF EXISTS zuechter_tenant_id_foreign;
ALTER TABLE tests DROP FOREIGN KEY IF EXISTS tests_tenant_id_foreign;

-- tenant_id-Spalten entfernen
ALTER TABLE users DROP COLUMN tenant_id;
ALTER TABLE dogs DROP COLUMN tenant_id;
ALTER TABLE halter DROP COLUMN tenant_id;
ALTER TABLE zuechter DROP COLUMN tenant_id;
ALTER TABLE tests DROP COLUMN tenant_id;

-- tenants-Tabelle löschen
DROP TABLE IF EXISTS tenants;

-- Hinweis: Die ursprüngliche Struktur ist wiederhergestellt
-- Alle Daten bleiben erhalten (bis auf tenant_id Zuweisungen)
