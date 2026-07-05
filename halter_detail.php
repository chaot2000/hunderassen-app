<?php
/**
 * halter_detail.php
 *
 * Zeigt einen Halter mit Kontaktdaten und allen ihm zugeordneten
 * Hunden. Portal-Prinzip: nur Ersteller oder Admin dürfen den
 * Datensatz sehen (Halter::findByIdForUser()); die zugehörigen Hunde
 * werden zusätzlich per Dog::findByHalterForUser() gescoped.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Halter.php';
require_once __DIR__ . '/models/Dog.php';

secure_session_start();
require_login();

$currentUserId = (int) current_user()['id'];
$isAdmin       = is_admin();

$halterId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$halter   = $halterId ? Halter::findByIdForUser($halterId, $currentUserId, $isAdmin) : null;

if ($halter === null) {
    http_response_code(404);
    $pageTitle = 'Nicht gefunden';
    require __DIR__ . '/views/partials/header.php';
    echo '<div class="text-center py-16 text-fellDk/50"><p>Dieser Halter wurde nicht gefunden.</p>
          <a href="/halter.php" class="text-tanne underline">Zurück zur Übersicht</a></div>';
    require __DIR__ . '/views/partials/footer.php';
    exit;
}

$dogs = Dog::findByHalterForUser($halterId, $currentUserId, $isAdmin);

$pageTitle = $halter['name'];
require __DIR__ . '/views/partials/header.php';
?>

<a href="/halter.php" class="text-sm text-tanne hover:text-tanneDk underline">&larr; Zurück zur Übersicht</a>

<div class="bg-white/80 rounded-2xl shadow border border-sand p-4 sm:p-6 mt-4 max-w-2xl">
    <div class="flex flex-col sm:flex-row gap-4 items-start">
        <?php if (!empty($halter['hat_bild'])): ?>
            <img src="/halterbild.php?id=<?= (int) $halter['id'] ?>&v=<?= urlencode((string) ($halter['bild_updated_at'] ?? '')) ?>"
                 alt="Foto von <?= e($halter['name']) ?>"
                 class="w-24 h-24 sm:w-28 sm:h-28 object-cover rounded-xl border border-sand shrink-0 mx-auto sm:mx-0">
        <?php else: ?>
            <div class="w-24 h-24 sm:w-28 sm:h-28 rounded-xl border border-sand bg-creme flex items-center justify-center shrink-0 mx-auto sm:mx-0" aria-hidden="true">
                <span class="text-3xl text-fellDk/20">🧑</span>
            </div>
        <?php endif; ?>

        <div class="flex-1 w-full">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <h1 class="text-2xl font-extrabold text-fellDk break-words"><?= e($halter['name']) ?></h1>
                <a href="/halter_form.php?id=<?= (int) $halter['id'] ?>" class="bg-pfote/10 text-fell font-semibold text-sm px-4 py-2 rounded-full hover:bg-pfote/20 transition-colors whitespace-nowrap">
                    Bearbeiten
                </a>
            </div>

            <dl class="mt-4 space-y-2 text-sm">
                <div class="flex gap-2">
                    <dt class="font-semibold text-fellDk/70 w-28 shrink-0">Telefon</dt>
                    <dd><?= $halter['telefon'] ? e($halter['telefon']) : '—' ?></dd>
                </div>
                <div class="flex gap-2">
                    <dt class="font-semibold text-fellDk/70 w-28 shrink-0">E-Mail</dt>
                    <dd><?= $halter['email'] ? e($halter['email']) : '—' ?></dd>
                </div>
                <div class="flex gap-2">
                    <dt class="font-semibold text-fellDk/70 w-28 shrink-0">Adresse</dt>
                    <dd><?= $halter['adresse'] ? e($halter['adresse']) : '—' ?></dd>
                </div>
                <?php if (!empty($halter['notizen'])): ?>
                    <div class="flex gap-2">
                        <dt class="font-semibold text-fellDk/70 w-28 shrink-0">Notizen</dt>
                        <dd class="whitespace-pre-line"><?= e($halter['notizen']) ?></dd>
                    </div>
                <?php endif; ?>
            </dl>
        </div>
    </div>
</div>

<div class="mt-6">
    <h2 class="text-lg font-bold text-fellDk mb-3">
        Hunde von <?= e($halter['name']) ?>
        <span class="text-sm font-normal text-fellDk/50">(<?= count($dogs) ?>)</span>
    </h2>

    <?php if (empty($dogs)): ?>
        <p class="text-fellDk/50 text-sm">Diesem Halter sind aktuell keine Hunde zugeordnet.</p>
    <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php foreach ($dogs as $dog): ?>
                <a href="/dog_detail.php?id=<?= (int) $dog['id'] ?>" class="block bg-white/80 rounded-2xl shadow border border-sand overflow-hidden hover:shadow-md transition-shadow">
                    <?php if (!empty($dog['hat_bild'])): ?>
                        <img
                            src="/hundebild.php?id=<?= (int) $dog['id'] ?>&v=<?= urlencode((string) ($dog['bild_updated_at'] ?? '')) ?>"
                            alt="<?= e($dog['name']) ?>"
                            class="w-full h-32 object-cover"
                        >
                    <?php else: ?>
                        <div class="w-full h-32 bg-creme flex items-center justify-center" aria-hidden="true">
                            <span class="text-3xl text-fellDk/20">🐾</span>
                        </div>
                    <?php endif; ?>
                    <div class="p-4">
                        <p class="font-bold text-fellDk"><?= e($dog['name']) ?></p>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
