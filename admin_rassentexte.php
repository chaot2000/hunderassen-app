<?php
/**
 * admin_rassentexte.php
 *
 * Rassendaten ins Merkmalsmodell übernehmen (Super-Admin, PLAN.md
 * Abschnitt 10). Jede Rasse wird beim ersten Aufruf ihrer Seite ohnehin
 * übernommen; hier geht es für alle auf einmal, z. B. direkt nach dem
 * Deploy, damit der Katalog-Filter vollständig ist.
 *
 * Noch nicht gegliederte VDH-Texte werden vorher gegliedert
 * (models/RassentextUmstellung.php), damit FCI-Kopf, Sektion und
 * Arbeitsprüfung als Daten ankommen und nicht im Text. Die frühere
 * Seite "Rassentexte gliedern" mit Vorschau und Rücknahme entfällt,
 * Texte werden jetzt je Abschnitt unter "Rasse bearbeiten" gepflegt.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/RassentextUmstellung.php';
require_once __DIR__ . '/models/RassenDaten.php';

secure_session_start();
// Schreibt in den geteilten Katalog aller Mandanten.
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    if (($_POST['aktion'] ?? '') === 'uebernehmen') {
        $g = RassentextUmstellung::alleUmstellen((int) current_user()['id']);
        $n = RassenDaten::alleUebernehmen();
        $_SESSION['rassentexte_meldung'] = "Übernommen: {$n} Rassen. Vorher gegliedert: {$g['umgestellt']}.";
    } else {
        $_SESSION['rassentexte_meldung'] = 'Unbekannte Aktion.';
    }
    header('Location: /admin_rassentexte.php');
    exit;
}

$meldung = $_SESSION['rassentexte_meldung'] ?? null;
unset($_SESSION['rassentexte_meldung']);

$pdo = Database::getConnection();
$gesamt = (int) $pdo->query('SELECT COUNT(*) FROM breeds')->fetchColumn();
$offen = (int) $pdo->query('SELECT COUNT(*) FROM breeds WHERE daten_stand IS NULL')->fetchColumn();
$zahlen = $pdo->query(
    "SELECT (SELECT COUNT(DISTINCT breed_id) FROM rasse_texte) AS mit_texten,
            (SELECT COUNT(DISTINCT breed_id) FROM rasse_texte WHERE sprache = 'en') AS mit_englisch,
            (SELECT COUNT(DISTINCT breed_id) FROM rasse_merkmale) AS mit_merkmalen"
)->fetch();

$pageTitle = 'Rassendaten übernehmen';
require __DIR__ . '/views/partials/header.php';
?>

<div class="max-w-3xl">
    <h1 class="text-2xl font-extrabold text-fellDk mb-2">Rassendaten übernehmen</h1>
    <p class="text-fellDk mb-6 max-w-[60ch]">
        Größen, Eigenschaften, Aktivitäten, Farben, Land und Texte stehen jetzt als Merkmale und Textabschnitte je Sprache.
        Jede Rasse wird beim ersten Öffnen automatisch übernommen. Hier geht es für alle auf einmal.
        Danach pflegst du Steckbrief und Texte unter „Rasse bearbeiten“.
    </p>

    <?php if ($meldung !== null): ?>
        <p class="bg-tanne/10 border border-tanne/40 text-tanneDk rounded-lg px-4 py-3 mb-6" role="status"><?= e($meldung) ?></p>
    <?php endif; ?>

    <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
        <?php foreach ([
            'Rassen' => $gesamt,
            'noch offen' => $offen,
            'mit Texten' => (int) $zahlen['mit_texten'],
            'mit englischem Text' => (int) $zahlen['mit_englisch'],
        ] as $label => $zahl): ?>
            <div class="bg-white/80 rounded-xl border border-sand p-4">
                <dt class="text-sm text-fellDk/80"><?= e($label) ?></dt>
                <dd class="text-2xl font-extrabold text-fellDk tabular-nums"><?= $zahl ?></dd>
            </div>
        <?php endforeach; ?>
    </dl>

    <form method="post" action="/admin_rassentexte.php">
        <?= csrf_field() ?>
        <input type="hidden" name="aktion" value="uebernehmen">
        <button type="submit" class="min-h-[44px] bg-tanne hover:bg-tanneDk text-creme font-bold px-5 rounded-lg disabled:opacity-60" <?= $offen === 0 ? 'disabled' : '' ?>>
            <?= $offen === 0 ? 'Alle Rassen sind übernommen' : 'Jetzt ' . $offen . ' Rassen übernehmen' ?>
        </button>
    </form>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
