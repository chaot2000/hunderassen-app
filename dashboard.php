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
$tenantId      = current_tenant_id();

$alleHunde     = Dog::search('', $currentUserId, $isAdmin, $tenantId);
$alleHalter    = Halter::findAllForUser($currentUserId, $isAdmin, $tenantId);
$alleZuechter  = Zuechter::findAllForUser($currentUserId, $isAdmin, $tenantId);

const DASHBOARD_HUNDE_VORSCHAU = 6;
$hundeVorschau = array_slice($alleHunde, 0, DASHBOARD_HUNDE_VORSCHAU);

$pageTitle = t('nav.uebersicht');
require __DIR__ . '/views/partials/header.php';
?>

<div class="flex items-center gap-3 mb-8">
    <img src="/icons/logo-kopf.png" width="44" height="44" alt="" class="shrink-0">
    <div>
        <h1 class="text-2xl font-extrabold text-fellDk">
            <?= e(t('dash.hallo', ['name' => current_user()['username'] ?? ''])) ?>
        </h1>
        <p class="text-sm text-fellDk/60">
            <?= e(t($isAdmin ? 'dash.intro_admin' : 'dash.intro_user')) ?>
        </p>
    </div>
</div>

<!-- Kennzahlen -->
<div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-8">
    <a href="/dogs.php" class="bg-white/80 rounded-2xl shadow border border-sand p-5 hover:shadow-md hover:border-tanne/40 transition-all">
        <span class="text-3xl" aria-hidden="true">🐕</span>
        <p class="text-2xl font-extrabold text-fellDk mt-2"><?= count($alleHunde) ?></p>
        <p class="text-sm text-fellDk/60"><?= e(t($isAdmin ? 'dash.zahl_hunde_portal' : 'dash.zahl_hunde_meine')) ?></p>
    </a>
    <a href="/halter.php" class="bg-white/80 rounded-2xl shadow border border-sand p-5 hover:shadow-md hover:border-tanne/40 transition-all">
        <span class="text-3xl" aria-hidden="true">🧑</span>
        <p class="text-2xl font-extrabold text-fellDk mt-2"><?= count($alleHalter) ?></p>
        <p class="text-sm text-fellDk/60"><?= e(t($isAdmin ? 'dash.zahl_halter_portal' : 'dash.zahl_halter_meine')) ?></p>
    </a>
    <a href="/zuechter.php" class="bg-white/80 rounded-2xl shadow border border-sand p-5 hover:shadow-md hover:border-tanne/40 transition-all">
        <span class="text-3xl" aria-hidden="true">🏠</span>
        <p class="text-2xl font-extrabold text-fellDk mt-2"><?= count($alleZuechter) ?></p>
        <p class="text-sm text-fellDk/60"><?= e(t($isAdmin ? 'dash.zahl_zuechter_portal' : 'dash.zahl_zuechter_meine')) ?></p>
    </a>
</div>

<!-- Meine Hunde -->
<div class="flex items-center justify-between mb-4">
    <h2 class="text-lg font-extrabold text-fellDk"><?= e(t($isAdmin ? 'dash.neueste_hunde' : 'dash.zahl_hunde_meine')) ?></h2>
    <div class="flex items-center gap-3">
        <a href="/dogs.php" class="text-sm text-tanne hover:text-tanneDk font-semibold hover:underline"><?= e(t('dash.alle_anzeigen')) ?> →</a>
        <a href="/dog_form.php" class="bg-tanne hover:bg-tanneDk text-creme font-bold text-sm px-4 py-2 rounded-full transition-colors whitespace-nowrap">
            + <?= e(t('dash.hund_anlegen')) ?>
        </a>
    </div>
</div>

<?php if (empty($hundeVorschau)): ?>
    <div class="bg-white/80 rounded-2xl shadow border border-sand mb-10">
        <p class="text-center py-12 text-fellDk/50">
            <span class="text-4xl block mb-2" aria-hidden="true">🦴</span>
            <?= e(t($isAdmin ? 'dash.leer_admin' : 'dash.leer_user')) ?>
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
                        <?= $dog['breed_name'] ? e(in_sprache($dog['breed_name'], $dog['breed_name_en'] ?? null)) : e(t('dash.rasse_unbekannt')) ?>
                    </p>
                    <p class="text-xs text-fellDk/50 mt-1">
                        <?= e(t('dash.halter')) ?> <?= $dog['halter_namen'] ? e($dog['halter_namen']) : '—' ?>
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
            <p class="font-bold text-fellDk"><?= e(t('dash.rassen_titel')) ?></p>
            <p class="text-sm text-fellDk/60"><?= e(t('dash.rassen_text')) ?></p>
        </div>
    </a>
    <a href="/zuechter.php" class="flex items-center gap-4 bg-white/80 rounded-2xl shadow border border-sand p-5 hover:shadow-md hover:border-tanne/40 transition-all">
        <span class="text-3xl" aria-hidden="true">🏠</span>
        <div>
            <p class="font-bold text-fellDk"><?= e(t('dash.zuechter_titel')) ?></p>
            <p class="text-sm text-fellDk/60"><?= e(t($isAdmin ? 'dash.zuechter_admin' : 'dash.zuechter_user')) ?></p>
        </div>
    </a>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
