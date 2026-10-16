<?php
/**
 * models/InhaltsDatei.php
 *
 * Dateiformat für das private Inhalte-Repo (PLAN.md Abschnitt 11):
 * je Rasse eine Markdown-Datei, dazu merkmale.md mit allen Werten.
 * Hin (schreiben) und zurück (lesen) ohne Datenbank, damit Export,
 * Importdatei und Tests dieselbe Logik nutzen.
 *
 * Rasse (rassen/012-afghanischer-windhund.md):
 *
 *   # Afghanischer Windhund
 *
 *   ## Rasse
 *   - id: 12
 *   - name_en: Afghan Hound
 *
 *   ## Merkmale
 *   - **Schulterhöhe** `schulterhoehe`: Rüde 68–74 cm, Hündin 63–69 cm
 *   - **Farben** `farben`: schwarz, creme
 *   - **Fellart** `fellart`:
 *
 *   ## Deutsch
 *   ### Kurzporträt `kurzportraet`
 *   Text …
 *
 *   ## English
 *   ### Kurzporträt `kurzportraet`
 *   Text …
 *
 * Werte stehen mit ihrem deutschen Namen. Leere Merkmale stehen mit,
 * damit man sie im Diff füllen kann. Leere Textabschnitte fehlen.
 *
 * Werte (merkmale.md):
 *
 *   ## Farben `farben`
 *   - `schwarz`: schwarz | black
 */

declare(strict_types=1);

require_once __DIR__ . '/RassenDaten.php';

class InhaltsDatei
{
    /** Textabschnitte (Schlüssel wie RassenDaten::ABSCHNITTE) mit Überschrift in der Datei */
    public const ABSCHNITTE = [
        'kurzportraet' => 'Kurzporträt',
        'allgemein'    => 'Allgemein',
        'wesen'        => 'Wesen',
        'haltung'      => 'Haltung und Erziehung',
        'pflege'       => 'Pflege',
        'aussehen'     => 'Aussehen',
        'herkunft'     => 'Herkunft und Geschichte',
        'zuchtzweck'   => 'Zuchtzweck',
        'standard'     => 'Angaben aus dem Standard',
        'weitere'      => 'Weitere Abschnitte',
    ];

    private const SPRACHEN = ['de' => 'Deutsch', 'en' => 'English'];
    private const GESCHLECHT = ['ruede' => 'Rüde', 'huendin' => 'Hündin', 'alle' => 'beide'];

    // ------------------------------------------------------------------
    // Rasse schreiben
    // ------------------------------------------------------------------

    /**
     * @param array{id: int, name: string, name_en: ?string} $rasse
     * @param array<string, array> $werte Formularformat je Merkmal (RassenDatenSchreiben::formularWerte)
     * @param array{de: array<string, string>, en: array<string, string>} $texte
     * @param list<array> $definitionen RassenDatenSchreiben::definitionen()
     * @param array<string, string> $abschnitte Schlüssel => Titel, in dieser Reihenfolge
     */
    public static function rasseSchreiben(array $rasse, array $werte, array $texte, array $definitionen, array $abschnitte): string
    {
        $z = ['# ' . $rasse['name'], '', '## Rasse', '- id: ' . (int) $rasse['id'], '- name_en: ' . trim((string) ($rasse['name_en'] ?? '')), '', '## Merkmale'];
        foreach ($definitionen as $m) {
            $z[] = rtrim('- **' . $m['name_de'] . '** `' . $m['schluessel'] . '`: ' . self::wertText($m, $werte[$m['schluessel']] ?? null));
        }
        foreach (self::SPRACHEN as $sprache => $titel) {
            $z[] = '';
            $z[] = '## ' . $titel;
            foreach ($abschnitte as $abschnitt => $abschnittTitel) {
                $text = trim(str_replace("\r", '', (string) ($texte[$sprache][$abschnitt] ?? '')));
                if ($text !== '') {
                    $z[] = '';
                    $z[] = '### ' . $abschnittTitel . ' `' . $abschnitt . '`';
                    $z[] = '';
                    $z[] = $text;
                }
            }
        }
        return implode("\n", $z) . "\n";
    }

    /** Formularformat → Zeilentext, z. B. "Rüde 68–74 cm, Hündin 63–69 cm" */
    public static function wertText(array $m, ?array $wert): string
    {
        if ($wert === null) {
            return '';
        }
        $teile = [];
        foreach ($wert['werte'] ?? [] as $w) {
            $teile[] = $m['werte'][$w]['name_de'] ?? $w;
        }
        foreach (array_intersect_key(self::GESCHLECHT, $wert['spannen'] ?? []) as $g => $gName) {
            [$von, $bis] = $wert['spannen'][$g];
            $zahl = fn($z) => str_replace('.', ',', (string) $z);
            $spanne = match (true) {
                $von === null => 'bis ' . $zahl($bis),
                $bis === null => 'ab ' . $zahl($von),
                $von === $bis => $zahl($von),
                default => $zahl($von) . '–' . $zahl($bis),
            };
            $einheit = ['cm' => ' cm', 'kg' => ' kg', 'jahre' => ' Jahre'][$m['einheit'] ?? ''] ?? '';
            $teile[] = ($m['je_geschlecht'] || $g !== 'alle' ? $gName . ' ' : '') . $spanne . $einheit;
        }
        if (($wert['text'] ?? null) !== null) {
            $teile[] = $m['typ'] === 'land'
                ? $wert['text'] . ' (' . RassenDaten::landName((string) $wert['text'], 'de') . ')'
                : (string) $wert['text'];
        }
        return implode(', ', $teile);
    }

    // ------------------------------------------------------------------
    // Rasse lesen
    // ------------------------------------------------------------------

    /**
     * @param array<string, array> $definitionen nach Schlüssel, Werte mit name_de (aus merkmale.md oder der Datenbank)
     * @return array{id: int, name_en: string, merkmale: array<string, ?array>, texte: array{de: array, en: array}, fehler: list<string>}
     */
    public static function rasseLesen(string $inhalt, array $definitionen, array $abschnitte): array
    {
        $ergebnis = ['id' => 0, 'name_en' => '', 'merkmale' => [], 'texte' => ['de' => [], 'en' => []], 'fehler' => []];
        $bereich = null;
        $sprache = null;
        $abschnitt = null;
        $puffer = [];
        $abschliessen = function () use (&$ergebnis, &$sprache, &$abschnitt, &$puffer) {
            if ($sprache !== null && $abschnitt !== null) {
                $text = trim(implode("\n", $puffer));
                if ($text !== '') {
                    $ergebnis['texte'][$sprache][$abschnitt] = $text;
                }
            }
            $puffer = [];
        };
        foreach (explode("\n", str_replace("\r", '', $inhalt)) as $nr => $zeile) {
            $sprachWechsel = array_search(trim($zeile), array_map(fn($t) => '## ' . $t, self::SPRACHEN), true);
            if (in_array(trim($zeile), ['## Rasse', '## Merkmale'], true) || $sprachWechsel !== false) {
                $abschliessen();
                $abschnitt = null;
                $bereich = $sprachWechsel !== false ? 'text' : substr(trim($zeile), 3);
                $sprache = $sprachWechsel !== false ? $sprachWechsel : null;
                continue;
            }
            if ($bereich === 'text' && preg_match('/^### .*`([a-z_]+)`\s*$/u', $zeile, $m)) {
                $abschliessen();
                if (!array_key_exists($m[1], $abschnitte)) {
                    $ergebnis['fehler'][] = 'Zeile ' . ($nr + 1) . ": unbekannter Abschnitt „{$m[1]}“";
                    $abschnitt = null;
                } else {
                    $abschnitt = $m[1];
                }
                continue;
            }
            if ($bereich === 'text') {
                $puffer[] = rtrim($zeile);
            } elseif ($bereich === 'Rasse' && preg_match('/^- (id|name_en):\s*(.*)$/u', $zeile, $m)) {
                $m[1] === 'id' ? $ergebnis['id'] = (int) $m[2] : $ergebnis['name_en'] = trim($m[2]);
            } elseif ($bereich === 'Merkmale' && preg_match('/^- \*\*.*?\*\* `([a-z0-9_]+)`:\s*(.*)$/u', $zeile, $m)) {
                if (!isset($definitionen[$m[1]])) {
                    $ergebnis['fehler'][] = 'Zeile ' . ($nr + 1) . ": unbekanntes Merkmal „{$m[1]}“";
                    continue;
                }
                $wert = self::wertLesen($definitionen[$m[1]], trim($m[2]), $fehler);
                if ($fehler !== null) {
                    $ergebnis['fehler'][] = 'Zeile ' . ($nr + 1) . ": {$fehler}";
                    continue;
                }
                $ergebnis['merkmale'][$m[1]] = $wert;
            }
        }
        $abschliessen();
        if ($ergebnis['id'] <= 0) {
            $ergebnis['fehler'][] = 'id fehlt';
        }
        return $ergebnis;
    }

    /** Zeilentext → Formularformat oder null (leer). Fehlertext in $fehler. */
    public static function wertLesen(array $m, string $text, ?string &$fehler = null): ?array
    {
        $fehler = null;
        if ($text === '') {
            return null;
        }
        $wert = ['werte' => [], 'spannen' => [], 'text' => null];
        // Trenner ist ", "; "2,5" (Dezimalkomma) bleibt ganz
        $teile = array_values(array_filter(array_map('trim', preg_split('/,\s+/u', $text) ?: []), fn($t) => $t !== ''));
        switch ($m['typ']) {
            case 'mehrfach':
            case 'auswahl':
            case 'stufe':
                $nachName = [];
                foreach ($m['werte'] as $schluessel => $w) {
                    $nachName[mb_strtolower($w['name_de'])] = (string) $schluessel;
                }
                foreach ($teile as $t) {
                    $schluessel = $nachName[mb_strtolower($t)] ?? null;
                    if ($schluessel === null) {
                        $fehler = "„{$t}“ ist kein Wert von „{$m['name_de']}“ (in merkmale.md ergänzen)";
                        return null;
                    }
                    if (!in_array($schluessel, $wert['werte'], true)) {
                        $wert['werte'][] = $schluessel;
                    }
                }
                if ($m['typ'] !== 'mehrfach' && count($wert['werte']) > 1) {
                    $fehler = "„{$m['name_de']}“ erlaubt nur einen Wert";
                    return null;
                }
                sort($wert['werte']);
                return $wert;
            case 'ja_nein':
                $t = mb_strtolower($text);
                if (!in_array($t, ['ja', 'nein'], true)) {
                    $fehler = "„{$m['name_de']}“: nur ja oder nein";
                    return null;
                }
                $wert['text'] = $t;
                return $wert;
            case 'land':
                if (!preg_match('/^([A-Za-z]{2})\b/', $text, $treffer)) {
                    $fehler = "„{$m['name_de']}“: Ländercode wie DE erwartet";
                    return null;
                }
                $wert['text'] = strtoupper($treffer[1]);
                return $wert;
            case 'zahl_spanne':
                $zahl = '(\d+(?:[.,]\d+)?)';
                foreach ($teile as $t) {
                    if (!preg_match('/^(?:(Rüde|Hündin|beide)\s+)?(?:bis\s+' . $zahl . '|ab\s+' . $zahl . '|' . $zahl . '(?:\s*[–-]\s*' . $zahl . ')?)\s*(?:cm|kg|Jahre)?$/u', $t, $p)) {
                        $fehler = "„{$m['name_de']}“: „{$t}“ nicht lesbar (z. B. „Rüde 58–61 cm“, „bis 53 cm“)";
                        return null;
                    }
                    $g = array_search($p[1] ?? '', self::GESCHLECHT, true) ?: 'alle';
                    if (!$m['je_geschlecht'] && $g !== 'alle') {
                        $fehler = "„{$m['name_de']}“ unterscheidet nicht nach Geschlecht";
                        return null;
                    }
                    $n = fn(int $i) => isset($p[$i]) && $p[$i] !== '' ? self::zahl($p[$i]) : null;
                    [$von, $bis] = match (true) {
                        $n(2) !== null => [null, $n(2)],
                        $n(3) !== null => [$n(3), null],
                        default => [$n(4), $n(5) ?? $n(4)],
                    };
                    if ($von !== null && $bis !== null && (float) $von > (float) $bis) {
                        $fehler = "„{$m['name_de']}“: Von-Wert größer als Bis-Wert";
                        return null;
                    }
                    $wert['spannen'][$g] = [$von, $bis];
                }
                ksort($wert['spannen']);
                return $wert;
        }
        $fehler = "unbekannter Typ „{$m['typ']}“";
        return null;
    }

    /** "12,5" → "12.5", "30" → "30" (wie RassenDatenSchreiben::zahlText) */
    private static function zahl(string $z): string
    {
        return rtrim(rtrim(number_format((float) str_replace(',', '.', $z), 1, '.', ''), '0'), '.');
    }

    // ------------------------------------------------------------------
    // merkmale.md
    // ------------------------------------------------------------------

    public static function merkmaleSchreiben(array $definitionen): string
    {
        $z = ['# Merkmale und Werte', '', 'Je Wert: `schlüssel`: deutsch | englisch. Neue Werte mit neuem Schlüssel (klein, a–z, 0–9, _) unten anfügen.',
            'Die Rassendateien nennen Werte mit dem deutschen Namen. Die Typzeile nicht ändern.'];
        foreach ($definitionen as $m) {
            $z[] = '';
            $z[] = '## ' . $m['name_de'] . ' `' . $m['schluessel'] . '`';
            $z[] = '';
            $z[] = 'Typ: ' . $m['typ'] . ($m['einheit'] ? ', Einheit: ' . $m['einheit'] : '') . ($m['je_geschlecht'] ? ', je Geschlecht' : '');
            if (in_array($m['typ'], ['mehrfach', 'auswahl', 'stufe'], true)) {
                $z[] = '';
            }
            foreach ($m['werte'] as $schluessel => $w) {
                $z[] = '- `' . $schluessel . '`: ' . $w['name_de'] . ' | ' . trim((string) $w['name_en']);
            }
        }
        return implode("\n", $z) . "\n";
    }

    /**
     * merkmale.md → Definitionen im Format von RassenDatenSchreiben::nachSchluessel()
     * (ohne Datenbank-IDs), Werte je Merkmal mit Namen.
     *
     * @return array{definitionen: array<string, array>, fehler: list<string>}
     */
    public static function merkmaleLesen(string $inhalt): array
    {
        $defs = [];
        $fehler = [];
        $merkmal = null;
        foreach (explode("\n", str_replace("\r", '', $inhalt)) as $nr => $zeile) {
            if (preg_match('/^## (.*) `([a-z0-9_]+)`\s*$/u', $zeile, $m)) {
                $merkmal = $m[2];
                $defs[$merkmal] = ['schluessel' => $merkmal, 'name_de' => $m[1], 'typ' => null, 'einheit' => null, 'je_geschlecht' => false, 'werte' => []];
            } elseif ($merkmal !== null && preg_match('/^Typ: ([a-z_]+)(?:, Einheit: ([a-z]+))?(, je Geschlecht)?\s*$/u', $zeile, $m)) {
                $defs[$merkmal]['typ'] = $m[1];
                $defs[$merkmal]['einheit'] = ($m[2] ?? '') !== '' ? $m[2] : null;
                $defs[$merkmal]['je_geschlecht'] = !empty($m[3]);
            } elseif ($merkmal !== null && preg_match('/^- `([a-z0-9_]+)`:\s*(.*?)\s*\|\s*(.*?)\s*$/u', $zeile, $m)) {
                if ($m[2] === '' || mb_strlen($m[2]) > 100 || mb_strlen($m[3]) > 100 || str_contains($m[2], ',')) {
                    $fehler[] = 'merkmale.md Zeile ' . ($nr + 1) . ': Name leer, zu lang oder mit Komma';
                    continue;
                }
                $defs[$merkmal]['werte'][$m[1]] = ['name_de' => $m[2], 'name_en' => $m[3] !== '' ? $m[3] : null];
            }
        }
        foreach ($defs as $schluessel => $d) {
            if ($d['typ'] === null) {
                $fehler[] = "merkmale.md: Typzeile fehlt bei „{$schluessel}“";
                unset($defs[$schluessel]);
            }
        }
        return ['definitionen' => $defs, 'fehler' => $fehler];
    }

    // ------------------------------------------------------------------
    // Vergleichen
    // ------------------------------------------------------------------

    /** Text vergleichbar machen: ohne \r, Zeilen rechts und Ganzes beidseitig getrimmt, leer = null */
    public static function textNorm(?string $text): ?string
    {
        $t = trim(implode("\n", array_map('rtrim', explode("\n", str_replace("\r", '', (string) $text)))));
        return $t === '' ? null : $t;
    }

    /** Merkmalswert vergleichbar machen, leer = null */
    public static function wertNorm(?array $wert): ?array
    {
        if ($wert === null) {
            return null;
        }
        $werte = array_values(array_map('strval', $wert['werte'] ?? []));
        sort($werte);
        $spannen = [];
        foreach ($wert['spannen'] ?? [] as $g => [$von, $bis]) {
            $z = fn($x) => $x === null || $x === '' ? null : rtrim(rtrim(number_format((float) $x, 1, '.', ''), '0'), '.');
            $spannen[$g] = [$z($von), $z($bis)];
        }
        ksort($spannen);
        $text = isset($wert['text']) && $wert['text'] !== '' ? (string) $wert['text'] : null;
        return !$werte && !$spannen && $text === null ? null : ['werte' => $werte, 'spannen' => $spannen, 'text' => $text];
    }

    /** Dateiname für eine Rasse: "012-afghanischer-windhund.md" */
    public static function dateiname(int $id, string $name): string
    {
        $s = mb_strtolower(trim(explode(',', $name)[0]));
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ç' => 'c', 'ñ' => 'n', 'ï' => 'i', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u']);
        $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
        return sprintf('%03d', $id) . '-' . substr($s !== '' ? $s : 'rasse', 0, 60) . '.md';
    }
}
