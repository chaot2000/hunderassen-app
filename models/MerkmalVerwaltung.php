<?php
/**
 * models/MerkmalVerwaltung.php
 *
 * Pflege der Merkmale und ihrer Werte (admin_merkmale.php, PLAN.md
 * Abschnitt 10, Schritt 3). Ersetzt "Eigenschaften & Aktivitäten".
 *
 * Schlüssel von Merkmalen und Werten entstehen beim Anlegen aus dem
 * deutschen Namen und bleiben danach fest, Links im Katalog-Filter
 * hängen daran. Namen lassen sich jederzeit ändern.
 *
 * Löschen geht nur, wenn keine Rasse den Wert bzw. das Merkmal nutzt.
 * Doppelte Werte (z. B. aus dem Temperament-Import) lassen sich
 * zusammenführen, das schreibt je betroffener Rasse eine Version.
 */

declare(strict_types=1);

require_once __DIR__ . '/RassenDatenSchreiben.php';
require_once __DIR__ . '/ContentRevision.php';

class MerkmalVerwaltung
{
    public const TYPEN = [
        'mehrfach'    => 'Mehrfachauswahl',
        'auswahl'     => 'Einfachauswahl',
        'stufe'       => 'Stufe (z. B. gering/mittel/hoch)',
        'ja_nein'     => 'Ja/Nein',
        'zahl_spanne' => 'Zahl von–bis',
        'land'        => 'Land',
    ];

    public const EINHEITEN = ['' => 'keine', 'cm' => 'cm', 'kg' => 'kg', 'jahre' => 'Jahre'];

    /** Typen mit eigener Werteliste */
    public const MIT_WERTEN = ['mehrfach', 'auswahl', 'stufe'];

    /**
     * Anzahl Rassen je Merkmal und je Wert.
     *
     * @return array{merkmale: array<int, int>, werte: array<int, int>}
     */
    public static function nutzung(): array
    {
        $pdo = Database::getConnection();
        $merkmale = [];
        foreach ($pdo->query('SELECT merkmal_id, COUNT(DISTINCT breed_id) n FROM rasse_merkmale GROUP BY merkmal_id') as $r) {
            $merkmale[(int) $r['merkmal_id']] = (int) $r['n'];
        }
        $werte = [];
        foreach ($pdo->query('SELECT wert_id, COUNT(DISTINCT breed_id) n FROM rasse_merkmale WHERE wert_id IS NOT NULL GROUP BY wert_id') as $r) {
            $werte[(int) $r['wert_id']] = (int) $r['n'];
        }
        return ['merkmale' => $merkmale, 'werte' => $werte];
    }

    /** "Größe für Wohnung" → "groesse_fuer_wohnung" */
    public static function schluessel(string $name, int $laenge): string
    {
        $s = mb_strtolower(trim($name));
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'á' => 'a', 'à' => 'a', 'ç' => 'c', 'ñ' => 'n']);
        $s = trim((string) preg_replace('/[^a-z0-9]+/', '_', $s), '_');
        return substr($s !== '' ? $s : substr(md5($name), 0, 12), 0, $laenge);
    }

    /** Namen prüfen und kürzen. @return array{0: ?string, 1: ?string, 2: ?string} [de, en, fehler] */
    private static function namen($de, $en): array
    {
        $de = trim((string) $de);
        $en = trim((string) $en);
        if ($de === '') {
            return [null, null, 'Der deutsche Name fehlt.'];
        }
        if (mb_strlen($de) > 100 || mb_strlen($en) > 100) {
            return [null, null, 'Namen dürfen höchstens 100 Zeichen lang sein.'];
        }
        return [$de, $en !== '' ? $en : null, null];
    }

    private static function rassen(int $n): string
    {
        return $n === 1 ? '1 Rasse' : "{$n} Rassen";
    }

    private static function ergebnis(bool $ok, string $meldung): array
    {
        return ['success' => $ok, 'message' => $meldung];
    }

    // ------------------------------------------------------------------
    // Merkmale
    // ------------------------------------------------------------------

    public static function merkmalAnlegen(array $eingabe): array
    {
        [$de, $en, $fehler] = self::namen($eingabe['name_de'] ?? '', $eingabe['name_en'] ?? '');
        if ($fehler) {
            return self::ergebnis(false, $fehler);
        }
        $typ = (string) ($eingabe['typ'] ?? '');
        $gruppe = (string) ($eingabe['gruppe'] ?? '');
        if (!isset(self::TYPEN[$typ])) {
            return self::ergebnis(false, 'Bitte einen Typ wählen.');
        }
        if (!array_key_exists($gruppe, RassenDaten::GRUPPEN)) {
            return self::ergebnis(false, 'Bitte eine Gruppe wählen.');
        }
        $einheit = $typ === 'zahl_spanne' ? (string) ($eingabe['einheit'] ?? '') : '';
        if (!array_key_exists($einheit, self::EINHEITEN)) {
            $einheit = '';
        }
        $pdo = Database::getConnection();
        $basis = self::schluessel($de, 46);
        $schluessel = $basis;
        $vorhanden = $pdo->query('SELECT schluessel FROM merkmale')->fetchAll(PDO::FETCH_COLUMN);
        for ($i = 2; in_array($schluessel, $vorhanden, true); $i++) {
            $schluessel = $basis . '_' . $i;
        }
        $reihenfolge = (int) $pdo->query('SELECT COALESCE(MAX(reihenfolge), 0) + 10 FROM merkmale')->fetchColumn();
        $pdo->prepare(
            'INSERT INTO merkmale (schluessel, typ, gruppe, name_de, name_en, einheit, je_geschlecht, filterbar, filter_verknuepfung, reihenfolge)
             VALUES (:s, :t, :g, :de, :en, :e, :jg, :f, :v, :r)'
        )->execute([
            ':s' => $schluessel, ':t' => $typ, ':g' => $gruppe, ':de' => $de, ':en' => $en,
            ':e' => $einheit !== '' ? $einheit : null,
            ':jg' => $typ === 'zahl_spanne' && !empty($eingabe['je_geschlecht']) ? 1 : 0,
            ':f' => !empty($eingabe['filterbar']) ? 1 : 0,
            ':v' => ($eingabe['verknuepfung'] ?? '') === 'oder' ? 'oder' : 'und',
            ':r' => $reihenfolge,
        ]);
        RassenDatenSchreiben::definitionen(true);
        return self::ergebnis(true, "Merkmal „{$de}“ angelegt." . (in_array($typ, self::MIT_WERTEN, true) ? ' Jetzt die Werte ergänzen.' : ''))
            + ['schluessel' => $schluessel];
    }

    /** Namen, Gruppe, Einheit, Filter, Reihenfolge. Typ und Schlüssel bleiben. */
    public static function merkmalAendern(int $id, array $eingabe): array
    {
        $m = self::merkmal($id);
        if ($m === null) {
            return self::ergebnis(false, 'Merkmal nicht gefunden.');
        }
        [$de, $en, $fehler] = self::namen($eingabe['name_de'] ?? '', $eingabe['name_en'] ?? '');
        if ($fehler) {
            return self::ergebnis(false, $fehler);
        }
        $gruppe = (string) ($eingabe['gruppe'] ?? $m['gruppe']);
        if (!array_key_exists($gruppe, RassenDaten::GRUPPEN)) {
            return self::ergebnis(false, 'Bitte eine Gruppe wählen.');
        }
        $einheit = $m['typ'] === 'zahl_spanne' ? (string) ($eingabe['einheit'] ?? '') : '';
        if (!array_key_exists($einheit, self::EINHEITEN)) {
            $einheit = '';
        }
        Database::getConnection()->prepare(
            'UPDATE merkmale SET name_de = :de, name_en = :en, gruppe = :g, einheit = :e, filterbar = :f,
                    filter_verknuepfung = :v, reihenfolge = :r WHERE id = :id'
        )->execute([
            ':de' => $de, ':en' => $en, ':g' => $gruppe, ':e' => $einheit !== '' ? $einheit : null,
            ':f' => !empty($eingabe['filterbar']) ? 1 : 0,
            ':v' => ($eingabe['verknuepfung'] ?? '') === 'oder' ? 'oder' : 'und',
            ':r' => max(0, min(9999, (int) ($eingabe['reihenfolge'] ?? $m['reihenfolge']))),
            ':id' => $id,
        ]);
        RassenDatenSchreiben::definitionen(true);
        return self::ergebnis(true, "Merkmal „{$de}“ gespeichert.");
    }

    public static function merkmalLoeschen(int $id): array
    {
        $m = self::merkmal($id);
        if ($m === null) {
            return self::ergebnis(false, 'Merkmal nicht gefunden.');
        }
        $n = self::nutzung()['merkmale'][$id] ?? 0;
        if ($n > 0) {
            return self::ergebnis(false, "„{$m['name_de']}“ ist bei " . self::rassen($n) . ' eingetragen und kann nicht gelöscht werden.');
        }
        Database::getConnection()->prepare('DELETE FROM merkmale WHERE id = :id')->execute([':id' => $id]);
        RassenDatenSchreiben::definitionen(true);
        return self::ergebnis(true, "Merkmal „{$m['name_de']}“ gelöscht.");
    }

    private static function merkmal(int $id): ?array
    {
        foreach (RassenDatenSchreiben::definitionen(true) as $m) {
            if ($m['id'] === $id) {
                return $m;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Werte
    // ------------------------------------------------------------------

    public static function wertAnlegen(int $merkmalId, $nameDe, $nameEn): array
    {
        $m = self::merkmal($merkmalId);
        if ($m === null || !in_array($m['typ'], self::MIT_WERTEN, true)) {
            return self::ergebnis(false, 'Dieses Merkmal hat keine Werteliste.');
        }
        [$de, $en, $fehler] = self::namen($nameDe, $nameEn);
        if ($fehler) {
            return self::ergebnis(false, $fehler);
        }
        foreach ($m['werte'] as $w) {
            if (mb_strtolower($w['name_de']) === mb_strtolower($de)) {
                return self::ergebnis(false, "„{$de}“ gibt es bei „{$m['name_de']}“ schon.");
            }
        }
        $basis = self::schluessel($de, 56);
        $schluessel = $basis;
        for ($i = 2; isset($m['werte'][$schluessel]); $i++) {
            $schluessel = $basis . '_' . $i;
        }
        $reihenfolge = $m['werte'] ? max(array_column($m['werte'], 'reihenfolge')) + 10 : 10;
        Database::getConnection()->prepare(
            'INSERT INTO merkmal_werte (merkmal_id, schluessel, name_de, name_en, reihenfolge) VALUES (:m, :s, :de, :en, :r)'
        )->execute([':m' => $merkmalId, ':s' => $schluessel, ':de' => $de, ':en' => $en, ':r' => min(9999, $reihenfolge)]);
        RassenDatenSchreiben::definitionen(true);
        return self::ergebnis(true, "Wert „{$de}“ angelegt.");
    }

    /**
     * Speichert die Werteliste eines Merkmals auf einmal (Namen, Reihenfolge).
     *
     * @param array<int|string, array{de?: string, en?: string, r?: string}> $eingabe [wertId => …]
     */
    public static function werteSpeichern(int $merkmalId, array $eingabe): array
    {
        $m = self::merkmal($merkmalId);
        if ($m === null) {
            return self::ergebnis(false, 'Merkmal nicht gefunden.');
        }
        $pdo = Database::getConnection();
        $upd = $pdo->prepare('UPDATE merkmal_werte SET name_de = :de, name_en = :en, reihenfolge = :r WHERE id = :id AND merkmal_id = :m');
        $geaendert = 0;
        $fehler = [];
        $namenDe = [];
        foreach ($m['werte'] as $w) {
            $neu = $eingabe[$w['id']] ?? null;
            if (!is_array($neu)) {
                $namenDe[mb_strtolower($w['name_de'])] = $w['id'];
                continue;
            }
            [$de, $en, $f] = self::namen($neu['de'] ?? '', $neu['en'] ?? '');
            if ($f) {
                $fehler[] = "„{$w['name_de']}“: {$f}";
                $namenDe[mb_strtolower($w['name_de'])] = $w['id'];
                continue;
            }
            if (isset($namenDe[mb_strtolower($de)])) {
                $fehler[] = "„{$de}“ steht doppelt in der Liste. Zum Zusammenlegen „Zusammenführen“ nutzen.";
                continue;
            }
            $namenDe[mb_strtolower($de)] = $w['id'];
            $r = max(0, min(9999, (int) ($neu['r'] ?? $w['reihenfolge'])));
            if ($de !== $w['name_de'] || $en !== $w['name_en'] || $r !== $w['reihenfolge']) {
                $upd->execute([':de' => $de, ':en' => $en, ':r' => $r, ':id' => $w['id'], ':m' => $merkmalId]);
                $geaendert++;
            }
        }
        RassenDatenSchreiben::definitionen(true);
        if ($fehler) {
            return self::ergebnis(false, implode(' ', $fehler) . ($geaendert ? " Die übrigen {$geaendert} Änderungen sind gespeichert." : ''));
        }
        return self::ergebnis(true, $geaendert ? "{$geaendert} Werte bei „{$m['name_de']}“ gespeichert." : 'Keine Änderungen.');
    }

    public static function wertLoeschen(int $merkmalId, int $wertId): array
    {
        $w = self::wert($merkmalId, $wertId);
        if ($w === null) {
            return self::ergebnis(false, 'Wert nicht gefunden.');
        }
        $n = self::nutzung()['werte'][$wertId] ?? 0;
        if ($n > 0) {
            return self::ergebnis(false, "„{$w['name_de']}“ ist bei " . self::rassen($n) . ' eingetragen. Erst zusammenführen oder bei den Rassen entfernen.');
        }
        Database::getConnection()->prepare('DELETE FROM merkmal_werte WHERE id = :id AND merkmal_id = :m')->execute([':id' => $wertId, ':m' => $merkmalId]);
        RassenDatenSchreiben::definitionen(true);
        return self::ergebnis(true, "Wert „{$w['name_de']}“ gelöscht.");
    }

    /**
     * Legt Wert $quellId auf $zielId (gleiches Merkmal): Rassen mit dem
     * Quellwert bekommen den Zielwert, der Quellwert verschwindet.
     * Je betroffener Rasse entsteht eine Version (Anlass "umstrukturierung").
     */
    public static function werteZusammenfuehren(int $merkmalId, int $quellId, int $zielId, ?int $userId): array
    {
        $quelle = self::wert($merkmalId, $quellId);
        $ziel = self::wert($merkmalId, $zielId);
        if ($quelle === null || $ziel === null || $quellId === $zielId) {
            return self::ergebnis(false, 'Bitte einen anderen Wert desselben Merkmals als Ziel wählen.');
        }
        $m = self::merkmal($merkmalId);
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT DISTINCT breed_id FROM rasse_merkmale WHERE wert_id = :w');
        $stmt->execute([':w' => $quellId]);
        $rassen = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $pdo->beginTransaction();
        try {
            $vorher = [];
            foreach ($rassen as $breedId) {
                $vorher[$breedId] = RassenDatenSchreiben::lesbar($m, RassenDatenSchreiben::formularWerte($breedId)[$m['schluessel']] ?? null);
            }
            // Wo der Zielwert schon steht, fällt der Quellwert nur weg
            $pdo->prepare(
                'DELETE q FROM rasse_merkmale q
                 JOIN rasse_merkmale z ON z.breed_id = q.breed_id AND z.merkmal_id = q.merkmal_id AND z.wert_id = :ziel
                 WHERE q.wert_id = :quelle'
            )->execute([':ziel' => $zielId, ':quelle' => $quellId]);
            $pdo->prepare('UPDATE rasse_merkmale SET wert_id = :ziel WHERE wert_id = :quelle')->execute([':ziel' => $zielId, ':quelle' => $quellId]);
            $pdo->prepare('DELETE FROM merkmal_werte WHERE id = :id')->execute([':id' => $quellId]);
            $mNeu = self::merkmal($merkmalId);
            foreach ($rassen as $breedId) {
                $nachher = RassenDatenSchreiben::lesbar($mNeu, RassenDatenSchreiben::formularWerte($breedId)[$m['schluessel']] ?? null);
                ContentRevision::protokolliereFeld($pdo, 'breeds', $breedId, 'merkmal_' . $m['schluessel'], $vorher[$breedId], $nachher, $userId, 'umstrukturierung');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Werte zusammenführen: ' . $e->getMessage());
            return self::ergebnis(false, 'Beim Zusammenführen ist ein Fehler aufgetreten. Es wurde nichts geändert.');
        }
        RassenDatenSchreiben::definitionen(true);
        return self::ergebnis(true, "„{$quelle['name_de']}“ ist in „{$ziel['name_de']}“ aufgegangen (" . self::rassen(count($rassen)) . ').');
    }

    private static function wert(int $merkmalId, int $wertId): ?array
    {
        $m = self::merkmal($merkmalId);
        foreach ($m['werte'] ?? [] as $schluessel => $w) {
            if ($w['id'] === $wertId) {
                return $w + ['schluessel' => $schluessel];
            }
        }
        return null;
    }
}
