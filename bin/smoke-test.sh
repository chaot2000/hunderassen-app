#!/usr/bin/env bash
#
# bin/smoke-test.sh
#
# Ruft die wichtigsten Seiten der App per curl auf und prüft HTTP-Status
# + charakteristische Textfragmente. Kein Ersatz für echte Tests, aber
# fängt zuverlässig das Muster ab, das uns schon mehrfach passiert ist:
# falscher require_once-Pfad -> Fatal Error -> Seite lädt gar nicht.
#
# Nutzung lokal (Laragon):
#   bash bin/smoke-test.sh http://hunderassen-app.test admin DeinPasswort123!
#
# Nutzung in CI: siehe .github/workflows/ci.yml (übergibt automatisch
# die dort erzeugten Test-Zugangsdaten).
#
# Exit-Code 0 = alles grün, 1 = mindestens ein Check fehlgeschlagen.

set -uo pipefail

BASE_URL="${1:-http://127.0.0.1:8000}"
ADMIN_USER="${2:-admin}"
ADMIN_PASS="${3:-}"

if [ -z "$ADMIN_PASS" ]; then
    echo "FEHLER: Kein Admin-Passwort übergeben. Aufruf: smoke-test.sh <base_url> <user> <passwort>"
    exit 1
fi

COOKIE_JAR="$(mktemp)"
FAILED=0

# Räumt das temporäre Cookie-Jar am Ende auf, egal wie das Skript endet.
trap 'rm -f "$COOKIE_JAR"' EXIT

# -----------------------------------------------------------------
# check_status: ruft eine URL auf und prüft den erwarteten HTTP-Status.
# -----------------------------------------------------------------
check_status() {
    local description="$1" url="$2" expected_status="$3" extra_curl_args="${4:-}"
    local status
    status=$(curl -s -o /tmp/smoke_last_response.html -w "%{http_code}" -b "$COOKIE_JAR" -c "$COOKIE_JAR" $extra_curl_args "$url")

    if [ "$status" = "$expected_status" ]; then
        echo "OK   [$status] $description"
    else
        echo "FAIL [$status, erwartet $expected_status] $description ($url)"
        FAILED=1
    fi
}

# -----------------------------------------------------------------
# check_body_contains: prüft, ob die zuletzt geladene Antwort einen
# bestimmten Text enthält (z. B. um "leere Seite trotz Status 200"
# zu erkennen, was bei PHP-Warnings mit unterdrückter Fehlerausgabe
# in Produktionsumgebungen sonst unbemerkt bliebe).
# -----------------------------------------------------------------
check_body_contains() {
    local description="$1" needle="$2"
    if grep -qF "$needle" /tmp/smoke_last_response.html; then
        echo "OK   Inhalt vorhanden: $description"
    else
        echo "FAIL Inhalt FEHLT: $description"
        FAILED=1
    fi
}

echo "=== Rauchtest gegen $BASE_URL ==="
echo ""

echo "--- Öffentliche Startseite lädt ohne Login ---"
check_status "index.php lädt (öffentlich)" "$BASE_URL/index.php" "200"
check_body_contains "Startseite zeigt Anmelden-Link" "Anmelden"

echo ""
echo "--- Ohne Login: geschützte Seiten müssen zur Login-Seite umleiten ---"
check_status "dashboard.php ohne Login (Redirect erwartet)" "$BASE_URL/dashboard.php" "302"
check_status "rassen.php ohne Login (Redirect erwartet)" "$BASE_URL/rassen.php" "302"
check_status "dogs.php ohne Login (Redirect erwartet)" "$BASE_URL/dogs.php" "302"
check_status "account.php ohne Login (Redirect erwartet)" "$BASE_URL/account.php" "302"

echo ""
echo "--- Login-Seite lädt ---"
check_status "login.php lädt" "$BASE_URL/login.php" "200"
check_body_contains "Login-Formular vorhanden" "Anmelden"

echo ""
echo "--- CSRF-Token aus dem Login-Formular extrahieren ---"
CSRF_TOKEN=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_last_response.html || true)
if [ -z "$CSRF_TOKEN" ]; then
    echo "FAIL Kein CSRF-Token im Login-Formular gefunden — Login-Test wird übersprungen."
    FAILED=1
else
    echo "OK   CSRF-Token gefunden"

    echo ""
    echo "--- Login mit Test-Admin ---"
    check_status "Login-POST (Redirect bei Erfolg erwartet)" "$BASE_URL/login.php" "302" \
        "-X POST --data-urlencode username=$ADMIN_USER --data-urlencode password=$ADMIN_PASS --data-urlencode csrf_token=$CSRF_TOKEN"

    echo ""
    echo "--- Nach Login: geschützte Seiten müssen laden ---"
    check_status "dashboard.php nach Login" "$BASE_URL/dashboard.php" "200"
    check_body_contains "Dashboard begrüßt den Nutzer" "Hallo,"

    check_status "rassen.php nach Login" "$BASE_URL/rassen.php" "200"
    check_body_contains "Rassen-Seite zeigt Überschrift" "Rassen durchsuchen"

    check_status "breed_detail.php (Testrasse)" "$BASE_URL/breed_detail.php?id=1" "200"
    check_body_contains "Testrasse wird angezeigt" "CI-Testrasse"

    check_status "dogs.php nach Login" "$BASE_URL/dogs.php" "200"
    check_status "dog_detail.php (Testhund)" "$BASE_URL/dog_detail.php?id=1" "200"
    check_body_contains "Testhund wird angezeigt" "CI-Testhund"

    check_status "halter.php nach Login" "$BASE_URL/halter.php" "200"
    check_status "halter_detail.php (Test-Halter)" "$BASE_URL/halter_detail.php?id=1" "200"
    check_body_contains "Test-Halter wird angezeigt" "CI-Test-Halter"

    check_status "zuechter.php nach Login" "$BASE_URL/zuechter.php" "200"
    check_status "zuechter_detail.php (Test-Zucht)" "$BASE_URL/zuechter_detail.php?id=1" "200"
    check_body_contains "Test-Zucht wird angezeigt" "CI-Test-Zucht"
    check_body_contains "Webseiten-Link vorhanden" "example.test"

    check_status "admin_manage_tags.php (admin-only)" "$BASE_URL/admin_manage_tags.php" "200"
    check_status "admin_manage_users.php (admin-only)" "$BASE_URL/admin_manage_users.php" "200"

    echo ""
    echo "--- Test-Katalog (Tests-Modul, Phase 1) ---"
    check_status "admin_manage_tests.php (admin-only)" "$BASE_URL/admin_manage_tests.php" "200"
    check_body_contains "CI-Testtest wird in der Liste angezeigt" "CI-Testtest"

    check_status "test_form.php?id=1 lädt (Bearbeiten-Modus)" "$BASE_URL/test_form.php?id=1" "200"
    check_body_contains "Aufgabe 1 wird vorbefüllt angezeigt" "CI-Aufgabe 1"
    check_body_contains "Ergebnis-Bezeichnung wird vorbefüllt angezeigt" "Bleibt entspannt"

    echo ""
    echo "--- Test anlegen und wieder löschen (echte Formular-Einreichung) ---"
    check_status "test_form.php (neu) lädt" "$BASE_URL/test_form.php" "200"
    TEST_FORM_CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_last_response.html || true)

    if [ -z "$TEST_FORM_CSRF" ]; then
        echo "FAIL Kein CSRF-Token auf test_form.php gefunden — Test-Anlegen wird übersprungen."
        FAILED=1
    else
        NEUER_TESTNAME="CI-SmokeTest-$(date +%s)"
        check_status "Neuen Test anlegen (Redirect erwartet)" "$BASE_URL/test_form.php" "302" \
            "-X POST \
             --data-urlencode csrf_token=$TEST_FORM_CSRF \
             --data-urlencode name=$NEUER_TESTNAME \
             --data-urlencode beschreibung=Smoke-Test-Eintrag \
             --data-urlencode zielgruppe=beide \
             --data-urlencode aufgaben[0][titel]=Smoke-Aufgabe \
             --data-urlencode aufgaben[0][ergebnisse][0][bezeichnung]=Smoke-Ergebnis \
             --data-urlencode aufgaben[0][ergebnisse][0][kategorie]=bestanden"

        check_status "admin_manage_tests.php nach Anlegen" "$BASE_URL/admin_manage_tests.php" "200"
        check_body_contains "Neu angelegter Test erscheint in der Liste" "$NEUER_TESTNAME"

        # Wieder löschen, damit der Testlauf wiederholbar bleibt (gleiches
        # Prinzip wie beim Passwort-Rückänderungs-Test weiter unten). Die
        # Zeile mit dem neuen Testnamen enthält in den nachfolgenden Zeilen
        # (gleiche <tr>) das zugehörige delete_test_id-Hidden-Field.
        NEUE_TEST_ID=$(grep -A5 -F "$NEUER_TESTNAME" /tmp/smoke_last_response.html | grep -oP 'name="delete_test_id" value="\K[0-9]+' | head -1 || true)
        DELETE_CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_last_response.html | head -1 || true)

        if [ -z "$NEUE_TEST_ID" ] || [ -z "$DELETE_CSRF" ]; then
            echo "FAIL Konnte neu angelegten Test nicht zum Löschen identifizieren — bitte manuell aufräumen."
            FAILED=1
        else
            check_status "Neu angelegten Test wieder löschen" "$BASE_URL/admin_manage_tests.php" "302" \
                "-X POST --data-urlencode csrf_token=$DELETE_CSRF --data-urlencode delete_test_id=$NEUE_TEST_ID"
        fi
    fi

    echo ""
    echo "--- Testdurchführungen (Tests-Modul, Phase 2) ---"
    check_status "dog_detail.php (Testhund) zeigt Testdurchführungen-Sektion" "$BASE_URL/dog_detail.php?id=1" "200"
    check_body_contains "Fixture-Testdurchführung erscheint in der Historie" "CI-Testtest"

    check_status "testdurchfuehrung_detail.php?id=1 lädt" "$BASE_URL/testdurchfuehrung_detail.php?id=1" "200"
    check_body_contains "Testname wird angezeigt" "CI-Testtest"
    check_body_contains "Aufgabe 1 wird im Breakdown angezeigt" "CI-Aufgabe 1"
    check_body_contains "Erfasstes Ergebnis wird angezeigt" "Bleibt entspannt"

    echo ""
    echo "--- Testdurchführung anlegen und wieder löschen (echte Formular-Einreichung) ---"
    check_status "testdurchfuehrung_form.php?dog_id=1 lädt" "$BASE_URL/testdurchfuehrung_form.php?dog_id=1" "200"

    # Regressionscheck: das x-data-Attribut enthält rohes JSON (mit
    # doppelten Anführungszeichen) und muss daher selbst in einfachen
    # Anführungszeichen stehen (x-data='...'), sonst bricht das JSON das
    # Attribut vorzeitig ab und der restliche JS-Code landet als
    # sichtbarer Text auf der Seite, während das Test-Auswahl-Dropdown
    # leer bleibt (da <template x-for> dann nie ausgeführt wird).
    check_body_contains "x-data schließt mit einfachen statt doppelten Anführungszeichen (JSON darin würde sonst das Attribut abbrechen)" "x-data='{"
    check_body_contains "Formular rendert normales HTML nach x-data (kein kaputtes Attribut sichtbar)" "Zielgruppe filtern"
    check_body_contains "Zielgruppen-Filter-Optionen vorhanden" "Nur Welpen-Tests"

    # Regressionscheck: aktualisiereStatusVorschlag() muss this.$root
    # verwenden, NICHT this.$el. Bei @change="aktualisiereStatusVorschlag()"
    # auf einem der Ergebnis-<select>-Elemente ist this.$el innerhalb der
    # Methode das <select> selbst (das Element, an dem die Direktive
    # steht) statt des Formular-Wurzelelements — eine querySelectorAll()
    # darauf findet dann keine Ergebnis-Selects mehr, wodurch der
    # Status-Vorschlag stillschweigend nie greift. $root verweist immer
    # auf das Element mit x-data, unabhängig vom auslösenden Kindelement.
    check_body_contains "Status-Vorschlag durchsucht Formular-Wurzel (\$root), nicht das auslösende Element (\$el)" 'this.$root.querySelectorAll("select[data-ergebnis-select]")'

    # Regressionscheck: bei einem Gleichstand (gleich viele bestanden wie
    # nicht_bestanden) muss der Vorschlag auf "offen" zurückgesetzt werden.
    # Ohne dieses Zurücksetzen bleibt der Status auf dem Vorschlag stehen,
    # der VOR dem Gleichstand galt (z.B. weil nur die erste geänderte
    # Aufgabe zählte, bevor der Gleichstand durch eine zweite Aufgabe
    # entstand) — das sieht dann fälschlich so aus, als würde nur das
    # zuerst gewählte Ergebnis berücksichtigt.
    check_body_contains "Bei Gleichstand wird der Status-Vorschlag auf 'offen' zurückgesetzt" 'this.$refs.statusSelect.value = "offen";'

    TDF_CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_last_response.html || true)

    if [ -z "$TDF_CSRF" ]; then
        echo "FAIL Kein CSRF-Token auf testdurchfuehrung_form.php gefunden — Anlegen wird übersprungen."
        FAILED=1
    else
        HEUTE=$(date +%Y-%m-%d)

        # Bewusst das HEUTIGE Datum verwenden (nicht ein fixes
        # Vergangenheitsdatum) — Regressionstest für einen Bug, bei dem
        # DateTime::createFromFormat()-Vergleiche das heutige Datum
        # fälschlich als "in der Zukunft" ablehnten (Uhrzeit-Anteil-
        # Mismatch zwischen dem geparsten Datum und "today" um Mitternacht).
        check_status "Neue Testdurchführung mit HEUTIGEM Datum anlegen (Redirect erwartet)" "$BASE_URL/testdurchfuehrung_form.php?dog_id=1" "302" \
            "-X POST \
             --data-urlencode csrf_token=$TDF_CSRF \
             --data-urlencode test_id=1 \
             --data-urlencode durchfuehrungsdatum=$HEUTE \
             --data-urlencode status=offen \
             --data-urlencode notizen=Smoke-Test-Durchfuehrung \
             --data-urlencode ergebnisse[1][ergebnis_id]=2 \
             --data-urlencode ergebnisse[2][ergebnis_id]=5"

        check_status "dog_detail.php zeigt neue Durchführung" "$BASE_URL/dog_detail.php?id=1" "200"

        # Neu angelegte Durchführung wieder löschen, damit der Testlauf
        # wiederholbar bleibt. Der Link zur Detailseite der zuletzt
        # angelegten (= zweiten) Durchführung liefert deren id.
        NEUE_TDF_ID=$(grep -oP 'testdurchfuehrung_detail\.php\?id=\K[0-9]+' /tmp/smoke_last_response.html | sort -n | tail -1 || true)

        if [ -z "$NEUE_TDF_ID" ] || [ "$NEUE_TDF_ID" = "1" ]; then
            echo "FAIL Konnte neu angelegte Testdurchführung nicht identifizieren — bitte manuell aufräumen."
            FAILED=1
        else
            check_status "Neue Testdurchführung lädt (Detailseite)" "$BASE_URL/testdurchfuehrung_detail.php?id=$NEUE_TDF_ID" "200"
            DELETE_TDF_CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_last_response.html | head -1 || true)

            check_status "Neue Testdurchführung wieder löschen" "$BASE_URL/testdurchfuehrung_detail.php?id=$NEUE_TDF_ID" "302" \
                "-X POST --data-urlencode csrf_token=$DELETE_TDF_CSRF --data-urlencode delete_durchfuehrung_id=$NEUE_TDF_ID"
        fi

        echo ""
        echo "--- Validierungsfehler darf erfasste Ergebnisse/Notizen NICHT verwerfen ---"
        # Absichtlich ein ungültiges (weit in der Zukunft liegendes) Datum
        # senden, um den Fehlerpfad auszulösen, während Ergebnisse/Notizen
        # bereits ausgefüllt sind — die Seite muss diese Werte weiterhin
        # anzeigen (nicht auf leere Felder zurückfallen), damit nach einer
        # Korrektur einfach erneut abgeschickt werden kann.
        check_status "Testdurchführung mit ungültigem Zukunftsdatum abschicken (200 mit Fehlermeldung erwartet)" "$BASE_URL/testdurchfuehrung_form.php?dog_id=1" "200" \
            "-X POST \
             --data-urlencode csrf_token=$TDF_CSRF \
             --data-urlencode test_id=1 \
             --data-urlencode durchfuehrungsdatum=2099-01-01 \
             --data-urlencode status=bestanden \
             --data-urlencode notizen=Smoke-Fehlerpfad-Notiz \
             --data-urlencode ergebnisse[1][ergebnis_id]=3 \
             --data-urlencode ergebnisse[1][notizen]=Smoke-Aufgabe1-Notiz \
             --data-urlencode ergebnisse[2][ergebnis_id]=5"
        check_body_contains "Fehlermeldung zum Zukunftsdatum erscheint" "darf nicht in der Zukunft liegen"
        check_body_contains "Gesamtnotiz bleibt nach Fehler sichtbar" "Smoke-Fehlerpfad-Notiz"
        check_body_contains "Aufgabe-1-Notiz bleibt nach Fehler sichtbar" "Smoke-Aufgabe1-Notiz"
        check_body_contains "Zuvor gewähltes Ergebnis (nicht_bestanden) bleibt nach Fehler ausgewählt" 'value="3" data-kategorie="nicht_bestanden" selected'
        # Regressionscheck: der Test selbst muss im Test-Select ebenfalls
        # als "selected" erhalten bleiben — sonst würde beim erneuten
        # Abschicken test_id=0 mitgesendet, was einen zweiten, irreführenden
        # Fehler ("Test nicht gefunden") statt der eigentlichen Korrektur
        # erlauben würde (Bug: <option>-Liste wurde per Alpine x-for erst
        # NACH x-model initialisiert, wodurch die Auswahl beim ersten
        # Rendern verloren ging).
        check_body_contains "Zuvor gewählter Test bleibt nach Fehler im Dropdown ausgewählt" 'value="1" data-zielgruppe="beide" selected'

        FEHLERPFAD_CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_last_response.html || true)
        if [ -z "$FEHLERPFAD_CSRF" ]; then
            echo "FAIL Kein CSRF-Token nach Fehleranzeige gefunden — Korrektur-Test wird übersprungen."
            FAILED=1
        else
            check_status "Nach Korrektur des Datums erneut abschicken (Redirect erwartet)" "$BASE_URL/testdurchfuehrung_form.php?dog_id=1" "302" \
                "-X POST \
                 --data-urlencode csrf_token=$FEHLERPFAD_CSRF \
                 --data-urlencode test_id=1 \
                 --data-urlencode durchfuehrungsdatum=$HEUTE \
                 --data-urlencode status=bestanden \
                 --data-urlencode notizen=Smoke-Fehlerpfad-Notiz \
                 --data-urlencode ergebnisse[1][ergebnis_id]=3 \
                 --data-urlencode ergebnisse[1][notizen]=Smoke-Aufgabe1-Notiz \
                 --data-urlencode ergebnisse[2][ergebnis_id]=5"

            # Die zuletzt geladene Antwort ist der 302-Redirect selbst
            # (kein Body mit einem Link zur Detailseite) — dog_detail.php
            # erneut per GET laden, um die id der soeben gespeicherten
            # Durchführung aus der Historie-Liste zu extrahieren.
            check_status "dog_detail.php nach Korrektur lädt" "$BASE_URL/dog_detail.php?id=1" "200"
            KORRIGIERTE_TDF_ID=$(grep -oP 'testdurchfuehrung_detail\.php\?id=\K[0-9]+' /tmp/smoke_last_response.html | sort -n | tail -1 || true)
            if [ -z "$KORRIGIERTE_TDF_ID" ] || [ "$KORRIGIERTE_TDF_ID" = "1" ]; then
                echo "FAIL Konnte die nach Korrektur gespeicherte Testdurchführung nicht identifizieren — bitte manuell aufräumen."
                FAILED=1
            else
                check_status "Korrigierte Testdurchführung lädt (Detailseite)" "$BASE_URL/testdurchfuehrung_detail.php?id=$KORRIGIERTE_TDF_ID" "200"
                check_body_contains "Nach Korrektur gespeicherte Aufgabe-1-Notiz ist vorhanden" "Smoke-Aufgabe1-Notiz"
                DELETE_KORRIGIERT_CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_last_response.html | head -1 || true)
                check_status "Korrigierte Testdurchführung wieder löschen" "$BASE_URL/testdurchfuehrung_detail.php?id=$KORRIGIERTE_TDF_ID" "302" \
                    "-X POST --data-urlencode csrf_token=$DELETE_KORRIGIERT_CSRF --data-urlencode delete_durchfuehrung_id=$KORRIGIERTE_TDF_ID"
            fi
        fi

        echo ""
        echo "--- Teil-Bearbeitung: unveränderte Ergebnisse bleiben beim Speichern erhalten ---"
        # Fixture-Durchführung (id=1) bearbeiten und NUR Aufgabe 1 ändern
        # (ergebnis_id 1 -> 3) — Aufgabe 2 wird unverändert mit ihrem
        # bereits gespeicherten Wert (ergebnis_id=4) zurückgeschickt, exakt
        # wie es ein Browser täte, der die vorbefüllten Dropdowns anzeigt.
        # Regressionstest für den fehlenden "ergebnis_id"-Spalten-Bug in
        # TestDurchfuehrung::findByIdForDog(), der die Vorbefüllung im
        # Bearbeiten-Formular unbemerkt auf "— Ergebnis wählen —"
        # zurückfallen ließ.
        check_status "testdurchfuehrung_form.php?id=1 lädt (Bearbeiten-Modus)" "$BASE_URL/testdurchfuehrung_form.php?id=1" "200"
        check_body_contains "Aufgabe 2 zeigt weiterhin ihr gespeichertes Ergebnis vorausgewählt" 'value="4" data-kategorie="bestanden" selected'

        EDIT_TDF_CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_last_response.html || true)
        if [ -z "$EDIT_TDF_CSRF" ]; then
            echo "FAIL Kein CSRF-Token auf testdurchfuehrung_form.php?id=1 gefunden — Teil-Edit-Test wird übersprungen."
            FAILED=1
        else
            check_status "Nur Aufgabe 1 ändern, Aufgabe 2 unverändert mitschicken (Redirect erwartet)" "$BASE_URL/testdurchfuehrung_form.php?id=1" "302" \
                "-X POST \
                 --data-urlencode csrf_token=$EDIT_TDF_CSRF \
                 --data-urlencode durchfuehrungsdatum=2026-07-01 \
                 --data-urlencode status=bestanden \
                 --data-urlencode notizen=CI-Testnotiz-Gesamtdurchfuehrung \
                 --data-urlencode ergebnisse[1][ergebnis_id]=3 \
                 --data-urlencode ergebnisse[2][ergebnis_id]=4"

            check_status "testdurchfuehrung_detail.php?id=1 nach Teil-Edit lädt" "$BASE_URL/testdurchfuehrung_detail.php?id=1" "200"
            check_body_contains "Aufgabe 1 zeigt das GEÄNDERTE Ergebnis" "Zeigt aggressives Verhalten"
            check_body_contains "Aufgabe 2 zeigt weiterhin ihr UNVERÄNDERTES Ergebnis (nicht verloren gegangen)" "Bleibt entspannt"

            # Fixture wieder auf Ausgangszustand zurücksetzen, damit der
            # Testlauf wiederholbar bleibt (gleiches Prinzip wie beim
            # Passwort-Rückänderungs-Test weiter unten).
            RESTORE_CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_last_response.html | head -1 || true)
            check_status "Fixture-Durchführung auf Ausgangszustand zurücksetzen" "$BASE_URL/testdurchfuehrung_form.php?id=1" "302" \
                "-X POST \
                 --data-urlencode csrf_token=$RESTORE_CSRF \
                 --data-urlencode durchfuehrungsdatum=2026-07-01 \
                 --data-urlencode status=bestanden \
                 --data-urlencode notizen=CI-Testnotiz-Gesamtdurchfuehrung \
                 --data-urlencode ergebnisse[1][ergebnis_id]=1 \
                 --data-urlencode ergebnisse[2][ergebnis_id]=4"
        fi
    fi

    echo ""
    echo "--- Eigenes Passwort ändern (account.php) ---"
    check_status "account.php lädt" "$BASE_URL/account.php" "200"
    check_body_contains "Formular zur Passwortänderung vorhanden" "Passwort ändern"

    ACCOUNT_CSRF_TOKEN=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_last_response.html || true)
    TEMP_PASSWORD="CiTemp-$(date +%s)Aa!"

    if [ -z "$ACCOUNT_CSRF_TOKEN" ]; then
        echo "FAIL Kein CSRF-Token auf account.php gefunden — Passwort-Test wird übersprungen."
        FAILED=1
    else
        check_status "Passwort ändern (Redirect/200 mit Erfolgsmeldung erwartet)" "$BASE_URL/account.php" "200" \
            "-X POST --data-urlencode current_password=$ADMIN_PASS --data-urlencode new_password=$TEMP_PASSWORD --data-urlencode new_password_repeat=$TEMP_PASSWORD --data-urlencode csrf_token=$ACCOUNT_CSRF_TOKEN"
        check_body_contains "Erfolgsmeldung nach Passwortänderung" "erfolgreich geändert"

        # CSRF-Token wird bei jedem Request neu ausgegeben — für den
        # Rückänderungs-Request den aktuellsten aus der letzten Antwort holen.
        ACCOUNT_CSRF_TOKEN=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_last_response.html || true)

        check_status "Passwort zurückändern auf Ausgangszustand" "$BASE_URL/account.php" "200" \
            "-X POST --data-urlencode current_password=$TEMP_PASSWORD --data-urlencode new_password=$ADMIN_PASS --data-urlencode new_password_repeat=$ADMIN_PASS --data-urlencode csrf_token=$ACCOUNT_CSRF_TOKEN"
        check_body_contains "Erfolgsmeldung nach Rückänderung" "erfolgreich geändert"
    fi

    echo ""
    echo "--- Logout ---"
    check_status "logout.php (Redirect erwartet)" "$BASE_URL/logout.php" "302"
    check_status "dashboard.php nach Logout (wieder Redirect erwartet)" "$BASE_URL/dashboard.php" "302"
fi

echo ""
if [ "$FAILED" -eq 0 ]; then
    echo "=== ALLE CHECKS BESTANDEN ==="
    exit 0
else
    echo "=== MINDESTENS EIN CHECK FEHLGESCHLAGEN ==="
    exit 1
fi
