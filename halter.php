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
$tenantId      = current_tenant_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_halter_id'])) {
    csrf_require_valid();
    $hid    = (int) $_POST['delete_halter_id'];
    $halter = Halter::findByIdForUser($hid, $currentUserId, $isAdmin, $tenantId);
    if ($halter !== null) {
        Halter::delete($hid, $tenantId);
    }
    header('Location: /halter.php');
    exit;
}

$allHalter = Halter::findAllForUser($currentUserId, $isAdmin, $tenantId);
$dogCounts = Halter::getDogCounts($currentUserId, $isAdmin, $tenantId);

$pageTitle = t('halter.titel');
require __DIR__ . '/views/partials/header.php';
?>

<a href="/dashboard.php" class="inline-flex items-center gap-1.5 text-sm text-fellDk/60 hover:text-fellDk mb-4">
    ← <?= e(t('allg.zur_uebersicht')) ?>
</a>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <h1 class="text-2xl font-extrabold text-fellDk"><?= e(t($isAdmin ? 'halter.alle' : 'halter.meine')) ?></h1>
    <a href="/halter_form.php" class="bg-tanne hover:bg-tanneDk text-creme font-bold text-sm px-5 py-2.5 rounded-full transition-colors whitespace-nowrap text-center">
        + <?= e(t('halter.anlegen')) ?>
    </a>
</div>

<?php if (empty($allHalter)): ?>
    <div class="bg-white/80 rounded-2xl shadow border border-sand">
        <p class="text-center py-16 text-fellDk/50">
            <?= e(t($isAdmin ? 'halter.leer_admin' : 'halter.leer_user')) ?>
        </p>
    </div>
<?php else: ?>

    <!-- Tabelle ab sm (>=640px) -->
    <div class="hidden sm:block bg-white/80 rounded-2xl shadow border border-sand overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-sand/30 text-left text-xs font-semibold uppercase tracking-wide text-fellDk/60">
                        <th class="px-4 py-3"><?= e(t('allg.name')) ?></th>
                        <th class="px-4 py-3"><?= e(t('allg.telefon')) ?></th>
                        <th class="px-4 py-3"><?= e(t('allg.email')) ?></th>
                        <th class="px-4 py-3"><?= e(t('allg.hunde')) ?></th>
                        <th class="px-4 py-3 text-right"><?= e(t('allg.aktionen')) ?></th>
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
                                <a href="/halter_form.php?id=<?= $hid ?>" class="text-tanne hover:text-tanneDk font-semibold mr-3"><?= e(t('allg.bearbeiten')) ?></a>
                                <form method="post" action="/halter.php" class="inline"
                                      onsubmit="return confirm(<?= e(json_encode(t('allg.loeschen_frage', ['name' => $halter['name']]) . (($dogCounts[$hid] ?? 0) > 0 ? ' ' . t('halter.loeschen_hunde') : ''), JSON_UNESCAPED_UNICODE)) ?>);">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="delete_halter_id" value="<?= $hid ?>">
                                    <button type="submit" class="text-red-600 hover:text-red-800 font-semibold"><?= e(t('allg.loeschen')) ?></button>
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
                    <span class="text-xs text-fellDk/50 shrink-0 pt-0.5"><?= (int) ($dogCounts[$hid] ?? 0) ?> <?= e(t('allg.hund_e')) ?></span>
                </div>
                <p class="text-sm text-fellDk/70 mt-1"><?= $halter['telefon'] ? e($halter['telefon']) : '—' ?></p>
                <p class="text-sm text-fellDk/70"><?= $halter['email'] ? e($halter['email']) : '—' ?></p>

                <div class="flex gap-4 mt-3 pt-3 border-t border-sand">
                    <a href="/halter_form.php?id=<?= $hid ?>" class="text-tanne hover:text-tanneDk font-semibold text-sm"><?= e(t('allg.bearbeiten')) ?></a>
                    <form method="post" action="/halter.php" class="inline"
                          onsubmit="return confirm(<?= e(json_encode(t('allg.loeschen_frage', ['name' => $halter['name']]) . (($dogCounts[$hid] ?? 0) > 0 ? ' ' . t('halter.loeschen_hunde') : ''), JSON_UNESCAPED_UNICODE)) ?>);">
                        <?= csrf_field() ?>
                        <input type="hidden" name="delete_halter_id" value="<?= $hid ?>">
                        <button type="submit" class="text-red-600 hover:text-red-800 font-semibold text-sm"><?= e(t('allg.loeschen')) ?></button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
