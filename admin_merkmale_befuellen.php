<?php
/**
 * admin_merkmale_befuellen.php
 *
 * Leere Merkmale aus TheDogAPI und aus dem VDH-Text befüllen (PLAN.md
 * Abschnitt 10, Schritt 4). Je Merkmal ein Reiter mit allen Rassen, bei
 * denen es noch leer ist und eine Quelle etwas liefert. Jede Zeile zeigt
 * den Vorschlag mit Beleg, übernommen wird nur, was angehakt bleibt.
 * Vorschläge: models/MerkmalVorschlaege.php
 *
 * Höchstens ~400 Zeilen je Reiter, also unter max_input_vars (1000).
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/MerkmalVorschlaege.php';
require_once __DIR__ . '/models/TheDogApiFelder.php';

secure_session_start();
// Schreibt in den geteilten Katalog aller Mandanten, wie der TheDogAPI-Abgleich
require_super_admin();
set_time_limit(120);

if ((int) Database::getConnection()->query('SELECT COUNT(*) FROM breeds WHERE daten_stand IS NULL')->fetchColumn() > 0) {
    RassenDaten::alleUebernehmen();
}

$definitionen = RassenDatenSchreiben::nachSchluessel();
$merkmale = array_values(array_filter(array_keys(MerkmalVorschlaege::MERKMALE), fn($s) => isset($definitionen[$s])));
$aktuell = in_array($_REQUEST['merkmal'] ?? '', $merkmale, true) ? $_REQUEST['merkmal'] : ($merkmale[0] ?? '');
$api = TheDogApiFelder::breeds();
$quelleName = ['vdh' => 'VDH-Text', 'thedogapi' => 'TheDogAPI'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $auswahl = is_array($_POST['w'] ?? null) ? $_POST['w'] : [];
    $liste = [];
    // Werte neu berechnen, nie aus dem Formular übernehmen
    foreach (MerkmalVorschlaege::fuerMerkmal($aktuell, $api['breeds']) as $zeile) {
        $quelle = (string) ($auswahl[$zeile['breed_id']] ?? '');
        if (isset($zeile[$quelle]) && isset($quelleName[$quelle])) {
            $liste[] = ['breed_id' => $zeile['breed_id'], 'merkmal' => $aktuell, 'wert' => $zeile[$quelle]['wert'],
                'quelle' => $quelle === 'vdh' ? 'vdh_text' : 'thedogapi'];
        }
    }
    $n = $liste ? RassenDatenSchreiben::vorschlaegeUebernehmen($liste, (int) current_user()['id']) : 0;
    $_SESSION['befuellen_meldung'] = $n === 1 ? '1 Wert übernommen.' : "{$n} Werte übernommen.";
    header('Location: /admin_merkmale_befuellen.php?merkmal=' . urlencode($aktuell));
    exit;
}

$meldung = $_SESSION['befuellen_meldung'] ?? null;
unset($_SESSION['befuellen_meldung']);

$anzahl = [];
$zeilen = [];
foreach ($merkmale as $s) {
    $z = MerkmalVorschlaege::fuerMerkmal($s, $api['breeds']);
    $anzahl[$s] = count($z);
    if ($s === $aktuell) {
        $zeilen = $z;
    }
}
$m = $definitionen[$aktuell] ?? null;

/** Vorschlag lesbar: "Rüde 58–61 cm, Hündin 56–59 cm", Farbnamen, Land */
function vorschlag_text(array $m, array $wert): string
{
    return (string) RassenDatenSchreiben::lesbar($m, $wert);
}

$pageTitle = 'Merkmale befüllen';
require __DIR__ . '/views/partials/header.php';
?>

<div class="max-w-5xl">
    <h1 class="text-2xl font-extrabold text-fellDk mb-2">Merkmale befüllen</h1>
    <p class="text-fellDk mb-4 max-w-[70ch]">
        Vorschläge für Merkmale, die bei einer Rasse noch leer sind. TheDogAPI zählt nur bei sicher zugeordneten Rassen.
        Aus dem VDH-Text werden Größe, Gewicht und Farben herausgelesen, darunter steht jeweils die Textstelle.
        Bitte prüfen: übernommen wird nur die gewählte Quelle, vorhandene Werte bleiben unberührt.
    </p>

    <?php if ($meldung !== null): ?>
        <p class="bg-tanne/10 border border-tanne/40 text-tanneDk rounded-lg px-4 py-3 mb-6" role="status"><?= e($meldung) ?></p>
    <?php endif; ?>
    <?php if ($api['fehler'] !== null): ?>
        <p class="bg-pfote/10 border border-pfote/40 text-fellDk rounded-lg px-4 py-3 mb-6" role="note">Ohne TheDogAPI: <?= e($api['fehler']) ?></p>
    <?php endif; ?>

    <nav class="flex flex-wrap gap-2 mb-6" aria-label="Merkmal wählen">
        <?php foreach ($merkmale as $s): $an = $s === $aktuell; ?>
            <a href="/admin_merkmale_befuellen.php?merkmal=<?= e($s) ?>" <?= $an ? 'aria-current="page"' : '' ?>
                class="inline-flex items-center gap-2 min-h-[40px] px-4 rounded-full border text-sm font-semibold <?= $an ? 'bg-tanne text-creme border-tanne' : 'border-sand text-tanneDk hover:bg-creme' ?>">
                <?= e($definitionen[$s]['name_de']) ?>
                <span class="tabular-nums font-normal <?= $an ? 'text-creme/90' : 'text-fellDk/70' ?>"><?= $anzahl[$s] ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($m === null || !$zeilen): ?>
        <p class="text-fellDk/80">Für „<?= e($m['name_de'] ?? $aktuell) ?>“ gibt es keine Vorschläge mehr.</p>
    <?php else:
        $mitVdh = count(array_filter($zeilen, fn($z) => isset($z['vdh'])));
        $mitApi = count(array_filter($zeilen, fn($z) => isset($z['thedogapi']))); ?>
        <form method="post" action="/admin_merkmale_befuellen.php" id="befuellen">
            <?= csrf_field() ?>
            <input type="hidden" name="merkmal" value="<?= e($aktuell) ?>">

            <div class="flex flex-wrap items-center gap-3 mb-4 text-sm">
                <span class="text-fellDk/80"><?= count($zeilen) ?> Rassen · VDH-Text <?= $mitVdh ?> · TheDogAPI <?= $mitApi ?></span>
                <span class="text-fellDk/80">Alle auf:</span>
                <?php foreach (['vdh' => 'VDH-Text', 'thedogapi' => 'TheDogAPI', '' => 'nichts'] as $q => $label): ?>
                    <button type="button" data-alle="<?= e($q) ?>" class="min-h-[36px] px-3 rounded-lg border border-sand hover:bg-creme"><?= e($label) ?></button>
                <?php endforeach; ?>
            </div>

            <ul class="space-y-2">
                <?php foreach ($zeilen as $z):
                    $id = $z['breed_id'];
                    $vorwahl = isset($z['vdh']) ? 'vdh' : 'thedogapi'; ?>
                    <li class="bg-white/80 rounded-xl border border-sand p-4">
                        <fieldset>
                            <legend class="font-bold text-fellDk">
                                <a href="/breed_detail.php?id=<?= $id ?>" class="hover:underline" target="_blank" rel="noopener"><?= e($z['name']) ?></a>
                            </legend>
                            <div class="mt-2 grid grid-cols-1 md:grid-cols-[1fr_1fr_auto] gap-3">
                                <?php foreach (['vdh', 'thedogapi'] as $q): ?>
                                    <?php if (isset($z[$q])): ?>
                                        <label class="flex items-start gap-2 rounded-lg border border-sand p-3 cursor-pointer has-[:checked]:border-tanne has-[:checked]:bg-tanne/5">
                                            <input type="radio" name="w[<?= $id ?>]" value="<?= $q ?>" <?= $q === $vorwahl ? 'checked' : '' ?> class="mt-1 border-sand text-tanne focus:ring-tanne">
                                            <span>
                                                <span class="text-xs font-semibold uppercase tracking-wide text-fellDk/70"><?= e($quelleName[$q]) ?></span><br>
                                                <span class="font-semibold"><?= e(vorschlag_text($m, $z[$q]['wert'])) ?></span>
                                                <span class="block text-xs text-fellDk/80 mt-1"><?= e($z[$q]['beleg']) ?></span>
                                            </span>
                                        </label>
                                    <?php else: ?>
                                        <span class="hidden md:block"></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <label class="flex items-center gap-2 text-sm text-fellDk/80 cursor-pointer px-2">
                                    <input type="radio" name="w[<?= $id ?>]" value="" class="border-sand text-tanne focus:ring-tanne">
                                    nicht übernehmen
                                </label>
                            </div>
                        </fieldset>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="sticky bottom-4 mt-6">
                <button type="submit" class="min-h-[48px] bg-tanne hover:bg-tanneDk text-creme font-bold px-6 rounded-lg shadow-lg">
                    Auswahl für „<?= e($m['name_de']) ?>“ übernehmen
                </button>
            </div>
        </form>

        <script>
            // "Alle auf …": je Rasse die gewählte Quelle, wo es sie gibt
            document.querySelectorAll('#befuellen [data-alle]').forEach(function (knopf) {
                knopf.addEventListener('click', function () {
                    var quelle = knopf.getAttribute('data-alle');
                    document.querySelectorAll('#befuellen fieldset').forEach(function (feld) {
                        var radio = feld.querySelector('input[type=radio][value="' + quelle + '"]');
                        if (radio) {
                            radio.checked = true;
                        }
                    });
                });
            });
        </script>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
