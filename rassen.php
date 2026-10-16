<?php
/**
 * rassen.php
 *
 * Rassenkatalog: Textsuche und Filter. Die Filter entstehen aus den
 * filterbaren Merkmalen (admin_merkmale.php, models/RassenFilter.php).
 * Jede Option zeigt, wie viele Rassen sie unter den übrigen Filtern
 * trifft. Optionen ohne Treffer und Merkmale ohne Daten fehlen.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Breed.php';
require_once __DIR__ . '/models/RassenFilter.php';
require_once __DIR__ . '/models/RassenDatenSchreiben.php';

secure_session_start();
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_breed_id'])) {
    require_manage_breeds();
    csrf_require_valid();
    Breed::delete((int) $_POST['delete_breed_id'], (int) current_user()['id']);
    header('Location: /rassen.php');
    exit;
}

// Filter brauchen die Merkmale aller Rassen. Direkt nach dem Deploy sind
// noch nicht alle übernommen, das holt der erste Aufruf einmalig nach.
if ((int) Database::getConnection()->query('SELECT COUNT(*) FROM breeds WHERE daten_stand IS NULL')->fetchColumn() > 0) {
    RassenDaten::alleUebernehmen();
}

$searchTerm   = clean_input($_GET['q'] ?? '');
$definitionen = RassenDatenSchreiben::nachSchluessel();
$filter       = RassenFilter::eingabe($_GET, $definitionen);
$daten        = RassenFilter::daten();
$sprache      = aktuelle_sprache();

$textTreffer     = array_column(Breed::search($searchTerm), null, 'id');
$kandidaten      = array_map('intval', array_keys($textTreffer));
$zahlen          = RassenFilter::zaehlen($kandidaten, $daten, $filter, $definitionen);
$breedsGefiltert = array_map(fn($id) => $textTreffer[$id], RassenFilter::anwenden($kandidaten, $daten, $filter, $definitionen));

// Pagination: Suche und Filter liefern das vollständige Ergebnis, hier
// wird nur der Ausschnitt für die aktuelle Seite herausgeschnitten.
const RASSEN_PRO_SEITE = 24;

$gesamtTreffer = count($breedsGefiltert);
$gesamtSeiten  = max(1, (int) ceil($gesamtTreffer / RASSEN_PRO_SEITE));
$aktuelleSeite = max(1, (int) ($_GET['seite'] ?? 1));
$aktuelleSeite = min($aktuelleSeite, $gesamtSeiten);

$breeds = array_slice($breedsGefiltert, ($aktuelleSeite - 1) * RASSEN_PRO_SEITE, RASSEN_PRO_SEITE);

/** Link auf den Katalog mit Suche und Filter. */
function katalog_link(string $q, array $filter, int $seite = 1): string
{
    $m = [];
    foreach ($filter as $schluessel => $f) {
        $m[$schluessel] = isset($f['werte'])
            ? $f['werte']
            : array_map('filter_zahl', array_filter(['von' => $f['von'], 'bis' => $f['bis']], fn($v) => $v !== null));
    }
    $params = array_filter(['q' => $q, 'm' => $m, 'seite' => $seite > 1 ? $seite : null], fn($v) => $v !== '' && $v !== [] && $v !== null);
    return '/rassen.php' . ($params ? '?' . http_build_query($params) : '');
}

function paginationLink(int $zielSeite): string
{
    global $searchTerm, $filter;
    return katalog_link($searchTerm, $filter, $zielSeite);
}

/** 30.0 → "30", 12.5 → "12,5" auf Deutsch */
function filter_zahl(float $z): string
{
    $text = rtrim(rtrim(number_format($z, 1, '.', ''), '0'), '.');
    return aktuelle_sprache() === 'de' ? str_replace('.', ',', $text) : $text;
}

function einheit_name(?string $einheit): string
{
    return match ($einheit) {
        'cm', 'kg' => $einheit,
        'jahre' => t('einheit.jahre'),
        default => '',
    };
}

/**
 * Optionen eines Werte-Merkmals: [wert => Bezeichnung], nur mit Treffern
 * oder gewählt. Feste Reihenfolge, Länder alphabetisch.
 */
function filter_optionen(array $m, array $zahlen, array $gewaehlt): array
{
    $optionen = [];
    if ($m['typ'] === 'land') {
        foreach (array_unique(array_merge(array_map('strval', array_keys($zahlen)), $gewaehlt)) as $code) {
            $optionen[$code] = RassenDaten::landName($code, aktuelle_sprache());
        }
        uasort($optionen, fn($a, $b) => strcoll($a, $b));
    } elseif ($m['typ'] === 'ja_nein') {
        $optionen = ['ja' => t('merkmal.ja'), 'nein' => t('merkmal.nein')];
    } else {
        foreach ($m['werte'] as $schluessel => $w) {
            $optionen[(string) $schluessel] = in_sprache($w['name_de'], $w['name_en']);
        }
    }
    return array_filter(
        $optionen,
        fn($label, $wert) => ($zahlen[$wert] ?? 0) > 0 || in_array((string) $wert, $gewaehlt, true),
        ARRAY_FILTER_USE_BOTH
    );
}

// Filterblöcke in Merkmal-Reihenfolge, nur mit Daten; dazu die aktiven Filter zum Abwählen
$filterBloecke = [];
$aktiveChips = [];
foreach ($definitionen as $schluessel => $m) {
    if (!$m['filterbar']) {
        continue;
    }
    $name = in_sprache($m['name_de'], $m['name_en']);
    $f = $filter[$schluessel] ?? null;
    if ($m['typ'] === 'zahl_spanne') {
        if ($f !== null || RassenFilter::hatDaten($daten, $schluessel)) {
            $filterBloecke[] = ['m' => $m, 'name' => $name, 'f' => $f];
        }
        if ($f !== null) {
            $spanne = match (true) {
                $f['von'] !== null && $f['bis'] !== null => filter_zahl($f['von']) . '–' . filter_zahl($f['bis']),
                $f['von'] !== null => '≥ ' . filter_zahl($f['von']),
                default => '≤ ' . filter_zahl($f['bis']),
            };
            $ohne = $filter;
            unset($ohne[$schluessel]);
            $aktiveChips[] = [$name . ': ' . trim($spanne . ' ' . einheit_name($m['einheit'])), katalog_link($searchTerm, $ohne)];
        }
        continue;
    }
    $gewaehlt = $f['werte'] ?? [];
    $optionen = filter_optionen($m, $zahlen[$schluessel] ?? [], $gewaehlt);
    if ($optionen) {
        $filterBloecke[] = ['m' => $m, 'name' => $name, 'optionen' => $optionen, 'gewaehlt' => $gewaehlt];
    }
    foreach ($gewaehlt as $w) {
        $ohne = $filter;
        $ohne[$schluessel]['werte'] = array_values(array_diff($gewaehlt, [$w]));
        if (!$ohne[$schluessel]['werte']) {
            unset($ohne[$schluessel]);
        }
        $aktiveChips[] = [$name . ': ' . ($optionen[$w] ?? $w), katalog_link($searchTerm, $ohne)];
    }
}

$pageTitle = t('rassen.titel');
require __DIR__ . '/views/partials/header.php';
?>

<div x-data="{ filtersOpen: <?= $filter ? 'true' : 'false' ?> }">

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-extrabold text-fellDk">🐕 <?= e(t('rassen.titel')) ?></h1>
        <?php if (can_manage_breeds()): ?>
            <a href="/admin_add_breed.php" class="bg-pfote hover:bg-fell text-white text-sm font-bold px-4 py-2 rounded-full transition-colors">
                + <?= e(t('rassen.neu')) ?>
            </a>
        <?php endif; ?>
    </div>

    <form method="get" action="/rassen.php" class="bg-white/70 rounded-2xl shadow p-5 border border-sand mb-8">
        <div class="flex flex-col md:flex-row gap-3">
            <input
                type="text" name="q" value="<?= e($searchTerm) ?>"
                placeholder="<?= e(t('rassen.suche_platzhalter')) ?>"
                aria-label="<?= e(t('rassen.suche_platzhalter')) ?>"
                class="flex-1 rounded-lg border border-sand px-4 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"
            >
            <button type="submit" class="bg-tanne hover:bg-tanneDk text-creme font-bold px-6 py-2 rounded-full transition-colors"><?= e(t('rassen.suchen')) ?></button>
            <button type="button" @click="filtersOpen = !filtersOpen" :aria-expanded="filtersOpen.toString()" aria-controls="katalog-filter"
                class="border border-tanne text-tanne hover:bg-tanne hover:text-creme font-bold px-6 py-2 rounded-full transition-colors">
                <?= e(t('rassen.filter')) ?><?= $filter ? ' (' . count($filter) . ')' : '' ?> <span x-text="filtersOpen ? '▲' : '▼'" aria-hidden="true"></span>
            </button>
        </div>

        <div id="katalog-filter" x-show="filtersOpen" x-cloak class="mt-5 pt-5 border-t border-sand grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($filterBloecke as $block):
                $m = $block['m'];
                $feld = 'm[' . $m['schluessel'] . ']';
                $einheit = einheit_name($m['einheit']); ?>
                <fieldset>
                    <legend class="font-bold text-sm mb-2 text-tanneDk">
                        <?= e($block['name']) ?><?= $m['typ'] === 'zahl_spanne' && $einheit !== '' ? ' (' . e($einheit) . ')' : '' ?>
                        <?php if ($m['typ'] === 'mehrfach'): ?>
                            <span class="font-normal text-xs text-fellDk/70"><?= e(t(RassenFilter::verknuepfung($m) === 'oder' ? 'rassen.verknuepfung_oder' : 'rassen.verknuepfung_und')) ?></span>
                        <?php endif; ?>
                    </legend>

                    <?php if ($m['typ'] === 'zahl_spanne'):
                        $von = $block['f']['von'] ?? null;
                        $bis = $block['f']['bis'] ?? null; ?>
                        <div class="flex items-center gap-2">
                            <input type="text" inputmode="decimal" name="<?= e($feld) ?>[von]" value="<?= $von !== null ? e(filter_zahl($von)) : '' ?>"
                                placeholder="<?= e(t('rassen.von')) ?>" aria-label="<?= e($block['name'] . ' ' . t('rassen.von')) ?>"
                                class="w-24 rounded-lg border border-sand px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
                            <span aria-hidden="true">–</span>
                            <input type="text" inputmode="decimal" name="<?= e($feld) ?>[bis]" value="<?= $bis !== null ? e(filter_zahl($bis)) : '' ?>"
                                placeholder="<?= e(t('rassen.bis')) ?>" aria-label="<?= e($block['name'] . ' ' . t('rassen.bis')) ?>"
                                class="w-24 rounded-lg border border-sand px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
                        </div>
                        <p class="mt-1 text-xs text-fellDk/70"><?= e(t('rassen.spanne_hinweis')) ?></p>

                    <?php else:
                        $viele = count($block['optionen']) > 8; ?>
                        <div <?= $viele ? 'x-data="{ suche: \'\' }"' : '' ?>>
                            <?php if ($viele): ?>
                                <input type="search" x-model="suche" placeholder="<?= e(t('rassen.wert_suchen')) ?>"
                                    aria-label="<?= e(t('rassen.wert_suchen_in', ['name' => $block['name']])) ?>"
                                    class="w-full rounded-lg border border-sand px-3 py-1.5 text-sm mb-2 focus:outline-none focus:ring-2 focus:ring-tanne">
                            <?php endif; ?>
                            <div class="space-y-1 <?= $viele ? 'max-h-48 overflow-y-auto pr-2' : '' ?>">
                                <?php foreach ($block['optionen'] as $wert => $label):
                                    $wert = (string) $wert;
                                    $aktiv = in_array($wert, $block['gewaehlt'], true);
                                    $anzahl = $zahlen[$m['schluessel']][$wert] ?? 0; ?>
                                    <label class="flex items-center gap-2 text-sm"
                                        <?= $viele ? 'x-show="suche === \'\' || ' . e(json_encode(mb_strtolower($label))) . '.includes(suche.toLowerCase())"' : '' ?>>
                                        <input type="checkbox" name="<?= e($feld) ?>[]" value="<?= e($wert) ?>" <?= $aktiv ? 'checked' : '' ?>
                                            class="rounded border-sand text-tanne focus:ring-tanne">
                                        <span><?= e($label) ?></span>
                                        <span class="ml-auto text-xs text-fellDk/60 tabular-nums"><?= $aktiv ? '' : '(' . $anzahl . ')' ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </fieldset>
            <?php endforeach; ?>
            <?php if (!$filterBloecke): ?>
                <p class="text-sm text-fellDk/70"><?= e(t('rassen.keine_filter')) ?></p>
            <?php endif; ?>
        </div>

        <div class="mt-4 flex items-center justify-between">
            <p class="text-sm text-fellDk/60" role="status"><?= e(t($gesamtTreffer === 1 ? 'rassen.treffer_1' : 'rassen.treffer_n', ['anzahl' => $gesamtTreffer])) ?></p>
            <div>
                <button type="submit" class="text-sm text-tanne underline hover:text-tanneDk"><?= e(t('rassen.filter_anwenden')) ?></button>
                <a href="/rassen.php" class="text-sm text-fellDk/60 underline hover:text-fellDk ml-4"><?= e(t('rassen.zuruecksetzen')) ?></a>
            </div>
        </div>
    </form>

    <?php if ($aktiveChips): ?>
        <div class="-mt-4 mb-6 flex flex-wrap items-center gap-2">
            <span class="text-sm text-fellDk/70"><?= e(t('rassen.aktive_filter')) ?></span>
            <?php foreach ($aktiveChips as [$label, $link]): ?>
                <a href="<?= e($link) ?>" title="<?= e(t('rassen.filter_entfernen', ['name' => $label])) ?>"
                    class="inline-flex items-center gap-1.5 text-sm bg-tanne/10 text-tanneDk border border-tanne/30 rounded-full px-3 py-1 hover:bg-tanne/20">
                    <?= e($label) ?> <span aria-hidden="true">✕</span><span class="sr-only"><?= e(t('rassen.entfernen')) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($breeds)): ?>
        <div class="text-center py-16 text-fellDk/60">
            <span class="text-5xl block mb-3" aria-hidden="true">🦴</span>
            <p><?= e(t('rassen.leer')) ?></p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
            <?php foreach ($breeds as $breed):
                $anzeigeName = in_sprache($breed['name'], $breed['name_en'] ?? null);
                $landCode = array_key_first($daten[(int) $breed['id']]['ursprungsland']['werte'] ?? []); ?>
                <div class="bg-white/80 rounded-2xl shadow border border-sand p-5 flex flex-col">
                    <a href="/breed_detail.php?id=<?= (int) $breed['id'] ?>" class="block group">
                        <?php if (!empty($breed['hat_bild'])): ?>
                            <img
                                src="/bildausgabe.php?id=<?= (int) $breed['id'] ?>&v=<?= urlencode((string) ($breed['bild_updated_at'] ?? '')) ?>"
                                alt="<?= e($anzeigeName) ?>"
                                class="w-full h-36 object-cover rounded-xl border border-sand mb-3 group-hover:opacity-90 transition-opacity"
                            >
                        <?php else: ?>
                            <div class="w-full h-36 rounded-xl border border-sand bg-creme flex items-center justify-center mb-3 group-hover:bg-sand/30 transition-colors" aria-hidden="true">
                                <span class="text-3xl text-fellDk/20">🐾</span>
                            </div>
                        <?php endif; ?>
                    </a>

                    <h2 class="text-lg font-extrabold text-fellDk mb-1"><?= e($anzeigeName) ?></h2>
                    <p class="text-sm text-fellDk/70 mb-3">
                        <?= $landCode !== null ? e(RassenDaten::landName((string) $landCode, $sprache)) : e(t('rassen.ursprung_unbekannt')) ?>
                        <?php if ($breed['fci_klasse']): ?>
                            &middot; <?= e(t('rassen.fci_klasse', ['nr' => (int) $breed['fci_klasse']])) ?>
                        <?php endif; ?>
                        <?php if (!empty($breed['fci_nr'])): ?>
                            &middot; <?= e(t('rassen.nr', ['nr' => $breed['fci_nr']])) ?>
                        <?php endif; ?>
                    </p>

                    <div class="mt-auto pt-3 flex gap-2">
                        <a href="/breed_detail.php?id=<?= (int) $breed['id'] ?>"
                            class="flex-1 text-center text-sm bg-tanne/10 text-tanneDk font-semibold py-2 rounded-full hover:bg-tanne/20 transition-colors">
                            <?= e(t('rassen.details')) ?>
                        </a>
                        <?php if (can_manage_breeds()): ?>
                            <a href="/admin_add_breed.php?id=<?= (int) $breed['id'] ?>"
                                class="flex-1 text-center text-sm bg-pfote/10 text-fell font-semibold py-2 rounded-full hover:bg-pfote/20 transition-colors">
                                <?= e(t('rassen.bearbeiten')) ?>
                            </a>
                            <form method="post" action="/rassen.php"
                                onsubmit="return confirm(<?= e(json_encode(t('rassen.loeschen_frage', ['name' => $anzeigeName]), JSON_UNESCAPED_UNICODE)) ?>);"
                                class="flex-1">
                                <?= csrf_field() ?>
                                <input type="hidden" name="delete_breed_id" value="<?= (int) $breed['id'] ?>">
                                <button type="submit"
                                    class="w-full text-sm bg-red-50 text-red-600 font-semibold py-2 rounded-full hover:bg-red-100 transition-colors">
                                    <?= e(t('rassen.loeschen')) ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($gesamtSeiten > 1): ?>
        <nav class="flex items-center justify-center gap-2 mt-8" aria-label="<?= e(t('rassen.seiten')) ?>">
            <?php if ($aktuelleSeite > 1): ?>
                <a href="<?= e(paginationLink($aktuelleSeite - 1)) ?>"
                    class="px-3 py-1.5 rounded-full text-sm border border-sand text-tanneDk hover:bg-tanne hover:text-creme transition-colors">
                    ← <?= e(t('rassen.zurueck')) ?>
                </a>
            <?php endif; ?>

            <span class="text-sm text-fellDk/60 px-2">
                <?= e(t('rassen.seite_von', ['seite' => $aktuelleSeite, 'gesamt' => $gesamtSeiten])) ?>
            </span>

            <?php if ($aktuelleSeite < $gesamtSeiten): ?>
                <a href="<?= e(paginationLink($aktuelleSeite + 1)) ?>"
                    class="px-3 py-1.5 rounded-full text-sm border border-sand text-tanneDk hover:bg-tanne hover:text-creme transition-colors">
                    <?= e(t('rassen.weiter')) ?> →
                </a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
