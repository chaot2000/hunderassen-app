<?php
/**
 * sprache.php
 *
 * Nimmt den Sprach-Umschalter aus dem Kopf entgegen (POST mit CSRF).
 * Eingeloggt wird die Sprache in users.sprache gespeichert, sonst nur
 * in der Session. Danach zurück auf die Seite, von der man kam.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';

secure_session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

csrf_require_valid();

$sprache = (string) ($_POST['sprache'] ?? '');
if (!isset(SPRACHEN[$sprache])) {
    http_response_code(400);
    die(e(t('sprache.ungueltig')));
}

if (is_logged_in()) {
    require_login();
    $user = current_user();
    User::setSprache((int) $user['id'], current_tenant_id(), $sprache);
    $_SESSION['user']['sprache'] = $sprache;
}
$_SESSION['sprache'] = $sprache;

// Nur auf eigene Seiten zurück, kein offener Redirect: ein Pfad, der
// mit genau einem / beginnt, ohne Steuerzeichen und Backslash.
$zurueck = (string) ($_POST['zurueck'] ?? '');
if (!preg_match('#^/(?![/\\\\])[^\x00-\x1f\\\\]*$#', $zurueck)) {
    $zurueck = is_logged_in() ? '/dashboard.php' : '/login.php';
}

header('Location: ' . $zurueck, true, 303);
exit;
