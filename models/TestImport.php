<?php
/**
 * models/TestImport.php
 *
 * Import von Tests aus einer JSON-Datei, die tools/excel_tests_umwandeln.py
 * aus den Excel-Eignungstests erzeugt (admin_tests_import.php).
 *
 * Format: {"format": "hunderassen-tests", "version": 1,
 *          "tests": [{"name", "beschreibung", "zielgruppe", "quelle",
 *                     "aufgaben": [{"abschnitt", "titel", "beschreibung",
 *                                   "ergebnisse": [{"bezeichnung", "kategorie"}]}]}],
 *          "warnungen": ["..."]}
 *
 * Importiert wird in die eigene Organisation. Gibt es dort (oder unter
 * den allgemeinen Tests) schon einen Test mit dem Namen, wird er
 * übersprungen, ein zweiter Lauf ändert also nichts. Angelegt wird über
 * Test::create(), damit dieselben Prüfungen greifen wie im Formular.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Test.php';

class TestImport
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    private const MAX_TESTS = 100;
    private const KATEGORIEN = ['bestanden', 'neutral', 'nicht_bestanden'];

    /**
     * Liest und prüft die Struktur der Datei.
     *
     * @return array{daten: ?array, fehler: ?string}
     */
    public static function lesen(string $inhalt): array
    {
        $daten = json_decode($inhalt, true);
        if (!is_array($daten) || ($daten['format'] ?? null) !== 'hunderassen-tests') {
            return ['daten' => null, 'fehler' => 'Das ist keine Testdatei aus tools/excel_tests_umwandeln.py.'];
        }
        if ((int) ($daten['version'] ?? 0) !== 1) {
            return ['daten' => null, 'fehler' => 'Unbekannte Version der Testdatei.'];
        }
        $tests = $daten['tests'] ?? null;
        if (!is_array($tests) || !array_is_list($tests) || $tests === []) {
            return ['daten' => null, 'fehler' => 'Die Datei enthält keine Tests.'];
        }
        if (count($tests) > self::MAX_TESTS) {
            return ['daten' => null, 'fehler' => 'Zu viele Tests in einer Datei (höchstens ' . self::MAX_TESTS . ').'];
        }
        foreach ($tests as $i => $t) {
            if (!is_array($t) || !is_string($t['name'] ?? null) || !is_array($t['aufgaben'] ?? null)) {
                return ['daten' => null, 'fehler' => 'Test Nr. ' . ($i + 1) . ' ist unvollständig (Name oder Aufgaben fehlen).'];
            }
            foreach ($t['aufgaben'] as $a) {
                if (!is_array($a) || !is_array($a['ergebnisse'] ?? null)) {
                    return ['daten' => null, 'fehler' => 'Test "' . $t['name'] . '" hat eine Aufgabe ohne Ergebnisliste.'];
                }
            }
        }
        $warnungen = array_values(array_filter($daten['warnungen'] ?? [], 'is_string'));
        return ['daten' => ['tests' => $tests, 'warnungen' => $warnungen], 'fehler' => null];
    }

    /**
     * Vorschau: je Test Zahlen und Status, ohne zu schreiben.
     *
     * @return array<int, array{name: string, quelle: string, zielgruppe: string, aufgaben: int, abschnitte: int, optionen: int, status: string, meldung: string}>
     */
    public static function pruefen(array $daten, int $tenantId): array
    {
        $vorhanden = self::vorhandeneNamen($tenantId);
        $zeilen = [];
        $imDatei = [];
        foreach ($daten['tests'] as $t) {
            $name = trim((string) $t['name']);
            $aufgaben = $t['aufgaben'];
            $abschnitte = array_unique(array_filter(array_map(fn($a) => trim((string) ($a['abschnitt'] ?? '')), $aufgaben)));
            $optionen = array_sum(array_map(fn($a) => count($a['ergebnisse']), $aufgaben));

            $fehler = self::inhaltsfehler($t);
            if ($fehler !== null) {
                [$status, $meldung] = ['fehler', $fehler];
            } elseif (isset($vorhanden[mb_strtolower($name)])) {
                [$status, $meldung] = ['schon', 'Ein Test mit diesem Namen existiert schon, wird übersprungen.'];
            } elseif (isset($imDatei[mb_strtolower($name)])) {
                [$status, $meldung] = ['schon', 'Name kommt in der Datei doppelt vor, nur der erste wird angelegt.'];
            } else {
                [$status, $meldung] = ['neu', 'Wird angelegt.'];
            }
            $imDatei[mb_strtolower($name)] = true;

            $zeilen[] = [
                'name'       => $name,
                'quelle'     => (string) ($t['quelle'] ?? ''),
                'zielgruppe' => (string) ($t['zielgruppe'] ?? ''),
                'aufgaben'   => count($aufgaben),
                'abschnitte' => count($abschnitte),
                'optionen'   => $optionen,
                'status'     => $status,
                'meldung'    => $meldung,
            ];
        }
        return $zeilen;
    }

    /**
     * Legt alle neuen Tests in der eigenen Organisation an.
     *
     * @return array{angelegt: int, uebersprungen: int, fehler: array<int, string>}
     */
    public static function anwenden(array $daten, int $tenantId, int $userId): array
    {
        $ergebnis = ['angelegt' => 0, 'uebersprungen' => 0, 'fehler' => []];
        foreach (self::pruefen($daten, $tenantId) as $i => $zeile) {
            if ($zeile['status'] !== 'neu') {
                $ergebnis['uebersprungen']++;
                continue;
            }
            $t = $daten['tests'][$i];
            $r = Test::create(
                [
                    'name'         => (string) $t['name'],
                    'beschreibung' => (string) ($t['beschreibung'] ?? ''),
                    'zielgruppe'   => (string) ($t['zielgruppe'] ?? ''),
                ],
                $t['aufgaben'],
                $userId,
                $tenantId,
                false,
                'import'
            );
            if ($r['success']) {
                $ergebnis['angelegt']++;
            } else {
                $ergebnis['fehler'][] = $zeile['name'] . ': ' . $r['message'];
            }
        }
        return $ergebnis;
    }

    /**
     * Namen eigener und allgemeiner Tests, klein geschrieben.
     *
     * @return array<string, true>
     */
    private static function vorhandeneNamen(int $tenantId): array
    {
        $stmt = Database::getConnection()->prepare('SELECT name FROM tests WHERE tenant_id = :t OR tenant_id IS NULL');
        $stmt->execute([':t' => $tenantId]);
        $namen = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $n) {
            $namen[mb_strtolower(trim((string) $n))] = true;
        }
        return $namen;
    }

    /** Fehler, die Test::create später ablehnen würde, schon in der Vorschau zeigen. */
    private static function inhaltsfehler(array $t): ?string
    {
        $name = trim((string) $t['name']);
        if ($name === '' || mb_strlen($name) > 150) {
            return 'Name fehlt oder ist länger als 150 Zeichen.';
        }
        if (!in_array($t['zielgruppe'] ?? '', ['welpe', 'erwachsen', 'beide'], true)) {
            return 'Ungültige Zielgruppe.';
        }
        if ($t['aufgaben'] === []) {
            return 'Keine Aufgaben.';
        }
        foreach ($t['aufgaben'] as $nr => $a) {
            $titel = trim((string) ($a['titel'] ?? ''));
            if ($titel === '' || mb_strlen($titel) > 200) {
                return 'Aufgabe ' . ($nr + 1) . ': Titel fehlt oder ist zu lang.';
            }
            if (mb_strlen(trim((string) ($a['abschnitt'] ?? ''))) > 150) {
                return 'Aufgabe "' . $titel . '": Abschnitt ist zu lang.';
            }
            if ($a['ergebnisse'] === []) {
                return 'Aufgabe "' . $titel . '" hat keine Ergebnisoptionen.';
            }
            foreach ($a['ergebnisse'] as $e) {
                $bez = trim((string) ($e['bezeichnung'] ?? ''));
                if ($bez === '' || mb_strlen($bez) > 200) {
                    return 'Aufgabe "' . $titel . '": eine Option fehlt oder ist länger als 200 Zeichen.';
                }
                if (!in_array($e['kategorie'] ?? '', self::KATEGORIEN, true)) {
                    return 'Aufgabe "' . $titel . '": ungültige Kategorie.';
                }
            }
        }
        return null;
    }
}
