<?php
/**
 * zuechter.php
 *
 * Übersicht aller Züchter — Portal-Prinzip: Admins sehen alle, normale
 * Nutzer nur ihre eigenen (Zuechter::findAllForUser()).
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Zuechter.php';

secure_session_start();
require_login();

$currentUserId = (int) current_user()['id'];
$isAdmin       = is_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_zuechter_id'])) {
    csrf_require_valid();
    $zid      = (int) $_POST['delete_zuechter_id'];
    $zuechter = Zuechter::findByIdForUser($zid, $currentUserId, $isAdmin);
    if ($zuechter !== null) {
        Zuechter::delete($zid);
    }
    header('Location: /zuechter.php');
    exit;
}

$allZuechter = Zuechter::findAllForUser($currentUserId, $isAdmin);
$dogCounts   = Zuechter::getDogCounts($currentUserId, $isAdmin);

/**
 * Baut "Vorname Nachname" aus den beiden optionalen Feldern, ohne
 * doppelte Leerzeichen, falls nur eines gesetzt ist.
 */
function format_ansprechpartner(?string $vorname, ?string $nachname): string
{
    $teile = array_filter([$vorname, $nachname]);
    return implode(' ', $teile) ?: '—';
}

$pageTitle = 'Züchter';
require __DIR__ . '/views/partials/header.php';
?>

<a href="/dashboard.php" class="inline-flex items-center gap-1.5 text-sm text-fellDk/60 hover:text-fellDk mb-4">
    ← Zur Übersicht
</a>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <h1 class="text-2xl font-extrabold text-fellDk"><?= $isAdmin ? 'Alle Züchter' : 'Meine Züchter' ?></h1>
    <a href="/zuechter_form.php" class="bg-tanne hover:bg-tanneDk text-creme font-bold text-sm px-5 py-2.5 rounded-full transition-colors whitespace-nowrap text-center">
        + Züchter anlegen
    </a>
</div>

<?php if (empty($allZuechter)): ?>
    <div class="bg-white/80 rounded-2xl shadow border border-sand">
        <p class="text-center py-16 text-fellDk/50">
            <?= $isAdmin ? 'Es wurden noch keine Züchter angelegt.' : 'Du hast noch keine Züchter angelegt.' ?>
        </p>
    </div>
<?php else: ?>

    <div class="hidden sm:block bg-white/80 rounded-2xl shadow border border-sand overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-sand/30 text-left text-xs font-semibold uppercase tracking-wide text-fellDk/60">
                        <th class="px-4 py-3">Zuchtname</th>
                        <th class="px-4 py-3">Ansprechpartner</th>
                        <th class="px-4 py-3">Telefon</th>
                        <th class="px-4 py-3">E-Mail</th>
                        <th class="px-4 py-3">Hunde</th>
                        <th class="px-4 py-3 text-right">Aktionen</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-sand">
                    <?php foreach ($allZuechter as $zuechter): $zid = (int) $zuechter['id']; ?>
                        <tr class="hover:bg-creme/60 transition-colors">
                            <td class="px-4 py-3 font-semibold">
                                <a href="/zuechter_detail.php?id=<?= $zid ?>" class="flex items-center gap-2.5 text-tanne hover:text-tanneDk hover:underline">
                                    <?php if (!empty($zuechter['hat_bild'])): ?>
                                        <img src="/zuechterbild.php?id=<?= $zid ?>&v=<?= urlencode((string) ($zuechter['bild_updated_at'] ?? '')) ?>"
                                             alt="" class="w-8 h-8 rounded-full object-cover border border-sand shrink-0">
                                    <?php else: ?>
                                        <span class="w-8 h-8 rounded-full bg-creme border border-sand flex items-center justify-center shrink-0 text-sm" aria-hidden="true">🏠</span>
                                    <?php endif; ?>
                                    <?= e($zuechter['zuchtname']) ?>
                                </a>
                            </td>
                            <td class="px-4 py-3 text-fellDk/70"><?= e(format_ansprechpartner($zuechter['ansprechpartner_vorname'], $zuechter['ansprechpartner_nachname'])) ?></td>
                            <td class="px-4 py-3 text-fellDk/70"><?= $zuechter['telefon'] ? e($zuechter['telefon']) : '—' ?></td>
                            <td class="px-4 py-3 text-fellDk/70"><?= $zuechter['email'] ? e($zuechter['email']) : '—' ?></td>
                            <td class="px-4 py-3"><?= (int) ($dogCounts[$zid] ?? 0) ?></td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <a href="/zuechter_form.php?id=<?= $zid ?>" class="text-tanne hover:text-tanneDk font-semibold mr-3">Bearbeiten</a>
                                <form method="post" action="/zuechter.php" class="inline"
                                      onsubmit="return confirm('<?= e($zuechter['zuchtname']) ?> wirklich löschen?<?= ($dogCounts[$zid] ?? 0) > 0 ? ' Zugeordnete Hunde verlieren dann ihren Züchter-Eintrag.' : '' ?>');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="delete_zuechter_id" value="<?= $zid ?>">
                                    <button type="submit" class="text-red-600 hover:text-red-800 font-semibold">Löschen</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="sm:hidden space-y-3">
        <?php foreach ($allZuechter as $zuechter): $zid = (int) $zuechter['id']; ?>
            <div class="bg-white/80 rounded-2xl shadow border border-sand p-4">
                <div class="flex items-start justify-between gap-2">
                    <a href="/zuechter_detail.php?id=<?= $zid ?>" class="flex items-center gap-2.5 font-bold text-tanne hover:text-tanneDk hover:underline text-base">
                        <?php if (!empty($zuechter['hat_bild'])): ?>
                            <img src="/zuechterbild.php?id=<?= $zid ?>&v=<?= urlencode((string) ($zuechter['bild_updated_at'] ?? '')) ?>"
                                 alt="" class="w-9 h-9 rounded-full object-cover border border-sand shrink-0">
                        <?php else: ?>
                            <span class="w-9 h-9 rounded-full bg-creme border border-sand flex items-center justify-center shrink-0" aria-hidden="true">🏠</span>
                        <?php endif; ?>
                        <?= e($zuechter['zuchtname']) ?>
                    </a>
                    <span class="text-xs text-fellDk/50 shrink-0 pt-0.5"><?= (int) ($dogCounts[$zid] ?? 0) ?> Hund(e)</span>
                </div>
                <p class="text-sm text-fellDk/70 mt-1"><?= e(format_ansprechpartner($zuechter['ansprechpartner_vorname'], $zuechter['ansprechpartner_nachname'])) ?></p>
                <p class="text-sm text-fellDk/70"><?= $zuechter['telefon'] ? e($zuechter['telefon']) : '—' ?></p>
                <p class="text-sm text-fellDk/70"><?= $zuechter['email'] ? e($zuechter['email']) : '—' ?></p>

                <div class="flex gap-4 mt-3 pt-3 border-t border-sand">
                    <a href="/zuechter_form.php?id=<?= $zid ?>" class="text-tanne hover:text-tanneDk font-semibold text-sm">Bearbeiten</a>
                    <form method="post" action="/zuechter.php" class="inline"
                          onsubmit="return confirm('<?= e($zuechter['zuchtname']) ?> wirklich löschen?<?= ($dogCounts[$zid] ?? 0) > 0 ? ' Zugeordnete Hunde verlieren dann ihren Züchter-Eintrag.' : '' ?>');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="delete_zuechter_id" value="<?= $zid ?>">
                        <button type="submit" class="text-red-600 hover:text-red-800 font-semibold text-sm">Löschen</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
