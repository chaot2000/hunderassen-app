<?php
/**
 * models/Test.php
 *
 * Test-Katalog (z.B. "Aggressionstest", "PTBS-Assistenzhund-Test") —
 * geteilte Katalogdaten wie models/Breed.php, admin-gepflegt. Ein Test
 * bündelt mehrere Aufgaben, jede Aufgabe mehrere mögliche Ergebnisse
 * (Kategorie: bestanden/neutral/nicht_bestanden).
 *
 * Phase 1: reines Katalog-CRUD, komplett unabhängig von Hunden. Die
 * eigentliche Durchführung (Testdurchführung pro Hund) kommt erst in
 * Phase 2 hinzu (models/TestDurchfuehrung.php) und wird dann auch
 * hasDurchfuehrungen()/getDurchfuehrungCounts() zu dieser Klasse
 * ergänzen, um Löschen/Bearbeiten der Struktur bei bereits
 * durchgeführten Tests einzuschränken. In Phase 1 ist die
 * Aufgaben-/Ergebnis-Struktur daher immer uneingeschränkt editierbar.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class Test
{
    private const VALID_KATEGORIEN  = ['bestanden', 'neutral', 'nicht_bestanden'];
    private const VALID_ZIELGRUPPEN = ['welpe', 'erwachsen', 'beide'];

    /**
     * @param string|null $zielgruppe Optionaler Filter: 'welpe'/'erwachsen' liefert
     *        zusätzlich immer auch Tests mit Zielgruppe 'beide' (passt auf beide
     *        Altersgruppen), null liefert alle Tests ungefiltert.
     * @return array<int, array<string, mixed>>
     */
    public static function findAll(?string $zielgruppe = null): array
    {
        $pdo = Database::getConnection();

        if ($zielgruppe !== null && in_array($zielgruppe, ['welpe', 'erwachsen'], true)) {
            $stmt = $pdo->prepare(
                "SELECT id, name, beschreibung, zielgruppe, created_at FROM tests
                 WHERE zielgruppe = :zielgruppe OR zielgruppe = 'beide'
                 ORDER BY name"
            );
            $stmt->execute([':zielgruppe' => $zielgruppe]);
            return $stmt->fetchAll();
        }

        $stmt = $pdo->query('SELECT id, name, beschreibung, zielgruppe, created_at FROM tests ORDER BY name');
        return $stmt->fetchAll();
    }

    /**
     * Lädt einen Test inkl. seiner Aufgaben, je mit ihren möglichen
     * Ergebnissen — ein Level tiefer verschachtelt als
     * Breed::findById() (dessen Kind-Arrays sind flach).
     */
    public static function findById(int $testId): ?array
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('SELECT id, name, beschreibung, zielgruppe, created_at, updated_at, created_by FROM tests WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $testId]);
        $test = $stmt->fetch();

        if (!$test) {
            return null;
        }

        $aufgabenStmt = $pdo->prepare(
            'SELECT id, titel, beschreibung FROM test_aufgaben WHERE test_id = :test_id ORDER BY id'
        );
        $aufgabenStmt->execute([':test_id' => $testId]);
        $aufgaben = $aufgabenStmt->fetchAll();

        if (!empty($aufgaben)) {
            $aufgabenIds = array_column($aufgaben, 'id');
            $placeholders = implode(',', array_fill(0, count($aufgabenIds), '?'));
            $ergebnisseStmt = $pdo->prepare(
                "SELECT id, aufgabe_id, bezeichnung, kategorie FROM test_ergebnisse WHERE aufgabe_id IN ({$placeholders}) ORDER BY id"
            );
            $ergebnisseStmt->execute($aufgabenIds);
            $ergebnisseByAufgabe = [];
            foreach ($ergebnisseStmt->fetchAll() as $ergebnis) {
                $ergebnisseByAufgabe[(int) $ergebnis['aufgabe_id']][] = $ergebnis;
            }

            foreach ($aufgaben as &$aufgabe) {
                $aufgabe['ergebnisse'] = $ergebnisseByAufgabe[(int) $aufgabe['id']] ?? [];
            }
            unset($aufgabe);
        }

        $test['aufgaben'] = $aufgaben;

        return $test;
    }

    /**
     * @param array<string, mixed> $testData Erwartet Schlüssel: name, beschreibung, zielgruppe
     * @param array $aufgaben Liste von ['titel', 'beschreibung', 'ergebnisse' => [['bezeichnung', 'kategorie'], ...]]
     * @return array{success: bool, message: string, test_id?: int}
     */
    public static function create(array $testData, array $aufgaben, int $createdByUserId): array
    {
        $name = clean_input((string) ($testData['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'message' => 'Der Name des Tests darf nicht leer sein.'];
        }

        $zielgruppe = (string) ($testData['zielgruppe'] ?? '');
        if (!in_array($zielgruppe, self::VALID_ZIELGRUPPEN, true)) {
            return ['success' => false, 'message' => 'Ungültige Zielgruppe (Welpe/Erwachsen/Beide).'];
        }

        $validatedAufgaben = self::validateAufgaben($aufgaben);
        if (!$validatedAufgaben['success']) {
            return $validatedAufgaben;
        }

        $pdo = Database::getConnection();

        $checkStmt = $pdo->prepare('SELECT id FROM tests WHERE name = :name LIMIT 1');
        $checkStmt->execute([':name' => $name]);
        if ($checkStmt->fetch()) {
            return ['success' => false, 'message' => 'Ein Test mit dem Namen "' . $name . '" existiert bereits.'];
        }

        try {
            $pdo->beginTransaction();

            $beschreibung = clean_input((string) ($testData['beschreibung'] ?? ''));
            $stmt = $pdo->prepare(
                'INSERT INTO tests (name, beschreibung, zielgruppe, created_by) VALUES (:name, :beschreibung, :zielgruppe, :created_by)'
            );
            $stmt->execute([
                ':name'         => $name,
                ':beschreibung' => $beschreibung !== '' ? $beschreibung : null,
                ':zielgruppe'   => $zielgruppe,
                ':created_by'   => $createdByUserId,
            ]);

            $testId = (int) $pdo->lastInsertId();
            self::insertAufgabenMitErgebnissen($pdo, $testId, $validatedAufgaben['aufgaben']);

            $pdo->commit();

            return ['success' => true, 'message' => 'Test "' . $name . '" wurde angelegt.', 'test_id' => $testId];
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Fehler beim Anlegen eines Tests: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Beim Speichern ist ein Fehler aufgetreten. Es wurden keine Daten gespeichert.'];
        }
    }

    /**
     * Ersetzt Name/Beschreibung sowie — sofern der Test noch KEINE
     * Testdurchführungen hat — die komplette Aufgaben-/Ergebnis-
     * Struktur (alte Zeilen löschen, neue einfügen — einfacher und
     * robuster als ein Diff, analog Breed::update()).
     *
     * Hat der Test bereits Durchführungen, würde ein Überschreiben der
     * Struktur die RESTRICT-FKs von test_durchfuehrung_ergebnisse
     * verletzen bzw. bestehende Historie kontextlos machen — in dem
     * Fall wird die übergebene Aufgaben-/Ergebnis-Liste ignoriert und
     * nur Name/Beschreibung aktualisiert (test_form.php rendert die
     * Struktur in diesem Fall ohnehin nur noch lesbar an).
     *
     * @param array<string, mixed> $testData
     * @param array $aufgaben
     * @return array{success: bool, message: string}
     */
    public static function update(int $testId, array $testData, array $aufgaben): array
    {
        $name = clean_input((string) ($testData['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'message' => 'Der Name des Tests darf nicht leer sein.'];
        }

        $zielgruppe = (string) ($testData['zielgruppe'] ?? '');
        if (!in_array($zielgruppe, self::VALID_ZIELGRUPPEN, true)) {
            return ['success' => false, 'message' => 'Ungültige Zielgruppe (Welpe/Erwachsen/Beide).'];
        }

        $strukturSperren = self::hasDurchfuehrungen($testId);

        if (!$strukturSperren) {
            $validatedAufgaben = self::validateAufgaben($aufgaben);
            if (!$validatedAufgaben['success']) {
                return $validatedAufgaben;
            }
        }

        $pdo = Database::getConnection();

        $checkStmt = $pdo->prepare('SELECT id FROM tests WHERE name = :name AND id != :id LIMIT 1');
        $checkStmt->execute([':name' => $name, ':id' => $testId]);
        if ($checkStmt->fetch()) {
            return ['success' => false, 'message' => 'Ein Test mit dem Namen "' . $name . '" existiert bereits.'];
        }

        try {
            $pdo->beginTransaction();

            $beschreibung = clean_input((string) ($testData['beschreibung'] ?? ''));
            $stmt = $pdo->prepare(
                'UPDATE tests SET name = :name, beschreibung = :beschreibung, zielgruppe = :zielgruppe, updated_at = NOW() WHERE id = :id'
            );
            $stmt->execute([
                ':name'         => $name,
                ':beschreibung' => $beschreibung !== '' ? $beschreibung : null,
                ':zielgruppe'   => $zielgruppe,
                ':id'           => $testId,
            ]);

            if (!$strukturSperren) {
                // Aufgaben löschen kaskadiert automatisch zu ihren
                // Ergebnissen (ON DELETE CASCADE test_ergebnisse -> test_aufgaben).
                $pdo->prepare('DELETE FROM test_aufgaben WHERE test_id = :test_id')->execute([':test_id' => $testId]);
                self::insertAufgabenMitErgebnissen($pdo, $testId, $validatedAufgaben['aufgaben']);
            }

            $pdo->commit();

            return ['success' => true, 'message' => 'Test "' . $name . '" wurde aktualisiert.'];
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Fehler beim Aktualisieren eines Tests: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Beim Speichern ist ein Fehler aufgetreten. Es wurden keine Änderungen übernommen.'];
        }
    }

    /**
     * Löscht einen Test inkl. seiner Aufgaben/Ergebnisse (CASCADE) —
     * sofern noch keine Testdurchführung darauf verweist (siehe
     * hasDurchfuehrungen()). Ein roher FK-Fehler (RESTRICT) würde sonst
     * bis zur Seite durchschlagen; hier wird stattdessen eine
     * verständliche Meldung zurückgegeben.
     *
     * @return array{success: bool, message: string}
     */
    public static function delete(int $testId): array
    {
        $anzahl = self::countDurchfuehrungen($testId);
        if ($anzahl > 0) {
            return [
                'success' => false,
                'message' => 'Dieser Test wurde bereits ' . $anzahl . ' Mal durchgeführt und kann nicht gelöscht werden.',
            ];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM tests WHERE id = :id');
        $stmt->execute([':id' => $testId]);

        return ['success' => true, 'message' => 'Test wurde gelöscht.'];
    }

    /**
     * Prüft, ob für diesen Test bereits mindestens eine Testdurchführung
     * existiert — steuert sowohl die Löschsperre (delete()) als auch,
     * ob die Aufgaben-/Ergebnis-Struktur in update()/test_form.php noch
     * frei bearbeitbar ist.
     */
    public static function hasDurchfuehrungen(int $testId): bool
    {
        return self::countDurchfuehrungen($testId) > 0;
    }

    private static function countDurchfuehrungen(int $testId): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM test_durchfuehrungen WHERE test_id = :test_id');
        $stmt->execute([':test_id' => $testId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Liefert die Anzahl Durchführungen pro Test — für Admin-Liste
     * (Nutzungshinweis vor dem Löschen), analog Tag::getBreedCounts().
     *
     * @return array<int, int> [test_id => durchfuehrung_count]
     */
    public static function getDurchfuehrungCounts(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query(
            'SELECT t.id, COUNT(td.id) AS durchfuehrung_count
             FROM tests t
             LEFT JOIN test_durchfuehrungen td ON td.test_id = t.id
             GROUP BY t.id'
        );
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(int) $row['id']] = (int) $row['durchfuehrung_count'];
        }
        return $counts;
    }

    /**
     * Liefert die Anzahl Aufgaben pro Test — für die Admin-Liste
     * (admin_manage_tests.php), analog Tag::getBreedCounts().
     *
     * @return array<int, int> [test_id => aufgaben_count]
     */
    public static function getAufgabenCounts(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query(
            'SELECT t.id, COUNT(ta.id) AS aufgaben_count
             FROM tests t
             LEFT JOIN test_aufgaben ta ON ta.test_id = t.id
             GROUP BY t.id'
        );
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(int) $row['id']] = (int) $row['aufgaben_count'];
        }
        return $counts;
    }

    // -------------------------------------------------------------
    // Private Hilfsmethoden
    // -------------------------------------------------------------

    /**
     * Validiert eine Liste von Aufgaben inkl. ihrer verschachtelten
     * Ergebnisse. Jede Aufgabe braucht einen nicht-leeren Titel und
     * mindestens ein Ergebnis mit gültiger Kategorie.
     *
     * @param array $aufgaben
     * @return array{success: bool, message: string, aufgaben?: array}
     */
    private static function validateAufgaben(array $aufgaben): array
    {
        $cleaned = [];

        foreach ($aufgaben as $aufgabe) {
            $titel = clean_input((string) ($aufgabe['titel'] ?? ''));
            if ($titel === '') {
                continue; // leere/nicht ausgefüllte Zeile überspringen
            }
            if (mb_strlen($titel) > 200) {
                return ['success' => false, 'message' => 'Der Titel einer Aufgabe darf maximal 200 Zeichen lang sein.'];
            }

            $beschreibung = clean_input((string) ($aufgabe['beschreibung'] ?? ''));

            $cleanedErgebnisse = [];
            foreach (($aufgabe['ergebnisse'] ?? []) as $ergebnis) {
                $bezeichnung = clean_input((string) ($ergebnis['bezeichnung'] ?? ''));
                if ($bezeichnung === '') {
                    continue; // leere/nicht ausgefüllte Ergebnis-Zeile überspringen
                }
                if (mb_strlen($bezeichnung) > 200) {
                    return ['success' => false, 'message' => 'Die Bezeichnung eines Ergebnisses darf maximal 200 Zeichen lang sein.'];
                }

                $kategorie = (string) ($ergebnis['kategorie'] ?? '');
                if (!in_array($kategorie, self::VALID_KATEGORIEN, true)) {
                    return ['success' => false, 'message' => 'Ungültige Kategorie für ein Ergebnis: "' . $kategorie . '".'];
                }

                $cleanedErgebnisse[] = ['bezeichnung' => $bezeichnung, 'kategorie' => $kategorie];
            }

            if (empty($cleanedErgebnisse)) {
                return ['success' => false, 'message' => 'Die Aufgabe "' . $titel . '" braucht mindestens ein mögliches Ergebnis.'];
            }

            $cleaned[] = [
                'titel'        => $titel,
                'beschreibung' => $beschreibung !== '' ? $beschreibung : null,
                'ergebnisse'   => $cleanedErgebnisse,
            ];
        }

        if (empty($cleaned)) {
            return ['success' => false, 'message' => 'Der Test braucht mindestens eine Aufgabe.'];
        }

        return ['success' => true, 'message' => '', 'aufgaben' => $cleaned];
    }

    /**
     * Fügt Aufgaben + ihre Ergebnisse ein — zweistufig verschachtelte
     * Variante von Breed::insertSizes(): pro Aufgabe wird zuerst die
     * Aufgaben-Zeile eingefügt, dann werden über deren lastInsertId()
     * die zugehörigen Ergebnis-Zeilen eingefügt.
     */
    private static function insertAufgabenMitErgebnissen(PDO $pdo, int $testId, array $aufgaben): void
    {
        $aufgabeStmt = $pdo->prepare(
            'INSERT INTO test_aufgaben (test_id, titel, beschreibung) VALUES (:test_id, :titel, :beschreibung)'
        );
        $ergebnisStmt = $pdo->prepare(
            'INSERT INTO test_ergebnisse (aufgabe_id, bezeichnung, kategorie) VALUES (:aufgabe_id, :bezeichnung, :kategorie)'
        );

        foreach ($aufgaben as $aufgabe) {
            $aufgabeStmt->execute([
                ':test_id'      => $testId,
                ':titel'        => $aufgabe['titel'],
                ':beschreibung' => $aufgabe['beschreibung'],
            ]);
            $aufgabeId = (int) $pdo->lastInsertId();

            foreach ($aufgabe['ergebnisse'] as $ergebnis) {
                $ergebnisStmt->execute([
                    ':aufgabe_id'  => $aufgabeId,
                    ':bezeichnung' => $ergebnis['bezeichnung'],
                    ':kategorie'   => $ergebnis['kategorie'],
                ]);
            }
        }
    }
}
