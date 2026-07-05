<?php
/**
 * dashboard.php
 *
 * Startseite nach dem Login. Bewusst hund-/halterzentriert statt
 * rassenzentriert: der Nutzer sieht zuerst seine eigenen Hunde und
 * Halter (Admins: aggregierte Zahlen über alle Nutzer), nicht den
 * geteilten Rassen-Katalog — der lebt jetzt eigenständig unter
 * rassen.php (siehe Header-Navigation "Rassen").
 *
 * Portal-Scoping: nutzt ausschließlich die bereits vorhandenen,
 * user-scoped Model-Methoden (Dog::search(), Halter::findAllForUser(),
 * Zuechter::findAllForUser()) — keine eigene SQL-Logik hier, damit die
 * Sichtbarkeitsregeln an genau einer Stelle (den Models) durchgesetzt
 * bleiben.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Dog.php';
require_once __DIR__ . '/models/Halter.php';
require_once __DIR__ . '/models/Zuechter.php';

secure_session_start();
require_login();

$currentUserId = (int) current_user()['id'];
$isAdmin       = is_admin();

$alleHunde     = Dog::search('', $currentUserId, $isAdmin);
$alleHalter    = Halter::findAllForUser($currentUserId, $isAdmin);
$alleZuechter  = Zuechter::findAllForUser($currentUserId, $isAdmin);

const DASHBOARD_HUNDE_VORSCHAU = 6;
$hundeVorschau = array_slice($alleHunde, 0, DASHBOARD_HUNDE_VORSCHAU);

$pageTitle = 'Übersicht';
require __DIR__ . '/views/partials/header.php';
?>

<div class="flex items-center gap-3 mb-8">
    <?= render_dog_icon(44, '#2C4130', '#3F5A3F') ?>
    <div>
        <h1 class="text-2xl font-extrabold text-fellDk">
            Hallo, <?= e(current_user()['username'] ?? '') ?>!
        </h1>
        <p class="text-sm text-fellDk/60">
            <?= $isAdmin ? 'Hier ist die Übersicht über das gesamte Portal.' : 'Hier ist deine persönliche Übersicht.' ?>
        </p>
    </div>
</div>

<!-- Kennzahlen -->
<div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-8">
    <a href="/dogs.php" class="bg-white/80 rounded-2xl shadow border border-sand p-5 hover:shadow-md hover:border-tanne/40 transition-all">
        <span class="text-3xl" aria-hidden="true">🐕</span>
        <p class="text-2xl font-extrabold text-fellDk mt-2"><?= count($alleHunde) ?></p>
        <p class="text-sm text-fellDk/60"><?= $isAdmin ? 'Hunde im Portal' : 'Meine Hunde' ?></p>
    </a>
    <a href="/halter.php" class="bg-white/80 rounded-2xl shadow border border-sand p-5 hover:shadow-md hover:border-tanne/40 transition-all">
        <span class="text-3xl" aria-hidden="true">🧑</span>
        <p class="text-2xl font-extrabold text-fellDk mt-2"><?= count($alleHalter) ?></p>
        <p class="text-sm text-fellDk/60"><?= $isAdmin ? 'Halter im Portal' : 'Meine Halter' ?></p>
    </a>
    <a href="/zuechter.php" class="bg-white/80 rounded-2xl shadow border border-sand p-5 hover:shadow-md hover:border-tanne/40 transition-all">
        <span class="text-3xl" aria-hidden="true">🏠</span>
        <p class="text-2xl font-extrabold text-fellDk mt-2"><?= count($alleZuechter) ?></p>
        <p class="text-sm text-fellDk/60"><?= $isAdmin ? 'Züchter im Portal' : 'Meine Züchter' ?></p>
    </a>
</div>

<!-- Meine Hunde -->
<div class="flex items-center justify-between mb-4">
    <h2 class="text-lg font-extrabold text-fellDk"><?= $isAdmin ? 'Neueste Hunde' : 'Meine Hunde' ?></h2>
    <div class="flex items-center gap-3">
        <a href="/dogs.php" class="text-sm text-tanne hover:text-tanneDk font-semibold hover:underline">Alle anzeigen →</a>
        <a href="/dog_form.php" class="bg-tanne hover:bg-tanneDk text-creme font-bold text-sm px-4 py-2 rounded-full transition-colors whitespace-nowrap">
            + Hund anlegen
        </a>
    </div>
</div>

<?php if (empty($hundeVorschau)): ?>
    <div class="bg-white/80 rounded-2xl shadow border border-sand mb-10">
        <p class="text-center py-12 text-fellDk/50">
            <span class="text-4xl block mb-2" aria-hidden="true">🦴</span>
            <?= $isAdmin ? 'Es wurden noch keine Hunde angelegt.' : 'Du hast noch keine Hunde angelegt.' ?>
        </p>
    </div>
<?php else: ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-10">
        <?php foreach ($hundeVorschau as $dog): ?>
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
                    <h3 class="font-bold text-fellDk"><?= e($dog['name']) ?></h3>
                    <p class="text-sm text-fellDk/70 mt-0.5">
                        <?= $dog['breed_name'] ? e($dog['breed_name']) : 'Rasse unbekannt' ?>
                    </p>
                    <p class="text-xs text-fellDk/50 mt-1">
                        Halter: <?= $dog['halter_namen'] ? e($dog['halter_namen']) : '—' ?>
                    </p>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Schnellzugriffe -->
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <a href="/rassen.php" class="flex items-center gap-4 bg-white/80 rounded-2xl shadow border border-sand p-5 hover:shadow-md hover:border-tanne/40 transition-all">
        <span class="text-3xl" aria-hidden="true">🔍</span>
        <div>
            <p class="font-bold text-fellDk">Rassen durchsuchen</p>
            <p class="text-sm text-fellDk/60">Den geteilten Rassen-Katalog stöbern und filtern.</p>
        </div>
    </a>
    <a href="/zuechter.php" class="flex items-center gap-4 bg-white/80 rounded-2xl shadow border border-sand p-5 hover:shadow-md hover:border-tanne/40 transition-all">
        <span class="text-3xl" aria-hidden="true">🏠</span>
        <div>
            <p class="font-bold text-fellDk">Züchter verwalten</p>
            <p class="text-sm text-fellDk/60"><?= $isAdmin ? 'Alle Züchter-Kontakte im Portal.' : 'Deine Züchter-Kontakte.' ?></p>
        </div>
    </a>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
