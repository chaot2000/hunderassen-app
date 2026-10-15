<?php
/**
 * admin_rassentexte.php
 *
 * Umstellung der Rassentexte auf die gegliederte Form (Super-Admin).
 * Zeigt, welche Rassen umstellbar sind, eine Vorschau je Rasse und
 * führt die Umstellung bzw. deren Rücknahme aus. Logik in
 * models/RassentextUmstellung.php, jede Änderung landet in den Versionen.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/RassentextUmstellung.php';
require_once __DIR__ . '/models/Breed.php';
require_once __DIR__ . '/views/partials/rassentext.php';

secure_session_start();
// Schreibt in den geteilten Katalog aller Mandanten.
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $userId = (int) current_user()['id'];
    $aktion = $_POST['aktion'] ?? '';

    if ($aktion === 'umstellen') {
        $r = RassentextUmstellung::alleUmstellen($userId);
        $meldung = "Umgestellt: {$r['umgestellt']} Rassen. Nur Felder ergänzt: {$r['nur_daten']}. Übersprungen: {$r['uebersprungen']}.";
    } elseif ($aktion === 'rueckgaengig') {
        $r = RassentextUmstellung::alleRueckgaengig($userId);
        $meldung = "Zurückgesetzt: {$r['zurueckgesetzt']} Rassen."
            . ($r['felder_behalten'] > 0 ? " {$r['felder_behalten']} seitdem von Hand geänderte Felder blieben unverändert." : '');
    } else {
        $meldung = 'Unbekannte Aktion.';
    }

    $_SESSION['rassentexte_meldung'] = $meldung;
    header('Location: /admin_rassentexte.php');
    exit;
}

$meldung = $_SESSION['rassentexte_meldung'] ?? null;
unset($_SESSION['rassentexte_meldung']);

$liste = RassentextUmstellung::uebersicht();
$zaehler = array_count_values(array_column($liste, 'status'));
$statusLabels = [
    'umstellbar'    => 'umstellbar',
    'gegliedert'    => 'bereits gegliedert',
    'von_hand'      => 'von Hand geändert, Text bleibt',
    'ohne_text'     => 'ohne Text',
    'kein_original' => 'kein Originaltext gespeichert',
    'verlust'       => 'Prüfung meldet Verlust',
];
$filter = isset($_GET['status'], $statusLabels[$_GET['status']]) ? $_GET['status'] : null;
$vorschau = isset($_GET['vorschau']) ? RassentextUmstellung::vorschau((int) $_GET['vorschau']) : null;

$pageTitle = 'Rassentexte gliedern';
require __DIR__ . '/views/partials/header.php';
?>

<h1 class="text-2xl font-black text-fellDk">Rassentexte gliedern</h1>
<p class="mt-2 text-fellDk max-w-[70ch]">
    Zerlegt die importierten VDH-Texte: FCI-Sektion und Arbeitsprüfung werden eigene Felder, der doppelte Text und der Restsatz der VDH-Seite fallen weg, die Absätze bekommen Überschriften (Wesen, Haltung, Aussehen …). Die Zuordnung ist automatisch und muss je Rasse geprüft werden.
    Von Hand geänderte Texte bleiben unverändert. Jede Änderung wird als Version gespeichert und lässt sich hier oder je Rasse zurücknehmen.
</p>

<?php if ($meldung !== null): ?>
    <div class="mt-4 bg-tanne/10 border border-tanne/30 text-tanneDk rounded-lg px-4 py-3" role="status"><?= e($meldung) ?></div>
<?php endif; ?>

<section aria-labelledby="stand-titel" class="mt-8 border-t-4 border-fellDk pt-4">
    <h2 id="stand-titel" class="text-xl font-black text-fellDk">Stand</h2>
    <ul class="mt-3 flex flex-wrap gap-2">
        <li><a href="/admin_rassentexte.php" class="inline-flex min-h-[44px] items-center px-3 rounded-sm text-sm font-bold <?= $filter === null ? 'bg-fellDk text-creme' : 'bg-sand text-fellDk' ?>">Alle (<?= count($liste) ?>)</a></li>
        <?php foreach ($statusLabels as $s => $label): if (empty($zaehler[$s])) continue; ?>
            <li><a href="/admin_rassentexte.php?status=<?= e($s) ?>" class="inline-flex min-h-[44px] items-center px-3 rounded-sm text-sm font-bold <?= $filter === $s ? 'bg-fellDk text-creme' : 'bg-sand text-fellDk' ?>"><?= e($label) ?> (<?= (int) $zaehler[$s] ?>)</a></li>
        <?php endforeach; ?>
    </ul>

    <div class="mt-6 flex flex-wrap gap-3">
        <form method="post" onsubmit="return confirm('Alle umstellbaren Rassen jetzt gliedern? Jede Änderung wird als Version gespeichert.');">
            <?= csrf_field() ?>
            <input type="hidden" name="aktion" value="umstellen">
            <button type="submit" class="min-h-[44px] bg-tanne hover:bg-tanneDk text-creme font-bold px-5 rounded-lg disabled:opacity-60" <?= empty($zaehler['umstellbar']) && empty($zaehler['ohne_text']) && empty($zaehler['von_hand']) ? 'disabled' : '' ?>>
                Jetzt umstellen (<?= (int) ($zaehler['umstellbar'] ?? 0) ?> Texte)
            </button>
        </form>
        <?php if (!empty($zaehler['gegliedert'])): ?>
            <form method="post" onsubmit="return confirm('Umstellung für alle Rassen zurücknehmen? Von Hand geänderte Felder bleiben.');">
                <?= csrf_field() ?>
                <input type="hidden" name="aktion" value="rueckgaengig">
                <button type="submit" class="min-h-[44px] border-2 border-fellDk text-fellDk hover:bg-fellDk hover:text-creme font-bold px-5 rounded-lg">
                    Umstellung zurücknehmen
                </button>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php if ($vorschau !== null):
    $neuText = $vorschau['neu']['beschreibung'] ?? $vorschau['rasse']['beschreibung'];
    $anzeige = RassentextGliederung::fuerAnzeige($neuText);
?>
    <section aria-labelledby="vorschau-titel" class="mt-10 border-t-4 border-fellDk pt-4">
        <h2 id="vorschau-titel" class="text-xl font-black text-fellDk">Vorschau: <?= e($vorschau['rasse']['name']) ?></h2>
        <p class="text-sm text-fellDk mt-1">Status: <?= e($statusLabels[$vorschau['status']] ?? $vorschau['status']) ?>. <?= e(implode(' ', $vorschau['hinweise'])) ?></p>

        <?php $felder = array_diff_key($vorschau['neu'], ['beschreibung' => 1]); ?>
        <?php if (!empty($felder)): ?>
            <dl class="mt-4 grid grid-cols-1 sm:grid-cols-[12rem_1fr] gap-x-4 gap-y-1 text-sm">
                <?php foreach ($felder as $feld => $wert): ?>
                    <dt class="font-bold text-fellDk"><?= e(ContentRevision::FELDER['breeds'][$feld] ?? $feld) ?></dt>
                    <dd class="text-fellDk"><span class="font-mono"><?= e((string) $wert) ?></span> <span class="text-fellDk/80">(vorher: <?= e((string) ($vorschau['rasse'][$feld] ?? 'leer')) ?>)</span></dd>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>

        <div class="mt-6">
            <?php if ($anzeige['kurzportraet'] !== null): ?>
                <p class="lg:ml-[33.333%] lg:pl-10 text-lg font-semibold text-fellDk max-w-[68ch] mb-8"><?= e($anzeige['kurzportraet']) ?></p>
            <?php endif; ?>
            <?= render_rassentext_abschnitte($anzeige) ?>
        </div>
    </section>
<?php endif; ?>

<section aria-labelledby="liste-titel" class="mt-10 border-t-4 border-fellDk pt-4">
    <h2 id="liste-titel" class="text-xl font-black text-fellDk">Rassen<?= $filter !== null ? ': ' . e($statusLabels[$filter]) : '' ?></h2>
    <ul class="mt-3 divide-y divide-sand">
        <?php foreach ($liste as $r): if ($filter !== null && $r['status'] !== $filter) continue; ?>
            <li class="py-2 flex flex-wrap items-center justify-between gap-2">
                <span class="text-fellDk"><?= e($r['name']) ?> <span class="text-sm text-fellDk/80">· <?= e($statusLabels[$r['status']] ?? $r['status']) ?></span></span>
                <span class="flex gap-4 text-sm font-semibold">
                    <a class="text-tanneDk underline underline-offset-4 min-h-[44px] inline-flex items-center" href="/admin_rassentexte.php?vorschau=<?= (int) $r['id'] ?><?= $filter ? '&amp;status=' . e($filter) : '' ?>#vorschau-titel">Vorschau</a>
                    <a class="text-tanneDk underline underline-offset-4 min-h-[44px] inline-flex items-center" href="/breed_detail.php?id=<?= (int) $r['id'] ?>">Rassenseite</a>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
