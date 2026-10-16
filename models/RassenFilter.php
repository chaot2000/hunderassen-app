<?php
/**
 * models/RassenFilter.php
 *
 * Katalog-Filter aus den filterbaren Merkmalen (PLAN.md Abschnitt 10,
 * Schritt 3). Alle Merkmalswerte aller Rassen werden einmal geladen und
 * in PHP gefiltert und gezählt. Bei rund 400 Rassen sind das wenige
 * tausend Zeilen, das ist schneller als eine Abfrage je Filteroption.
 *
 * Werte je Rasse (daten()):
 *   [breedId => [merkmalSchluessel => ['werte' => [wert => true], 'spannen' => [[von, bis], …]]]]
 * Land und Ja/Nein stehen ebenfalls unter 'werte' (Ländercode bzw. 'ja'/'nein').
 *
 * Filter (eingabe()):
 *   [merkmalSchluessel => ['werte' => [wert, …]] | ['von' => ?float, 'bis' => ?float]]
 * URL: m[farben][]=schwarz, m[schulterhoehe][von]=30, m[ursprungsland][]=DE
 */

declare(strict_types=1);

require_once __DIR__ . '/RassenDaten.php';

class RassenFilter
{
    /** Typen, bei denen eine Rasse höchstens einen Wert hat: mehrere Häkchen heißen immer "oder". */
    private const EIN_WERT = ['auswahl', 'stufe', 'land', 'ja_nein'];

    /** Liest den Filter aus $_GET. Unbekannte Merkmale und Werte fallen weg. */
    public static function eingabe(array $get, array $definitionen): array
    {
        $roh = is_array($get['m'] ?? null) ? $get['m'] : [];
        // Alte Links aus dem früheren Größenfilter
        if (!empty($get['groesse']) && is_array($get['groesse']) && !isset($roh['groessenklasse'])) {
            $roh['groessenklasse'] = $get['groesse'];
        }
        $filter = [];
        foreach ($definitionen as $m) {
            if (empty($m['filterbar']) || !isset($roh[$m['schluessel']]) || !is_array($roh[$m['schluessel']])) {
                continue;
            }
            $e = $roh[$m['schluessel']];
            if ($m['typ'] === 'zahl_spanne') {
                $von = self::zahl($e['von'] ?? null);
                $bis = self::zahl($e['bis'] ?? null);
                if ($von !== null && $bis !== null && $von > $bis) {
                    [$von, $bis] = [$bis, $von];
                }
                if ($von !== null || $bis !== null) {
                    $filter[$m['schluessel']] = ['von' => $von, 'bis' => $bis];
                }
                continue;
            }
            $erlaubt = match ($m['typ']) {
                'land' => null, // jeder Code aus zwei Großbuchstaben, Treffer gibt es nur für vorhandene
                'ja_nein' => ['ja', 'nein'],
                default => array_keys($m['werte']),
            };
            $werte = [];
            foreach ($e as $schluessel => $w) {
                if (!is_int($schluessel) || !is_string($w)) {
                    continue;
                }
                if ($m['typ'] === 'land') {
                    $w = strtoupper($w);
                }
                $gueltig = $erlaubt === null ? (bool) preg_match('/^[A-Z]{2}$/', $w) : in_array($w, $erlaubt, true);
                if ($gueltig && !in_array($w, $werte, true)) {
                    $werte[] = $w;
                }
            }
            if ($werte) {
                $filter[$m['schluessel']] = ['werte' => $werte];
            }
        }
        return $filter;
    }

    private static function zahl($wert): ?float
    {
        $wert = trim(str_replace(',', '.', (string) $wert));
        return $wert !== '' && is_numeric($wert) && (float) $wert >= 0 ? (float) $wert : null;
    }

    /** Alle Merkmalswerte aller Rassen, siehe Kopfkommentar. */
    public static function daten(): array
    {
        $rows = Database::getConnection()->query(
            'SELECT rm.breed_id, m.schluessel, m.typ, w.schluessel AS wert, rm.zahl_von, rm.zahl_bis, rm.text_wert
             FROM rasse_merkmale rm
             JOIN merkmale m ON m.id = rm.merkmal_id
             LEFT JOIN merkmal_werte w ON w.id = rm.wert_id'
        )->fetchAll();
        $daten = [];
        foreach ($rows as $r) {
            $e = &$daten[(int) $r['breed_id']][$r['schluessel']];
            $e ??= ['werte' => [], 'spannen' => []];
            if ($r['wert'] !== null) {
                $e['werte'][$r['wert']] = true;
            } elseif ($r['text_wert'] !== null && $r['text_wert'] !== '') {
                $e['werte'][(string) $r['text_wert']] = true;
            }
            if ($r['zahl_von'] !== null || $r['zahl_bis'] !== null) {
                $e['spannen'][] = [
                    $r['zahl_von'] !== null ? (float) $r['zahl_von'] : null,
                    $r['zahl_bis'] !== null ? (float) $r['zahl_bis'] : null,
                ];
            }
            unset($e);
        }
        return $daten;
    }

    /** "oder" oder "und" für ein Merkmal, bei Ein-Wert-Typen immer "oder". */
    public static function verknuepfung(array $m): string
    {
        return in_array($m['typ'], self::EIN_WERT, true) ? 'oder' : (($m['verknuepfung'] ?? 'und') === 'oder' ? 'oder' : 'und');
    }

    /** Passt eine Rasse (ihre Werte zu diesem Merkmal) zu einem Filtereintrag? */
    public static function passt(?array $rasse, array $m, array $f): bool
    {
        if ($rasse === null) {
            return false;
        }
        if (array_key_exists('von', $f)) {
            foreach ($rasse['spannen'] as [$von, $bis]) {
                $a = $von ?? $bis;
                $b = $bis ?? $von;
                if (($f['bis'] === null || $a <= $f['bis']) && ($f['von'] === null || $b >= $f['von'])) {
                    return true;
                }
            }
            return false;
        }
        $treffer = count(array_intersect_key(array_flip($f['werte']), $rasse['werte']));
        return self::verknuepfung($m) === 'oder' ? $treffer > 0 : $treffer === count($f['werte']);
    }

    /**
     * @param list<int> $ids Kandidaten (z. B. aus der Textsuche)
     * @param array<string, array> $definitionen nach Schlüssel
     * @return list<int>
     */
    public static function anwenden(array $ids, array $daten, array $filter, array $definitionen): array
    {
        if (!$filter) {
            return $ids;
        }
        return array_values(array_filter($ids, function (int $id) use ($daten, $filter, $definitionen) {
            foreach ($filter as $schluessel => $f) {
                if (!isset($definitionen[$schluessel]) || !self::passt($daten[$id][$schluessel] ?? null, $definitionen[$schluessel], $f)) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * Trefferzahl je Filteroption unter den übrigen aktiven Filtern.
     * Bei "oder" zählt die Option neben den schon gewählten Werten desselben
     * Merkmals, bei "und" zusätzlich zu ihnen. Spannen werden nicht gezählt.
     *
     * @return array<string, array<string, int>> [merkmal => [wert => anzahl]], nur Werte mit Treffern
     */
    public static function zaehlen(array $ids, array $daten, array $filter, array $definitionen): array
    {
        $ergebnis = [];
        $alle = self::anwenden($ids, $daten, $filter, $definitionen);
        foreach ($definitionen as $schluessel => $m) {
            if (empty($m['filterbar']) || $m['typ'] === 'zahl_spanne') {
                continue;
            }
            $basis = $alle;
            if (isset($filter[$schluessel]) && self::verknuepfung($m) === 'oder') {
                $ohne = $filter;
                unset($ohne[$schluessel]);
                $basis = self::anwenden($ids, $daten, $ohne, $definitionen);
            }
            $zahlen = [];
            foreach ($basis as $id) {
                foreach (array_keys($daten[$id][$schluessel]['werte'] ?? []) as $w) {
                    $zahlen[(string) $w] = ($zahlen[(string) $w] ?? 0) + 1;
                }
            }
            $ergebnis[$schluessel] = $zahlen;
        }
        return $ergebnis;
    }

    /** Hat mindestens eine Rasse einen Wert für dieses Merkmal? */
    public static function hatDaten(array $daten, string $schluessel): bool
    {
        foreach ($daten as $rasse) {
            if (isset($rasse[$schluessel])) {
                return true;
            }
        }
        return false;
    }
}
