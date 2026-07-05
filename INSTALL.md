# Installation — Hunderassen-Verwaltung

Dieses Repo läuft unverändert sowohl lokal (Laragon) als auch auf
Prod (all-inkl Privat Shared-Hosting). Der Unterschied liegt
ausschließlich in zwei Dateien, die **nicht** Teil des Git-Repos sind:
`config/database.local.php` und den Datenbank-Inhalten selbst.

## 1. Dateien hochladen / auschecken

Auf all-inkl: kompletten Projektordner nach `/www/htdocs/<dein-webspace>/`
hochladen (z. B. per FTP/SFTP). Struktur bleibt 1:1 wie im Repo.

## 2. Datenbank anlegen

1. In der all-inkl-KAS (Kunden-Administrations-System) eine neue
   MySQL-Datenbank anlegen, Zugangsdaten notieren (Host, DB-Name,
   Benutzer, Passwort).
2. `sql/schema.sql` per phpMyAdmin (oder `mysql`-CLI, falls per SSH
   verfügbar) einmalig importieren. Diese eine Datei genügt für eine
   **komplette Neuinstallation** — sie enthält bereits den aktuellen
   Stand inkl. aller Migrationen (auch `zuechter`-Tabelle und
   `dogs.zuechter_id` aus `migration_2026_07_03_zuechter_und_scoping.sql`).
   Die Einzeldateien in `sql/migrationen/` müssen dafür **nicht** mehr
   ausgeführt werden; sie sind nur das historische Archiv einer
   bereits laufenden Installation.

   **Wichtig für eine bereits laufende Installation (Update, kein
   Fresh-Install):** Dort NICHT `schema.sql` neu einspielen (würde
   `DROP TABLE` auf bestehende Daten auslösen), sondern nur die neuen
   Migrationen nachziehen, in dieser Reihenfolge:
   1. `migration_2026_07_03_zuechter_und_scoping.sql`
   2. `migration_2026_07_04_mehrfachhalter_und_zuechter_erweitert.sql`
   
   Beide sind idempotent. Migration 4 benennt `zuechter.name` in
   `zuechter.zuchtname` um und ergänzt Ansprechpartner-/Webseite-Felder
   sowie die neue `dog_halter`-Pivot-Tabelle (mehrere Halter/eine
   Familie pro Hund) — bestehende 1:1-Zuordnungen aus `dogs.halter_id`
   werden dabei automatisch in die neue Tabelle übernommen.

   **Portal-Prinzip (seit dieser Version):** Hunde, Halter und Züchter
   sind strikt pro Nutzer sichtbar — jeder Nutzer sieht und verwaltet
   ausschließlich seine eigenen Einträge, Admins sehen alle. Rassen
   bleiben weiterhin ein geteilter Katalog für alle Nutzer.

## 3. Zugangsdaten konfigurieren

`config/database.local.example.php` nach `config/database.local.php`
kopieren und mit den echten Werten aus Schritt 2 füllen:

```php
return [
    'host'    => 'localhost',        // bei all-inkl i. d. R. 'localhost'
    'name'    => 'dXXXXXX_hunde',    // dein DB-Name aus der KAS
    'user'    => 'dXXXXXX',          // dein DB-Benutzer aus der KAS
    'pass'    => 'DEIN_DB_PASSWORT',
    'charset' => 'utf8mb4',
];
```

`config/database.local.php` wird **niemals committed** (steht in
`.gitignore`) — pro Umgebung (lokal/Prod) legt jeder sie einmal manuell
an. So bleibt das Repo überall identisch, nur die Zugangsdaten
unterscheiden sich.

## 4. Verzeichnis `logs/` anlegen

`config/environment.php` legt `logs/app_errors.log` automatisch an,
sobald PHP Schreibrechte hat — meist unproblematisch. Falls nicht,
manuell einen leeren Ordner `logs/` mit Schreibrechten (755) anlegen.
Der Ordner ist bereits per `.htaccess` vor Web-Zugriff gesperrt.

## 5. Admin-Passwort setzen

Siehe Abschnitt "Admin-Passwort in Prod setzen" weiter unten — läuft
über ein PHP-Einzeiler-Script, **nicht** über den Platzhalter-Hash in
`sql/schema.sql`.

## 6. Umgebungserkennung — nichts zu tun

`config/environment.php` erkennt automatisch anhand des Hostnamens,
ob development (localhost/*.test/*.local/CLI) oder production
angenommen wird — production ist der sichere Default, falls die
Erkennung uneindeutig ist. Keine manuelle Umschaltung nötig.

## 7. HTTPS erzwingen

Ist bereits in der mitgelieferten `.htaccess` aktiv (Redirect von HTTP
auf HTTPS, mit Ausnahme für lokale Entwicklungs-Domains — localhost,
127.0.0.1, sowie alles auf `.test`/`.local`, also auch Laragons
Standard-Domain wie `hunderassen-app.test`). Voraussetzung ist ein aktives
SSL-Zertifikat für deine Domain in der all-inkl-KAS unter
"SSL-Zertifikate" — ohne aktives Zertifikat würde die Weiterleitung in
einen Fehler laufen. Nach dem Hochladen einmal `https://deine-domain`
im Browser aufrufen und prüfen, dass kein Zertifikatsfehler kommt,
bevor du dich auf die automatische Weiterleitung verlässt.

---

## Admin-Passwort in Prod setzen

`sql/schema.sql` legt den ersten Admin-User mit einem **Platzhalter-Hash**
an, der so *nicht* nutzbar ist. Setze das echte Passwort per PHP-Einzeiler
lokal auf deinem Rechner (nicht im Browser!): `php -r "echo password_hash('DeinSicheresPasswort123!', PASSWORD_DEFAULT);"`
Den ausgegebenen Hash (beginnt mit `$2y$10$…`) per phpMyAdmin in
`UPDATE users SET password_hash = 'DER_HASH' WHERE username = 'admin';`
einsetzen. Danach kannst du dich mit Benutzername `admin` und deinem
gewählten Klartext-Passwort einloggen — das Klartext-Passwort selbst
landet nie in der Datenbank. Ändere im selben Zug sinnvollerweise auch
gleich den Benutzernamen, falls du `admin` nicht als öffentlich
erratbaren Login behalten willst (per `admin_manage_users.php` nach
dem ersten Login).

---

## Daten von Dev nach Prod bringen (all-inkl)

1. **Lokalen Datenbestand exportieren:** In deinem lokalen phpMyAdmin
   (Laragon) → Datenbank `hunderassen` auswählen → Exportieren →
   Format SQL, "Schnell" reicht meist. Ergebnis ist eine `.sql`-Datei
   mit allen aktuellen Daten (Rassen, Tags, Hunde, Halter, Nutzer, …).
2. **Struktur-Unterschiede prüfen:** Falls die Prod-DB schon existiert
   (nicht bei einer echten Erstinstallation), vorher sicherstellen,
   dass beide Schemas identisch sind — sonst schlägt der Import ggf.
   fehl. Bei einer echten Erstinstallation entfällt das: erst
   `sql/schema.sql` auf Prod importieren (siehe oben), danach diesen
   Datenexport importieren, oder direkt den Datenexport allein nutzen,
   wenn er bereits das komplette Schema mit enthält.
3. **Import in Prod:** In der all-inkl-phpMyAdmin → Ziel-Datenbank
   auswählen → Importieren → die exportierte `.sql`-Datei hochladen.
   Bei großen Bild-BLOBs (Rassebilder/Hundefotos) kann die Datei groß
   werden — prüfe das Upload-Limit von phpMyAdmin in der KAS; bei
   Überschreitung die Datei vorher z. B. mit `gzip` komprimieren
   (phpMyAdmin akzeptiert `.sql.gz` direkt).
4. **Admin-Passwort danach neu setzen:** Der importierte Datensatz
   enthält dein *lokales* Passwort-Hash — auf Prod trotzdem einmal neu
   setzen (siehe Abschnitt oben), falls du für Prod ein anderes
   Passwort willst oder dein lokaler Admin-Account kompromittiert
   werden könnte.
5. **`config/database.local.php` bleibt Prod-spezifisch** und wird
   durch den Datenimport nicht berührt — sie enthält nur Zugangsdaten,
   keine fachlichen Daten.

**Faustregel:** Struktur (Schema) und Zugangsdaten sind pro Umgebung
getrennt zu betrachten von den Fachdaten (Inhalte). Struktur kommt aus
`sql/schema.sql` (versioniert im Repo), Zugangsdaten aus
`config/database.local.php` (nie versioniert), Fachdaten per manuellem
Export/Import wie oben beschrieben (kein automatisierter Sync
vorhanden — für den aktuellen Projektumfang bewusst nicht nötig).
