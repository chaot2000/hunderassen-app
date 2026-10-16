<?php
/**
 * views/partials/rasse_formular.php
 *
 * Bausteine für "Rasse bearbeiten": Steckbrief (alle Merkmale, aus der
 * Tabelle merkmale erzeugt) und Texte je Abschnitt, Deutsch und Englisch
 * nebeneinander. Feldnamen passen zu RassenDatenSchreiben::ausEingabe()
 * und ::texteAusEingabe().
 *
 * Die Verwaltungsseiten sind vorerst deutsch (PLAN.md 9, Schritt 3.4).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../models/RassenDaten.php';

const MERKMAL_GRUPPEN = RassenDaten::GRUPPEN;

const TEXT_ABSCHNITT_NAMEN = [
    'kurzportraet' => 'Kurzporträt',
    'allgemein'    => 'Allgemein (ohne Überschrift)',
    'wesen'        => 'Wesen',
    'haltung'      => 'Haltung und Erziehung',
    'pflege'       => 'Pflege',
    'aussehen'     => 'Aussehen',
    'herkunft'     => 'Herkunft und Geschichte',
    'zuchtzweck'   => 'Zuchtzweck',
    'standard'     => 'Angaben aus dem Standard',
    'weitere'      => 'Weitere Abschnitte',
];

const FORM_FELD = 'w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne';

/**
 * @param list<array> $definitionen RassenDatenSchreiben::definitionen()
 * @param array<string, array> $werte RassenDatenSchreiben::formularWerte() bzw. ausEingabe()['werte']
 */
function render_steckbrief_formular(array $definitionen, array $werte): void
{
    $nachGruppe = [];
    foreach ($definitionen as $m) {
        // Auswahlmerkmale ohne Begriffe (z. B. "weitere" vor dem ersten Eintrag) nicht zeigen
        if (in_array($m['typ'], ['mehrfach', 'auswahl', 'stufe'], true) && !$m['werte']) {
            continue;
        }
        $nachGruppe[$m['gruppe']][] = $m;
    }
    $laender = require __DIR__ . '/../../daten/laender.php';
    uasort($laender, fn($a, $b) => strcoll($a[0], $b[0]));
    ?>
    <div class="bg-white/80 rounded-2xl shadow border border-sand p-6">
        <h2 class="text-lg font-bold text-fellDk mb-1">Steckbrief</h2>
        <p class="text-sm text-fellDk/80 mb-4">Werte und Begriffe haben feste deutsche und englische Namen, die Rassenseite zeigt sie in der gewählten Sprache. Neue Begriffe kommen in der Merkmal-Verwaltung dazu.</p>
        <?php foreach (MERKMAL_GRUPPEN + array_fill_keys(array_keys($nachGruppe), null) as $gruppe => $gruppenName):
            if (empty($nachGruppe[$gruppe])) {
                continue;
            } ?>
            <fieldset class="border-t border-sand pt-4 mt-4 first-of-type:border-t-0 first-of-type:pt-0 first-of-type:mt-0">
                <legend class="text-sm font-black uppercase tracking-wide text-fellDk mb-3"><?= e($gruppenName ?? $gruppe) ?></legend>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                    <?php foreach ($nachGruppe[$gruppe] as $m): render_merkmal_feld($m, $werte[$m['schluessel']] ?? null, $laender); endforeach; ?>
                </div>
            </fieldset>
        <?php endforeach; ?>
    </div>
    <?php
}

function render_merkmal_feld(array $m, ?array $wert, array $laender): void
{
    $id = 'merkmal-' . $m['schluessel'];
    $name = 'merkmal[' . $m['schluessel'] . ']';
    $einheit = ['cm' => 'cm', 'kg' => 'kg', 'jahre' => 'Jahre'][$m['einheit'] ?? ''] ?? '';
    $breit = $m['typ'] === 'mehrfach' && count($m['werte']) > 8;
    ?>
    <div class="<?= $breit ? 'md:col-span-2' : '' ?>">
        <?php if ($m['typ'] === 'zahl_spanne'): ?>
            <p class="block text-sm font-semibold mb-1" id="<?= e($id) ?>-label"><?= e($m['name_de']) ?><?= $einheit ? ' in ' . e($einheit) : '' ?></p>
            <?php foreach ($m['je_geschlecht'] ? ['ruede' => 'Rüde', 'huendin' => 'Hündin', 'alle' => 'beide'] : ['alle' => ''] as $g => $gName):
                [$von, $bis] = $wert['spannen'][$g] ?? [null, null]; ?>
                <div class="flex items-center gap-2 mb-2" role="group" aria-labelledby="<?= e($id) ?>-label">
                    <?php if ($gName !== ''): ?><span class="w-16 text-sm"><?= e($gName) ?></span><?php endif; ?>
                    <input type="text" inputmode="decimal" name="<?= e($name) ?>[spanne][<?= $g ?>][von]" value="<?= e((string) $von) ?>"
                        aria-label="<?= e(trim($m['name_de'] . ' ' . $gName)) ?> von" placeholder="von" class="<?= FORM_FELD ?> max-w-[7rem]">
                    <span aria-hidden="true">–</span>
                    <input type="text" inputmode="decimal" name="<?= e($name) ?>[spanne][<?= $g ?>][bis]" value="<?= e((string) $bis) ?>"
                        aria-label="<?= e(trim($m['name_de'] . ' ' . $gName)) ?> bis" placeholder="bis" class="<?= FORM_FELD ?> max-w-[7rem]">
                </div>
            <?php endforeach; ?>

        <?php elseif ($m['typ'] === 'mehrfach'): ?>
            <fieldset <?= $breit ? 'x-data="{ suche: \'\' }"' : '' ?>>
                <legend class="block text-sm font-semibold mb-1"><?= e($m['name_de']) ?></legend>
                <?php if ($breit): ?>
                    <input type="search" x-model="suche" placeholder="Begriff suchen …" aria-label="<?= e($m['name_de']) ?> durchsuchen" class="<?= FORM_FELD ?> max-w-xs mb-2 py-1.5 text-sm">
                <?php endif; ?>
                <div class="flex flex-wrap gap-2 <?= $breit ? 'max-h-56 overflow-y-auto pr-1' : '' ?>">
                    <?php foreach ($m['werte'] as $schl => $w):
                        $an = in_array($schl, $wert['werte'] ?? [], true); ?>
                        <label class="inline-flex items-center gap-1.5 text-sm border border-sand rounded-full px-3 py-1 cursor-pointer has-[:checked]:border-tanne has-[:checked]:bg-tanne/10 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-tanne"
                            <?= $breit ? 'x-show="suche === \'\' || ' . e(json_encode(mb_strtolower($w['name_de'] . ' ' . $w['name_en']))) . '.includes(suche.toLowerCase())"' : '' ?>>
                            <input type="checkbox" name="<?= e($name) ?>[werte][]" value="<?= e($schl) ?>" <?= $an ? 'checked' : '' ?> class="rounded border-sand text-tanne focus:ring-tanne">
                            <?= e($w['name_de']) ?>
                            <?php if (!$w['name_en']): ?><span class="text-xs text-fell" title="Englischer Name fehlt">EN?</span><?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

        <?php elseif (in_array($m['typ'], ['auswahl', 'stufe', 'ja_nein'], true)):
            $aktuell = $m['typ'] === 'ja_nein' ? ($wert['text'] ?? '') : ($wert['werte'][0] ?? '');
            $optionen = $m['typ'] === 'ja_nein' ? ['ja' => 'ja', 'nein' => 'nein'] : array_map(fn($w) => $w['name_de'], $m['werte']); ?>
            <label for="<?= e($id) ?>" class="block text-sm font-semibold mb-1"><?= e($m['name_de']) ?></label>
            <select id="<?= e($id) ?>" name="<?= e($name) ?>[wert]" class="<?= FORM_FELD ?>">
                <option value="">keine Angabe</option>
                <?php foreach ($optionen as $schl => $label): ?>
                    <option value="<?= e((string) $schl) ?>" <?= (string) $schl === $aktuell ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>

        <?php elseif ($m['typ'] === 'land'): ?>
            <label for="<?= e($id) ?>" class="block text-sm font-semibold mb-1"><?= e($m['name_de']) ?></label>
            <select id="<?= e($id) ?>" name="<?= e($name) ?>[land]" class="<?= FORM_FELD ?>">
                <option value="">keine Angabe</option>
                <?php foreach ($laender as $code => [$de]): ?>
                    <option value="<?= e($code) ?>" <?= ($wert['text'] ?? '') === $code ? 'selected' : '' ?>><?= e($de) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
    </div>
    <?php
}

/** @param array{de: array<string, string>, en: array<string, string>} $texte */
function render_texte_formular(array $texte): void
{
    ?>
    <div class="bg-white/80 rounded-2xl shadow border border-sand p-6">
        <h2 class="text-lg font-bold text-fellDk mb-1">Texte</h2>
        <p class="text-sm text-fellDk/80 mb-4">Ein Feld je Abschnitt, links deutsch, rechts englisch. Leere Abschnitte erscheinen auf der Rassenseite nicht. Fehlt ein Abschnitt in einer Sprache, zeigt die Seite den anderen mit Hinweis. Absätze mit einer Leerzeile trennen.</p>
        <?php foreach (TEXT_ABSCHNITT_NAMEN as $abschnitt => $titel):
            $zeilen = $abschnitt === 'kurzportraet' ? 3 : 6; ?>
            <fieldset class="border-t border-sand pt-4 mt-4 first-of-type:border-t-0 first-of-type:pt-0 first-of-type:mt-0">
                <legend class="text-sm font-black uppercase tracking-wide text-fellDk mb-2"><?= e($titel) ?></legend>
                <?php if ($abschnitt === 'weitere'): ?>
                    <p class="text-xs text-fellDk/80 mb-2">Eigene Abschnitte mit Überschrift: Zeile mit <code class="font-mono">## Titel</code>, darunter der Text.</p>
                <?php endif; ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach (['de' => 'Deutsch', 'en' => 'Englisch'] as $sprache => $spracheName): ?>
                        <div>
                            <label for="text-<?= $sprache ?>-<?= $abschnitt ?>" class="block text-xs font-semibold mb-1 text-fellDk/80"><?= $spracheName ?></label>
                            <textarea id="text-<?= $sprache ?>-<?= $abschnitt ?>" name="text[<?= $sprache ?>][<?= $abschnitt ?>]" rows="<?= $zeilen ?>" lang="<?= $sprache ?>"
                                class="<?= FORM_FELD ?> leading-relaxed text-sm"><?= e($texte[$sprache][$abschnitt] ?? '') ?></textarea>
                        </div>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        <?php endforeach; ?>
    </div>
    <?php
}
