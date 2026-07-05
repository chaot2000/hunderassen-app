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
