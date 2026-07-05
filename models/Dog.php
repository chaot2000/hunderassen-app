<?php
/**
 * models/Dog.php
 *
 * Datenzugriff für die Hundeverwaltung. Analog zu models/Breed.php
 * aufgebaut (statische Methoden, PDO, clean_input() vor dem Speichern,
 * Bild-Handling analog zu Breed::create()/update()/findImageById()).
 *
 * Halter: n:m über die Pivot-Tabelle `dog_halter` (mehrere Halter/eine
 * "Familie" pro Hund möglich, seit migration_2026_07_04). Sync-Logik
 * analog zu Breed::syncTags(). Die alte 1:1-Spalte dogs.halter_id
 * existiert in bereits laufenden Installationen non-destruktiv als
 * Altlast weiter, wird hier aber nicht mehr verwendet.
 *
 * Züchter: weiterhin 1:1 FK auf `zuechter` (dogs.zuechter_id) — ein
 * Hund stammt von genau einem Züchter, das bleibt unverändert.
 *
 * Portal-Scoping: strikt user-scoped. search() und findByIdForUser()
 * verlangen bewusst $userId + $isAdmin als Pflichtparameter — Sichtbar-
 * keit ist damit an der Modell-Grenze erzwungen, nicht nur in den
 * aufrufenden Seiten.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class Dog
{
    /** Anzahl der Eigenschaften, die auf der Detailseite als "wichtigste" der verknüpften Rasse angezeigt werden. */
    public const TOP_TAG_COUNT = 5;

    /**
     * Liefert Hunde, gescoped auf den Nutzer: Admins sehen alle,
     * normale Nutzer nur ihre eigenen (created_by). Optional zusätzlich
     * per Freitextsuche auf dem Namen gefiltert.
     *
     * Enthält bereits Rassename und eine kommaseparierte Liste der
     * Halternamen (per GROUP_CONCAT, da ein Hund jetzt mehrere Halter
     * haben kann) sowie hat_bild/bild_updated_at fürs Vorschaubild,
     * damit die Übersicht ohne N+1-Queries auskommt.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function search(string $term, int $userId, bool $isAdmin): array
    {
        $pdo = Database::getConnection();

        $sql = "SELECT d.id, d.name, d.geburtsdatum, d.farbe, d.breed_id, d.zuechter_id,
                       d.created_at, d.updated_at, d.created_by,
                       d.bild_updated_at, (d.bild_blob IS NOT NULL) AS hat_bild,
                       b.name AS breed_name,
                       GROUP_CONCAT(h.name ORDER BY h.name SEPARATOR ', ') AS halter_namen
                FROM dogs d
                LEFT JOIN breeds b ON b.id = d.breed_id
                LEFT JOIN dog_halter dh ON dh.dog_id = d.id
                LEFT JOIN halter h ON h.id = dh.halter_id
                WHERE 1 = 1";
        $params = [];

        if (!$isAdmin) {
            $sql .= ' AND d.created_by = :created_by';
            $params[':created_by'] = $userId;
        }

        $term = clean_input($term);
        if ($term !== '') {
            $sql .= ' AND d.name LIKE :term';
            $params[':term'] = '%' . $term . '%';
        }

        $sql .= ' GROUP BY d.id ORDER BY d.name';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $dogs = $stmt->fetchAll();
        foreach ($dogs as &$dog) {
            $dog['hat_bild'] = (bool) $dog['hat_bild'];
        }
        unset($dog);

        return $dogs;
    }

    /**
     * Detailansicht eines einzelnen Hundes, NUR wenn der Nutzer ihn
     * sehen darf (Ersteller oder Admin) — sonst null (führt in den
     * Seiten zu 404). Inkl. Rassename, Halter-Liste (mehrere möglich,
     * siehe getHalters()) und Züchtername, sowie der TOP_TAG_COUNT
     * wichtigsten Eigenschaften der verknüpften Rasse.
     *
     * Liefert bewusst NICHT den Bild-BLOB selbst mit, analog zu
     * Breed::findById() — dafür findImageByIdForUser().
     */
    public static function findByIdForUser(int $id, int $userId, bool $isAdmin): ?array
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare(
            'SELECT d.id, d.name, d.geburtsdatum, d.farbe, d.breed_id, d.zuechter_id,
                    d.created_at, d.updated_at, d.created_by,
                    d.bild_updated_at, (d.bild_blob IS NOT NULL) AS hat_bild,
                    b.name AS breed_name, b.farben AS breed_farben,
                    z.zuchtname AS zuechter_name, z.telefon AS zuechter_telefon, z.email AS zuechter_email
             FROM dogs d
             LEFT JOIN breeds b ON b.id = d.breed_id
             LEFT JOIN zuechter z ON z.id = d.zuechter_id
             WHERE d.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $dog = $stmt->fetch();

        if ($dog === false) {
            return null;
        }
        if (!$isAdmin && (int) $dog['created_by'] !== $userId) {
            return null;
        }

        $dog['hat_bild'] = (bool) $dog['hat_bild'];
        $dog['halters']  = self::getHalters($id);

        $dog['top_tags'] = $dog['breed_id'] !== null
            ? self::getTopBreedTags((int) $dog['breed_id'], self::TOP_TAG_COUNT)
            : [];

        return $dog;
    }

    /**
     * Liefert alle Halter (Familie) eines Hundes.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getHalters(int $dogId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT h.id, h.name, h.telefon, h.email
             FROM halter h
             INNER JOIN dog_halter dh ON dh.halter_id = h.id
             WHERE dh.dog_id = :dog_id
             ORDER BY h.name'
        );
        $stmt->execute([':dog_id' => $dogId]);
        return $stmt->fetchAll();
    }

    /**
     * Liefert NUR die rohen Bilddaten (Blob + Mime-Type) eines Hundes,
     * gescoped auf den Nutzer (Ersteller oder Admin) — sonst null.
     * Getrennt von findByIdForUser(), damit das potenziell große BLOB
     * nicht bei jeder Listen-/Detailabfrage mitgeladen wird.
     *
     * @return array{blob: string, mime: string}|null
     */
    public static function findImageByIdForUser(int $dogId, int $userId, bool $isAdmin): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT bild_blob, bild_mime, created_by FROM dogs WHERE id = :id AND bild_blob IS NOT NULL LIMIT 1');
        $stmt->execute([':id' => $dogId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }
        if (!$isAdmin && (int) $row['created_by'] !== $userId) {
            return null;
        }

        return ['blob' => $row['bild_blob'], 'mime' => $row['bild_mime']];
    }

    /**
     * Liefert die ersten $limit Eigenschaften/Tags einer Rasse
     * (aktuell ohne explizite Wichtigkeits-Reihenfolge in der DB).
     * Rassen sind geteilte Katalogdaten, kein Scoping nötig.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getTopBreedTags(int $breedId, int $limit = self::TOP_TAG_COUNT): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT t.id, t.name, t.category
             FROM tags t
             INNER JOIN breed_tags bt ON bt.tag_id = t.id
             WHERE bt.breed_id = :breed_id
             ORDER BY t.category, t.name
             LIMIT :limit'
        );
        $stmt->bindValue(':breed_id', $breedId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Liefert alle Hunde eines bestimmten Halters, gescoped auf den
     * Nutzer — für die Halter-Detailseite (halter_detail.php). Nutzt
     * die dog_halter-Pivot-Tabelle (ein Halter kann mehreren Hunden
     * zugeordnet sein, unverändert zur bisherigen Logik).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function findByHalterForUser(int $halterId, int $userId, bool $isAdmin): array
    {
        $pdo = Database::getConnection();

        $sql = 'SELECT d.id, d.name, d.geburtsdatum, d.farbe,
                       d.bild_updated_at, (d.bild_blob IS NOT NULL) AS hat_bild
                FROM dogs d
                INNER JOIN dog_halter dh ON dh.dog_id = d.id
                WHERE dh.halter_id = :halter_id';
        $params = [':halter_id' => $halterId];
        if (!$isAdmin) {
            $sql .= ' AND d.created_by = :uid';
            $params[':uid'] = $userId;
        }
        $sql .= ' ORDER BY d.name';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $dogs = $stmt->fetchAll();
        foreach ($dogs as &$dog) {
            $dog['hat_bild'] = (bool) $dog['hat_bild'];
        }
        unset($dog);

        return $dogs;
    }

    /**
     * Liefert alle Hunde eines bestimmten Züchters, gescoped auf den
     * Nutzer — für die Züchter-Detailseite (zuechter_detail.php).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function findByZuechterForUser(int $zuechterId, int $userId, bool $isAdmin): array
    {
        $pdo = Database::getConnection();

        $sql = 'SELECT d.id, d.name, d.geburtsdatum, d.farbe,
                       d.bild_updated_at, (d.bild_blob IS NOT NULL) AS hat_bild
                FROM dogs d
                WHERE d.zuechter_id = :zuechter_id';
        $params = [':zuechter_id' => $zuechterId];
        if (!$isAdmin) {
            $sql .= ' AND d.created_by = :uid';
            $params[':uid'] = $userId;
        }
        $sql .= ' ORDER BY d.name';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $dogs = $stmt->fetchAll();
        foreach ($dogs as &$dog) {
            $dog['hat_bild'] = (bool) $dog['hat_bild'];
        }
        unset($dog);

        return $dogs;
    }

    /**
     * @param array<string, mixed> $data Erwartet Schlüssel: name, geburtsdatum,
     *        farbe, breed_id, zuechter_id (jeweils roh aus $_POST)
     * @param int[] $halterIds Liste der Halter-IDs (Familie) — leeres Array = kein Halter
     * @param array|null $image Optional ['blob' => string, 'mime' => string] —
     *        bereits verkleinerte Bilddaten, siehe BildVerarbeitung::verarbeiteUpload()
     * @return array{success: bool, message: string, dog_id?: int}
     */
    public static function create(array $data, array $halterIds, int $createdBy, ?array $image = null): array
    {
        $validated = self::validate($data, $halterIds);
        if (!$validated['success']) {
            return $validated;
        }

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'INSERT INTO dogs (name, geburtsdatum, farbe, breed_id, zuechter_id, bild_blob, bild_mime, bild_updated_at, created_by)
                 VALUES (:name, :geburtsdatum, :farbe, :breed_id, :zuechter_id, :bild_blob, :bild_mime, :bild_updated_at, :created_by)'
            );
            $stmt->execute([
                ':name'            => $validated['name'],
                ':geburtsdatum'    => $validated['geburtsdatum'],
                ':farbe'           => $validated['farbe'],
                ':breed_id'        => $validated['breed_id'],
                ':zuechter_id'     => $validated['zuechter_id'],
                ':bild_blob'       => $image['blob'] ?? null,
                ':bild_mime'       => $image['mime'] ?? null,
                ':bild_updated_at' => $image !== null ? date('Y-m-d H:i:s') : null,
                ':created_by'      => $createdBy,
            ]);

            $dogId = (int) $pdo->lastInsertId();
            self::syncHalters($pdo, $dogId, $validated['halter_ids']);

            $pdo->commit();

            return ['success' => true, 'message' => 'Hund "' . $validated['name'] . '" wurde angelegt.', 'dog_id' => $dogId];
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Fehler beim Anlegen eines Hundes: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Beim Speichern ist ein Fehler aufgetreten. Es wurden keine Daten gespeichert.'];
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param int[] $halterIds Liste der Halter-IDs (Familie) — ersetzt die bisherige Zuordnung komplett
     * @param array|null $image Neues Bild (überschreibt bestehendes), oder null = unverändert lassen
     * @param bool $removeImage true = vorhandenes Bild entfernen (nur wirksam, wenn $image === null)
     * @return array{success: bool, message: string}
     */
    public static function update(int $id, array $data, array $halterIds, ?array $image = null, bool $removeImage = false): array
    {
        $validated = self::validate($data, $halterIds);
        if (!$validated['success']) {
            return $validated;
        }

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

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'UPDATE dogs
                 SET name = :name, geburtsdatum = :geburtsdatum, farbe = :farbe,
                     breed_id = :breed_id, zuechter_id = :zuechter_id' . $imageSql . '
                 WHERE id = :id'
            );
            $stmt->execute(array_merge([
                ':name'         => $validated['name'],
                ':geburtsdatum' => $validated['geburtsdatum'],
                ':farbe'        => $validated['farbe'],
                ':breed_id'     => $validated['breed_id'],
                ':zuechter_id'  => $validated['zuechter_id'],
                ':id'           => $id,
            ], $imageParams));

            self::syncHalters($pdo, $id, $validated['halter_ids']);

            $pdo->commit();

            return ['success' => true, 'message' => 'Hund "' . $validated['name'] . '" wurde aktualisiert.'];
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Fehler beim Aktualisieren eines Hundes: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Beim Speichern ist ein Fehler aufgetreten. Es wurden keine Änderungen übernommen.'];
        }
    }

    public static function delete(int $id): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM dogs WHERE id = :id');
        return $stmt->execute([':id' => $id]);
        // dog_halter-Zeilen werden per ON DELETE CASCADE automatisch mitgelöscht.
    }

    /**
     * Ersetzt die Halter-Zuordnung eines Hundes komplett (alte
     * Zeilen löschen, neue einfügen) — einfacher und robuster als ein
     * Diff, analog zu Breed::syncTags().
     *
     * @param int[] $halterIds
     */
    private static function syncHalters(PDO $pdo, int $dogId, array $halterIds): void
    {
        $pdo->prepare('DELETE FROM dog_halter WHERE dog_id = :dog_id')->execute([':dog_id' => $dogId]);

        if (empty($halterIds)) {
            return;
        }

        $stmt = $pdo->prepare('INSERT INTO dog_halter (dog_id, halter_id) VALUES (:dog_id, :halter_id)');
        foreach (array_unique(array_map('intval', $halterIds)) as $halterId) {
            $stmt->execute([':dog_id' => $dogId, ':halter_id' => $halterId]);
        }
    }

    /**
     * Validiert + normalisiert rohe Formulardaten. Wird von create()
     * und update() gemeinsam genutzt, damit beide Pfade dieselben
     * Regeln durchsetzen.
     *
     * @param array<string, mixed> $data
     * @param int[] $halterIds
     * @return array{success: bool, message: string, name?: string, geburtsdatum?: ?string,
     *               farbe?: ?string, breed_id?: ?int, zuechter_id?: ?int, halter_ids?: int[]}
     */
    private static function validate(array $data, array $halterIds): array
    {
        $name = clean_input((string) ($data['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'message' => 'Der Name des Hundes darf nicht leer sein.'];
        }
        if (mb_strlen($name) > 120) {
            return ['success' => false, 'message' => 'Der Name darf maximal 120 Zeichen lang sein.'];
        }

        $geburtsdatumRaw = clean_input((string) ($data['geburtsdatum'] ?? ''));
        $geburtsdatum = null;
        if ($geburtsdatumRaw !== '') {
            $parsed = DateTime::createFromFormat('Y-m-d', $geburtsdatumRaw);
            if ($parsed === false || $parsed->format('Y-m-d') !== $geburtsdatumRaw) {
                return ['success' => false, 'message' => 'Das Geburtsdatum ist ungültig (Format: JJJJ-MM-TT).'];
            }
            if ($parsed > new DateTime('today')) {
                return ['success' => false, 'message' => 'Das Geburtsdatum darf nicht in der Zukunft liegen.'];
            }
            $geburtsdatum = $geburtsdatumRaw;
        }

        $farbe = clean_input((string) ($data['farbe'] ?? ''));
        $farbe = $farbe !== '' ? $farbe : null;
        if ($farbe !== null && mb_strlen($farbe) > 255) {
            return ['success' => false, 'message' => 'Die Farbe darf maximal 255 Zeichen lang sein.'];
        }

        $breedId = isset($data['breed_id']) && $data['breed_id'] !== '' ? (int) $data['breed_id'] : null;
        if ($breedId !== null) {
            $pdo = Database::getConnection();
            $check = $pdo->prepare('SELECT id FROM breeds WHERE id = :id LIMIT 1');
            $check->execute([':id' => $breedId]);
            if (!$check->fetch()) {
                return ['success' => false, 'message' => 'Die ausgewählte Rasse wurde nicht gefunden.'];
            }
        }

        // WICHTIG: halter_ids/zuechter_id werden hier nur auf reine
        // Existenz geprüft, NICHT auf Eigentümerschaft — die Auswahl
        // im Formular (dog_form.php) zeigt dem Nutzer ohnehin nur die
        // eigenen (bzw. bei Admins alle) Halter/Züchter an.
        $cleanHalterIds = array_values(array_unique(array_filter(array_map('intval', $halterIds))));
        if (!empty($cleanHalterIds)) {
            $pdo = Database::getConnection();
            $placeholders = implode(',', array_fill(0, count($cleanHalterIds), '?'));
            $check = $pdo->prepare("SELECT COUNT(*) FROM halter WHERE id IN ({$placeholders})");
            $check->execute($cleanHalterIds);
            if ((int) $check->fetchColumn() !== count($cleanHalterIds)) {
                return ['success' => false, 'message' => 'Mindestens einer der ausgewählten Halter wurde nicht gefunden.'];
            }
        }

        $zuechterId = isset($data['zuechter_id']) && $data['zuechter_id'] !== '' ? (int) $data['zuechter_id'] : null;
        if ($zuechterId !== null) {
            $pdo = Database::getConnection();
            $check = $pdo->prepare('SELECT id FROM zuechter WHERE id = :id LIMIT 1');
            $check->execute([':id' => $zuechterId]);
            if (!$check->fetch()) {
                return ['success' => false, 'message' => 'Der ausgewählte Züchter wurde nicht gefunden.'];
            }
        }

        return [
            'success'      => true,
            'message'      => '',
            'name'         => $name,
            'geburtsdatum' => $geburtsdatum,
            'farbe'        => $farbe,
            'breed_id'     => $breedId,
            'zuechter_id'  => $zuechterId,
            'halter_ids'   => $cleanHalterIds,
        ];
    }
}
