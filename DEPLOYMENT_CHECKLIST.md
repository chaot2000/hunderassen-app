# Deployment-Checkliste: Laragon → all-inkl

Kurze, abhakbare Zusammenfassung für den konkreten Umzug inkl.
bestehender lokaler Daten. Die ausführliche Begründung zu jedem Punkt
steht in `INSTALL.md` — hier nur die Reihenfolge, in der du tatsächlich
vorgehen solltest.

## Vorher (einmalig, schon erledigt)

- [x] `phpinfo.php` aus dem Projekt entfernt (lag ungeschützt im
      Web-Root — hätte auf Prod Serverpfade, PHP-Konfiguration und
      geladene Module öffentlich offengelegt)
- [x] HTTPS-Redirect ist jetzt aktiv in `.htaccess` (siehe INSTALL.md
      Punkt 7) — greift automatisch, sobald ein SSL-Zertifikat für die
      Domain aktiv ist

## 1. Datenbank auf all-inkl anlegen

- [ ] In der all-inkl-KAS neue MySQL-Datenbank anlegen, Zugangsdaten
      (Host, DB-Name, Benutzer, Passwort) notieren

## 2. Lokale Daten exportieren

- [ ] Laragon-phpMyAdmin → Datenbank `hunderassen` → Exportieren →
      Format SQL ("Schnell" reicht)
- [ ] Bei großen Hunde-/Rassebildern (BLOBs): Datei ggf. mit `gzip`
      komprimieren, phpMyAdmin akzeptiert `.sql.gz` direkt

## 3. Struktur + Daten in Prod importieren

- [ ] **Wichtig:** NICHT zusätzlich `sql/schema.sql` einspielen, wenn
      dein lokaler Export bereits die komplette Struktur enthält (bei
      "Schnell"-Export ist das der Fall) — sonst gibt es doppelte
      `CREATE TABLE`-Fehler. Export allein reicht für eine echte
      Erstinstallation auf Prod.
- [ ] all-inkl-phpMyAdmin → Ziel-Datenbank → Importieren → exportierte
      Datei hochladen

## 4. Zugangsdaten konfigurieren (NICHT die lokale Datei hochladen!)

- [ ] Auf dem Server **neu und direkt dort** `config/database.local.php`
      anlegen (per FTP-Editor oder all-inkl-Datei-Manager), Vorlage aus
      `config/database.local.example.php` nutzen, mit den **all-inkl**-
      Zugangsdaten aus Schritt 1 (nicht den lokalen Laragon-Werten
      `root` / leeres Passwort!)
- [ ] Prüfen: Deine lokale `config/database.local.php` (mit
      `user => 'root'`) ist NICHT Teil des Uploads — sie steht zwar in
      `.gitignore`, aber bei einem manuellen ZIP-Upload (statt Git)
      musst du selbst aufpassen, sie nicht mitzuschicken

## 5. Dateien hochladen

- [ ] Projektordner per FTP/SFTP nach `/www/htdocs/<dein-webspace>/`
      hochladen — **außer** `config/database.local.php` (kommt aus
      Schritt 4 direkt vom Server) und `.git/` (nicht nötig auf Prod)

## 6. logs/-Verzeichnis prüfen

- [ ] Ordner `logs/` existiert mit Schreibrechten (i. d. R. automatisch
      durch den Upload von `logs/.gitkeep`, sonst manuell 755 anlegen)

## 7. Admin-Zugang absichern

- [ ] Da die Daten aus deiner lokalen DB kommen, ist dein **lokaler**
      Admin-Account (inkl. Passwort-Hash) jetzt auch auf Prod aktiv.
      Trotzdem empfehlenswert: nach dem ersten Login auf Prod das
      Passwort einmal über `/account.php` ändern (dein neues
      Self-Service-Feature!) — dann ist sicher, dass Lokal- und
      Prod-Passwort unterschiedlich sind
- [ ] Optional: Admin-Benutzernamen in `admin_manage_users.php` ändern,
      falls `admin` nicht als Login öffentlich erratbar bleiben soll

## 8. Funktionstest auf Prod

- [ ] `bin/smoke-test.sh https://deine-domain.de admin DeinProdPasswort`
      einmal von deinem lokalen Rechner gegen die Prod-URL laufen
      lassen (curl braucht keinen Serverzugriff, nur die URL muss von
      außen erreichbar sein)
- [ ] Einmal händisch im Browser: Login, ein Hund/Halter/Züchter
      anschauen, Bild-Anzeige (Rasse + eigener Hund) prüfen

## Danach aufräumen (kein Blocker fürs Deployment, aber notiert)

- [ ] `admin_manage_halter_3.php` — vermutliche Altlast, prüfen ob
      löschbar (siehe letzte Zusammenfassung)
- [ ] TheDogAPI-Admin-Seiten ins neue Design überführen (optional)
