<?php
/**
 * admin_manage_tags.php
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Tag.php';
require_once __DIR__ . '/models/Activity.php';

secure_session_start();
require_admin();

$errorMessage   = null;
$successMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'create_tag':
            $result = Tag::create($_POST['name'] ?? '', $_POST['category'] ?? null, $_POST['description'] ?? null);
            $result['success'] ? $successMessage = $result['message'] : $errorMessage = $result['message'];
            break;

        case 'update_tag':
            $result = Tag::update(
                (int) ($_POST['tag_id'] ?? 0),
                $_POST['name'] ?? '',
                $_POST['category'] ?? null,
                $_POST['description'] ?? null
            );
            $result['success'] ? $successMessage = $result['message'] : $errorMessage = $result['message'];
            break;

        case 'delete_tag':
            Tag::delete((int) ($_POST['tag_id'] ?? 0));
            header('Location: /admin_manage_tags.php');
            exit;

        case 'create_activity':
            $result = Activity::create($_POST['name'] ?? '', $_POST['description'] ?? null);
            $result['success'] ? $successMessage = $result['message'] : $errorMessage = $result['message'];
            break;

        case 'update_activity':
            $result = Activity::update(
                (int) ($_POST['activity_id'] ?? 0),
                $_POST['name'] ?? '',
                $_POST['description'] ?? null
            );
            $result['success'] ? $successMessage = $result['message'] : $errorMessage = $result['message'];
            break;

        case 'delete_activity':
            Activity::delete((int) ($_POST['activity_id'] ?? 0));
            header('Location: /admin_manage_tags.php');
            exit;

        default:
            $errorMessage = 'Unbekannte Aktion.';
    }
}

$tagGroups      = Tag::findAllGroupedByCategory();
$allActivities  = Activity::findAll();
$tagCounts      = Tag::getBreedCounts();
$activityCounts = Activity::getBreedCounts();
$allCategories  = array_keys($tagGroups);

$pageTitle = 'Eigenschaften & Aktivitäten verwalten';
require __DIR__ . '/views/partials/header.php';
?>

<h1 class="text-2xl font-extrabold text-fellDk mb-6">🏷️ Eigenschaften &amp; Aktivitäten verwalten</h1>

<?php if ($successMessage !== null): ?>
    <div class="bg-tanne/10 border border-tanne/30 text-tanneDk text-sm rounded-lg px-4 py-3 mb-6" role="status"><?= e($successMessage) ?></div>
<?php endif; ?>
<?php if ($errorMessage !== null): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3 mb-6" role="alert"><?= e($errorMessage) ?></div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-8">

    <!-- ============================================================ -->
    <!-- Eigenschaften / Tags                                          -->
    <!-- ============================================================ -->
    <div class="bg-white/80 rounded-2xl shadow border border-sand p-6">
        <h2 class="text-lg font-bold text-fellDk mb-4">Eigenschaften</h2>

        <form method="post" action="/admin_manage_tags.php" class="space-y-3 mb-6 border-b border-sand pb-6">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_tag">
            <div>
                <label for="tag_name" class="block text-sm font-semibold mb-1">Name *</label>
                <input type="text" id="tag_name" name="name" required maxlength="100"
                    placeholder="z. B. Wasserliebend"
                    class="w-full rounded-lg border border-sand px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
            </div>
            <div>
                <label for="tag_category" class="block text-sm font-semibold mb-1">Kategorie</label>
                <input type="text" id="tag_category" name="category" maxlength="50"
                    placeholder="z. B. Wesen, Pflege" list="existing_categories"
                    class="w-full rounded-lg border border-sand px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
                <datalist id="existing_categories">
                    <?php foreach ($allCategories as $cat): ?>
                        <option value="<?= e($cat) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div>
                <label for="tag_description" class="block text-sm font-semibold mb-1">Beschreibung</label>
                <input type="text" id="tag_description" name="description" maxlength="255"
                    class="w-full rounded-lg border border-sand px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
            </div>
            <button type="submit" class="bg-tanne hover:bg-tanneDk text-creme font-bold text-sm px-5 py-2 rounded-full transition-colors">
                + Eigenschaft anlegen
            </button>
        </form>

        <div class="space-y-4 max-h-[28rem] overflow-y-auto pr-1">
            <?php foreach ($tagGroups as $category => $tags): ?>
                <div>
                    <p class="text-xs font-semibold text-fellDk/60 mb-2"><?= e($category) ?></p>
                    <div class="space-y-2">
                        <?php foreach ($tags as $tag):
                            $tagId      = (int) $tag['id'];
                            $breedCount = $tagCounts[$tagId] ?? 0;
                            // Daten als PHP-generierte JS-Literale: einfache Anführungszeichen
                            // um x-data, JSON_HEX_APOS verhindert Kollision mit äußerem '...'
                            $jsName        = json_encode($tag['name'], JSON_HEX_APOS);
                            $jsCategory    = json_encode($tag['category'] ?? '', JSON_HEX_APOS);
                            $jsDescription = json_encode($tag['description'] ?? '', JSON_HEX_APOS);
                        ?>
                            <div
                                x-data='{ editing: false, name: <?= $jsName ?>, category: <?= $jsCategory ?>, description: <?= $jsDescription ?> }'
                                class="border border-sand rounded-lg px-3 py-2"
                            >
                                <div x-show="!editing" class="flex items-center gap-2">
                                    <div class="flex-1 min-w-0">
                                        <span class="text-sm"><?= e($tag['name']) ?></span>
                                        <?php if (!empty($tag['description'])): ?>
                                            <p class="text-xs text-fellDk/50 mt-0.5 truncate"><?= e($tag['description']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($breedCount > 0): ?>
                                        <span class="text-xs text-fellDk/40 tabular-nums shrink-0"><?= $breedCount ?>×</span>
                                    <?php endif; ?>
                                    <button type="button" @click="editing = true"
                                        class="text-xs text-tanne hover:text-tanneDk font-semibold shrink-0">
                                        Bearbeiten
                                    </button>
                                    <form method="post" action="/admin_manage_tags.php" class="inline shrink-0"
                                        onsubmit="return confirm('Eigenschaft löschen?<?= $breedCount > 0 ? ' Betrifft ' . $breedCount . ' Rasse(n).' : '' ?>');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_tag">
                                        <input type="hidden" name="tag_id" value="<?= $tagId ?>">
                                        <button type="submit" class="text-xs text-red-500 hover:text-red-700 font-semibold">
                                            Löschen<?= $breedCount > 0 ? ' (' . $breedCount . ')' : '' ?>
                                        </button>
                                    </form>
                                </div>

                                <form x-show="editing" x-cloak method="post" action="/admin_manage_tags.php" class="space-y-2">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_tag">
                                    <input type="hidden" name="tag_id" value="<?= $tagId ?>">
                                    <input type="text" name="name" x-model="name" required maxlength="100"
                                        placeholder="Name"
                                        class="w-full rounded border border-sand px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
                                    <input type="text" name="category" x-model="category" maxlength="50"
                                        placeholder="Kategorie" list="existing_categories"
                                        class="w-full rounded border border-sand px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
                                    <input type="text" name="description" x-model="description" maxlength="255"
                                        placeholder="Beschreibung (optional)"
                                        class="w-full rounded border border-sand px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
                                    <div class="flex gap-2">
                                        <button type="submit" class="text-xs bg-tanne text-creme font-semibold px-3 py-1 rounded-full hover:bg-tanneDk">
                                            Speichern
                                        </button>
                                        <button type="button" @click="editing = false"
                                            class="text-xs text-fellDk/60 hover:text-fellDk font-semibold px-3 py-1">
                                            Abbrechen
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($tagGroups)): ?>
                <p class="text-sm text-fellDk/50">Noch keine Eigenschaften angelegt.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- Aktivitäten                                                   -->
    <!-- ============================================================ -->
    <div class="bg-white/80 rounded-2xl shadow border border-sand p-6">
        <h2 class="text-lg font-bold text-fellDk mb-4">Aktivitäten</h2>

        <form method="post" action="/admin_manage_tags.php" class="space-y-3 mb-6 border-b border-sand pb-6">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_activity">
            <div>
                <label for="activity_name" class="block text-sm font-semibold mb-1">Name *</label>
                <input type="text" id="activity_name" name="name" required maxlength="120"
                    placeholder="z. B. Fährtenarbeit"
                    class="w-full rounded-lg border border-sand px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
            </div>
            <div>
                <label for="activity_description" class="block text-sm font-semibold mb-1">Beschreibung</label>
                <input type="text" id="activity_description" name="description" maxlength="255"
                    class="w-full rounded-lg border border-sand px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
            </div>
            <button type="submit" class="bg-pfote hover:bg-fell text-white font-bold text-sm px-5 py-2 rounded-full transition-colors">
                + Aktivität anlegen
            </button>
        </form>

        <div class="space-y-2 max-h-[28rem] overflow-y-auto pr-1">
            <?php foreach ($allActivities as $activity):
                $actId      = (int) $activity['id'];
                $breedCount = $activityCounts[$actId] ?? 0;
                $jsActName  = json_encode($activity['name'], JSON_HEX_APOS);
                $jsActDesc  = json_encode($activity['description'] ?? '', JSON_HEX_APOS);
            ?>
                <div
                    x-data='{ editing: false, name: <?= $jsActName ?>, description: <?= $jsActDesc ?> }'
                    class="border border-sand rounded-lg px-3 py-2"
                >
                    <div x-show="!editing" class="flex items-center gap-2">
                        <div class="flex-1 min-w-0">
                            <span class="text-sm"><?= e($activity['name']) ?></span>
                            <?php if (!empty($activity['description'])): ?>
                                <p class="text-xs text-fellDk/50 mt-0.5 truncate"><?= e($activity['description']) ?></p>
                            <?php endif; ?>
                        </div>
                        <?php if ($breedCount > 0): ?>
                            <span class="text-xs text-fellDk/40 tabular-nums shrink-0"><?= $breedCount ?>×</span>
                        <?php endif; ?>
                        <button type="button" @click="editing = true"
                            class="text-xs text-tanne hover:text-tanneDk font-semibold shrink-0">
                            Bearbeiten
                        </button>
                        <form method="post" action="/admin_manage_tags.php" class="inline shrink-0"
                            onsubmit="return confirm('Aktivität löschen?<?= $breedCount > 0 ? ' Betrifft ' . $breedCount . ' Rasse(n).' : '' ?>');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_activity">
                            <input type="hidden" name="activity_id" value="<?= $actId ?>">
                            <button type="submit" class="text-xs text-red-500 hover:text-red-700 font-semibold">
                                Löschen<?= $breedCount > 0 ? ' (' . $breedCount . ')' : '' ?>
                            </button>
                        </form>
                    </div>

                    <form x-show="editing" x-cloak method="post" action="/admin_manage_tags.php" class="space-y-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_activity">
                        <input type="hidden" name="activity_id" value="<?= $actId ?>">
                        <input type="text" name="name" x-model="name" required maxlength="120"
                            placeholder="Name"
                            class="w-full rounded border border-sand px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
                        <input type="text" name="description" x-model="description" maxlength="255"
                            placeholder="Beschreibung (optional)"
                            class="w-full rounded border border-sand px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
                        <div class="flex gap-2">
                            <button type="submit" class="text-xs bg-pfote text-white font-semibold px-3 py-1 rounded-full hover:bg-fell">
                                Speichern
                            </button>
                            <button type="button" @click="editing = false"
                                class="text-xs text-fellDk/60 hover:text-fellDk font-semibold px-3 py-1">
                                Abbrechen
                            </button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
            <?php if (empty($allActivities)): ?>
                <p class="text-sm text-fellDk/50">Noch keine Aktivitäten angelegt.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>