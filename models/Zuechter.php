<?php
/**
 * models/Zuechter.php
 *
 * Züchterverwaltung. Strikt user-scoped: normale Nutzer sehen
 * ausschließlich ihre eigenen Einträge, Admins sehen alle (siehe
 * findAllForUser()/findByIdForUser()).
 *
 * Felder: Zuchtname (Pflicht), Ansprechpartner (Vor-/Nachname),
 * Adresse, Kontaktdaten (Telefon, E-Mail, Webseite), Notizen.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class Zuechter
{
    private const SELECT_FIELDS = 'id, zuchtname, ansprechpartner_vorname, ansprechpartner_nachname,
                                    adresse, telefon, email, webseite, notizen, created_by,
                                    bild_updated_at, (bild_blob IS NOT NULL) AS hat_bild';

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function findAllForUser(int $userId, bool $isAdmin): array
    {
        $pdo = Database::getConnection();

        if ($isAdmin) {
            $stmt = $pdo->query('SELECT ' . self::SELECT_FIELDS . ' FROM zuechter ORDER BY zuchtname');
            $zuechterListe = $stmt->fetchAll();
        } else {
            $stmt = $pdo->prepare('SELECT ' . self::SELECT_FIELDS . ' FROM zuechter WHERE created_by = :uid ORDER BY zuchtname');
            $stmt->execute([':uid' => $userId]);
            $zuechterListe = $stmt->fetchAll();
        }

        foreach ($zuechterListe as &$zuechter) {
            $zuechter['hat_bild'] = (bool) $zuechter['hat_bild'];
        }

        return $zuechterListe;
    }

    /**
     * Liefert einen Züchter nur zurück, wenn er dem Nutzer gehört ODER
     * der Nutzer Admin ist — sonst null (führt in den Seiten zu 404).
     */
    public static function findByIdForUser(int $id, int $userId, bool $isAdmin): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT ' . self::SELECT_FIELDS . ', created_at FROM zuechter WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $zuechter = $stmt->fetch();

        if ($zuechter === false) {
            return null;
        }
        if (!$isAdmin && (int) $zuechter['created_by'] !== $userId) {
            return null;
        }

        $zuechter['hat_bild'] = (bool) $zuechter['hat_bild'];

        return $zuechter;
    }

    /**
     * Liefert NUR die rohen Bilddaten (Blob + Mime-Type) eines
     * Züchters, gescoped auf den Nutzer (Ersteller oder Admin) — sonst
     * null. Analog zu Dog::findImageByIdForUser().
     *
     * @return array{blob: string, mime: string}|null
     */
    public static function findImageByIdForUser(int $zuechterId, int $userId, bool $isAdmin): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT bild_blob, bild_mime, created_by FROM zuechter WHERE id = :id AND bild_blob IS NOT NULL LIMIT 1');
        $stmt->execute([':id' => $zuechterId]);
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
     * Ungeprüfter Zugriff für interne Zwecke (Berechtigungsprüfung ist
     * dann bereits an anderer Stelle passiert).
     */
    public static function findById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT ' . self::SELECT_FIELDS . ' FROM zuechter WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $zuechter = $stmt->fetch();
        return $zuechter ?: null;
    }

    /**
     * Anzahl der Hunde pro Züchter — für die Übersichtsliste.
     * @return array<int, int>
     */
    public static function getDogCounts(int $userId, bool $isAdmin): array
    {
        $pdo = Database::getConnection();

        $sql = 'SELECT z.id, COUNT(d.id) AS hunde_count
                FROM zuechter z
                LEFT JOIN dogs d ON d.zuechter_id = z.id';
        $params = [];
        if (!$isAdmin) {
            $sql .= ' WHERE z.created_by = :uid';
            $params[':uid'] = $userId;
        }
        $sql .= ' GROUP BY z.id';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(int) $row['id']] = (int) $row['hunde_count'];
        }
        return $counts;
    }

    /**
     * @param array|null $image Optional ['blob' => string, 'mime' => string] —
     *        bereits verkleinerte Bilddaten, siehe BildVerarbeitung::verarbeiteUpload()
     * @return array{success: bool, message: string, zuechter_id?: int}
     */
    public static function create(array $data, int $createdBy, ?array $image = null): array
    {
        $validated = self::validate($data);
        if (!$validated['success']) {
            return $validated;
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO zuechter (zuchtname, ansprechpartner_vorname, ansprechpartner_nachname, adresse, telefon, email, webseite, notizen, bild_blob, bild_mime, bild_updated_at, created_by)
             VALUES (:zuchtname, :vorname, :nachname, :adresse, :telefon, :email, :webseite, :notizen, :bild_blob, :bild_mime, :bild_updated_at, :created_by)'
        );
        $stmt->execute([
            ':zuchtname'       => $validated['zuchtname'],
            ':vorname'         => $validated['ansprechpartner_vorname'],
            ':nachname'        => $validated['ansprechpartner_nachname'],
            ':adresse'         => $validated['adresse'],
            ':telefon'         => $validated['telefon'],
            ':email'           => $validated['email'],
            ':webseite'        => $validated['webseite'],
            ':notizen'         => $validated['notizen'],
            ':bild_blob'       => $image['blob'] ?? null,
            ':bild_mime'       => $image['mime'] ?? null,
            ':bild_updated_at' => $image !== null ? date('Y-m-d H:i:s') : null,
            ':created_by'      => $createdBy,
        ]);

        return ['success' => true, 'message' => 'Züchter "' . $validated['zuchtname'] . '" wurde angelegt.', 'zuechter_id' => (int) $pdo->lastInsertId()];
    }

    /**
     * @param array|null $image Neues Bild (überschreibt bestehendes), oder null = unverändert lassen
     * @param bool $removeImage true = vorhandenes Bild entfernen (nur wirksam, wenn $image === null)
     */
    public static function update(int $id, array $data, ?array $image = null, bool $removeImage = false): array
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
            'UPDATE zuechter
             SET zuchtname = :zuchtname, ansprechpartner_vorname = :vorname, ansprechpartner_nachname = :nachname,
                 adresse = :adresse, telefon = :telefon, email = :email, webseite = :webseite, notizen = :notizen' . $imageSql . '
             WHERE id = :id'
        );
        $stmt->execute(array_merge([
            ':zuchtname' => $validated['zuchtname'],
            ':vorname'   => $validated['ansprechpartner_vorname'],
            ':nachname'  => $validated['ansprechpartner_nachname'],
            ':adresse'   => $validated['adresse'],
            ':telefon'   => $validated['telefon'],
            ':email'     => $validated['email'],
            ':webseite'  => $validated['webseite'],
            ':notizen'   => $validated['notizen'],
            ':id'        => $id,
        ], $imageParams));

        return ['success' => true, 'message' => 'Züchter "' . $validated['zuchtname'] . '" wurde aktualisiert.'];
    }

    /**
     * Löscht einen Züchter. Hunde, die auf ihn verweisen, verlieren
     * lediglich die Zuordnung (dogs.zuechter_id -> NULL).
     */
    public static function delete(int $id): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM zuechter WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    private static function validate(array $data): array
    {
        $zuchtname = clean_input((string) ($data['zuchtname'] ?? ''));
        if ($zuchtname === '') {
            return ['success' => false, 'message' => 'Der Zuchtname darf nicht leer sein.'];
        }
        if (mb_strlen($zuchtname) > 150) {
            return ['success' => false, 'message' => 'Der Zuchtname darf maximal 150 Zeichen lang sein.'];
        }

        $vorname  = clean_input((string) ($data['ansprechpartner_vorname'] ?? ''));
        $vorname  = $vorname !== '' ? $vorname : null;
        $nachname = clean_input((string) ($data['ansprechpartner_nachname'] ?? ''));
        $nachname = $nachname !== '' ? $nachname : null;

        $adresse = clean_input((string) ($data['adresse'] ?? ''));
        $adresse = $adresse !== '' ? $adresse : null;

        $telefon = clean_input((string) ($data['telefon'] ?? ''));
        $telefon = $telefon !== '' ? $telefon : null;

        $email = clean_input((string) ($data['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Die E-Mail-Adresse ist ungültig.'];
        }
        $email = $email !== '' ? $email : null;

        $webseite = trim((string) ($data['webseite'] ?? ''));
        if ($webseite !== '') {
            // Nutzerfreundlich: "www.zucht.de" ohne Schema wird automatisch
            // zu "https://www.zucht.de" ergänzt, statt eine Fehlermeldung
            // zu zeigen — das ist die übliche Eingabe, die Leute tippen.
            if (!preg_match('~^https?://~i', $webseite)) {
                $webseite = 'https://' . $webseite;
            }
            $webseite = clean_input($webseite);
            if (!filter_var($webseite, FILTER_VALIDATE_URL)) {
                return ['success' => false, 'message' => 'Die Webseite ist keine gültige URL.'];
            }
            if (mb_strlen($webseite) > 255) {
                return ['success' => false, 'message' => 'Die Webseiten-URL darf maximal 255 Zeichen lang sein.'];
            }
        } else {
            $webseite = null;
        }

        $notizen = clean_input((string) ($data['notizen'] ?? ''));
        $notizen = $notizen !== '' ? $notizen : null;

        return [
            'success'                  => true,
            'message'                  => '',
            'zuchtname'                => $zuchtname,
            'ansprechpartner_vorname'  => $vorname,
            'ansprechpartner_nachname' => $nachname,
            'adresse'                  => $adresse,
            'telefon'                  => $telefon,
            'email'                    => $email,
            'webseite'                 => $webseite,
            'notizen'                  => $notizen,
        ];
    }
}
