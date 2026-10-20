<?php
/**
 * models/RassenDatenSchreiben.php
 *
 * Schreibt Merkmale und Textabschnitte einer Rasse (PLAN.md Abschnitt 10):
 * "Rasse bearbeiten", TheDogAPI-Abgleich, Wiederherstellen aus den
 * Versionen. Jede Änderung landet in content_revisions, mit Feldnamen
 * wie "text_de_wesen" oder "merkmal_farben" (tabelle = 'breeds').
 * Texte lassen sich daraus wiederherstellen, Merkmale nur nachlesen.
 *
 * Die Größenklasse folgt der Schulterhöhe (Groessenklasse.php, quelle
 * 'berechnet'), außer sie wurde von Hand gesetzt (quelle 'hand').
 *
 * Werte im Formularformat je Merkmal (siehe formularWerte()):
 *   ['werte' => [wertSchluessel, …], 'spannen' => [geschlecht => [von, bis]], 'text' => ?string]
 */

declare(strict_types=1);

require_once __DIR__ . '/RassenDaten.php';
require_once __DIR__ . '/ContentRevision.php';
require_once __DIR__ . '/Uebersetzung.php';
require_once __DIR__ . '/Groessenklasse.php';

class RassenDatenSchreiben
{
    /** Markiert eine Rasse, die nie aus alten Feldern übernommen werden soll (neu angelegt). */
    private const STAND_MANUELL = 'manuell';

    // ------------------------------------------------------------------
    // Definitionen und aktuelle Werte
    // ------------------------------------------------------------------

    /**
     * Alle Merkmale mit ihren Werten, in Anzeigereihenfolge.
     *
     * @return list<array{id: int, schluessel: string, typ: string, gruppe: string, name_de: string, name_en: ?string,
     *     einheit: ?string, je_geschlecht: bool, filterbar: bool, verknuepfung: string, reihenfolge: int,
     *     werte: array<string, array{id: int, name_de: string, name_en: ?string, reihenfolge: int}>}>
     */
    public static function definitionen(bool $neuLaden = false): array
    {
        static $cache = null;
        if ($cache !== null && !$neuLaden) {
            return $cache;
        }
        $pdo = Database::getConnection();
        $merkmale = [];
        foreach ($pdo->query('SELECT * FROM merkmale ORDER BY reihenfolge, id')->fetchAll() as $m) {
            $merkmale[(int) $m['id']] = [
                'id' => (int) $m['id'], 'schluessel' => $m['schluessel'], 'typ' => $m['typ'], 'gruppe' => $m['gruppe'],
                'name_de' => $m['name_de'], 'name_en' => $m['name_en'], 'einheit' => $m['einheit'],
                'je_geschlecht' => (bool) $m['je_geschlecht'], 'filterbar' => (bool) $m['filterbar'],
                'verknuepfung' => $m['filter_verknuepfung'] ?? 'und', 'reihenfolge' => (int) $m['reihenfolge'], 'werte' => [],
            ];
        }
        foreach ($pdo->query('SELECT * FROM merkmal_werte ORDER BY reihenfolge, name_de')->fetchAll() as $w) {
            if (isset($merkmale[(int) $w['merkmal_id']])) {
                $merkmale[(int) $w['merkmal_id']]['werte'][$w['schluessel']] = ['id' => (int) $w['id'], 'name_de' => $w['name_de'], 'name_en' => $w['name_en'], 'reihenfolge' => (int) $w['reihenfolge']];
            }
        }
        return $cache = array_values($merkmale);
    }

    /** @return array<string, array> Definition je Schlüssel */
    public static function nachSchluessel(bool $neuLaden = false): array
    {
        return array_column(self::definitionen($neuLaden), null, 'schluessel');
    }

    /**
     * Aktuelle Werte einer Rasse im Formularformat, alle Quellen zusammen.
     *
     * @return array<string, array{werte: list<string>, spannen: array<string, array{0: ?string, 1: ?string}>, text: ?string}>
     */
    public static function formularWerte(int $breedId): array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT m.schluessel, w.schluessel AS wert, rm.zahl_von, rm.zahl_bis, rm.text_wert, rm.geschlecht
             FROM rasse_merkmale rm
             JOIN merkmale m ON m.id = rm.merkmal_id
             LEFT JOIN merkmal_werte w ON w.id = rm.wert_id
             WHERE rm.breed_id = :id'
        );
        $stmt->execute([':id' => $breedId]);
        $ergebnis = [];
        foreach ($stmt->fetchAll() as $r) {
            $e = &$ergebnis[$r['schluessel']];
            $e ??= ['werte' => [], 'spannen' => [], 'text' => null];
            if ($r['wert'] !== null && !in_array($r['wert'], $e['werte'], true)) {
                $e['werte'][] = $r['wert'];
            }
            if ($r['zahl_von'] !== null || $r['zahl_bis'] !== null) {
                $e['spannen'][$r['geschlecht']] = [self::zahlText($r['zahl_von']), self::zahlText($r['zahl_bis'])];
            }
            if ($r['text_wert'] !== null) {
                $e['text'] = (string) $r['text_wert'];
            }
            unset($e);
        }
        foreach ($ergebnis as &$e) {
            sort($e['werte']);
            ksort($e['spannen']);
        }
        unset($e);
        return $ergebnis;
    }

    private static function zahlText($zahl): ?string
    {
        if ($zahl === null || $zahl === '') {
            return null;
        }
        return rtrim(rtrim(number_format((float) $zahl, 1, '.', ''), '0'), '.');
    }

    // ------------------------------------------------------------------
    // Formulareingabe
    // ------------------------------------------------------------------

    /**
     * Formulareingabe → Formularformat, mit Prüfung.
     * Eingabe: merkmal[schl][werte][] / [wert] / [spanne][geschlecht][von|bis] / [land]
     *
     * @return array{werte: array<string, array>, fehler: list<string>}
     */
    public static function ausEingabe(array $post): array
    {
        $werte = [];
        $fehler = [];
        foreach (self::definitionen() as $m) {
            $roh = is_array($post[$m['schluessel']] ?? null) ? $post[$m['schluessel']] : [];
            $e = ['werte' => [], 'spannen' => [], 'text' => null];
            switch ($m['typ']) {
                case 'mehrfach':
                    foreach ((array) ($roh['werte'] ?? []) as $w) {
                        if (is_string($w) && isset($m['werte'][$w]) && !in_array($w, $e['werte'], true)) {
                            $e['werte'][] = $w;
                        }
                    }
                    sort($e['werte']);
                    break;
                case 'auswahl':
                case 'stufe':
                    $w = (string) ($roh['wert'] ?? '');
                    if ($w !== '' && isset($m['werte'][$w])) {
                        $e['werte'] = [$w];
                    }
                    break;
                case 'ja_nein':
                    $w = (string) ($roh['wert'] ?? '');
                    $e['text'] = in_array($w, ['ja', 'nein'], true) ? $w : null;
                    break;
                case 'land':
                    $code = strtoupper(trim((string) ($roh['land'] ?? '')));
                    if ($code !== '') {
                        if (RassenDaten::landName($code, 'de') === $code) {
                            $fehler[] = "„{$m['name_de']}“: unbekannter Ländercode {$code}.";
                        } else {
                            $e['text'] = $code;
                        }
                    }
                    break;
                case 'zahl_spanne':
                    // "alle" auch bei je_geschlecht: Angaben ohne Unterschied (z. B. TheDogAPI)
                    $geschlechter = $m['je_geschlecht'] ? ['ruede', 'huendin', 'alle'] : ['alle'];
                    foreach ($geschlechter as $g) {
                        $von = self::zahlAusEingabe($roh['spanne'][$g]['von'] ?? '');
                        $bis = self::zahlAusEingabe($roh['spanne'][$g]['bis'] ?? '');
                        if ($von === false || $bis === false) {
                            $fehler[] = "„{$m['name_de']}“: bitte nur Zahlen eintragen.";
                            continue;
                        }
                        if ($von !== null && $bis !== null && (float) $von > (float) $bis) {
                            $fehler[] = "„{$m['name_de']}“: der Von-Wert ist größer als der Bis-Wert.";
                            continue;
                        }
                        if ($von !== null || $bis !== null) {
                            $e['spannen'][$g] = [$von, $bis];
                        }
                    }
                    break;
            }
            if ($e['werte'] || $e['spannen'] || $e['text'] !== null) {
                $werte[$m['schluessel']] = $e;
            }
        }
        return ['werte' => $werte, 'fehler' => $fehler];
    }

    /** "12,5" → "12.5", leer → null, keine Zahl → false */
    private static function zahlAusEingabe($wert): string|null|false
    {
        $wert = trim(str_replace(',', '.', (string) $wert));
        if ($wert === '') {
            return null;
        }
        if (!is_numeric($wert) || (float) $wert < 0 || (float) $wert > 9999) {
            return false;
        }
        return self::zahlText($wert);
    }

    /** Texteingabe text[sprache][abschnitt] → bereinigt, leere weg, \r\n → \n */
    public static function texteAusEingabe(array $post): array
    {
        $ergebnis = ['de' => [], 'en' => []];
        foreach (['de', 'en'] as $sprache) {
            foreach (array_keys(RassenDaten::ABSCHNITTE) as $abschnitt) {
                $t = trim(str_replace("\r\n", "\n", (string) ($post[$sprache][$abschnitt] ?? '')));
                if ($t !== '') {
                    $ergebnis[$sprache][$abschnitt] = $t;
                }
            }
        }
        return $ergebnis;
    }

    // ------------------------------------------------------------------
    // Speichern
    // ------------------------------------------------------------------

    /**
     * Speichert Merkmale und Texte aus dem Formular. Nur Geänderte werden
     * geschrieben (Quelle 'hand') und als Version festgehalten.
     */
    /**
     * @param ?bool $groesseAuto true = Größenklasse aus der Schulterhöhe, false = von Hand wie eingegeben,
     *                           null = nur neu berechnen, wenn sie nicht von Hand gesetzt ist
     */
    public static function speichern(int $breedId, array $merkmalWerte, array $texte, ?int $userId, ?bool $groesseAuto = null): void
    {
        RassenDaten::uebernehmenWennNoetig($breedId);
        $pdo = Database::getConnection();
        $vorher = self::formularWerte($breedId);
        $texteVorher = RassenDaten::alleTexte($breedId);

        $pdo->beginTransaction();
        try {
            foreach (self::definitionen() as $m) {
                $alt = $vorher[$m['schluessel']] ?? null;
                $neu = $merkmalWerte[$m['schluessel']] ?? null;
                if ($m['schluessel'] === 'groessenklasse' && $groesseAuto === true) {
                    continue;
                }
                if (self::gleich($alt, $neu)) {
                    if ($m['schluessel'] === 'groessenklasse' && $groesseAuto === false) {
                        // unverändert, aber ab jetzt von Hand festgelegt
                        $pdo->prepare("UPDATE rasse_merkmale SET quelle = 'hand' WHERE breed_id = :b AND merkmal_id = :m")
                            ->execute([':b' => $breedId, ':m' => $m['id']]);
                    }
                    continue;
                }
                self::merkmalSchreiben($pdo, $breedId, $m, $neu, 'hand');
                ContentRevision::protokolliereFeld($pdo, 'breeds', $breedId, 'merkmal_' . $m['schluessel'],
                    self::lesbar($m, $alt), self::lesbar($m, $neu), $userId, 'bearbeitung');
            }
            foreach (['de', 'en'] as $sprache) {
                foreach (array_keys(RassenDaten::ABSCHNITTE) as $abschnitt) {
                    self::textSchreiben($pdo, $breedId, $sprache, $abschnitt, $texteVorher[$sprache][$abschnitt] ?? null,
                        $texte[$sprache][$abschnitt] ?? null, $userId, 'bearbeitung', 'hand');
                }
            }
            if ($groesseAuto !== false) {
                self::groessenklasseAktualisieren($pdo, $breedId, $userId, $groesseAuto === true, 'bearbeitung');
            }
            // Neu angelegte Rasse: nie aus (leeren) alten Feldern übernehmen
            $pdo->prepare('UPDATE breeds SET daten_stand = :s, updated_at = updated_at WHERE id = :id AND daten_stand IS NULL')
                ->execute([':s' => self::STAND_MANUELL, ':id' => $breedId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function gleich(?array $a, ?array $b): bool
    {
        $norm = fn(?array $x) => json_encode($x === null || (!$x['werte'] && !$x['spannen'] && $x['text'] === null) ? null : $x);
        return $norm($a) === $norm($b);
    }

    /**
     * Übernimmt geprüfte Vorschläge (admin_merkmale_befuellen.php), nur
     * wo das Merkmal noch leer ist. Je Wert eine Version, Anlass "import".
     *
     * @param list<array{breed_id: int, merkmal: string, wert: array, quelle: string}> $liste quelle: thedogapi | vdh_text
     * @return int Anzahl geschriebener Werte
     */
    public static function vorschlaegeUebernehmen(array $liste, ?int $userId): int
    {
        $defs = self::nachSchluessel();
        $pdo = Database::getConnection();
        $geschrieben = 0;
        $pdo->beginTransaction();
        try {
            foreach ($liste as $v) {
                $m = $defs[$v['merkmal']] ?? null;
                if ($m === null || !in_array($v['quelle'], ['thedogapi', 'vdh_text'], true)) {
                    continue;
                }
                $vorher = self::formularWerte($v['breed_id'])[$m['schluessel']] ?? null;
                if (!self::gleich($vorher, null)) {
                    continue;   // inzwischen gefüllt
                }
                $neu = ['werte' => array_values(array_filter($v['wert']['werte'] ?? [], fn($w) => isset($m['werte'][$w]))),
                    'spannen' => $v['wert']['spannen'] ?? [], 'text' => $v['wert']['text'] ?? null];
                if (self::gleich($neu, null)) {
                    continue;
                }
                self::merkmalSchreiben($pdo, $v['breed_id'], $m, $neu, $v['quelle']);
                ContentRevision::protokolliereFeld($pdo, 'breeds', $v['breed_id'], 'merkmal_' . $m['schluessel'],
                    null, self::lesbar($m, $neu), $userId, 'import');
                $geschrieben++;
                if ($m['schluessel'] === 'schulterhoehe') {
                    self::groessenklasseAktualisieren($pdo, $v['breed_id'], $userId, false, 'import');
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $geschrieben;
    }

    /**
     * Schreibt geprüfte Inhalte aus dem Inhalte-Repo (InhalteImport) in
     * der laufenden Transaktion. Quelle 'hand' (neu formuliert, keine
     * Quellenangabe), jede Änderung als Version mit Anlass "import".
     *
     * @param array<string, ?array> $merkmale Schlüssel => neuer Wert (Formularformat) oder null = leeren
     * @param array<string, array<string, ?string>> $texte sprache => abschnitt => neuer Text oder null = löschen
     */
    public static function importSchreiben(PDO $pdo, int $breedId, array $merkmale, array $texte, ?string $nameEn, ?int $userId): void
    {
        $defs = self::nachSchluessel();
        $vorher = self::formularWerte($breedId);
        foreach ($merkmale as $schluessel => $neu) {
            $m = $defs[$schluessel] ?? null;
            if ($m === null) {
                continue;
            }
            self::merkmalSchreiben($pdo, $breedId, $m, $neu, 'hand');
            ContentRevision::protokolliereFeld($pdo, 'breeds', $breedId, 'merkmal_' . $schluessel,
                self::lesbar($m, $vorher[$schluessel] ?? null), self::lesbar($m, $neu), $userId, 'import');
        }
        if (array_key_exists('schulterhoehe', $merkmale) && !array_key_exists('groessenklasse', $merkmale)) {
            self::groessenklasseAktualisieren($pdo, $breedId, $userId, false, 'import');
        }
        $texteVorher = RassenDaten::alleTexte($breedId);
        foreach ($texte as $sprache => $abschnitte) {
            foreach ($abschnitte as $abschnitt => $neu) {
                self::textSchreiben($pdo, $breedId, $sprache, $abschnitt, $texteVorher[$sprache][$abschnitt] ?? null, $neu, $userId, 'import', 'hand');
            }
        }
        if ($nameEn !== null) {
            $alt = $pdo->prepare('SELECT name_en FROM breeds WHERE id = :id');
            $alt->execute([':id' => $breedId]);
            $altWert = $alt->fetchColumn();
            $pdo->prepare('UPDATE breeds SET name_en = :n WHERE id = :id')->execute([':n' => $nameEn !== '' ? $nameEn : null, ':id' => $breedId]);
            ContentRevision::protokolliereFeld($pdo, 'breeds', $breedId, 'name_en', $altWert === false ? null : $altWert, $nameEn !== '' ? $nameEn : null, $userId, 'import');
        }
    }

    /**
     * Setzt die Größenklasse einer Rasse aus ihrer Schulterhöhe, in der
     * laufenden Transaktion. Von Hand gesetzte Klassen bleiben, außer bei
     * $erzwingen. Ohne Schulterhöhe bleibt die Klasse stehen, bei
     * $erzwingen gilt sie danach nicht mehr als von Hand gesetzt.
     *
     * @return bool ob sich die Klasse geändert hat
     */
    public static function groessenklasseAktualisieren(PDO $pdo, int $breedId, ?int $userId, bool $erzwingen, string $anlass): bool
    {
        $m = self::nachSchluessel()['groessenklasse'] ?? null;
        if ($m === null) {
            return false;
        }
        $q = $pdo->prepare('SELECT DISTINCT quelle FROM rasse_merkmale WHERE breed_id = :b AND merkmal_id = :m');
        $q->execute([':b' => $breedId, ':m' => $m['id']]);
        $quellen = $q->fetchAll(PDO::FETCH_COLUMN);
        if (!$erzwingen && in_array('hand', $quellen, true)) {
            return false;
        }
        $vorher = self::formularWerte($breedId);
        $klassen = array_values(array_filter(
            Groessenklasse::ausSpannen(array_values($vorher['schulterhoehe']['spannen'] ?? [])),
            fn($k) => isset($m['werte'][$k])
        ));
        if (!$klassen) {
            if ($erzwingen && in_array('hand', $quellen, true)) {
                $pdo->prepare("UPDATE rasse_merkmale SET quelle = 'alt' WHERE breed_id = :b AND merkmal_id = :m")
                    ->execute([':b' => $breedId, ':m' => $m['id']]);
            }
            return false;
        }
        $alt = $vorher['groessenklasse'] ?? null;
        $neu = ['werte' => $klassen, 'spannen' => [], 'text' => null];
        if (self::gleich($alt, $neu)) {
            if ($quellen !== ['berechnet']) {
                $pdo->prepare("UPDATE rasse_merkmale SET quelle = 'berechnet' WHERE breed_id = :b AND merkmal_id = :m")
                    ->execute([':b' => $breedId, ':m' => $m['id']]);
            }
            return false;
        }
        self::merkmalSchreiben($pdo, $breedId, $m, $neu, 'berechnet');
        ContentRevision::protokolliereFeld($pdo, 'breeds', $breedId, 'merkmal_groessenklasse',
            self::lesbar($m, $alt), self::lesbar($m, $neu), $userId, $anlass);
        return true;
    }

    /**
     * Größenklasse für alle Rassen mit Schulterhöhe neu berechnen, von
     * Hand gesetzte bleiben (Knopf auf admin_merkmale.php).
     *
     * @return array{geaendert: int, von_hand: int}
     */
    public static function alleGroessenklassenAktualisieren(?int $userId): array
    {
        RassenDaten::alleUebernehmen();
        $pdo = Database::getConnection();
        $ids = $pdo->query(
            "SELECT DISTINCT rm.breed_id FROM rasse_merkmale rm JOIN merkmale m ON m.id = rm.merkmal_id
             WHERE m.schluessel = 'schulterhoehe' ORDER BY rm.breed_id"
        )->fetchAll(PDO::FETCH_COLUMN);
        $vonHand = (int) $pdo->query(
            "SELECT COUNT(DISTINCT rm.breed_id) FROM rasse_merkmale rm JOIN merkmale m ON m.id = rm.merkmal_id
             WHERE m.schluessel = 'groessenklasse' AND rm.quelle = 'hand'"
        )->fetchColumn();
        $geaendert = 0;
        $pdo->beginTransaction();
        try {
            foreach ($ids as $id) {
                $geaendert += self::groessenklasseAktualisieren($pdo, (int) $id, $userId, false, 'umstrukturierung') ? 1 : 0;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['geaendert' => $geaendert, 'von_hand' => $vonHand];
    }

    /** Ob die Größenklasse einer Rasse von Hand gesetzt ist. */
    public static function groessenklasseVonHand(int $breedId): bool
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT 1 FROM rasse_merkmale rm JOIN merkmale m ON m.id = rm.merkmal_id
             WHERE rm.breed_id = :b AND m.schluessel = 'groessenklasse' AND rm.quelle = 'hand' LIMIT 1"
        );
        $stmt->execute([':b' => $breedId]);
        return (bool) $stmt->fetchColumn();
    }

    /** Ersetzt alle Werte eines Merkmals einer Rasse. */
    private static function merkmalSchreiben(PDO $pdo, int $breedId, array $m, ?array $neu, string $quelle): void
    {
        $pdo->prepare('DELETE FROM rasse_merkmale WHERE breed_id = :b AND merkmal_id = :m')->execute([':b' => $breedId, ':m' => $m['id']]);
        if ($neu === null) {
            return;
        }
        $ins = $pdo->prepare(
            'INSERT INTO rasse_merkmale (breed_id, merkmal_id, wert_id, zahl_von, zahl_bis, text_wert, geschlecht, quelle)
             VALUES (:b, :m, :w, :von, :bis, :t, :g, :q)'
        );
        foreach ($neu['werte'] as $w) {
            if (isset($m['werte'][$w])) {
                $ins->execute([':b' => $breedId, ':m' => $m['id'], ':w' => $m['werte'][$w]['id'], ':von' => null, ':bis' => null, ':t' => null, ':g' => 'alle', ':q' => $quelle]);
            }
        }
        foreach ($neu['spannen'] as $g => [$von, $bis]) {
            $ins->execute([':b' => $breedId, ':m' => $m['id'], ':w' => null, ':von' => $von, ':bis' => $bis, ':t' => null, ':g' => $g, ':q' => $quelle]);
        }
        if ($neu['text'] !== null) {
            $ins->execute([':b' => $breedId, ':m' => $m['id'], ':w' => null, ':von' => null, ':bis' => null, ':t' => $neu['text'], ':g' => 'alle', ':q' => $quelle]);
        }
    }

    /** Lesbare Fassung für die Versionen (deutsch). */
    public static function lesbar(array $m, ?array $wert): ?string
    {
        if ($wert === null) {
            return null;
        }
        $teile = [];
        foreach ($wert['werte'] as $w) {
            $teile[] = $m['werte'][$w]['name_de'] ?? $w;
        }
        $geschlechtName = ['ruede' => 'Rüde', 'huendin' => 'Hündin', 'alle' => ''];
        // Feste Reihenfolge Rüde, Hündin, beide (gespeichert ist alphabetisch)
        $spannen = array_replace(array_intersect_key(['ruede' => 0, 'huendin' => 0, 'alle' => 0], $wert['spannen']), $wert['spannen']);
        foreach ($spannen as $g => [$von, $bis]) {
            // Versionen und Prüfseite sind deutsch: Dezimalkomma
            [$von, $bis] = array_map(fn($z) => $z === null ? null : str_replace('.', ',', (string) $z), [$von, $bis]);
            $spanne = match (true) {
                $von === null => 'bis ' . $bis,
                $bis === null => 'ab ' . $von,
                default => $von . ($bis !== $von ? '–' . $bis : ''),
            } . ($m['einheit'] ? ' ' . $m['einheit'] : '');
            $teile[] = trim(($geschlechtName[$g] ?? $g) . ' ' . $spanne);
        }
        if ($wert['text'] !== null) {
            $teile[] = $m['typ'] === 'land' ? $wert['text'] . ' (' . RassenDaten::landName($wert['text'], 'de') . ')' : $wert['text'];
        }
        return $teile ? implode(', ', $teile) : null;
    }

    /** Schreibt einen Textabschnitt, wenn er sich ändert, samt Version. */
    private static function textSchreiben(PDO $pdo, int $breedId, string $sprache, string $abschnitt, ?string $alt, ?string $neu, ?int $userId, string $anlass, string $quelle): bool
    {
        $norm = fn(?string $t) => ($t = trim(str_replace("\r", '', (string) $t))) === '' ? null : $t;
        if ($norm($alt) === $norm($neu)) {
            return false;
        }
        if ($norm($neu) === null) {
            $pdo->prepare('DELETE FROM rasse_texte WHERE breed_id = :b AND sprache = :s AND abschnitt = :a')
                ->execute([':b' => $breedId, ':s' => $sprache, ':a' => $abschnitt]);
        } else {
            $pdo->prepare(
                'INSERT INTO rasse_texte (breed_id, sprache, abschnitt, text, quelle) VALUES (:b, :s, :a, :t, :q)
                 ON DUPLICATE KEY UPDATE text = VALUES(text), quelle = VALUES(quelle), geprueft = 0'
            )->execute([':b' => $breedId, ':s' => $sprache, ':a' => $abschnitt, ':t' => $norm($neu), ':q' => $quelle]);
        }
        ContentRevision::protokolliereFeld($pdo, 'breeds', $breedId, "text_{$sprache}_{$abschnitt}", $norm($alt), $norm($neu), $userId, $anlass);
        return true;
    }

    // ------------------------------------------------------------------
    // Versionen
    // ------------------------------------------------------------------

    /** "text_de_wesen" → ['de', 'wesen'], sonst null */
    public static function textFeld(string $feld): ?array
    {
        if (preg_match('/^text_(de|en)_([a-z]+)$/', $feld, $m) && array_key_exists($m[2], RassenDaten::ABSCHNITTE)) {
            return [$m[1], $m[2]];
        }
        return null;
    }

    /** Beschriftung virtueller Felder für die Versionsseite, sonst null. */
    public static function feldLabel(string $feld): ?string
    {
        if ($t = self::textFeld($feld)) {
            $titel = RassenDaten::ABSCHNITTE[$t[1]] ?? ['kurzportraet' => 'Kurzporträt', 'allgemein' => 'Allgemein', 'weitere' => 'Weitere Abschnitte'][$t[1]];
            return 'Text „' . $titel . '“ (' . ($t[0] === 'de' ? 'deutsch' : 'englisch') . ')';
        }
        if (str_starts_with($feld, 'merkmal_')) {
            $m = self::nachSchluessel()[substr($feld, 8)] ?? null;
            return 'Merkmal „' . ($m['name_de'] ?? substr($feld, 8)) . '“';
        }
        return null;
    }

    /** Setzt einen Textabschnitt auf einen früheren Stand (aus den Versionen). */
    public static function textWiederherstellen(int $breedId, string $feld, ?string $wert, int $userId): array
    {
        $t = self::textFeld($feld);
        if ($t === null) {
            return ['success' => false, 'message' => 'Dieses Feld lässt sich nicht automatisch wiederherstellen.'];
        }
        $pdo = Database::getConnection();
        $aktuell = RassenDaten::alleTexte($breedId)[$t[0]][$t[1]] ?? null;
        $pdo->beginTransaction();
        try {
            $geaendert = self::textSchreiben($pdo, $breedId, $t[0], $t[1], $aktuell, $wert, $userId, 'wiederherstellung', 'hand');
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Text wiederherstellen: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Beim Wiederherstellen ist ein Fehler aufgetreten. Es wurde nichts geändert.'];
        }
        return $geaendert
            ? ['success' => true, 'message' => self::feldLabel($feld) . ' wurde wiederhergestellt.']
            : ['success' => true, 'message' => 'Dieser Stand ist bereits aktuell, es wurde nichts geändert.'];
    }

    /** Vor dem Löschen einer Rasse: alle Texte als Version sichern (in der laufenden Transaktion). */
    public static function loeschungProtokollieren(PDO $pdo, int $breedId, ?int $userId): void
    {
        foreach (RassenDaten::alleTexte($breedId) as $sprache => $abschnitte) {
            foreach ($abschnitte as $abschnitt => $text) {
                ContentRevision::protokolliereFeld($pdo, 'breeds', $breedId, "text_{$sprache}_{$abschnitt}", $text, null, $userId, 'loeschung');
            }
        }
        $vorher = self::formularWerte($breedId);
        foreach (self::definitionen() as $m) {
            if (isset($vorher[$m['schluessel']])) {
                ContentRevision::protokolliereFeld($pdo, 'breeds', $breedId, 'merkmal_' . $m['schluessel'], self::lesbar($m, $vorher[$m['schluessel']]), null, $userId, 'loeschung');
            }
        }
    }

    // ------------------------------------------------------------------
    // TheDogAPI
    // ------------------------------------------------------------------

    /**
     * Übernimmt TheDogAPI-Werte ins neue Modell, nur wo noch nichts steht.
     *
     * @param array{temperament_en?: ?string, lebenserwartung?: ?string, zuchtzweck_en?: ?string, beschreibung_en?: ?string} $werte
     * @return list<string> was ergänzt wurde
     */
    public static function ausTheDogApi(int $breedId, array $werte, ?int $userId): array
    {
        RassenDaten::uebernehmenWennNoetig($breedId);
        $pdo = Database::getConnection();
        $vorher = self::formularWerte($breedId);
        $texte = RassenDaten::alleTexte($breedId);
        $defs = self::nachSchluessel();
        $ergaenzt = [];

        $pdo->beginTransaction();
        try {
            // Wesen aus dem englischen Temperament
            $en = trim((string) ($werte['temperament_en'] ?? ''));
            if ($en !== '' && empty($vorher['wesen']) && isset($defs['wesen'])) {
                $neu = ['werte' => [], 'spannen' => [], 'text' => null];
                foreach (array_filter(array_map('trim', explode(',', $en))) as $begriff) {
                    $deutsch = Uebersetzung::temperament($begriff)['text'];
                    $neu['werte'][] = self::wesenWert($pdo, $deutsch ?? $begriff, $begriff);
                }
                $neu['werte'] = array_values(array_unique($neu['werte']));
                sort($neu['werte']);
                $defs = self::nachSchluessel(true);
                self::merkmalSchreiben($pdo, $breedId, $defs['wesen'], $neu, 'thedogapi');
                ContentRevision::protokolliereFeld($pdo, 'breeds', $breedId, 'merkmal_wesen', null, self::lesbar($defs['wesen'], $neu), $userId, 'import');
                $ergaenzt[] = 'Wesen';
            }
            // Lebenserwartung "12-15" / "12 - 15 years"
            $le = (string) ($werte['lebenserwartung'] ?? '');
            if (empty($vorher['lebenserwartung']) && isset($defs['lebenserwartung'])
                && preg_match('/(\d+(?:[.,]\d+)?)(?:\s*(?:[-–]|to|bis)\s*(\d+(?:[.,]\d+)?))?/u', $le, $z)) {
                $von = str_replace(',', '.', $z[1]);
                $neu = ['werte' => [], 'spannen' => ['alle' => [self::zahlText($von), self::zahlText(str_replace(',', '.', $z[2] ?? $von))]], 'text' => null];
                self::merkmalSchreiben($pdo, $breedId, $defs['lebenserwartung'], $neu, 'thedogapi');
                ContentRevision::protokolliereFeld($pdo, 'breeds', $breedId, 'merkmal_lebenserwartung', null, self::lesbar($defs['lebenserwartung'], $neu), $userId, 'import');
                $ergaenzt[] = 'Lebenserwartung';
            }
            // Englische Texte
            $neueTexte = [];
            if (trim((string) ($werte['zuchtzweck_en'] ?? '')) !== '') {
                $neueTexte['zuchtzweck'] = trim((string) $werte['zuchtzweck_en']);
            }
            if (trim((string) ($werte['beschreibung_en'] ?? '')) !== '') {
                $neueTexte += RassenDaten::textZerlegen((string) $werte['beschreibung_en'], true);
            }
            foreach ($neueTexte as $abschnitt => $text) {
                if (trim($texte['en'][$abschnitt] ?? '') === ''
                    && self::textSchreiben($pdo, $breedId, 'en', $abschnitt, null, $text, $userId, 'import', 'thedogapi')) {
                    $ergaenzt[] = 'Text ' . $abschnitt . ' (englisch)';
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $ergaenzt;
    }

    /** Wesen-Wert nach deutschem Namen finden oder anlegen. */
    private static function wesenWert(PDO $pdo, string $deutsch, string $englisch): string
    {
        $wesen = self::nachSchluessel()['wesen'];
        foreach ($wesen['werte'] as $schl => $w) {
            if (mb_strtolower($w['name_de']) === mb_strtolower($deutsch)) {
                return $schl;
            }
        }
        $schl = substr(trim((string) preg_replace('/[^a-z0-9]+/', '_', strtr(mb_strtolower($deutsch), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'])), '_'), 0, 60) ?: md5($deutsch);
        $pdo->prepare('INSERT IGNORE INTO merkmal_werte (merkmal_id, schluessel, name_de, name_en, reihenfolge) VALUES (:m, :s, :de, :en, 1000)')
            ->execute([':m' => $wesen['id'], ':s' => $schl, ':de' => mb_substr($deutsch, 0, 100), ':en' => mb_substr($englisch, 0, 100)]);
        self::nachSchluessel(true);
        return $schl;
    }
}
