<?php
/**
 * models/Test.php
 *
 * Test-Katalog (z.B. "Aggressionstest", "PTBS-Assistenzhund-Test"). Ein
 * Test bündelt mehrere Aufgaben, jede Aufgabe mehrere mögliche Ergebnisse
 * (Kategorie: bestanden/neutral/nicht_bestanden).
 *
 * Geltungsbereich (tests.tenant_id):
 * - Zahl: Test einer Organisation, nur dort sichtbar.
 * - NULL: allgemeiner Test, in allen Organisationen sichtbar. Anlegen,
 *   Ändern, Löschen, Freigeben und Zurückholen nur durch den Super-Admin
 *   (geprüft hier im Model, nicht nur auf der Seite). Jede Organisation
 *   kann einen allgemeinen Test für sich ausblenden (test_ausblendungen).
 *
 * Jede öffentliche Methode verlangt die Organisation des Aufrufers. Lesen
 * liefert eigene und allgemeine Tests. Durchführungen und Ergebnisse
 * gehören immer zum Hund und damit zu genau einer Organisation: Zähler
 * zählen nur Durchführungen von Hunden der eigenen Organisation, und
 * Meldungen zu allgemeinen Tests nennen keine fremden Zahlen.
 *
 * Hat ein Test Durchführungen (egal in welcher Organisation), ist seine
 * Aufgaben-/Ergebnisstruktur gesperrt und er lässt sich nicht löschen.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ContentRevision.php';

class Test
{
    private const VALID_KATEGORIEN  = ['bestanden', 'neutral', 'nicht_bestanden'];
    private const VALID_ZIELGRUPPEN = ['welpe', 'erwachsen', 'beide'];

    /** Werte des versionierten Feldes "geltungsbereich". */
    private const GELTUNG_ORGANISATION = 'Organisation';
    private const GELTUNG_ALLGEMEIN    = 'Allgemein';

    /**
     * Eigene und allgemeine Tests, allgemeine zuerst. Jede Zeile hat
     * zusätzlich `allgemein` und `ausgeblendet` (für diese Organisation).
     *
     * @param string|null $zielgruppe Optionaler Filter: 'welpe'/'erwachsen' liefert
     *        zusätzlich immer auch Tests mit Zielgruppe 'beide' (passt auf beide
     *        Altersgruppen), null liefert alle Tests ungefiltert.
     * @param bool $ohneAusgeblendete true für die Auswahl neuer Durchführungen
     * @return array<int, array<string, mixed>>
     */
    public static function findAll(int $tenantId, ?string $zielgruppe = null, bool $ohneAusgeblendete = false): array
    {
        $sql = 'SELECT t.id, t.name, t.beschreibung, t.zielgruppe, t.created_at,
                       (t.tenant_id IS NULL) AS allgemein,
                       (a.test_id IS NOT NULL) AS ausgeblendet
                FROM tests t
                LEFT JOIN test_ausblendungen a ON a.test_id = t.id AND a.tenant_id = :tenant_a
                WHERE (t.tenant_id = :tenant_id OR t.tenant_id IS NULL)';
        $params = [':tenant_a' => $tenantId, ':tenant_id' => $tenantId];

        if ($zielgruppe !== null && in_array($zielgruppe, ['welpe', 'erwachsen'], true)) {
            $sql .= " AND (t.zielgruppe = :zielgruppe OR t.zielgruppe = 'beide')";
            $params[':zielgruppe'] = $zielgruppe;
        }
        if ($ohneAusgeblendete) {
            $sql .= ' AND a.test_id IS NULL';
        }
        $sql .= ' ORDER BY allgemein DESC, t.name';

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'flagsAlsBool'], $stmt->fetchAll());
    }

    /**
     * Lädt einen für die Organisation sichtbaren Test (eigen oder
     * allgemein, auch ausgeblendet) inkl. seiner Aufgaben, je mit ihren
     * möglichen Ergebnissen.
     */
    public static function findById(int $testId, int $tenantId): ?array
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare(
            'SELECT t.id, t.name, t.beschreibung, t.zielgruppe, t.created_at, t.updated_at, t.created_by,
                    (t.tenant_id IS NULL) AS allgemein,
                    (a.test_id IS NOT NULL) AS ausgeblendet
             FROM tests t
             LEFT JOIN test_ausblendungen a ON a.test_id = t.id AND a.tenant_id = :tenant_a
             WHERE t.id = :id AND (t.tenant_id = :tenant_id OR t.tenant_id IS NULL)
             LIMIT 1'
        );
        $stmt->execute([':id' => $testId, ':tenant_a' => $tenantId, ':tenant_id' => $tenantId]);
        $test = $stmt->fetch();

        if (!$test) {
            return null;
        }
        $test = self::flagsAlsBool($test);

        $aufgabenStmt = $pdo->prepare(
            'SELECT id, abschnitt, titel, beschreibung FROM test_aufgaben WHERE test_id = :test_id ORDER BY id'
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
     * Darf der angemeldete Benutzer allgemeine Tests anlegen, ändern,
     * löschen, freigeben oder zurückholen? Nur der Super-Admin.
     */
    public static function darfAllgemeineVerwalten(): bool
    {
        return function_exists('is_super_admin') && is_super_admin();
    }

    /**
     * @param array<string, mixed> $testData Erwartet Schlüssel: name, beschreibung, zielgruppe
     * @param array $aufgaben Liste von ['titel', 'beschreibung', 'ergebnisse' => [['bezeichnung', 'kategorie'], ...]]
     * @param int $tenantId Organisation des Aufrufers
     * @param bool $allgemein true = allgemeiner Test für alle Organisationen (nur Super-Admin)
     * @param string|null $anlass 'import': der neue Test wird zusätzlich als Version gespeichert
     * @return array{success: bool, message: string, test_id?: int}
     */
    public static function create(array $testData, array $aufgaben, int $createdByUserId, int $tenantId, bool $allgemein = false, ?string $anlass = null): array
    {
        if ($allgemein && !self::darfAllgemeineVerwalten()) {
            return ['success' => false, 'message' => t('tests.m_nur_super_admin')];
        }

        $name = clean_input((string) ($testData['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'message' => t('tests.m_name_leer')];
        }

        $zielgruppe = (string) ($testData['zielgruppe'] ?? '');
        if (!in_array($zielgruppe, self::VALID_ZIELGRUPPEN, true)) {
            return ['success' => false, 'message' => t('tests.m_zielgruppe')];
        }

        $validatedAufgaben = self::validateAufgaben($aufgaben);
        if (!$validatedAufgaben['success']) {
            return $validatedAufgaben;
        }

        $pdo = Database::getConnection();

        $konflikt = self::namensKonflikt($pdo, $name, $allgemein ? null : $tenantId, null);
        if ($konflikt !== null) {
            return ['success' => false, 'message' => $konflikt];
        }

        try {
            $pdo->beginTransaction();

            $beschreibung = clean_input((string) ($testData['beschreibung'] ?? ''));
            $stmt = $pdo->prepare(
                'INSERT INTO tests (tenant_id, name, beschreibung, zielgruppe, created_by) VALUES (:tenant_id, :name, :beschreibung, :zielgruppe, :created_by)'
            );
            $stmt->execute([
                ':tenant_id'    => $allgemein ? null : $tenantId,
                ':name'         => $name,
                ':beschreibung' => $beschreibung !== '' ? $beschreibung : null,
                ':zielgruppe'   => $zielgruppe,
                ':created_by'   => $createdByUserId,
            ]);

            $testId = (int) $pdo->lastInsertId();
            self::insertAufgabenMitErgebnissen($pdo, $testId, $validatedAufgaben['aufgaben']);

            if ($anlass !== null) {
                ContentRevision::protokolliere($pdo, 'tests', $testId, [], [
                    'name'         => $name,
                    'beschreibung' => $beschreibung !== '' ? $beschreibung : null,
                    'zielgruppe'   => $zielgruppe,
                    'aufgaben'     => $validatedAufgaben['aufgaben'],
                ], $createdByUserId, $anlass, $allgemein ? null : $tenantId);
            }

            $pdo->commit();

            return ['success' => true, 'message' => t('tests.m_angelegt', ['name' => $name]), 'test_id' => $testId];
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Fehler beim Anlegen eines Tests: ' . $e->getMessage());
            return ['success' => false, 'message' => t('allg.speicherfehler_daten')];
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
     * Allgemeine Tests darf nur der Super-Admin ändern.
     *
     * @param array<string, mixed> $testData
     * @param array $aufgaben
     * @return array{success: bool, message: string}
     */
    public static function update(int $testId, int $tenantId, array $testData, array $aufgaben, ?int $geaendertVon = null): array
    {
        $pdo = Database::getConnection();

        $geltung = self::geltung($pdo, $testId, $tenantId);
        if ($geltung === false) {
            return ['success' => false, 'message' => t('tests.m_nicht_gefunden')];
        }
        if ($geltung === null && !self::darfAllgemeineVerwalten()) {
            return ['success' => false, 'message' => t('tests.m_nur_super_admin')];
        }

        $name = clean_input((string) ($testData['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'message' => t('tests.m_name_leer')];
        }

        $zielgruppe = (string) ($testData['zielgruppe'] ?? '');
        if (!in_array($zielgruppe, self::VALID_ZIELGRUPPEN, true)) {
            return ['success' => false, 'message' => t('tests.m_zielgruppe')];
        }

        $strukturSperren = self::hasDurchfuehrungen($testId);

        if (!$strukturSperren) {
            $validatedAufgaben = self::validateAufgaben($aufgaben);
            if (!$validatedAufgaben['success']) {
                return $validatedAufgaben;
            }
        }

        $konflikt = self::namensKonflikt($pdo, $name, $geltung, $testId);
        if ($konflikt !== null) {
            return ['success' => false, 'message' => $konflikt];
        }

        $scope = $geltung ?? 0;

        try {
            $pdo->beginTransaction();

            $altStmt = $pdo->prepare('SELECT name, beschreibung, zielgruppe FROM tests WHERE id = :id AND scope_id = :scope FOR UPDATE');
            $altStmt->execute([':id' => $testId, ':scope' => $scope]);
            $alt = $altStmt->fetch() ?: [];
            $alt['aufgaben'] = self::aufgabenSnapshot($pdo, $testId);

            $beschreibung = clean_input((string) ($testData['beschreibung'] ?? ''));
            $neu = [
                'name'         => $name,
                'beschreibung' => $beschreibung !== '' ? $beschreibung : null,
                'zielgruppe'   => $zielgruppe,
            ];
            if (!$strukturSperren) {
                $neu['aufgaben'] = $validatedAufgaben['aufgaben'];
            }

            $stmt = $pdo->prepare(
                'UPDATE tests SET name = :name, beschreibung = :beschreibung, zielgruppe = :zielgruppe, updated_at = NOW()
                 WHERE id = :id AND scope_id = :scope'
            );
            $stmt->execute([
                ':name'         => $name,
                ':beschreibung' => $beschreibung !== '' ? $beschreibung : null,
                ':zielgruppe'   => $zielgruppe,
                ':id'           => $testId,
                ':scope'        => $scope,
            ]);

            if (!$strukturSperren) {
                // Aufgaben löschen kaskadiert automatisch zu ihren
                // Ergebnissen (ON DELETE CASCADE test_ergebnisse -> test_aufgaben).
                $pdo->prepare('DELETE FROM test_aufgaben WHERE test_id = :test_id')->execute([':test_id' => $testId]);
                self::insertAufgabenMitErgebnissen($pdo, $testId, $validatedAufgaben['aufgaben']);
            }

            ContentRevision::protokolliere($pdo, 'tests', $testId, $alt, $neu, $geaendertVon, 'bearbeitung', $geltung);

            $pdo->commit();

            return ['success' => true, 'message' => t('tests.m_aktualisiert', ['name' => $name])];
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Fehler beim Aktualisieren eines Tests: ' . $e->getMessage());
            return ['success' => false, 'message' => t('allg.speicherfehler_aenderungen')];
        }
    }

    /**
     * Löscht einen Test inkl. seiner Aufgaben/Ergebnisse (CASCADE) —
     * sofern noch keine Testdurchführung darauf verweist (siehe
     * hasDurchfuehrungen()). Ein roher FK-Fehler (RESTRICT) würde sonst
     * bis zur Seite durchschlagen; hier wird stattdessen eine
     * verständliche Meldung zurückgegeben. Bei allgemeinen Tests nennt
     * die Meldung keine Anzahl, weil die Durchführungen anderen
     * Organisationen gehören.
     *
     * @return array{success: bool, message: string}
     */
    public static function delete(int $testId, int $tenantId, ?int $geaendertVon = null): array
    {
        $pdo = Database::getConnection();

        $geltung = self::geltung($pdo, $testId, $tenantId);
        if ($geltung === false) {
            return ['success' => false, 'message' => t('tests.m_nicht_gefunden')];
        }
        if ($geltung === null && !self::darfAllgemeineVerwalten()) {
            return ['success' => false, 'message' => t('tests.m_nur_super_admin')];
        }

        $anzahl = self::countDurchfuehrungen($testId);
        if ($anzahl > 0) {
            return [
                'success' => false,
                'message' => $geltung === null ? t('tests.m_genutzt') : t('tests.m_durchgefuehrt', ['anzahl' => $anzahl]),
            ];
        }

        $scope = $geltung ?? 0;

        try {
            $pdo->beginTransaction();

            // Texte und Aufgaben-Struktur vorher als Version sichern.
            $altStmt = $pdo->prepare('SELECT name, beschreibung, zielgruppe FROM tests WHERE id = :id AND scope_id = :scope FOR UPDATE');
            $altStmt->execute([':id' => $testId, ':scope' => $scope]);
            $alt = $altStmt->fetch() ?: [];
            $alt['aufgaben'] = self::aufgabenSnapshot($pdo, $testId);
            ContentRevision::protokolliere($pdo, 'tests', $testId, $alt, [], $geaendertVon, 'loeschung', $geltung);

            // Ausblendungen gehen per ON DELETE CASCADE mit.
            $stmt = $pdo->prepare('DELETE FROM tests WHERE id = :id AND scope_id = :scope');
            $stmt->execute([':id' => $testId, ':scope' => $scope]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Fehler beim Löschen eines Tests: ' . $e->getMessage());
            return ['success' => false, 'message' => t('allg.loeschfehler')];
        }

        return ['success' => true, 'message' => t('tests.m_geloescht')];
    }

    /**
     * Blendet einen allgemeinen Test für die Organisation aus oder wieder
     * ein. Eigene Tests lassen sich nicht ausblenden.
     *
     * @return array{success: bool, message: string}
     */
    public static function setzeAusgeblendet(int $testId, int $tenantId, bool $ausblenden, int $userId): array
    {
        $pdo = Database::getConnection();

        $geltung = self::geltung($pdo, $testId, $tenantId);
        if ($geltung === false) {
            return ['success' => false, 'message' => t('tests.m_nicht_gefunden')];
        }
        if ($geltung !== null) {
            return ['success' => false, 'message' => t('tests.m_nur_allgemein_ausblenden')];
        }

        if ($ausblenden) {
            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO test_ausblendungen (tenant_id, test_id, created_by) VALUES (:tenant_id, :test_id, :created_by)'
            );
            $stmt->execute([':tenant_id' => $tenantId, ':test_id' => $testId, ':created_by' => $userId]);
            return ['success' => true, 'message' => t('tests.m_ausgeblendet')];
        }

        $stmt = $pdo->prepare('DELETE FROM test_ausblendungen WHERE tenant_id = :tenant_id AND test_id = :test_id');
        $stmt->execute([':tenant_id' => $tenantId, ':test_id' => $testId]);
        return ['success' => true, 'message' => t('tests.m_eingeblendet')];
    }

    /**
     * Ist ein (allgemeiner) Test für die Organisation ausgeblendet?
     */
    public static function istAusgeblendet(int $testId, int $tenantId): bool
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT 1 FROM test_ausblendungen WHERE tenant_id = :tenant_id AND test_id = :test_id LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':test_id' => $testId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Macht einen Test der eigenen Organisation zu einem allgemeinen
     * (nur Super-Admin). Aufgaben, Ergebnisse und Durchführungen bleiben
     * unverändert, die Versionen wandern mit.
     *
     * @return array{success: bool, message: string}
     */
    public static function freigeben(int $testId, int $tenantId, int $userId): array
    {
        if (!self::darfAllgemeineVerwalten()) {
            return ['success' => false, 'message' => t('tests.m_nur_super_admin')];
        }

        $pdo = Database::getConnection();

        if (self::geltung($pdo, $testId, $tenantId) !== $tenantId) {
            return ['success' => false, 'message' => t('tests.m_nicht_gefunden')];
        }

        try {
            $pdo->beginTransaction();

            $sel = $pdo->prepare('SELECT name FROM tests WHERE id = :id AND tenant_id = :tenant_id FOR UPDATE');
            $sel->execute([':id' => $testId, ':tenant_id' => $tenantId]);
            $name = $sel->fetchColumn();
            if ($name === false) {
                $pdo->rollBack();
                return ['success' => false, 'message' => t('tests.m_nicht_gefunden')];
            }

            $chk = $pdo->prepare('SELECT 1 FROM tests WHERE name = :name AND tenant_id IS NULL LIMIT 1');
            $chk->execute([':name' => $name]);
            if ($chk->fetchColumn()) {
                $pdo->rollBack();
                return ['success' => false, 'message' => t('tests.m_existiert_allgemein', ['name' => $name])];
            }

            $andere = $pdo->prepare(
                'SELECT COUNT(DISTINCT tenant_id) FROM tests WHERE name = :name AND tenant_id IS NOT NULL AND tenant_id <> :tenant_id'
            );
            $andere->execute([':name' => $name, ':tenant_id' => $tenantId]);
            $anzahlAndere = (int) $andere->fetchColumn();

            $pdo->prepare('UPDATE tests SET tenant_id = NULL, updated_at = NOW() WHERE id = :id')->execute([':id' => $testId]);
            $pdo->prepare("UPDATE content_revisions SET tenant_id = NULL WHERE tabelle = 'tests' AND datensatz_id = :id AND tenant_id = :tenant_id")
                ->execute([':id' => $testId, ':tenant_id' => $tenantId]);
            ContentRevision::protokolliere(
                $pdo, 'tests', $testId,
                ['geltungsbereich' => self::GELTUNG_ORGANISATION],
                ['geltungsbereich' => self::GELTUNG_ALLGEMEIN],
                $userId, 'bearbeitung', null
            );

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Fehler beim Freigeben eines Tests: ' . $e->getMessage());
            return ['success' => false, 'message' => t('allg.speicherfehler_aenderungen')];
        }

        $meldung = t('tests.m_freigegeben', ['name' => (string) $name]);
        if ($anzahlAndere > 0) {
            $meldung .= ' ' . t('tests.m_freigegeben_namensgleich', ['anzahl' => $anzahlAndere]);
        }
        return ['success' => true, 'message' => $meldung];
    }

    /**
     * Holt einen allgemeinen Test in die eigene Organisation zurück (nur
     * Super-Admin). Geht nur, solange keine andere Organisation
     * Durchführungen damit hat. Ausblendungen entfallen.
     *
     * @return array{success: bool, message: string}
     */
    public static function zurueckholen(int $testId, int $tenantId, int $userId): array
    {
        if (!self::darfAllgemeineVerwalten()) {
            return ['success' => false, 'message' => t('tests.m_nur_super_admin')];
        }

        $pdo = Database::getConnection();

        if (self::geltung($pdo, $testId, $tenantId) !== null) {
            return ['success' => false, 'message' => t('tests.m_nicht_gefunden')];
        }

        try {
            $pdo->beginTransaction();

            $sel = $pdo->prepare('SELECT name FROM tests WHERE id = :id AND tenant_id IS NULL FOR UPDATE');
            $sel->execute([':id' => $testId]);
            $name = $sel->fetchColumn();
            if ($name === false) {
                $pdo->rollBack();
                return ['success' => false, 'message' => t('tests.m_nicht_gefunden')];
            }

            $fremd = $pdo->prepare(
                'SELECT 1 FROM test_durchfuehrungen td JOIN dogs d ON d.id = td.dog_id
                 WHERE td.test_id = :id AND d.tenant_id <> :tenant_id LIMIT 1'
            );
            $fremd->execute([':id' => $testId, ':tenant_id' => $tenantId]);
            if ($fremd->fetchColumn()) {
                $pdo->rollBack();
                return ['success' => false, 'message' => t('tests.m_zurueck_fremd')];
            }

            $chk = $pdo->prepare('SELECT 1 FROM tests WHERE name = :name AND tenant_id = :tenant_id LIMIT 1');
            $chk->execute([':name' => $name, ':tenant_id' => $tenantId]);
            if ($chk->fetchColumn()) {
                $pdo->rollBack();
                return ['success' => false, 'message' => t('tests.m_existiert', ['name' => $name])];
            }

            $pdo->prepare('UPDATE tests SET tenant_id = :tenant_id, updated_at = NOW() WHERE id = :id')
                ->execute([':tenant_id' => $tenantId, ':id' => $testId]);
            $pdo->prepare('DELETE FROM test_ausblendungen WHERE test_id = :id')->execute([':id' => $testId]);
            $pdo->prepare("UPDATE content_revisions SET tenant_id = :tenant_id WHERE tabelle = 'tests' AND datensatz_id = :id AND tenant_id IS NULL")
                ->execute([':tenant_id' => $tenantId, ':id' => $testId]);
            ContentRevision::protokolliere(
                $pdo, 'tests', $testId,
                ['geltungsbereich' => self::GELTUNG_ALLGEMEIN],
                ['geltungsbereich' => self::GELTUNG_ORGANISATION],
                $userId, 'bearbeitung', $tenantId
            );

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Fehler beim Zurückholen eines Tests: ' . $e->getMessage());
            return ['success' => false, 'message' => t('allg.speicherfehler_aenderungen')];
        }

        return ['success' => true, 'message' => t('tests.m_zurueckgeholt', ['name' => (string) $name])];
    }

    /**
     * Aufgaben-/Ergebnisstruktur eines Tests im selben Format wie
     * validateAufgaben() sie liefert, für den Vergleich und die
     * Versionierung (ContentRevision, Feld "aufgaben"). Leere Struktur
     * ergibt null, damit kein leeres JSON als Version entsteht.
     *
     * @return array<int, array{abschnitt?: string, titel: string, beschreibung: ?string, ergebnisse: array<int, array{bezeichnung: string, kategorie: string}>}>|null
     */
    private static function aufgabenSnapshot(PDO $pdo, int $testId): ?array
    {
        $aufgabenStmt = $pdo->prepare('SELECT id, abschnitt, titel, beschreibung FROM test_aufgaben WHERE test_id = :test_id ORDER BY id');
        $aufgabenStmt->execute([':test_id' => $testId]);
        $aufgaben = $aufgabenStmt->fetchAll();
        if (empty($aufgaben)) {
            return null;
        }

        $ergStmt = $pdo->prepare('SELECT bezeichnung, kategorie FROM test_ergebnisse WHERE aufgabe_id = :aufgabe_id ORDER BY id');
        $snapshot = [];
        foreach ($aufgaben as $aufgabe) {
            $ergStmt->execute([':aufgabe_id' => $aufgabe['id']]);
            $snapshot[] = self::mitAbschnitt($aufgabe['abschnitt'], [
                'titel'        => (string) $aufgabe['titel'],
                'beschreibung' => $aufgabe['beschreibung'] !== null && $aufgabe['beschreibung'] !== '' ? (string) $aufgabe['beschreibung'] : null,
                'ergebnisse'   => array_map(
                    fn($e) => ['bezeichnung' => (string) $e['bezeichnung'], 'kategorie' => (string) $e['kategorie']],
                    $ergStmt->fetchAll()
                ),
            ]);
        }
        return $snapshot;
    }

    /**
     * Prüft, ob für diesen Test bereits mindestens eine Testdurchführung
     * existiert, in welcher Organisation auch immer — steuert sowohl die
     * Löschsperre (delete()) als auch, ob die Aufgaben-/Ergebnis-Struktur
     * in update()/test_form.php noch frei bearbeitbar ist. Liefert bewusst
     * nur ja/nein.
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
     * Anzahl Durchführungen pro sichtbarem Test, gezählt nur für Hunde
     * der eigenen Organisation (Nutzungshinweis in der Admin-Liste).
     *
     * @return array<int, int> [test_id => durchfuehrung_count]
     */
    public static function getDurchfuehrungCounts(int $tenantId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT t.id, COUNT(d.id) AS durchfuehrung_count
             FROM tests t
             LEFT JOIN test_durchfuehrungen td ON td.test_id = t.id
             LEFT JOIN dogs d ON d.id = td.dog_id AND d.tenant_id = :tenant_d
             WHERE (t.tenant_id = :tenant_id OR t.tenant_id IS NULL)
             GROUP BY t.id'
        );
        $stmt->execute([':tenant_d' => $tenantId, ':tenant_id' => $tenantId]);
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(int) $row['id']] = (int) $row['durchfuehrung_count'];
        }
        return $counts;
    }

    /**
     * Anzahl Aufgaben pro sichtbarem Test — für die Admin-Liste
     * (admin_manage_tests.php), analog Tag::getBreedCounts().
     *
     * @return array<int, int> [test_id => aufgaben_count]
     */
    public static function getAufgabenCounts(int $tenantId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT t.id, COUNT(ta.id) AS aufgaben_count
             FROM tests t
             LEFT JOIN test_aufgaben ta ON ta.test_id = t.id
             WHERE (t.tenant_id = :tenant_id OR t.tenant_id IS NULL)
             GROUP BY t.id'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(int) $row['id']] = (int) $row['aufgaben_count'];
        }
        return $counts;
    }

    /**
     * Wie viele Organisationen haben einen allgemeinen Test ausgeblendet?
     * Nur die Zahl, nicht welche. Für die Liste des Super-Admins.
     *
     * @return array<int, int> [test_id => anzahl]
     */
    public static function getAusblendungCounts(): array
    {
        if (!self::darfAllgemeineVerwalten()) {
            return [];
        }
        $rows = Database::getConnection()->query(
            'SELECT test_id, COUNT(*) AS anzahl FROM test_ausblendungen GROUP BY test_id'
        )->fetchAll();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['test_id']] = (int) $row['anzahl'];
        }
        return $counts;
    }

    // -------------------------------------------------------------
    // Private Hilfsmethoden
    // -------------------------------------------------------------

    /**
     * Geltungsbereich eines für die Organisation sichtbaren Tests:
     * tenant_id (eigener Test), null (allgemein) oder false (nicht
     * vorhanden oder fremd).
     *
     * @return int|null|false
     */
    private static function geltung(PDO $pdo, int $testId, int $tenantId)
    {
        $stmt = $pdo->prepare(
            'SELECT tenant_id FROM tests WHERE id = :id AND (tenant_id = :tenant_id OR tenant_id IS NULL) LIMIT 1'
        );
        $stmt->execute([':id' => $testId, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }
        return $row['tenant_id'] === null ? null : (int) $row['tenant_id'];
    }

    /**
     * Prüft, ob der Name im Geltungsbereich schon vergeben ist. Ein
     * eigener Test darf außerdem nicht so heißen wie ein allgemeiner,
     * sonst stünden zwei gleichnamige Tests in derselben Auswahl.
     *
     * @param int|null $tenantId Organisation oder null für allgemein
     * @return string|null Fehlermeldung oder null
     */
    private static function namensKonflikt(PDO $pdo, string $name, ?int $tenantId, ?int $ausserId): ?string
    {
        $sql = 'SELECT scope_id FROM tests WHERE name = :name AND scope_id IN (:scope, 0)';
        $params = [':name' => $name, ':scope' => $tenantId ?? 0];
        if ($ausserId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $ausserId;
        }
        $stmt = $pdo->prepare($sql . ' ORDER BY scope_id DESC LIMIT 1');
        $stmt->execute($params);
        $treffer = $stmt->fetchColumn();
        if ($treffer === false) {
            return null;
        }
        return ($tenantId !== null && (int) $treffer === 0)
            ? t('tests.m_existiert_allgemein', ['name' => $name])
            : t('tests.m_existiert', ['name' => $name]);
    }

    /**
     * Stellt den Abschnitt vor die Aufgabe, aber nur wenn es einen gibt.
     * So bleiben Versionen von Tests ohne Abschnitte im bisherigen Format.
     */
    private static function mitAbschnitt(?string $abschnitt, array $aufgabe): array
    {
        $abschnitt = $abschnitt !== null ? trim($abschnitt) : '';
        return $abschnitt !== '' ? ['abschnitt' => $abschnitt] + $aufgabe : $aufgabe;
    }

    /** MySQL liefert die berechneten Spalten als 0/1, die Seiten erwarten bool. */
    private static function flagsAlsBool(array $row): array
    {
        $row['allgemein']    = (bool) $row['allgemein'];
        $row['ausgeblendet'] = (bool) $row['ausgeblendet'];
        return $row;
    }

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
                return ['success' => false, 'message' => t('tests.m_titel_lang')];
            }

            $beschreibung = clean_input((string) ($aufgabe['beschreibung'] ?? ''));
            $abschnitt = clean_input((string) ($aufgabe['abschnitt'] ?? ''));
            if (mb_strlen($abschnitt) > 150) {
                return ['success' => false, 'message' => t('tests.m_abschnitt_lang')];
            }

            $cleanedErgebnisse = [];
            foreach (($aufgabe['ergebnisse'] ?? []) as $ergebnis) {
                $bezeichnung = clean_input((string) ($ergebnis['bezeichnung'] ?? ''));
                if ($bezeichnung === '') {
                    continue; // leere/nicht ausgefüllte Ergebnis-Zeile überspringen
                }
                if (mb_strlen($bezeichnung) > 200) {
                    return ['success' => false, 'message' => t('tests.m_ergebnis_lang')];
                }

                $kategorie = (string) ($ergebnis['kategorie'] ?? '');
                if (!in_array($kategorie, self::VALID_KATEGORIEN, true)) {
                    return ['success' => false, 'message' => t('tests.m_kategorie', ['kategorie' => $kategorie])];
                }

                $cleanedErgebnisse[] = ['bezeichnung' => $bezeichnung, 'kategorie' => $kategorie];
            }

            if (empty($cleanedErgebnisse)) {
                return ['success' => false, 'message' => t('tests.m_aufgabe_ohne_ergebnis', ['titel' => $titel])];
            }

            $cleaned[] = self::mitAbschnitt($abschnitt, [
                'titel'        => $titel,
                'beschreibung' => $beschreibung !== '' ? $beschreibung : null,
                'ergebnisse'   => $cleanedErgebnisse,
            ]);
        }

        if (empty($cleaned)) {
            return ['success' => false, 'message' => t('tests.m_eine_aufgabe')];
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
            'INSERT INTO test_aufgaben (test_id, abschnitt, titel, beschreibung) VALUES (:test_id, :abschnitt, :titel, :beschreibung)'
        );
        $ergebnisStmt = $pdo->prepare(
            'INSERT INTO test_ergebnisse (aufgabe_id, bezeichnung, kategorie) VALUES (:aufgabe_id, :bezeichnung, :kategorie)'
        );

        foreach ($aufgaben as $aufgabe) {
            $aufgabeStmt->execute([
                ':test_id'      => $testId,
                ':abschnitt'    => $aufgabe['abschnitt'] ?? null,
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
