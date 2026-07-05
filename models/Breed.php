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

class Breed
{
    private const VALID_GROESSEN = ['klein', 'mittel', 'gross', 'sehr_gross'];

    // -------------------------------------------------------------
    // Gewichte für getSimilar() — als benannte Konstanten ausgelagert,
    // damit sie ohne Logik-Eingriff experimentell angepasst werden
    // können. Aktueller Stand siehe Session-Notizen.
    // -------------------------------------------------------------
    private const SIMILARITY_WEIGHT_FCI_GRUPPE    = 40; // einmalig, falls gleiche FCI-Klasse
    private const SIMILARITY_WEIGHT_GROESSE       = 10; // pro gemeinsamer Größenklasse
    private const SIMILARITY_WEIGHT_TAG           = 8;  // pro gemeinsamem Tag/Eigenschaft
    private const SIMILARITY_WEIGHT_ACTIVITY       = 6;  // pro gemeinsamer Aktivität
    private const SIMILARITY_WEIGHT_URSPRUNGSLAND = 5;  // einmalig, falls gleiches Ursprungsland

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

            $insertBreed = $pdo->prepare(
                'INSERT INTO breeds (name, alternative_namen, namenszusatz, ursprungsland, farben, fci_klasse, fci_nr, beschreibung, bild_blob, bild_mime, bild_updated_at, created_at, updated_at, created_by)
                 VALUES (:name, :alternative_namen, :namenszusatz, :ursprungsland, :farben, :fci_klasse, :fci_nr, :beschreibung, :bild_blob, :bild_mime, :bild_updated_at, NOW(), NOW(), :created_by)'
            );
            $insertBreed->execute([
                ':name'              => $name,
                ':alternative_namen' => clean_input($breedData['alternative_namen'] ?? '') ?: null,
                ':namenszusatz'      => clean_input($breedData['namenszusatz'] ?? '') ?: null,
                ':ursprungsland'     => clean_input($breedData['ursprungsland'] ?? '') ?: null,
                ':farben'            => clean_input($breedData['farben'] ?? '') ?: null,
                ':fci_klasse'        => $fciKlasse,
                ':fci_nr'            => clean_input($breedData['fci_nr'] ?? '') ?: null,
                ':beschreibung'      => clean_input($breedData['beschreibung'] ?? '') ?: null,
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
     */
    public static function update(
        int $breedId,
        array $breedData,
        array $sizes,
        array $tagIds,
        array $activityIds,
        ?array $image = null,
        bool $removeImage = false
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

            $updateBreed = $pdo->prepare(
                'UPDATE breeds
                 SET name = :name, alternative_namen = :alternative_namen, namenszusatz = :namenszusatz,
                     ursprungsland = :ursprungsland, farben = :farben,
                     fci_klasse = :fci_klasse, fci_nr = :fci_nr, beschreibung = :beschreibung,
                     updated_at = NOW()' . $imageSql . '
                 WHERE id = :id'
            );
            $updateBreed->execute(array_merge([
                ':name'              => $name,
                ':alternative_namen' => clean_input($breedData['alternative_namen'] ?? '') ?: null,
                ':namenszusatz'      => clean_input($breedData['namenszusatz'] ?? '') ?: null,
                ':ursprungsland'     => clean_input($breedData['ursprungsland'] ?? '') ?: null,
                ':farben'            => clean_input($breedData['farben'] ?? '') ?: null,
                ':fci_klasse'        => $fciKlasse,
                ':fci_nr'            => clean_input($breedData['fci_nr'] ?? '') ?: null,
                ':beschreibung'      => clean_input($breedData['beschreibung'] ?? '') ?: null,
                ':id'                => $breedId,
            ], $imageParams));

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
     */
    public static function delete(int $breedId): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM breeds WHERE id = :id');
        return $stmt->execute([':id' => $breedId]);
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
            'SELECT id, name, alternative_namen, namenszusatz, ursprungsland, farben, fci_klasse, fci_nr, beschreibung,
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
     * Sucht/filtert Rassen für das Dashboard.
     *
     * @param string     $searchTerm   Freitextsuche auf Name UND alternative Bezeichnungen (optional)
     * @param int[]      $tagIds       Nur Rassen, die ALLE angegebenen Tags besitzen (UND-Verknüpfung)
     * @param int[]      $activityIds  Nur Rassen, die ALLE angegebenen Aktivitäten besitzen
     * @param string[]   $groessen     Nur Rassen mit mindestens einer dieser Größenklassen
     */
    public static function search(
        string $searchTerm = '',
        array $tagIds = [],
        array $activityIds = [],
        array $groessen = []
    ): array {
        $pdo = Database::getConnection();

        $where  = [];
        $params = [];

        $searchTerm = clean_input($searchTerm);
        if ($searchTerm !== '') {
            // Sucht sowohl im Hauptnamen als auch in den alternativen
            // Bezeichnungen (z.B. "Alsatian" findet auch "Deutscher
            // Schäferhund", falls dort als alternative_namen hinterlegt).
            $where[]             = '(b.name LIKE :search OR b.alternative_namen LIKE :search_alt)';
            $params[':search']   = '%' . $searchTerm . '%';
            $params[':search_alt'] = '%' . $searchTerm . '%';
        }

        $sql = 'SELECT DISTINCT b.id, b.name, b.namenszusatz, b.ursprungsland, b.fci_klasse, b.fci_nr, b.bild_updated_at, (b.bild_blob IS NOT NULL) AS hat_bild
                FROM breeds b';

        if (!empty($groessen)) {
            $validGroessen = array_values(array_intersect($groessen, self::VALID_GROESSEN));
            if (!empty($validGroessen)) {
                $placeholders = [];
                foreach ($validGroessen as $i => $g) {
                    $key = ":groesse{$i}";
                    $placeholders[] = $key;
                    $params[$key] = $g;
                }
                $sql .= ' INNER JOIN breed_sizes bs ON bs.breed_id = b.id AND bs.groesse IN (' . implode(',', $placeholders) . ')';
            }
        }

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY b.name ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $breeds = $stmt->fetchAll();

        // UND-Verknüpfung für Tags/Aktivitäten wird in PHP nachgefiltert,
        // da "Rasse muss ALLE angegebenen Tags haben" in reinem SQL mit
        // dynamischer Anzahl an Bedingungen unübersichtlich (HAVING COUNT)
        // und bei kleinen Datenmengen (Hunderassen-Lexikon) performant
        // genug ist.
        if (!empty($tagIds)) {
            $breeds = array_values(array_filter($breeds, function ($breed) use ($pdo, $tagIds) {
                return self::breedHasAllTags($pdo, (int) $breed['id'], $tagIds);
            }));
        }

        if (!empty($activityIds)) {
            $breeds = array_values(array_filter($breeds, function ($breed) use ($pdo, $activityIds) {
                return self::breedHasAllActivities($pdo, (int) $breed['id'], $activityIds);
            }));
        }

        return $breeds;
    }

    /**
     * Liefert für jede Filter-Option die Anzahl passender Rassen —
     * unter Berücksichtigung der AKTUELL aktiven Filter (d.h. wie viele
     * Treffer bei Hinzufügen dieser Option zu den bestehenden Filtern
     * entstehen würden). Außerdem welche Größen/Tags/Aktivitäten
     * überhaupt bei mindestens einer Rasse vorhanden sind.
     *
     * Rückgabe:
     * [
     *   'groessen'   => ['klein' => 12, 'mittel' => 34, ...],
     *   'tags'       => [1 => 5, 2 => 8, ...],    // [tag_id => count]
     *   'activities' => [1 => 3, 2 => 7, ...],
     * ]
     */
    public static function getFilterCounts(
        string $searchTerm = '',
        array $activeTagIds = [],
        array $activeActivityIds = [],
        array $activeGroessen = []
    ): array {
        $groessenCounts = [];
        $tagCounts      = [];
        $activityCounts = [];

        foreach (self::VALID_GROESSEN as $g) {
            $groessenCounts[$g] = count(self::search($searchTerm, $activeTagIds, $activeActivityIds, [$g]));
        }

        $pdo = Database::getConnection();
        $allTagIds = $pdo->query('SELECT id FROM tags ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($allTagIds as $tagId) {
            $tagId = (int) $tagId;
            $mergedTags = array_unique(array_merge($activeTagIds, [$tagId]));
            $tagCounts[$tagId] = count(self::search($searchTerm, $mergedTags, $activeActivityIds, $activeGroessen));
        }

        $allActIds = $pdo->query('SELECT id FROM activities ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($allActIds as $actId) {
            $actId = (int) $actId;
            $mergedActs = array_unique(array_merge($activeActivityIds, [$actId]));
            $activityCounts[$actId] = count(self::search($searchTerm, $activeTagIds, $mergedActs, $activeGroessen));
        }

        return [
            'groessen'   => $groessenCounts,
            'tags'       => $tagCounts,
            'activities' => $activityCounts,
        ];
    }

    /**
     * Liefert bis zu $limit ähnliche Rassen zu $breedId, score-basiert.
     * Die Berechnung erfolgt bewusst in PHP (analog zu search() /
     * getFilterCounts()), statt als eine große SQL-Formel — dadurch
     * bleibt die Gewichtung nachvollziehbar und leicht anpassbar
     * (siehe SIMILARITY_WEIGHT_*-Konstanten).
     *
     * Score setzt sich zusammen aus:
     *  - gleiche FCI-Gruppe (einmalig)
     *  - gemeinsame Größenklassen (pro Treffer)
     *  - gemeinsame Tags/Eigenschaften (pro Treffer)
     *  - gemeinsame Aktivitäten (pro Treffer)
     *  - gleiches Ursprungsland (einmalig)
     *
     * @return array<int, array{breed: array<string, mixed>, score: int, gruende: array<int, string>}>
     */
    public static function getSimilar(int $breedId, int $limit = 6): array
    {
        $current = self::findById($breedId);
        if ($current === null) {
            return [];
        }

        $pdo = Database::getConnection();

        $othersStmt = $pdo->prepare(
            'SELECT id, name, ursprungsland, fci_klasse, bild_updated_at, (bild_blob IS NOT NULL) AS hat_bild
             FROM breeds WHERE id != :id'
        );
        $othersStmt->execute([':id' => $breedId]);
        $others = $othersStmt->fetchAll();

        if (empty($others)) {
            return [];
        }

        // Größen/Tags/Aktivitäten aller anderen Rassen in Bulk laden
        // und je Rasse gruppieren, statt pro Kandidat einzeln
        // nachzufragen (N+1 vermeiden).
        $sizesByBreed = [];
        foreach ($pdo->query('SELECT breed_id, groesse FROM breed_sizes') as $row) {
            $sizesByBreed[(int) $row['breed_id']][] = $row['groesse'];
        }

        $tagsByBreed = [];
        foreach ($pdo->query('SELECT breed_id, tag_id FROM breed_tags') as $row) {
            $tagsByBreed[(int) $row['breed_id']][] = (int) $row['tag_id'];
        }

        $activitiesByBreed = [];
        foreach ($pdo->query('SELECT breed_id, activity_id FROM breed_activities') as $row) {
            $activitiesByBreed[(int) $row['breed_id']][] = (int) $row['activity_id'];
        }

        $currentGroessen   = array_column($current['sizes'], 'groesse');
        $currentTagIds     = array_column($current['tags'], 'id');
        $currentActivityIds = array_column($current['activities'], 'id');

        $results = [];

        foreach ($others as $other) {
            $otherId = (int) $other['id'];
            $score   = 0;
            $gruende = [];

            if ($current['fci_klasse'] !== null && $other['fci_klasse'] !== null
                && (int) $other['fci_klasse'] === (int) $current['fci_klasse']) {
                $score += self::SIMILARITY_WEIGHT_FCI_GRUPPE;
                $gruende[] = 'Gleiche FCI-Gruppe';
            }

            $sharedGroessen = count(array_intersect($currentGroessen, $sizesByBreed[$otherId] ?? []));
            if ($sharedGroessen > 0) {
                $score += $sharedGroessen * self::SIMILARITY_WEIGHT_GROESSE;
                $gruende[] = $sharedGroessen . ' gemeinsame Größenklasse' . ($sharedGroessen > 1 ? 'n' : '');
            }

            $sharedTags = count(array_intersect($currentTagIds, $tagsByBreed[$otherId] ?? []));
            if ($sharedTags > 0) {
                $score += $sharedTags * self::SIMILARITY_WEIGHT_TAG;
                $gruende[] = $sharedTags . ' gemeinsame' . ($sharedTags > 1 ? '' : 's') . ' Merkmal' . ($sharedTags > 1 ? 'e' : '');
            }

            $sharedActivities = count(array_intersect($currentActivityIds, $activitiesByBreed[$otherId] ?? []));
            if ($sharedActivities > 0) {
                $score += $sharedActivities * self::SIMILARITY_WEIGHT_ACTIVITY;
                $gruende[] = $sharedActivities . ' gemeinsame Aktivität' . ($sharedActivities > 1 ? 'en' : '');
            }

            if ($current['ursprungsland'] !== null && $other['ursprungsland'] !== null
                && $other['ursprungsland'] === $current['ursprungsland']) {
                $score += self::SIMILARITY_WEIGHT_URSPRUNGSLAND;
                $gruende[] = 'Gleiches Ursprungsland';
            }

            if ($score > 0) {
                $other['hat_bild'] = (bool) $other['hat_bild'];
                $results[] = [
                    'breed'   => $other,
                    'score'   => $score,
                    'gruende' => $gruende,
                ];
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

    private static function breedHasAllTags(PDO $pdo, int $breedId, array $tagIds): bool
    {
        $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT tag_id) FROM breed_tags WHERE breed_id = ? AND tag_id IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$breedId], array_map('intval', $tagIds)));
        return (int) $stmt->fetchColumn() === count(array_unique($tagIds));
    }

    private static function breedHasAllActivities(PDO $pdo, int $breedId, array $activityIds): bool
    {
        $placeholders = implode(',', array_fill(0, count($activityIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT activity_id) FROM breed_activities WHERE breed_id = ? AND activity_id IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$breedId], array_map('intval', $activityIds)));
        return (int) $stmt->fetchColumn() === count(array_unique($activityIds));
    }

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