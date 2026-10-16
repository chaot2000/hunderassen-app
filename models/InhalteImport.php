<?php
/**
 * models/InhalteImport.php
 *
 * Liest eine Importdatei aus tools/inhalte_importdatei.php (PLAN.md
 * Abschnitt 11), vergleicht jede Änderung mit dem Live-Stand und
 * schreibt die passenden. Status je Änderung:
 *  - ok:       live entspricht dem alten Wert → wird geschrieben
 *  - schon:    live entspricht bereits dem neuen Wert → nichts zu tun
 *  - konflikt: live wurde inzwischen anders geändert → bleibt, wird angezeigt
 *  - fehler:   Datei passt nicht zur App (unbekannte Rasse, Merkmal, Wert)
 */

declare(strict_types=1);

require_once __DIR__ . '/RassenDatenSchreiben.php';
require_once __DIR__ . '/InhaltsDatei.php';

class InhalteImport
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** @return array{daten: ?array, fehler: ?string} */
    public static function lesen(string $json): array
    {
        $d = json_decode($json, true);
        if (!is_array($d) || ($d['format'] ?? null) !== 1 || !is_array($d['werte'] ?? null) || !is_array($d['rassen'] ?? null)) {
            return ['daten' => null, 'fehler' => 'Keine gültige Importdatei (Format 1 aus tools/inhalte_importdatei.php).'];
        }
        return ['daten' => $d, 'fehler' => null];
    }

    /**
     * Jede Änderung mit Status, gegen den aktuellen Live-Stand.
     *
     * @return array{werte: list<array>, aenderungen: list<array>, zahlen: array<string, int>}
     */
    public static function pruefen(array $daten): array
    {
        $defs = RassenDatenSchreiben::nachSchluessel(true);
        $zahlen = ['ok' => 0, 'schon' => 0, 'konflikt' => 0, 'fehler' => 0];

        $werte = [];
        $neueWerte = [];
        foreach ($daten['werte'] as $w) {
            $m = $defs[$w['merkmal'] ?? ''] ?? null;
            $schluessel = (string) ($w['schluessel'] ?? '');
            $neu = $w['neu'] ?? null;
            $status = 'fehler';
            $live = null;
            if ($m !== null && preg_match('/^[a-z0-9_]{1,60}$/', $schluessel) && is_array($neu) && trim((string) ($neu['name_de'] ?? '')) !== '') {
                $namen = fn($x) => is_array($x)
                    ? ['name_de' => trim((string) ($x['name_de'] ?? '')), 'name_en' => trim((string) ($x['name_en'] ?? '')) ?: null]
                    : null;
                $live = $namen($m['werte'][$schluessel] ?? null);
                $status = self::status($live, $namen($w['alt'] ?? null), $namen($neu));
                $neueWerte[$w['merkmal']][$schluessel] = true;
            }
            $zahlen[$status]++;
            $werte[] = $w + ['status' => $status, 'live' => $live];
        }

        $rassenNamen = [];
        foreach (Database::getConnection()->query('SELECT id, name, name_en FROM breeds') as $r) {
            $rassenNamen[(int) $r['id']] = $r;
        }

        $aenderungen = [];
        foreach ($daten['rassen'] as $r) {
            $id = (int) ($r['id'] ?? 0);
            $name = $rassenNamen[$id]['name'] ?? null;
            $eintrag = fn(string $art, string $feld, string $label, $alt, $neu, $live, string $status) =>
                ['breed_id' => $id, 'rasse' => $name ?? ('#' . $id), 'art' => $art, 'feld' => $feld, 'label' => $label,
                    'alt' => $alt, 'neu' => $neu, 'live' => $live, 'status' => $status];
            if ($name === null) {
                $aenderungen[] = $eintrag('rasse', '', 'Rasse', null, null, null, 'fehler');
                $zahlen['fehler']++;
                continue;
            }
            $liveWerte = RassenDatenSchreiben::formularWerte($id);
            foreach ((array) ($r['merkmale'] ?? []) as $schluessel => $c) {
                $m = $defs[$schluessel] ?? null;
                $neu = $c['neu'] ?? null;
                $gueltig = $m !== null && ($neu === null || self::werteBekannt($m, $neu, $neueWerte[$schluessel] ?? []));
                $live = InhaltsDatei::wertNorm($liveWerte[$schluessel] ?? null);
                $status = !$gueltig ? 'fehler' : self::status($live, InhaltsDatei::wertNorm($c['alt'] ?? null), InhaltsDatei::wertNorm($neu));
                $aenderungen[] = $eintrag('merkmal', (string) $schluessel, 'Merkmal „' . ($m['name_de'] ?? $schluessel) . '“',
                    $c['alt'] ?? null, $neu, $live, $status);
                $zahlen[$status]++;
            }
            $liveTexte = RassenDaten::alleTexte($id);
            foreach ((array) ($r['texte'] ?? []) as $sprache => $abschnitte) {
                foreach ((array) $abschnitte as $abschnitt => $c) {
                    $gueltig = in_array($sprache, ['de', 'en'], true) && array_key_exists($abschnitt, RassenDaten::ABSCHNITTE)
                        && mb_strlen((string) ($c['neu'] ?? '')) <= 20000;
                    $live = InhaltsDatei::textNorm($liveTexte[$sprache][$abschnitt] ?? null);
                    $status = !$gueltig ? 'fehler' : self::status($live, InhaltsDatei::textNorm($c['alt'] ?? null), InhaltsDatei::textNorm($c['neu'] ?? null));
                    $aenderungen[] = $eintrag('text', $sprache . '_' . $abschnitt,
                        'Text „' . (InhaltsDatei::ABSCHNITTE[$abschnitt] ?? $abschnitt) . '“ (' . ($sprache === 'de' ? 'deutsch' : 'englisch') . ')',
                        $c['alt'] ?? null, $c['neu'] ?? null, $live, $status);
                    $zahlen[$status]++;
                }
            }
            if (isset($r['name_en'])) {
                $live = trim((string) ($rassenNamen[$id]['name_en'] ?? ''));
                $neu = trim((string) ($r['name_en']['neu'] ?? ''));
                $status = mb_strlen($neu) > 150 ? 'fehler' : self::status($live, trim((string) ($r['name_en']['alt'] ?? '')), $neu);
                $aenderungen[] = $eintrag('name_en', 'name_en', 'Name (englisch)', $r['name_en']['alt'] ?? '', $neu, $live, $status);
                $zahlen[$status]++;
            }
        }
        return ['werte' => $werte, 'aenderungen' => $aenderungen, 'zahlen' => $zahlen];
    }

    private static function status($live, $alt, $neu): string
    {
        return match (true) {
            $live === $neu => 'schon',
            $live === $alt => 'ok',
            default => 'konflikt',
        };
    }

    /** Alle Werte eines Formularwerts gibt es (oder kommen mit der Datei)? */
    private static function werteBekannt(array $m, array $wert, array $neueWerte): bool
    {
        foreach ($wert['werte'] ?? [] as $w) {
            if (!isset($m['werte'][$w]) && !isset($neueWerte[$w])) {
                return false;
            }
        }
        if ($m['typ'] === 'land' && ($wert['text'] ?? null) !== null && RassenDaten::landName((string) $wert['text'], 'de') === $wert['text']) {
            return false;
        }
        return true;
    }

    /**
     * Schreibt alle Änderungen mit Status ok, in einer Transaktion.
     *
     * @return array{werte: int, felder: int, rassen: int}
     */
    public static function anwenden(array $daten, ?int $userId): array
    {
        $pruefung = self::pruefen($daten);
        $pdo = Database::getConnection();
        $ergebnis = ['werte' => 0, 'felder' => 0, 'rassen' => 0];
        $pdo->beginTransaction();
        try {
            $defs = RassenDatenSchreiben::nachSchluessel(true);
            $ins = $pdo->prepare('INSERT INTO merkmal_werte (merkmal_id, schluessel, name_de, name_en, reihenfolge) VALUES (:m, :s, :de, :en, :r)');
            $upd = $pdo->prepare('UPDATE merkmal_werte SET name_de = :de, name_en = :en WHERE merkmal_id = :m AND schluessel = :s');
            foreach ($pruefung['werte'] as $w) {
                if ($w['status'] !== 'ok') {
                    continue;
                }
                $m = $defs[$w['merkmal']];
                $p = [':m' => $m['id'], ':s' => $w['schluessel'], ':de' => mb_substr(trim($w['neu']['name_de']), 0, 100),
                    ':en' => ($en = trim((string) ($w['neu']['name_en'] ?? ''))) !== '' ? mb_substr($en, 0, 100) : null];
                if ($w['live'] === null) {
                    $ins->execute($p + [':r' => 1000]);
                } else {
                    $upd->execute($p);
                }
                $ergebnis['werte']++;
            }
            RassenDatenSchreiben::definitionen(true);

            $jeRasse = [];
            foreach ($pruefung['aenderungen'] as $a) {
                if ($a['status'] !== 'ok') {
                    continue;
                }
                $r = &$jeRasse[$a['breed_id']];
                $r ??= ['merkmale' => [], 'texte' => [], 'name_en' => null];
                if ($a['art'] === 'merkmal') {
                    $r['merkmale'][$a['feld']] = InhaltsDatei::wertNorm($a['neu']);
                } elseif ($a['art'] === 'text') {
                    [$sprache, $abschnitt] = explode('_', $a['feld'], 2);
                    $r['texte'][$sprache][$abschnitt] = InhaltsDatei::textNorm($a['neu']);
                } else {
                    $r['name_en'] = (string) $a['neu'];
                }
                unset($r);
                $ergebnis['felder']++;
            }
            foreach ($jeRasse as $id => $r) {
                RassenDatenSchreiben::importSchreiben($pdo, (int) $id, $r['merkmale'], $r['texte'], $r['name_en'], $userId);
            }
            $ergebnis['rassen'] = count($jeRasse);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $ergebnis;
    }
}
