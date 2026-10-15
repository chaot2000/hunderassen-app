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
#   bash bin/smoke-test.sh http://hunderassen-app.test admin DeinPasswort123! ci-b admin PasswortVonB
#
# Argumente 4-6 sind Kürzel, Benutzer und Passwort des Admins von
# Mandant B aus sql/fixtures/ci_seed.sql (Isolations-Checks).
#
# Nutzung in CI: siehe .github/workflows/ci.yml (übergibt automatisch
# die dort erzeugten Test-Zugangsdaten).
#
# Exit-Code 0 = alles grün, 1 = mindestens ein Check fehlgeschlagen.

set -uo pipefail

BASE_URL="${1:-http://127.0.0.1:8000}"
ADMIN_USER="${2:-admin}"
ADMIN_PASS="${3:-}"
B_TENANT="${4:-ci-b}"
B_USER="${5:-admin}"
B_PASS="${6:-}"

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

# -----------------------------------------------------------------
# check_body_not_contains: Gegenstück, z. B. für Mandanten-Isolation.
# -----------------------------------------------------------------
check_body_not_contains() {
    local description="$1" needle="$2"
    if grep -qF "$needle" /tmp/smoke_last_response.html; then
        echo "FAIL Inhalt DARF NICHT vorkommen: $description"
        FAILED=1
    else
        echo "OK   Inhalt fehlt wie erwartet: $description"
    fi
}

# -----------------------------------------------------------------
# login_as: meldet sich im aktuellen $COOKIE_JAR an (vorher frische
# Session). Erfolg prüft der Aufrufer über eine geschützte Seite.
# -----------------------------------------------------------------
login_as() {
    local mandant="$1" user="$2" pass="$3" token
    curl -s -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/logout.php" -o /dev/null
    curl -s -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/login.php" -o /tmp/smoke_login_form.html
    token=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_login_form.html || true)
    curl -s -b "$COOKIE_JAR" -c "$COOKIE_JAR" -o /dev/null \
        --data-urlencode "csrf_token=$token" \
        --data-urlencode "mandant=$mandant" \
        --data-urlencode "username=$user" \
        --data-urlencode "password=$pass" \
        "$BASE_URL/login.php"
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
check_body_contains "Login-Formular hat Feld Mandant (mehrere Mandanten aktiv)" 'name="mandant"'

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
    echo "--- Granulare Portal-Rechte (can_manage_breeds/can_manage_tests) ---"

    # Legt über admin_add_user.php einen Benutzer OHNE Admin-Rolle, aber
    # MIT can_manage_breeds an, und prüft mit einem eigenen (zweiten)
    # Cookie-Jar, dass dieser Benutzer genau die Rassen-Verwaltungsseiten
    # sehen darf, aber NICHT die Test-Verwaltung oder Benutzerverwaltung
    # (require_manage_breeds()/require_manage_tests() in config/auth.php
    # müssen unabhängig voneinander greifen, nicht wie früher pauschal
    # require_admin()).
    check_status "admin_add_user.php lädt" "$BASE_URL/admin_add_user.php" "200"
    ADD_USER_CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_last_response.html | head -1 || true)
    PORTAL_USER="ci-portalrechte-$(date +%s)"
    PORTAL_PASS="Portal-Rechte-Test-123!"

    if [ -z "$ADD_USER_CSRF" ]; then
        echo "FAIL Kein CSRF-Token auf admin_add_user.php gefunden — Portal-Rechte-Test wird übersprungen."
        FAILED=1
    else
        check_status "Benutzer mit can_manage_breeds anlegen (Erfolgsmeldung erwartet)" "$BASE_URL/admin_add_user.php" "200" \
            "--data-urlencode csrf_token=$ADD_USER_CSRF \
             --data-urlencode username=$PORTAL_USER \
             --data-urlencode password=$PORTAL_PASS \
             --data-urlencode password_confirm=$PORTAL_PASS \
             --data-urlencode role=user \
             --data-urlencode can_manage_breeds=1"
        check_body_contains "Neuer Portal-Rechte-Benutzer erfolgreich angelegt" "erfolgreich angelegt"

        PORTAL_COOKIE_JAR="$(mktemp)"
        curl -s -c "$PORTAL_COOKIE_JAR" "$BASE_URL/login.php" -o /tmp/smoke_portaluser_login.html
        PORTAL_LOGIN_CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_portaluser_login.html || true)
        curl -s -b "$PORTAL_COOKIE_JAR" -c "$PORTAL_COOKIE_JAR" \
            --data-urlencode "csrf_token=$PORTAL_LOGIN_CSRF" \
            --data-urlencode "username=$PORTAL_USER" \
            --data-urlencode "password=$PORTAL_PASS" \
            "$BASE_URL/login.php" -o /dev/null

        RASSEN_STATUS=$(curl -s -o /dev/null -w "%{http_code}" -b "$PORTAL_COOKIE_JAR" -c "$PORTAL_COOKIE_JAR" "$BASE_URL/admin_add_breed.php")
        TESTS_STATUS=$(curl -s -o /dev/null -w "%{http_code}" -b "$PORTAL_COOKIE_JAR" -c "$PORTAL_COOKIE_JAR" "$BASE_URL/test_form.php")
        USERS_STATUS=$(curl -s -o /dev/null -w "%{http_code}" -b "$PORTAL_COOKIE_JAR" -c "$PORTAL_COOKIE_JAR" "$BASE_URL/admin_manage_users.php")

        if [ "$RASSEN_STATUS" = "200" ]; then
            echo "OK   [200] Portal-Rechte-Benutzer darf admin_add_breed.php (can_manage_breeds=1)"
        else
            echo "FAIL [$RASSEN_STATUS, erwartet 200] Portal-Rechte-Benutzer darf admin_add_breed.php"
            FAILED=1
        fi
        if [ "$TESTS_STATUS" = "403" ]; then
            echo "OK   [403] Portal-Rechte-Benutzer OHNE can_manage_tests wird von test_form.php abgewiesen"
        else
            echo "FAIL [$TESTS_STATUS, erwartet 403] Portal-Rechte-Benutzer sollte test_form.php NICHT sehen dürfen"
            FAILED=1
        fi
        if [ "$USERS_STATUS" = "403" ]; then
            echo "OK   [403] Portal-Rechte-Benutzer (kein Admin) wird von admin_manage_users.php abgewiesen"
        else
            echo "FAIL [$USERS_STATUS, erwartet 403] Portal-Rechte-Benutzer sollte admin_manage_users.php NICHT sehen dürfen"
            FAILED=1
        fi

        rm -f "$PORTAL_COOKIE_JAR" /tmp/smoke_portaluser_login.html

        # Aufräumen: den temporären Testbenutzer wieder löschen, damit der
        # Lauf wiederholbar bleibt. Dafür zurück auf admin_manage_users.php
        # (mit dem Haupt-COOKIE_JAR, also weiterhin als Admin eingeloggt).
        check_status "admin_manage_users.php lädt (für Aufräumen)" "$BASE_URL/admin_manage_users.php" "200"
        DELETE_USER_CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_last_response.html | head -1 || true)

        # Die Benutzer-ID über den bekannten Benutzernamen ermitteln: das
        # umgebende <input type="hidden" name="user_id" value="..."> steht
        # im selben Karten-Block wie der Benutzername im HTML.
        PORTAL_USER_ID=$(grep -ozP "(?s)$PORTAL_USER.*?name=\"user_id\" value=\"\K[0-9]+" /tmp/smoke_last_response.html | tr -d '\0' || true)

        if [ -n "$PORTAL_USER_ID" ] && [ -n "$DELETE_USER_CSRF" ]; then
            check_status "Temporären Portal-Rechte-Benutzer wieder löschen" "$BASE_URL/admin_manage_users.php" "302" \
                "-X POST --data-urlencode csrf_token=$DELETE_USER_CSRF --data-urlencode action=delete_user --data-urlencode user_id=$PORTAL_USER_ID"
        else
            echo "FAIL Konnte user_id für $PORTAL_USER nicht ermitteln — manuelles Aufräumen in der DB nötig."
            FAILED=1
        fi
    fi

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
    echo "--- Mandanten-Isolation (Mandant B darf nichts aus Mandant 1 sehen) ---"
    if [ -z "$B_PASS" ]; then
        echo "FAIL Kein Passwort für Mandant B übergeben — Isolations-Checks werden übersprungen."
        FAILED=1
    else
        A_COOKIE_JAR="$COOKIE_JAR"
        B_COOKIE_JAR="$(mktemp)"
        COOKIE_JAR="$B_COOKIE_JAR"

        # Gleicher Benutzername, falsches Mandanten-Kürzel: Login muss scheitern.
        # Achtung: dieser Block erzeugt pro Lauf zwei absichtliche
        # Fehlversuche in Mandant 1. Die Login-Sperre zählt pro Mandant
        # auch pro IP (5 in 15 Min.), lokal also nach dem zweiten Lauf
        # login_attempts leeren.
        login_as "" "$B_USER" "$B_PASS"
        check_status "Login B-Admin ohne Kürzel (landet in Mandant 1) schlägt fehl" "$BASE_URL/dashboard.php" "302"
        login_as "gibt-es-nicht" "$B_USER" "$B_PASS"
        check_status "Login mit unbekanntem Kürzel schlägt fehl" "$BASE_URL/dashboard.php" "302"

        login_as "$B_TENANT" "$B_USER" "$B_PASS"
        check_status "Login B-Admin mit Kürzel $B_TENANT" "$BASE_URL/dashboard.php" "200"

        check_status "B: eigener Hund lädt" "$BASE_URL/dog_detail.php?id=2" "200"
        check_body_contains "B: eigener Hund wird angezeigt" "CI-B-Hund"
        check_status "B: Hundeliste" "$BASE_URL/dogs.php" "200"
        check_body_not_contains "B: Hundeliste zeigt keinen Hund aus Mandant 1" "CI-Testhund"
        check_status "B: Halterliste" "$BASE_URL/halter.php" "200"
        check_body_not_contains "B: Halterliste zeigt keinen Halter aus Mandant 1" "CI-Test-Halter"
        check_status "B: Züchterliste" "$BASE_URL/zuechter.php" "200"
        check_body_not_contains "B: Züchterliste zeigt keinen Züchter aus Mandant 1" "CI-Test-Zucht"
        check_status "B: Hundeformular" "$BASE_URL/dog_form.php" "200"
        check_body_not_contains "B: Halter-Auswahl im Hundeformular ohne Mandant 1" "CI-Test-Halter"

        check_status "B: Hund aus Mandant 1 -> 404" "$BASE_URL/dog_detail.php?id=1" "404"
        check_status "B: Hund aus Mandant 1 bearbeiten -> 404" "$BASE_URL/dog_form.php?id=1" "404"
        check_status "B: Hundebild aus Mandant 1 -> 404" "$BASE_URL/hundebild.php?id=1" "404"
        check_status "B: Halter aus Mandant 1 -> 404" "$BASE_URL/halter_detail.php?id=1" "404"
        check_status "B: Halter aus Mandant 1 bearbeiten -> 404" "$BASE_URL/halter_form.php?id=1" "404"
        check_status "B: Züchter aus Mandant 1 -> 404" "$BASE_URL/zuechter_detail.php?id=1" "404"
        check_status "B: Züchter aus Mandant 1 bearbeiten -> 404" "$BASE_URL/zuechter_form.php?id=1" "404"
        check_status "B: Test aus Mandant 1 bearbeiten -> 404" "$BASE_URL/test_form.php?id=1" "404"
        check_status "B: Testdurchführung aus Mandant 1 -> 404" "$BASE_URL/testdurchfuehrung_detail.php?id=1" "404"
        check_status "B: Testdurchführung aus Mandant 1 bearbeiten -> 404" "$BASE_URL/testdurchfuehrung_form.php?id=1" "404"
        check_status "B: Testdurchführung für Hund aus Mandant 1 anlegen -> 404" "$BASE_URL/testdurchfuehrung_form.php?dog_id=1" "404"

        check_status "B: eigener Test lädt" "$BASE_URL/test_form.php?id=2" "200"
        check_body_contains "B: eigene Aufgabe wird angezeigt" "CI-B-Aufgabe"
        check_status "B: Testauswahl beim eigenen Hund" "$BASE_URL/testdurchfuehrung_form.php?dog_id=2" "200"
        check_body_not_contains "B: Testauswahl enthält keine Aufgabe aus Mandant 1" "CI-Aufgabe 1"
        B_TDF_CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_last_response.html | head -1 || true)
        check_status "B: Testdurchführung mit Test aus Mandant 1 wird abgelehnt" "$BASE_URL/testdurchfuehrung_form.php?dog_id=2" "200" \
            "-X POST --data-urlencode csrf_token=$B_TDF_CSRF --data-urlencode test_id=1 \
             --data-urlencode durchfuehrungsdatum=2026-07-01 --data-urlencode status=offen \
             --data-urlencode ergebnisse[1][ergebnis_id]=1 --data-urlencode ergebnisse[2][ergebnis_id]=4"
        check_body_contains "B: Fehlermeldung 'Test nicht gefunden'" "nicht gefunden"

        check_status "B: Mandantenverwaltung ist nur für Super-Admins (403)" "$BASE_URL/admin_manage_tenants.php" "403"
        check_status "B: TheDogAPI-Abgleich ist nur für Super-Admins (403)" "$BASE_URL/admin_thedogapi_review.php" "403"
        check_status "B: TheDogAPI-Übernahme ist nur für Super-Admins (403)" "$BASE_URL/admin_thedogapi_apply.php" "403"
        check_status "B: Benutzerverwaltung lädt" "$BASE_URL/admin_manage_users.php" "200"
        check_body_not_contains "B: Benutzerliste zeigt den Super-Admin aus Mandant 1 nicht" "Super-Admin"

        # Fremde Halter-ID ins Hundeformular schreiben -> abgelehnt.
        check_status "B: Hundeformular für Halter-Manipulation" "$BASE_URL/dog_form.php?id=2" "200"
        B_DOG_CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_last_response.html | head -1 || true)
        check_status "B: Hund mit Halter aus Mandant 1 speichern wird abgelehnt" "$BASE_URL/dog_form.php?id=2" "200" \
            "-X POST --data-urlencode csrf_token=$B_DOG_CSRF --data-urlencode name=CI-B-Hund \
             --data-urlencode breed_id=1 --data-urlencode zuechter_id=2 --data-urlencode halter_ids[]=1"
        check_body_contains "B: Fehlermeldung zum fremden Halter" "Halter wurde nicht gefunden"
        check_status "B: Hund mit Züchter aus Mandant 1 speichern wird abgelehnt" "$BASE_URL/dog_form.php?id=2" "200" \
            "-X POST --data-urlencode csrf_token=$B_DOG_CSRF --data-urlencode name=CI-B-Hund \
             --data-urlencode breed_id=1 --data-urlencode zuechter_id=1 --data-urlencode halter_ids[]=2"
        check_body_contains "B: Fehlermeldung zum fremden Züchter" "Züchter wurde nicht gefunden"

        # Löschversuche auf fremde IDs dürfen nichts bewirken. Das
        # CSRF-Token gilt pro Session, also das aus dem Hundeformular.
        B_DEL_CSRF="$B_DOG_CSRF"
        check_status "B: Löschversuch Hund aus Mandant 1" "$BASE_URL/dogs.php" "302" \
            "-X POST --data-urlencode csrf_token=$B_DEL_CSRF --data-urlencode delete_dog_id=1"
        check_status "B: Löschversuch Halter aus Mandant 1" "$BASE_URL/halter.php" "302" \
            "-X POST --data-urlencode csrf_token=$B_DEL_CSRF --data-urlencode delete_halter_id=1"
        check_status "B: Löschversuch Testdurchführung aus Mandant 1" "$BASE_URL/testdurchfuehrung_detail.php?id=1" "404" \
            "-X POST --data-urlencode csrf_token=$B_DEL_CSRF --data-urlencode delete_durchfuehrung_id=1"
        check_status "B: Test-Verwaltung für Lösch-Token" "$BASE_URL/admin_manage_tests.php" "200"
        B_TEST_CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_last_response.html | head -1 || true)
        check_status "B: Löschversuch Test aus Mandant 1" "$BASE_URL/admin_manage_tests.php" "200" \
            "-X POST --data-urlencode csrf_token=$B_TEST_CSRF --data-urlencode delete_test_id=1"
        check_body_contains "B: Test aus Mandant 1 wurde nicht gefunden" "nicht gefunden"

        # Ein gepostetes tenant_id muss für normale Admins ignoriert werden.
        check_status "B: Benutzer anlegen lädt" "$BASE_URL/admin_add_user.php" "200"
        check_body_not_contains "B: kein Mandanten-Feld für normale Admins" 'name="tenant_id"'
        B_ADD_CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_last_response.html | head -1 || true)
        B_NEW_USER="ci-b-tenantpost-$(date +%s)"
        B_NEW_PASS="Tenant-Post-Test-123!"
        check_status "B: Benutzer mit gepostetem tenant_id=1 anlegen" "$BASE_URL/admin_add_user.php" "200" \
            "--data-urlencode csrf_token=$B_ADD_CSRF --data-urlencode username=$B_NEW_USER \
             --data-urlencode password=$B_NEW_PASS --data-urlencode password_confirm=$B_NEW_PASS \
             --data-urlencode role=admin --data-urlencode tenant_id=1"
        check_body_contains "B: Benutzer angelegt" "erfolgreich angelegt"

        NEW_JAR="$(mktemp)"
        COOKIE_JAR="$NEW_JAR"
        login_as "" "$B_NEW_USER" "$B_NEW_PASS"
        check_status "Neuer Benutzer ist NICHT in Mandant 1 gelandet" "$BASE_URL/dashboard.php" "302"
        login_as "$B_TENANT" "$B_NEW_USER" "$B_NEW_PASS"
        check_status "Neuer Benutzer ist in Mandant B gelandet" "$BASE_URL/dashboard.php" "200"
        rm -f "$NEW_JAR"

        COOKIE_JAR="$B_COOKIE_JAR"
        check_status "B: Benutzerverwaltung (Aufräumen)" "$BASE_URL/admin_manage_users.php" "200"
        B_CLEAN_CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_last_response.html | head -1 || true)
        B_NEW_USER_ID=$(grep -ozP "(?s)$B_NEW_USER.*?name=\"user_id\" value=\"\K[0-9]+" /tmp/smoke_last_response.html | tr -d '\0' || true)
        if [ -n "$B_NEW_USER_ID" ]; then
            check_status "B: Testbenutzer wieder löschen" "$BASE_URL/admin_manage_users.php" "302" \
                "-X POST --data-urlencode csrf_token=$B_CLEAN_CSRF --data-urlencode action=delete_user --data-urlencode user_id=$B_NEW_USER_ID"
        else
            echo "FAIL Konnte user_id für $B_NEW_USER nicht ermitteln — manuelles Aufräumen nötig."
            FAILED=1
        fi

        # B versucht, den Super-Admin (id 1) zu ändern -> nichts passiert.
        check_status "B: Passwort-Reset für Benutzer aus Mandant 1" "$BASE_URL/admin_manage_users.php" "200" \
            "-X POST --data-urlencode csrf_token=$B_CLEAN_CSRF --data-urlencode action=reset_password \
             --data-urlencode user_id=1 --data-urlencode new_password=Uebernommen-12345"
        check_body_contains "B: Benutzer aus Mandant 1 wurde nicht gefunden" "Benutzer nicht gefunden"

        rm -f "$B_COOKIE_JAR"
        COOKIE_JAR="$A_COOKIE_JAR"

        echo ""
        echo "--- Mandant 1 nach den Versuchen aus Mandant B unverändert ---"
        check_status "A: Hund 1 existiert noch" "$BASE_URL/dog_detail.php?id=1" "200"
        check_body_contains "A: Testhund wird angezeigt" "CI-Testhund"
        check_status "A: Halter 1 existiert noch" "$BASE_URL/halter_detail.php?id=1" "200"
        check_status "A: Testdurchführung 1 existiert noch" "$BASE_URL/testdurchfuehrung_detail.php?id=1" "200"
        check_status "A: Test 1 existiert noch" "$BASE_URL/test_form.php?id=1" "200"
        check_status "A: Mandantenverwaltung für Super-Admin" "$BASE_URL/admin_manage_tenants.php" "200"
        check_body_contains "A: Mandant B wird gelistet" "CI-Mandant B"
        RES_CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_last_response.html | head -1 || true)
        check_status "A: Mandant mit reserviertem Kürzel 'hunde' anlegen" "$BASE_URL/admin_manage_tenants.php" "200" \
            "-X POST --data-urlencode csrf_token=$RES_CSRF --data-urlencode action=create \
             --data-urlencode name=Reserviert --data-urlencode subdomain=hunde"
        check_body_contains "A: Kürzel 'hunde' wird abgelehnt" "Das Kürzel &quot;hunde&quot; ist reserviert."
        # Ohne config/thedogapi.local.php (wie in CI): 503 statt 403, d. h.
        # der Super-Admin kommt durch die Rechteprüfung.
        check_status "A: TheDogAPI-Abgleich ohne Schlüssel meldet 503 (nicht 403)" "$BASE_URL/admin_thedogapi_review.php" "503"
        check_body_contains "A: Hinweis auf fehlenden Schlüssel" "Kein TheDogAPI-Schlüssel hinterlegt"

        echo ""
        echo "--- Login-Sperre zählt pro Mandant ---"
        LOCK_JAR="$(mktemp)"
        COOKIE_JAR="$LOCK_JAR"
        for i in 1 2 3 4 5; do
            login_as "ci-c" "sperrtest" "falsches-passwort-$i"
        done
        curl -s -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/login.php" -o /tmp/smoke_login_form.html
        LOCK_CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_login_form.html || true)
        # Mit gefälschtem X-Forwarded-For: darf die IP-Sperre nicht umgehen.
        check_status "C: sechster Fehlversuch mit gefälschter IP im Header" "$BASE_URL/login.php" "200" \
            "-X POST -H X-Forwarded-For:203.0.113.$RANDOM --data-urlencode csrf_token=$LOCK_CSRF --data-urlencode mandant=ci-c \
             --data-urlencode username=sperrtest-$RANDOM --data-urlencode password=falsch"
        check_body_contains "C: Sperre greift trotz gefälschtem Header (IP-Sperre)" "Zu viele fehlgeschlagene Login-Versuche"
        login_as "" "$ADMIN_USER" "$ADMIN_PASS"
        check_status "A: Login von derselben IP klappt trotz Sperre in Mandant C" "$BASE_URL/dashboard.php" "200"
        rm -f "$LOCK_JAR"
        COOKIE_JAR="$A_COOKIE_JAR"

        echo ""
        echo "--- Deaktivieren wirkt sofort auf laufende Sessions ---"
        check_status "A: Benutzer anlegen lädt" "$BASE_URL/admin_add_user.php" "200"
        SESS_ADD_CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_last_response.html | head -1 || true)
        SESS_USER="ci-session-$(date +%s)"
        SESS_PASS="Session-Test-123!"
        check_status "A: Benutzer für Session-Test anlegen" "$BASE_URL/admin_add_user.php" "200" \
            "--data-urlencode csrf_token=$SESS_ADD_CSRF --data-urlencode username=$SESS_USER \
             --data-urlencode password=$SESS_PASS --data-urlencode password_confirm=$SESS_PASS --data-urlencode role=user"
        check_body_contains "A: Session-Testbenutzer angelegt" "erfolgreich angelegt"

        SESS_JAR="$(mktemp)"
        COOKIE_JAR="$SESS_JAR"
        login_as "" "$SESS_USER" "$SESS_PASS"
        check_status "Session-Testbenutzer ist eingeloggt" "$BASE_URL/dashboard.php" "200"

        COOKIE_JAR="$A_COOKIE_JAR"
        check_status "A: Benutzerverwaltung" "$BASE_URL/admin_manage_users.php" "200"
        SESS_CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_last_response.html | head -1 || true)
        SESS_USER_ID=$(grep -ozP "(?s)$SESS_USER.*?name=\"user_id\" value=\"\K[0-9]+" /tmp/smoke_last_response.html | tr -d '\0' || true)
        check_status "A: Session-Testbenutzer deaktivieren" "$BASE_URL/admin_manage_users.php" "302" \
            "-X POST --data-urlencode csrf_token=$SESS_CSRF --data-urlencode action=toggle_active \
             --data-urlencode user_id=$SESS_USER_ID --data-urlencode active=0"

        COOKIE_JAR="$SESS_JAR"
        check_status "Deaktivierter Benutzer wird beim nächsten Klick abgemeldet" "$BASE_URL/dashboard.php" "302"
        check_status "Session des deaktivierten Benutzers bleibt beendet" "$BASE_URL/dogs.php" "302"
        rm -f "$SESS_JAR"

        COOKIE_JAR="$A_COOKIE_JAR"
        check_status "A: Session-Testbenutzer wieder löschen" "$BASE_URL/admin_manage_users.php" "302" \
            "-X POST --data-urlencode csrf_token=$SESS_CSRF --data-urlencode action=delete_user --data-urlencode user_id=$SESS_USER_ID"

        B2_JAR="$(mktemp)"
        COOKIE_JAR="$B2_JAR"
        login_as "$B_TENANT" "$B_USER" "$B_PASS"
        check_status "B ist eingeloggt" "$BASE_URL/dashboard.php" "200"

        COOKIE_JAR="$A_COOKIE_JAR"
        check_status "A: Mandantenverwaltung" "$BASE_URL/admin_manage_tenants.php" "200"
        TENANT_CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_last_response.html | head -1 || true)
        check_status "A: Mandant B deaktivieren" "$BASE_URL/admin_manage_tenants.php" "200" \
            "-X POST --data-urlencode csrf_token=$TENANT_CSRF --data-urlencode action=set_active \
             --data-urlencode tenant_id=2 --data-urlencode active=0"
        check_body_contains "A: Mandant B ist deaktiviert" "Mandant wurde deaktiviert."

        COOKIE_JAR="$B2_JAR"
        check_status "B wird nach Deaktivieren des Mandanten sofort abgemeldet" "$BASE_URL/dashboard.php" "302"
        login_as "$B_TENANT" "$B_USER" "$B_PASS"
        check_status "B kann sich bei deaktiviertem Mandanten nicht anmelden" "$BASE_URL/dashboard.php" "302"

        COOKIE_JAR="$A_COOKIE_JAR"
        check_status "A: Mandant B wieder aktivieren" "$BASE_URL/admin_manage_tenants.php" "200" \
            "-X POST --data-urlencode csrf_token=$TENANT_CSRF --data-urlencode action=set_active \
             --data-urlencode tenant_id=2 --data-urlencode active=1"
        check_body_contains "A: Mandant B ist wieder aktiv" "Mandant wurde aktiviert."

        COOKIE_JAR="$B2_JAR"
        login_as "$B_TENANT" "$B_USER" "$B_PASS"
        check_status "B kann sich nach Reaktivieren wieder anmelden" "$BASE_URL/dashboard.php" "200"
        rm -f "$B2_JAR"
        COOKIE_JAR="$A_COOKIE_JAR"
    fi

    echo ""
    echo "--- Textversionen (content_versionen.php) ---"
    check_status "Rasse bearbeiten lädt" "$BASE_URL/admin_add_breed.php?id=1" "200"
    check_body_contains "Link auf die Versionen vorhanden" "content_versionen.php?typ=rasse&amp;id=1"
    BREED_CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_last_response.html | head -1 || true)
    # Werte ohne Leerzeichen: extra_curl_args wird per Wortaufteilung übergeben.
    check_status "Rasse mit geändertem Text speichern (Redirect)" "$BASE_URL/admin_add_breed.php?id=1" "302" \
        "-X POST --data-urlencode csrf_token=$BREED_CSRF --data-urlencode name=CI-Testrasse \
         --data-urlencode ursprungsland=Deutschland --data-urlencode farben=schwarz,braun --data-urlencode fci_klasse=1 \
         --data-urlencode beschreibung=CI-Versionstext-geaendert \
         --data-urlencode sizes[0][groesse]=mittel --data-urlencode sizes[0][ruede_min]=40 --data-urlencode sizes[0][ruede_max]=50"
    check_status "Versionen der Rasse laden" "$BASE_URL/content_versionen.php?typ=rasse&id=1" "200"
    check_body_contains "Version mit dem neuen Text" "CI-Versionstext-geaendert"
    check_body_contains "Anlass „Bearbeitet“" "Bearbeitet"
    check_body_contains "Originaltext wird angeboten" "Original wiederherstellen"
    VER_CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_last_response.html | head -1 || true)
    check_status "Original wiederherstellen (Redirect)" "$BASE_URL/content_versionen.php?typ=rasse&id=1" "302" \
        "-X POST --data-urlencode csrf_token=$VER_CSRF --data-urlencode original=1"
    check_status "Versionen nach der Wiederherstellung" "$BASE_URL/content_versionen.php?typ=rasse&id=1" "200"
    check_body_contains "Erfolgsmeldung der Wiederherstellung" "Beschreibung wurde wiederhergestellt."
    check_body_contains "Wiederherstellung ist selbst eine Version" "Wiederhergestellt"
    check_body_not_contains "Original wird nicht mehr angeboten (ist wieder aktuell)" "Original wiederherstellen"
    check_status "Rassendetail nach der Wiederherstellung" "$BASE_URL/breed_detail.php?id=1" "200"
    check_body_contains "Originaltext ist wieder aktiv" "Nur für automatisierte Tests angelegt."
    check_body_not_contains "Geänderter Text ist weg" "CI-Versionstext-geaendert"
    check_status "Versionen eines Tests (Mandant 1) laden" "$BASE_URL/content_versionen.php?typ=test&id=1" "200"
    check_body_contains "Leerer Zustand ohne Versionen" "Noch keine Änderungen gespeichert."
    check_status "Versionen ohne gültigen Typ: 404" "$BASE_URL/content_versionen.php?typ=foo&id=1" "404"

    if [ -n "$B_PASS" ]; then
        A_VER_JAR="$COOKIE_JAR"
        B_VER_JAR="$(mktemp)"
        COOKIE_JAR="$B_VER_JAR"
        login_as "$B_TENANT" "$B_USER" "$B_PASS"
        check_status "B: Versionen des Tests aus Mandant 1 nicht abrufbar (404)" "$BASE_URL/content_versionen.php?typ=test&id=1" "404"
        check_status "B: Versionen des eigenen Tests laden" "$BASE_URL/content_versionen.php?typ=test&id=2" "200"
        check_status "B: Rassentexte gliedern nur für Super-Admins (403)" "$BASE_URL/admin_rassentexte.php" "403"
        rm -f "$B_VER_JAR"
        COOKIE_JAR="$A_VER_JAR"
    fi

    echo ""
    echo "--- TheDogAPI-Übernahme füllt nur leere Felder ---"
    TDA_CSRF=$(curl -s -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/admin_rassentexte.php" | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1 || true)
    check_status "Erste Übernahme: Temperament ist leer und wird ergänzt" "$BASE_URL/admin_thedogapi_apply.php" "200" \
        "-X POST --data-urlencode csrf_token=$TDA_CSRF --data-urlencode items[1][breed_id]=1 \
         --data-urlencode items[1][temperament]=CI-Erstwert --data-urlencode items[1][uebernehmen_temperament]=1"
    check_body_contains "Ergebnis: ergänzt" "Rasse #1: ergänzt"
    TDA_CSRF=$(curl -s -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/admin_rassentexte.php" | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1 || true)
    check_status "Zweite Übernahme: vorhandenes Temperament bleibt" "$BASE_URL/admin_thedogapi_apply.php" "200" \
        "-X POST --data-urlencode csrf_token=$TDA_CSRF --data-urlencode items[1][breed_id]=1 \
         --data-urlencode items[1][temperament]=CI-Zweitwert --data-urlencode items[1][uebernehmen_temperament]=1"
    check_body_contains "Ergebnis: nicht überschrieben" "Nicht überschrieben: temperament"
    check_status "Versionen zeigen den Import" "$BASE_URL/content_versionen.php?typ=rasse&id=1" "200"
    check_body_contains "Erstwert als Import-Version" "CI-Erstwert"
    check_body_not_contains "Zweitwert nie gespeichert" "CI-Zweitwert"
    # So schickt die Review-Seite die Pauschal-Auswahl: ein JSON-Feld.
    TDA_CSRF=$(curl -s -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/admin_rassentexte.php" | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1 || true)
    check_status "Übernahme als JSON (Pauschal-Auswahl)" "$BASE_URL/admin_thedogapi_apply.php" "200" \
        "-X POST --data-urlencode csrf_token=$TDA_CSRF --data-urlencode items_json={\"1\":{\"lebenserwartung\":\"CI-Json\",\"uebernehmen_lebenserwartung\":\"1\"}}"
    check_body_contains "JSON-Weg ergänzt" "Rasse #1: ergänzt"
    check_body_contains "Zusammenfassung oben" "Ergänzt: 1"
    TDA_CSRF=$(curl -s -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/admin_rassentexte.php" | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1 || true)
    check_status "Bild von fremder Adresse" "$BASE_URL/admin_thedogapi_apply.php" "200" \
        "-X POST --data-urlencode csrf_token=$TDA_CSRF --data-urlencode items_json={\"1\":{\"bild_url\":\"http://127.0.0.1:8000/login.php\",\"uebernehmen_bild\":\"1\"}}"
    check_body_contains "Fremde Bildadresse wird abgelehnt" "nicht von TheDogAPI"

    echo ""
    echo "--- Rassen-Detailseite und Gliederung der Rassentexte ---"
    check_status "Rassen-Detailseite lädt" "$BASE_URL/breed_detail.php?id=1" "200"
    check_body_contains "FCI-Gruppe als Balken" "Hüte- und Treibhunde"
    check_body_contains "Aktion: Hund dieser Rasse anlegen" "/dog_form.php?rasse=1"
    check_body_not_contains "Kein VDH-Restsatz" "Hier werden Würfe"
    check_status "Hundeformular mit vorgewählter Rasse" "$BASE_URL/dog_form.php?rasse=1" "200"
    check_body_contains "Rasse 1 ist vorgewählt" 'breedId: "1"'
    check_status "Rassentexte gliedern lädt (Super-Admin)" "$BASE_URL/admin_rassentexte.php" "200"
    check_body_contains "Fixture-Rasse ist umstellbar" "umstellbar (1)"
    GLIED_CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' /tmp/smoke_last_response.html | head -1 || true)
    check_status "Rassentexte umstellen (Redirect)" "$BASE_URL/admin_rassentexte.php" "302" \
        "-X POST --data-urlencode csrf_token=$GLIED_CSRF --data-urlencode aktion=umstellen"
    check_status "Ergebnis der Umstellung" "$BASE_URL/admin_rassentexte.php" "200"
    check_body_contains "Eine Rasse umgestellt" "Umgestellt: 1 Rassen."
    check_status "Detailseite nach der Umstellung" "$BASE_URL/breed_detail.php?id=1" "200"
    check_body_contains "Hinweis auf automatische Gliederung" "automatisch zugeordnet"
    GLIED_CSRF=$(curl -s -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/admin_rassentexte.php" | grep -oP 'name="csrf_token" value="\K[^"]+' | head -1 || true)
    check_status "Umstellung zurücknehmen (Redirect)" "$BASE_URL/admin_rassentexte.php" "302" \
        "-X POST --data-urlencode csrf_token=$GLIED_CSRF --data-urlencode aktion=rueckgaengig"
    check_status "Ergebnis der Rücknahme" "$BASE_URL/admin_rassentexte.php" "200"
    check_body_contains "Eine Rasse zurückgesetzt" "Zurückgesetzt: 1 Rassen."

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
