<?php
/**
 * admin_merkmale.php
 *
 * Verwaltung der Merkmale und ihrer Werte, je mit deutschem und
 * englischem Namen (PLAN.md Abschnitt 10, Schritt 3). Ersetzt
 * "Eigenschaften & Aktivitäten" (admin_manage_tags.php leitet hierher).
 * Logik in models/MerkmalVerwaltung.php.
 *
 * Je Merkmal ein Formular für die ganze Werteliste, damit sich z. B.
 * fehlende englische Namen in einem Rutsch nachtragen lassen. Löschen
 * und Zusammenführen sind Knöpfe in diesem Formular (name="aktion_wert").
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/MerkmalVerwaltung.php';

secure_session_start();
// Geteilter Katalog: wie "Rasse bearbeiten"
require_manage_breeds();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $merkmalId = (int) ($_POST['merkmal_id'] ?? 0);
    $userId = (int) current_user()['id'];
    $anker = '';
    switch ($_POST['aktion'] ?? '') {
        case 'merkmal_anlegen':
            $r = MerkmalVerwaltung::merkmalAnlegen($_POST);
            $anker = isset($r['schluessel']) ? 'merkmal-' . $r['schluessel'] : 'neu';
            break;
        case 'merkmal_aendern':
            $r = MerkmalVerwaltung::merkmalAendern($merkmalId, $_POST);
            break;
        case 'merkmal_loeschen':
            $r = MerkmalVerwaltung::merkmalLoeschen($merkmalId);
            break;
        case 'wert_anlegen':
            $r = MerkmalVerwaltung::wertAnlegen($merkmalId, $_POST['name_de'] ?? '', $_POST['name_en'] ?? '');
            break;
        case 'werte':
            // Knopf "Löschen" / "Zusammenführen" in einer Zeile, sonst alles speichern
            [$wertAktion, $wertId] = array_pad(explode(':', (string) ($_POST['aktion_wert'] ?? ''), 2), 2, '0');
            $wertId = (int) $wertId;
            $r = match ($wertAktion) {
                'loeschen' => MerkmalVerwaltung::wertLoeschen($merkmalId, $wertId),
                'zusammen' => MerkmalVerwaltung::werteZusammenfuehren($merkmalId, $wertId, (int) ($_POST['ziel'][$wertId] ?? 0), $userId),
                default => MerkmalVerwaltung::werteSpeichern($merkmalId, is_array($_POST['w'] ?? null) ? $_POST['w'] : []),
            };
            break;
        default:
            $r = ['success' => false, 'message' => 'Unbekannte Aktion.'];
    }
    $_SESSION['merkmale_meldung'] = [$r['success'], $r['message']];
    if ($anker === '' && $merkmalId > 0) {
        foreach (RassenDatenSchreiben::definitionen(true) as $m) {
            if ($m['id'] === $merkmalId) {
                $anker = 'merkmal-' . $m['schluessel'];
            }
        }
    }
    header('Location: /admin_merkmale.php' . ($anker !== '' ? '#' . $anker : ''));
    exit;
}

[$meldungOk, $meldung] = $_SESSION['merkmale_meldung'] ?? [null, null];
unset($_SESSION['merkmale_meldung']);

$definitionen = RassenDatenSchreiben::definitionen(true);
$nutzung = MerkmalVerwaltung::nutzung();
$nachGruppe = [];
$ohneEnglisch = 0;
foreach ($definitionen as $m) {
    $nachGruppe[$m['gruppe']][] = $m;
    $ohneEnglisch += ($m['name_en'] ? 0 : 1) + count(array_filter($m['werte'], fn($w) => !$w['name_en']));
}

const FELD = 'w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne';
const FELD_KLEIN = 'w-full rounded-md border border-sand px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-tanne';

$pageTitle = 'Merkmale verwalten';
require __DIR__ . '/views/partials/header.php';
?>

<div x-data="{ nurOhneEn: false }">
    <h1 class="text-2xl font-extrabold text-fellDk mb-2">Merkmale verwalten</h1>
    <p class="text-fellDk mb-4 max-w-[70ch]">
        Merkmale bilden den Steckbrief jeder Rasse und die Filter im Rassenkatalog. Jeder Wert hat einen deutschen und einen englischen Namen,
        die Seiten zeigen ihn in der gewählten Sprache. Fehlt der englische, erscheint der deutsche.
    </p>

    <?php if ($meldung !== null): ?>
        <p class="<?= $meldungOk ? 'bg-tanne/10 border-tanne/40 text-tanneDk' : 'bg-red-50 border-red-200 text-red-700' ?> border rounded-lg px-4 py-3 mb-6" role="<?= $meldungOk ? 'status' : 'alert' ?>"><?= e($meldung) ?></p>
    <?php endif; ?>

    <div class="flex flex-wrap items-center gap-4 mb-6">
        <label class="inline-flex items-center gap-2 text-sm">
            <input type="checkbox" x-model="nurOhneEn" class="rounded border-sand text-tanne focus:ring-tanne">
            Nur Einträge ohne englischen Namen (<?= $ohneEnglisch ?>)
        </label>
        <a href="#neu" class="text-sm text-tanne underline hover:text-tanneDk">Neues Merkmal anlegen</a>
    </div>

    <?php foreach (RassenDaten::GRUPPEN + array_fill_keys(array_keys($nachGruppe), null) as $gruppe => $gruppenName):
        if (empty($nachGruppe[$gruppe])) {
            continue;
        }
        $gruppeOhneEn = 0;
        foreach ($nachGruppe[$gruppe] as $m) {
            $gruppeOhneEn += ($m['name_en'] ? 0 : 1) + count(array_filter($m['werte'], fn($w) => !$w['name_en']));
        } ?>
        <section x-show="!nurOhneEn || <?= $gruppeOhneEn ?> > 0">
        <h2 class="text-sm font-black uppercase tracking-wide text-fellDk mt-8 mb-3"><?= e($gruppenName ?? $gruppe) ?></h2>
        <div class="space-y-3">
        <?php foreach ($nachGruppe[$gruppe] as $m):
            $mitWerten = in_array($m['typ'], MerkmalVerwaltung::MIT_WERTEN, true);
            $fehlendEn = ($m['name_en'] ? 0 : 1) + count(array_filter($m['werte'], fn($w) => !$w['name_en']));
            $rassen = $nutzung['merkmale'][$m['id']] ?? 0;
            $formId = 'werte-' . $m['schluessel']; ?>
            <details id="merkmal-<?= e($m['schluessel']) ?>" class="bg-white/80 rounded-2xl shadow border border-sand group"
                x-show="!nurOhneEn || <?= $fehlendEn ?> > 0">
                <summary class="cursor-pointer list-none px-5 py-4 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <span class="font-bold text-fellDk"><?= e($m['name_de']) ?></span>
                    <span class="text-sm text-fellDk/70" lang="en"><?= $m['name_en'] ? e($m['name_en']) : '<span class="text-fell">EN fehlt</span>' ?></span>
                    <span class="text-xs text-fellDk/60"><?= e(MerkmalVerwaltung::TYPEN[$m['typ']] ?? $m['typ']) ?><?= $mitWerten ? ' · ' . count($m['werte']) . ' Werte' : '' ?> · <?= $rassen ?> Rassen<?= $m['filterbar'] ? ' · im Filter' : '' ?></span>
                    <?php if ($fehlendEn > 0): ?><span class="text-xs font-semibold text-fell"><?= $fehlendEn ?> × EN?</span><?php endif; ?>
                    <span class="ml-auto text-fellDk/50 group-open:rotate-180 transition-transform" aria-hidden="true">▾</span>
                </summary>

                <div class="px-5 pb-5 border-t border-sand pt-4 space-y-6">
                    <!-- Merkmal selbst -->
                    <form method="post" action="/admin_merkmale.php" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
                        <?= csrf_field() ?>
                        <input type="hidden" name="aktion" value="merkmal_aendern">
                        <input type="hidden" name="merkmal_id" value="<?= $m['id'] ?>">
                        <label class="text-sm">Name deutsch
                            <input name="name_de" value="<?= e($m['name_de']) ?>" required maxlength="100" class="<?= FELD ?> mt-1">
                        </label>
                        <label class="text-sm">Name englisch
                            <input name="name_en" value="<?= e((string) $m['name_en']) ?>" maxlength="100" lang="en" class="<?= FELD ?> mt-1">
                        </label>
                        <label class="text-sm">Gruppe
                            <select name="gruppe" class="<?= FELD ?> mt-1">
                                <?php foreach (RassenDaten::GRUPPEN as $g => $gName): ?>
                                    <option value="<?= e($g) ?>" <?= $g === $m['gruppe'] ? 'selected' : '' ?>><?= e($gName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="text-sm">Reihenfolge
                            <input name="reihenfolge" type="number" min="0" max="9999" value="<?= $m['reihenfolge'] ?>" class="<?= FELD ?> mt-1">
                        </label>
                        <?php if ($m['typ'] === 'zahl_spanne'): ?>
                            <label class="text-sm">Einheit
                                <select name="einheit" class="<?= FELD ?> mt-1">
                                    <?php foreach (MerkmalVerwaltung::EINHEITEN as $eh => $ehName): ?>
                                        <option value="<?= e($eh) ?>" <?= $eh === (string) $m['einheit'] ? 'selected' : '' ?>><?= e($ehName) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        <?php endif; ?>
                        <label class="inline-flex items-center gap-2 text-sm min-h-[42px]">
                            <input type="checkbox" name="filterbar" value="1" <?= $m['filterbar'] ? 'checked' : '' ?> class="rounded border-sand text-tanne focus:ring-tanne">
                            Im Katalog-Filter zeigen
                        </label>
                        <?php if ($m['typ'] === 'mehrfach'): ?>
                            <label class="text-sm">Mehrere Häkchen im Filter
                                <select name="verknuepfung" class="<?= FELD ?> mt-1">
                                    <option value="und" <?= $m['verknuepfung'] !== 'oder' ? 'selected' : '' ?>>alle müssen passen</option>
                                    <option value="oder" <?= $m['verknuepfung'] === 'oder' ? 'selected' : '' ?>>einer reicht</option>
                                </select>
                            </label>
                        <?php endif; ?>
                        <div class="flex gap-2">
                            <button type="submit" class="min-h-[42px] bg-tanne hover:bg-tanneDk text-creme font-bold px-4 rounded-lg">Speichern</button>
                        </div>
                    </form>

                    <?php if ($mitWerten): ?>
                        <!-- Werteliste -->
                        <form method="post" action="/admin_merkmale.php" id="<?= e($formId) ?>" x-data="{ suche: '' }">
                            <?= csrf_field() ?>
                            <input type="hidden" name="aktion" value="werte">
                            <!-- Standardknopf für Enter: speichert, statt die erste Zeile zu löschen -->
                            <button type="submit" name="aktion_wert" value="speichern:0" class="sr-only" tabindex="-1" aria-hidden="true">Werte speichern</button>
                            <input type="hidden" name="merkmal_id" value="<?= $m['id'] ?>">
                            <div class="flex flex-wrap items-center justify-between gap-3 mb-2">
                                <h3 class="font-bold text-fellDk">Werte</h3>
                                <?php if (count($m['werte']) > 8): ?>
                                    <input type="search" x-model="suche" placeholder="Wert suchen …" aria-label="Werte von <?= e($m['name_de']) ?> durchsuchen" class="<?= FELD_KLEIN ?> max-w-xs">
                                <?php endif; ?>
                            </div>
                            <?php if (!$m['werte']): ?>
                                <p class="text-sm text-fellDk/70">Noch keine Werte. Unten den ersten anlegen.</p>
                            <?php else: ?>
                                <div class="hidden md:grid grid-cols-[1fr_1fr_5rem_4rem_minmax(12rem,1fr)] gap-2 text-xs font-semibold text-fellDk/70 px-1 mb-1">
                                    <span>Deutsch</span><span>Englisch</span><span>Reihenf.</span><span>Rassen</span><span>Löschen oder zusammenführen</span>
                                </div>
                                <div class="space-y-2 md:space-y-1">
                                <?php foreach ($m['werte'] as $schl => $w):
                                    $n = $nutzung['werte'][$w['id']] ?? 0;
                                    $suchtext = mb_strtolower($w['name_de'] . ' ' . $w['name_en']); ?>
                                    <div class="grid grid-cols-2 md:grid-cols-[1fr_1fr_5rem_4rem_minmax(12rem,1fr)] gap-2 items-center border-b border-sand/60 md:border-0 pb-2 md:pb-0"
                                        x-show="(suche === '' || <?= e(json_encode($suchtext)) ?>.includes(suche.toLowerCase())) && (!nurOhneEn || <?= $w['name_en'] ? 'false' : 'true' ?>)">
                                        <input name="w[<?= $w['id'] ?>][de]" value="<?= e($w['name_de']) ?>" required maxlength="100" aria-label="<?= e($w['name_de']) ?> deutsch" class="<?= FELD_KLEIN ?>">
                                        <input name="w[<?= $w['id'] ?>][en]" value="<?= e((string) $w['name_en']) ?>" maxlength="100" lang="en" placeholder="EN fehlt" aria-label="<?= e($w['name_de']) ?> englisch" class="<?= FELD_KLEIN ?> <?= $w['name_en'] ? '' : 'border-pfote' ?>">
                                        <input name="w[<?= $w['id'] ?>][r]" type="number" min="0" max="9999" value="<?= $w['reihenfolge'] ?>" aria-label="<?= e($w['name_de']) ?> Reihenfolge" class="<?= FELD_KLEIN ?>">
                                        <span class="text-sm tabular-nums text-fellDk/80" title="Rassen mit diesem Wert"><?= $n ?></span>
                                        <div class="col-span-2 md:col-span-1 flex items-center gap-2">
                                            <?php if ($n === 0): ?>
                                                <button type="submit" name="aktion_wert" value="loeschen:<?= $w['id'] ?>" formnovalidate
                                                    onclick="return confirm(<?= e(json_encode('„' . $w['name_de'] . '“ löschen?', JSON_UNESCAPED_UNICODE)) ?>);"
                                                    class="text-sm text-red-700 underline hover:text-red-900">Löschen</button>
                                            <?php endif; ?>
                                            <?php if (count($m['werte']) > 1): ?>
                                                <select name="ziel[<?= $w['id'] ?>]" aria-label="<?= e($w['name_de']) ?> zusammenführen mit" class="<?= FELD_KLEIN ?> min-w-0 flex-1">
                                                    <option value="">zusammenführen mit …</option>
                                                    <?php foreach ($m['werte'] as $zielSchl => $z): if ($zielSchl === $schl) continue; ?>
                                                        <option value="<?= $z['id'] ?>"><?= e($z['name_de']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" name="aktion_wert" value="zusammen:<?= $w['id'] ?>" formnovalidate
                                                    onclick="return confirm(<?= e(json_encode('„' . $w['name_de'] . '“ in den gewählten Wert überführen? Bei ' . $n . ' Rassen wird der Wert ersetzt.', JSON_UNESCAPED_UNICODE)) ?>);"
                                                    class="text-sm text-tanne underline hover:text-tanneDk shrink-0">OK</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                                <button type="submit" name="aktion_wert" value="speichern:0" class="mt-3 min-h-[42px] bg-tanne hover:bg-tanneDk text-creme font-bold px-4 rounded-lg">Werte speichern</button>
                            <?php endif; ?>
                        </form>

                        <form method="post" action="/admin_merkmale.php" class="flex flex-wrap items-end gap-3">
                            <?= csrf_field() ?>
                            <input type="hidden" name="aktion" value="wert_anlegen">
                            <input type="hidden" name="merkmal_id" value="<?= $m['id'] ?>">
                            <label class="text-sm flex-1 min-w-[10rem]">Neuer Wert deutsch
                                <input name="name_de" required maxlength="100" class="<?= FELD ?> mt-1">
                            </label>
                            <label class="text-sm flex-1 min-w-[10rem]">englisch
                                <input name="name_en" maxlength="100" lang="en" class="<?= FELD ?> mt-1">
                            </label>
                            <button type="submit" class="min-h-[42px] border border-tanne text-tanne hover:bg-tanne hover:text-creme font-bold px-4 rounded-lg">Wert anlegen</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($rassen === 0): ?>
                        <form method="post" action="/admin_merkmale.php"
                            onsubmit="return confirm(<?= e(json_encode('Merkmal „' . $m['name_de'] . '“ mit allen Werten löschen?', JSON_UNESCAPED_UNICODE)) ?>);">
                            <?= csrf_field() ?>
                            <input type="hidden" name="aktion" value="merkmal_loeschen">
                            <input type="hidden" name="merkmal_id" value="<?= $m['id'] ?>">
                            <button type="submit" class="text-sm text-red-700 underline hover:text-red-900">Merkmal löschen</button>
                        </form>
                    <?php else: ?>
                        <p class="text-xs text-fellDk/70">Löschen geht erst, wenn keine Rasse das Merkmal mehr nutzt.</p>
                    <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
        </div>
        </section>
    <?php endforeach; ?>

    <!-- Neues Merkmal -->
    <section id="neu" class="bg-white/80 rounded-2xl shadow border border-sand p-6 mt-10" x-data="{ typ: 'mehrfach' }">
        <h2 class="text-lg font-bold text-fellDk mb-1">Neues Merkmal</h2>
        <p class="text-sm text-fellDk/80 mb-4">Der Typ lässt sich später nicht mehr ändern. Werte kommen nach dem Anlegen dazu.</p>
        <form method="post" action="/admin_merkmale.php" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="aktion" value="merkmal_anlegen">
            <label class="text-sm">Name deutsch
                <input name="name_de" required maxlength="100" placeholder="z. B. Ohrform" class="<?= FELD ?> mt-1">
            </label>
            <label class="text-sm">Name englisch
                <input name="name_en" maxlength="100" lang="en" placeholder="z. B. Ear shape" class="<?= FELD ?> mt-1">
            </label>
            <label class="text-sm">Typ
                <select name="typ" x-model="typ" class="<?= FELD ?> mt-1">
                    <?php foreach (MerkmalVerwaltung::TYPEN as $typ => $typName): ?>
                        <option value="<?= e($typ) ?>"><?= e($typName) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="text-sm">Gruppe
                <select name="gruppe" class="<?= FELD ?> mt-1">
                    <?php foreach (RassenDaten::GRUPPEN as $g => $gName): ?>
                        <option value="<?= e($g) ?>" <?= $g === 'weitere' ? 'selected' : '' ?>><?= e($gName) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="text-sm" x-show="typ === 'zahl_spanne'" x-cloak>Einheit
                <select name="einheit" class="<?= FELD ?> mt-1">
                    <?php foreach (MerkmalVerwaltung::EINHEITEN as $eh => $ehName): ?>
                        <option value="<?= e($eh) ?>"><?= e($ehName) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="inline-flex items-center gap-2 text-sm min-h-[42px]" x-show="typ === 'zahl_spanne'" x-cloak>
                <input type="checkbox" name="je_geschlecht" value="1" class="rounded border-sand text-tanne focus:ring-tanne">
                Getrennt nach Rüde und Hündin
            </label>
            <label class="text-sm" x-show="typ === 'mehrfach'">Mehrere Häkchen im Filter
                <select name="verknuepfung" class="<?= FELD ?> mt-1">
                    <option value="und">alle müssen passen</option>
                    <option value="oder">einer reicht</option>
                </select>
            </label>
            <label class="inline-flex items-center gap-2 text-sm min-h-[42px]">
                <input type="checkbox" name="filterbar" value="1" checked class="rounded border-sand text-tanne focus:ring-tanne">
                Im Katalog-Filter zeigen
            </label>
            <div>
                <button type="submit" class="min-h-[42px] bg-tanne hover:bg-tanneDk text-creme font-bold px-5 rounded-lg">Merkmal anlegen</button>
            </div>
        </form>
    </section>
</div>

<script>
    // Nach dem Speichern zum bearbeiteten Merkmal: aufgeklappt zeigen
    (function () {
        var ziel = location.hash && document.getElementById(location.hash.slice(1));
        if (ziel && ziel.tagName === 'DETAILS') {
            ziel.open = true;
        }
    })();
</script>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
