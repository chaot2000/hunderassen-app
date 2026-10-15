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
     * Liefert Hunde, gescoped auf den Nutzer und Tenant: Admins sehen alle
     * Hunde im aktuellen Tenant, normale Nutzer nur ihre eigenen (created_by).
     * Optional zusätzlich per Freitextsuche auf dem Namen gefiltert.
     *
     * Enthält bereits Rassename und eine kommaseparierte Liste der
     * Halternamen (per GROUP_CONCAT, da ein Hund jetzt mehrere Halter
     * haben kann) sowie hat_bild/bild_updated_at fürs Vorschaubild,
     * damit die Übersicht ohne N+1-Queries auskommt.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function search(string $term, int $userId, bool $isAdmin, int $tenantId): array
    {
        $pdo = Database::getConnection();

        $sql = "SELECT d.id, d.name, d.geburtsdatum, d.farbe, d.breed_id, d.zuechter_id,
                       d.created_at, d.updated_at, d.created_by,
                       d.bild_updated_at, (d.bild_blob IS NOT NULL) AS hat_bild,
                       b.name AS breed_name, b.name_en AS breed_name_en,
                       GROUP_CONCAT(h.name ORDER BY h.name SEPARATOR ', ') AS halter_namen
                FROM dogs d
                LEFT JOIN breeds b ON b.id = d.breed_id
                LEFT JOIN dog_halter dh ON dh.dog_id = d.id
                LEFT JOIN halter h ON h.id = dh.halter_id
                WHERE d.tenant_id = :tenant_id";
        $params = [':tenant_id' => $tenantId];

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
     * sehen darf (Ersteller oder Admin) und im gleichen Tenant — sonst null.
     * Inkl. Rassename, Halter-Liste (mehrere möglich, siehe getHalters())
     * und Züchtername, sowie der TOP_TAG_COUNT wichtigsten Eigenschaften
     * der verknüpften Rasse.
     *
     * Liefert bewusst NICHT den Bild-BLOB selbst mit, analog zu
     * Breed::findById() — dafür findImageByIdForUser().
     */
    public static function findByIdForUser(int $id, int $userId, bool $isAdmin, int $tenantId): ?array
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare(
            'SELECT d.id, d.name, d.geburtsdatum, d.farbe, d.breed_id, d.zuechter_id,
                    d.created_at, d.updated_at, d.created_by,
                    d.bild_updated_at, (d.bild_blob IS NOT NULL) AS hat_bild,
                    b.name AS breed_name, b.name_en AS breed_name_en, b.farben AS breed_farben,
                    z.zuchtname AS zuechter_name, z.telefon AS zuechter_telefon, z.email AS zuechter_email
             FROM dogs d
             LEFT JOIN breeds b ON b.id = d.breed_id
             LEFT JOIN zuechter z ON z.id = d.zuechter_id
             WHERE d.id = :id AND d.tenant_id = :tenant_id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
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
     * gescoped auf den Nutzer und Tenant (Ersteller oder Admin) — sonst null.
     * Getrennt von findByIdForUser(), damit das potenziell große BLOB
     * nicht bei jeder Listen-/Detailabfrage mitgeladen wird.
     *
     * @return array{blob: string, mime: string}|null
     */
    public static function findImageByIdForUser(int $dogId, int $userId, bool $isAdmin, int $tenantId): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT bild_blob, bild_mime, created_by FROM dogs WHERE id = :id AND tenant_id = :tenant_id AND bild_blob IS NOT NULL LIMIT 1');
        $stmt->execute([':id' => $dogId, ':tenant_id' => $tenantId]);
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
     * Nutzer und Tenant — für die Halter-Detailseite (halter_detail.php).
     * Nutzt die dog_halter-Pivot-Tabelle (ein Halter kann mehreren Hunden
     * zugeordnet sein, unverändert zur bisherigen Logik).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function findByHalterForUser(int $halterId, int $userId, bool $isAdmin, int $tenantId): array
    {
        $pdo = Database::getConnection();

        $sql = 'SELECT d.id, d.name, d.geburtsdatum, d.farbe,
                       d.bild_updated_at, (d.bild_blob IS NOT NULL) AS hat_bild
                FROM dogs d
                INNER JOIN dog_halter dh ON dh.dog_id = d.id
                WHERE dh.halter_id = :halter_id AND d.tenant_id = :tenant_id';
        $params = [':halter_id' => $halterId, ':tenant_id' => $tenantId];
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
     * Nutzer und Tenant — für die Züchter-Detailseite (zuechter_detail.php).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function findByZuechterForUser(int $zuechterId, int $userId, bool $isAdmin, int $tenantId): array
    {
        $pdo = Database::getConnection();

        $sql = 'SELECT d.id, d.name, d.geburtsdatum, d.farbe,
                       d.bild_updated_at, (d.bild_blob IS NOT NULL) AS hat_bild
                FROM dogs d
                WHERE d.zuechter_id = :zuechter_id AND d.tenant_id = :tenant_id';
        $params = [':zuechter_id' => $zuechterId, ':tenant_id' => $tenantId];
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
    public static function create(array $data, array $halterIds, int $createdBy, bool $isAdmin, int $tenantId, ?array $image = null): array
    {
        $validated = self::validate($data, $halterIds, $tenantId, $createdBy, $isAdmin, null);
        if (!$validated['success']) {
            return $validated;
        }

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'INSERT INTO dogs (tenant_id, name, geburtsdatum, farbe, breed_id, zuechter_id, bild_blob, bild_mime, bild_updated_at, created_by)
                 VALUES (:tenant_id, :name, :geburtsdatum, :farbe, :breed_id, :zuechter_id, :bild_blob, :bild_mime, :bild_updated_at, :created_by)'
            );
            $stmt->execute([
                ':tenant_id'      => $tenantId,
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

            return ['success' => true, 'message' => t('hunde.m_angelegt', ['name' => $validated['name']]), 'dog_id' => $dogId];
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Fehler beim Anlegen eines Hundes: ' . $e->getMessage());
            return ['success' => false, 'message' => t('allg.speicherfehler_daten')];
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param int[] $halterIds Liste der Halter-IDs (Familie) — ersetzt die bisherige Zuordnung komplett
     * @param array|null $image Neues Bild (überschreibt bestehendes), oder null = unverändert lassen
     * @param bool $removeImage true = vorhandenes Bild entfernen (nur wirksam, wenn $image === null)
     * @return array{success: bool, message: string}
     */
    public static function update(int $id, int $tenantId, int $userId, bool $isAdmin, array $data, array $halterIds, ?array $image = null, bool $removeImage = false): array
    {
        if (self::findByIdForUser($id, $userId, $isAdmin, $tenantId) === null) {
            return ['success' => false, 'message' => t('hunde.m_nicht_gefunden')];
        }

        $validated = self::validate($data, $halterIds, $tenantId, $userId, $isAdmin, $id);
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
                 WHERE id = :id AND tenant_id = :tenant_id'
            );
            $stmt->execute(array_merge([
                ':name'         => $validated['name'],
                ':geburtsdatum' => $validated['geburtsdatum'],
                ':farbe'        => $validated['farbe'],
                ':breed_id'     => $validated['breed_id'],
                ':zuechter_id'  => $validated['zuechter_id'],
                ':id'           => $id,
                ':tenant_id'    => $tenantId,
            ], $imageParams));

            self::syncHalters($pdo, $id, $validated['halter_ids']);

            $pdo->commit();

            return ['success' => true, 'message' => t('hunde.m_aktualisiert', ['name' => $validated['name']])];
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Fehler beim Aktualisieren eines Hundes: ' . $e->getMessage());
            return ['success' => false, 'message' => t('allg.speicherfehler_aenderungen')];
        }
    }

    public static function delete(int $id, int $tenantId): bool
    {
        // dog_halter-Zeilen werden per ON DELETE CASCADE automatisch mitgelöscht.
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM dogs WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        return $stmt->rowCount() > 0;
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
     * Halter und Züchter müssen zum Mandanten gehören und, außer bei
     * Admins, vom Nutzer selbst angelegt sein. Beim Bearbeiten
     * ($dogId gesetzt) bleiben bereits verknüpfte Halter/Züchter
     * erlaubt, auch wenn ein Admin sie zugeordnet hat.
     *
     * @param array<string, mixed> $data
     * @param int[] $halterIds
     * @return array{success: bool, message: string, name?: string, geburtsdatum?: ?string,
     *               farbe?: ?string, breed_id?: ?int, zuechter_id?: ?int, halter_ids?: int[]}
     */
    private static function validate(array $data, array $halterIds, int $tenantId, int $userId, bool $isAdmin, ?int $dogId): array
    {
        $name = clean_input((string) ($data['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'message' => t('hunde.m_name_leer')];
        }
        if (mb_strlen($name) > 120) {
            return ['success' => false, 'message' => t('allg.name_max120')];
        }

        $geburtsdatumRaw = clean_input((string) ($data['geburtsdatum'] ?? ''));
        $geburtsdatum = null;
        if ($geburtsdatumRaw !== '') {
            $parsed = DateTime::createFromFormat('Y-m-d', $geburtsdatumRaw);
            if ($parsed === false || $parsed->format('Y-m-d') !== $geburtsdatumRaw) {
                return ['success' => false, 'message' => t('hunde.m_geburt_format')];
            }
            if ($parsed > new DateTime('today')) {
                return ['success' => false, 'message' => t('hunde.m_geburt_zukunft')];
            }
            $geburtsdatum = $geburtsdatumRaw;
        }

        $farbe = clean_input((string) ($data['farbe'] ?? ''));
        $farbe = $farbe !== '' ? $farbe : null;
        if ($farbe !== null && mb_strlen($farbe) > 255) {
            return ['success' => false, 'message' => t('hunde.m_farbe_lang')];
        }

        $breedId = isset($data['breed_id']) && $data['breed_id'] !== '' ? (int) $data['breed_id'] : null;
        if ($breedId !== null) {
            $pdo = Database::getConnection();
            $check = $pdo->prepare('SELECT id FROM breeds WHERE id = :id LIMIT 1');
            $check->execute([':id' => $breedId]);
            if (!$check->fetch()) {
                return ['success' => false, 'message' => t('hunde.m_rasse')];
            }
        }

        // Die Formularauswahl allein schützt nicht: eine fremde ID lässt
        // sich von Hand ins Formular schreiben. Deshalb hier Mandant und
        // Besitzer prüfen.
        $cleanHalterIds = array_values(array_unique(array_filter(array_map('intval', $halterIds))));
        if (!empty($cleanHalterIds)) {
            $erlaubt = self::allowedIds('halter', $cleanHalterIds, $tenantId, $userId, $isAdmin);
            if ($dogId !== null) {
                $erlaubt = array_merge($erlaubt, array_map('intval', array_column(self::getHalters($dogId), 'id')));
            }
            if (array_diff($cleanHalterIds, $erlaubt) !== []) {
                return ['success' => false, 'message' => t('hunde.m_halter')];
            }
        }

        $zuechterId = isset($data['zuechter_id']) && $data['zuechter_id'] !== '' ? (int) $data['zuechter_id'] : null;
        if ($zuechterId !== null) {
            $erlaubt = self::allowedIds('zuechter', [$zuechterId], $tenantId, $userId, $isAdmin);
            if ($erlaubt === [] && !($dogId !== null && self::currentZuechterId($dogId) === $zuechterId)) {
                return ['success' => false, 'message' => t('hunde.m_zuechter')];
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

    /**
     * Welche der IDs aus halter/zuechter liegen im Mandanten und dürfen
     * vom Nutzer verknüpft werden (Admin: alle im Mandanten, sonst nur
     * selbst angelegte)?
     *
     * @param 'halter'|'zuechter' $table
     * @param int[] $ids
     * @return int[]
     */
    private static function allowedIds(string $table, array $ids, int $tenantId, int $userId, bool $isAdmin): array
    {
        if (!in_array($table, ['halter', 'zuechter'], true)) {
            throw new InvalidArgumentException('Unbekannte Tabelle: ' . $table);
        }

        $pdo = Database::getConnection();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT id FROM {$table} WHERE tenant_id = ? AND id IN ({$placeholders})";
        $params = array_merge([$tenantId], $ids);
        if (!$isAdmin) {
            $sql .= ' AND created_by = ?';
            $params[] = $userId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function currentZuechterId(int $dogId): ?int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT zuechter_id FROM dogs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $dogId]);
        $value = $stmt->fetchColumn();
        return $value !== false && $value !== null ? (int) $value : null;
    }
}
