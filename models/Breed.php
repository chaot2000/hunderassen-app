<?php
/**
 * models/Breed.php
 *
 * Verwaltung von Hunderassen inkl. ihrer Größenklassen (breed_sizes),
 * Tag-Zuordnungen (breed_tags) und Aktivitäten-Zuordnungen
 * (breed_activities). Diese drei Beziehungen werden hier gebündelt,
 * weil eine Rasse aus fachlicher Sicht immer als Gesamtpaket
 * angelegt/bearbeitet wird (siehe admin_add_breed.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ContentRevision.php';
require_once __DIR__ . '/RassenFilter.php';
require_once __DIR__ . '/RassenDatenSchreiben.php';

class Breed
{
    private const VALID_GROESSEN = ['klein', 'mittel', 'gross', 'sehr_gross'];

    /** FCI-Gruppen nach der FCI-Nomenklatur, für Anzeige und Filter. */
    public const FCI_GRUPPEN = [
        1  => 'Hüte- und Treibhunde',
        2  => 'Pinscher und Schnauzer, Molosser, Schweizer Sennenhunde',
        3  => 'Terrier',
        4  => 'Dachshunde',
        5  => 'Spitze und Hunde vom Urtyp',
        6  => 'Laufhunde, Schweißhunde und verwandte Rassen',
        7  => 'Vorstehhunde',
        8  => 'Apportierhunde, Stöberhunde, Wasserhunde',
        9  => 'Gesellschafts- und Begleithunde',
        10 => 'Windhunde',
    ];

    /**
     * Textfelder für Charakter und Übersetzung, deutsch und englisch.
     * Werden wie die Strukturfelder in create() und update() geschrieben.
     */
    public const INHALT_FELDER = [
        'name_en', 'temperament', 'temperament_en', 'lebenserwartung',
        'zuchtzweck', 'zuchtzweck_en', 'beschreibung_en',
    ];

    /**
     * Struktur- und Inhaltsfelder aus dem Formular, bereinigt. Ungültige
     * Auswahlwerte werden zu null bzw. 'keine'.
     *
     * @return array<string, ?string>
     */
    private static function strukturFelder(array $breedData): array
    {
        $ap = (string) ($breedData['arbeitspruefung'] ?? '');
        $gl = (string) ($breedData['gliederung'] ?? 'keine');
        $felder = [
            'fci_sektion'      => clean_input($breedData['fci_sektion'] ?? '') ?: null,
            'fci_sektion_name' => clean_input($breedData['fci_sektion_name'] ?? '') ?: null,
            'arbeitspruefung'  => in_array($ap, ['mit', 'ohne', 'fakultativ'], true) ? $ap : null,
            'gliederung'       => in_array($gl, ['keine', 'automatisch', 'geprueft'], true) ? $gl : 'keine',
        ];
        foreach (self::INHALT_FELDER as $feld) {
            // Browser schicken Zeilenumbrüche als \r\n. Ohne Angleichen
            // gälte ein unveränderter Text als geändert (neue Version).
            $wert = str_replace("\r\n", "\n", (string) ($breedData[$feld] ?? ''));
            $felder[$feld] = clean_input($wert) ?: null;
        }
        return $felder;
    }

    /** "fci_sektion = :fci_sektion, ..." bzw. ":fci_sektion, ..." für alle Felder aus strukturFelder(). */
    private static function strukturSql(bool $alsZuweisung): string
    {
        $namen = array_merge(['fci_sektion', 'fci_sektion_name', 'arbeitspruefung', 'gliederung'], self::INHALT_FELDER);
        return implode(', ', array_map(fn($f) => $alsZuweisung ? "{$f} = :{$f}" : ":{$f}", $namen));
    }

    /** @return array<string, ?string> Parameter mit Doppelpunkt für PDO */
    private static function strukturParameter(array $struktur): array
    {
        $params = [];
        foreach ($struktur as $feld => $wert) {
            $params[':' . $feld] = $wert;
        }
        return $params;
    }

    /** Name der FCI-Gruppe in der aktuellen Sprache, null bei unbekannter Nummer. */
    public static function fciGruppenName(int $nr): ?string
    {
        return isset(self::FCI_GRUPPEN[$nr]) ? t('fci.gruppe.' . $nr) : null;
    }

    /**
     * Teilt "Originalname, deutscher Name" (so kommen viele Namen aus dem
     * VDH-Import) für die Anzeige auf. Ändert keine Daten.
     *
     * @return array{haupt: string, zweit: ?string}
     */
    public static function namensteile(string $name): array
    {
        $teile = explode(', ', $name, 2);
        return ['haupt' => trim($teile[0]), 'zweit' => isset($teile[1]) && trim($teile[1]) !== '' ? trim($teile[1]) : null];
    }

    // -------------------------------------------------------------
    // Gewichte für getSimilar() — als benannte Konstanten ausgelagert,
    // damit sie ohne Logik-Eingriff experimentell angepasst werden
    // können. Aktueller Stand siehe Session-Notizen.
    // -------------------------------------------------------------
    private const SIMILARITY_WEIGHT_FCI_GRUPPE    = 40; // einmalig, falls gleiche FCI-Klasse
    private const SIMILARITY_WEIGHT_GROESSE       = 10; // pro gemeinsamer Größenklasse
    private const SIMILARITY_WEIGHT_TAG           = 8;  // pro gemeinsamem übrigen Merkmalswert (Wesen, Eignung …)
    private const SIMILARITY_WEIGHT_ACTIVITY       = 6;  // pro gemeinsamer Aktivität
    private const SIMILARITY_WEIGHT_URSPRUNGSLAND = 5;  // einmalig, falls gleiches Ursprungsland

    // Merkmale, die über Ähnlichkeit wenig sagen (fast alle Rassen "ohne Arbeitsprüfung")
    private const AEHNLICH_OHNE = ['farben', 'arbeitspruefung', 'verbreitung'];

    /**
     * Legt eine neue Rasse inklusive Größenklassen, Tags und Aktivitäten
     * in einer Transaktion an. Schlägt ein Teilschritt fehl, wird alles
     * zurückgerollt — es entstehen keine halbfertigen Datensätze.
     *
     * @param array $breedData    ['name', 'alternative_namen', 'namenszusatz', 'ursprungsland',
     *                            'farben', 'fci_klasse', 'fci_nr', 'beschreibung']
     * @param array $sizes        Liste von ['groesse', 'ruede_min', 'ruede_max', 'haendin_min', 'haendin_max',
     *                            'gewicht_ruede_min', 'gewicht_ruede_max', 'gewicht_haendin_min', 'gewicht_haendin_max']
     * @param array $tagIds       Liste von Tag-IDs (int)
     * @param array $activityIds  Liste von Activity-IDs (int)
     * @param array|null $image   Optional ['blob' => string, 'mime' => string] — bereits
     *                            verkleinerte Bilddaten, siehe BildVerarbeitung::resize()
     *
     * @return array{success: bool, message: string, breed_id?: int}
     */
    public static function create(
        array $breedData,
        array $sizes,
        array $tagIds,
        array $activityIds,
        int $createdByUserId,
        ?array $image = null
    ): array {
        $name = clean_input($breedData['name'] ?? '');
        if ($name === '') {
            return ['success' => false, 'message' => 'Der Name der Rasse darf nicht leer sein.'];
        }

        $fciKlasse = self::normalizeFciKlasse($breedData['fci_klasse'] ?? null);
        if ($fciKlasse === false) {
            return ['success' => false, 'message' => 'Die FCI-Klasse muss zwischen 1 und 10 liegen oder leer sein.'];
        }

        $sizeValidation = self::validateSizes($sizes);
        if (!$sizeValidation['success']) {
            return $sizeValidation;
        }

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            // beschreibung_original = Text beim Anlegen, wird danach nie
            // mehr überschrieben (siehe ContentRevision).
            $strukturSpalten = str_replace(':', '', self::strukturSql(false));
            $insertBreed = $pdo->prepare(
                'INSERT INTO breeds (name, alternative_namen, namenszusatz, ursprungsland, farben, fci_klasse, fci_nr, beschreibung, beschreibung_original, ' . $strukturSpalten . ', bild_blob, bild_mime, bild_updated_at, created_at, updated_at, created_by)
                 VALUES (:name, :alternative_namen, :namenszusatz, :ursprungsland, :farben, :fci_klasse, :fci_nr, :beschreibung, :beschreibung_original, ' . self::strukturSql(false) . ', :bild_blob, :bild_mime, :bild_updated_at, NOW(), NOW(), :created_by)'
            );
            $beschreibung = clean_input(str_replace("\r\n", "\n", (string) ($breedData['beschreibung'] ?? ''))) ?: null;
            $struktur = self::strukturFelder($breedData);
            $insertBreed->execute(self::strukturParameter($struktur) + [
                ':name'              => $name,
                ':alternative_namen' => clean_input($breedData['alternative_namen'] ?? '') ?: null,
                ':namenszusatz'      => clean_input($breedData['namenszusatz'] ?? '') ?: null,
                ':ursprungsland'     => clean_input($breedData['ursprungsland'] ?? '') ?: null,
                ':farben'            => clean_input($breedData['farben'] ?? '') ?: null,
                ':fci_klasse'        => $fciKlasse,
                ':fci_nr'            => clean_input($breedData['fci_nr'] ?? '') ?: null,
                ':beschreibung'      => $beschreibung,
                ':beschreibung_original' => $beschreibung,
                ':bild_blob'         => $image['blob'] ?? null,
                ':bild_mime'         => $image['mime'] ?? null,
                ':bild_updated_at'   => $image !== null ? date('Y-m-d H:i:s') : null,
                ':created_by'        => $createdByUserId,
            ]);

            $breedId = (int) $pdo->lastInsertId();

            self::insertSizes($pdo, $breedId, $sizeValidation['sizes']);
            self::syncTags($pdo, $breedId, $tagIds);
            self::syncActivities($pdo, $breedId, $activityIds);

            $pdo->commit();

            return ['success' => true, 'message' => "Rasse „{$name}“ wurde erfolgreich angelegt.", 'breed_id' => $breedId];
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Fehler beim Anlegen einer Rasse: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Beim Speichern ist ein Fehler aufgetreten. Es wurden keine Daten gespeichert.'];
        }
    }

    /**
     * Aktualisiert eine bestehende Rasse inkl. Größenklassen, Tags und
     * Aktivitäten. Größenklassen/Tags/Aktivitäten werden komplett
     * ersetzt (alte Zuordnungen gelöscht, neue eingefügt) — einfacher
     * und robuster als ein Diff der Einzelposten.
     *
     * Bild-Verhalten: $image === null bedeutet "kein neues Bild
     * hochgeladen, bestehendes Bild unverändert lassen". Um ein
     * vorhandenes Bild zu entfernen, $removeImage = true setzen.
     *
     * Geänderte Textfelder werden in derselben Transaktion als Version
     * gespeichert (ContentRevision), $geaendertVon ist der Benutzer.
     */
    public static function update(
        int $breedId,
        array $breedData,
        array $sizes,
        array $tagIds,
        array $activityIds,
        ?array $image = null,
        bool $removeImage = false,
        ?int $geaendertVon = null
    ): array {
        $name = clean_input($breedData['name'] ?? '');
        if ($name === '') {
            return ['success' => false, 'message' => 'Der Name der Rasse darf nicht leer sein.'];
        }

        $fciKlasse = self::normalizeFciKlasse($breedData['fci_klasse'] ?? null);
        if ($fciKlasse === false) {
            return ['success' => false, 'message' => 'Die FCI-Klasse muss zwischen 1 und 10 liegen oder leer sein.'];
        }

        $sizeValidation = self::validateSizes($sizes);
        if (!$sizeValidation['success']) {
            return $sizeValidation;
        }

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            // Bild-Update nur als eigene, bedingte SQL-Klausel: ein
            // neues Bild überschreibt, "entfernen" setzt auf NULL,
            // ansonsten bleiben bild_blob/bild_mime unangetastet.
            $imageSql    = '';
            $imageParams = [];
            if ($image !== null) {
                $imageSql = ', bild_blob = :bild_blob, bild_mime = :bild_mime, bild_updated_at = :bild_updated_at';
                $imageParams = [
                    ':bild_blob'       => $image['blob'],
                    ':bild_mime'       => $image['mime'],
                    ':bild_updated_at' => date('Y-m-d H:i:s'),
                ];
            } elseif ($removeImage) {
                $imageSql = ', bild_blob = NULL, bild_mime = NULL, bild_updated_at = NULL';
            }

            $altStmt = $pdo->prepare(
                'SELECT name, alternative_namen, namenszusatz, ursprungsland, farben, fci_klasse, fci_nr,
                        beschreibung, ' . str_replace(':', '', self::strukturSql(false)) . '
                 FROM breeds WHERE id = :id FOR UPDATE'
            );
            $altStmt->execute([':id' => $breedId]);
            $alt = $altStmt->fetch() ?: [];

            $neu = self::strukturFelder($breedData) + [
                'name'              => $name,
                'alternative_namen' => clean_input($breedData['alternative_namen'] ?? '') ?: null,
                'namenszusatz'      => clean_input($breedData['namenszusatz'] ?? '') ?: null,
                'ursprungsland'     => clean_input($breedData['ursprungsland'] ?? '') ?: null,
                'farben'            => clean_input($breedData['farben'] ?? '') ?: null,
                'fci_klasse'        => $fciKlasse,
                'fci_nr'            => clean_input($breedData['fci_nr'] ?? '') ?: null,
                'beschreibung'      => clean_input(str_replace("\r\n", "\n", (string) ($breedData['beschreibung'] ?? ''))) ?: null,
            ];

            $updateBreed = $pdo->prepare(
                'UPDATE breeds
                 SET name = :name, alternative_namen = :alternative_namen, namenszusatz = :namenszusatz,
                     ursprungsland = :ursprungsland, farben = :farben,
                     fci_klasse = :fci_klasse, fci_nr = :fci_nr, beschreibung = :beschreibung,
                     ' . self::strukturSql(true) . ',
                     updated_at = NOW()' . $imageSql . '
                 WHERE id = :id'
            );
            $updateBreed->execute(array_merge(self::strukturParameter(self::strukturFelder($breedData)), [
                ':name'              => $neu['name'],
                ':alternative_namen' => $neu['alternative_namen'],
                ':namenszusatz'      => $neu['namenszusatz'],
                ':ursprungsland'     => $neu['ursprungsland'],
                ':farben'            => $neu['farben'],
                ':fci_klasse'        => $neu['fci_klasse'],
                ':fci_nr'            => $neu['fci_nr'],
                ':beschreibung'      => $neu['beschreibung'],
                ':id'                => $breedId,
            ], $imageParams));

            ContentRevision::protokolliere($pdo, 'breeds', $breedId, $alt, $neu, $geaendertVon, 'bearbeitung');

            $pdo->prepare('DELETE FROM breed_sizes WHERE breed_id = :id')->execute([':id' => $breedId]);
            self::insertSizes($pdo, $breedId, $sizeValidation['sizes']);

            self::syncTags($pdo, $breedId, $tagIds);
            self::syncActivities($pdo, $breedId, $activityIds);

            $pdo->commit();

            return ['success' => true, 'message' => "Rasse „{$name}“ wurde erfolgreich aktualisiert."];
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Fehler beim Aktualisieren einer Rasse: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Beim Speichern ist ein Fehler aufgetreten. Es wurden keine Änderungen übernommen.'];
        }
    }

    /**
     * Löscht eine Rasse. Größenklassen, Tag- und Aktivitäten-Zuordnungen
     * werden durch ON DELETE CASCADE automatisch mitgelöscht.
     *
     * Alle Textfelder werden vorher als Version (Anlass "loeschung")
     * gesichert, damit der Text nach dem Löschen nachlesbar bleibt.
     */
    public static function delete(int $breedId, ?int $geaendertVon = null): bool
    {
        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $felder = '`' . implode('`, `', array_keys(ContentRevision::FELDER['breeds'])) . '`';
            $altStmt = $pdo->prepare("SELECT {$felder} FROM breeds WHERE id = :id FOR UPDATE");
            $altStmt->execute([':id' => $breedId]);
            $alt = $altStmt->fetch();

            if ($alt) {
                ContentRevision::protokolliere($pdo, 'breeds', $breedId, $alt, [], $geaendertVon, 'loeschung');
                // Texte und Merkmale gehen per ON DELETE CASCADE mit, vorher sichern
                require_once __DIR__ . '/RassenDatenSchreiben.php';
                RassenDatenSchreiben::loeschungProtokollieren($pdo, $breedId, $geaendertVon);
            }

            $stmt = $pdo->prepare('DELETE FROM breeds WHERE id = :id');
            $stmt->execute([':id' => $breedId]);

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Fehler beim Löschen einer Rasse: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Liefert eine einzelne Rasse mit allen Größenklassen, Tags und
     * Aktivitäten — für die Detailansicht/Bearbeitungsmaske.
     *
     * Liefert bewusst NICHT den Bild-BLOB selbst mit (das würde jede
     * Listen-/Detailabfrage unnötig aufblähen) — nur ob ein Bild
     * existiert (hat_bild) und den Cache-Busting-Zeitstempel. Das
     * eigentliche Bild wird separat über bildausgabe.php per
     * findImageById() ausgeliefert.
     */
    public static function findById(int $breedId): ?array
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare(
            'SELECT id, name, name_en, alternative_namen, namenszusatz, ursprungsland, farben, fci_klasse, fci_nr,
                    fci_sektion, fci_sektion_name, arbeitspruefung, beschreibung, beschreibung_en,
                    temperament, temperament_en, lebenserwartung, zuchtzweck, zuchtzweck_en, thedogapi_id,
                    quelle_url, quelle_stand, gliederung,
                    (bild_blob IS NOT NULL) AS hat_bild, bild_updated_at,
                    created_at, updated_at, created_by
             FROM breeds WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $breedId]);
        $breed = $stmt->fetch();

        if (!$breed) {
            return null;
        }

        $breed['hat_bild'] = (bool) $breed['hat_bild'];

        $sizeStmt = $pdo->prepare(
            'SELECT groesse, schulterhoehe_ruede_min_cm, schulterhoehe_ruede_max_cm,
                    schulterhoehe_haendin_min_cm, schulterhoehe_haendin_max_cm,
                    gewicht_ruede_min_kg, gewicht_ruede_max_kg,
                    gewicht_haendin_min_kg, gewicht_haendin_max_kg
             FROM breed_sizes WHERE breed_id = :id
             ORDER BY FIELD(groesse, "klein", "mittel", "gross", "sehr_gross")'
        );
        $sizeStmt->execute([':id' => $breedId]);
        $breed['sizes'] = $sizeStmt->fetchAll();

        $tagStmt = $pdo->prepare(
            'SELECT t.id, t.name, t.category
             FROM tags t
             INNER JOIN breed_tags bt ON bt.tag_id = t.id
             WHERE bt.breed_id = :id
             ORDER BY t.category, t.name'
        );
        $tagStmt->execute([':id' => $breedId]);
        $breed['tags'] = $tagStmt->fetchAll();

        $activityStmt = $pdo->prepare(
            'SELECT a.id, a.name
             FROM activities a
             INNER JOIN breed_activities ba ON ba.activity_id = a.id
             WHERE ba.breed_id = :id
             ORDER BY a.name'
        );
        $activityStmt->execute([':id' => $breedId]);
        $breed['activities'] = $activityStmt->fetchAll();

        return $breed;
    }

    /**
     * Liefert NUR die rohen Bilddaten (Blob + Mime-Type) einer Rasse,
     * für die Auslieferung über bildausgabe.php. Getrennt von
     * findById(), damit das potenziell große BLOB nicht bei jeder
     * Detail-/Listenabfrage mitgeladen wird.
     *
     * @return array{blob: string, mime: string}|null
     */
    public static function findImageById(int $breedId): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT bild_blob, bild_mime FROM breeds WHERE id = :id AND bild_blob IS NOT NULL LIMIT 1');
        $stmt->execute([':id' => $breedId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return ['blob' => $row['bild_blob'], 'mime' => $row['bild_mime']];
    }

    /**
     * Textsuche im Katalog: Name, alternative Bezeichnungen und englischer
     * Name. Leerer Suchbegriff = alle Rassen. Die Filter nach Merkmalen
     * kommen danach (models/RassenFilter.php, rassen.php).
     */
    public static function search(string $searchTerm = ''): array
    {
        $pdo = Database::getConnection();
        $sql = 'SELECT b.id, b.name, b.name_en, b.namenszusatz, b.ursprungsland, b.fci_klasse, b.fci_nr, b.bild_updated_at, (b.bild_blob IS NOT NULL) AS hat_bild
                FROM breeds b';
        $params = [];
        $searchTerm = clean_input($searchTerm);
        if ($searchTerm !== '') {
            // "Alsatian" findet auch "Deutscher Schäferhund", wenn es dort als alternativer Name steht
            $sql .= ' WHERE (b.name LIKE :search OR b.alternative_namen LIKE :search_alt OR b.name_en LIKE :search_en)';
            $params = [':search' => '%' . $searchTerm . '%', ':search_alt' => '%' . $searchTerm . '%', ':search_en' => '%' . $searchTerm . '%'];
        }
        $stmt = $pdo->prepare($sql . ' ORDER BY b.name ASC');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Liefert bis zu $limit ähnliche Rassen zu $breedId, nach Punkten.
     * Grundlage sind die Merkmale (RassenFilter::daten()):
     *  - gleiche FCI-Gruppe (einmalig)
     *  - gemeinsame Größenklassen, Aktivitäten (je Treffer)
     *  - übrige gemeinsame Werte wie Wesen oder Eignung (je Treffer)
     *  - gleiches Ursprungsland (einmalig)
     * Farben, Arbeitsprüfung und Verbreitung zählen nicht (AEHNLICH_OHNE).
     *
     * @return array<int, array{breed: array<string, mixed>, score: int, gruende: array<int, string>}>
     */
    public static function getSimilar(int $breedId, int $limit = 6): array
    {
        $pdo = Database::getConnection();
        $alle = $pdo->query(
            'SELECT id, name, name_en, fci_klasse, bild_updated_at, (bild_blob IS NOT NULL) AS hat_bild FROM breeds'
        )->fetchAll();
        $alle = array_column($alle, null, 'id');
        if (!isset($alle[$breedId])) {
            return [];
        }
        $current = $alle[$breedId];
        $daten = RassenFilter::daten();
        $definitionen = RassenDatenSchreiben::nachSchluessel();
        $eigene = $daten[$breedId] ?? [];

        $results = [];
        foreach ($alle as $otherId => $other) {
            if ((int) $otherId === $breedId) {
                continue;
            }
            $andere = $daten[(int) $otherId] ?? [];
            $score = 0;
            $gruende = [];

            if ($current['fci_klasse'] !== null && $other['fci_klasse'] !== null
                && (int) $other['fci_klasse'] === (int) $current['fci_klasse']) {
                $score += self::SIMILARITY_WEIGHT_FCI_GRUPPE;
                $gruende[] = t('aehnlich.fci');
            }

            $geteilt = ['groessenklasse' => 0, 'aktivitaeten' => 0, 'sonst' => 0];
            foreach ($eigene as $schluessel => $werte) {
                $typ = $definitionen[$schluessel]['typ'] ?? null;
                if (!in_array($typ, ['mehrfach', 'auswahl', 'stufe'], true) || in_array($schluessel, self::AEHNLICH_OHNE, true) || !isset($andere[$schluessel])) {
                    continue;
                }
                $n = count(array_intersect_key($werte['werte'], $andere[$schluessel]['werte']));
                $geteilt[isset($geteilt[$schluessel]) ? $schluessel : 'sonst'] += $n;
            }
            if ($geteilt['groessenklasse'] > 0) {
                $score += $geteilt['groessenklasse'] * self::SIMILARITY_WEIGHT_GROESSE;
                $gruende[] = t($geteilt['groessenklasse'] > 1 ? 'aehnlich.groessen_n' : 'aehnlich.groessen_1', ['anzahl' => $geteilt['groessenklasse']]);
            }
            if ($geteilt['sonst'] > 0) {
                $score += $geteilt['sonst'] * self::SIMILARITY_WEIGHT_TAG;
                $gruende[] = t($geteilt['sonst'] > 1 ? 'aehnlich.merkmale_n' : 'aehnlich.merkmale_1', ['anzahl' => $geteilt['sonst']]);
            }
            if ($geteilt['aktivitaeten'] > 0) {
                $score += $geteilt['aktivitaeten'] * self::SIMILARITY_WEIGHT_ACTIVITY;
                $gruende[] = t($geteilt['aktivitaeten'] > 1 ? 'aehnlich.aktivitaeten_n' : 'aehnlich.aktivitaeten_1', ['anzahl' => $geteilt['aktivitaeten']]);
            }

            $land = array_key_first($eigene['ursprungsland']['werte'] ?? []);
            if ($land !== null && isset($andere['ursprungsland']['werte'][$land])) {
                $score += self::SIMILARITY_WEIGHT_URSPRUNGSLAND;
                $gruende[] = t('aehnlich.land');
            }

            if ($score > 0) {
                $other['hat_bild'] = (bool) $other['hat_bild'];
                $results[] = ['breed' => $other, 'score' => $score, 'gruende' => $gruende];
            }
        }

        usort($results, function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return $a['breed']['name'] <=> $b['breed']['name'];
            }
            return $b['score'] <=> $a['score'];
        });

        return array_slice($results, 0, $limit);
    }

    // -------------------------------------------------------------
    // Private Hilfsmethoden
    // -------------------------------------------------------------

    /**
     * Validiert eine Liste von Größenklassen-Einträgen.
     * Gibt im Erfolgsfall die bereinigte Liste zurück.
     */
    private static function validateSizes(array $sizes): array
    {
        $cleaned = [];
        $seenGroessen = [];

        foreach ($sizes as $size) {
            $groesse = $size['groesse'] ?? '';
            if (!in_array($groesse, self::VALID_GROESSEN, true)) {
                return ['success' => false, 'message' => "Ungültige Größenklasse: {$groesse}"];
            }

            if (isset($seenGroessen[$groesse])) {
                return ['success' => false, 'message' => "Die Größenklasse „{$groesse}“ wurde mehrfach angegeben."];
            }
            $seenGroessen[$groesse] = true;

            $ruedeMin   = self::normalizeCm($size['ruede_min'] ?? null);
            $ruedeMax   = self::normalizeCm($size['ruede_max'] ?? null);
            $haendinMin = self::normalizeCm($size['haendin_min'] ?? null);
            $haendinMax = self::normalizeCm($size['haendin_max'] ?? null);

            if ($ruedeMin !== null && $ruedeMax !== null && $ruedeMin > $ruedeMax) {
                return ['success' => false, 'message' => "Bei „{$groesse}“: Schulterhöhe Rüde — Minimum darf nicht größer als Maximum sein."];
            }
            if ($haendinMin !== null && $haendinMax !== null && $haendinMin > $haendinMax) {
                return ['success' => false, 'message' => "Bei „{$groesse}“: Schulterhöhe Hündin — Minimum darf nicht größer als Maximum sein."];
            }

            $gewichtRuedeMin   = self::normalizeKg($size['gewicht_ruede_min'] ?? null);
            $gewichtRuedeMax   = self::normalizeKg($size['gewicht_ruede_max'] ?? null);
            $gewichtHaendinMin = self::normalizeKg($size['gewicht_haendin_min'] ?? null);
            $gewichtHaendinMax = self::normalizeKg($size['gewicht_haendin_max'] ?? null);

            if ($gewichtRuedeMin !== null && $gewichtRuedeMax !== null && $gewichtRuedeMin > $gewichtRuedeMax) {
                return ['success' => false, 'message' => "Bei „{$groesse}“: Gewicht Rüde — Minimum darf nicht größer als Maximum sein."];
            }
            if ($gewichtHaendinMin !== null && $gewichtHaendinMax !== null && $gewichtHaendinMin > $gewichtHaendinMax) {
                return ['success' => false, 'message' => "Bei „{$groesse}“: Gewicht Hündin — Minimum darf nicht größer als Maximum sein."];
            }

            $cleaned[] = [
                'groesse'             => $groesse,
                'ruede_min'           => $ruedeMin,
                'ruede_max'           => $ruedeMax,
                'haendin_min'         => $haendinMin,
                'haendin_max'         => $haendinMax,
                'gewicht_ruede_min'   => $gewichtRuedeMin,
                'gewicht_ruede_max'   => $gewichtRuedeMax,
                'gewicht_haendin_min' => $gewichtHaendinMin,
                'gewicht_haendin_max' => $gewichtHaendinMax,
            ];
        }

        return ['success' => true, 'sizes' => $cleaned];
    }

    private static function normalizeCm($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || (int) $value < 0 || (int) $value > 200) {
            return null; // unrealistische Werte werden stillschweigend als "keine Angabe" behandelt
        }
        return (int) $value;
    }

    /**
     * Normalisiert ein Gewichtsfeld (kg). Erlaubt Komma ODER Punkt als
     * Dezimaltrennzeichen, da Nutzer auf einer deutschsprachigen Seite
     * typischerweise Komma eingeben. Werte außerhalb 0-120 kg werden
     * als "keine Angabe" behandelt (deckt auch den schwersten Hund
     * der Welt komfortabel ab, ohne Tippfehler durchzulassen).
     */
    private static function normalizeKg($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = str_replace(',', '.', (string) $value);
        if (!is_numeric($normalized)) {
            return null;
        }
        $floatVal = (float) $normalized;
        if ($floatVal < 0 || $floatVal > 120) {
            return null;
        }
        return round($floatVal, 1);
    }

    /**
     * @return int|false|null  int = gültige Klasse 1-10, null = keine Angabe, false = ungültig
     */
    private static function normalizeFciKlasse($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return false;
        }
        $intVal = (int) $value;
        if ($intVal < 1 || $intVal > 10) {
            return false;
        }
        return $intVal;
    }

    private static function insertSizes(PDO $pdo, int $breedId, array $sizes): void
    {
        if (empty($sizes)) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO breed_sizes
                (breed_id, groesse, schulterhoehe_ruede_min_cm, schulterhoehe_ruede_max_cm,
                 schulterhoehe_haendin_min_cm, schulterhoehe_haendin_max_cm,
                 gewicht_ruede_min_kg, gewicht_ruede_max_kg,
                 gewicht_haendin_min_kg, gewicht_haendin_max_kg)
             VALUES (:breed_id, :groesse, :ruede_min, :ruede_max, :haendin_min, :haendin_max,
                     :gewicht_ruede_min, :gewicht_ruede_max, :gewicht_haendin_min, :gewicht_haendin_max)'
        );

        foreach ($sizes as $size) {
            $stmt->execute([
                ':breed_id'            => $breedId,
                ':groesse'             => $size['groesse'],
                ':ruede_min'           => $size['ruede_min'],
                ':ruede_max'           => $size['ruede_max'],
                ':haendin_min'         => $size['haendin_min'],
                ':haendin_max'         => $size['haendin_max'],
                ':gewicht_ruede_min'   => $size['gewicht_ruede_min'],
                ':gewicht_ruede_max'   => $size['gewicht_ruede_max'],
                ':gewicht_haendin_min' => $size['gewicht_haendin_min'],
                ':gewicht_haendin_max' => $size['gewicht_haendin_max'],
            ]);
        }
    }

    private static function syncTags(PDO $pdo, int $breedId, array $tagIds): void
    {
        $pdo->prepare('DELETE FROM breed_tags WHERE breed_id = :id')->execute([':id' => $breedId]);

        if (empty($tagIds)) {
            return;
        }

        $stmt = $pdo->prepare('INSERT INTO breed_tags (breed_id, tag_id) VALUES (:breed_id, :tag_id)');
        foreach (array_unique(array_map('intval', $tagIds)) as $tagId) {
            $stmt->execute([':breed_id' => $breedId, ':tag_id' => $tagId]);
        }
    }

    private static function syncActivities(PDO $pdo, int $breedId, array $activityIds): void
    {
        $pdo->prepare('DELETE FROM breed_activities WHERE breed_id = :id')->execute([':id' => $breedId]);

        if (empty($activityIds)) {
            return;
        }

        $stmt = $pdo->prepare('INSERT INTO breed_activities (breed_id, activity_id) VALUES (:breed_id, :activity_id)');
        foreach (array_unique(array_map('intval', $activityIds)) as $activityId) {
            $stmt->execute([':breed_id' => $breedId, ':activity_id' => $activityId]);
        }
    }
}