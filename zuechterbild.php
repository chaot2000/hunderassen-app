<?php
/**
 * zuechterbild.php
 *
 * Liefert das Foto eines Züchters direkt aus der Datenbank (BLOB) aus.
 * Analog zu hundebild.php. Portal-Prinzip: nur Ersteller oder Admin
 * dürfen das Foto sehen (Zuechter::findImageByIdForUser() erzwingt das
 * Scoping).
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Zuechter.php';

secure_session_start();
require_login();

$currentUserId = (int) current_user()['id'];
$isAdmin       = is_admin();

$zuechterId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$image    = $zuechterId ? Zuechter::findImageByIdForUser($zuechterId, $currentUserId, $isAdmin) : null;

if ($image === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Kein Foto vorhanden.';
    exit;
}

header('Content-Type: ' . $image['mime']);
header('Cache-Control: private, max-age=2592000, immutable'); // 30 Tage
header('Content-Length: ' . strlen($image['blob']));
echo $image['blob'];
