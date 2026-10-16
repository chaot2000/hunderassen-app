<?php
/**
 * models/MerkmalVorschlaege.php
 *
 * Vorschläge für leere Merkmale (PLAN.md Abschnitt 10, Schritt 4),
 * geprüft und übernommen auf admin_merkmale_befuellen.php:
 *  - TheDogAPI: Schulterhöhe, Gewicht, Lebenserwartung, Ursprungsland,
 *    nur bei sicher zugeordneten Rassen
 *  - VDH-Text: Schulterhöhe und Gewicht (je Rüde/Hündin, wenn genannt),
 *    Farben, jeweils mit der Textstelle als Beleg
 *
 * Das Herauslesen aus dem Text ist bewusst vorsichtig: lieber kein
 * Vorschlag als ein falscher. Unplausible Zahlen (Haarlänge, Toleranz)
 * fallen über Mindest- und Höchstwerte weg.
 *
 * Werte im Formularformat von RassenDatenSchreiben:
 *   ['werte' => [...], 'spannen' => [geschlecht => [von, bis]], 'text' => ?string]
 */

declare(strict_types=1);

require_once __DIR__ . '/RassenDaten.php';
require_once __DIR__ . '/RassenDatenSchreiben.php';
require_once __DIR__ . '/RassenFilter.php';
require_once __DIR__ . '/TheDogApiAbgleich.php';

class MerkmalVorschlaege
{
    /** Merkmal => Quellen, in dieser Reihenfolge auf der Prüfseite */
    public const MERKMALE = [
        'schulterhoehe'   => ['vdh', 'thedogapi'],
        'gewicht'         => ['vdh', 'thedogapi'],
        'farben'          => ['vdh'],
        'ursprungsland'   => ['thedogapi'],
        'lebenserwartung' => ['thedogapi'],
    ];

    /** Plausible Grenzen je Einheit */
    private const GRENZEN = ['cm' => [15.0, 110.0], 'kg' => [1.0, 120.0], 'jahre' => [4.0, 25.0]];

    /** Textabschnitte in der Reihenfolge, in der sie befragt werden */
    private const ABSCHNITTE = ['standard', 'aussehen', 'kurzportraet', 'allgemein', 'weitere', 'herkunft'];

    /**
     * Farbwörter → Wert von "farben". Zusammengesetzte zuerst, ihr Treffer
     * wird danach ausgeblendet ("wolfsgrau" zählt nicht noch als "grau").
     */
    private const FARBEN = [
        'schwarz_loh'  => '/schwarz[\s-]*(?:mit|und)?[\s-]*loh|black and tan/u',
        'dreifarbig'   => '/dreifarbig|tri-?colou?r/u',
        'wildfarben'   => '/wildfarb|wolfsgrau|wolfsfarb/u',
        'leberfarben'  => '/leberfarb|leberbraun|leber\b/u',
        'merle'        => '/merle/u',
        'gestromt'     => '/gestromt|stromung/u',
        'gescheckt'    => '/gescheckt|scheckig|schecke/u',
        'gefleckt'     => '/gefleckt|getüpfelt|getupft|gesprenkelt|tüpfel/u',
        'zobel'        => '/zobel|sable/u',
        'schwarz'      => '/schwarz/u',
        'weiss'        => '/weiß|weiss/u',
        'braun'        => '/braun|schoko/u',
        'rot'          => '/(?<![a-zäöüß])(?:rot|rötlich)|(?:hell|dunkel|fuchs|kupfer|mahagoni|wein|braun|gold)rot/u',
        'gelb'         => '/gelb/u',
        'gold'         => '/gold/u',
        'falb'         => '/falb|fauve/u',
        'creme'        => '/creme/u',
        'sand'         => '/sand/u',
        'grau'         => '/grau/u',
        'blau'         => '/blau/u',
        'silber'       => '/silber/u',
    ];

    // ------------------------------------------------------------------
    // TheDogAPI
    // ------------------------------------------------------------------

    /** "23-29", "23 - 29", "25", "12 - 15 years" → [von, bis] oder null */
    public static function spanneAusApi(?string $text, string $einheit): ?array
    {
        if (!preg_match_all('/\d+(?:[.,]\d+)?/', (string) $text, $m) || count($m[0]) > 2) {
            return null;
        }
        $zahlen = array_map(fn($z) => (float) str_replace(',', '.', $z), $m[0]);
        $von = $zahlen[0];
        $bis = $zahlen[1] ?? $zahlen[0];
        return self::plausibel($von, $bis, $einheit);
    }

    /**
     * Vorschläge aus einer TheDogAPI-Rasse.
     *
     * @return array<string, array{wert: array, beleg: string}>
     */
    public static function ausTheDogApi(array $api): array
    {
        $vorschlaege = [];
        $quelle = 'TheDogAPI „' . trim((string) ($api['name'] ?? '')) . '“';
        foreach (['schulterhoehe' => ['height', 'cm'], 'gewicht' => ['weight', 'kg']] as $schluessel => [$feld, $einheit]) {
            $roh = (string) ($api[$feld]['metric'] ?? '');
            $spanne = self::spanneAusApi($roh, $einheit);
            if ($spanne !== null) {
                $vorschlaege[$schluessel] = ['wert' => self::spannenWert(['alle' => $spanne]), 'beleg' => $quelle . ': ' . $roh . ' ' . $einheit];
            }
        }
        $spanne = self::spanneAusApi((string) ($api['life_span'] ?? ''), 'jahre');
        if ($spanne !== null) {
            $vorschlaege['lebenserwartung'] = ['wert' => self::spannenWert(['alle' => $spanne]), 'beleg' => $quelle . ': ' . trim((string) $api['life_span'])];
        }
        $code = strtoupper(trim((string) ($api['country_code'] ?? '')));
        if (preg_match('/^[A-Z]{2}$/', $code) && RassenDaten::landName($code, 'de') !== $code) {
            $vorschlaege['ursprungsland'] = ['wert' => ['werte' => [], 'spannen' => [], 'text' => $code],
                'beleg' => $quelle . ': ' . trim((string) ($api['origin'] ?? '')) . ' (' . $code . ')'];
        }
        return $vorschlaege;
    }

    // ------------------------------------------------------------------
    // VDH-Text
    // ------------------------------------------------------------------

    /** Einheiten und Zahlen vereinheitlichen, Toleranzangaben entfernen. */
    public static function normalisieren(string $text): string
    {
        $t = str_replace(["\r", '–', '—', '‑', ' ', '±'], ['', '-', '-', '-', ' ', '+/-'], $text);
        $t = preg_replace('/(\d),(\d)/u', '$1.$2', $t);
        $t = preg_replace('/\b(?:Zentimetern|Zentimeter|Zentimetres?)\b/u', 'cm', $t);
        $t = preg_replace('/\b(?:Kilogramm|Kilo)\b/u', 'kg', $t);
        $t = preg_replace('/\b(cm|kg)(?=[A-Za-zÄÖÜäöü])/u', '$1 ', $t);
        // "(+/- 3 cm)", "(Toleranz 2 cm)", "(Idealgröße: 66-68 cm)"
        $t = preg_replace('/\([^)]*(?:\+|Toleranz|[Ii]deal)[^)]*\)/u', '', $t);
        return (string) $t;
    }

    /** @return list<string> Sätze bzw. Zeilen */
    private static function saetze(string $text): array
    {
        $teile = preg_split('/(?<=[.!?;])\s+(?=[A-ZÄÖÜ0-9])|\n+/u', $text) ?: [];
        return array_values(array_filter(array_map('trim', $teile), fn($s) => $s !== ''));
    }

    /**
     * Größe ('cm') oder Gewicht ('kg') aus einem Text.
     *
     * @return array{spannen: array<string, array{0: ?float, 1: ?float}>, beleg: string}|null
     */
    public static function massAusText(string $text, string $einheit, string $rassenName = ''): ?array
    {
        $gefunden = [];   // geschlecht => [von, bis]
        $belege = [];
        $vorigerSatzMass = false;
        foreach (self::saetze(self::normalisieren($text)) as $satz) {
            $stichwort = $einheit === 'cm'
                ? '/Widerrist|Schulterh|Größe|groß|hoch|Höhe|Mindestma|Höchstma|messen|misst/u'
                : '/Gewicht|wieg|schwer|Waage|kg/u';
            // "Rüden … 68 bis 74 cm. Hündinnen 63 bis 69 cm." – der zweite Satz hat kein Stichwort
            $folgesatz = $vorigerSatzMass && preg_match('/^\W*(?:Rüden?|Hündin(?:nen)?)\b/u', $satz);
            $vorigerSatzMass = false;
            if (!preg_match($stichwort, $satz) && !$folgesatz) {
                continue;
            }
            $modifikator = null;   // gilt im Satz weiter: "maximal 52 cm, Hündinnen 48 cm"

            $muster = '/(?:(?:zwischen|von)\s+)?(\d+(?:\.\d+)?)\s*(?:' . $einheit . ')?\s*(?:-|bis(?:\s+zu)?|und)\s*(\d+(?:\.\d+)?)\s*' . $einheit
                . '\b|(\d+(?:\.\d+)?)\s*' . $einheit . '\b/u';
            if (!preg_match_all($muster, $satz, $treffer, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $vorigerSatzMass = true;
            foreach ($treffer as $t) {
                $start = $t[0][1];
                $ende = $start + strlen($t[0][0]);
                $vorher = mb_strcut($satz, max(0, $start - 40), min(40, $start));   // mb_strcut: nie mitten im Umlaut
                $nachher = mb_strcut($satz, $ende, 45);
                if (self::andereVarietaet($vorher, $rassenName)) {
                    continue;
                }
                if (preg_match('/[Ii]deal\S*\s*:?\s*$|Haar|Fell|Länge|Ohr|Rute|Kopf\b/u', mb_strcut($vorher, max(0, strlen($vorher) - 20)))
                    || preg_match('/^\s*(?:als\s+)?ideal|^\s*(?:lang|Länge|pro)\b/u', $nachher)) {
                    continue;
                }
                if (isset($t[3]) && $t[3][1] >= 0) {
                    $von = $bis = (float) $t[3][0];
                } else {
                    $von = (float) $t[1][0];
                    $bis = (float) $t[2][0];
                }
                $istSpanne = $t[1][1] >= 0 && $t[2][1] >= 0 && !(isset($t[3]) && $t[3][1] >= 0);
                $geschlechtDazwischen = '(?:\s+(?:für\s+|der\s+)?(?:Rüden?|Hündin(?:nen)?))?\s*:?\s*$/u';
                if (preg_match('/(?:bis zu|(?:^|[:,;(])\s*bis|[Mm]aximal|[Mm]aximum|höchstens|Höchstma(?:ss|ß)\S*|nicht über\S*|nicht größer als)' . $geschlechtDazwischen, $vorher)
                    || preg_match('/^\s*(?:\S+\s+)?nicht überschreiten/u', $nachher)) {
                    $modifikator = 'max';
                } elseif (preg_match('/(?:Mindestma(?:ss|ß)\S*|mindestens|[Mm]inimum|nicht kleiner als|(?<!nicht )über|ab)' . $geschlechtDazwischen, $vorher)
                    || preg_match('/^\s*(?:und|oder)\s+(?:größer|mehr|darüber)|^\s*(?:\S+\s+)?als Mindest/u', $nachher)) {
                    $modifikator = 'min';
                } elseif ($istSpanne || preg_match('/(?:Höchst|Mindest|[Ii]deal)/u', $vorher)) {
                    $modifikator = null;
                }
                if (!$istSpanne && $modifikator === 'max') {
                    $von = null;
                } elseif (!$istSpanne && $modifikator === 'min') {
                    $bis = null;
                }
                $spanne = self::plausibel($von, $bis, $einheit);
                if ($spanne === null) {
                    continue;
                }
                $geschlecht = self::geschlecht($satz, $start, $nachher);
                $alt = $gefunden[$geschlecht] ?? null;
                if ($alt === null) {
                    $gefunden[$geschlecht] = $spanne;
                    $belege[self::ausschnitt($satz, $start, $ende)] = true;
                } elseif ($alt[1] === null && $spanne[0] === null) {
                    $gefunden[$geschlecht] = [$alt[0], $spanne[1]];   // "Mindestmaß 70, Höchstmaß 90"
                } elseif ($alt[0] === null && $spanne[1] === null) {
                    $gefunden[$geschlecht] = [$spanne[0], $alt[1]];
                }
            }
        }
        if (isset($gefunden['ruede']) || isset($gefunden['huendin'])) {
            unset($gefunden['alle']);
        }
        if (!$gefunden) {
            return null;
        }
        ksort($gefunden);
        $beleg = implode(' … ', array_keys($belege));
        return ['spannen' => $gefunden, 'beleg' => mb_strlen($beleg) > 300 ? mb_substr($beleg, 0, 297) . '…' : $beleg];
    }

    /** Langer Satz → nur die Umgebung des Treffers (Byte-Positionen, zeichensicher geschnitten). */
    private static function ausschnitt(string $satz, int $start, int $ende): string
    {
        if (mb_strlen($satz) <= 200) {
            return $satz;
        }
        $von = max(0, $start - 90);
        $text = trim(mb_strcut($satz, $von, $ende - $von + 90));
        return ($von > 0 ? '…' : '') . $text . ($ende + 90 < strlen($satz) ? '…' : '');
    }

    /** Bezeichnungen vor einem Doppelpunkt, die keine Varietät sind */
    private const KEINE_VARIETAET = ['rüde', 'rüden', 'hündin', 'hündinnen', 'widerristhöhe', 'schulterhöhe', 'größe', 'grösse', 'gewicht',
        'minimum', 'maximum', 'idealgröße', 'idealgrösse', 'idealmaß', 'mindestmass', 'mindestmaß', 'höchstmass', 'höchstmaß', 'toleranz',
        'alter', 'erwünscht', 'ideal', 'ca', 'circa', 'etwa'];

    /**
     * Steht vor der Zahl eine andere Varietät, z. B. "Großpudel: 45-60 cm"
     * im gemeinsamen Pudel-Standard, gelesen für den Toypudel?
     */
    private static function andereVarietaet(string $vorher, string $rassenName): bool
    {
        if ($rassenName === '' || !preg_match('/([A-ZÄÖÜ][\wäöüß.\/-]*)\s*(?::|\()\s*(?:[Üü]ber|[Vv]on|[Bb]is|ca\.|circa)?\s*$/u', $vorher, $m)) {
            return false;
        }
        $teile = array_filter(explode('/', mb_strtolower(trim($m[1], '.'))), fn($t) => mb_strlen($t) >= 3);
        if (!$teile || array_intersect($teile, self::KEINE_VARIETAET)
            || preg_match('/gewicht|höhe|größe|grösse|ma(?:ss|ß)|ideal|toleranz/u', implode(' ', $teile))) {
            return false;
        }
        $name = mb_strtolower($rassenName);
        foreach ($teile as $t) {
            if (str_contains($name, $t)) {
                return false;
            }
        }
        return true;
    }

    /** Rüde, Hündin oder beide: "(Rüden)" direkt danach, sonst die letzte Nennung davor. */
    private static function geschlecht(string $satz, int $start, string $nachher): string
    {
        if (preg_match('/^\s*(?:\(|bei\s+(?:den\s+)?|für\s+(?:\S+\s+){0,2})(Rüden?|Hündin(?:nen)?)\b/u', $nachher, $m)) {
            return str_starts_with($m[1], 'R') ? 'ruede' : 'huendin';
        }
        $davor = substr($satz, 0, $start);
        $r = strrpos($davor, 'Rüde');
        $h = strrpos($davor, 'Hündin');
        $r = $r === false ? -1 : $r;
        $h = $h === false ? -1 : $h;
        if ($r < 0 && $h < 0) {
            return 'alle';
        }
        return $r > $h ? 'ruede' : 'huendin';
    }

    /**
     * Farben aus einem Text: nur Sätze, die von Farben sprechen, ohne
     * Augen und ohne "alle Farben".
     *
     * @return array{werte: list<string>, beleg: string}|null
     */
    public static function farbenAusText(string $text): ?array
    {
        $werte = [];
        $belege = [];
        foreach (self::saetze(str_replace("\r", '', $text)) as $satz) {
            if (!preg_match('/Farb|farbig|Fellfarbe/u', $satz) || preg_match('/Auge|alle(?:n)? Farb|jede Farbe|Farben sind (?:alle )?zulässig\.?$/u', $satz)) {
                continue;
            }
            $klein = mb_strtolower($satz);
            // Abzeichen, Maske, Nase usw. sind keine Fellfarbe
            $klein = (string) preg_replace('/[a-zäöüß-]+\s+(?:abzeichen|brand|maske|nasenschwamm|nase|krallen|lefzen|unterwolle|pigment\S*|augen\S*)/u', ' ', $klein);
            $vorher = count($werte);
            foreach (self::FARBEN as $wert => $muster) {
                if (preg_match($muster, $klein)) {
                    $werte[$wert] = true;
                    $klein = (string) preg_replace($muster, ' ', $klein);
                }
            }
            if (count($werte) > $vorher) {
                $belege[] = $satz;
            }
        }
        if (!$werte) {
            return null;
        }
        $beleg = implode(' … ', $belege);
        return ['werte' => array_keys($werte), 'beleg' => mb_strlen($beleg) > 300 ? mb_substr($beleg, 0, 297) . '…' : $beleg];
    }

    /**
     * Vorschläge aus den deutschen Textabschnitten einer Rasse.
     *
     * @param array<string, string> $texte abschnitt => Text
     * @return array<string, array{wert: array, beleg: string}>
     */
    public static function ausVdhText(array $texte, string $rassenName = ''): array
    {
        $vorschlaege = [];
        foreach (['schulterhoehe' => 'cm', 'gewicht' => 'kg'] as $schluessel => $einheit) {
            foreach (self::ABSCHNITTE as $abschnitt) {
                $mass = isset($texte[$abschnitt]) ? self::massAusText($texte[$abschnitt], $einheit, $rassenName) : null;
                if ($mass !== null) {
                    $vorschlaege[$schluessel] = ['wert' => self::spannenWert($mass['spannen']), 'beleg' => $mass['beleg']];
                    break;
                }
            }
        }
        $farbText = implode("\n", array_filter([$texte['standard'] ?? null, $texte['aussehen'] ?? null]));
        $farben = $farbText !== '' ? self::farbenAusText($farbText) : null;
        if ($farben !== null) {
            $vorschlaege['farben'] = ['wert' => ['werte' => $farben['werte'], 'spannen' => [], 'text' => null], 'beleg' => $farben['beleg']];
        }
        return $vorschlaege;
    }

    // ------------------------------------------------------------------
    // Hilfen
    // ------------------------------------------------------------------

    private static function plausibel(?float $von, ?float $bis, string $einheit): ?array
    {
        [$min, $max] = self::GRENZEN[$einheit];
        if ($von !== null && $bis !== null && $von > $bis) {
            [$von, $bis] = [$bis, $von];
        }
        foreach ([$von, $bis] as $z) {
            if ($z !== null && ($z < $min || $z > $max)) {
                return null;
            }
        }
        return $von === null && $bis === null ? null : [$von, $bis];
    }

    /** [geschlecht => [von, bis]] (Zahlen) → Formularformat (Texte wie "12.5") */
    private static function spannenWert(array $spannen): array
    {
        $text = fn(?float $z) => $z === null ? null : rtrim(rtrim(number_format($z, 1, '.', ''), '0'), '.');
        $ergebnis = [];
        foreach ($spannen as $g => [$von, $bis]) {
            $ergebnis[$g] = [$text($von), $text($bis)];
        }
        ksort($ergebnis);
        return ['werte' => [], 'spannen' => $ergebnis, 'text' => null];
    }

    // ------------------------------------------------------------------
    // Alle Rassen (Datenbank)
    // ------------------------------------------------------------------

    /**
     * Vorschläge für ein Merkmal über alle Rassen, nur wo es noch leer ist.
     *
     * @param list<array> $apiBreeds TheDogApiFelder::breeds()['breeds'], leer = ohne TheDogAPI
     * @return list<array{breed_id: int, name: string, vdh?: array, thedogapi?: array}>
     */
    public static function fuerMerkmal(string $schluessel, array $apiBreeds): array
    {
        $quellen = self::MERKMALE[$schluessel] ?? [];
        $pdo = Database::getConnection();
        $daten = RassenFilter::daten();
        $rassen = $pdo->query('SELECT id, name, name_en, alternative_namen, thedogapi_id FROM breeds ORDER BY name')->fetchAll();

        $texte = [];
        if (in_array('vdh', $quellen, true)) {
            foreach ($pdo->query("SELECT breed_id, abschnitt, text FROM rasse_texte WHERE sprache = 'de'") as $r) {
                $texte[(int) $r['breed_id']][$r['abschnitt']] = (string) $r['text'];
            }
        }

        $ergebnis = [];
        foreach ($rassen as $rasse) {
            $id = (int) $rasse['id'];
            if (isset($daten[$id][$schluessel])) {
                continue;
            }
            $zeile = ['breed_id' => $id, 'name' => $rasse['name']];
            if (isset($texte[$id])) {
                $v = self::ausVdhText($texte[$id], $rasse['name'])[$schluessel] ?? null;
                if ($v !== null) {
                    $zeile['vdh'] = $v;
                }
            }
            if ($apiBreeds && in_array('thedogapi', $quellen, true)) {
                $match = TheDogApiAbgleich::bestesMatch($rasse, $apiBreeds);
                if ($match !== null && $match['score'] >= TheDogApiAbgleich::SICHER) {
                    $v = self::ausTheDogApi($match['breed'])[$schluessel] ?? null;
                    if ($v !== null) {
                        $zeile['thedogapi'] = $v;
                    }
                }
            }
            if (isset($zeile['vdh']) || isset($zeile['thedogapi'])) {
                $ergebnis[] = $zeile;
            }
        }
        return $ergebnis;
    }
}
