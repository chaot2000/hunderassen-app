<?php
/**
 * admin_thedogapi_apply.php
 *
 * STUFE 2 des TheDogAPI-Imports: verarbeitet das Formular aus
 * admin_thedogapi_review.php. Schreibt NUR Felder/Bilder, die dort
 * explizit per Checkbox bestätigt wurden — alles andere bleibt
 * unangetastet. Läuft idempotent: mehrfaches Absenden derselben Daten
 * überschreibt lediglich erneut mit denselben Werten, richtet keinen
 * Schaden an.
 *
 * Bilder werden NICHT direkt als Rohdaten von TheDogAPI übernommen,
 * sondern durch dieselbe BildVerarbeitung-Pipeline geschickt wie
 * reguläre Admin-Uploads (Verkleinerung auf 1200px, Re-Encoding),
 * damit das Format/die Herkunft konsistent zu den übrigen Bildern
 * bleibt und keine unerwarteten Formate/Metadaten in die DB gelangen.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/models/BildVerarbeitung.php';

secure_session_start();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Dieses Script wird nur über das Formular aus admin_thedogapi_review.php aufgerufen.');
}

csrf_require_valid();

$items = $_POST['items'] ?? [];
if (!is_array($items) || count($items) === 0) {
    die('Keine Daten übermittelt.');
}

$pdo = Database::getConnection();

$ergebnisse = [];

foreach ($items as $breedId => $daten) {
    $breedId = (int) $breedId;
    if ($breedId <= 0) {
        continue;
    }

    $updates = [];
    $params = [':id' => $breedId];

    if (!empty($daten['uebernehmen_temperament']) && trim((string)($daten['temperament'] ?? '')) !== '') {
        $updates[] = 'temperament = :temperament';
        $params[':temperament'] = trim($daten['temperament']);
    }

    if (!empty($daten['uebernehmen_lebenserwartung']) && trim((string)($daten['lebenserwartung'] ?? '')) !== '') {
        $updates[] = 'lebenserwartung = :lebenserwartung';
        $params[':lebenserwartung'] = trim($daten['lebenserwartung']);
    }

    if (!empty($daten['uebernehmen_zuchtzweck']) && trim((string)($daten['zuchtzweck'] ?? '')) !== '') {
        $updates[] = 'zuchtzweck = :zuchtzweck';
        $params[':zuchtzweck'] = trim($daten['zuchtzweck']);
    }

    $bildStatus = null;
    if (!empty($daten['uebernehmen_bild']) && !empty($daten['bild_url'])) {
        $bildStatus = uebernehmeBildVonUrl($daten['bild_url']);
        if ($bildStatus['success']) {
            $updates[] = 'bild_blob = :bild_blob';
            $updates[] = 'bild_mime = :bild_mime';
            $updates[] = 'bild_updated_at = NOW()';
            $params[':bild_blob'] = $bildStatus['blob'];
            $params[':bild_mime'] = $bildStatus['mime'];
        }
    }

    if (!empty($daten['markieren'])) {
        $updates[] = 'thedogapi_id = :thedogapi_id';
        $updates[] = 'thedogapi_stand = NOW()';
        $params[':thedogapi_id'] = $daten['thedogapi_id'] ?? null;
    }

    if (count($updates) === 0) {
        $ergebnisse[] = ['id' => $breedId, 'status' => 'übersprungen (nichts ausgewählt)'];
        continue;
    }

    $sql = 'UPDATE breeds SET ' . implode(', ', $updates) . ' WHERE id = :id';
    $stmt = $pdo->prepare($sql);

    try {
        $stmt->execute($params);
        $status = 'aktualisiert';
        if ($bildStatus !== null && !$bildStatus['success']) {
            $status .= ' (Bild-Übernahme fehlgeschlagen: ' . $bildStatus['message'] . ')';
        }
        $ergebnisse[] = ['id' => $breedId, 'status' => $status];
    } catch (PDOException $e) {
        $ergebnisse[] = ['id' => $breedId, 'status' => 'FEHLER: ' . $e->getMessage()];
    }
}

/**
 * Lädt ein Bild von einer externen URL, prüft/verarbeitet es über
 * dieselbe Logik wie BildVerarbeitung::verarbeiteUpload(), indem ein
 * temporäres $_FILES-kompatibles Array simuliert wird.
 */
function uebernehmeBildVonUrl(string $url): array
{
    $tmpDatei = tempnam(sys_get_temp_dir(), 'tdapi_');
    if ($tmpDatei === false) {
        return ['success' => false, 'message' => 'Konnte keine temporäre Datei anlegen.'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $bildDaten = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($bildDaten === false || $httpCode !== 200) {
        @unlink($tmpDatei);
        return ['success' => false, 'message' => 'Download fehlgeschlagen (HTTP ' . $httpCode . ')'];
    }

    file_put_contents($tmpDatei, $bildDaten);

    // BildVerarbeitung::verarbeiteUpload() erwartet ein $_FILES-Format
    // inkl. is_uploaded_file()-Check — das schlägt bei einer Datei aus
    // tempnam() fehl, weil sie nicht über einen echten HTTP-Upload
    // kam. Deshalb hier eine schlanke Parallel-Verarbeitung statt der
    // Klasse direkt, aber mit denselben Kern-Regeln (Verkleinerung,
    // echter MIME-Check anhand der Bytes).
    $result = verarbeiteExternesBild($tmpDatei);
    @unlink($tmpDatei);

    return $result;
}

function verarbeiteExternesBild(string $pfad): array
{
    $info = @getimagesize($pfad);
    if ($info === false || !isset($info['mime'])) {
        return ['success' => false, 'message' => 'Kein gültiges Bildformat.'];
    }

    $mime = $info['mime'];
    $erlaubt = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $erlaubt, true)) {
        return ['success' => false, 'message' => 'Nicht unterstütztes Format: ' . $mime];
    }

    $bild = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($pfad),
        'image/png' => @imagecreatefrompng($pfad),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($pfad) : false,
        default => false,
    };

    if ($bild === false) {
        return ['success' => false, 'message' => 'Bild konnte nicht gelesen werden.'];
    }

    $breite = imagesx($bild);
    $hoehe = imagesy($bild);
    $maxBreite = 1200;

    if ($breite > $maxBreite) {
        $zielHoehe = (int) round($hoehe * ($maxBreite / $breite));
        $verkleinert = imagecreatetruecolor($maxBreite, $zielHoehe);
        imagealphablending($verkleinert, false);
        imagesavealpha($verkleinert, true);
        $transparent = imagecolorallocatealpha($verkleinert, 0, 0, 0, 127);
        imagefilledrectangle($verkleinert, 0, 0, $maxBreite, $zielHoehe, $transparent);
        imagecopyresampled($verkleinert, $bild, 0, 0, 0, 0, $maxBreite, $zielHoehe, $breite, $hoehe);
        imagedestroy($bild);
        $bild = $verkleinert;
    }

    ob_start();
    $erfolg = match ($mime) {
        'image/jpeg' => imagejpeg($bild, null, 85),
        'image/png' => imagepng($bild, null, 6),
        'image/webp' => function_exists('imagewebp') ? imagewebp($bild, null, 85) : false,
        default => false,
    };
    $blob = ob_get_clean();
    imagedestroy($bild);

    if (!$erfolg) {
        return ['success' => false, 'message' => 'Bild konnte nicht kodiert werden.'];
    }

    return ['success' => true, 'blob' => $blob, 'mime' => $mime];
}

$pageTitle = 'TheDogAPI Import – Ergebnis';
require __DIR__ . '/views/partials/header.php';
?>

<style>
    .tdapi-fehler { color: #c94b4b; }
</style>

<h1 class="text-xl font-bold mb-4">Import abgeschlossen</h1>
<ul class="space-y-1 mb-4">
<?php foreach ($ergebnisse as $r): ?>
    <li class="<?= str_starts_with($r['status'], 'FEHLER') ? 'tdapi-fehler' : '' ?>">
        Rasse #<?= $r['id'] ?>: <?= e($r['status']) ?>
    </li>
<?php endforeach; ?>
</ul>
<p><a href="/admin_thedogapi_review.php" class="underline">← Zurück zur Review (weitere Rassen abgleichen)</a></p>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
