<?php
/**
 * config/auth.php
 *
 * Hilfsfunktionen rund um den aktuell eingeloggten Benutzer.
 * Setzt voraus, dass secure_session_start() bereits aufgerufen wurde.
 */

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/../models/User.php';

/**
 * Liefert den aktuell eingeloggten Benutzer aus der Session, oder
 * null, falls niemand eingeloggt ist. Validiert NICHT erneut gegen
 * die Datenbank (Performance) — für sicherheitskritische Aktionen
 * ggf. is_active separat über User::findById() prüfen.
 */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(): bool
{
    $user = current_user();
    return $user !== null && ($user['role'] ?? '') === 'admin';
}

/**
 * Portal-Recht "Rassen verwalten". Admins haben es implizit immer,
 * unabhängig vom can_manage_breeds-Flag in der Session.
 */
function can_manage_breeds(): bool
{
    if (is_admin()) {
        return true;
    }
    $user = current_user();
    return $user !== null && !empty($user['can_manage_breeds']);
}

/**
 * Portal-Recht "Tests verwalten". Admins haben es implizit immer,
 * unabhängig vom can_manage_tests-Flag in der Session.
 */
function can_manage_tests(): bool
{
    if (is_admin()) {
        return true;
    }
    $user = current_user();
    return $user !== null && !empty($user['can_manage_tests']);
}

/**
 * Erzwingt einen eingeloggten Benutzer. Nicht eingeloggte Besucher
 * werden zur Login-Seite umgeleitet. In jeder geschützten View ganz
 * oben aufzurufen, NACH secure_session_start().
 */
function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: /login.php');
        exit;
    }
}

/**
 * Erzwingt die Admin-Rolle. Eingeloggte Nicht-Admins erhalten einen
 * 403-Fehler statt einer stillen Umleitung, damit klar ist, dass es
 * sich um fehlende Berechtigung und nicht um fehlenden Login handelt.
 */
function require_admin(): void
{
    require_login();

    if (!is_admin()) {
        http_response_code(403);
        die('Zugriff verweigert: Diese Aktion ist nur für Administratoren verfügbar.');
    }
}

/**
 * Erzwingt das Portal-Recht "Rassen verwalten" (Admins immer erlaubt,
 * sonst nur Benutzer mit gesetztem can_manage_breeds-Flag).
 */
function require_manage_breeds(): void
{
    require_login();

    if (!can_manage_breeds()) {
        http_response_code(403);
        die('Zugriff verweigert: Diese Aktion erfordert die Berechtigung "Rassen verwalten".');
    }
}

/**
 * Erzwingt das Portal-Recht "Tests verwalten" (Admins immer erlaubt,
 * sonst nur Benutzer mit gesetztem can_manage_tests-Flag).
 */
function require_manage_tests(): void
{
    require_login();

    if (!can_manage_tests()) {
        http_response_code(403);
        die('Zugriff verweigert: Diese Aktion erfordert die Berechtigung "Tests verwalten".');
    }
}

/**
 * Schreibt den Benutzer nach erfolgreichem Login in die Session und
 * regeneriert die Session-ID (Schutz vor Session-Fixation).
 */
function login_user(array $user): void
{
    regenerate_session_after_login();
    $_SESSION['user'] = $user;
}

function logout_user(): void
{
    destroy_session();
}
