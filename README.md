# Hunderassen-Verwaltungs-App

Verwaltungs-App für Hunderassen (VDH/FCI-Daten), gebaut in plain PHP 8.x
+ MySQL für Shared Hosting (all-inkl), mit RBAC, CSRF/XSS-Schutz,
Prepared Statements und einem MVC-ähnlichen Aufbau über direkt
erreichbare PHP-Dateien (kein Front-Controller — bewusste Entscheidung,
da Front-Controller + `.htaccess`-Rewriting auf Shared Hosting
erfahrungsgemäß fragil ist).

## Features

- Rassenverwaltung mit Bild-Upload (LONGBLOB, GD-Verkleinerung auf
  max. 1200px) inkl. clientseitigem Crop-Editor (Alpine.js + Canvas)
- Facettierte Suche/Filterung im Dashboard (Größe, Tags, Aktivitäten)
  mit Live-Trefferzahlen pro Filteroption
- Tag- und Aktivitäten-Verwaltung (inline editierbar)
- Benutzerverwaltung mit RBAC (Admin/Nutzer), Selbstschutz gegen
  versehentliches Selbst-Downgrade/-Löschen
- TheDogAPI-Anreicherung: automatischer Fuzzy-Abgleich der VDH-Rassen
  gegen TheDogAPI, mit manueller Review-Stufe vor jeder Übernahme
  (siehe `admin_thedogapi_review.php` / `admin_thedogapi_apply.php`)

## Projektstruktur

```
.
├── admin_add_breed.php          Rasse anlegen/bearbeiten inkl. Crop-Editor
├── admin_add_user.php           (Legacy, ersetzt durch admin_manage_users.php)
├── admin_merkmale.php           Merkmale und Werte verwalten (DE/EN)
├── admin_merkmale_befuellen.php Leere Merkmale aus TheDogAPI und VDH-Text befüllen
├── admin_inhalte_import.php     Geprüfte Inhalte aus dem privaten Inhalte-Repo importieren
├── admin_manage_users.php       Benutzerverwaltung
├── admin_thedogapi_review.php   TheDogAPI-Abgleich, Stufe 1 (Review, liest nur)
├── admin_thedogapi_apply.php    TheDogAPI-Abgleich, Stufe 2 (schreibt bestätigte Werte)
├── ajax_create_taxonomy.php     AJAX-Endpoint für Tag/Aktivitäten-Erstellung
├── bildausgabe.php              Liefert Rassebilder aus der DB aus
├── breed_detail.php             Detailansicht einer Rasse
├── dashboard.php                Haupt-/Suchansicht mit Pagination
├── header.php                   (Legacy-Duplikat, siehe Hinweis unten)
├── login.php / logout.php       Authentifizierung
│
├── config/
│   ├── auth.php                 Session-/Rollen-Hilfsfunktionen
│   ├── database.php             PDO-Singleton (Zugangsdaten! siehe Sicherheitshinweise)
│   ├── environment.php          Umgebungserkennung (dev/prod) + Error-Handling
│   └── security.php             CSRF, XSS-Escaping, Brute-Force-Schutz, Session-Härtung
│
├── models/
│   ├── Activity.php
│   ├── BildVerarbeitung.php     GD-basierte Bildverarbeitung
│   ├── Breed.php
│   ├── Tag.php
│   └── User.php
│
├── views/partials/
│   ├── header.php                Navigation, Kopfbereich
│   └── footer.php
│
├── sql/
│   ├── schema.sql                Aktuelles vollständiges Schema
│   └── schema_backup.sql
│
├── DB_Generator/
│   ├── generate_import_sql.php   Mapping-Script VDH-JSON → SQL-Insert
│   ├── hunderassen_all_vdh.json  382 VDH-Rassen als Quelldaten
│   └── import_hunderassen.sql    Generiertes Insert-Script
│
├── import_hunderassen_claude.sql
├── migration_2026_06_30_bild_gewicht_fcinr.sql
├── migration_2026_07_01_thedogapi_felder.sql
└── logs/                         Laufzeit-Fehlerlog (siehe .gitignore)
```

> **Hinweis:** `header.php` im Root scheint ein Duplikat von
> `views/partials/header.php` zu sein. Die Admin-Seiten binden
> nachweislich `views/partials/header.php` ein — die Root-Datei wird
> aktuell von keiner der geprüften Dateien referenziert. Vor dem
> Löschen einmal projektweit nach `require.*header.php` (ohne
> `partials/`) suchen, um sicherzugehen.

## Setup (lokal, Laragon)

1. Repo klonen, in den Laragon-`www`-Ordner legen
2. Datenbank `hunderassen` anlegen, `sql/schema.sql` importieren
3. Migrationen der Reihe nach ausführen:
   ```
   migration_2026_06_30_bild_gewicht_fcinr.sql
   migration_2026_07_01_thedogapi_felder.sql
   ```
   Beide sind idempotent (sicher mehrfach ausführbar).
4. `config/database.php` mit den lokalen Zugangsdaten anpassen
   (Standard: `root` ohne Passwort, passt zu Laragon-Defaults)
5. Optional: VDH-Rassen importieren über `DB_Generator/import_hunderassen.sql`
   bzw. `import_hunderassen_claude.sql`
6. Optional: TheDogAPI-Key als Umgebungsvariable `THEDOGAPI_KEY` setzen
   (oder lokal direkt in `admin_thedogapi_review.php` eintragen — dann
   aber **nicht committen**, siehe unten)

## Setup (Produktiv, all-inkl Shared Hosting)

- `config/database.php` mit den echten Zugangsdaten befüllen
- `.htaccess`-Dateien (Root + Unterordner) mit hochladen — sperren
  `config/`, `sql/`, `models/`, `views/`, `logs/`, `DB_Generator/`
  sowie alle `.sql`-Dateien für direkten Web-Zugriff
- `config/environment.php` erkennt die Produktivumgebung automatisch
  (fail-safe: alles, was nicht eindeutig als lokal erkannt wird, gilt
  als `production` → Fehler werden geloggt statt im Browser angezeigt)

## Sicherheitshinweise

- **`config/database.php` enthält aktuell nur lokale Platzhalter-
  Zugangsdaten** (`root` / kein Passwort). Für den produktiven Einsatz
  bitte die echten all-inkl-Zugangsdaten eintragen — und dann diese
  Datei **nicht mit echten Zugangsdaten committen**. Empfehlenswert:
  Repo privat halten, oder `config/database.php` per
  `git rm --cached config/database.php` aus der Versionierung nehmen,
  sobald echte Zugangsdaten eingetragen werden, und stattdessen über
  Umgebungsvariablen laden (analog zum bereits umgesetzten Muster in
  `admin_thedogapi_review.php` für den TheDogAPI-Key).
- CSRF-Schutz, XSS-Escaping (`e()`), Prepared Statements und
  Brute-Force-Schutz (5 Fehlversuche → 15 Minuten Sperre) sind
  projektweit etabliert — bei neuen Dateien bitte an den bestehenden
  Mustern in `config/security.php` orientieren.

## Lizenz

Noch nicht final festgelegt. Bis zur Entscheidung ist dieses Repo
implizit "alle Rechte vorbehalten" — bei Bedarf eine `LICENSE`-Datei
(z.B. MIT für offene Nutzung, oder proprietär, falls das Projekt Teil
eines kommerziellen Angebots wie "Rent a Sven" werden soll) ergänzen.
