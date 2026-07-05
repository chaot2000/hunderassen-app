<?php
/**
 * zuechter_detail.php
 *
 * Zeigt einen Züchter mit allen Feldern (Zuchtname, Ansprechpartner,
 * Adresse, Kontaktdaten inkl. Webseite als externer Link, Notizen)
 * und allen ihm zugeordneten Hunden. Portal-Prinzip: nur Ersteller
 * oder Admin dürfen sehen.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Zuechter.php';
require_once __DIR__ . '/models/Dog.php';

secure_session_start();
require_login();

$currentUserId = (int) current_user()['id'];
$isAdmin       = is_admin();

$zuechterId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$zuechter   = $zuechterId ? Zuechter::findByIdForUser($zuechterId, $currentUserId, $isAdmin) : null;

if ($zuechter === null) {
    http_response_code(404);
    $pageTitle = 'Nicht gefunden';
    require __DIR__ . '/views/partials/header.php';
    echo '<div class="text-center py-16 text-fellDk/50"><p>Dieser Züchter wurde nicht gefunden.</p>
          <a href="/zuechter.php" class="text-tanne underline">Zurück zur Übersicht</a></div>';
    require __DIR__ . '/views/partials/footer.php';
    exit;
}

$dogs = Dog::findByZuechterForUser($zuechterId, $currentUserId, $isAdmin);

$ansprechpartner = implode(' ', array_filter([
    $zuechter['ansprechpartner_vorname'],
    $zuechter['ansprechpartner_nachname'],
]));

$pageTitle = $zuechter['zuchtname'];
require __DIR__ . '/views/partials/header.php';
?>

<a href="/zuechter.php" class="text-sm text-tanne hover:text-tanneDk underline">&larr; Zurück zur Übersicht</a>

<div class="bg-white/80 rounded-2xl shadow border border-sand p-4 sm:p-6 mt-4 max-w-2xl">
    <div class="flex flex-col sm:flex-row gap-4 items-start">
        <?php if (!empty($zuechter['hat_bild'])): ?>
            <img src="/zuechterbild.php?id=<?= (int) $zuechter['id'] ?>&v=<?= urlencode((string) ($zuechter['bild_updated_at'] ?? '')) ?>"
                 alt="Foto von <?= e($zuechter['zuchtname']) ?>"
                 class="w-24 h-24 sm:w-28 sm:h-28 object-cover rounded-xl border border-sand shrink-0 mx-auto sm:mx-0">
        <?php else: ?>
            <div class="w-24 h-24 sm:w-28 sm:h-28 rounded-xl border border-sand bg-creme flex items-center justify-center shrink-0 mx-auto sm:mx-0" aria-hidden="true">
                <span class="text-3xl text-fellDk/20">🏠</span>
            </div>
        <?php endif; ?>

        <div class="flex-1 w-full">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <h1 class="text-2xl font-extrabold text-fellDk break-words"><?= e($zuechter['zuchtname']) ?></h1>
                <a href="/zuechter_form.php?id=<?= (int) $zuechter['id'] ?>" class="bg-pfote/10 text-fell font-semibold text-sm px-4 py-2 rounded-full hover:bg-pfote/20 transition-colors whitespace-nowrap">
                    Bearbeiten
                </a>
            </div>

            <dl class="mt-4 space-y-2 text-sm">
        <?php if ($ansprechpartner !== ''): ?>
            <div class="flex gap-2">
                <dt class="font-semibold text-fellDk/70 w-32 shrink-0">Ansprechpartner</dt>
                <dd><?= e($ansprechpartner) ?></dd>
            </div>
        <?php endif; ?>
        <div class="flex gap-2">
            <dt class="font-semibold text-fellDk/70 w-32 shrink-0">Adresse</dt>
            <dd><?= $zuechter['adresse'] ? e($zuechter['adresse']) : '—' ?></dd>
        </div>
        <div class="flex gap-2">
            <dt class="font-semibold text-fellDk/70 w-32 shrink-0">Telefon</dt>
            <dd><?= $zuechter['telefon'] ? e($zuechter['telefon']) : '—' ?></dd>
        </div>
        <div class="flex gap-2">
            <dt class="font-semibold text-fellDk/70 w-32 shrink-0">E-Mail</dt>
            <dd><?= $zuechter['email'] ? e($zuechter['email']) : '—' ?></dd>
        </div>
        <div class="flex gap-2">
            <dt class="font-semibold text-fellDk/70 w-32 shrink-0">Webseite</dt>
            <dd>
                <?php if (!empty($zuechter['webseite'])): ?>
                    <a href="<?= e($zuechter['webseite']) ?>" target="_blank" rel="noopener noreferrer"
                       class="text-tanne hover:text-tanneDk underline inline-flex items-center gap-1">
                        <?= e(preg_replace('~^https?://~i', '', $zuechter['webseite'])) ?>
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z" />
                            <path d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 000-2H5z" />
                        </svg>
                    </a>
                <?php else: ?>
                    —
                <?php endif; ?>
            </dd>
        </div>
        <?php if (!empty($zuechter['notizen'])): ?>
            <div class="flex gap-2">
                <dt class="font-semibold text-fellDk/70 w-32 shrink-0">Notizen</dt>
                <dd class="whitespace-pre-line"><?= e($zuechter['notizen']) ?></dd>
            </div>
        <?php endif; ?>
            </dl>
        </div>
    </div>
</div>

<div class="mt-6">
    <h2 class="text-lg font-bold text-fellDk mb-3">
        Hunde von <?= e($zuechter['zuchtname']) ?>
        <span class="text-sm font-normal text-fellDk/50">(<?= count($dogs) ?>)</span>
    </h2>

    <?php if (empty($dogs)): ?>
        <p class="text-fellDk/50 text-sm">Diesem Züchter sind aktuell keine Hunde zugeordnet.</p>
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
