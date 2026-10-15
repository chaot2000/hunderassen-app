<?php
/**
 * admin_thedogapi_apply.php
 *
 * STUFE 2 des TheDogAPI-Imports: verarbeitet das Formular aus
 * admin_thedogapi_review.php. Schreibt NUR Felder/Bilder, die dort
 * explizit per Checkbox bestätigt wurden, und davon nur leere.
 * Mehrfaches Absenden ist unschädlich.
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
require_once __DIR__ . '/models/ContentRevision.php';
require_once __DIR__ . '/models/TheDogApiFelder.php';
require_once __DIR__ . '/models/Uebersetzung.php';

secure_session_start();
// Schreibt in den geteilten Rassenkatalog aller Mandanten, daher nur
// für den Super-Admin (wie admin_thedogapi_review.php).
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Dieses Script wird nur über das Formular aus admin_thedogapi_review.php aufgerufen.');
}

csrf_require_valid();

$pdo = Database::getConnection();
$leer = fn($v): bool => $v === null || trim((string) $v) === '';
$userId = (int) current_user()['id'];

$ergebnisse = [];
$offen = [];

if (($_POST['aktion'] ?? '') === 'temperament_uebersetzen') {
    // Sammelaktion: Rassen mit englischem, aber ohne deutsches Temperament
    // über das Wörterbuch übersetzen. Unvollständige bleiben leer.
    $rassen = $pdo->query("SELECT id, temperament_en FROM breeds
                           WHERE (temperament IS NULL OR temperament = '') AND temperament_en <> ''")->fetchAll();
    $stmt = $pdo->prepare("UPDATE breeds SET temperament = :t WHERE id = :id AND (temperament IS NULL OR temperament = '')");
    foreach ($rassen as $r) {
        $u = Uebersetzung::temperament($r['temperament_en']);
        if ($u['text'] === null) {
            $ergebnisse[] = ['id' => (int) $r['id'], 'status' => 'übersprungen (nicht im Wörterbuch: ' . implode(', ', $u['unbekannt']) . ')'];
            continue;
        }
        $pdo->beginTransaction();
        $stmt->execute([':t' => $u['text'], ':id' => $r['id']]);
        ContentRevision::protokolliere($pdo, 'breeds', (int) $r['id'], ['temperament' => null], ['temperament' => $u['text']], $userId, 'import');
        $pdo->commit();
        $ergebnisse[] = ['id' => (int) $r['id'], 'status' => 'ergänzt (Temperament: ' . $u['text'] . ')'];
    }
    $items = [];
} else {
    // Die Review-Seite schickt die Auswahl als ein JSON-Feld. Einzelne
    // Formularfelder pro Rasse würden bei einer Pauschal-Auswahl über
    // max_input_vars (Standard 1000) kommen, PHP schneidet dann still ab.
    // items[...] bleibt als Weg ohne JavaScript erhalten.
    $items = $_POST['items'] ?? [];
    if (isset($_POST['items_json'])) {
        $items = json_decode((string) $_POST['items_json'], true);
    }
    if (!is_array($items) || count($items) === 0) {
        die('Keine Daten übermittelt.');
    }
}

// Bilder laden dauert pro Rasse bis zu 15 s. Damit eine große Auswahl
// nicht an max_execution_time scheitert, wird nach ZEITBUDGET_SEK
// abgebrochen und der Rest auf der Ergebnisseite zum Weitermachen
// angeboten. Ein erneuter Lauf ist unschädlich, es werden nur leere
// Felder gefüllt.
const ZEITBUDGET_SEK = 20;
$start = microtime(true);

// Textfelder, die gefüllt werden können: alles aus TheDogAPI plus das
// deutsche Temperament, das aus temperament_en übersetzt wird.
$textFelder = array_merge(array_keys(TheDogApiFelder::TEXT), ['temperament']);
$feldLabel = TheDogApiFelder::TEXT + ['temperament' => 'Temperament'];

foreach ($items as $breedId => $daten) {
    $breedId = (int) $breedId;
    if ($breedId <= 0 || !is_array($daten)) {
        continue;
    }
    // Mindestens eine Rasse pro Lauf, sonst geht es nie voran.
    if ($ergebnisse && microtime(true) - $start > ZEITBUDGET_SEK) {
        $offen[$breedId] = $daten;
        continue;
    }

    // Aktuellen Stand lesen: Es werden NUR leere Felder gefüllt, nie
    // vorhandene Werte oder Bilder überschrieben (Wunsch 16.10.2026).
    // Ändern lässt sich ein vorhandener Wert über "Rasse bearbeiten".
    $altStmt = $pdo->prepare('SELECT ' . implode(', ', $textFelder) . ', thedogapi_id, bild_blob IS NOT NULL AS hat_bild FROM breeds WHERE id = :id');
    $altStmt->execute([':id' => $breedId]);
    $vorher = $altStmt->fetch();
    if (!$vorher) {
        $ergebnisse[] = ['id' => $breedId, 'status' => 'übersprungen (Rasse nicht gefunden)'];
        continue;
    }

    $neu = [];        // Feld => Wert, nur leere Felder
    $behalten = [];
    $hinweise = [];

    foreach (array_keys(TheDogApiFelder::TEXT) as $feld) {
        $wert = trim((string) ($daten[$feld] ?? ''));
        if (empty($daten['uebernehmen_' . $feld]) || $wert === '') {
            continue;
        }
        if (!$leer($vorher[$feld])) {
            $behalten[] = $feldLabel[$feld];
            continue;
        }
        $neu[$feld] = $wert;
    }

    // Deutsches Temperament aus dem englischen, wenn das Häkchen gesetzt
    // ist. Ist das englische schon da, wird es dafür genommen.
    // Ein vorhandenes deutsches Temperament bleibt still stehen.
    if (!empty($daten['uebernehmen_temperament_en']) && $leer($vorher['temperament'])) {
        $en = $neu['temperament_en'] ?? $vorher['temperament_en'];
        $u = Uebersetzung::temperament($en);
        if ($u['text'] !== null) {
            $neu['temperament'] = $u['text'];
        } elseif ($u['unbekannt']) {
            $hinweise[] = 'Temperament nicht übersetzt, unbekannt: ' . implode(', ', $u['unbekannt']);
        }
    }

    $sets = [];
    $params = [':id' => $breedId];
    foreach ($neu as $feld => $wert) {
        // Leer-Bedingung im UPDATE wiederholen: Hat jemand das Feld
        // zwischen Lesen und Schreiben gefüllt, bleibt sein Wert stehen.
        $sets[] = "{$feld} = IF({$feld} IS NULL OR {$feld} = '', :{$feld}, {$feld})";
        $params[':' . $feld] = $wert;
    }

    $bildStatus = null;
    if (!empty($daten['uebernehmen_bild']) && !empty($daten['bild_url'])) {
        if ($vorher['hat_bild']) {
            $behalten[] = 'Bild';
        } else {
            $bildStatus = uebernehmeBildVonUrl($daten['bild_url']);
            if ($bildStatus['success']) {
                // bild_mime VOR bild_blob: MySQL wertet die Zuweisungen von
                // links nach rechts aus, die IF-Bedingung soll den alten Stand sehen.
                $sets[] = 'bild_mime = IF(bild_blob IS NULL, :bild_mime, bild_mime)';
                $sets[] = 'bild_blob = IF(bild_blob IS NULL, :bild_blob, bild_blob)';
                $sets[] = 'bild_updated_at = NOW()';
                $params[':bild_blob'] = $bildStatus['blob'];
                $params[':bild_mime'] = $bildStatus['mime'];
            } else {
                $hinweise[] = 'Bild-Übernahme fehlgeschlagen: ' . $bildStatus['message'];
            }
        }
    }

    // Ausdrücklich gewünschte Korrektur einer falschen Altzuordnung: die
    // einzige Stelle, an der ein vorhandener Wert überschrieben wird.
    // Die alte ID steht danach in den Versionen der Rasse.
    $neueId = trim((string) ($daten['thedogapi_id'] ?? ''));
    if (!empty($daten['korrigieren']) && $neueId !== '' && preg_match('/^\d{1,10}$/', $neueId)
        && (string) $vorher['thedogapi_id'] !== $neueId) {
        $sets[] = 'thedogapi_id = :thedogapi_id';
        $sets[] = 'thedogapi_stand = NOW()';
        $params[':thedogapi_id'] = $neueId;
        $neu['thedogapi_id'] = $neueId;
        $hinweise[] = 'Zuordnung korrigiert';
        $daten['markieren'] = null;
    }

    if (!empty($daten['markieren'])) {
        $neueId = trim((string) ($daten['thedogapi_id'] ?? ''));
        if (!$leer($vorher['thedogapi_id']) && (string) $vorher['thedogapi_id'] !== $neueId) {
            $behalten[] = 'Zuordnung (schon mit anderer TheDogAPI-Rasse verknüpft)';
        } elseif ($neueId !== '') {
            $sets[] = 'thedogapi_id = :thedogapi_id';
            $sets[] = 'thedogapi_stand = NOW()';
            $params[':thedogapi_id'] = $neueId;
            $neu['thedogapi_id'] = $neueId;
        }
    }

    $zusatz = ($behalten ? ' Nicht überschrieben: ' . implode(', ', $behalten) . '.' : '')
        . ($hinweise ? ' (' . implode('; ', $hinweise) . ')' : '');

    if (count($sets) === 0) {
        $ergebnisse[] = ['id' => $breedId, 'status' => 'übersprungen (nichts Neues)' . $zusatz];
        continue;
    }

    try {
        $pdo->beginTransaction();
        $altStmt = $pdo->prepare('SELECT ' . implode(', ', $textFelder) . ', thedogapi_id FROM breeds WHERE id = :id FOR UPDATE');
        $altStmt->execute([':id' => $breedId]);
        $alt = $altStmt->fetch() ?: [];
        $pdo->prepare('UPDATE breeds SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
        ContentRevision::protokolliere($pdo, 'breeds', $breedId, $alt, $neu, $userId, 'import');
        $pdo->commit();
        $ergebnisse[] = ['id' => $breedId, 'status' => 'ergänzt' . $zusatz];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
    // Die URL kommt aus dem Formular. Nur Bilder von TheDogAPI laden,
    // sonst ließe sich der Server auf beliebige Adressen schicken.
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !preg_match('/(^|\.)thedogapi\.com$/', $host)) {
        return ['success' => false, 'message' => 'Bildadresse ist nicht von TheDogAPI.'];
    }

    $tmpDatei = tempnam(sys_get_temp_dir(), 'tdapi_');
    if ($tmpDatei === false) {
        return ['success' => false, 'message' => 'Konnte keine temporäre Datei anlegen.'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        // Weiterleitungen nur innerhalb von HTTPS, höchstens drei.
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
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

<?php
$anzahlErgaenzt = count(array_filter($ergebnisse, fn($r) => str_starts_with($r['status'], 'ergänzt')));
$anzahlFehler = count(array_filter($ergebnisse, fn($r) => str_starts_with($r['status'], 'FEHLER')));
?>
<h1 class="text-xl font-bold mb-2"><?= $offen ? 'Import läuft' : 'Import abgeschlossen' ?></h1>
<p class="mb-4">
    Ergänzt: <?= $anzahlErgaenzt ?> ·
    übersprungen: <?= count($ergebnisse) - $anzahlErgaenzt - $anzahlFehler ?> ·
    Fehler: <?= $anzahlFehler ?><?= $offen ? ' · noch offen: ' . count($offen) : '' ?>
</p>
<ul class="space-y-1 mb-4">
<?php foreach ($ergebnisse as $r): ?>
    <li class="<?= str_starts_with($r['status'], 'FEHLER') ? 'tdapi-fehler' : '' ?>">
        Rasse #<?= $r['id'] ?>: <?= e($r['status']) ?>
    </li>
<?php endforeach; ?>
</ul>
<?php if ($offen): ?>
<form action="/admin_thedogapi_apply.php" method="post" class="mb-4">
    <?= csrf_field() ?>
    <input type="hidden" name="items_json" value="<?= e(json_encode($offen, JSON_UNESCAPED_UNICODE)) ?>">
    <p class="mb-2"><?= count($offen) ?> Rassen sind noch offen. Die Übernahme läuft in Etappen, damit der Server nicht abbricht.</p>
    <button type="submit" class="min-h-[44px] bg-tanne hover:bg-tanneDk text-creme font-bold px-5 rounded-lg">Mit den restlichen <?= count($offen) ?> Rassen weitermachen</button>
</form>
<?php endif; ?>
<p><a href="/admin_thedogapi_review.php" class="underline">← Zurück zur Review (weitere Rassen abgleichen)</a></p>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
