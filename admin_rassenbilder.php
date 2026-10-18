<?php
/**
 * admin_rassenbilder.php
 *
 * Rassenbilder aus TheDogAPI auswählen. Je Rasse mit TheDogAPI-Zuordnung
 * zeigt die Seite einige Bildkandidaten (models/TheDogApiBilder.php), der
 * Super-Admin wählt eines oder keines. Übernommen wird verkleinert in
 * breeds.bild_blob, die Herkunft steht in breeds.bild_quelle.
 *
 * Drei Ansichten: "ohne" listet Rassen ohne Bild (Standard), "mit" listet
 * Rassen mit Bild, um eines bei Bedarf zu ersetzen. In der Ansicht "ohne"
 * wird nie ein vorhandenes Bild überschrieben, auch wenn es zwischen
 * Anzeigen und Absenden dazugekommen ist. "zurueck" listet Rassen, die
 * zurückgestellt sind (breeds.bild_zurueckgestellt), weil kein passendes
 * Bild dabei war. Dort lassen sie sich wieder anbieten oder direkt mit
 * einem neuen Bild versehen.
 *
 * Seitenweise, damit die Abrufe an TheDogAPI überschaubar bleiben.
 * Rassen ohne Zuordnung werden zuerst über admin_thedogapi_review.php
 * zugeordnet.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/models/TheDogApiBilder.php';

secure_session_start();
// Schreibt in den geteilten Rassenkatalog aller Organisationen.
require_super_admin();
set_time_limit(300);

const RB_PRO_SEITE = 10;

$pdo = Database::getConnection();
$modus = in_array($_REQUEST['modus'] ?? '', ['mit', 'zurueck'], true) ? $_REQUEST['modus'] : 'ohne';
$seite = max(1, (int) ($_REQUEST['seite'] ?? 1));
$zugeordnet = "thedogapi_id IS NOT NULL AND thedogapi_id <> ''";
$bedingung = match ($modus) {
    'mit'     => 'bild_blob IS NOT NULL AND bild_zurueckgestellt IS NULL',
    'zurueck' => 'bild_zurueckgestellt IS NOT NULL',
    default   => 'bild_blob IS NULL AND bild_zurueckgestellt IS NULL',
};
$zurueck = '/admin_rassenbilder.php?modus=' . $modus . '&seite=' . $seite;

// Werte der Auswahl neben den Bild-IDs. Der Doppelpunkt kommt in
// Bild-IDs nicht vor (TheDogApiBilder::auswerten).
const RB_ZURUECKSTELLEN = ':zurueckstellen';
const RB_ANBIETEN = ':anbieten';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $lesen = $pdo->prepare("SELECT id, name, thedogapi_id FROM breeds WHERE id = :id AND {$zugeordnet}");

    if (($_POST['aktion'] ?? '') === 'neu_abrufen') {
        $ids = [];
        foreach ((array) ($_POST['rassen'] ?? []) as $breedId) {
            $lesen->execute([':id' => (int) $breedId]);
            if ($rasse = $lesen->fetch()) {
                $ids[] = (string) $rasse['thedogapi_id'];
            }
        }
        TheDogApiBilder::vergessen($ids);
        $_SESSION['rassenbilder_meldung'] = [true, 'Bilder für ' . count($ids) . ' Rassen neu bei TheDogAPI abgerufen.'];
        header('Location: ' . $zurueck);
        exit;
    }

    $wahl = is_array($_POST['wahl'] ?? null) ? $_POST['wahl'] : [];
    // In der Ansicht "ohne" schützt die WHERE-Bedingung ein Bild, das
    // zwischen Anzeigen und Absenden hochgeladen wurde.
    $schreiben = $pdo->prepare(
        'UPDATE breeds SET bild_blob = :blob, bild_mime = :mime, bild_updated_at = NOW(), bild_quelle = :quelle, bild_zurueckgestellt = NULL
         WHERE id = :id' . ($modus === 'ohne' ? ' AND bild_blob IS NULL' : '')
    );
    $zurueckstellen = $pdo->prepare('UPDATE breeds SET bild_zurueckgestellt = NOW() WHERE id = :id AND bild_zurueckgestellt IS NULL');
    $anbieten = $pdo->prepare('UPDATE breeds SET bild_zurueckgestellt = NULL WHERE id = :id');
    $zahl = ['uebernommen' => 0, 'zurueckgestellt' => 0, 'angeboten' => 0];
    $fehler = [];
    foreach ($wahl as $breedId => $bildId) {
        $bildId = (string) $bildId;
        if ($bildId === '') {
            continue;
        }
        $lesen->execute([':id' => (int) $breedId]);
        $rasse = $lesen->fetch();
        if (!$rasse) {
            continue;
        }
        if ($bildId === RB_ZURUECKSTELLEN) {
            $zurueckstellen->execute([':id' => (int) $rasse['id']]);
            $zahl['zurueckgestellt'] += $zurueckstellen->rowCount();
            continue;
        }
        if ($bildId === RB_ANBIETEN) {
            // Beim nächsten Anzeigen frisch bei TheDogAPI nachsehen.
            TheDogApiBilder::vergessen([(string) $rasse['thedogapi_id']]);
            $anbieten->execute([':id' => (int) $rasse['id']]);
            $zahl['angeboten'] += $anbieten->rowCount();
            continue;
        }
        $kandidat = TheDogApiBilder::kandidat((string) $rasse['thedogapi_id'], $bildId);
        if ($kandidat === null) {
            $fehler[] = $rasse['name'] . ': Bild gehört nicht zu dieser Rasse.';
            continue;
        }
        $bild = TheDogApiBilder::laden($kandidat['url']);
        if (!$bild['success']) {
            $fehler[] = $rasse['name'] . ': ' . $bild['message'];
            continue;
        }
        $schreiben->execute([
            ':blob' => $bild['blob'], ':mime' => $bild['mime'],
            ':quelle' => TheDogApiBilder::quelle($bildId), ':id' => (int) $rasse['id'],
        ]);
        if ($schreiben->rowCount() === 1) {
            $zahl['uebernommen']++;
        } else {
            $fehler[] = $rasse['name'] . ': hat inzwischen ein Bild, nichts geändert.';
        }
    }
    $teile = [$zahl['uebernommen'] . ' ' . ($zahl['uebernommen'] === 1 ? 'Bild übernommen.' : 'Bilder übernommen.')];
    if ($zahl['zurueckgestellt'] > 0) {
        $teile[] = $zahl['zurueckgestellt'] . ' zurückgestellt.';
    }
    if ($zahl['angeboten'] > 0) {
        $teile[] = $zahl['angeboten'] . ' wieder im Abgleich.';
    }
    if ($fehler) {
        $teile[] = 'Nicht übernommen: ' . implode(' | ', $fehler);
    }
    $_SESSION['rassenbilder_meldung'] = [!$fehler, implode(' ', $teile)];
    header('Location: ' . $zurueck);
    exit;
}

[$meldungOk, $meldung] = $_SESSION['rassenbilder_meldung'] ?? [null, null];
unset($_SESSION['rassenbilder_meldung']);

$gesamt = (int) $pdo->query("SELECT COUNT(*) FROM breeds WHERE {$zugeordnet} AND {$bedingung}")->fetchColumn();
$anzahlZurueck = (int) $pdo->query("SELECT COUNT(*) FROM breeds WHERE {$zugeordnet} AND bild_zurueckgestellt IS NOT NULL")->fetchColumn();
$ohneZuordnung = (int) $pdo->query("SELECT COUNT(*) FROM breeds WHERE thedogapi_id IS NULL OR thedogapi_id = ''")->fetchColumn();
$seiten = max(1, (int) ceil($gesamt / RB_PRO_SEITE));
$seite = min($seite, $seiten);

$stmt = $pdo->prepare(
    "SELECT id, name, thedogapi_id, bild_blob IS NOT NULL AS hat_bild, bild_quelle, bild_updated_at, bild_zurueckgestellt FROM breeds
     WHERE {$zugeordnet} AND {$bedingung} ORDER BY name LIMIT " . RB_PRO_SEITE . ' OFFSET ' . (($seite - 1) * RB_PRO_SEITE)
);
$stmt->execute();
$rassen = $stmt->fetchAll();

$apiFehler = null;
foreach ($rassen as &$r) {
    $k = TheDogApiBilder::kandidaten((string) $r['thedogapi_id']);
    $r['kandidaten'] = $k['bilder'];
    $apiFehler ??= $k['fehler'];
}
unset($r);

$pageTitle = 'Rassenbilder';
require __DIR__ . '/views/partials/header.php';
?>

<?php
$ansichten = ['ohne' => 'Rassen ohne Bild', 'mit' => 'Rassen mit Bild ersetzen', 'zurueck' => 'Zurückgestellt (' . $anzahlZurueck . ')'];
$beschreibung = ['ohne' => 'Rassen ohne Bild', 'mit' => 'Rassen mit Bild', 'zurueck' => 'zurückgestellte Rassen'];
$behaltenText = ['ohne' => 'Offen lassen', 'mit' => 'Behalten', 'zurueck' => 'Zurückgestellt lassen'];
$kachel = 'flex flex-col items-center justify-center gap-2 border border-sand rounded-lg p-2 cursor-pointer has-[:checked]:ring-2 has-[:checked]:ring-tanne min-h-[8rem]';
?>
<div class="max-w-5xl">
    <h1 class="text-2xl font-extrabold text-fellDk mb-2">🖼️ Rassenbilder aus TheDogAPI</h1>
    <p class="text-fellDk mb-4 max-w-[70ch]">
        Je Rasse stehen bis zu <?= TheDogApiBilder::ANZAHL ?> Bilder aus TheDogAPI zur Auswahl. Wähle das passende aus.
        Passt keines, stell die Rasse zurück, dann kommt sie hier nicht mehr. Unter „Zurückgestellt“ kannst du sie
        jederzeit wieder anbieten und nach neuen Bildern sehen. Übernommen wird verkleinert, die Herkunft wird
        mitgespeichert. Die Bilder stammen von Nutzern von TheDogAPI, die Rechte daran sind nicht geklärt.
    </p>

    <nav class="flex flex-wrap gap-2 mb-4" aria-label="Ansicht">
        <?php foreach ($ansichten as $m => $label): ?>
            <a href="/admin_rassenbilder.php?modus=<?= $m ?>"
               class="text-sm font-semibold rounded-full px-4 py-1.5 border <?= $modus === $m ? 'bg-tanne text-creme border-tanne' : 'border-sand hover:bg-sand/30' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($meldung !== null): ?>
        <p class="<?= $meldungOk ? 'bg-tanne/10 border-tanne/40 text-tanneDk' : 'bg-red-50 border-red-200 text-red-700' ?> border rounded-lg px-4 py-3 mb-4" role="<?= $meldungOk ? 'status' : 'alert' ?>"><?= e($meldung) ?></p>
    <?php endif; ?>
    <?php if ($apiFehler !== null): ?>
        <p class="bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 mb-4" role="alert"><?= e($apiFehler) ?></p>
    <?php endif; ?>

    <p class="text-sm text-fellDk/60 mb-4">
        <?= $gesamt ?> <?= $beschreibung[$modus] ?> mit TheDogAPI-Zuordnung, Seite <?= $seite ?> von <?= $seiten ?>.
        <?php if ($ohneZuordnung > 0): ?>
            <?= $ohneZuordnung ?> Rassen haben noch keine Zuordnung, die gehen über den <a href="/admin_thedogapi_review.php" class="underline">TheDogAPI-Abgleich</a>.
        <?php endif; ?>
    </p>
    <?php if ($modus !== 'ohne'): ?>
        <p class="bg-pfote/10 border border-pfote/30 rounded-lg px-4 py-3 mb-4 text-sm">Ein gewähltes Bild ersetzt ein vorhandenes. Das alte Bild ist danach weg.</p>
    <?php endif; ?>

    <?php if (!$rassen): ?>
        <p class="text-fellDk/60">Keine Rassen in dieser Ansicht.</p>
    <?php else: ?>
        <form method="post" action="/admin_rassenbilder.php" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="modus" value="<?= $modus ?>">
            <input type="hidden" name="seite" value="<?= $seite ?>">
            <?php foreach ($rassen as $r): $rid = (int) $r['id']; ?>
                <fieldset class="bg-white/80 rounded-2xl shadow border border-sand p-4">
                    <legend class="px-1 font-bold text-fellDk">
                        <a href="/breed_detail.php?id=<?= $rid ?>" class="hover:underline"><?= e($r['name']) ?></a>
                        <span class="text-xs font-normal text-fellDk/50">TheDogAPI <?= e((string) $r['thedogapi_id']) ?></span>
                        <?php if ($r['bild_zurueckgestellt']): ?>
                            <span class="text-xs font-normal text-fellDk/50">· zurückgestellt seit <?= e(date('d.m.Y', (int) strtotime((string) $r['bild_zurueckgestellt']))) ?></span>
                        <?php endif; ?>
                    </legend>
                    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3 mt-2">
                        <label class="<?= $kachel ?>">
                            <?php if ($r['hat_bild']): ?>
                                <img src="/bildausgabe.php?id=<?= $rid ?>&amp;v=<?= urlencode((string) ($r['bild_updated_at'] ?? '')) ?>" alt="Aktuelles Bild" class="w-full h-24 object-cover rounded">
                            <?php endif; ?>
                            <span class="text-xs text-center"><input type="radio" name="wahl[<?= $rid ?>]" value="" checked> <?= $behaltenText[$modus] ?><?= $r['bild_quelle'] ? '<br><span class="text-fellDk/50">' . e($r['bild_quelle']) . '</span>' : '' ?></span>
                        </label>
                        <label class="<?= $kachel ?>">
                            <?php if ($modus === 'zurueck'): ?>
                                <span class="text-xs text-center"><input type="radio" name="wahl[<?= $rid ?>]" value="<?= RB_ANBIETEN ?>"> Wieder anbieten</span>
                            <?php else: ?>
                                <span class="text-xs text-center"><input type="radio" name="wahl[<?= $rid ?>]" value="<?= RB_ZURUECKSTELLEN ?>"> Zurückstellen<br><span class="text-fellDk/50">kein passendes Bild</span></span>
                            <?php endif; ?>
                        </label>
                        <?php foreach ($r['kandidaten'] as $k): ?>
                            <label class="flex flex-col gap-1 border border-sand rounded-lg p-2 cursor-pointer has-[:checked]:ring-2 has-[:checked]:ring-tanne">
                                <img src="<?= e($k['url']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" class="w-full h-24 object-cover rounded">
                                <span class="text-xs"><input type="radio" name="wahl[<?= $rid ?>]" value="<?= e($k['id']) ?>"> <?= $k['breite'] ?>×<?= $k['hoehe'] ?></span>
                            </label>
                        <?php endforeach; ?>
                        <?php if (!$r['kandidaten']): ?>
                            <p class="text-xs text-fellDk/50 self-center col-span-2">TheDogAPI hat keine Bilder zu dieser Rasse.</p>
                        <?php endif; ?>
                    </div>
                </fieldset>
            <?php endforeach; ?>
            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="bg-tanne hover:bg-tanneDk text-creme font-bold text-sm px-5 py-2.5 rounded-full transition-colors">Auswahl speichern</button>
                <span class="text-sm text-fellDk/60">Rassen auf „<?= $behaltenText[$modus] ?>“ bleiben unverändert.</span>
            </div>
        </form>

        <form method="post" action="/admin_rassenbilder.php" class="mt-4">
            <?= csrf_field() ?>
            <input type="hidden" name="aktion" value="neu_abrufen">
            <input type="hidden" name="modus" value="<?= $modus ?>">
            <input type="hidden" name="seite" value="<?= $seite ?>">
            <?php foreach ($rassen as $r): ?><input type="hidden" name="rassen[]" value="<?= (int) $r['id'] ?>"><?php endforeach; ?>
            <button type="submit" class="text-sm font-semibold text-tanne hover:text-tanneDk underline">Bilder dieser Seite neu bei TheDogAPI abrufen</button>
            <span class="text-sm text-fellDk/60">Sonst kommen die Bilder bis zu einer Woche aus dem Zwischenspeicher.</span>
        </form>

        <?php if ($seiten > 1): ?>
            <nav class="flex gap-3 mt-6 text-sm" aria-label="Seiten">
                <?php if ($seite > 1): ?><a href="/admin_rassenbilder.php?modus=<?= $modus ?>&amp;seite=<?= $seite - 1 ?>" class="underline">← Vorherige Seite</a><?php endif; ?>
                <?php if ($seite < $seiten): ?><a href="/admin_rassenbilder.php?modus=<?= $modus ?>&amp;seite=<?= $seite + 1 ?>" class="underline">Nächste Seite →</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
