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
require_once __DIR__ . '/models/TheDogApiFelder.php';
require_once __DIR__ . '/models/TheDogApiAbgleich.php';
require_once __DIR__ . '/models/Uebersetzung.php';

secure_session_start();
// Schreibt in den geteilten Rassenkatalog aller Mandanten, daher nur
// für den Super-Admin.
require_super_admin();

// API-Schlüssel liegt NICHT im Repo, sondern in der nicht versionierten
// config/thedogapi.local.php (Vorlage: config/thedogapi.local.example.php).
$thedogapiConfigDatei = __DIR__ . '/config/thedogapi.local.php';
$thedogapiConfig = is_file($thedogapiConfigDatei) ? require $thedogapiConfigDatei : [];
define('THEDOGAPI_KEY', trim((string) ($thedogapiConfig['api_key'] ?? '')));
const THEDOGAPI_URL  = 'https://api.thedogapi.com/v1/breeds';
const CACHE_DATEI    = __DIR__ . '/cache_thedogapi_breeds.json';
const CACHE_MAX_ALTER_SEK = 86400; // 1 Tag – TheDogAPI ändert sich selten

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

    if (THEDOGAPI_KEY === '') {
        http_response_code(503);
        die('Kein TheDogAPI-Schlüssel hinterlegt. Lege config/thedogapi.local.php nach der Vorlage config/thedogapi.local.example.php an.');
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
// 2) VDH-Rassen laden. Gezeigt werden Rassen ohne Zuordnung und Rassen,
//    deren vorhandene Zuordnung von einem sicheren Treffer abweicht
//    (Altlast aus dem ersten Import, Juli 2026). Mit ?alle=1 alle.
// ===================================================================
$pdo = Database::getConnection();

$nurOffene = !isset($_GET['alle']);
$vdhRassen = $pdo->query(
    'SELECT id, name, name_en, alternative_namen, thedogapi_id, beschreibung, beschreibung_en, temperament, temperament_en,
            lebenserwartung, zuchtzweck, zuchtzweck_en, bild_blob IS NOT NULL AS hat_bild
     FROM breeds ORDER BY name'
)->fetchAll();

$apiBreeds = ladeTheDogApiBreeds();

// Bild-URL je Rasse: Je nach API-Stand steckt sie in image.url oder
// nur als reference_image_id. Ältere Antworten haben gar keine.
foreach ($apiBreeds as &$b) {
    $ref = trim((string) ($b['reference_image_id'] ?? ''));
    $b['_bild_url'] = (string) ($b['image']['url'] ?? '');
    if ($b['_bild_url'] === '' && preg_match('/^[A-Za-z0-9_-]+$/', $ref)) {
        $b['_bild_url'] = 'https://cdn2.thedogapi.com/images/' . $ref . '.jpg';
    }
}
unset($b);

// ===================================================================
// 3) Zuordnen (Regeln in models/TheDogApiAbgleich.php). Berechnet wird
//    ohne die vorhandene Zuordnung, damit falsche Altlasten auffallen.
// ===================================================================
$apiNachId = [];
foreach ($apiBreeds as $b) {
    $apiNachId[(string) $b['id']] = $b;
}
$treffer = [];
$ohneTreffer = [];
$abweichend = 0;

foreach ($vdhRassen as $rasse) {
    $vorhanden = trim((string) ($rasse['thedogapi_id'] ?? ''));
    $match = TheDogApiAbgleich::bestesMatch(['thedogapi_id' => null] + $rasse, $apiBreeds);
    $weichtAb = $vorhanden !== '' && $match !== null && $match['score'] >= TheDogApiAbgleich::SICHER
        && (string) $match['breed']['id'] !== $vorhanden
        // TheDogAPI führt manche Rassen doppelt (gleicher Name, andere ID)
        && TheDogApiAbgleich::aufbereiten((string) $match['breed']['name']) !== TheDogApiAbgleich::aufbereiten((string) ($apiNachId[$vorhanden]['name'] ?? ''));
    if ($vorhanden !== '' && !$weichtAb) {
        // Vorhandene Zuordnung bestätigt (oder kein besserer Treffer): bleibt
        if ($nurOffene) {
            continue;
        }
        $match = isset($apiNachId[$vorhanden]) ? ['breed' => $apiNachId[$vorhanden], 'score' => 100, 'grund' => 'schon zugeordnet'] : $match;
    }
    if ($match === null) {
        $ohneTreffer[] = $rasse;
        continue;
    }
    $abweichend += $weichtAb ? 1 : 0;
    $treffer[] = [
        'vdh' => $rasse,
        'api' => $match['breed'],
        'score' => $match['score'],
        'grund' => $match['grund'],
        'bisher' => $weichtAb ? ($apiNachId[$vorhanden]['name'] ?? ('ID ' . $vorhanden)) : null,
    ];
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
    .tdapi-feld input[type=text], .tdapi-feld textarea { width: 100%; box-sizing: border-box; font-family: inherit; font-size: .85rem; padding: .3rem; border: 1px solid #ccc; border-radius: 4px; }
    .tdapi-checkboxen { display: flex; gap: 1rem; margin-top: .5rem; flex-wrap: wrap; }
    .tdapi-checkboxen label { font-size: .8rem; display: flex; align-items: center; gap: .3rem; }
    .tdapi-card img.thumb { max-width: 100%; border-radius: 4px; margin-bottom: .4rem; }
    .tdapi-unmatched { background: #fff8f0; border: 1px dashed #e0a050; padding: .5rem .75rem; margin-bottom: .3rem; border-radius: 4px; font-size: .85rem; }
    .tdapi-submit { position: sticky; bottom: 1rem; background: #2e6bd9; color: white; border: none; padding: .8rem 1.5rem; border-radius: 6px; font-size: 1rem; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,.2); }
    .tdapi-info { background: #eef4ff; border: 1px solid #b6d0ff; padding: .75rem 1rem; border-radius: 6px; margin-bottom: 1.5rem; }
    .tdapi-pauschal { border: 1px solid #ddd; border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; background: #fff; }
    .tdapi-pauschal table { width: 100%; border-collapse: collapse; font-size: .9rem; }
    .tdapi-pauschal th, .tdapi-pauschal td { text-align: left; padding: .4rem .5rem; border-top: 1px solid #eee; vertical-align: middle; }
    .tdapi-pauschal thead th { border-top: none; font-size: .75rem; color: #555; font-weight: normal; }
    .tdapi-pauschal button { min-height: 36px; padding: 0 .75rem; border: 1px solid #888; border-radius: 6px; background: #fafafa; margin: .1rem .25rem .1rem 0; cursor: pointer; }
    .tdapi-pauschal button:hover { background: #eee; }
    .tdapi-zahl { font-variant-numeric: tabular-nums; }
    @media (max-width: 640px) {
        .tdapi-pauschal thead { display: none; }
        .tdapi-pauschal tr { display: block; border-top: 1px solid #eee; padding: .4rem 0; }
        .tdapi-pauschal th, .tdapi-pauschal td { display: block; border: none; padding: .15rem 0; }
    }
</style>

<h1 class="text-xl font-bold mb-4">🐾 TheDogAPI Abgleich – Stufe 1: Review</h1>

<div class="tdapi-info">
    <?= count($treffer) ?> automatische Treffer gefunden
    (<?= count(array_filter($treffer, fn($t) => $t['score'] >= TheDogApiAbgleich::SICHER)) ?> sicher,
     <?= count(array_filter($treffer, fn($t) => $t['score'] < TheDogApiAbgleich::SICHER)) ?> unsicher),
    <?= count($ohneTreffer) ?> ohne Treffer.
    Es wird <strong>noch nichts in der Datenbank geändert</strong> — erst nach Klick auf
    "Ausgewählte übernehmen" unten, das geht an Stufe 2.<br>
    Übernommen werden nur <strong>leere</strong> Felder und nur, wenn noch kein Bild da ist; vorhandene Werte bleiben immer.
    Vorausgewählt sind nur sichere Treffer (≥85). Textänderungen und die Zuordnung landen in den Versionen der Rasse.
    <?php if ($abweichend > 0): ?>
        <br><strong><?= $abweichend ?> Rassen sind bisher mit einer anderen TheDogAPI-Rasse verknüpft.</strong>
        Sie stehen unten mit dem Hinweis „bisher“. Erst das Häkchen „Zuordnung korrigieren“ ändert die Verknüpfung.
    <?php endif; ?>
    <?php if ($nurOffene): ?>
        <br><a href="?alle=1" class="underline">Auch bereits abgeglichene Rassen erneut anzeigen</a>
    <?php endif; ?>
</div>

<form action="/admin_thedogapi_apply.php" method="post" id="tdapi-formular">
<?= csrf_field() ?>

<?php
// Pauschal-Auswahl pro Feld. Zählt, wie viele Rassen das Feld bekommen
// könnten: TheDogAPI hat einen Wert und bei uns ist es noch leer.
$pauschalFelder = ['bild' => 'Bild']
    + array_replace(TheDogApiFelder::TEXT, ['temperament_en' => 'Temperament (englisch, deutsch per Wörterbuch)'])
    + ['markieren' => 'Als „abgeglichen“ markieren', 'korrigieren' => 'Abweichende Zuordnung korrigieren'];
$moeglich = array_fill_keys(array_keys($pauschalFelder), ['alle' => 0, 'sicher' => 0]);
foreach ($treffer as $t) {
    foreach ($pauschalFelder as $feld => $_) {
        $geht = match ($feld) {
            'bild' => $t['api']['_bild_url'] !== '' && !$t['vdh']['hat_bild'],
            'markieren' => $t['bisher'] === null,
            'korrigieren' => $t['bisher'] !== null,
            default => TheDogApiFelder::wert($t['api'], $feld) !== '' && trim((string) ($t['vdh'][$feld] ?? '')) === '',
        };
        if ($geht) {
            $moeglich[$feld]['alle']++;
            $moeglich[$feld]['sicher'] += $t['score'] >= 85 ? 1 : 0;
        }
    }
}
?>
<section class="tdapi-pauschal" aria-labelledby="tdapi-pauschal-titel">
    <h2 id="tdapi-pauschal-titel" class="font-bold mb-1">Pauschal auswählen</h2>
    <p style="font-size:.85rem;color:#555;" class="mb-2">
        Setzt oder entfernt die Häkchen in allen Rassen unten. Felder, die bei uns schon gefüllt sind, bleiben gesperrt.
        TheDogAPI ist englisch, die Texte landen in den englischen Feldern. Das deutsche Temperament wird
        über das Wörterbuch gefüllt, Zuchtzweck und Beschreibung übersetzt du in „Rasse bearbeiten“.
    </p>
    <table>
        <thead><tr><th>Feld</th><th>Möglich (sicher / alle)</th><th>Auswählen</th></tr></thead>
        <tbody>
        <?php foreach ($pauschalFelder as $feld => $feldLabel): ?>
            <tr>
                <th scope="row"><?= e($feldLabel) ?></th>
                <td class="tdapi-zahl"><?= $moeglich[$feld]['sicher'] ?> / <?= $moeglich[$feld]['alle'] ?></td>
                <td>
                    <button type="button" data-pauschal="<?= $feld ?>" data-umfang="sicher">Sichere Treffer</button>
                    <button type="button" data-pauschal="<?= $feld ?>" data-umfang="alle">Alle Treffer</button>
                    <button type="button" data-pauschal="<?= $feld ?>" data-umfang="keine">Keine</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="mt-2" style="font-size:.9rem;" aria-live="polite">Ausgewählt: <strong id="tdapi-anzahl" class="tdapi-zahl">0</strong> Rassen.</p>
</section>

<?php foreach ($treffer as $t):
    $vdh = $t['vdh'];
    $api = $t['api'];
    $score = $t['score'];
    $klasse = $score >= TheDogApiAbgleich::SICHER ? 'hoch' : ($score >= 70 ? 'mittel' : 'niedrig');
    $rowId = (int) $vdh['id'];
    // Nur sichere Treffer sind vorausgewählt, unsichere muss man bewusst ankreuzen.
    $sicher = $score >= TheDogApiAbgleich::SICHER;
?>
<div class="tdapi-card <?= $klasse ?>" data-rasse="<?= $rowId ?>" data-sicher="<?= $sicher ? '1' : '0' ?>">
    <h3>
        <?= e($vdh['name']) ?> ↔ <?= e($api['name']) ?>
        <span class="tdapi-score">Score: <?= $score ?>% · <?= e($t['grund']) ?></span>
    </h3>
    <?php if ($t['bisher'] !== null): ?>
        <p style="font-size:.85rem;color:#8a3b1d;margin:0 0 .5rem 0;">Bisher verknüpft mit <strong><?= e($t['bisher']) ?></strong>. Das wird nur mit „Zuordnung korrigieren“ geändert.</p>
    <?php endif; ?>

    <input type="hidden" name="items[<?= $rowId ?>][thedogapi_id]" value="<?= e((string)$api['id']) ?>">

    <div class="tdapi-grid">
        <div>
            <h4>Aktuell bei uns</h4>
            <?php if ($vdh['hat_bild']): ?>
                <img class="thumb" src="/bildausgabe.php?id=<?= $rowId ?>" alt="">
            <?php else: ?>
                <p style="color:#666;font-size:.8rem;">Kein Bild vorhanden</p>
            <?php endif; ?>
            <p style="font-size:.8rem;"><?= nl2br(e(mb_substr((string)$vdh['beschreibung'], 0, 300))) ?>…</p>
            <p style="font-size:.8rem;color:#555;">
                Temperament: <?= e((string)($vdh['temperament'] ?: '—')) ?><br>
                <?php foreach (TheDogApiFelder::TEXT as $feld => $feldLabel): ?>
                    <?= e($feldLabel) ?>: <?= e(mb_strimwidth((string)($vdh[$feld] ?: '—'), 0, 120, '…')) ?><br>
                <?php endforeach; ?>
            </p>
        </div>
        <div>
            <h4>Von TheDogAPI</h4>
            <?php if ($api['_bild_url'] !== ''): ?>
                <img class="thumb" src="<?= e($api['_bild_url']) ?>" alt="">
            <?php endif; ?>
            <?php foreach (TheDogApiFelder::TEXT as $feld => $feldLabel): $wert = TheDogApiFelder::wert($api, $feld); ?>
            <div class="tdapi-feld">
                <label for="f-<?= $rowId ?>-<?= $feld ?>"><?= e($feldLabel) ?></label>
                <?php if ($feld === 'beschreibung_en'): ?>
                    <textarea id="f-<?= $rowId ?>-<?= $feld ?>" lang="en" rows="3" name="items[<?= $rowId ?>][<?= $feld ?>]"><?= e($wert) ?></textarea>
                <?php else: ?>
                    <input type="text" id="f-<?= $rowId ?>-<?= $feld ?>" lang="en" name="items[<?= $rowId ?>][<?= $feld ?>]" value="<?= e($wert) ?>">
                <?php endif; ?>
                <?php if ($feld === 'temperament_en' && $wert !== ''): $de = Uebersetzung::temperament($wert); ?>
                    <p style="font-size:.75rem;color:#555;margin-top:.15rem;">
                        Deutsch: <?= $de['text'] !== null ? e($de['text']) : 'nicht im Wörterbuch: ' . e(implode(', ', $de['unbekannt'])) ?>
                    </p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="tdapi-checkboxen">
        <?php foreach (TheDogApiFelder::TEXT as $feld => $feldLabel): $hatWert = trim((string) ($vdh[$feld] ?? '')) !== ''; ?>
            <label<?= $hatWert ? ' style="color:#666"' : '' ?>><input type="checkbox" data-feld="<?= $feld ?>" name="items[<?= $rowId ?>][uebernehmen_<?= $feld ?>]" value="1" <?= $hatWert ? 'disabled' : '' ?>>
                <?= e($feldLabel) ?><?= $hatWert ? ' (schon vorhanden, bleibt)' : '' ?></label>
        <?php endforeach; ?>
        <?php if ($api['_bild_url'] !== ''): ?>
        <label<?= $vdh['hat_bild'] ? ' style="color:#666"' : '' ?>><input type="checkbox" data-feld="bild" name="items[<?= $rowId ?>][uebernehmen_bild]" value="1" <?= $vdh['hat_bild'] ? 'disabled' : ($sicher ? 'checked' : '') ?>>
            Bild <?= $vdh['hat_bild'] ? '(Bild vorhanden, bleibt)' : '(noch kein Bild vorhanden)' ?>
        </label>
        <input type="hidden" name="items[<?= $rowId ?>][bild_url]" value="<?= e($api['_bild_url']) ?>">
        <?php endif; ?>
        <?php if ($t['bisher'] !== null): ?>
            <label><input type="checkbox" data-feld="korrigieren" name="items[<?= $rowId ?>][korrigieren]" value="1"> Zuordnung korrigieren (statt <?= e($t['bisher']) ?>)</label>
        <?php else: ?>
            <label><input type="checkbox" data-feld="markieren" name="items[<?= $rowId ?>][markieren]" value="1" <?= $sicher ? 'checked' : '' ?>> Als "abgeglichen" markieren (thedogapi_id + Datum setzen)</label>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<button type="submit" class="tdapi-submit">✅ Ausgewählte übernehmen → Stufe 2</button>

</form>

<script>
(function () {
    var formular = document.getElementById('tdapi-formular');
    var karten = Array.prototype.slice.call(formular.querySelectorAll('.tdapi-card'));
    var anzeige = document.getElementById('tdapi-anzahl');

    function hatAuswahl(karte) {
        return karte.querySelector('input[type=checkbox][data-feld]:checked:not(:disabled)') !== null;
    }
    function zaehlen() {
        anzeige.textContent = karten.filter(hatAuswahl).length;
    }

    formular.querySelectorAll('[data-pauschal]').forEach(function (knopf) {
        knopf.addEventListener('click', function () {
            var feld = knopf.dataset.pauschal, umfang = knopf.dataset.umfang;
            karten.forEach(function (karte) {
                var box = karte.querySelector('input[data-feld="' + feld + '"]');
                if (!box || box.disabled) return;
                if (umfang === 'keine') { box.checked = false; return; }
                // Ohne Wert von TheDogAPI gibt es nichts zu übernehmen.
                var text = karte.querySelector('[name$="[' + feld + ']"]:not([type=checkbox])');
                if (text && text.value.trim() === '') return;
                if (umfang === 'alle' || karte.dataset.sicher === '1') box.checked = true;
            });
            zaehlen();
        });
    });
    formular.addEventListener('change', zaehlen);

    // Nur Rassen mit Häkchen verschicken, gebündelt in einem Feld
    // (sonst greift bei vielen Rassen max_input_vars).
    formular.addEventListener('submit', function (ereignis) {
        var auswahl = {};
        karten.filter(hatAuswahl).forEach(function (karte) {
            var daten = {};
            karte.querySelectorAll('input[name^="items["], textarea[name^="items["]').forEach(function (feld) {
                var schluessel = feld.name.slice(feld.name.lastIndexOf('[') + 1, -1);
                if (feld.type === 'checkbox') {
                    if (feld.checked && !feld.disabled) daten[schluessel] = '1';
                } else {
                    daten[schluessel] = feld.value;
                }
            });
            auswahl[karte.dataset.rasse] = daten;
        });
        if (Object.keys(auswahl).length === 0) {
            ereignis.preventDefault();
            alert('Es ist nichts ausgewählt.');
            return;
        }
        formular.querySelectorAll('input[name^="items["], textarea[name^="items["]').forEach(function (feld) { feld.disabled = true; });
        var json = document.createElement('input');
        json.type = 'hidden';
        json.name = 'items_json';
        json.value = JSON.stringify(auswahl);
        formular.appendChild(json);
    });

    zaehlen();
})();
</script>

<?php if (count($ohneTreffer) > 0): ?>
<h2 class="text-lg font-bold mt-6 mb-2">Ohne automatischen Treffer (<?= count($ohneTreffer) ?>)</h2>
<p style="font-size:.85rem;color:#666;">
    Diese Rassen konnten nicht sicher zugeordnet werden — meist exotischere Rassen ohne
    Eintrag in TheDogAPI, oder Namen, die die Übersetzungstabelle nicht abdeckt.
    Abhilfe: unter „Rasse bearbeiten“ den englischen Namen eintragen, genau wie bei TheDogAPI.
    Dann wird die Rasse beim nächsten Laden sicher zugeordnet. Feste Zuordnungen stehen in <code>models/TheDogApiAbgleich.php</code>.
</p>
<?php foreach ($ohneTreffer as $r): ?>
    <div class="tdapi-unmatched"><?= e($r['name']) ?></div>
<?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
