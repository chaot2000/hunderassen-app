<?php
/**
 * breed_detail.php
 *
 * Detailansicht einer Rasse, für alle eingeloggten Benutzer.
 *
 * Gestaltung "Bildbetonte Kachel" (Redesign 2026-10, siehe PRODUCT.md):
 * Kopfmodul aus Tanne-Block (Name, Kurzporträt) und Foto- bzw.
 * Signetfläche, darüber geschobene Farbbalken mit der FCI-Einordnung.
 * Maße als Balken auf einer festen Skala, Textabschnitte mit festen
 * Farbblöcken je Abschnitt (views/partials/rassentext.php). Fehlende
 * Daten werden benannt, nicht versteckt.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Breed.php';
require_once __DIR__ . '/models/ContentRevision.php';
require_once __DIR__ . '/models/RassentextGliederung.php';
require_once __DIR__ . '/models/RassenDaten.php';
require_once __DIR__ . '/views/partials/rassentext.php';

secure_session_start();
require_login();

$breedId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$breed   = $breedId ? Breed::findById($breedId) : null;

if ($breed === null) {
    http_response_code(404);
    $pageTitle = t('rasse.nicht_gefunden_titel');
    require __DIR__ . '/views/partials/header.php';
    echo '<div class="text-center py-16 text-fellDk"><p>' . e(t('rasse.nicht_gefunden')) . '</p>
          <a href="/rassen.php" class="text-tanne font-semibold underline">' . e(t('rasse.zurueck_rassen')) . '</a></div>';
    require __DIR__ . '/views/partials/footer.php';
    exit;
}

$similarBreeds = Breed::getSimilar($breedId, 6);
$darfPflegen   = can_manage_breeds();
$namen         = Breed::namensteile((string) $breed['name']);
$gruppe        = $breed['fci_klasse'] !== null ? (int) $breed['fci_klasse'] : null;
$englisch      = aktuelle_sprache() === 'en';

// Auf Englisch: englischer Name als Überschrift, der Originalname darunter.
if ($englisch && trim((string) ($breed['name_en'] ?? '')) !== '') {
    $original = (string) $breed['name'];
    $namen = ['haupt' => (string) $breed['name_en'], 'zweit' => $original !== $breed['name_en'] ? $original : null];
}

// Merkmale und Texte aus dem neuen Modell (models/RassenDaten.php). Hat
// sich die Rasse seit der letzten Übernahme geändert, wird vorher neu
// übernommen.
RassenDaten::uebernehmenWennNoetig($breedId);
$sprache     = aktuelle_sprache();
$texte       = RassenDaten::texte($breedId, $sprache);
$merkmale    = RassenDaten::merkmale($breedId, $sprache);
$merkmalNach = [];
foreach ($merkmale as $liste) {
    foreach ($liste as $m) {
        $merkmalNach[$m['schluessel']] = $m;
    }
}

// Abschnitte für views/partials/rassentext.php. Jeder Abschnitt kommt in
// der gewählten Sprache, sonst in der anderen (gekennzeichnet).
$absaetze = fn(string $t): array => array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', str_replace("\r", '', $t)))));
$text = [
    'kurzportraet' => $texte['kurzportraet']['text'] ?? null,
    'einleitung'   => isset($texte['allgemein']) ? $absaetze($texte['allgemein']['text']) : [],
    'abschnitte'   => [],
];
$kurzSprache = $texte['kurzportraet']['sprache'] ?? $sprache;
foreach (RassenDaten::ABSCHNITTE as $abschnitt => $titel) {
    if ($titel === null || !isset($texte[$abschnitt])) {
        continue;
    }
    $text['abschnitte'][] = ['titel' => $titel, 'absaetze' => $absaetze($texte[$abschnitt]['text']), 'sprache' => $texte[$abschnitt]['sprache']];
}
if (isset($texte['weitere'])) {
    foreach (RassentextGliederung::fuerAnzeige($texte['weitere']['text'])['abschnitte'] as $a) {
        $text['abschnitte'][] = $a + ['sprache' => $texte['weitere']['sprache']];
    }
}
$textSprachen = array_values(array_unique(array_column($texte, 'sprache')));
$textSprache  = in_array($sprache, $textSprachen, true) || $textSprachen === [] ? $sprache : $textSprachen[0];
$textFremd    = $textSprachen !== [] && !in_array($sprache, $textSprachen, true);
$englisch     = $sprache === 'en';
// Quelle nur nennen, wenn ein gezeigter englischer Abschnitt unverändert aus
// TheDogAPI stammt. Selbst geschrieben oder nachbearbeitet: Quelle 'hand'.
$textAusTheDogApi = false;
$textUngeprueft   = false;
foreach ($texte as $t) {
    $textAusTheDogApi = $textAusTheDogApi || ($t['sprache'] === 'en' && $t['quelle'] === 'thedogapi');
    // Automatisch gegliederter VDH-Text, noch nicht von Hand bearbeitet
    $textUngeprueft = $textUngeprueft || ($t['sprache'] === 'de' && $t['quelle'] === 'alt' && ($breed['gliederung'] ?? '') === 'automatisch');
}
$versionen     = $darfPflegen ? ContentRevision::zaehle('breeds', $breedId, null) : 0;

/** "46–58 cm", "ab 46 cm", "bis 58 cm" oder null; Dezimalzeichen nach Sprache */
function format_spanne(?float $min, ?float $max, string $einheit): ?string
{
    $komma = aktuelle_sprache() === 'en' ? '.' : ',';
    $fmt = static fn(float $v): string => rtrim(rtrim(number_format($v, 1, $komma, ''), '0'), $komma);
    if ($min !== null && $max !== null) {
        return ($min === $max ? $fmt($min) : $fmt($min) . '–' . $fmt($max)) . ' ' . $einheit;
    }
    if ($min !== null) {
        return t('rasse.ab', ['wert' => $fmt($min) . ' ' . $einheit]);
    }
    if ($max !== null) {
        return t('rasse.bis', ['wert' => $fmt($max) . ' ' . $einheit]);
    }
    return null;
}

/**
 * Balken für eine Spanne auf einer Skala 0..$skala. Nur Darstellung,
 * die Werte stehen immer auch als Text daneben.
 */
function render_spannenbalken(?float $min, ?float $max, float $skala, string $farbe): string
{
    if ($min === null && $max === null) {
        return '<div class="h-3 bg-sand/60 rounded-sm" aria-hidden="true"></div>';
    }
    $von = max(0.0, min(100.0, (($min ?? $max) / $skala) * 100));
    $bis = max(0.0, min(100.0, (($max ?? $min) / $skala) * 100));
    $breite = max(1.5, $bis - $von);
    return '<div class="relative h-3 bg-sand/60 rounded-sm" aria-hidden="true">'
        . render_skalenmarken($skala)
        . '<div class="absolute inset-y-0 ' . $farbe . ' rounded-sm" style="left:' . round($von, 2) . '%;width:' . round($breite, 2) . '%;min-width:6px"></div>'
        . '</div>';
}

/** Feine Striche alle 30 Einheiten, wie ein Lineal unter dem Balken. */
function render_skalenmarken(float $skala): string
{
    $html = '';
    for ($w = 30; $w < $skala; $w += 30) {
        $html .= '<span class="absolute inset-y-0 w-px bg-fellDk/30" style="left:' . round($w / $skala * 100, 2) . '%"></span>';
    }
    return $html;
}

/** Beschriftung der Skala unter den Balken (0, 30, 60, 90 …). */
function render_skalenzeile(float $skala, string $einheit): string
{
    $html = '<div class="grid grid-cols-[7.5rem_1fr] sm:grid-cols-[8.5rem_1fr] gap-3 mt-1" aria-hidden="true"><span></span><div class="relative h-4 font-mono text-xs text-fellDk/80">';
    for ($w = 0; $w <= $skala; $w += 30) {
        $pos = round($w / $skala * 100, 2);
        if ($w + 30 > $skala) {
            // Letzte Marke rechtsbündig mit Einheit, damit nichts über den Rand ragt
            $html .= '<span class="absolute right-0">' . $w . ' ' . e($einheit) . '</span>';
        } elseif ($w === 0) {
            $html .= '<span class="absolute left-0">0</span>';
        } else {
            $html .= '<span class="absolute -translate-x-1/2" style="left:' . $pos . '%">' . $w . '</span>';
        }
    }
    return $html . '</div></div>';
}

// Skalen: feste Obergrenzen, damit Rassen untereinander vergleichbar
// bleiben; nur bei Ausreißern wächst die Skala mit.
$maxCm = 90.0;
$maxKg = 90.0;
foreach (['schulterhoehe' => 'maxCm', 'gewicht' => 'maxKg'] as $schl => $var) {
    foreach ($merkmalNach[$schl]['spannen'] ?? [] as [$von, $bis]) {
        $$var = max($$var, (float) $von, (float) $bis);
    }
}

// Maße für die Balken: Rüde, Hündin oder für beide gemeinsam
$masse = [];
foreach (['schulterhoehe' => ['rasse.schulterhoehe', 'cm', $maxCm], 'gewicht' => ['rasse.gewicht', 'kg', $maxKg]] as $schl => [$label, $einheit, $skala]) {
    $zeilen = [];
    foreach (['ruede' => ['rasse.ruede', 'bg-tanne'], 'huendin' => ['rasse.huendin', 'bg-fell'], 'alle' => [null, 'bg-tanne']] as $g => [$gLabel, $farbe]) {
        if (isset($merkmalNach[$schl]['spannen'][$g])) {
            [$von, $bis] = $merkmalNach[$schl]['spannen'][$g];
            $zeilen[] = [$gLabel !== null ? t($gLabel) : '', $von, $bis, $farbe];
        }
    }
    if ($zeilen) {
        $masse[] = ['name' => t($label), 'einheit' => $einheit, 'skala' => $skala, 'zeilen' => $zeilen];
    }
}

/** Anzeigewert eines Merkmals: Werte, Spanne mit Einheit oder Land. */
function merkmal_anzeige(array $m): string
{
    if ($m['werte']) {
        return implode(', ', $m['werte']);
    }
    if ($m['land'] !== null) {
        return $m['land'];
    }
    $teile = [];
    foreach ($m['spannen'] as [$von, $bis]) {
        $spanne = format_spanne($von, $bis, $m['einheit'] === 'jahre' ? '' : (string) $m['einheit']);
        if ($spanne !== null) {
            $teile[] = $m['einheit'] === 'jahre' ? t('rasse.jahre', ['wert' => trim($spanne)]) : $spanne;
        }
    }
    return implode(' · ', $teile);
}

// Was schon in der Größen-Spalte oder im Kopf steht, nicht noch einmal
$imSteckbrief = array_diff_key($merkmalNach, array_flip(['schulterhoehe', 'gewicht', 'groessenklasse', 'arbeitspruefung']));

$pageTitle = $namen['haupt'];
require __DIR__ . '/views/partials/header.php';
?>

<style>
    /* Ein Auftritt: die Balken schieben sich beim Laden von links ins
       Modul. Inhalt ist von Anfang an sichtbar (clip-path, kein opacity). */
    @keyframes balken-ein { from { clip-path: inset(0 35% 0 0); } to { clip-path: inset(0 0 0 0); } }
    .balken { animation: balken-ein 650ms cubic-bezier(0.16, 1, 0.3, 1) both; }
    .balken:nth-child(2) { animation-delay: 90ms; }
    .balken:nth-child(3) { animation-delay: 180ms; }
    @media (prefers-reduced-motion: reduce) { .balken { animation: none; } }
</style>

<article aria-labelledby="rasse-name">

    <!-- Kopfmodul -->
    <header class="grid grid-cols-1 lg:grid-cols-12">
        <div class="lg:col-span-7 bg-tanne text-creme px-5 sm:px-10 pt-6 pb-8 lg:pb-32 rounded-t-lg lg:rounded-tr-none lg:rounded-l-lg">
            <a href="/rassen.php" class="inline-flex items-center gap-1.5 min-h-[44px] py-2 text-sm font-semibold text-creme underline-offset-4 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-pfote rounded-sm">
                <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M17 10a.75.75 0 01-.75.75H5.612l4.158 3.96a.75.75 0 11-1.04 1.08l-5.5-5.25a.75.75 0 010-1.08l5.5-5.25a.75.75 0 111.04 1.08L5.612 9.25H16.25A.75.75 0 0117 10z" clip-rule="evenodd"/></svg>
                <?= e(t('rasse.alle_rassen')) ?>
            </a>

            <h1 id="rasse-name" class="mt-6 text-4xl sm:text-6xl font-black tracking-tight leading-[0.95] break-words" style="text-wrap: balance">
                <?= e($namen['haupt']) ?>
            </h1>
            <?php if ($namen['zweit'] !== null): ?>
                <p class="mt-3 text-lg sm:text-xl font-bold text-creme">
                    <?= e($namen['zweit']) ?>
                    <?php if (str_ends_with($namen['zweit'], '…')): ?>
                        <span class="block text-sm font-semibold text-creme/90 mt-1"><?= e(t('rasse.name_gekuerzt')) ?></span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <?php if (!empty($breed['alternative_namen']) || !empty($breed['namenszusatz'])): ?>
                <p class="mt-2 text-sm text-creme/90">
                    <?= e(trim(($breed['namenszusatz'] ?? '') . ' ' . (!empty($breed['alternative_namen']) ? t('rasse.auch', ['namen' => $breed['alternative_namen']]) : ''))) ?>
                </p>
            <?php endif; ?>

            <?php if ($text['kurzportraet'] !== null): ?>
                <?php $lang = mb_strlen($text['kurzportraet']) > 160; ?>
                <div <?= $lang ? 'x-data="{ ganz: false }"' : '' ?> lang="<?= $kurzSprache ?>">
                    <!-- Am Handy optisch gekürzt, damit die Aktion im ersten Bild bleibt; der volle Text steht im DOM. -->
                    <p class="mt-5 sm:mt-6 text-base sm:text-lg leading-relaxed max-w-[60ch] <?= $lang ? 'line-clamp-3 sm:line-clamp-none' : '' ?>" <?= $lang ? ':class="ganz && \'line-clamp-none\'"' : '' ?>><?= e($text['kurzportraet']) ?></p>
                    <?php if ($lang): ?>
                        <button type="button" x-show="!ganz" @click="ganz = true" class="sm:hidden mt-2 min-h-[44px] py-2 text-sm font-bold text-creme underline underline-offset-4"><?= e(t('rasse.weiterlesen')) ?></button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($breed['hat_bild'])): ?>
            <div class="lg:col-span-5 relative min-h-[220px] sm:min-h-[300px] bg-sand overflow-hidden lg:rounded-r-lg">
                <img src="/bildausgabe.php?id=<?= (int) $breed['id'] ?>&v=<?= urlencode((string) ($breed['bild_updated_at'] ?? '')) ?>"
                     alt="<?= e($namen['haupt']) ?>"
                     class="absolute inset-0 w-full h-full object-cover object-[50%_25%]">
            </div>
        <?php else: ?>
            <div class="lg:col-span-5 relative bg-tanneDk overflow-hidden lg:min-h-[300px] lg:rounded-r-lg">
                <!-- Schmal (unter lg): eine Zeile mit kleinem Signet, damit die Aktion im ersten Bild bleibt -->
                <div class="lg:hidden flex items-center gap-3 px-5 min-h-[56px]">
                    <?= render_dog_icon(32, '#B5502E', '#E4DEC8') ?>
                    <span class="text-sm font-semibold text-creme"><?= e(t('rasse.kein_foto')) ?></span>
                    <?php if ($darfPflegen): ?>
                        <a href="/admin_add_breed.php?id=<?= (int) $breed['id'] ?>" class="ml-auto text-sm font-bold text-creme underline underline-offset-4 min-h-[44px] py-2 inline-flex items-center"><?= e(t('rasse.foto_hinzufuegen')) ?></a>
                    <?php endif; ?>
                </div>
                <!-- Breit: gesetzte Farbfläche mit großer Gruppennummer und Signet -->
                <div class="hidden lg:block absolute inset-0">
                    <span class="absolute -top-6 right-2 font-mono font-medium text-[12rem] leading-none text-creme/15 select-none" aria-hidden="true"><?= $gruppe !== null ? $gruppe : '' ?></span>
                    <div class="absolute inset-0 flex items-center justify-center pb-24"><?= render_dog_icon(200, '#B5502E', '#E4DEC8') ?></div>
                    <p class="sr-only"><?= e(t('rasse.kein_foto')) ?>.</p>
                    <?php if ($darfPflegen): ?>
                        <a href="/admin_add_breed.php?id=<?= (int) $breed['id'] ?>" class="absolute top-3 right-4 text-sm font-bold text-creme underline underline-offset-4 min-h-[44px] py-2 inline-flex items-center"><?= e(t('rasse.foto_hinzufuegen')) ?></a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- FCI-Balken, über die Kante des Kopfmoduls geschoben (schmal ohne Foto: direkt darunter) -->
        <div class="lg:col-span-12 <?= !empty($breed['hat_bild']) ? '-mt-16' : 'mt-0' ?> lg:-mt-24 relative z-10 flex flex-col">
            <p class="balken self-start w-full lg:w-[78%] bg-fell text-creme px-5 sm:px-10 py-4 text-lg sm:text-xl font-black leading-snug">
                <?php if ($gruppe !== null): ?>
                    <span class="mr-2">FCI <span class="font-mono font-medium"><?= $gruppe ?></span></span>
                    <?= e(Breed::fciGruppenName($gruppe) ?? t('rasse.gruppe', ['nr' => $gruppe])) ?>
                <?php else: ?>
                    <?= e(t('rasse.fci_fehlt')) ?>
                <?php endif; ?>
            </p>
            <?php if (!empty($breed['fci_sektion']) || !empty($breed['fci_sektion_name'])): ?>
                <p class="balken self-end w-[92%] lg:w-[56%] lg:mr-[22%] bg-pfote text-fellDk px-5 sm:px-8 py-3 text-base sm:text-lg font-bold">
                    <?php if (!empty($breed['fci_sektion'])): ?><span class="mr-2"><?= e(t('rasse.sektion')) ?> <span class="font-mono font-medium"><?= e($breed['fci_sektion']) ?></span></span><?php endif; ?>
                    <?= e($breed['fci_sektion_name'] ?? '') ?>
                </p>
            <?php endif; ?>
            <p class="balken self-start w-[86%] lg:w-[48%] lg:ml-[8%] bg-fellDk text-creme px-5 sm:px-8 py-3 text-sm sm:text-base font-bold flex flex-wrap gap-x-6 gap-y-1">
                <span><?= e(!empty($merkmalNach['arbeitspruefung']) ? merkmal_anzeige($merkmalNach['arbeitspruefung']) : t('rasse.ap_keine')) ?></span>
                <span><?= e(t('rasse.standard')) ?> <?= !empty($breed['fci_nr']) ? e(t('rasse.nr_kurz')) . ' <span class="font-mono font-medium">' . e($breed['fci_nr']) . '</span>' : e(t('rasse.ohne_nr')) ?></span>
            </p>
        </div>
    </header>

    <!-- Aktionen -->
    <div class="mt-4 sm:mt-6 flex flex-wrap items-center gap-3">
        <a href="/dog_form.php?rasse=<?= (int) $breed['id'] ?>"
           class="inline-flex items-center min-h-[44px] bg-tanne hover:bg-tanneDk text-creme font-bold text-sm px-5 py-2 rounded-lg transition-colors focus-visible:outline focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-pfote">
            <?= e(t('rasse.hund_anlegen')) ?>
        </a>
        <?php if ($darfPflegen): ?>
            <a href="/admin_add_breed.php?id=<?= (int) $breed['id'] ?>"
               class="inline-flex items-center min-h-[44px] border-2 border-fellDk text-fellDk hover:bg-fellDk hover:text-creme font-bold text-sm px-5 py-2 rounded-lg transition-colors">
                <?= e(t('rassen.bearbeiten')) ?>
            </a>
            <a href="/content_versionen.php?typ=rasse&amp;id=<?= (int) $breed['id'] ?>"
               class="inline-flex items-center min-h-[44px] text-fellDk font-semibold text-sm px-2 py-2 underline underline-offset-4 hover:text-tanneDk">
                <?= e(t('rasse.versionen', ['anzahl' => $versionen])) ?>
            </a>
        <?php endif; ?>
    </div>

    <!-- Steckbrief: Maße und Merkmale -->
    <div class="mt-10 grid grid-cols-1 lg:grid-cols-12 gap-10">
        <section aria-labelledby="masse-titel" class="lg:col-span-5 border-t-4 border-fellDk pt-4">
            <h2 id="masse-titel" class="text-xl font-black text-fellDk"><?= e(t('rasse.masse_titel')) ?></h2>
            <?php if (!$masse): ?>
                <p class="mt-3 text-sm text-fellDk/80"><?= e(t('rasse.keine_masse')) ?></p>
            <?php else: ?>
                <p class="mt-1 text-xs text-fellDk/80">
                    <?= e(t('rasse.skala')) ?>
                    <span class="inline-flex items-center gap-1 ml-2"><span class="inline-block w-3 h-3 bg-tanne rounded-sm" aria-hidden="true"></span><?= e(t('rasse.ruede')) ?></span>
                    <span class="inline-flex items-center gap-1 ml-2"><span class="inline-block w-3 h-3 bg-fell rounded-sm" aria-hidden="true"></span><?= e(t('rasse.huendin')) ?></span>
                </p>
            <?php endif; ?>
            <?php if (!empty($merkmalNach['groessenklasse'])): ?>
                <p class="mt-4 text-sm font-bold text-fellDk"><?= e(t('rasse.groessenklasse', ['klasse' => merkmal_anzeige($merkmalNach['groessenklasse'])])) ?></p>
            <?php endif; ?>
            <?php foreach ($masse as $mass): ?>
                <div class="mt-4 mb-2">
                    <dl class="space-y-3">
                        <?php foreach ($mass['zeilen'] as [$geschlecht, $min, $max, $farbe]): ?>
                            <div class="grid grid-cols-[7.5rem_1fr] sm:grid-cols-[8.5rem_1fr] items-center gap-3">
                                <dt class="text-sm text-fellDk">
                                    <?= e(trim($mass['name'] . ' ' . $geschlecht)) ?>
                                    <span class="block font-mono text-sm font-medium"><?= e(format_spanne($min, $max, $mass['einheit']) ?? '–') ?></span>
                                </dt>
                                <dd><?= render_spannenbalken($min, $max, $mass['skala'], $farbe) ?></dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                    <?= render_skalenzeile($mass['skala'], $mass['einheit']) ?>
                </div>
            <?php endforeach; ?>
        </section>

        <section aria-labelledby="merkmale-titel" class="lg:col-span-7 border-t-4 border-fellDk pt-4">
            <h2 id="merkmale-titel" class="text-xl font-black text-fellDk"><?= e(t('rasse.merkmale')) ?></h2>
            <?php if (!$imSteckbrief): ?>
                <p class="mt-3 text-sm text-fellDk/80"><?= e(t('rasse.keine_merkmale')) ?></p>
            <?php endif; ?>
            <dl class="mt-3 divide-y divide-sand">
                <?php foreach ($imSteckbrief as $m): ?>
                    <div class="py-3 grid grid-cols-1 sm:grid-cols-[10rem_1fr] gap-1 sm:gap-4">
                        <dt class="text-sm font-bold text-fellDk"><?= e($m['name']) ?></dt>
                        <?php if (in_array($m['typ'], ['mehrfach'], true) && count($m['werte']) > 1): ?>
                            <dd>
                                <ul class="flex flex-wrap gap-2">
                                    <?php foreach ($m['werte'] as $w): ?>
                                        <li class="bg-sand text-fellDk text-sm font-semibold px-3 py-1 rounded-sm"><?= e($w) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </dd>
                        <?php else: ?>
                            <dd class="text-sm text-fellDk"><?= e(merkmal_anzeige($m)) ?></dd>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </dl>
        </section>
    </div>

    <!-- Text -->
    <div class="mt-14">
        <?php if ($text['kurzportraet'] === null && empty($text['abschnitte'])): ?>
            <div class="border-t-4 border-fellDk pt-4">
                <h2 class="text-xl font-black text-fellDk"><?= e(t('rasse.beschreibung')) ?></h2>
                <p class="mt-2 text-fellDk"><?= e(t('rasse.keine_beschreibung')) ?></p>
                <?php if ($darfPflegen): ?>
                    <a href="/admin_add_breed.php?id=<?= (int) $breed['id'] ?>#beschreibung" class="mt-3 inline-flex items-center min-h-[44px] py-2 font-bold text-tanneDk underline underline-offset-4"><?= e(t('rasse.beschreibung_schreiben')) ?></a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php if ($textFremd): ?>
                <p class="text-sm font-semibold text-fellDk mb-3"><?= e(t('rasse.text_nur_deutsch')) ?></p>
            <?php endif; ?>
            <?php if ($textSprache === 'de' && $textUngeprueft && !empty($text['abschnitte'])): ?>
                <p class="text-sm text-fellDk mb-3"><?= e(t('rasse.ungeprueft')) ?></p>
            <?php endif; ?>
            <?= render_rassentext_sprungleiste($text['abschnitte']) ?>
            <?= render_rassentext_abschnitte($text) ?>
        <?php endif; ?>

        <?php if ($textSprache === 'en'): ?>
            <?php if ($textAusTheDogApi): ?>
            <footer class="lg:grid lg:grid-cols-12 lg:gap-10 mt-4">
                <p class="lg:col-start-5 lg:col-span-8 max-w-[60ch] border-t border-sand pt-4 text-sm text-fellDk"><?= e(t('rasse.text_thedogapi')) ?></p>
            </footer>
            <?php endif; ?>
        <?php elseif ($text['kurzportraet'] !== null && ($textUngeprueft || !empty($breed['quelle_url']))): ?>
            <footer class="lg:grid lg:grid-cols-12 lg:gap-10 mt-4">
                <div class="lg:col-start-5 lg:col-span-8 max-w-[60ch] border-t border-sand pt-4 space-y-1 text-sm text-fellDk">
                    <?php if (!empty($breed['quelle_url'])): ?>
                        <p>
                            <?= e(t('rasse.quelle_vdh')) ?><?= !empty($breed['quelle_stand']) ? e(t('rasse.abgerufen', ['datum' => date('d.m.Y', strtotime((string) $breed['quelle_stand']))])) : '' ?>.
                            <a href="<?= e($breed['quelle_url']) ?>" target="_blank" rel="noopener noreferrer" class="inline-block py-1 font-semibold text-tanneDk underline underline-offset-4"><?= e(t('rasse.original_vdh')) ?></a>
                        </p>
                    <?php endif; ?>
                    <?php if ($textUngeprueft): ?>
                        <p><?= e(t('rasse.ungeprueft')) ?></p>
                    <?php endif; ?>
                </div>
            </footer>
        <?php endif; ?>
    </div>

    <?php if (!empty($similarBreeds)): ?>
        <section aria-labelledby="aehnlich-titel" class="mt-14 border-t-4 border-fellDk pt-4">
            <h2 id="aehnlich-titel" class="text-xl font-black text-fellDk"><?= e(t('rasse.aehnlich')) ?></h2>
            <ul class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-8">
                <?php foreach ($similarBreeds as $item): $sb = $item['breed']; $sbNamen = Breed::namensteile(in_sprache($sb['name'], $sb['name_en'] ?? null)); ?>
                    <li class="border-b border-sand">
                        <a href="/breed_detail.php?id=<?= (int) $sb['id'] ?>" class="group flex items-center gap-3 py-3 min-h-[44px]">
                            <span class="shrink-0 w-11 h-11 flex items-center justify-center bg-fell text-creme font-mono font-medium text-sm rounded-sm" aria-hidden="true">
                                <?= $sb['fci_klasse'] !== null ? (int) $sb['fci_klasse'] : '–' ?>
                            </span>
                            <span>
                                <span class="block font-bold text-fellDk group-hover:text-tanneDk group-hover:underline underline-offset-4"><?= e($sbNamen['haupt']) ?></span>
                                <span class="block text-sm text-fellDk/80"><?= e(implode(', ', $item['gruende'])) ?></span>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
</article>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
