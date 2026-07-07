<?php
/**
 * models/TestDurchfuehrung.php
 *
 * Testdurchführungen: die tatsächliche Ausführung eines Tests (siehe
 * models/Test.php) bei einem konkreten Hund. Analog zu models/Dog.php
 * aufgebaut (statische Methoden, PDO, clean_input() vor dem
 * Speichern), aber ohne dessen Bild-Handling.
 *
 * Ownership/Scoping: eine Testdurchführung gehört zu genau einem Hund;
 * die eigentliche Berechtigungsprüfung ("darf dieser Nutzer diesen
 * Hund überhaupt sehen?") läuft über Dog::findByIdForUser() — jede
 * aufrufende Seite MUSS das zuerst prüfen, bevor sie Methoden dieser
 * Klasse mit der zugehörigen dog_id aufruft (exakt wie
 * Dog::getHalters()/getTopBreedTags() das bereits für andere
 * hundegebundene Daten so handhaben, siehe models/Dog.php).
 *
 * Der Gesamtstatus (status) wird MANUELL vom Nutzer gesetzt, nicht
 * automatisch aus den Einzelergebnissen berechnet — die je Aufgabe
 * erfassten Ergebnisse sind rein informativ/historisch.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Test.php';

class TestDurchfuehrung
{
    private const VALID_STATUS = ['offen', 'bestanden', 'nicht_bestanden'];

    /**
     * Historie aller Testdurchführungen eines Hundes, neueste zuerst —
     * für die Sektion auf dog_detail.php.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function findAllForDog(int $dogId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT td.id, td.durchfuehrungsdatum, td.status, t.name AS test_name
             FROM test_durchfuehrungen td
             INNER JOIN tests t ON t.id = td.test_id
             WHERE td.dog_id = :dog_id
             ORDER BY td.durchfuehrungsdatum DESC, td.id DESC'
        );
        $stmt->execute([':dog_id' => $dogId]);
        return $stmt->fetchAll();
    }

    /**
     * Liefert NUR die dog_id einer Testdurchführung, ohne jede
     * Berechtigungsprüfung — Hilfsmethode für Seiten, die im
     * Bearbeiten-Modus zuerst herausfinden müssen, zu welchem Hund eine
     * gegebene Durchführungs-ID gehört, um DANACH per
     * Dog::findByIdForUser() zu prüfen, ob der Nutzer diesen Hund
     * überhaupt sehen darf (fetch-then-verify, siehe Docblock oben).
     */
    public static function findDogIdById(int $id): ?int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT dog_id FROM test_durchfuehrungen WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ? (int) $row['dog_id'] : null;
    }

    /**
     * Lädt eine einzelne Testdurchführung inkl. Test-Name, Gesamtstatus/
     * -notiz und dem per-Aufgabe-Breakdown (Titel, erfasstes Ergebnis
     * inkl. Kategorie, Einzelnotiz). Liefert null, wenn die Durchführung
     * nicht existiert ODER nicht zum angegebenen Hund gehört (fetch-
     * then-verify-Idiom analog Dog::findByIdForUser()) — der Aufrufer
     * muss VORHER bereits per Dog::findByIdForUser() geprüft haben, dass
     * der Nutzer diesen Hund sehen darf.
     */
    public static function findByIdForDog(int $id, int $dogId): ?array
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare(
            'SELECT td.id, td.dog_id, td.test_id, td.durchfuehrungsdatum, td.status, td.notizen,
                    td.created_at, td.updated_at, t.name AS test_name
             FROM test_durchfuehrungen td
             INNER JOIN tests t ON t.id = td.test_id
             WHERE td.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $durchfuehrung = $stmt->fetch();

        if (!$durchfuehrung || (int) $durchfuehrung['dog_id'] !== $dogId) {
            return null;
        }

        $ergebnisStmt = $pdo->prepare(
            'SELECT ta.id AS aufgabe_id, ta.titel AS aufgabe_titel,
                    te.id AS ergebnis_id, te.bezeichnung AS ergebnis_bezeichnung, te.kategorie,
                    tde.notizen
             FROM test_durchfuehrung_ergebnisse tde
             INNER JOIN test_aufgaben ta ON ta.id = tde.aufgabe_id
             INNER JOIN test_ergebnisse te ON te.id = tde.ergebnis_id
             WHERE tde.durchfuehrung_id = :durchfuehrung_id
             ORDER BY ta.id'
        );
        $ergebnisStmt->execute([':durchfuehrung_id' => $id]);
        $durchfuehrung['ergebnisse'] = $ergebnisStmt->fetchAll();

        return $durchfuehrung;
    }

    /**
     * @param array<string, mixed> $durchfuehrungData Erwartet Schlüssel: durchfuehrungsdatum, status, notizen
     * @param array $ergebnisse Liste von ['aufgabe_id' => int, 'ergebnis_id' => int, 'notizen' => ?string]
     * @return array{success: bool, message: string, durchfuehrung_id?: int}
     */
    public static function create(int $dogId, int $testId, array $durchfuehrungData, array $ergebnisse, int $createdByUserId): array
    {
        $validated = self::validate($testId, $durchfuehrungData, $ergebnisse);
        if (!$validated['success']) {
            return $validated;
        }

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'INSERT INTO test_durchfuehrungen (dog_id, test_id, durchfuehrungsdatum, status, notizen, created_by)
                 VALUES (:dog_id, :test_id, :durchfuehrungsdatum, :status, :notizen, :created_by)'
            );
            $stmt->execute([
                ':dog_id'              => $dogId,
                ':test_id'             => $testId,
                ':durchfuehrungsdatum' => $validated['durchfuehrungsdatum'],
                ':status'              => $validated['status'],
                ':notizen'             => $validated['notizen'],
                ':created_by'          => $createdByUserId,
            ]);

            $durchfuehrungId = (int) $pdo->lastInsertId();
            self::syncErgebnisse($pdo, $durchfuehrungId, $validated['ergebnisse']);

            $pdo->commit();

            return ['success' => true, 'message' => 'Testdurchführung wurde erfasst.', 'durchfuehrung_id' => $durchfuehrungId];
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Fehler beim Anlegen einer Testdurchführung: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Beim Speichern ist ein Fehler aufgetreten. Es wurden keine Daten gespeichert.'];
        }
    }

    /**
     * @param array<string, mixed> $durchfuehrungData
     * @param array $ergebnisse
     * @return array{success: bool, message: string}
     */
    public static function update(int $id, int $testId, array $durchfuehrungData, array $ergebnisse): array
    {
        $validated = self::validate($testId, $durchfuehrungData, $ergebnisse);
        if (!$validated['success']) {
            return $validated;
        }

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'UPDATE test_durchfuehrungen
                 SET durchfuehrungsdatum = :durchfuehrungsdatum, status = :status, notizen = :notizen, updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                ':durchfuehrungsdatum' => $validated['durchfuehrungsdatum'],
                ':status'              => $validated['status'],
                ':notizen'             => $validated['notizen'],
                ':id'                  => $id,
            ]);

            self::syncErgebnisse($pdo, $id, $validated['ergebnisse']);

            $pdo->commit();

            return ['success' => true, 'message' => 'Testdurchführung wurde aktualisiert.'];
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Fehler beim Aktualisieren einer Testdurchführung: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Beim Speichern ist ein Fehler aufgetreten. Es wurden keine Änderungen übernommen.'];
        }
    }

    /**
     * Löscht eine Testdurchführung inkl. ihrer per-Aufgabe-Ergebnisse
     * (ON DELETE CASCADE test_durchfuehrung_ergebnisse -> test_durchfuehrungen).
     */
    public static function delete(int $id): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM test_durchfuehrungen WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    // -------------------------------------------------------------
    // Private Hilfsmethoden
    // -------------------------------------------------------------

    /**
     * Validiert Kopfdaten der Durchführung sowie die Liste der
     * erfassten Ergebnisse: jede Aufgabe des gewählten Tests braucht
     * genau ein Ergebnis, und das gewählte Ergebnis muss tatsächlich zu
     * dieser Aufgabe gehören (Cross-Check gegen Test::findById()).
     *
     * @param array<string, mixed> $durchfuehrungData
     * @param array $ergebnisse
     * @return array{success: bool, message: string, durchfuehrungsdatum?: string, status?: string, notizen?: ?string, ergebnisse?: array}
     */
    private static function validate(int $testId, array $durchfuehrungData, array $ergebnisse): array
    {
        $test = Test::findById($testId);
        if ($test === null) {
            return ['success' => false, 'message' => 'Der ausgewählte Test wurde nicht gefunden.'];
        }
        if (empty($test['aufgaben'])) {
            return ['success' => false, 'message' => 'Der ausgewählte Test hat keine Aufgaben und kann nicht durchgeführt werden.'];
        }

        $datumRaw = clean_input((string) ($durchfuehrungData['durchfuehrungsdatum'] ?? ''));
        $datum = DateTime::createFromFormat('Y-m-d', $datumRaw);
        if ($datum === false || $datum->format('Y-m-d') !== $datumRaw) {
            return ['success' => false, 'message' => 'Das Durchführungsdatum ist ungültig (Format: JJJJ-MM-TT).'];
        }
        // Reiner Datums-String-Vergleich statt DateTime-Objekt-Vergleich:
        // DateTime::createFromFormat('Y-m-d', ...) übernimmt für die nicht
        // angegebene Uhrzeit die AKTUELLE Uhrzeit (nicht Mitternacht),
        // während `new DateTime('today')` auf 00:00:00 steht — beim
        // heutigen Datum wäre $datum dadurch immer "später" als "today"
        // und der Vergleich hätte fälschlich schon das heutige Datum als
        // Zukunft abgelehnt.
        if ($datumRaw > date('Y-m-d')) {
            return ['success' => false, 'message' => 'Das Durchführungsdatum darf nicht in der Zukunft liegen.'];
        }

        $status = (string) ($durchfuehrungData['status'] ?? '');
        if (!in_array($status, self::VALID_STATUS, true)) {
            return ['success' => false, 'message' => 'Ungültiger Gesamtstatus.'];
        }

        $notizen = clean_input((string) ($durchfuehrungData['notizen'] ?? ''));

        // Erlaubte Ergebnis-IDs je Aufgabe aus der Test-Struktur ableiten,
        // damit ein gepostetes ergebnis_id nicht zu einer fremden Aufgabe
        // gehören kann.
        $erlaubteErgebnisseByAufgabe = [];
        foreach ($test['aufgaben'] as $aufgabe) {
            $erlaubteErgebnisseByAufgabe[(int) $aufgabe['id']] = array_column($aufgabe['ergebnisse'], 'id');
        }

        $ergebnisseByAufgabe = [];
        foreach ($ergebnisse as $ergebnis) {
            $aufgabeId  = (int) ($ergebnis['aufgabe_id'] ?? 0);
            $ergebnisId = (int) ($ergebnis['ergebnis_id'] ?? 0);
            if ($aufgabeId > 0 && $ergebnisId > 0) {
                $ergebnisseByAufgabe[$aufgabeId] = [
                    'ergebnis_id' => $ergebnisId,
                    'notizen'     => clean_input((string) ($ergebnis['notizen'] ?? '')),
                ];
            }
        }

        $cleanedErgebnisse = [];
        foreach ($erlaubteErgebnisseByAufgabe as $aufgabeId => $erlaubteIds) {
            if (!isset($ergebnisseByAufgabe[$aufgabeId])) {
                return ['success' => false, 'message' => 'Für jede Aufgabe muss ein Ergebnis ausgewählt werden.'];
            }

            $gewaehlt = $ergebnisseByAufgabe[$aufgabeId];
            if (!in_array($gewaehlt['ergebnis_id'], array_map('intval', $erlaubteIds), true)) {
                return ['success' => false, 'message' => 'Das ausgewählte Ergebnis passt nicht zur zugehörigen Aufgabe.'];
            }

            $cleanedErgebnisse[] = [
                'aufgabe_id'  => $aufgabeId,
                'ergebnis_id' => $gewaehlt['ergebnis_id'],
                'notizen'     => $gewaehlt['notizen'] !== '' ? $gewaehlt['notizen'] : null,
            ];
        }

        return [
            'success'              => true,
            'message'              => '',
            'durchfuehrungsdatum'  => $datumRaw,
            'status'               => $status,
            'notizen'              => $notizen !== '' ? $notizen : null,
            'ergebnisse'           => $cleanedErgebnisse,
        ];
    }

    /**
     * Ersetzt die erfassten Ergebnisse einer Durchführung komplett
     * (alte Zeilen löschen, neue einfügen) — analog Breed::syncTags(),
     * hier mit Payload-Feldern (ergebnis_id, notizen) statt reinem Pivot.
     */
    private static function syncErgebnisse(PDO $pdo, int $durchfuehrungId, array $ergebnisse): void
    {
        $pdo->prepare('DELETE FROM test_durchfuehrung_ergebnisse WHERE durchfuehrung_id = :durchfuehrung_id')
            ->execute([':durchfuehrung_id' => $durchfuehrungId]);

        if (empty($ergebnisse)) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO test_durchfuehrung_ergebnisse (durchfuehrung_id, aufgabe_id, ergebnis_id, notizen)
             VALUES (:durchfuehrung_id, :aufgabe_id, :ergebnis_id, :notizen)'
        );
        foreach ($ergebnisse as $ergebnis) {
            $stmt->execute([
                ':durchfuehrung_id' => $durchfuehrungId,
                ':aufgabe_id'       => $ergebnis['aufgabe_id'],
                ':ergebnis_id'      => $ergebnis['ergebnis_id'],
                ':notizen'          => $ergebnis['notizen'],
            ]);
        }
    }
}
