# Multi-Tenancy Deployment Checkliste (FTP)

## Vorbereitung

### 1. Backup erstellen
- [ ] Datenbank-Backup über phpMyAdmin exportieren
- [ ] Optional: Backup aller PHP-Dateien per FTP herunterladen

### 2. Dateien für Upload vorbereiten
Alle folgenden Dateien müssen per FTP hochgeladen werden:

#### Neue Dateien
- `admin_manage_tenants.php` (neu)

#### Geänderte Dateien
- `sql/migrationen/migration_2026_07_10_multi_tenant.sql`
- `sql/schema.sql`
- `config/auth.php`
- `models/User.php`
- `models/Dog.php`
- `models/Halter.php`
- `models/Zuechter.php`
- `models/Test.php`
- `admin_add_user.php`
- `admin_manage_users.php`
- `admin_manage_tests.php`
- `dogs.php`
- `dog_detail.php`
- `dog_form.php`
- `halter.php`
- `halter_detail.php`
- `halter_form.php`
- `halterbild.php`
- `zuechter.php`
- `zuechter_detail.php`
- `zuechter_form.php`
- `zuechterbild.php`
- `hundebild.php`
- `test_form.php`
- `views/partials/header.php`

## Deployment-Schritte

### Schritt 1: Dateien hochladen
1. Per FTP mit all-inkl. verbinden
2. Alle oben genannten Dateien in die entsprechenden Verzeichnisse hochladen
3. Bestehende Dateien überschreiben (Backup ist vorhanden)

### Schritt 2: Datenbank-Migration
1. phpMyAdmin auf all-inkl. öffnen
2. Reiter "SQL" wählen
3. Inhalt von `sql/migrationen/migration_2026_07_10_multi_tenant.sql` kopieren und ausführen
4. Erfolgsmeldung prüfen

### Schritt 3: Überprüfung
1. Tabelle `tenants` prüfen (sollte 1 Eintrag haben)
2. Tabellenstruktur prüfen:
   - `users.tenant_id` vorhanden
   - `dogs.tenant_id` vorhanden
   - `halter.tenant_id` vorhanden
   - `zuechter.tenant_id` vorhanden
   - `tests.tenant_id` vorhanden
3. Daten prüfen: Alle Einträge sollten `tenant_id = 1` haben

### Schritt 4: Funktionstest
1. Mit bestehendem Admin-Account einloggen
2. Unter "Verwaltung" → "Mandanten verwalten" neuen Tenant anlegen
3. Neuen Benutzer für neuen Tenant erstellen
4. Mit neuem Benutzer einloggen und Daten anlegen
5. Tenant-Isolation prüfen

## Rollback bei Problemen

Wenn Probleme auftreten, Rollback-Skript ausführen:
- Inhalt von `sql/migrationen/rollback_multi_tenant.sql` in phpMyAdmin ausführen
- Dateien aus Backup wiederherstellen

## Wichtige Hinweise

- MariaDB-Kompatibilität: Keine Änderungen nötig
- Keine Datenverlust durch Migration
- Alle bestehenden Daten werden Default-Tenant (ID 1) zugeordnet
- Während Wartungsfenster durchführen
