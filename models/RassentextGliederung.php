<?php
/**
 * models/RassentextGliederung.php
 *
 * Zerlegt einen Rassentext im Format des VDH-Imports (siehe
 * DB_Generator/) in strukturierte Felder und einen gegliederten Text.
 * Reine Textverarbeitung ohne Datenbank, damit sie sich isoliert
 * testen lässt (tests/RassentextGliederungTest.php).
 *
 * Aufbau des Imports:
 *   1. FCI-Kopf "FCI-Gruppe 2: … Sektion 1: … Ohne Arbeitsprüfung."
 *   2. Haupttext in Absätzen
 *   3. Restsatz der VDH-Seite "Hier werden Würfe … angezeigt."
 *   4. "## Charakter" / "## Haltung": Ausschnitte, die wortgleich schon
 *      im Haupttext stehen (geprüft für alle 382 Rassen)
 *
 * Ergebnis: Kurzporträt (erster Absatz) plus Absätze, gruppiert unter
 * "## Überschrift". Die Zuordnung der Absätze zu Abschnitten geschieht
 * per Stichwort und ist ein Vorschlag (breeds.gliederung =
 * 'automatisch'), der von Hand geprüft wird.
 *
 * Grundsatz: Kein Satz des Haupttextes geht verloren. verloreneAbsaetze()
 * prüft das; Rassen mit Verlust werden nicht umgestellt.
 */

declare(strict_types=1);

class RassentextGliederung
{
    public const RESTSATZ = 'Hier werden Würfe der aktuellen Rasse sowie der dazugehörigen FCI Gruppe angezeigt.';

    /** Abschnitt => Überschrift im Text. Reihenfolge = Anzeige im Formular-Hinweis. */
    public const ABSCHNITTE = [
        'wesen'    => 'Wesen',
        'haltung'  => 'Haltung und Erziehung',
        'pflege'   => 'Pflege',
        'aussehen' => 'Aussehen',
        'herkunft' => 'Herkunft und Geschichte',
    ];

    /**
     * Stichwörter je Abschnitt. Gezählt wird pro Absatz, der Abschnitt
     * mit den meisten Treffern gewinnt. Bei Gleichstand gilt die
     * Reihenfolge dieser Liste.
     */
    private const STICHWOERTER = [
        'herkunft' => '/\b1[0-9]{3}\b|Mittelalter|ältesten|Geschichte|Ursprung|stammt|Jahrhundert|gezüchtet|Vorfahren|Herkunft|Heimat|ursprünglich|Zucht/u',
        'pflege'   => '/Pflege|bürsten|Bürsten|trimmen|Trimmen|pflegeleicht|Haarwechsel|Fellwechsel|scheren/u',
        'wesen'    => '/Wesen|Charakter|Temperament|anhänglich|freundlich|wachsam|selbstbewusst|sensibel|Persönlichkeit|verspielt|Mut\b|mutig/u',
        'haltung'  => '/Erziehung|Haltung|Auslauf|Beschäftigung|Anfänger|Konsequenz|konsequent|Bewegung|Spaziergäng|Hundesport|Agility|Familie|Zuhause|Jagd/u',
        'aussehen' => '/Fell|Haarkleid|Farbe|Kopf|Rute|Ohren|Körperbau|Widerrist|Gewicht|Augen|Gangwerk|Behang|Statur/u',
    ];

    /**
     * @return array{
     *   fci_gruppe: ?int, fci_sektion: ?string, fci_sektion_name: ?string,
     *   arbeitspruefung: ?string, kurzportraet: string, text: ?string,
     *   absaetze: int, abschnitte: array<int, string>, hinweise: array<int, string>
     * }
     */
    public static function zerlege(string $original): array
    {
        $roh = str_replace(["\r\n", "\r"], "\n", $original);
        $hinweise = [];

        // 4. Doppelte Ausschnitte abtrennen, 3. Restsatz entfernen.
        $haupt = preg_split('/\n?## (?:Charakter|Haltung)\n/u', $roh)[0];
        $haupt = trim(str_replace(self::RESTSATZ, '', $haupt));

        // 1. FCI-Kopf
        $gruppe = $sektion = $sektionName = $arbeit = null;
        if (preg_match('/^FCI-Gruppe\s*(\d+)\s*:(.*?)(?:\n\s*\n|$)/su', $haupt, $m)) {
            $gruppe = (int) $m[1];
            $kopf = $m[0];
            if (preg_match('/(?:Sektion|Section)\s*([\d.]+)\s*[:.]?\s*\n?\s*([^\n]*)/iu', $kopf, $s)) {
                $sektion = rtrim($s[1], '.');
                // "Zwergterrier, ohne Arbeitsprüfung" -> "Zwergterrier"
                $name = preg_replace('/,?\s*(?:mit|ohne)\s+Arbeitsprüfung.*$|,?\s*Arbeitsprüfung\s+fakultativ.*$/iu', '', self::norm($s[2]));
                $sektionName = rtrim((string) $name, '.') ?: null;
            }
            $arbeit = self::arbeitspruefung($kopf);
            $haupt = trim(mb_substr($haupt, mb_strlen($m[0])));
        } else {
            $arbeit = self::arbeitspruefung(mb_substr($haupt, 0, 300));
            if ($haupt !== '') {
                $hinweise[] = 'FCI-Kopf nicht im Standardformat, Text unverändert übernommen.';
            }
        }

        if ($gruppe !== null && ($gruppe < 1 || $gruppe > 10)) {
            $gruppe = null;
        }

        $absaetze = array_values(array_filter(
            array_map([self::class, 'norm'], preg_split('/\n\s*\n/u', $haupt) ?: []),
            fn($p) => $p !== ''
        ));

        if (empty($absaetze)) {
            return [
                'fci_gruppe' => $gruppe, 'fci_sektion' => $sektion, 'fci_sektion_name' => $sektionName,
                'arbeitspruefung' => $arbeit, 'kurzportraet' => '', 'text' => null,
                'absaetze' => 0, 'abschnitte' => [], 'hinweise' => array_merge($hinweise, ['Kein Beschreibungstext.']),
            ];
        }

        if (!preg_match('/[.!?")]$/u', end($absaetze))) {
            $hinweise[] = 'Der Text endet mitten im Satz (schon in der Quelle).';
        }

        // Datenzeilen aus dem Standard (Maße, Einordnung) taugen nicht als
        // Kurzporträt. Sie kommen gesammelt ans Ende.
        $daten = array_values(array_filter($absaetze, [self::class, 'istDatenzeile']));
        $fliesstext = array_values(array_filter($absaetze, fn($p) => !self::istDatenzeile($p)));
        if (empty($fliesstext)) {
            $fliesstext = $daten;
            $daten = [];
        }

        // Gliederung: erster Absatz = Kurzporträt, danach Überschrift bei
        // jedem Wechsel zu einem noch nicht benutzten Abschnitt. Absätze
        // ohne klare Zuordnung bleiben beim vorherigen Abschnitt.
        $zeilen = [$fliesstext[0]];
        $aktuell = null;
        $benutzt = [];
        foreach (array_slice($fliesstext, 1) as $absatz) {
            $abschnitt = self::klassifiziere($absatz);
            if ($abschnitt !== null && $abschnitt !== $aktuell && !isset($benutzt[$abschnitt])) {
                $zeilen[] = '## ' . self::ABSCHNITTE[$abschnitt];
                $aktuell = $abschnitt;
                $benutzt[$abschnitt] = true;
            }
            $zeilen[] = $absatz;
        }

        if (!empty($daten)) {
            $zeilen[] = '## ' . self::DATEN_TITEL;
            array_push($zeilen, ...$daten);
        }

        return [
            'fci_gruppe'       => $gruppe,
            'fci_sektion'      => $sektion,
            'fci_sektion_name' => $sektionName,
            'arbeitspruefung'  => $arbeit,
            'kurzportraet'     => $fliesstext[0],
            'text'             => implode("\n\n", $zeilen),
            'absaetze'         => count($absaetze),
            'abschnitte'       => array_keys($benutzt),
            'hinweise'         => $hinweise,
        ];
    }

    /**
     * Absätze des Originals (ohne FCI-Kopf, Restsatz und doppelte
     * Ausschnitte), die im neuen Text fehlen. Leeres Array = nichts
     * verloren.
     *
     * @return array<int, string>
     */
    public static function verloreneAbsaetze(string $original, ?string $neu): array
    {
        $roh = str_replace(["\r\n", "\r"], "\n", $original);
        $haupt = trim(str_replace(self::RESTSATZ, '', preg_split('/\n?## (?:Charakter|Haltung)\n/u', $roh)[0]));
        if (preg_match('/^FCI-Gruppe\s*\d+\s*:.*?(?:\n\s*\n|$)/su', $haupt, $m)) {
            $haupt = mb_substr($haupt, mb_strlen($m[0]));
        }
        $einzeilig = fn(string $t): string => trim((string) preg_replace('/\s+/u', ' ', $t));
        $neuNorm = $einzeilig((string) $neu);

        $fehlt = [];
        foreach (preg_split('/\n\s*\n/u', $haupt) ?: [] as $absatz) {
            $absatz = $einzeilig($absatz);
            if ($absatz !== '' && !str_contains($neuNorm, $absatz)) {
                $fehlt[] = $absatz;
            }
        }
        return $fehlt;
    }

    /**
     * Zerlegt einen gegliederten Text für die Anzeige: Kurzporträt,
     * Absätze vor der ersten Überschrift und Abschnitte mit Überschrift.
     * Auch für von Hand geschriebene Texte, jede Zeile "## …" ist eine
     * Überschrift.
     *
     * @return array{kurzportraet: ?string, einleitung: array<int, string>, abschnitte: array<int, array{titel: string, absaetze: array<int, string>}>}
     */
    public static function fuerAnzeige(?string $text): array
    {
        $ergebnis = ['kurzportraet' => null, 'einleitung' => [], 'abschnitte' => []];
        if ($text === null || trim($text) === '') {
            return $ergebnis;
        }

        // Restsatz der VDH-Seite nie anzeigen, auch in nicht umgestellten Texten
        $text = str_replace(["\r\n", "\r", self::RESTSATZ], ["\n", "\n", ''], $text);
        $aktuell = null;
        foreach (preg_split('/\n\s*\n|\n(?=## )/u', $text) ?: [] as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            if (preg_match('/^##[ \t]+([^\n]+)(?:\n(.*))?$/su', $block, $m)) {
                $ergebnis['abschnitte'][] = ['titel' => trim($m[1]), 'absaetze' => []];
                $aktuell = array_key_last($ergebnis['abschnitte']);
                // Text direkt unter der Überschrift (ohne Leerzeile)
                $block = trim($m[2] ?? '');
                if ($block === '') {
                    continue;
                }
            }
            if ($aktuell !== null) {
                $ergebnis['abschnitte'][$aktuell]['absaetze'][] = $block;
            } elseif ($ergebnis['kurzportraet'] === null) {
                $ergebnis['kurzportraet'] = $block;
            } else {
                $ergebnis['einleitung'][] = $block;
            }
        }
        return $ergebnis;
    }

    /** Überschrift für gesammelte Datenzeilen aus dem Standard. */
    public const DATEN_TITEL = 'Angaben aus dem Standard';

    private static function istDatenzeile(string $absatz): bool
    {
        return (bool) preg_match('/^(Widerristhöhe|Schulterhöhe|Gewicht|Größe|FCI-Gruppe|Gruppe\s*\d|Group\s*\d|VERWENDUNG|KLASSIFIKATION|Rüden\s*:|Hündinnen\s*:)/iu', $absatz);
    }

    private static function klassifiziere(string $absatz): ?string
    {
        $bester = null;
        $max = 0;
        foreach (self::STICHWOERTER as $abschnitt => $muster) {
            $treffer = preg_match_all($muster, $absatz);
            if ($treffer > $max) {
                $max = $treffer;
                $bester = $abschnitt;
            }
        }
        return $bester;
    }

    private static function arbeitspruefung(string $text): ?string
    {
        if (preg_match('/Arbeitsprüfung\s+fakultativ/iu', $text)) {
            return 'fakultativ';
        }
        if (preg_match('/\b(mit|ohne)\s+Arbeitsprüfung/iu', $text, $m)) {
            return mb_strtolower($m[1]);
        }
        return null;
    }

    private static function norm(string $text): string
    {
        return trim((string) preg_replace('/[ \t]+/u', ' ', $text));
    }
}
