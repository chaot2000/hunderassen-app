<?php
/**
 * breed_detail.php
 *
 * Read-only Detailansicht einer einzelnen Rasse — für alle
 * eingeloggten Benutzer (admin und user) zugänglich.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Breed.php';

secure_session_start();
require_login();

$breedId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$breed   = $breedId ? Breed::findById($breedId) : null;

if ($breed === null) {
    http_response_code(404);
    $pageTitle = 'Nicht gefunden';
    require __DIR__ . '/views/partials/header.php';
    echo '<div class="text-center py-16 text-fellDk/50"><p>Diese Rasse wurde nicht gefunden.</p>
          <a href="/rassen.php" class="text-tanne underline">Zurück zur Übersicht</a></div>';
    require __DIR__ . '/views/partials/footer.php';
    exit;
}

$similarBreeds = Breed::getSimilar($breedId, 6);

$groessenLabels = [
    'klein'      => 'Klein',
    'mittel'     => 'Mittel',
    'gross'      => 'Groß',
    'sehr_gross' => 'Sehr groß',
];

/**
 * Formatiert eine Min-Max-Spanne in cm für die Anzeige.
 * Gibt z.B. "46–58 cm", "bis 58 cm", "ab 46 cm" oder "—" zurück,
 * je nachdem welche Werte vorhanden sind.
 */
function format_cm_range(?int $min, ?int $max): string
{
    if ($min !== null && $max !== null) {
        return "{$min}–{$max} cm";
    }
    if ($min !== null) {
        return "ab {$min} cm";
    }
    if ($max !== null) {
        return "bis {$max} cm";
    }
    return '—';
}

/**
 * Formatiert eine Min-Max-Spanne in kg für die Anzeige, analog zu
 * format_cm_range(). Nutzt Komma als Dezimaltrennzeichen (deutsche
 * Schreibweise) und lässt überflüssige ".0"-Nachkommastellen weg.
 */
function format_kg_range(?float $min, ?float $max): string
{
    $fmt = static function (float $v): string {
        return rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');
    };

    if ($min !== null && $max !== null) {
        return $fmt($min) . '–' . $fmt($max) . ' kg';
    }
    if ($min !== null) {
        return 'ab ' . $fmt($min) . ' kg';
    }
    if ($max !== null) {
        return 'bis ' . $fmt($max) . ' kg';
    }
    return '—';
}

$pageTitle = $breed['name'];
require __DIR__ . '/views/partials/header.php';
?>

<a href="/rassen.php" class="inline-flex items-center gap-1.5 bg-white/80 border border-sand text-tanneDk font-semibold text-sm px-4 py-2 rounded-full hover:bg-tanne hover:text-creme hover:border-tanne transition-colors">
    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path fill-rule="evenodd" d="M17 10a.75.75 0 01-.75.75H5.612l4.158 3.96a.75.75 0 11-1.04 1.08l-5.5-5.25a.75.75 0 010-1.08l5.5-5.25a.75.75 0 111.04 1.08L5.612 9.25H16.25A.75.75 0 0117 10z" clip-rule="evenodd" />
    </svg>
    Zurück zur Übersicht
</a>

<div class="bg-white/80 rounded-2xl shadow border border-sand p-6 mt-4">
    <div class="flex flex-col sm:flex-row gap-6">
        <?php if (!empty($breed['hat_bild'])): ?>
            <img
                src="/bildausgabe.php?id=<?= (int) $breed['id'] ?>&v=<?= urlencode((string) ($breed['bild_updated_at'] ?? '')) ?>"
                alt="<?= e($breed['name']) ?>"
                class="w-full sm:w-64 h-64 object-cover rounded-xl border border-sand shrink-0"
            >
        <?php endif; ?>

        <!--
            WICHTIG: Größe & Gewicht / Geeignete Aktivitäten / Eigenschaften
            liegen bewusst INNERHALB dieser flex-1-Spalte (nicht mehr als
            Geschwister-Divs danach) — sonst laufen sie bei vorhandenem
            Bild über die volle Breite statt neben dem Bild eingerückt zu
            bleiben. Ohne Bild macht das optisch keinen Unterschied
            (flex-1 nimmt dann ohnehin die volle Breite ein), mit Bild
            ist es aber der entscheidende Unterschied.
        -->
        <div class="flex-1">
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-2xl font-extrabold text-fellDk">
                        <?= e($breed['name']) ?>
                        <?php if (!empty($breed['namenszusatz'])): ?>
                            <span class="text-lg font-semibold text-fellDk/60"><?= e($breed['namenszusatz']) ?></span>
                        <?php endif; ?>
                    </h1>
                    <?php if (!empty($breed['alternative_namen'])): ?>
                        <p class="text-sm text-fellDk/60 italic mt-0.5">auch bekannt als: <?= e($breed['alternative_namen']) ?></p>
                    <?php endif; ?>
                    <p class="text-sm text-fellDk/70 mt-1">
                        <?= $breed['ursprungsland'] ? e($breed['ursprungsland']) : 'Ursprungsland unbekannt' ?>
                        <?php if ($breed['fci_klasse']): ?>
                            &middot; FCI-Klasse <?= (int) $breed['fci_klasse'] ?>
                        <?php endif; ?>
                        <?php if (!empty($breed['fci_nr'])): ?>
                            &middot; FCI-Nr. <?= e($breed['fci_nr']) ?>
                        <?php endif; ?>
                    </p>
                </div>
                <?php if (is_admin()): ?>
                    <a href="/admin_add_breed.php?id=<?= (int) $breed['id'] ?>" class="bg-pfote/10 text-fell font-semibold text-sm px-4 py-2 rounded-full hover:bg-pfote/20 transition-colors whitespace-nowrap">
                        Bearbeiten
                    </a>
                <?php endif; ?>
            </div>

            <?php if (!empty($breed['farben'])): ?>
                <p class="text-sm mt-3"><span class="font-semibold">Farben:</span> <?= e($breed['farben']) ?></p>
            <?php endif; ?>

            <?php if (!empty($breed['beschreibung'])): ?>
                <p class="text-sm mt-3 leading-relaxed"><?= nl2br(e($breed['beschreibung'])) ?></p>
            <?php endif; ?>

            <!-- Größenklassen -->
            <div class="mt-6">
                <h2 class="font-bold text-fellDk mb-2">Größe &amp; Gewicht</h2>
                <?php if (empty($breed['sizes'])): ?>
                    <p class="text-sm text-fellDk/50">Keine Größenangaben hinterlegt.</p>
                <?php else: ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <?php foreach ($breed['sizes'] as $size): ?>
                            <div class="border border-sand rounded-lg px-4 py-3 text-sm">
                                <p class="font-semibold text-tanneDk mb-2">
                                    <?= e($groessenLabels[$size['groesse']] ?? $size['groesse']) ?>
                                </p>
                                <p class="text-xs font-semibold text-fellDk/50 mb-0.5">Schulterhöhe</p>
                                <p>Rüde: <?= e(format_cm_range(
                                    $size['schulterhoehe_ruede_min_cm'] !== null ? (int) $size['schulterhoehe_ruede_min_cm'] : null,
                                    $size['schulterhoehe_ruede_max_cm'] !== null ? (int) $size['schulterhoehe_ruede_max_cm'] : null
                                )) ?></p>
                                <p>Hündin: <?= e(format_cm_range(
                                    $size['schulterhoehe_haendin_min_cm'] !== null ? (int) $size['schulterhoehe_haendin_min_cm'] : null,
                                    $size['schulterhoehe_haendin_max_cm'] !== null ? (int) $size['schulterhoehe_haendin_max_cm'] : null
                                )) ?></p>

                                <p class="text-xs font-semibold text-fellDk/50 mb-0.5 mt-2">Gewicht</p>
                                <p>Rüde: <?= e(format_kg_range(
                                    $size['gewicht_ruede_min_kg'] !== null ? (float) $size['gewicht_ruede_min_kg'] : null,
                                    $size['gewicht_ruede_max_kg'] !== null ? (float) $size['gewicht_ruede_max_kg'] : null
                                )) ?></p>
                                <p>Hündin: <?= e(format_kg_range(
                                    $size['gewicht_haendin_min_kg'] !== null ? (float) $size['gewicht_haendin_min_kg'] : null,
                                    $size['gewicht_haendin_max_kg'] !== null ? (float) $size['gewicht_haendin_max_kg'] : null
                                )) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Aktivitäten -->
            <div class="mt-6">
                <h2 class="font-bold text-fellDk mb-2">Geeignete Aktivitäten</h2>
                <?php if (empty($breed['activities'])): ?>
                    <p class="text-sm text-fellDk/50">Keine Aktivitäten hinterlegt.</p>
                <?php else: ?>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($breed['activities'] as $activity): ?>
                            <span class="text-sm bg-tanne/10 text-tanneDk px-3 py-1 rounded-full"><?= e($activity['name']) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Eigenschaften -->
            <div class="mt-6">
                <h2 class="font-bold text-fellDk mb-2">Eigenschaften</h2>
                <?php if (empty($breed['tags'])): ?>
                    <p class="text-sm text-fellDk/50">Keine Eigenschaften hinterlegt.</p>
                <?php else: ?>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($breed['tags'] as $tag): ?>
                            <span class="text-sm bg-pfote/10 text-fell px-3 py-1 rounded-full"><?= e($tag['name']) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($similarBreeds)): ?>
<div class="bg-white/80 rounded-2xl shadow border border-sand p-6 mt-6">
    <h2 class="text-lg font-bold text-fellDk mb-4">Ähnliche Rassen</h2>
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-4">
        <?php foreach ($similarBreeds as $item): $sb = $item['breed']; ?>
            <a href="/breed_detail.php?id=<?= (int) $sb['id'] ?>" class="block group">
                <div class="w-full aspect-square rounded-xl border border-sand bg-creme overflow-hidden flex items-center justify-center">
                    <?php if (!empty($sb['hat_bild'])): ?>
                        <img
                            src="/bildausgabe.php?id=<?= (int) $sb['id'] ?>&v=<?= urlencode((string) ($sb['bild_updated_at'] ?? '')) ?>"
                            alt="<?= e($sb['name']) ?>"
                            class="w-full h-full object-cover group-hover:scale-105 transition-transform"
                        >
                    <?php else: ?>
                        <span class="text-3xl text-fellDk/30" aria-hidden="true">🐾</span>
                    <?php endif; ?>
                </div>
                <p class="text-sm font-semibold text-fellDk mt-2 group-hover:text-tanneDk transition-colors"><?= e($sb['name']) ?></p>
                <p class="text-xs text-fellDk/50" title="<?= e(implode(', ', $item['gruende'])) ?>">
                    <?= e($item['gruende'][0] ?? '') ?>
                </p>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="mt-6 text-center">
    <a href="/rassen.php" class="inline-flex items-center gap-1.5 bg-white/80 border border-sand text-tanneDk font-semibold text-sm px-4 py-2 rounded-full hover:bg-tanne hover:text-creme hover:border-tanne transition-colors">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M17 10a.75.75 0 01-.75.75H5.612l4.158 3.96a.75.75 0 11-1.04 1.08l-5.5-5.25a.75.75 0 010-1.08l5.5-5.25a.75.75 0 111.04 1.08L5.612 9.25H16.25A.75.75 0 0117 10z" clip-rule="evenodd" />
        </svg>
        Zurück zur Übersicht
    </a>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>