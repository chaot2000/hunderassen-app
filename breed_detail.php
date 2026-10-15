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
require_once __DIR__ . '/views/partials/rassentext.php';

secure_session_start();
require_login();

$breedId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$breed   = $breedId ? Breed::findById($breedId) : null;

if ($breed === null) {
    http_response_code(404);
    $pageTitle = 'Nicht gefunden';
    require __DIR__ . '/views/partials/header.php';
    echo '<div class="text-center py-16 text-fellDk"><p>Diese Rasse wurde nicht gefunden.</p>
          <a href="/rassen.php" class="text-tanne font-semibold underline">Zurück zu den Rassen</a></div>';
    require __DIR__ . '/views/partials/footer.php';
    exit;
}

$similarBreeds = Breed::getSimilar($breedId, 6);
$darfPflegen   = can_manage_breeds();
$namen         = Breed::namensteile((string) $breed['name']);
$gruppe        = $breed['fci_klasse'] !== null ? (int) $breed['fci_klasse'] : null;
$text          = RassentextGliederung::fuerAnzeige($breed['beschreibung']);
$versionen     = $darfPflegen ? ContentRevision::zaehle('breeds', $breedId, null) : 0;

$groessenLabels = [
    'klein'      => 'Klein',
    'mittel'     => 'Mittel',
    'gross'      => 'Groß',
    'sehr_gross' => 'Sehr groß',
];
$arbeitspruefungLabels = [
    'mit'        => 'mit Arbeitsprüfung',
    'ohne'       => 'ohne Arbeitsprüfung',
    'fakultativ' => 'Arbeitsprüfung fakultativ',
];

/** "46–58 cm", "ab 46 cm", "bis 58 cm" oder null */
function format_spanne(?float $min, ?float $max, string $einheit): ?string
{
    $fmt = static fn(float $v): string => rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');
    if ($min !== null && $max !== null) {
        return $fmt($min) . '–' . $fmt($max) . ' ' . $einheit;
    }
    if ($min !== null) {
        return 'ab ' . $fmt($min) . ' ' . $einheit;
    }
    if ($max !== null) {
        return 'bis ' . $fmt($max) . ' ' . $einheit;
    }
    return null;
}

function als_zahl($wert): ?float
{
    return $wert !== null && $wert !== '' ? (float) $wert : null;
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
        . '<div class="absolute inset-y-0 ' . $farbe . ' rounded-sm" style="left:' . round($von, 2) . '%;width:' . round($breite, 2) . '%"></div>'
        . '</div>';
}

// Skalen: feste Obergrenzen, damit Rassen untereinander vergleichbar
// bleiben; nur bei Ausreißern wächst die Skala mit.
$maxCm = 90.0;
$maxKg = 90.0;
foreach ($breed['sizes'] as $s) {
    foreach (['schulterhoehe_ruede_max_cm', 'schulterhoehe_haendin_max_cm', 'schulterhoehe_ruede_min_cm', 'schulterhoehe_haendin_min_cm'] as $k) {
        $maxCm = max($maxCm, (float) ($s[$k] ?? 0));
    }
    foreach (['gewicht_ruede_max_kg', 'gewicht_haendin_max_kg', 'gewicht_ruede_min_kg', 'gewicht_haendin_min_kg'] as $k) {
        $maxKg = max($maxKg, (float) ($s[$k] ?? 0));
    }
}

$pageTitle = $breed['name'];
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
        <div class="lg:col-span-7 bg-tanne text-creme px-5 sm:px-10 pt-6 pb-28 sm:pb-32 rounded-t-lg lg:rounded-tr-none lg:rounded-l-lg">
            <a href="/rassen.php" class="inline-flex items-center gap-1.5 min-h-[44px] py-2 text-sm font-semibold text-creme underline-offset-4 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-pfote rounded-sm">
                <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M17 10a.75.75 0 01-.75.75H5.612l4.158 3.96a.75.75 0 11-1.04 1.08l-5.5-5.25a.75.75 0 010-1.08l5.5-5.25a.75.75 0 111.04 1.08L5.612 9.25H16.25A.75.75 0 0117 10z" clip-rule="evenodd"/></svg>
                Alle Rassen
            </a>

            <h1 id="rasse-name" class="mt-6 text-4xl sm:text-6xl font-black tracking-tight leading-[0.95] break-words" style="text-wrap: balance">
                <?= e($namen['haupt']) ?>
            </h1>
            <?php if ($namen['zweit'] !== null): ?>
                <p class="mt-3 text-lg sm:text-xl font-bold text-creme">
                    <?= e($namen['zweit']) ?>
                    <?php if (str_ends_with($namen['zweit'], '…')): ?>
                        <span class="block text-sm font-semibold text-creme/90 mt-1">Name in der Quelle gekürzt.</span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <?php if (!empty($breed['alternative_namen']) || !empty($breed['namenszusatz'])): ?>
                <p class="mt-2 text-sm text-creme/90">
                    <?= e(trim(($breed['namenszusatz'] ?? '') . ' ' . (!empty($breed['alternative_namen']) ? 'auch: ' . $breed['alternative_namen'] : ''))) ?>
                </p>
            <?php endif; ?>

            <?php if ($text['kurzportraet'] !== null): ?>
                <p class="mt-6 text-base sm:text-lg leading-relaxed max-w-[60ch]"><?= e($text['kurzportraet']) ?></p>
            <?php else: ?>
                <p class="mt-6 text-base leading-relaxed max-w-[60ch] text-creme/90">Für diese Rasse gibt es noch keine Beschreibung.</p>
            <?php endif; ?>
        </div>

        <div class="lg:col-span-5 relative min-h-[240px] sm:min-h-[300px] bg-sand overflow-hidden lg:rounded-r-lg">
            <?php if (!empty($breed['hat_bild'])): ?>
                <img src="/bildausgabe.php?id=<?= (int) $breed['id'] ?>&v=<?= urlencode((string) ($breed['bild_updated_at'] ?? '')) ?>"
                     alt="<?= e($breed['name']) ?>"
                     class="absolute inset-0 w-full h-full object-cover">
            <?php else: ?>
                <div class="absolute inset-0 flex flex-col items-center justify-center gap-3 px-6 text-center">
                    <?= render_dog_icon(112, '#B5502E', '#3F5A3F') ?>
                    <p class="text-sm font-semibold text-fellDk">Noch kein Foto</p>
                    <?php if ($darfPflegen): ?>
                        <a href="/admin_add_breed.php?id=<?= (int) $breed['id'] ?>" class="text-sm font-bold text-tanneDk underline underline-offset-4 min-h-[44px] py-2 inline-flex items-center">Foto hinzufügen</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- FCI-Balken, über die Kante des Kopfmoduls geschoben -->
        <div class="lg:col-span-12 -mt-24 sm:-mt-24 relative z-10 flex flex-col">
            <p class="balken self-start w-full lg:w-[78%] bg-fell text-creme px-5 sm:px-10 py-4 text-lg sm:text-xl font-black leading-snug">
                <?php if ($gruppe !== null): ?>
                    <span class="font-mono font-medium mr-2">FCI <?= $gruppe ?></span>
                    <?= e(Breed::FCI_GRUPPEN[$gruppe] ?? 'Gruppe ' . $gruppe) ?>
                <?php else: ?>
                    FCI-Gruppe nicht hinterlegt
                <?php endif; ?>
            </p>
            <?php if (!empty($breed['fci_sektion']) || !empty($breed['fci_sektion_name'])): ?>
                <p class="balken self-end w-[92%] lg:w-[56%] lg:mr-[22%] bg-pfote text-fellDk px-5 sm:px-8 py-3 text-base sm:text-lg font-bold">
                    <?php if (!empty($breed['fci_sektion'])): ?><span class="font-mono font-medium mr-2">Sektion <?= e($breed['fci_sektion']) ?></span><?php endif; ?>
                    <?= e($breed['fci_sektion_name'] ?? '') ?>
                </p>
            <?php endif; ?>
            <p class="balken self-start w-[86%] lg:w-[48%] lg:ml-[8%] bg-fellDk text-creme px-5 sm:px-8 py-3 text-sm sm:text-base font-bold flex flex-wrap gap-x-6 gap-y-1">
                <span><?= e($arbeitspruefungLabels[$breed['arbeitspruefung'] ?? ''] ?? 'Arbeitsprüfung: keine Angabe') ?></span>
                <span>Standard <span class="font-mono font-medium"><?= !empty($breed['fci_nr']) ? 'Nr. ' . e($breed['fci_nr']) : 'ohne Nr.' ?></span></span>
            </p>
        </div>
    </header>

    <!-- Aktionen -->
    <div class="mt-6 flex flex-wrap items-center gap-3">
        <a href="/dog_form.php?rasse=<?= (int) $breed['id'] ?>"
           class="inline-flex items-center min-h-[44px] bg-tanne hover:bg-tanneDk text-creme font-bold text-sm px-5 py-2 rounded-lg transition-colors focus-visible:outline focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-pfote">
            Hund dieser Rasse anlegen
        </a>
        <?php if ($darfPflegen): ?>
            <a href="/admin_add_breed.php?id=<?= (int) $breed['id'] ?>"
               class="inline-flex items-center min-h-[44px] border-2 border-fellDk text-fellDk hover:bg-fellDk hover:text-creme font-bold text-sm px-5 py-2 rounded-lg transition-colors">
                Bearbeiten
            </a>
            <a href="/content_versionen.php?typ=rasse&amp;id=<?= (int) $breed['id'] ?>"
               class="inline-flex items-center min-h-[44px] text-fellDk font-semibold text-sm px-2 py-2 underline underline-offset-4 hover:text-tanneDk">
                Versionen (<?= $versionen ?>)
            </a>
        <?php endif; ?>
    </div>

    <!-- Steckbrief: Maße und Merkmale -->
    <div class="mt-10 grid grid-cols-1 lg:grid-cols-12 gap-10">
        <section aria-labelledby="masse-titel" class="lg:col-span-5 border-t-4 border-fellDk pt-4">
            <h2 id="masse-titel" class="text-xl font-black text-fellDk">Größe und Gewicht</h2>
            <?php if (empty($breed['sizes'])): ?>
                <p class="mt-3 text-sm text-fellDk/80">Keine Maße hinterlegt.</p>
            <?php else: ?>
                <p class="mt-1 text-xs text-fellDk/80">
                    Skala 0–<?= (int) $maxCm ?> cm und 0–<?= (int) $maxKg ?> kg.
                    <span class="inline-flex items-center gap-1 ml-2"><span class="inline-block w-3 h-3 bg-tanne rounded-sm" aria-hidden="true"></span>Rüde</span>
                    <span class="inline-flex items-center gap-1 ml-2"><span class="inline-block w-3 h-3 bg-fell rounded-sm" aria-hidden="true"></span>Hündin</span>
                </p>
                <?php foreach ($breed['sizes'] as $size):
                    $werte = [
                        'Schulterhöhe' => [
                            ['Rüde', als_zahl($size['schulterhoehe_ruede_min_cm']), als_zahl($size['schulterhoehe_ruede_max_cm']), 'bg-tanne'],
                            ['Hündin', als_zahl($size['schulterhoehe_haendin_min_cm']), als_zahl($size['schulterhoehe_haendin_max_cm']), 'bg-fell'],
                        ],
                        'Gewicht' => [
                            ['Rüde', als_zahl($size['gewicht_ruede_min_kg']), als_zahl($size['gewicht_ruede_max_kg']), 'bg-tanne'],
                            ['Hündin', als_zahl($size['gewicht_haendin_min_kg']), als_zahl($size['gewicht_haendin_max_kg']), 'bg-fell'],
                        ],
                    ];
                ?>
                    <div class="mt-5">
                        <?php if (count($breed['sizes']) > 1): ?>
                            <h3 class="text-sm font-black uppercase tracking-wide text-fellDk mb-2"><?= e($groessenLabels[$size['groesse']] ?? $size['groesse']) ?></h3>
                        <?php else: ?>
                            <p class="text-sm font-bold text-fellDk mb-2">Größenklasse: <?= e($groessenLabels[$size['groesse']] ?? $size['groesse']) ?></p>
                        <?php endif; ?>
                        <dl class="space-y-3">
                            <?php foreach ($werte as $art => $zeilen):
                                $einheit = $art === 'Gewicht' ? 'kg' : 'cm';
                                $skala = $art === 'Gewicht' ? $maxKg : $maxCm;
                                // Nur Zeilen mit Werten zeigen
                                $zeilen = array_filter($zeilen, fn($z) => $z[1] !== null || $z[2] !== null);
                            ?>
                                <?php foreach ($zeilen as [$geschlecht, $min, $max, $farbe]): ?>
                                    <div class="grid grid-cols-[7.5rem_1fr] sm:grid-cols-[8.5rem_1fr] items-center gap-3">
                                        <dt class="text-sm text-fellDk">
                                            <?= e($art) ?> <?= e($geschlecht) ?>
                                            <span class="block font-mono text-sm font-medium"><?= e(format_spanne($min, $max, $einheit) ?? '–') ?></span>
                                        </dt>
                                        <dd><?= render_spannenbalken($min, $max, $skala, $farbe) ?></dd>
                                    </div>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </dl>
                        <?php if (count(array_filter([
                            $size['schulterhoehe_ruede_min_cm'], $size['schulterhoehe_ruede_max_cm'], $size['schulterhoehe_haendin_min_cm'], $size['schulterhoehe_haendin_max_cm'],
                            $size['gewicht_ruede_min_kg'], $size['gewicht_ruede_max_kg'], $size['gewicht_haendin_min_kg'], $size['gewicht_haendin_max_kg'],
                        ], fn($v) => $v !== null)) < 8): ?>
                            <p class="mt-2 text-xs text-fellDk/80">Fehlende Werte sind nicht hinterlegt.</p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <section aria-labelledby="merkmale-titel" class="lg:col-span-7 border-t-4 border-fellDk pt-4">
            <h2 id="merkmale-titel" class="text-xl font-black text-fellDk">Merkmale</h2>
            <dl class="mt-3 divide-y divide-sand">
                <div class="py-3 grid grid-cols-1 sm:grid-cols-[10rem_1fr] gap-1 sm:gap-4">
                    <dt class="text-sm font-bold text-fellDk">Eigenschaften</dt>
                    <dd>
                        <?php if (empty($breed['tags'])): ?>
                            <span class="text-sm text-fellDk/80">Noch keine zugeordnet</span>
                        <?php else: ?>
                            <ul class="flex flex-wrap gap-2">
                                <?php foreach ($breed['tags'] as $tag): ?>
                                    <li class="bg-sand text-fellDk text-sm font-semibold px-3 py-1 rounded-sm"><?= e($tag['name']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="py-3 grid grid-cols-1 sm:grid-cols-[10rem_1fr] gap-1 sm:gap-4">
                    <dt class="text-sm font-bold text-fellDk">Aktivitäten</dt>
                    <dd>
                        <?php if (empty($breed['activities'])): ?>
                            <span class="text-sm text-fellDk/80">Noch keine zugeordnet</span>
                        <?php else: ?>
                            <ul class="flex flex-wrap gap-2">
                                <?php foreach ($breed['activities'] as $activity): ?>
                                    <li class="bg-tanne/10 text-tanneDk text-sm font-semibold px-3 py-1 rounded-sm"><?= e($activity['name']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="py-3 grid grid-cols-1 sm:grid-cols-[10rem_1fr] gap-1 sm:gap-4">
                    <dt class="text-sm font-bold text-fellDk">Farben</dt>
                    <dd class="text-sm text-fellDk"><?= !empty($breed['farben']) ? e($breed['farben']) : '<span class="text-fellDk/80">keine Angabe</span>' ?></dd>
                </div>
                <div class="py-3 grid grid-cols-1 sm:grid-cols-[10rem_1fr] gap-1 sm:gap-4">
                    <dt class="text-sm font-bold text-fellDk">Ursprungsland</dt>
                    <dd class="text-sm text-fellDk"><?= !empty($breed['ursprungsland']) ? e($breed['ursprungsland']) : '<span class="text-fellDk/80">keine Angabe</span>' ?></dd>
                </div>
            </dl>
        </section>
    </div>

    <!-- Text -->
    <div class="mt-14">
        <?php if ($text['kurzportraet'] === null && empty($text['abschnitte'])): ?>
            <div class="border-t-4 border-fellDk pt-4">
                <h2 class="text-xl font-black text-fellDk">Beschreibung</h2>
                <p class="mt-2 text-fellDk">Für diese Rasse gibt es noch keine Beschreibung.</p>
                <?php if ($darfPflegen): ?>
                    <a href="/admin_add_breed.php?id=<?= (int) $breed['id'] ?>#beschreibung" class="mt-3 inline-flex items-center min-h-[44px] py-2 font-bold text-tanneDk underline underline-offset-4">Beschreibung schreiben</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?= render_rassentext_sprungleiste($text['abschnitte']) ?>
            <?= render_rassentext_abschnitte($text) ?>
        <?php endif; ?>

        <?php if ($text['kurzportraet'] !== null && (($breed['gliederung'] ?? '') === 'automatisch' || !empty($breed['quelle_url']))): ?>
            <footer class="lg:grid lg:grid-cols-12 lg:gap-10 mt-4">
                <div class="lg:col-start-5 lg:col-span-8 max-w-[60ch] border-t border-sand pt-4 space-y-1 text-sm text-fellDk">
                    <?php if (!empty($breed['quelle_url'])): ?>
                        <p>
                            Text: VDH-Rasselexikon<?= !empty($breed['quelle_stand']) ? ', abgerufen am ' . e(date('d.m.Y', strtotime((string) $breed['quelle_stand']))) : '' ?>.
                            <a href="<?= e($breed['quelle_url']) ?>" target="_blank" rel="noopener noreferrer" class="inline-block py-1 font-semibold text-tanneDk underline underline-offset-4">Original beim VDH</a>
                        </p>
                    <?php endif; ?>
                    <?php if (($breed['gliederung'] ?? '') === 'automatisch'): ?>
                        <p>Die Abschnitte wurden automatisch zugeordnet und sind noch nicht geprüft.</p>
                    <?php endif; ?>
                </div>
            </footer>
        <?php endif; ?>
    </div>

    <?php if (!empty($similarBreeds)): ?>
        <section aria-labelledby="aehnlich-titel" class="mt-14 border-t-4 border-fellDk pt-4">
            <h2 id="aehnlich-titel" class="text-xl font-black text-fellDk">Ähnliche Rassen</h2>
            <ul class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-8">
                <?php foreach ($similarBreeds as $item): $sb = $item['breed']; $sbNamen = Breed::namensteile((string) $sb['name']); ?>
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
