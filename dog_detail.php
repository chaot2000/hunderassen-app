<?php
/**
 * dog_detail.php
 *
 * Detailansicht eines einzelnen Hundes. Portal-Prinzip: nur Ersteller
 * oder Admin dürfen den Datensatz überhaupt SEHEN (nicht nur
 * bearbeiten) — Dog::findByIdForUser() liefert sonst null, was hier
 * zu 404 führt (bewusst 404 statt 403, um nicht zu verraten, dass
 * unter dieser ID überhaupt ein fremder Datensatz existiert).
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Dog.php';

secure_session_start();
require_login();

$currentUserId = (int) current_user()['id'];
$isAdmin       = is_admin();

$dogId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$dog   = $dogId ? Dog::findByIdForUser($dogId, $currentUserId, $isAdmin) : null;

if ($dog === null) {
    http_response_code(404);
    $pageTitle = 'Nicht gefunden';
    require __DIR__ . '/views/partials/header.php';
    echo '<div class="text-center py-16 text-fellDk/50"><p>Dieser Hund wurde nicht gefunden.</p>
          <a href="/dogs.php" class="text-tanne underline">Zurück zur Übersicht</a></div>';
    require __DIR__ . '/views/partials/footer.php';
    exit;
}

// Auf Portal-Ebene ist "sehen" bereits gleichbedeutend mit "eigen oder
// Admin" (siehe oben) — Bearbeiten/Löschen ist daher hier immer erlaubt.
$canEdit = true;

$pageTitle = $dog['name'];
require __DIR__ . '/views/partials/header.php';
?>

<a href="/dogs.php" class="text-sm text-tanne hover:text-tanneDk underline">&larr; Zurück zur Übersicht</a>

<div class="bg-white/80 rounded-2xl shadow border border-sand p-4 sm:p-6 mt-4 max-w-2xl">
    <div class="flex flex-col sm:flex-row gap-6">
        <?php if (!empty($dog['hat_bild'])): ?>
            <img
                src="/hundebild.php?id=<?= (int) $dog['id'] ?>&v=<?= urlencode((string) ($dog['bild_updated_at'] ?? '')) ?>"
                alt="<?= e($dog['name']) ?>"
                class="w-full sm:w-56 h-56 object-cover rounded-xl border border-sand shrink-0"
            >
        <?php else: ?>
            <div class="w-full sm:w-56 h-56 rounded-xl border border-sand bg-creme flex items-center justify-center shrink-0" aria-hidden="true">
                <span class="text-5xl text-fellDk/20">🐾</span>
            </div>
        <?php endif; ?>

        <div class="flex-1 min-w-0">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <h1 class="text-2xl font-extrabold text-fellDk break-words"><?= e($dog['name']) ?></h1>
                <?php if ($canEdit): ?>
                    <div class="flex gap-2 shrink-0">
                        <a href="/dog_form.php?id=<?= (int) $dog['id'] ?>" class="bg-pfote/10 text-fell font-semibold text-sm px-4 py-2 rounded-full hover:bg-pfote/20 transition-colors whitespace-nowrap">
                            Bearbeiten
                        </a>
                        <form method="post" action="/dogs.php" onsubmit="return confirm('Diesen Hund wirklich löschen?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="delete_dog_id" value="<?= (int) $dog['id'] ?>">
                            <button type="submit" class="bg-red-50 text-red-700 font-semibold text-sm px-4 py-2 rounded-full hover:bg-red-100 transition-colors whitespace-nowrap">
                                Löschen
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($isAdmin && (int) $dog['created_by'] !== $currentUserId): ?>
                <p class="text-xs text-fellDk/40 mt-1">Eintrag eines anderen Nutzers (Admin-Ansicht)</p>
            <?php endif; ?>

            <dl class="mt-4 space-y-2 text-sm">
                <div class="flex gap-2">
                    <dt class="font-semibold text-fellDk/70 w-32 shrink-0">Rasse</dt>
                    <dd>
                        <?php if ($dog['breed_id']): ?>
                            <a href="/breed_detail.php?id=<?= (int) $dog['breed_id'] ?>" class="text-tanne hover:text-tanneDk underline">
                                <?= e($dog['breed_name']) ?>
                            </a>
                        <?php else: ?>
                            <span class="text-fellDk/50">unbekannt / Mischling</span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="flex gap-2">
                    <dt class="font-semibold text-fellDk/70 w-32 shrink-0">Geburtsdatum</dt>
                    <dd><?= !empty($dog['geburtsdatum']) ? e(date('d.m.Y', strtotime($dog['geburtsdatum']))) : '—' ?></dd>
                </div>
                <div class="flex gap-2">
                    <dt class="font-semibold text-fellDk/70 w-32 shrink-0">Farbe</dt>
                    <dd><?= !empty($dog['farbe']) ? e($dog['farbe']) : '—' ?></dd>
                </div>
                <div class="flex gap-2">
                    <dt class="font-semibold text-fellDk/70 w-32 shrink-0">Halter</dt>
                    <dd>
                        <?php if (!empty($dog['halters'])): ?>
                            <ul class="space-y-0.5">
                                <?php foreach ($dog['halters'] as $h): ?>
                                    <li>
                                        <a href="/halter_detail.php?id=<?= (int) $h['id'] ?>" class="text-tanne hover:text-tanneDk hover:underline"><?= e($h['name']) ?></a>
                                        <?php if (!empty($h['telefon']) || !empty($h['email'])): ?>
                                            <span class="text-fellDk/50 text-xs">
                                                (<?= implode(' · ', array_filter([
                                                    $h['telefon'] ? e($h['telefon']) : null,
                                                    $h['email'] ? e($h['email']) : null,
                                                ])) ?>)
                                            </span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="flex gap-2">
                    <dt class="font-semibold text-fellDk/70 w-32 shrink-0">Züchter</dt>
                    <dd>
                        <?php if ($dog['zuechter_name']): ?>
                            <a href="/zuechter_detail.php?id=<?= (int) $dog['zuechter_id'] ?>" class="text-tanne hover:text-tanneDk hover:underline"><?= e($dog['zuechter_name']) ?></a>
                            <?php if (!empty($dog['zuechter_telefon']) || !empty($dog['zuechter_email'])): ?>
                                <span class="text-fellDk/50">
                                    (<?= implode(' · ', array_filter([
                                        $dog['zuechter_telefon'] ? e($dog['zuechter_telefon']) : null,
                                        $dog['zuechter_email'] ? e($dog['zuechter_email']) : null,
                                    ])) ?>)
                                </span>
                            <?php endif; ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </dd>
                </div>
            </dl>

            <?php if ($dog['breed_id']): ?>
                <div class="mt-6">
                    <h2 class="font-bold text-fellDk mb-2">Wichtigste Eigenschaften der Rasse</h2>
                    <?php if (empty($dog['top_tags'])): ?>
                        <p class="text-sm text-fellDk/50">Für diese Rasse sind noch keine Eigenschaften hinterlegt.</p>
                    <?php else: ?>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($dog['top_tags'] as $tag): ?>
                                <span class="text-sm bg-pfote/10 text-fell px-3 py-1 rounded-full"><?= e($tag['name']) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
