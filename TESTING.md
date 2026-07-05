# Automatisierte Tests

Es gibt (noch) keine "echten" Unit-Tests (PHPUnit) — dafür einen
**Rauchtest**, der die wichtigsten Seiten der App per curl aufruft und
prüft, ob sie laden und den erwarteten Inhalt zeigen. Das deckt genau
das Muster ab, das in diesem Projekt schon mehrfach aufgetreten ist:
ein falscher `require_once`-Pfad oder eine fehlende Datei, die erst
beim Klicken im Browser auffällt.

## Lokal ausführen (Laragon)

Voraussetzung: `sql/fixtures/ci_seed.sql` einmal auf einer **Testdatenbank**
importiert (NICHT auf deiner echten Dev-DB mit echten Daten — die
Fixture legt feste IDs an, die mit vorhandenen Datensätzen kollidieren
können).

```bash
bash bin/smoke-test.sh http://hunderassen-app.test admin DeinAdminPasswort
```

Unter Windows ohne WSL/Git-Bash geht das Skript nicht direkt — entweder
Git Bash (bringt Laragon i. d. R. schon mit) oder WSL nutzen.

## Automatisch bei jedem Push (GitHub Actions)

`.github/workflows/ci.yml` läuft bei jedem Push und jedem Pull Request
vollautomatisch:

1. Baut eine frische MySQL-Datenbank in der CI-Umgebung auf
2. Importiert `sql/schema.sql` + `sql/fixtures/ci_seed.sql`
3. Setzt ein bekanntes Test-Admin-Passwort (`CiTest123!` — nur in der
   isolierten CI-Umgebung, hat nichts mit deinem echten Admin-Passwort
   zu tun)
4. Startet den PHP-Webserver
5. Lässt `bin/smoke-test.sh` dagegenlaufen

**Wo du das Ergebnis siehst:** In deinem GitHub-Repo oben im Reiter
**"Actions"** — jeder Push bekommt dort einen grünen Haken (✅) oder
ein rotes Kreuz (❌). Bei einem Fehlschlag zeigt der Workflow-Log
genau, welcher Check fehlgeschlagen ist, plus den Inhalt von
`logs/app_errors.log` aus dem Testlauf.

## Was der Rauchtest konkret prüft

- Geschützte Seiten leiten ohne Login korrekt zur Login-Seite um (302)
- Login mit korrekten Zugangsdaten funktioniert (inkl. CSRF-Token)
- Nach Login laden: Dashboard, Rassen-Detail, Hunde-Übersicht +
  Detail, Halter-Übersicht + Detail, Züchter-Übersicht + Detail,
  Admin-Seiten
- Die Testdaten aus der Fixture werden tatsächlich angezeigt (nicht
  nur "Seite lädt", sondern "Seite zeigt den erwarteten Inhalt")
- Der Webseiten-Link beim Züchter ist vorhanden
- **Eigenes Passwort ändern (account.php)**: erste echte
  Formular-Einreichung im Rauchtest (nicht nur "Seite lädt") — der
  Test ändert das Passwort des Test-Admins auf ein Zufallspasswort und
  direkt danach wieder zurück auf das ursprüngliche, damit der Testlauf
  wiederholbar bleibt und keine Zugangsdaten "kaputt" zurücklässt
- Logout funktioniert, danach sind geschützte Seiten wieder gesperrt

## Was das (bewusst) NICHT prüft

- Weitere Formular-Einreichungen (Hund/Halter/Züchter/Rasse
  anlegen/bearbeiten/löschen) — das wäre der nächste sinnvolle
  Ausbauschritt, aber deutlich aufwändiger (Testdaten müssten nach
  jedem Lauf zurückgesetzt werden)
- Kein Bild-Upload (BildVerarbeitung/GD-Verarbeitung)
- Keine Mobile-/Responsive-Prüfung (das bräuchte ein Tool wie
  Playwright/Cypress mit echtem Browser, nicht nur curl)

Für den aktuellen Projektumfang reicht der Rauchtest als erste
Absicherung; bei Bedarf lässt er sich später um PHPUnit-Tests für die
Model-Validierung (`Dog::validate()`, `Halter::validate()` etc.) und/
oder Playwright für echte Browser-Tests erweitern.
