<?php
/**
 * bildausgabe.php
 *
 * Liefert das Bild einer Rasse direkt aus der Datenbank (BLOB) aus,
 * damit es per <img src="/bildausgabe.php?id=123"> eingebunden werden
 * kann. Getrennt von breed_detail.php/dashboard.php, damit die
 * potenziell großen Bilddaten nicht bei jeder normalen Seitenanfrage
 * mitgeladen werden müssen.
 *
 * Für eingeloggte Nutzer zugänglich (gleiche Sichtbarkeit wie die
 * Rassedaten selbst) — kein öffentlicher Zugriff ohne Login.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Breed.php';

secure_session_start();
require_login();

$breedId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$image   = $breedId ? Breed::findImageById($breedId) : null;

if ($image === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Kein Bild vorhanden.';
    exit;
}

// Ein Bild, das einmal gespeichert wurde, ändert sich nur durch ein
// erneutes Hochladen (neue bild_updated_at) — daher darf der Browser
// es lange cachen. Der ?v=... Cache-Busting-Parameter beim Einbinden
// (siehe breed_detail.php/dashboard.php) sorgt dafür, dass nach einem
// Bild-Update trotzdem die neue Version geladen wird.
header('Content-Type: ' . $image['mime']);
header('Cache-Control: private, max-age=2592000, immutable'); // 30 Tage
header('Content-Length: ' . strlen($image['blob']));
echo $image['blob'];
