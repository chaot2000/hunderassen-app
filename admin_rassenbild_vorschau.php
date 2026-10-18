<?php
/**
 * admin_rassenbild_vorschau.php
 *
 * Reicht ein Bildkandidat von TheDogAPI oder Wikimedia über die eigene
 * Seite durch, damit der Zuschneider in admin_rassenbild.php ihn auf ein
 * Canvas zeichnen darf. Fremde Bilder ohne passende CORS-Kopfzeilen
 * würden das Canvas sperren, der Ausschnitt ließe sich nicht speichern.
 *
 * Nur für den Super-Admin und nur für Kandidaten, die im Zwischenspeicher
 * zur Zuordnung der Rasse gehören. Eine Adresse nimmt die Seite nie
 * entgegen, sonst ließe sich der Server auf beliebige Ziele schicken.
 *
 * Aufruf: ?id=<Rasse>&quelle=thedogapi|wikimedia&bild=<Bild-ID bzw. Dateiname>
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/RassenBild.php';
require_once __DIR__ . '/models/TheDogApiBilder.php';
require_once __DIR__ . '/models/WikimediaBilder.php';

secure_session_start();
require_super_admin();

$rasse = RassenBild::finde((int) ($_GET['id'] ?? 0));
$bildId = (string) ($_GET['bild'] ?? '');
$kandidat = match ($_GET['quelle'] ?? '') {
    'thedogapi' => $rasse ? TheDogApiBilder::kandidat((string) $rasse['thedogapi_id'], $bildId) : null,
    'wikimedia' => $rasse ? WikimediaBilder::kandidat((string) $rasse['wikidata_id'], $bildId) : null,
    default     => null,
};
$erlaubt = $kandidat !== null && (TheDogApiBilder::istTheDogApiUrl($kandidat['url']) || WikimediaBilder::istWikimediaUrl($kandidat['url']));
if (!$erlaubt) {
    http_response_code(404);
    exit;
}

$r = ExternesBild::holen($kandidat['url']);
$info = $r['daten'] !== null ? @getimagesizefromstring($r['daten']) : false;
if ($info === false || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(502);
    exit;
}

header('Content-Type: ' . $info['mime']);
header('Content-Length: ' . strlen($r['daten']));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
echo $r['daten'];
