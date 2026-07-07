<?php
/**
 * dogs.php
 *
 * Portal-Prinzip: Admins sehen alle Hunde, normale Nutzer ausschließlich
 * ihre eigenen (Dog::search() erzwingt das Scoping selbst, siehe
 * models/Dog.php — kein Toggle/Query-Parameter kann das umgehen).
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Dog.php';

secure_session_start();
require_login();

$currentUserId = (int) current_user()['id'];
$isAdmin       = is_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_dog_id'])) {
    csrf_require_valid();

    $dogId = (int) $_POST['delete_dog_id'];
    $dog   = Dog::findByIdForUser($dogId, $currentUserId, $isAdmin);

    if ($dog !== null) {
        Dog::delete($dogId);
    }

    header('Location: /dogs.php');
    exit;
}

$searchTerm = clean_input($_GET['q'] ?? '');
$dogs       = Dog::search($searchTerm, $currentUserId, $isAdmin);

$pageTitle = $isAdmin ? 'Alle Hunde' : 'Meine Hunde';
require __DIR__ . '/views/partials/header.php';
?>

<a href="/dashboard.php" class="inline-flex items-center gap-1.5 text-sm text-fellDk/60 hover:text-fellDk mb-4">
    ← Zur Übersicht
</a>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <h1 class="text-2xl font-extrabold text-fellDk">
        <?= $isAdmin ? 'Alle Hunde' : 'Meine Hunde' ?>
    </h1>
    <a href="/dog_form.php" class="bg-tanne hover:bg-tanneDk text-creme font-bold text-sm px-5 py-2.5 rounded-full transition-colors whitespace-nowrap text-center">
        + Hund anlegen
    </a>
</div>

<div class="bg-white/80 rounded-2xl shadow border border-sand p-4 mb-6">
    <form method="get" action="/dogs.php" class="flex flex-col sm:flex-row gap-3 items-stretch sm:items-center">
        <input type="text" name="q" value="<?= e($searchTerm) ?>" placeholder="Hund suchen…"
            class="flex-1 rounded-lg border border-sand px-3 py-2.5 text-base sm:text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
        <div class="flex gap-2">
            <button type="submit" class="flex-1 sm:flex-none bg-pfote hover:bg-fell text-white font-semibold text-sm px-4 py-2.5 rounded-full transition-colors">
                Suchen
            </button>
            <?php if ($searchTerm !== ''): ?>
                <a href="/dogs.php" class="text-sm text-fellDk/60 self-center hover:text-fellDk">Zurücksetzen</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if (empty($dogs)): ?>
    <p class="text-center py-16 text-fellDk/50">
        <?= $isAdmin ? 'Es wurden noch keine Hunde angelegt.' : 'Du hast noch keine Hunde angelegt.' ?>
    </p>
<?php else: ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($dogs as $dog): ?>
            <a href="/dog_detail.php?id=<?= (int) $dog['id'] ?>" class="block bg-white/80 rounded-2xl shadow border border-sand overflow-hidden hover:shadow-md transition-shadow">
                <?php if (!empty($dog['hat_bild'])): ?>
                    <img
                        src="/hundebild.php?id=<?= (int) $dog['id'] ?>&v=<?= urlencode((string) ($dog['bild_updated_at'] ?? '')) ?>"
                        alt="<?= e($dog['name']) ?>"
                        class="w-full h-40 object-cover"
                    >
                <?php else: ?>
                    <div class="w-full h-40 bg-creme flex items-center justify-center" aria-hidden="true">
                        <span class="text-4xl text-fellDk/20">🐾</span>
                    </div>
                <?php endif; ?>

                <div class="p-5">
                    <div class="flex items-start justify-between gap-2">
                        <h2 class="font-bold text-fellDk text-lg"><?= e($dog['name']) ?></h2>
                        <?php if ($isAdmin && (int) $dog['created_by'] !== $currentUserId): ?>
                            <span class="text-xs text-fellDk/40 shrink-0 pt-1">fremder Eintrag</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-sm text-fellDk/70 mt-1">
                        <?= $dog['breed_name'] ? e($dog['breed_name']) : 'Rasse unbekannt' ?>
                    </p>
                    <?php if (!empty($dog['geburtsdatum'])): ?>
                        <p class="text-xs text-fellDk/50 mt-2">geboren am <?= e(date('d.m.Y', strtotime($dog['geburtsdatum']))) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($dog['farbe'])): ?>
                        <p class="text-xs text-fellDk/50">Farbe: <?= e($dog['farbe']) ?></p>
                    <?php endif; ?>
                    <p class="text-xs text-fellDk/50 mt-1">
                        Halter: <?= $dog['halter_namen'] ? e($dog['halter_namen']) : '—' ?>
                    </p>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
