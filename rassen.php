<?php
/**
 * rassen.php
 *
 * Rassen-Suche/-Filterung (früher unter dashboard.php erreichbar,
 * jetzt eigenständig — dashboard.php ist die neue, hund-/halterzentrierte
 * Startseite nach dem Login). Filter-Optionen zeigen dynamisch, wie viele
 * Treffer bei Aktivierung entstehen würden, und werden ausgeblendet, wenn
 * keine Treffer möglich sind (0 Rassen).
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Breed.php';
require_once __DIR__ . '/models/Tag.php';
require_once __DIR__ . '/models/Activity.php';

secure_session_start();
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_breed_id'])) {
    require_admin();
    csrf_require_valid();
    Breed::delete((int) $_POST['delete_breed_id']);
    header('Location: /rassen.php');
    exit;
}

$searchTerm   = clean_input($_GET['q'] ?? '');
$selectedTags = array_map('intval', $_GET['tags'] ?? []);
$selectedActs = array_map('intval', $_GET['activities'] ?? []);
$selectedSize = array_values(array_intersect($_GET['groesse'] ?? [], ['klein', 'mittel', 'gross', 'sehr_gross']));

$breedsGefiltert = Breed::search($searchTerm, $selectedTags, $selectedActs, $selectedSize);
$tagGroups        = Tag::findAllGroupedByCategory();
$activities        = Activity::findAll();
$counts            = Breed::getFilterCounts($searchTerm, $selectedTags, $selectedActs, $selectedSize);

// Pagination: Breed::search() liefert bereits das vollständige,
// gefilterte Ergebnis (Tags/Aktivitäten werden dort in PHP
// nachgefiltert, siehe Kommentar dort) — daher wird hier nur noch der
// Ausschnitt für die aktuelle Seite herausgeschnitten, statt die
// Filterlogik zu duplizieren. Bei aktuell ~382 Rassen ist das
// unproblematisch; sollte der Datensatz stark wachsen, müsste die
// Tag/Aktivitäten-Filterung in SQL wandern, um LIMIT/OFFSET direkt in
// der Query nutzen zu können.
const RASSEN_PRO_SEITE = 24;

$gesamtTreffer = count($breedsGefiltert);
$gesamtSeiten  = max(1, (int) ceil($gesamtTreffer / RASSEN_PRO_SEITE));
$aktuelleSeite = max(1, (int) ($_GET['seite'] ?? 1));
$aktuelleSeite = min($aktuelleSeite, $gesamtSeiten);

$breeds = array_slice($breedsGefiltert, ($aktuelleSeite - 1) * RASSEN_PRO_SEITE, RASSEN_PRO_SEITE);

/**
 * Baut die Query-String für einen Pagination-Link, unter Beibehaltung
 * aller aktuell aktiven Filter (q, tags[], activities[], groesse[]),
 * nur "seite" wird überschrieben.
 */
function paginationLink(int $zielSeite): string
{
    $params = $_GET;
    $params['seite'] = $zielSeite;
    return '/rassen.php?' . http_build_query($params);
}

$groessenLabels = ['klein' => 'Klein', 'mittel' => 'Mittel', 'gross' => 'Groß', 'sehr_gross' => 'Sehr groß'];

$pageTitle = 'Rassen durchsuchen';
require __DIR__ . '/views/partials/header.php';
?>

<div x-data="{ filtersOpen: <?= !empty($selectedTags) || !empty($selectedActs) || !empty($selectedSize) ? 'true' : 'false' ?> }">

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-extrabold text-fellDk">🐕 Rassen durchsuchen</h1>
        <?php if (is_admin()): ?>
            <a href="/admin_add_breed.php" class="bg-pfote hover:bg-fell text-white text-sm font-bold px-4 py-2 rounded-full transition-colors">
                + Neue Rasse anlegen
            </a>
        <?php endif; ?>
    </div>

    <form method="get" action="/rassen.php" class="bg-white/70 rounded-2xl shadow p-5 border border-sand mb-8">
        <div class="flex flex-col md:flex-row gap-3">
            <input
                type="text" name="q" value="<?= e($searchTerm) ?>"
                placeholder="Rasse suchen, z. B. &bdquo;Labrador&ldquo; &hellip;"
                class="flex-1 rounded-lg border border-sand px-4 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"
            >
            <button type="submit" class="bg-tanne hover:bg-tanneDk text-creme font-bold px-6 py-2 rounded-full transition-colors">Suchen</button>
            <button type="button" @click="filtersOpen = !filtersOpen"
                class="border border-tanne text-tanne hover:bg-tanne hover:text-creme font-bold px-6 py-2 rounded-full transition-colors">
                Filter <span x-text="filtersOpen ? '▲' : '▼'"></span>
            </button>
        </div>

        <div x-show="filtersOpen" x-cloak class="mt-5 pt-5 border-t border-sand grid grid-cols-1 md:grid-cols-3 gap-6">

            <!-- Größe -->
            <div>
                <h3 class="font-bold text-sm mb-2 text-tanneDk">Größe</h3>
                <div class="space-y-1">
                    <?php foreach ($groessenLabels as $value => $label):
                        $count   = $counts['groessen'][$value] ?? 0;
                        $active  = in_array($value, $selectedSize, true);
                        $disabled = !$active && $count === 0;
                    ?>
                        <label class="flex items-center gap-2 text-sm <?= $disabled ? 'opacity-40' : '' ?>">
                            <input type="checkbox" name="groesse[]" value="<?= e($value) ?>"
                                <?= $active ? 'checked' : '' ?>
                                <?= $disabled ? 'disabled' : '' ?>
                                class="rounded border-sand text-tanne focus:ring-tanne">
                            <span><?= e($label) ?></span>
                            <span class="ml-auto text-xs text-fellDk/40 tabular-nums"><?= $active ? '' : '(' . $count . ')' ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Aktivitäten -->
            <div>
                <h3 class="font-bold text-sm mb-2 text-tanneDk">Aktivitäten</h3>
                <div class="space-y-1 max-h-48 overflow-y-auto pr-2">
                    <?php foreach ($activities as $activity):
                        $id      = (int) $activity['id'];
                        $count   = $counts['activities'][$id] ?? 0;
                        $active  = in_array($id, $selectedActs, true);
                        $disabled = !$active && $count === 0;
                    ?>
                        <label class="flex items-center gap-2 text-sm <?= $disabled ? 'opacity-40' : '' ?>">
                            <input type="checkbox" name="activities[]" value="<?= $id ?>"
                                <?= $active ? 'checked' : '' ?>
                                <?= $disabled ? 'disabled' : '' ?>
                                class="rounded border-sand text-tanne focus:ring-tanne">
                            <span><?= e($activity['name']) ?></span>
                            <span class="ml-auto text-xs text-fellDk/40 tabular-nums"><?= $active ? '' : '(' . $count . ')' ?></span>
                        </label>
                    <?php endforeach; ?>
                    <?php if (empty($activities)): ?>
                        <p class="text-xs text-fellDk/50">Noch keine Aktivitäten angelegt.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Eigenschaften / Tags -->
            <?php
                // Flache Tag-Liste (id, name, category, count) als JSON fürs
                // Alpine-Autocomplete unten — Daten sind ohnehin serverseitig
                // schon geladen, daher kein zusätzlicher AJAX-Request nötig.
                $tagsFuerAutocomplete = [];
                foreach ($tagGroups as $category => $tags) {
                    foreach ($tags as $tag) {
                        $id = (int) $tag['id'];
                        $tagsFuerAutocomplete[] = [
                            'id'       => $id,
                            'name'     => $tag['name'],
                            'category' => $category,
                            'count'    => $counts['tags'][$id] ?? 0,
                        ];
                    }
                }
            ?>
            <div x-data="{
                    checkedTags: [<?= implode(',', $selectedTags) ?>],
                    tagQuery: '',
                    alleTags: <?= e(json_encode($tagsFuerAutocomplete, JSON_UNESCAPED_UNICODE)) ?>,
                    get vorschlaege() {
                        const q = this.tagQuery.trim().toLowerCase();
                        if (q === '') return [];
                        return this.alleTags
                            .filter(t => !this.checkedTags.includes(t.id))
                            .filter(t => this.checkedTags.length > 0 || t.count > 0) // 0-Treffer nur ausblenden, wenn noch kein Filter aktiv
                            .filter(t => t.name.toLowerCase().includes(q))
                            .slice(0, 8);
                    },
                    tagHinzufuegen(tag) {
                        this.checkedTags.push(tag.id);
                        this.tagQuery = '';
                    }
                 }">
                <h3 class="font-bold text-sm mb-2 text-tanneDk">Eigenschaften</h3>

                <div class="relative mb-2">
                    <input
                        type="text" x-model="tagQuery"
                        placeholder="Tag suchen, z. B. „agil“ …"
                        autocomplete="off"
                        class="w-full rounded-lg border border-sand px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-tanne"
                    >
                    <div x-show="vorschlaege.length > 0" x-cloak
                         class="absolute z-10 left-0 right-0 mt-1 bg-white rounded-lg shadow-lg border border-sand overflow-hidden">
                        <template x-for="tag in vorschlaege" :key="tag.id">
                            <button type="button" @click="tagHinzufuegen(tag)"
                                class="w-full flex items-center gap-2 px-3 py-1.5 text-sm text-left hover:bg-creme transition-colors">
                                <span x-text="tag.name"></span>
                                <span class="text-xs text-fellDk/40" x-text="'(' + tag.category + ')'"></span>
                            </button>
                        </template>
                    </div>
                </div>

                <div class="space-y-3 max-h-48 overflow-y-auto pr-2">
                    <?php foreach ($tagGroups as $category => $tags): ?>
                        <div>
                            <p class="text-xs font-semibold text-fellDk/60 mb-1"><?= e($category) ?></p>
                            <?php foreach ($tags as $tag):
                                $id      = (int) $tag['id'];
                                $count   = $counts['tags'][$id] ?? 0;
                                $active  = in_array($id, $selectedTags, true);
                                $disabled = !$active && $count === 0;
                            ?>
                                <label class="flex items-center gap-2 text-sm <?= $disabled ? 'opacity-40' : '' ?>">
                                    <input type="checkbox" name="tags[]" value="<?= $id ?>"
                                        x-model.number="checkedTags"
                                        <?= $disabled ? 'disabled' : '' ?>
                                        class="rounded border-sand text-tanne focus:ring-tanne">
                                    <span><?= e($tag['name']) ?></span>
                                    <span class="ml-auto text-xs text-fellDk/40 tabular-nums"><?= $active ? '' : '(' . $count . ')' ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($tagGroups)): ?>
                        <p class="text-xs text-fellDk/50">Noch keine Eigenschaften angelegt.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="mt-4 flex items-center justify-between">
            <p class="text-sm text-fellDk/50"><?= $gesamtTreffer ?> Rasse<?= $gesamtTreffer !== 1 ? 'n' : '' ?> gefunden</p>
            <div>
                <button type="submit" class="text-sm text-tanne underline hover:text-tanneDk">Filter anwenden</button>
                <a href="/rassen.php" class="text-sm text-fellDk/50 underline hover:text-fellDk ml-4">Zurücksetzen</a>
            </div>
        </div>
    </form>

    <?php if (empty($breeds)): ?>
        <div class="text-center py-16 text-fellDk/50">
            <span class="text-5xl block mb-3" aria-hidden="true">🦴</span>
            <p>Keine Rassen gefunden. Versuch es mit anderen Suchbegriffen oder Filtern.</p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
            <?php foreach ($breeds as $breed): ?>
                <div class="bg-white/80 rounded-2xl shadow border border-sand p-5 flex flex-col">
                    <a href="/breed_detail.php?id=<?= (int) $breed['id'] ?>" class="block group">
                        <?php if (!empty($breed['hat_bild'])): ?>
                            <img
                                src="/bildausgabe.php?id=<?= (int) $breed['id'] ?>&v=<?= urlencode((string) ($breed['bild_updated_at'] ?? '')) ?>"
                                alt="<?= e($breed['name']) ?>"
                                class="w-full h-36 object-cover rounded-xl border border-sand mb-3 group-hover:opacity-90 transition-opacity"
                            >
                        <?php else: ?>
                            <div class="w-full h-36 rounded-xl border border-sand bg-creme flex items-center justify-center mb-3 group-hover:bg-sand/30 transition-colors" aria-hidden="true">
                                <span class="text-3xl text-fellDk/20">🐾</span>
                            </div>
                        <?php endif; ?>
                    </a>

                    <h2 class="text-lg font-extrabold text-fellDk mb-1"><?= e($breed['name']) ?></h2>
                    <p class="text-sm text-fellDk/70 mb-3">
                        <?= $breed['ursprungsland'] ? e($breed['ursprungsland']) : 'Ursprungsland unbekannt' ?>
                        <?php if ($breed['fci_klasse']): ?>
                            &middot; FCI-Klasse <?= (int) $breed['fci_klasse'] ?>
                        <?php endif; ?>
                        <?php if (!empty($breed['fci_nr'])): ?>
                            &middot; Nr. <?= e($breed['fci_nr']) ?>
                        <?php endif; ?>
                    </p>

                    <div class="mt-auto pt-3 flex gap-2">
                        <a href="/breed_detail.php?id=<?= (int) $breed['id'] ?>"
                            class="flex-1 text-center text-sm bg-tanne/10 text-tanneDk font-semibold py-2 rounded-full hover:bg-tanne/20 transition-colors">
                            Details
                        </a>
                        <?php if (is_admin()): ?>
                            <a href="/admin_add_breed.php?id=<?= (int) $breed['id'] ?>"
                                class="flex-1 text-center text-sm bg-pfote/10 text-fell font-semibold py-2 rounded-full hover:bg-pfote/20 transition-colors">
                                Bearbeiten
                            </a>
                            <form method="post" action="/rassen.php"
                                onsubmit="return confirm('<?= e($breed['name']) ?> wirklich löschen?');"
                                class="flex-1">
                                <?= csrf_field() ?>
                                <input type="hidden" name="delete_breed_id" value="<?= (int) $breed['id'] ?>">
                                <button type="submit"
                                    class="w-full text-sm bg-red-50 text-red-600 font-semibold py-2 rounded-full hover:bg-red-100 transition-colors">
                                    Löschen
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($gesamtSeiten > 1): ?>
        <nav class="flex items-center justify-center gap-2 mt-8" aria-label="Seiten">
            <?php if ($aktuelleSeite > 1): ?>
                <a href="<?= e(paginationLink($aktuelleSeite - 1)) ?>"
                    class="px-3 py-1.5 rounded-full text-sm border border-sand text-tanneDk hover:bg-tanne hover:text-creme transition-colors">
                    ← Zurück
                </a>
            <?php endif; ?>

            <span class="text-sm text-fellDk/60 px-2">
                Seite <?= $aktuelleSeite ?> von <?= $gesamtSeiten ?>
            </span>

            <?php if ($aktuelleSeite < $gesamtSeiten): ?>
                <a href="<?= e(paginationLink($aktuelleSeite + 1)) ?>"
                    class="px-3 py-1.5 rounded-full text-sm border border-sand text-tanneDk hover:bg-tanne hover:text-creme transition-colors">
                    Weiter →
                </a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
