# Mandanten-Umstellung deployen (FTP)

Im Wartungsfenster durchführen. Reihenfolge: Backup, Migration, Dateien, Prüfen. Die Migration vor den Dateien, weil der neue Code `tenant_id` und `is_super_admin` voraussetzt.

## 1. Vorbereitung

- [ ] Datenbank in phpMyAdmin exportieren (komplett, mit Daten).
- [ ] Alle PHP-Dateien per FTP herunterladen und gegen das Repo vergleichen. Abweichungen erst klären.
- [ ] Prüfen, welches Konto Super-Admin werden soll. Die Migration nimmt automatisch das älteste Admin-Konto (kleinste `id` mit `role = 'admin'`), falls es noch keinen Super-Admin gibt.

## 2. Migration

1. phpMyAdmin öffnen, Datenbank wählen, Reiter "SQL".
2. Inhalt von `sql/migrationen/migration_2026_07_10_multi_tenant.sql` einfügen und ausführen.
3. Die letzte Abfrage zeigt den Super-Admin. Soll es ein anderes Konto sein:
   ```sql
   UPDATE users SET is_super_admin = 0;
   UPDATE users SET is_super_admin = 1 WHERE id = <id> AND role = 'admin';
   ```

Die Migration ist idempotent. Bricht sie mittendrin ab, Ursache beheben und das Skript einfach noch einmal komplett ausführen.

## 3. Dateien hochladen

Alle Dateien, die sich seit Tag `prod-2026-10-15` geändert haben:

```bash
git diff --name-only prod-2026-10-15 feature/mandanten
```

Neu sind unter anderem `models/Tenant.php` und `admin_manage_tenants.php`. Nicht hochladen: `sql/`, `bin/`, `.github/`, Markdown-Dateien.

Nach dem Upload sind alle alten Sessions ungültig (kein Mandant in der Session) und landen beim Login. Das ist gewollt.

Nach dem Livegang einmal `SELECT ip_address, attempted_at FROM login_attempts ORDER BY id DESC LIMIT 5;` ansehen. Steht dort bei verschiedenen Logins immer dieselbe Adresse, läuft der Server hinter einem Proxy. Dann `TRUSTED_PROXIES` setzen, sonst sperrt die IP-Sperre alle Benutzer eines Mandanten gemeinsam.

## 4. Prüfen

1. Mit dem Super-Admin einloggen. Solange es nur einen Mandanten gibt, sieht das Login-Formular aus wie vorher.
2. Dashboard, Hunde, Halter, Züchter, Rassen, Tests öffnen.
3. Verwaltung → Mandanten verwalten: Mandant "Default" ist da.
4. Optional einen Test-Mandanten anlegen, darin einen Admin, mit Kürzel einloggen, prüfen, dass keine Daten aus "Default" sichtbar sind. Danach den Test-Mandanten deaktivieren.

## Login und Mandanten

- Der Mandant kommt aus der Subdomain (`kunde1.deine-domain.de` → Kürzel `kunde1`).
- Passt die Subdomain zu keinem Mandanten und gibt es mehr als einen aktiven Mandanten, zeigt das Login-Formular ein Feld "Mandant". Leer gelassen gilt der Mandant `default`.
- Benutzernamen sind nur pro Mandant eindeutig.
- Nur der Super-Admin verwaltet Mandanten und wählt beim Anlegen von Benutzern den Mandanten. Normale Admins verwalten nur Benutzer ihres Mandanten und können Super-Admin-Konten nicht ändern.
- Deaktivieren eines Mandanten oder Benutzers wirkt sofort: neue Logins sind gesperrt, laufende Sessions enden beim nächsten Seitenaufruf. Rolle und Rechte werden bei jedem Seitenaufruf aus der Datenbank übernommen.
- Login-Sperre: 5 Fehlversuche in 15 Minuten, gezählt pro Mandant (Benutzername und IP). Die IP kommt aus `REMOTE_ADDR`. Steht der Server hinter einem eigenen Proxy, dessen Adresse in `TRUSTED_PROXIES` (config/security.php) eintragen.

## Rollback

1. Dateien aus dem Backup zurückspielen.
2. `sql/migrationen/rollback_multi_tenant.sql` in phpMyAdmin ausführen.

Das Rollback bricht ab, bevor es etwas ändert, wenn es schon Daten in einem anderen Mandanten als `default` gibt (Fehler `Table '...abbruch_...' doesn't exist`). Diese Daten erst sichern und löschen. Danach ist das Schema wieder auf dem Stand von `prod-2026-10-15`, inklusive `uq_username` und `uq_test_name`.

## Getestet

- Kopie der Prod-DB vom 15.10.2026 in Laragon (MySQL 8.4): Migration zweimal, Rollback zweimal, Schema und Daten danach identisch mit vorher.
- CI-Job `migration` macht dasselbe bei jedem Push auf MariaDB 10.11 mit dem Schema vom Tag `prod-2026-10-15`. Noch nicht gelaufen, solange der Branch nicht gepusht ist.
