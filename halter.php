<?php
/**
 * halter.php
 *
 * Übersicht aller Halter — Portal-Prinzip: Admins sehen alle, normale
 * Nutzer nur ihre eigenen (Halter::findAllForUser()). Anlegen/
 * Bearbeiten erfolgt auf der eigenen Seite halter_form.php.
 *
 * NICHT mehr admin-only (war früher admin_manage_halter.php) — jeder
 * eingeloggte Nutzer verwaltet hier seine eigenen Halter-Kontakte.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Halter.php';

secure_session_start();
require_login();

$currentUserId = (int) current_user()['id'];
$isAdmin       = is_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_halter_id'])) {
    csrf_require_valid();
    $hid    = (int) $_POST['delete_halter_id'];
    $halter = Halter::findByIdForUser($hid, $currentUserId, $isAdmin);
    if ($halter !== null) {
        Halter::delete($hid);
    }
    header('Location: /halter.php');
    exit;
}

$allHalter = Halter::findAllForUser($currentUserId, $isAdmin);
$dogCounts = Halter::getDogCounts($currentUserId, $isAdmin);

$pageTitle = 'Halter';
require __DIR__ . '/views/partials/header.php';
?>

<a href="/dashboard.php" class="inline-flex items-center gap-1.5 text-sm text-fellDk/60 hover:text-fellDk mb-4">
    ← Zur Übersicht
</a>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <h1 class="text-2xl font-extrabold text-fellDk"><?= $isAdmin ? 'Alle Halter' : 'Meine Halter' ?></h1>
    <a href="/halter_form.php" class="bg-tanne hover:bg-tanneDk text-creme font-bold text-sm px-5 py-2.5 rounded-full transition-colors whitespace-nowrap text-center">
        + Halter anlegen
    </a>
</div>

<?php if (empty($allHalter)): ?>
    <div class="bg-white/80 rounded-2xl shadow border border-sand">
        <p class="text-center py-16 text-fellDk/50">
            <?= $isAdmin ? 'Es wurden noch keine Halter angelegt.' : 'Du hast noch keine Halter angelegt.' ?>
        </p>
    </div>
<?php else: ?>

    <!-- Tabelle ab sm (>=640px) -->
    <div class="hidden sm:block bg-white/80 rounded-2xl shadow border border-sand overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-sand/30 text-left text-xs font-semibold uppercase tracking-wide text-fellDk/60">
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Telefon</th>
                        <th class="px-4 py-3">E-Mail</th>
                        <th class="px-4 py-3">Hunde</th>
                        <th class="px-4 py-3 text-right">Aktionen</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-sand">
                    <?php foreach ($allHalter as $halter): $hid = (int) $halter['id']; ?>
                        <tr class="hover:bg-creme/60 transition-colors">
                            <td class="px-4 py-3 font-semibold">
                                <a href="/halter_detail.php?id=<?= $hid ?>" class="flex items-center gap-2.5 text-tanne hover:text-tanneDk hover:underline">
                                    <?php if (!empty($halter['hat_bild'])): ?>
                                        <img src="/halterbild.php?id=<?= $hid ?>&v=<?= urlencode((string) ($halter['bild_updated_at'] ?? '')) ?>"
                                             alt="" class="w-8 h-8 rounded-full object-cover border border-sand shrink-0">
                                    <?php else: ?>
                                        <span class="w-8 h-8 rounded-full bg-creme border border-sand flex items-center justify-center shrink-0 text-sm" aria-hidden="true">🧑</span>
                                    <?php endif; ?>
                                    <?= e($halter['name']) ?>
                                </a>
                            </td>
                            <td class="px-4 py-3 text-fellDk/70"><?= $halter['telefon'] ? e($halter['telefon']) : '—' ?></td>
                            <td class="px-4 py-3 text-fellDk/70"><?= $halter['email'] ? e($halter['email']) : '—' ?></td>
                            <td class="px-4 py-3"><?= (int) ($dogCounts[$hid] ?? 0) ?></td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <a href="/halter_form.php?id=<?= $hid ?>" class="text-tanne hover:text-tanneDk font-semibold mr-3">Bearbeiten</a>
                                <form method="post" action="/halter.php" class="inline"
                                      onsubmit="return confirm('<?= e($halter['name']) ?> wirklich löschen?<?= ($dogCounts[$hid] ?? 0) > 0 ? ' Zugeordnete Hunde verlieren dann ihren Halter-Eintrag.' : '' ?>');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="delete_halter_id" value="<?= $hid ?>">
                                    <button type="submit" class="text-red-600 hover:text-red-800 font-semibold">Löschen</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Karten-Liste unter sm (<640px) -->
    <div class="sm:hidden space-y-3">
        <?php foreach ($allHalter as $halter): $hid = (int) $halter['id']; ?>
            <div class="bg-white/80 rounded-2xl shadow border border-sand p-4">
                <div class="flex items-start justify-between gap-2">
                    <a href="/halter_detail.php?id=<?= $hid ?>" class="flex items-center gap-2.5 font-bold text-tanne hover:text-tanneDk hover:underline text-base">
                        <?php if (!empty($halter['hat_bild'])): ?>
                            <img src="/halterbild.php?id=<?= $hid ?>&v=<?= urlencode((string) ($halter['bild_updated_at'] ?? '')) ?>"
                                 alt="" class="w-9 h-9 rounded-full object-cover border border-sand shrink-0">
                        <?php else: ?>
                            <span class="w-9 h-9 rounded-full bg-creme border border-sand flex items-center justify-center shrink-0" aria-hidden="true">🧑</span>
                        <?php endif; ?>
                        <?= e($halter['name']) ?>
                    </a>
                    <span class="text-xs text-fellDk/50 shrink-0 pt-0.5"><?= (int) ($dogCounts[$hid] ?? 0) ?> Hund(e)</span>
                </div>
                <p class="text-sm text-fellDk/70 mt-1"><?= $halter['telefon'] ? e($halter['telefon']) : '—' ?></p>
                <p class="text-sm text-fellDk/70"><?= $halter['email'] ? e($halter['email']) : '—' ?></p>

                <div class="flex gap-4 mt-3 pt-3 border-t border-sand">
                    <a href="/halter_form.php?id=<?= $hid ?>" class="text-tanne hover:text-tanneDk font-semibold text-sm">Bearbeiten</a>
                    <form method="post" action="/halter.php" class="inline"
                          onsubmit="return confirm('<?= e($halter['name']) ?> wirklich löschen?<?= ($dogCounts[$hid] ?? 0) > 0 ? ' Zugeordnete Hunde verlieren dann ihren Halter-Eintrag.' : '' ?>');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="delete_halter_id" value="<?= $hid ?>">
                        <button type="submit" class="text-red-600 hover:text-red-800 font-semibold text-sm">Löschen</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
