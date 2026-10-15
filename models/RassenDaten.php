<?php
/**
 * models/RassenDaten.php
 *
 * Merkmale und Textabschnitte einer Rasse (PLAN.md Abschnitt 10):
 * Lesen für die Anzeige und Übernahme aus den alten Feldern.
 *
 * Übernahme: Einmal je Rasse wird das neue Modell aus den alten Feldern
 * (breed_sizes, Tags, beschreibung …) abgeleitet, Quelle 'alt' bzw.
 * 'thedogapi' für einen unveränderten TheDogAPI-Text. Danach ist das neue
 * Modell die einzige Quelle: "Rasse bearbeiten" und der TheDogAPI-Abgleich
 * schreiben direkt hinein (speichern(), ausTheDogApi()). breeds.daten_stand
 * hält den Prüfwert der alten Felder zum Zeitpunkt der Übernahme fest;
 * NULL heißt "noch nicht übernommen".
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/RassentextGliederung.php';
require_once __DIR__ . '/Uebersetzung.php';
require_once __DIR__ . '/ContentRevision.php';

class RassenDaten
{
    /** Textabschnitte in Anzeigereihenfolge → Titel für Farbe und Übersetzung (rassentext.php). */
    public const ABSCHNITTE = [
        'kurzportraet' => null,
        'allgemein'    => null,
        'wesen'        => 'Wesen',
        'haltung'      => 'Haltung und Erziehung',
        'pflege'       => 'Pflege',
        'aussehen'     => 'Aussehen',
        'herkunft'     => 'Herkunft und Geschichte',
        'zuchtzweck'   => 'Zuchtzweck',
        'standard'     => 'Angaben aus dem Standard',
        'weitere'      => null,
    ];

    /** Überschriften in alten Texten → Abschnitt (deutsch und englisch). */
    private const TITEL_ZU_ABSCHNITT = [
        'wesen' => 'wesen', 'charakter' => 'wesen', 'character' => 'wesen', 'temperament' => 'wesen',
        'haltung und erziehung' => 'haltung', 'haltung' => 'haltung', 'erziehung' => 'haltung', 'keeping and training' => 'haltung',
        'pflege' => 'pflege', 'care' => 'pflege', 'grooming' => 'pflege',
        'aussehen' => 'aussehen', 'appearance' => 'aussehen',
        'herkunft und geschichte' => 'herkunft', 'herkunft' => 'herkunft', 'geschichte' => 'herkunft',
        'origin and history' => 'herkunft', 'history' => 'herkunft', 'origin' => 'herkunft',
        'zuchtzweck' => 'zuchtzweck', 'bred for' => 'zuchtzweck',
        'angaben aus dem standard' => 'standard', 'details from the standard' => 'standard',
    ];

    /** Bisherige Eigenschafts-Tags (Name klein) → [Merkmal, Wert]. Unbekannte landen unter "weitere". */
    private const TAG_ZUORDNUNG = [
        'beschäftigungsbedürftig'   => ['beschaeftigungsbedarf', 'hoch'],
        'mantrailing'               => ['aktivitaeten', 'mantrailing'],
        'sportlich'                 => ['bewegungsbedarf', 'hoch'],
        'wenig bewegungsdrang'      => ['bewegungsbedarf', 'gering'],
        'assistenzhund geeignet'    => ['eignung', 'assistenzhund'],
        'begleithund'               => ['eignung', 'begleithund'],
        'familienfreundlich'        => ['eignung', 'familienhund'],
        'geringer pflegeaufwand'    => ['pflegeaufwand', 'gering'],
        'normaler pflegeaufwand'    => ['pflegeaufwand', 'mittel'],
        'hoher pflegeaufwand'       => ['pflegeaufwand', 'hoch'],
        'selten'                    => ['verbreitung', 'selten'],
        'weit verbreitet'           => ['verbreitung', 'weit_verbreitet'],
        'hartnäckig'                => ['wesen', 'hartnaeckig'],
        'wachsam'                   => ['wesen', 'wachsam'],
    ];

    /** Gebräuchliche Ländernamen, die nicht dem ICU-Namen entsprechen. */
    private const LAND_ALIAS = [
        'england' => 'GB', 'grossbritannien' => 'GB', 'großbritannien' => 'GB', 'schottland' => 'GB', 'wales' => 'GB',
        'great britain' => 'GB', 'scotland' => 'GB', 'uk' => 'GB', 'usa' => 'US', 'vereinigte staaten' => 'US',
        'united states' => 'US', 'holland' => 'NL', 'tschechische republik' => 'CZ', 'czech republic' => 'CZ',
        'tibet' => 'CN', 'russland' => 'RU', 'russia' => 'RU',
    ];

    /** Ländername in der aktuellen Sprache, sonst der Code. */
    public static function landName(string $code, string $sprache): string
    {
        static $laender = null;
        $laender ??= require __DIR__ . '/../daten/laender.php';
        return $laender[$code][$sprache === 'en' ? 1 : 0] ?? $code;
    }

    /** "Deutschland", "Germany", "DE" → "DE"; null, wenn unbekannt. */
    public static function landCode(string $eingabe): ?string
    {
        static $index = null;
        $norm = fn(string $s): string => mb_strtolower(trim($s));
        if ($index === null) {
            $index = [];
            foreach (require __DIR__ . '/../daten/laender.php' as $code => [$de, $en]) {
                $index[$norm($de)] = $code;
                $index[$norm($en)] = $code;
                $index[mb_strtolower($code)] = $code;
            }
            foreach (self::LAND_ALIAS as $name => $code) {
                $index[$name] = $code;
            }
        }
        return $index[$norm($eingabe)] ?? null;
    }

    // ------------------------------------------------------------------
    // Übernahme aus den alten Feldern
    // ------------------------------------------------------------------

    /**
     * Prüfwert über alle alten Felder einer Rasse b, die in die Übernahme
     * eingehen, inklusive Größen, Tags (mit Kategorie) und Aktivitäten.
     */
    private const STAND_SQL = "MD5(CONCAT_WS('|',
        b.beschreibung, b.beschreibung_en, b.temperament, b.temperament_en, b.zuchtzweck, b.zuchtzweck_en,
        b.lebenserwartung, b.farben, b.ursprungsland, b.arbeitspruefung,
        (SELECT GROUP_CONCAT(CONCAT_WS(',', s.groesse, s.schulterhoehe_ruede_min_cm, s.schulterhoehe_ruede_max_cm,
                s.schulterhoehe_haendin_min_cm, s.schulterhoehe_haendin_max_cm, s.gewicht_ruede_min_kg, s.gewicht_ruede_max_kg,
                s.gewicht_haendin_min_kg, s.gewicht_haendin_max_kg) ORDER BY s.groesse SEPARATOR ';')
         FROM breed_sizes s WHERE s.breed_id = b.id),
        (SELECT GROUP_CONCAT(CONCAT(t.category, ':', t.name) ORDER BY t.id SEPARATOR ';')
         FROM breed_tags bt JOIN tags t ON t.id = bt.tag_id WHERE bt.breed_id = b.id),
        (SELECT GROUP_CONCAT(a.name ORDER BY a.id SEPARATOR ';')
         FROM breed_activities ba JOIN activities a ON a.id = ba.activity_id WHERE ba.breed_id = b.id),
        (SELECT MAX(r.id) FROM content_revisions r WHERE r.tabelle = 'breeds' AND r.datensatz_id = b.id AND r.feld = 'beschreibung_en')
    ))";

    /** Übernimmt, wenn die Rasse noch nicht ins neue Modell übernommen ist. */
    public static function uebernehmenWennNoetig(int $breedId): void
    {
        $stmt = Database::getConnection()->prepare('SELECT daten_stand IS NULL FROM breeds WHERE id = :id');
        $stmt->execute([':id' => $breedId]);
        if ((bool) $stmt->fetchColumn()) {
            self::uebernehmen($breedId);
        }
    }

    /** Alle Rassen, die eine Übernahme brauchen. @return int Anzahl übernommener Rassen */
    public static function alleUebernehmen(): int
    {
        $ids = Database::getConnection()->query(
            'SELECT id FROM breeds WHERE daten_stand IS NULL'
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            self::uebernehmen((int) $id);
        }
        return count($ids);
    }

    public static function uebernehmen(int $breedId): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, beschreibung, beschreibung_en, temperament, temperament_en, zuchtzweck, zuchtzweck_en,
                    lebenserwartung, farben, ursprungsland, arbeitspruefung
             FROM breeds WHERE id = :id'
        );
        $stmt->execute([':id' => $breedId]);
        $b = $stmt->fetch();
        if (!$b) {
            return;
        }

        $merkmale = [];   // [merkmal, wertSchluessel|null, von, bis, text, geschlecht]
        $texte = [];      // [sprache, abschnitt, text]

        // Größe aus breed_sizes
        $sizes = $pdo->prepare('SELECT * FROM breed_sizes WHERE breed_id = :id');
        $sizes->execute([':id' => $breedId]);
        foreach ($sizes->fetchAll() as $s) {
            $merkmale[] = ['groessenklasse', $s['groesse'], null, null, null, 'alle'];
            foreach ([
                ['schulterhoehe', 'ruede', 'schulterhoehe_ruede_min_cm', 'schulterhoehe_ruede_max_cm'],
                ['schulterhoehe', 'huendin', 'schulterhoehe_haendin_min_cm', 'schulterhoehe_haendin_max_cm'],
                ['gewicht', 'ruede', 'gewicht_ruede_min_kg', 'gewicht_ruede_max_kg'],
                ['gewicht', 'huendin', 'gewicht_haendin_min_kg', 'gewicht_haendin_max_kg'],
            ] as [$merkmal, $geschlecht, $vonFeld, $bisFeld]) {
                $von = $s[$vonFeld] ?? null;
                $bis = $s[$bisFeld] ?? null;
                if ($von !== null || $bis !== null) {
                    $merkmale[] = [$merkmal, null, $von, $bis, null, $geschlecht];
                }
            }
        }

        // Eigenschafts-Tags und Aktivitäten
        // Bekannte Tags fest zugeordnet, sonst Kategorie "Wesen" → Wesen
        // (mit dem Wort des Tags), alles andere → "weitere"
        $tags = $pdo->prepare('SELECT t.name, t.category FROM tags t JOIN breed_tags bt ON bt.tag_id = t.id WHERE bt.breed_id = :id');
        $tags->execute([':id' => $breedId]);
        foreach ($tags->fetchAll() as ['name' => $name, 'category' => $kategorie]) {
            $ziel = mb_strtolower(trim((string) $kategorie)) === 'wesen' ? 'wesen' : 'weitere';
            [$merkmal, $wert] = self::TAG_ZUORDNUNG[mb_strtolower(trim($name))] ?? [$ziel, self::wertAnlegen($ziel, $name)];
            $merkmale[] = [$merkmal, $wert, null, null, null, 'alle'];
        }
        $akt = $pdo->prepare('SELECT a.name FROM activities a JOIN breed_activities ba ON ba.activity_id = a.id WHERE ba.breed_id = :id');
        $akt->execute([':id' => $breedId]);
        foreach ($akt->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $merkmale[] = ['aktivitaeten', self::wertAnlegen('aktivitaeten', $name), null, null, null, 'alle'];
        }

        // Einzelfelder
        if (in_array($b['arbeitspruefung'], ['mit', 'ohne', 'fakultativ'], true)) {
            $merkmale[] = ['arbeitspruefung', $b['arbeitspruefung'], null, null, null, 'alle'];
        }
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:[-–]|bis|to)\s*(\d+(?:[.,]\d+)?)/u', (string) $b['lebenserwartung'], $m)) {
            $merkmale[] = ['lebenserwartung', null, str_replace(',', '.', $m[1]), str_replace(',', '.', $m[2]), null, 'alle'];
        } elseif (preg_match('/^\s*(\d+)\s*/', (string) $b['lebenserwartung'], $m)) {
            $merkmale[] = ['lebenserwartung', null, $m[1], $m[1], null, 'alle'];
        }
        foreach (preg_split('/\s*(?:,|;|\/|\bund\b|\boder\b)\s*/u', trim((string) $b['farben'])) ?: [] as $farbe) {
            if ($farbe !== '') {
                $merkmale[] = ['farben', self::wertAnlegen('farben', $farbe), null, null, null, 'alle'];
            }
        }
        $land = trim((string) $b['ursprungsland']) !== '' ? self::landCode((string) $b['ursprungsland']) : null;
        if ($land !== null) {
            $merkmale[] = ['ursprungsland', null, null, null, $land, 'alle'];
        }

        // Wesen aus dem Temperament: deutsch; sonst jeder englische Begriff
        // einzeln, über das Wörterbuch übersetzt. Unbekannte Begriffe werden
        // als Wert angelegt (deutsch = englisch), bis jemand sie übersetzt.
        if (trim((string) $b['temperament']) !== '') {
            foreach (array_filter(array_map('trim', explode(',', (string) $b['temperament']))) as $begriff) {
                $merkmale[] = ['wesen', self::wertAnlegen('wesen', $begriff), null, null, null, 'alle'];
            }
        } else {
            foreach (array_filter(array_map('trim', explode(',', (string) $b['temperament_en']))) as $begriff) {
                $deutsch = Uebersetzung::temperament($begriff)['text'];
                $merkmale[] = ['wesen', self::wertAnlegen('wesen', $deutsch ?? $begriff, $deutsch === null ? $begriff : null), null, null, null, 'alle'];
            }
        }

        // Texte
        foreach (self::textZerlegen((string) $b['beschreibung'], false) as $abschnitt => $text) {
            $texte[] = ['de', $abschnitt, $text, 'alt'];
        }
        $importiert = ContentRevision::stammtAusImport('breeds', $breedId, 'beschreibung_en', $b['beschreibung_en'], null);
        foreach (self::textZerlegen((string) $b['beschreibung_en'], $importiert) as $abschnitt => $text) {
            $texte[] = ['en', $abschnitt, $text, $importiert ? 'thedogapi' : 'alt'];
        }
        foreach (['de' => 'zuchtzweck', 'en' => 'zuchtzweck_en'] as $sprache => $feld) {
            if (trim((string) $b[$feld]) !== '') {
                $texte[] = [$sprache, 'zuchtzweck', trim((string) $b[$feld]), 'alt'];
            }
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM rasse_merkmale WHERE breed_id = :id AND quelle = 'alt'")->execute([':id' => $breedId]);
            $pdo->prepare("DELETE FROM rasse_texte WHERE breed_id = :id AND quelle = 'alt'")->execute([':id' => $breedId]);

            $ids = self::merkmalIds();
            $ins = $pdo->prepare(
                "INSERT INTO rasse_merkmale (breed_id, merkmal_id, wert_id, zahl_von, zahl_bis, text_wert, geschlecht, quelle)
                 VALUES (:breed, :merkmal, :wert, :von, :bis, :text, :geschlecht, 'alt')"
            );
            $schonDa = [];
            foreach ($merkmale as [$merkmal, $wert, $von, $bis, $text, $geschlecht]) {
                $merkmalId = $ids[$merkmal]['id'] ?? null;
                $wertId = $wert !== null ? ($ids[$merkmal]['werte'][$wert] ?? null) : null;
                if ($merkmalId === null || ($wert !== null && $wertId === null)) {
                    continue;
                }
                $schluessel = $merkmal . '|' . $wertId . '|' . $geschlecht . '|' . $text;
                if (isset($schonDa[$schluessel])) {
                    continue;
                }
                $schonDa[$schluessel] = true;
                $ins->execute([':breed' => $breedId, ':merkmal' => $merkmalId, ':wert' => $wertId, ':von' => $von,
                    ':bis' => $bis, ':text' => $text, ':geschlecht' => $geschlecht]);
            }

            // Abschnitte, die schon von Hand oder aus einer anderen Quelle da sind, bleiben
            $insText = $pdo->prepare(
                'INSERT IGNORE INTO rasse_texte (breed_id, sprache, abschnitt, text, quelle) VALUES (:breed, :sprache, :abschnitt, :text, :quelle)'
            );
            foreach ($texte as [$sprache, $abschnitt, $text, $quelle]) {
                $insText->execute([':breed' => $breedId, ':sprache' => $sprache, ':abschnitt' => $abschnitt, ':text' => $text, ':quelle' => $quelle]);
            }

            // Prüfwert merken; updated_at = updated_at hält den Änderungszeitpunkt fest
            $pdo->prepare('UPDATE breeds b SET b.daten_stand = ' . self::STAND_SQL . ', b.updated_at = b.updated_at WHERE b.id = :id')
                ->execute([':id' => $breedId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('RassenDaten::uebernehmen(' . $breedId . '): ' . $e->getMessage());
        }
    }

    /**
     * Ein alter Text (mit "## Überschriften") → [abschnitt => text].
     * Erster Absatz = Kurzporträt, weitere Absätze ohne Überschrift =
     * "allgemein". Unbekannte Überschriften bleiben mit Überschrift unter
     * "weitere". Ein unveränderter TheDogAPI-Import (Beschreibung, Leerzeile,
     * Geschichte) wird zu Kurzporträt und Herkunft.
     *
     * @return array<string, string>
     */
    public static function textZerlegen(string $text, bool $theDogApiImport): array
    {
        $anzeige = RassentextGliederung::fuerAnzeige($text);
        $ergebnis = [];
        if ($anzeige['kurzportraet'] !== null) {
            $ergebnis['kurzportraet'] = $anzeige['kurzportraet'];
        }
        if ($anzeige['einleitung']) {
            if ($theDogApiImport && !$anzeige['abschnitte']) {
                $ergebnis['herkunft'] = implode("\n\n", $anzeige['einleitung']);
            } else {
                $ergebnis['allgemein'] = implode("\n\n", $anzeige['einleitung']);
            }
        }
        foreach ($anzeige['abschnitte'] as $a) {
            $abschnitt = self::TITEL_ZU_ABSCHNITT[mb_strtolower(trim($a['titel']))] ?? null;
            $inhalt = implode("\n\n", $a['absaetze']);
            if ($abschnitt === null) {
                $abschnitt = 'weitere';
                $inhalt = '## ' . $a['titel'] . "\n\n" . $inhalt;
            }
            if ($inhalt === '') {
                continue;
            }
            $ergebnis[$abschnitt] = isset($ergebnis[$abschnitt]) ? $ergebnis[$abschnitt] . "\n\n" . $inhalt : $inhalt;
        }
        return $ergebnis;
    }

    /**
     * Wert eines Merkmals anhand des deutschen Namens finden oder anlegen
     * (Name englisch leer, wird in der Merkmal-Verwaltung ergänzt).
     * @return string Schlüssel des Werts
     */
    private static function wertAnlegen(string $merkmal, string $nameDe, ?string $nameEn = null): string
    {
        $ids = self::merkmalIds();
        $norm = mb_strtolower(trim($nameDe));
        foreach ($ids[$merkmal]['namen'] ?? [] as $schluessel => $name) {
            if (mb_strtolower($name) === $norm || $schluessel === $norm) {
                return $schluessel;
            }
        }
        $schluessel = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtr($norm, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'])), '_');
        $schluessel = substr($schluessel !== '' ? $schluessel : md5($norm), 0, 60);
        if (isset($ids[$merkmal])) {
            Database::getConnection()->prepare(
                'INSERT IGNORE INTO merkmal_werte (merkmal_id, schluessel, name_de, name_en, reihenfolge) VALUES (:m, :s, :n, :en, 1000)'
            )->execute([':m' => $ids[$merkmal]['id'], ':s' => $schluessel, ':n' => mb_substr(trim($nameDe), 0, 100),
                ':en' => $nameEn !== null ? mb_substr(trim($nameEn), 0, 100) : null]);
            self::merkmalIds(true);
        }
        return $schluessel;
    }

    /** @return array<string, array{id: int, werte: array<string, int>, namen: array<string, string>}> */
    private static function merkmalIds(bool $neuLaden = false): array
    {
        static $cache = null;
        if ($cache !== null && !$neuLaden) {
            return $cache;
        }
        $cache = [];
        $rows = Database::getConnection()->query(
            'SELECT m.schluessel AS m, m.id AS mid, w.schluessel AS w, w.id AS wid, w.name_de
             FROM merkmale m LEFT JOIN merkmal_werte w ON w.merkmal_id = m.id'
        )->fetchAll();
        foreach ($rows as $r) {
            $cache[$r['m']] ??= ['id' => (int) $r['mid'], 'werte' => [], 'namen' => []];
            if ($r['w'] !== null) {
                $cache[$r['m']]['werte'][$r['w']] = (int) $r['wid'];
                $cache[$r['m']]['namen'][$r['w']] = (string) $r['name_de'];
            }
        }
        return $cache;
    }

    // ------------------------------------------------------------------
    // Lesen für die Anzeige
    // ------------------------------------------------------------------

    /**
     * Texte einer Rasse in der gewünschten Sprache, je Abschnitt mit
     * Rückfall auf die andere Sprache.
     *
     * @return array<string, array{text: string, sprache: string, quelle: string}>
     */
    public static function texte(int $breedId, string $sprache): array
    {
        $stmt = Database::getConnection()->prepare('SELECT sprache, abschnitt, text, quelle FROM rasse_texte WHERE breed_id = :id');
        $stmt->execute([':id' => $breedId]);
        $alle = [];
        foreach ($stmt->fetchAll() as $r) {
            $alle[$r['abschnitt']][$r['sprache']] = ['text' => (string) $r['text'], 'quelle' => (string) $r['quelle']];
        }
        $andere = $sprache === 'en' ? 'de' : 'en';
        $ergebnis = [];
        foreach (array_keys(self::ABSCHNITTE) as $abschnitt) {
            foreach ([$sprache, $andere] as $sp) {
                if (trim($alle[$abschnitt][$sp]['text'] ?? '') !== '') {
                    $ergebnis[$abschnitt] = $alle[$abschnitt][$sp] + ['sprache' => $sp];
                    break;
                }
            }
        }
        return $ergebnis;
    }

    /** Alle Texte zum Bearbeiten: [sprache][abschnitt] => text */
    public static function alleTexte(int $breedId): array
    {
        $stmt = Database::getConnection()->prepare('SELECT sprache, abschnitt, text FROM rasse_texte WHERE breed_id = :id');
        $stmt->execute([':id' => $breedId]);
        $ergebnis = ['de' => [], 'en' => []];
        foreach ($stmt->fetchAll() as $r) {
            $ergebnis[$r['sprache']][$r['abschnitt']] = (string) $r['text'];
        }
        return $ergebnis;
    }

    /**
     * Merkmale einer Rasse, fertig für die Anzeige, nach Gruppe.
     *
     * @return array<string, list<array{schluessel: string, typ: string, name: string, einheit: ?string,
     *     werte: list<string>, spannen: array<string, array{0: ?float, 1: ?float}>, land: ?string}>>
     */
    public static function merkmale(int $breedId, string $sprache): array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT m.schluessel, m.typ, m.gruppe, m.name_de, m.name_en, m.einheit, m.reihenfolge,
                    w.name_de AS wert_de, w.name_en AS wert_en, w.reihenfolge AS wert_reihenfolge,
                    rm.zahl_von, rm.zahl_bis, rm.text_wert, rm.geschlecht
             FROM rasse_merkmale rm
             JOIN merkmale m ON m.id = rm.merkmal_id
             LEFT JOIN merkmal_werte w ON w.id = rm.wert_id
             WHERE rm.breed_id = :id
             ORDER BY m.reihenfolge, w.reihenfolge, w.name_de'
        );
        $stmt->execute([':id' => $breedId]);
        $en = $sprache === 'en';
        $nachSchluessel = [];
        foreach ($stmt->fetchAll() as $r) {
            $s = $r['schluessel'];
            $nachSchluessel[$s] ??= [
                'schluessel' => $s,
                'typ' => $r['typ'],
                'gruppe' => $r['gruppe'],
                'name' => $en && $r['name_en'] ? $r['name_en'] : $r['name_de'],
                'einheit' => $r['einheit'],
                'werte' => [],
                'spannen' => [],
                'land' => null,
            ];
            if ($r['wert_de'] !== null) {
                $label = $en && $r['wert_en'] ? $r['wert_en'] : $r['wert_de'];
                if (!in_array($label, $nachSchluessel[$s]['werte'], true)) {
                    $nachSchluessel[$s]['werte'][] = $label;
                }
            }
            if ($r['zahl_von'] !== null || $r['zahl_bis'] !== null) {
                $nachSchluessel[$s]['spannen'][$r['geschlecht']] = [
                    $r['zahl_von'] !== null ? (float) $r['zahl_von'] : null,
                    $r['zahl_bis'] !== null ? (float) $r['zahl_bis'] : null,
                ];
            }
            if ($r['typ'] === 'land' && $r['text_wert']) {
                $nachSchluessel[$s]['land'] = self::landName((string) $r['text_wert'], $sprache);
            }
        }
        $gruppen = [];
        foreach ($nachSchluessel as $m) {
            $gruppen[$m['gruppe']][] = $m;
        }
        return $gruppen;
    }
}
