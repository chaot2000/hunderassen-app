<?php
/**
 * admin_datenpruefung.php
 *
 * Werkzeuge für die Fehlersuche (Issue #26). Nur lesend.
 *
 * - Reiter „Rassenkatalog“ (nur Super-Admin): Rassen ohne Bild, fehlende
 *   Merkmale und Texte, auffällige Namen, mögliche Doppel, unplausible
 *   Werte, Vollständigkeit je Merkmal.
 * - Reiter „Meine Organisation“ (alle Admins): Hunde, Halter und Züchter
 *   der eigenen Organisation mit fehlenden Angaben.
 *
 * Jede Liste verlinkt auf die Bearbeiten-Seite und lässt sich als CSV
 * (Semikolon, UTF-8 mit BOM für Excel) herunterladen. Logik in
 * models/Datenpruefung.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Datenpruefung.php';
require_once __DIR__ . '/models/RassenDaten.php';

secure_session_start();
require_admin();

$istSuperAdmin = is_super_admin();
$tenantId      = current_tenant_id();

$bereich = ($_GET['bereich'] ?? '') === 'organisation' || !$istSuperAdmin ? 'organisation' : 'katalog';
if (($_GET['bereich'] ?? '') === 'katalog' && !$istSuperAdmin) {
    http_response_code(403);
    die(e(t('dp.nur_super_admin')));
}

$liste      = $bereich === 'katalog' ? Datenpruefung::KATALOG : Datenpruefung::ORGANISATION;
$pruefung   = (string) ($_GET['pruefung'] ?? '');
$pruefung   = isset($liste[$pruefung]) ? $pruefung : null;
$merkmale   = $bereich === 'katalog' ? Datenpruefung::merkmalNamen() : [];
$merkmal    = (string) ($_GET['merkmal'] ?? 'schulterhoehe');
$merkmal    = isset($merkmale[$merkmal]) ? $merkmal : 'schulterhoehe';

// Merkmale aller Rassen wie im Katalog einmalig übernehmen, sonst fehlen sie hier fälschlich.
if ($bereich === 'katalog'
    && (int) Database::getConnection()->query('SELECT COUNT(*) FROM breeds WHERE daten_stand IS NULL')->fetchColumn() > 0) {
    RassenDaten::alleUebernehmen();
}

$ergebnis = function (string $p) use ($bereich, $tenantId, $merkmal): array {
    return $bereich === 'katalog'
        ? Datenpruefung::katalog($p, $merkmal)
        : Datenpruefung::organisation($p, $tenantId);
};

// CSV-Export der gewählten Liste
if ($pruefung !== null && isset($_GET['csv'])) {
    $zeilen = $ergebnis($pruefung);
    $datei = 'datenpruefung_' . $bereich . '_' . $pruefung . ($pruefung === 'merkmal' ? '_' . $merkmal : '') . '_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $datei . '"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [t('dp.spalte_id'), t('dp.spalte_name'), t('dp.spalte_problem'), t('dp.spalte_link')], ';', '"', '');
    $basis = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
    foreach ($zeilen as $z) {
        // Führende =, +, -, @ würde Excel als Formel lesen
        $name = preg_match('/^[=+\-@]/', $z['name']) ? "'" . $z['name'] : $z['name'];
        fputcsv($out, [$z['id'], $name, $z['problem'], $basis . $z['link']], ';', '"', '');
    }
    fclose($out);
    exit;
}

$anzahlen = [];
foreach (array_keys($liste) as $p) {
    $anzahlen[$p] = count($ergebnis($p));
}
$zeilen           = $pruefung !== null ? $ergebnis($pruefung) : [];
$vollstaendigkeit = $bereich === 'katalog' ? Datenpruefung::vollstaendigkeit() : [];

$link = function (array $extra) use ($bereich, $pruefung, $merkmal): string {
    $p = ['bereich' => $bereich, 'pruefung' => $pruefung, 'merkmal' => $pruefung === 'merkmal' ? $merkmal : null];
    return '/admin_datenpruefung.php?' . http_build_query(array_filter($extra + $p, fn($v) => $v !== null));
};

$pageTitle = t('dp.titel');
require __DIR__ . '/views/partials/header.php';
?>

<h1 class="text-2xl font-extrabold text-fellDk mb-2">🔎 <?= e(t('dp.titel')) ?></h1>
<p class="text-fellDk/70 mb-6 max-w-[70ch]"><?= e(t('dp.einleitung')) ?></p>

<?php if ($istSuperAdmin): ?>
    <nav class="flex gap-2 mb-6" aria-label="<?= e(t('dp.bereiche')) ?>">
        <?php foreach (['katalog' => t('dp.bereich_katalog'), 'organisation' => t('dp.bereich_organisation')] as $b => $label): ?>
            <a href="/admin_datenpruefung.php?bereich=<?= e($b) ?>"
               class="px-4 py-2 rounded-full text-sm font-semibold border transition-colors <?= $bereich === $b ? 'bg-tanne text-creme border-tanne' : 'border-sand hover:border-tanne text-fellDk' ?>"
               <?= $bereich === $b ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<p class="text-sm text-fellDk/60 mb-3">
    <?= e($bereich === 'katalog' ? t('dp.hinweis_katalog') : t('dp.hinweis_organisation')) ?>
</p>

<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 mb-8">
    <?php foreach ($liste as $p => $titelKey): ?>
        <a href="<?= e($link(['pruefung' => $p])) ?>"
           class="block bg-white/80 rounded-2xl shadow border p-4 transition-colors <?= $pruefung === $p ? 'border-tanne ring-2 ring-tanne/30' : 'border-sand hover:border-tanne' ?>">
            <span class="block text-sm font-semibold text-fellDk"><?= e(t($titelKey)) ?></span>
            <span class="block text-2xl font-extrabold <?= $anzahlen[$p] > 0 ? 'text-fell' : 'text-tanne' ?>"><?= (int) $anzahlen[$p] ?></span>
            <?php if ($p === 'merkmal'): ?>
                <span class="block text-xs text-fellDk/50"><?= e($merkmale[$merkmal] ?? $merkmal) ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($pruefung !== null): ?>
    <section class="bg-white/80 rounded-2xl shadow border border-sand p-6 mb-8" aria-labelledby="liste-titel">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <h2 id="liste-titel" class="text-lg font-bold text-fellDk">
                <?= e(t($liste[$pruefung])) ?>: <?= count($zeilen) ?>
            </h2>
            <?php if ($zeilen): ?>
                <a href="<?= e($link(['csv' => '1'])) ?>" class="text-sm font-semibold text-tanne hover:text-tanneDk border border-tanne/40 hover:border-tanne rounded-lg px-3 py-1.5 transition-colors">
                    <?= e(t('dp.csv')) ?>
                </a>
            <?php endif; ?>
        </div>

        <?php if ($pruefung === 'merkmal'): ?>
            <form method="get" action="/admin_datenpruefung.php" class="flex flex-wrap items-end gap-3 mb-4">
                <input type="hidden" name="bereich" value="katalog">
                <input type="hidden" name="pruefung" value="merkmal">
                <div>
                    <label for="merkmal" class="block text-sm font-semibold mb-1"><?= e(t('dp.merkmal_waehlen')) ?></label>
                    <select id="merkmal" name="merkmal" class="rounded-lg border border-sand px-3 py-2">
                        <?php foreach ($merkmale as $schluessel => $name): ?>
                            <option value="<?= e($schluessel) ?>" <?= $schluessel === $merkmal ? 'selected' : '' ?>><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="bg-tanne hover:bg-tanneDk text-creme font-bold text-sm px-5 py-2.5 rounded-full transition-colors"><?= e(t('dp.anzeigen')) ?></button>
            </form>
        <?php endif; ?>

        <?php if (!$zeilen): ?>
            <p class="text-tanneDk"><?= e(t('dp.alles_gut')) ?></p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-sand/30 text-left text-xs font-semibold uppercase tracking-wide text-fellDk/60">
                            <th class="px-3 py-2"><?= e(t('dp.spalte_id')) ?></th>
                            <th class="px-3 py-2"><?= e(t('dp.spalte_name')) ?></th>
                            <th class="px-3 py-2"><?= e(t('dp.spalte_problem')) ?></th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-sand">
                        <?php foreach ($zeilen as $z): ?>
                            <tr class="hover:bg-creme/60">
                                <td class="px-3 py-2 text-fellDk/60"><?= (int) $z['id'] ?></td>
                                <td class="px-3 py-2 font-semibold"><?= e($z['name']) ?></td>
                                <td class="px-3 py-2 text-fellDk/80"><?= e($z['problem']) ?></td>
                                <td class="px-3 py-2 text-right whitespace-nowrap">
                                    <a href="<?= e($z['link']) ?>" class="text-tanne hover:text-tanneDk font-semibold"><?= e(t('dp.bearbeiten')) ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($vollstaendigkeit): ?>
    <section class="bg-white/80 rounded-2xl shadow border border-sand p-6" aria-labelledby="voll-titel">
        <h2 id="voll-titel" class="text-lg font-bold text-fellDk mb-4"><?= e(t('dp.vollstaendigkeit')) ?></h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-sand/30 text-left text-xs font-semibold uppercase tracking-wide text-fellDk/60">
                        <th class="px-3 py-2"><?= e(t('dp.spalte_merkmal')) ?></th>
                        <th class="px-3 py-2"><?= e(t('dp.spalte_mit_wert')) ?></th>
                        <th class="px-3 py-2 w-1/2"><?= e(t('dp.spalte_anteil')) ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-sand">
                    <?php foreach ($vollstaendigkeit as $v): ?>
                        <tr>
                            <td class="px-3 py-2">
                                <a href="<?= e('/admin_datenpruefung.php?' . http_build_query(['bereich' => 'katalog', 'pruefung' => 'merkmal', 'merkmal' => $v['schluessel']])) ?>"
                                   class="text-tanne hover:text-tanneDk font-semibold"><?= e($v['name']) ?></a>
                                <?php if (in_array($v['schluessel'], Datenpruefung::PFLICHTMERKMALE, true)): ?>
                                    <span class="ml-1 text-xs text-fellDk/50">(<?= e(t('dp.pflicht')) ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap"><?= (int) $v['mit'] ?> / <?= (int) $v['gesamt'] ?></td>
                            <td class="px-3 py-2">
                                <div class="flex items-center gap-2">
                                    <div class="flex-1 h-2 rounded-full bg-sand/50 overflow-hidden" role="presentation">
                                        <div class="h-2 bg-tanne" style="width: <?= (int) $v['prozent'] ?>%"></div>
                                    </div>
                                    <span class="w-10 text-right tabular-nums"><?= (int) $v['prozent'] ?> %</span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
