<?php
/**
 * models/Halter.php
 *
 * Eigenständige Halterverwaltung. Ein Halter ist KEIN App-Benutzer
 * (siehe dogs.halter_id) — bewusst getrennt, da nicht jeder Halter
 * einen Login braucht und nicht jeder Benutzer zwangsläufig Halter
 * ist.
 *
 * Strikt user-scoped (Portal-Prinzip): normale Nutzer sehen und
 * verwalten ausschließlich ihre eigenen Halter-Einträge, Admins sehen
 * und verwalten alle. Siehe findAllForUser()/findByIdForUser().
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class Halter
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function findAllForUser(int $userId, bool $isAdmin, int $tenantId): array
    {
        $pdo = Database::getConnection();

        if ($isAdmin) {
            $stmt = $pdo->prepare('SELECT id, name, telefon, email, adresse, notizen, created_by,
                       bild_updated_at, (bild_blob IS NOT NULL) AS hat_bild
                FROM halter WHERE tenant_id = :tenant_id ORDER BY name');
            $stmt->execute([':tenant_id' => $tenantId]);
            $halters = $stmt->fetchAll();
        } else {
            $stmt = $pdo->prepare('SELECT id, name, telefon, email, adresse, notizen, created_by,
                    bild_updated_at, (bild_blob IS NOT NULL) AS hat_bild
             FROM halter WHERE tenant_id = :tenant_id AND created_by = :uid ORDER BY name');
            $stmt->execute([':tenant_id' => $tenantId, ':uid' => $userId]);
            $halters = $stmt->fetchAll();
        }

        foreach ($halters as &$halter) {
            $halter['hat_bild'] = (bool) $halter['hat_bild'];
        }

        return $halters;
    }

    /**
     * Liefert einen Halter nur zurück, wenn er dem Nutzer gehört ODER
     * der Nutzer Admin ist und im gleichen Tenant — sonst null.
     */
    public static function findByIdForUser(int $id, int $userId, bool $isAdmin, int $tenantId): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id, name, telefon, email, adresse, notizen, created_at, created_by,
                       bild_updated_at, (bild_blob IS NOT NULL) AS hat_bild
                FROM halter WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $halter = $stmt->fetch();

        if ($halter === false) {
            return null;
        }
        if (!$isAdmin && (int) $halter['created_by'] !== $userId) {
            return null;
        }

        $halter['hat_bild'] = (bool) $halter['hat_bild'];

        return $halter;
    }

    /**
     * Liefert NUR die rohen Bilddaten (Blob + Mime-Type) eines Halters,
     * gescoped auf den Nutzer und Tenant (Ersteller oder Admin) — sonst null.
     * Analog zu Dog::findImageByIdForUser().
     *
     * @return array{blob: string, mime: string}|null
     */
    public static function findImageByIdForUser(int $halterId, int $userId, bool $isAdmin, int $tenantId): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT bild_blob, bild_mime, created_by FROM halter WHERE id = :id AND tenant_id = :tenant_id AND bild_blob IS NOT NULL LIMIT 1');
        $stmt->execute([':id' => $halterId, ':tenant_id' => $tenantId]);
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
     * Anzahl der Hunde pro Halter — für die Übersichtsliste.
     * @return array<int, int>
     */
    public static function getDogCounts(int $userId, bool $isAdmin, int $tenantId): array
    {
        $pdo = Database::getConnection();

        $sql = 'SELECT h.id, COUNT(dh.dog_id) AS hunde_count
                FROM halter h
                LEFT JOIN dog_halter dh ON dh.halter_id = h.id
                WHERE h.tenant_id = :tenant_id';
        $params = [':tenant_id' => $tenantId];
        if (!$isAdmin) {
            $sql .= ' AND h.created_by = :uid';
            $params[':uid'] = $userId;
        }
        $sql .= ' GROUP BY h.id';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(int) $row['id']] = (int) $row['hunde_count'];
        }
        return $counts;
    }

    /**
     * @param array<string, mixed> $data Rohe Formulardaten: name, telefon, email, adresse, notizen
     * @param array|null $image Optional ['blob' => string, 'mime' => string] —
     *        bereits verkleinerte Bilddaten, siehe BildVerarbeitung::verarbeiteUpload()
     * @return array{success: bool, message: string, halter_id?: int}
     */
    public static function create(array $data, int $createdBy, int $tenantId, ?array $image = null): array
    {
        $validated = self::validate($data);
        if (!$validated['success']) {
            return $validated;
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO halter (tenant_id, name, telefon, email, adresse, notizen, bild_blob, bild_mime, bild_updated_at, created_by)
             VALUES (:tenant_id, :name, :telefon, :email, :adresse, :notizen, :bild_blob, :bild_mime, :bild_updated_at, :created_by)'
        );
        $stmt->execute([
            ':tenant_id'      => $tenantId,
            ':name'            => $validated['name'],
            ':telefon'         => $validated['telefon'],
            ':email'           => $validated['email'],
            ':adresse'         => $validated['adresse'],
            ':notizen'         => $validated['notizen'],
            ':bild_blob'       => $image['blob'] ?? null,
            ':bild_mime'       => $image['mime'] ?? null,
            ':bild_updated_at' => $image !== null ? date('Y-m-d H:i:s') : null,
            ':created_by'      => $createdBy,
        ]);

        return ['success' => true, 'message' => 'Halter "' . $validated['name'] . '" wurde angelegt.', 'halter_id' => (int) $pdo->lastInsertId()];
    }

    /**
     * @param array|null $image Neues Bild (überschreibt bestehendes), oder null = unverändert lassen
     * @param bool $removeImage true = vorhandenes Bild entfernen (nur wirksam, wenn $image === null)
     * @return array{success: bool, message: string}
     */
    public static function update(int $id, int $tenantId, array $data, ?array $image = null, bool $removeImage = false): array
    {
        $validated = self::validate($data);
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
        $stmt = $pdo->prepare(
            'UPDATE halter SET name = :name, telefon = :telefon, email = :email, adresse = :adresse, notizen = :notizen' . $imageSql . '
             WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute(array_merge([
            ':name'    => $validated['name'],
            ':telefon' => $validated['telefon'],
            ':email'   => $validated['email'],
            ':adresse' => $validated['adresse'],
            ':notizen'   => $validated['notizen'],
            ':id'        => $id,
            ':tenant_id' => $tenantId,
        ], $imageParams));

        return ['success' => true, 'message' => 'Halter "' . $validated['name'] . '" wurde aktualisiert.'];
    }

    /**
     * Löscht einen Halter. Hunde, die auf ihn verweisen, verlieren
     * lediglich die Zuordnung (dog_halter-Pivot-Zeile wird per
     * ON DELETE CASCADE mitgelöscht) — die Hunde selbst bleiben
     * erhalten, ggf. mit den übrigen Mit-Haltern der Familie.
     */
    public static function delete(int $id, int $tenantId): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM halter WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{success: bool, message: string, name?: string, telefon?: ?string,
     *               email?: ?string, adresse?: ?string, notizen?: ?string}
     */
    private static function validate(array $data): array
    {
        $name = clean_input((string) ($data['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'message' => 'Der Name des Halters darf nicht leer sein.'];
        }
        if (mb_strlen($name) > 150) {
            return ['success' => false, 'message' => 'Der Name darf maximal 150 Zeichen lang sein.'];
        }

        $telefon = clean_input((string) ($data['telefon'] ?? ''));
        $telefon = $telefon !== '' ? $telefon : null;

        $email = clean_input((string) ($data['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Die E-Mail-Adresse ist ungültig.'];
        }
        $email = $email !== '' ? $email : null;

        $adresse = clean_input((string) ($data['adresse'] ?? ''));
        $adresse = $adresse !== '' ? $adresse : null;

        $notizen = clean_input((string) ($data['notizen'] ?? ''));
        $notizen = $notizen !== '' ? $notizen : null;

        return [
            'success' => true,
            'message' => '',
            'name'     => $name,
            'telefon'  => $telefon,
            'email'    => $email,
            'adresse'  => $adresse,
            'notizen'  => $notizen,
        ];
    }
}
