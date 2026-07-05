<?php
/**
 * admin_thedogapi_review.php
 *
 * STUFE 1 des TheDogAPI-Imports: liest ausschließlich, schreibt
 * NICHTS in die Datenbank. Holt alle Rassen von TheDogAPI, matcht sie
 * per Fuzzy-Logik gegen die vorhandenen VDH-Rassen und zeigt für jeden
 * Treffer eine Gegenüberstellung (aktueller DB-Stand vs. TheDogAPI),
 * damit die Werte manuell geprüft/editiert werden können, bevor in
 * Stufe 2 (admin_thedogapi_apply.php) etwas übernommen wird.
 *
 * Aufruf: einfach im Browser als eingeloggter Admin öffnen.
 * Ergebnis: ein Formular, jede Zeile eine Rasse, mit Checkbox pro
 * übernehmbarem Feld/Bild plus editierbaren Textfeldern. Submit geht
 * an Stufe 2.
 *
 * WICHTIG: API-Key unten eintragen (config/thedogapi.php empfohlen,
 * hier der Einfachheit halber als Konstante — bei produktivem Einsatz
 * bitte in eine eigene, nicht versionierte Config-Datei auslagern,
 * analog zu config/database.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';

secure_session_start();
require_admin();

const THEDOGAPI_KEY = ' live_PHvjRcqAvU1R1DeM6j3rneqbKeAUaLJ3x2B1NgU8FBfO2zJXdlQBRHBnxoUeykJE ';
const THEDOGAPI_URL  = 'https://api.thedogapi.com/v1/breeds';
const CACHE_DATEI    = __DIR__ . '/cache_thedogapi_breeds.json';
const CACHE_MAX_ALTER_SEK = 86400; // 1 Tag – TheDogAPI ändert sich selten

// Nur Treffer ab diesem Score werden überhaupt vorgeschlagen (0-100,
// siehe berechneMatchScore()). Alles darunter wird als "kein Treffer"
// behandelt und taucht am Ende der Liste als unmatched auf.
const MATCH_SCHWELLE = 55;

// -----------------------------------------------------------------
// Übersetzungs-Wörterbuch für die häufigsten kynologischen Begriffe.
// Notwendig, weil VDH-Namen deutsch und TheDogAPI-Namen englisch
// sind — reine String-Ähnlichkeit (z.B. Levenshtein) versagt sonst
// bei fast allen Rassen. Bewusst NICHT vollständig, sondern auf die
// häufigsten Bestandteile beschränkt; seltene/exotische Rassen landen
// dann eher in der "kein automatischer Treffer"-Gruppe und werden von
// Hand zugeordnet (siehe manuelle Override-Liste weiter unten).
// -----------------------------------------------------------------
const BEGRIFFS_UEBERSETZUNG = [
    'schäferhund' => 'shepherd dog',
    'schaferhund' => 'shepherd dog',
    'terrier' => 'terrier',
    'dogge' => 'mastiff',
    'bulldogge' => 'bulldog',
    'spaniel' => 'spaniel',
    'retriever' => 'retriever',
    'pinscher' => 'pinscher',
    'schnauzer' => 'schnauzer',
    'setter' => 'setter',
    'pudel' => 'poodle',
    'windhund' => 'greyhound',
    'bracke' => 'hound',
    'laufhund' => 'hound',
    'vorstehhund' => 'pointer',
    'wachtelhund' => 'spaniel',
    'sennenhund' => 'mountain dog',
    'berghund' => 'mountain dog',
    'hütehund' => 'sheepdog',
    'hutehund' => 'sheepdog',
    'treibhund' => 'cattle dog',
    'zwerg' => 'miniature',
    'riesen' => 'giant',
    'kurzhaar' => 'shorthaired',
    'langhaar' => 'longhaired',
    'rauhaar' => 'wirehaired',
    'drahthaar' => 'wirehaired',
    'glatthaar' => 'smooth',
    'shar pei' => 'shar-pei',
    'chow chow' => 'chow chow',
    'dackel' => 'dachshund',
    'teckel' => 'dachshund',
    'spitz' => 'spitz',
    'malinois' => 'malinois',
    'deutsch' => 'german',
    'deutscher' => 'german',
    'englisch' => 'english',
    'englische' => 'english',
    'französisch' => 'french',
    'französische' => 'french',
    'belgisch' => 'belgian',
    'belgische' => 'belgian',
    'amerikanisch' => 'american',
    'amerikanische' => 'american',
    'irisch' => 'irish',
    'irische' => 'irish',
    'schottisch' => 'scottish',
    'schottische' => 'scottish',
    'sibirisch' => 'siberian',
    'russisch' => 'russian',
    'russische' => 'russian',
    'ungarisch' => 'hungarian',
    'ungarische' => 'hungarian',
    'österreichisch' => 'austrian',
    'schweizer' => 'swiss',
    'spanisch' => 'spanish',
    'spanische' => 'spanish',
    'portugiesisch' => 'portuguese',
    'portugiesische' => 'portuguese',
    'italienisch' => 'italian',
    'italienische' => 'italian',
    'holländisch' => 'dutch',
    'holländische' => 'dutch',
    'niederländisch' => 'dutch',
];

// -----------------------------------------------------------------
// Manuelle Override-Liste: für Rassen, deren deutscher und englischer
// Name komplett unterschiedlich sind (keine Wortstamm-Überlappung
// möglich) und die daher auch nach Übersetzung nicht automatisch
// gefunden würden. Bei Bedarf einfach ergänzen — Format:
// 'exakter VDH-Name (klein geschrieben)' => 'exakter TheDogAPI-Name'
// -----------------------------------------------------------------
const MANUELLE_ZUORDNUNG = [
    'deutscher schäferhund' => 'German Shepherd Dog',
    'labrador retriever' => 'Labrador Retriever',
    'golden retriever' => 'Golden Retriever',
    'berner sennenhund' => 'Bernese Mountain Dog',
    'zwergpinscher' => 'Miniature Pinscher',
    'rauhaardackel' => 'Dachshund',
    'kurzhaardackel' => 'Dachshund',
    'langhaardackel' => 'Dachshund',
    'mops' => 'Pug',
    'malteser' => 'Maltese',
    'chihuahua' => 'Chihuahua',
    'boxer' => 'Boxer',
    'dobermann' => 'Dobermann',
    'rottweiler' => 'Rottweiler',
    'weimaraner' => 'Weimaraner',
    'foxterrier' => 'Fox Terrier',
    'jack russell terrier' => 'Jack Russell Terrier',
    'bologneser' => 'Bolognese',
    'havaneser' => 'Havanese',
    'shih tzu' => 'Shih Tzu',
    'basset hound' => 'Basset Hound',
    'beagle' => 'Beagle',
    'bloodhound' => 'Bloodhound',
    'dalmatiner' => 'Dalmatian',
    'neufundländer' => 'Newfoundland',
    'bernhardiner' => 'St. Bernard',
    'leonberger' => 'Leonberger',
    'chow chow' => 'Chow Chow',
    'shar pei' => 'Shar Pei',
    'akita' => 'Akita',
    'shiba inu' => 'Shiba Inu',
    'siberian husky' => 'Siberian Husky',
    'alaskan malamute' => 'Alaskan Malamute',
    'border collie' => 'Border Collie',
    'australian shepherd' => 'Australian Shepherd',
];

// ===================================================================
// 1) TheDogAPI-Daten holen (mit Datei-Cache, um nicht bei jedem
//    Testlauf erneut 300+ Requests abzusetzen)
// ===================================================================
function ladeTheDogApiBreeds(): array
{
    if (is_file(CACHE_DATEI) && (time() - filemtime(CACHE_DATEI)) < CACHE_MAX_ALTER_SEK) {
        $inhalt = file_get_contents(CACHE_DATEI);
        $daten = json_decode($inhalt, true);
        if (is_array($daten)) {
            return $daten;
        }
    }

    $ch = curl_init(THEDOGAPI_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . THEDOGAPI_KEY],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $fehler = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        die('Fehler beim Abruf von TheDogAPI (HTTP ' . $httpCode . '): ' . e($fehler));
    }

    $daten = json_decode($response, true);
    if (!is_array($daten)) {
        die('TheDogAPI-Antwort konnte nicht als JSON gelesen werden.');
    }

    file_put_contents(CACHE_DATEI, $response);

    return $daten;
}

// ===================================================================
// 2) Matching
// ===================================================================
function normalisiere(string $text): string
{
    $text = mb_strtolower(trim($text));
    $text = str_replace(['ä', 'ö', 'ü', 'ß'], ['a', 'o', 'u', 'ss'], $text);
    $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function uebersetzeBegriffe(string $normalisierterVdhName): string
{
    $ersetzt = $normalisierterVdhName;
    foreach (BEGRIFFS_UEBERSETZUNG as $de => $en) {
        $deNorm = normalisiere($de);
        if (str_contains($ersetzt, $deNorm)) {
            $ersetzt = str_replace($deNorm, $en, $ersetzt);
        }
    }
    return $ersetzt;
}

/**
 * Liefert einen Score 0-100 für die Ähnlichkeit zwischen einem
 * VDH-Rassenamen und einem TheDogAPI-Rassenamen.
 */
function berechneMatchScore(string $vdhName, string $apiName): int
{
    $vdhNorm = normalisiere($vdhName);
    $apiNorm = normalisiere($apiName);

    // 1) Manuelle Zuordnung hat immer Vorrang -> 100
    if (isset(MANUELLE_ZUORDNUNG[$vdhNorm]) && normalisiere(MANUELLE_ZUORDNUNG[$vdhNorm]) === $apiNorm) {
        return 100;
    }

    // 2) Exakte Übereinstimmung nach Übersetzung
    $vdhUebersetzt = uebersetzeBegriffe($vdhNorm);
    if ($vdhUebersetzt === $apiNorm) {
        return 95;
    }

    // 3) similar_text auf übersetzter Variante
    similar_text($vdhUebersetzt, $apiNorm, $prozentUebersetzt);

    // 4) similar_text auf unübersetzter Variante (falls Name eh schon
    //    ähnlich klingt, z.B. "Boxer" -> "Boxer")
    similar_text($vdhNorm, $apiNorm, $prozentRoh);

    return (int) round(max($prozentUebersetzt, $prozentRoh));
}

function findeBestesMatch(string $vdhName, array $apiBreeds): ?array
{
    $vdhNorm = normalisiere($vdhName);

    // Manuelle Zuordnung zuerst prüfen
    if (isset(MANUELLE_ZUORDNUNG[$vdhNorm])) {
        $zielName = normalisiere(MANUELLE_ZUORDNUNG[$vdhNorm]);
        foreach ($apiBreeds as $b) {
            if (normalisiere($b['name']) === $zielName) {
                return ['breed' => $b, 'score' => 100];
            }
        }
    }

    $bester = null;
    $besterScore = 0;
    foreach ($apiBreeds as $b) {
        $score = berechneMatchScore($vdhName, $b['name']);
        if ($score > $besterScore) {
            $besterScore = $score;
            $bester = $b;
        }
    }

    if ($bester !== null && $besterScore >= MATCH_SCHWELLE) {
        return ['breed' => $bester, 'score' => $besterScore];
    }

    return null;
}

// ===================================================================
// 3) VDH-Rassen aus der DB laden (nur die, die noch nicht abgeglichen
//    wurden — thedogapi_id IS NULL — außer ?alle=1 wird übergeben)
// ===================================================================
$pdo = Database::getConnection();

$nurOffene = !isset($_GET['alle']);
$sql = 'SELECT id, name, beschreibung, temperament, lebenserwartung, zuchtzweck, bild_blob IS NOT NULL AS hat_bild
        FROM breeds';
if ($nurOffene) {
    $sql .= ' WHERE thedogapi_id IS NULL';
}
$sql .= ' ORDER BY name';

$stmt = $pdo->query($sql);
$vdhRassen = $stmt->fetchAll();

$apiBreeds = ladeTheDogApiBreeds();

// ===================================================================
// 4) Matching durchführen
// ===================================================================
$treffer = [];
$ohneTreffer = [];

foreach ($vdhRassen as $rasse) {
    $match = findeBestesMatch($rasse['name'], $apiBreeds);
    if ($match !== null) {
        $treffer[] = [
            'vdh' => $rasse,
            'api' => $match['breed'],
            'score' => $match['score'],
        ];
    } else {
        $ohneTreffer[] = $rasse;
    }
}

// Nach Score absteigend sortieren, damit sichere Treffer oben stehen
usort($treffer, fn($a, $b) => $b['score'] <=> $a['score']);

$pageTitle = 'TheDogAPI Abgleich – Review';
require __DIR__ . '/views/partials/header.php';
?>

<style>
    .tdapi-card { border: 1px solid #ddd; border-radius: 8px; padding: 1rem; margin-bottom: 1rem; background: #fff; }
    .tdapi-card.hoch { border-left: 5px solid #2e9e44; }
    .tdapi-card.mittel { border-left: 5px solid #d9a400; }
    .tdapi-card.niedrig { border-left: 5px solid #c94b4b; }
    .tdapi-card h3 { margin: 0 0 .5rem 0; display: flex; justify-content: space-between; align-items: center; font-size: 1rem; }
    .tdapi-score { font-size: .8rem; font-weight: normal; color: #666; }
    .tdapi-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    @media (max-width: 640px) {
        .tdapi-grid { grid-template-columns: 1fr; }
        .tdapi-card h3 { flex-direction: column; align-items: flex-start; gap: .25rem; }
        .tdapi-submit { width: 100%; box-sizing: border-box; text-align: center; }
    }
    .tdapi-grid > div { background: #fafafa; border-radius: 6px; padding: .5rem .75rem; }
    .tdapi-grid h4 { margin: 0 0 .4rem 0; font-size: .75rem; text-transform: uppercase; color: #888; }
    .tdapi-feld { margin-bottom: .5rem; }
    .tdapi-feld label { display: block; font-size: .75rem; color: #666; margin-bottom: .15rem; }
    .tdapi-feld input[type=text] { width: 100%; box-sizing: border-box; font-family: inherit; font-size: .85rem; padding: .3rem; border: 1px solid #ccc; border-radius: 4px; }
    .tdapi-checkboxen { display: flex; gap: 1rem; margin-top: .5rem; flex-wrap: wrap; }
    .tdapi-checkboxen label { font-size: .8rem; display: flex; align-items: center; gap: .3rem; }
    .tdapi-card img.thumb { max-width: 100%; border-radius: 4px; margin-bottom: .4rem; }
    .tdapi-unmatched { background: #fff8f0; border: 1px dashed #e0a050; padding: .5rem .75rem; margin-bottom: .3rem; border-radius: 4px; font-size: .85rem; }
    .tdapi-submit { position: sticky; bottom: 1rem; background: #2e6bd9; color: white; border: none; padding: .8rem 1.5rem; border-radius: 6px; font-size: 1rem; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,.2); }
    .tdapi-info { background: #eef4ff; border: 1px solid #b6d0ff; padding: .75rem 1rem; border-radius: 6px; margin-bottom: 1.5rem; }
</style>

<h1 class="text-xl font-bold mb-4">🐾 TheDogAPI Abgleich – Stufe 1: Review</h1>

<div class="tdapi-info">
    <?= count($treffer) ?> automatische Treffer gefunden
    (<?= count(array_filter($treffer, fn($t) => $t['score'] >= 85)) ?> sicher ≥85,
     <?= count(array_filter($treffer, fn($t) => $t['score'] >= MATCH_SCHWELLE && $t['score'] < 85)) ?> unsicher),
    <?= count($ohneTreffer) ?> ohne Treffer.
    Es wird <strong>noch nichts in der Datenbank geändert</strong> — erst nach Klick auf
    "Ausgewählte übernehmen" unten, das geht an Stufe 2.
    <?php if ($nurOffene): ?>
        <br><a href="?alle=1" class="underline">Auch bereits abgeglichene Rassen erneut anzeigen</a>
    <?php endif; ?>
</div>

<form action="/admin_thedogapi_apply.php" method="post">
<?= csrf_field() ?>

<?php foreach ($treffer as $t):
    $vdh = $t['vdh'];
    $api = $t['api'];
    $score = $t['score'];
    $klasse = $score >= 85 ? 'hoch' : ($score >= 70 ? 'mittel' : 'niedrig');
    $rowId = (int) $vdh['id'];
?>
<div class="tdapi-card <?= $klasse ?>">
    <h3>
        <?= e($vdh['name']) ?> ↔ <?= e($api['name']) ?>
        <span class="tdapi-score">Score: <?= $score ?>%</span>
    </h3>

    <input type="hidden" name="items[<?= $rowId ?>][breed_id]" value="<?= $rowId ?>">
    <input type="hidden" name="items[<?= $rowId ?>][thedogapi_id]" value="<?= e((string)$api['id']) ?>">

    <div class="tdapi-grid">
        <div>
            <h4>Aktuell in der DB</h4>
            <?php if ($vdh['hat_bild']): ?>
                <img class="thumb" src="/bildausgabe.php?id=<?= $rowId ?>" alt="">
            <?php else: ?>
                <p style="color:#999;font-size:.8rem;">Kein Bild vorhanden</p>
            <?php endif; ?>
            <p style="font-size:.8rem;"><?= nl2br(e(mb_substr((string)$vdh['beschreibung'], 0, 300))) ?>…</p>
            <p style="font-size:.8rem;color:#666;">
                Temperament: <?= e((string)($vdh['temperament'] ?? '—')) ?><br>
                Lebenserwartung: <?= e((string)($vdh['lebenserwartung'] ?? '—')) ?><br>
                Zuchtzweck: <?= e((string)($vdh['zuchtzweck'] ?? '—')) ?>
            </p>
        </div>
        <div>
            <h4>Von TheDogAPI</h4>
            <?php if (!empty($api['image']['url'])): ?>
                <img class="thumb" src="<?= e($api['image']['url']) ?>" alt="">
            <?php endif; ?>
            <div class="tdapi-feld">
                <label>Temperament (übernehmbar)</label>
                <input type="text" name="items[<?= $rowId ?>][temperament]" value="<?= e((string)($api['temperament'] ?? '')) ?>">
            </div>
            <div class="tdapi-feld">
                <label>Lebenserwartung (übernehmbar)</label>
                <input type="text" name="items[<?= $rowId ?>][lebenserwartung]" value="<?= e((string)($api['life_span'] ?? '')) ?>">
            </div>
            <div class="tdapi-feld">
                <label>Zuchtzweck / bred_for (übernehmbar)</label>
                <input type="text" name="items[<?= $rowId ?>][zuchtzweck]" value="<?= e((string)($api['bred_for'] ?? '')) ?>">
            </div>
        </div>
    </div>

    <div class="tdapi-checkboxen">
        <label><input type="checkbox" name="items[<?= $rowId ?>][uebernehmen_temperament]" value="1"> Temperament übernehmen</label>
        <label><input type="checkbox" name="items[<?= $rowId ?>][uebernehmen_lebenserwartung]" value="1"> Lebenserwartung übernehmen</label>
        <label><input type="checkbox" name="items[<?= $rowId ?>][uebernehmen_zuchtzweck]" value="1"> Zuchtzweck übernehmen</label>
        <?php if (!empty($api['image']['url'])): ?>
        <label><input type="checkbox" name="items[<?= $rowId ?>][uebernehmen_bild]" value="1" <?= $vdh['hat_bild'] ? '' : 'checked' ?>>
            Bild übernehmen <?= $vdh['hat_bild'] ? '(überschreibt vorhandenes!)' : '(noch kein Bild vorhanden)' ?>
        </label>
        <input type="hidden" name="items[<?= $rowId ?>][bild_url]" value="<?= e($api['image']['url']) ?>">
        <?php endif; ?>
        <label><input type="checkbox" name="items[<?= $rowId ?>][markieren]" value="1" checked> Als "abgeglichen" markieren (thedogapi_id + Datum setzen)</label>
    </div>
</div>
<?php endforeach; ?>

<button type="submit" class="tdapi-submit">✅ Ausgewählte übernehmen → Stufe 2</button>

</form>

<?php if (count($ohneTreffer) > 0): ?>
<h2 class="text-lg font-bold mt-6 mb-2">Ohne automatischen Treffer (<?= count($ohneTreffer) ?>)</h2>
<p style="font-size:.85rem;color:#666;">
    Diese Rassen konnten nicht sicher zugeordnet werden — meist exotischere Rassen ohne
    Eintrag in TheDogAPI, oder Namen, die die Übersetzungstabelle nicht abdeckt.
    Bei Bedarf <code>MANUELLE_ZUORDNUNG</code> im Script ergänzen und neu laden.
</p>
<?php foreach ($ohneTreffer as $r): ?>
    <div class="tdapi-unmatched"><?= e($r['name']) ?></div>
<?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
