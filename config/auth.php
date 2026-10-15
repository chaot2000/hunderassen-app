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
 * null, falls niemand eingeloggt ist. Auf geschützten Seiten hat
 * require_login() die Daten in diesem Request bereits gegen die
 * Datenbank abgeglichen (siehe refresh_session_user()).
 */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * Liefert die Tenant-ID des aktuell eingeloggten Benutzers aus der Session.
 *
 * Fail-closed: Fehlt der Mandant (z. B. Session von vor der Migration)
 * oder ist er ungültig, wird die Session beendet und zum Login
 * umgeleitet. Es gibt bewusst keinen Fallback auf Mandant 1, sonst
 * landet eine kaputte Session in fremden Daten.
 */
function current_tenant_id(): int
{
    $tenantId = $_SESSION['tenant_id'] ?? null;

    if (!is_int($tenantId) || $tenantId <= 0) {
        destroy_session();
        header('Location: /login.php');
        exit;
    }

    return $tenantId;
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
 * Super-Admin: mandantenübergreifende Rolle. Nur sie darf Mandanten
 * anlegen/abschalten und beim Anlegen von Benutzern den Mandanten
 * wählen. Wird nicht über die Oberfläche vergeben, sondern per SQL
 * (users.is_super_admin = 1), siehe DEPLOYMENT_MULTI_TENANT.md.
 * Ein Super-Admin ist immer auch Admin (role = 'admin').
 */
function is_super_admin(): bool
{
    $user = current_user();
    return is_admin() && !empty($user['is_super_admin']);
}

/**
 * Mandanten-Scope für die Benutzerverwaltung: Super-Admins sehen alle
 * Mandanten (null), normale Admins nur den eigenen.
 */
function user_admin_scope(): ?int
{
    return is_super_admin() ? null : current_tenant_id();
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

    // Prüft den Mandanten früh, damit keine Seite ohne gültigen
    // Mandanten-Kontext weiterläuft (siehe current_tenant_id()).
    current_tenant_id();
    refresh_session_user();
}

/**
 * Gleicht den eingeloggten Benutzer bei jedem geschützten Seitenaufruf
 * mit der Datenbank ab. Ist das Konto oder sein Mandant inzwischen
 * deaktiviert (oder gelöscht), endet die Session sofort, nicht erst
 * beim Logout. Rolle und Portal-Rechte werden dabei aus der Datenbank
 * übernommen, damit ein entzogenes Recht ebenfalls sofort wirkt.
 * Eine indizierte Abfrage pro Request.
 */
function refresh_session_user(): void
{
    static $erledigt = false;
    if ($erledigt) {
        return;
    }
    $erledigt = true;

    $fresh = User::findActiveForSession((int) (current_user()['id'] ?? 0), current_tenant_id());

    if ($fresh === null) {
        destroy_session();
        header('Location: /login.php');
        exit;
    }

    $_SESSION['user'] = array_merge($_SESSION['user'], $fresh);
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
 * Erzwingt die Super-Admin-Rolle (Mandantenverwaltung).
 */
function require_super_admin(): void
{
    require_login();

    if (!is_super_admin()) {
        http_response_code(403);
        die('Zugriff verweigert: Diese Aktion ist nur für Super-Admins verfügbar.');
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
 * Speichert auch die tenant_id für Multi-Tenant-Support.
 */
function login_user(array $user): void
{
    $tenantId = (int) ($user['tenant_id'] ?? 0);
    if ($tenantId <= 0) {
        throw new RuntimeException('login_user(): Benutzer ohne Mandant.');
    }

    regenerate_session_after_login();
    $_SESSION['user'] = $user;
    $_SESSION['tenant_id'] = $tenantId;
}

function logout_user(): void
{
    destroy_session();
}
