<?php

/**
 * generate_import_sql.php
 *
 * Liest die gescrapte JSON-Datei (VDH-Rasselexikon) und erzeugt daraus
 * eine fertige import_hunderassen.sql-Datei mit INSERT-Statements für
 * die Tabellen `breeds` und `breed_sizes`.
 *
 * WICHTIG: Dieses Skript läuft NICHT auf dem Zielserver. Es ist ein
 * einmaliger Daten-Generator — das Ergebnis ist eine reine .sql-Datei,
 * die du in HeidiSQL lädst (genau wie schema.sql).
 *
 * Aufruf: php generate_import_sql.php <pfad-zur-json> <ausgabe.sql>
 *
 * Mapping-Entscheidungen (mit dir abgestimmt):
 * - breeds.ursprungsland  -> immer NULL (Daten im JSON nicht zuverlässig
 *                            extrahierbar, lieber leer als falsch)
 * - breeds.beschreibung   -> text_vorlage + text_charakter + text_haltung
 *                            zusammengeführt mit Überschriften
 * - breed_sizes           -> EINE Größenklassen-Zeile pro Rasse, abgeleitet
 *                            aus widerristhoehe_cm; Rüde- und Hündin-Spanne
 *                            identisch übernommen (JSON trennt nicht nach
 *                            Geschlecht); groesse wird primär aus dem
 *                            Mittelwert der cm-Spanne abgeleitet:
 *                              <  30 cm -> klein
 *                              <  45 cm -> mittel
 *                              <  60 cm -> gross
 *                              >= 60 cm -> sehr_gross
 *                            Fehlt die Schulterhöhe, aber das Gewicht ist
 *                            vorhanden (betrifft 23 Rassen, z.B. Chihuahua,
 *                            Malteser, Mops), wird stattdessen aus dem
 *                            Gewichts-Mittelwert abgeleitet:
 *                              <   5 kg -> klein
 *                              <  15 kg -> mittel
 *                              <  30 kg -> gross
 *                              >= 30 kg -> sehr_gross
 * - gewicht_kg             -> NEUE Spalten in breed_sizes:
 *                            gewicht_min_kg, gewicht_max_kg (DECIMAL(5,1))
 *                            Diese Migration liegt als ALTER TABLE am
 *                            Anfang der erzeugten SQL-Datei.
 * - Re-Import-Sicherheit   -> Skript beginnt mit TRUNCATE breed_sizes,
 *                            breeds (in der richtigen Reihenfolge wegen FK)
 * - Rassen ohne jede cm/kg-Angabe -> bekommen KEINE breed_sizes-Zeile
 *                            (nichts Erfundenes eintragen)
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// 0) CLI-Argumente
// ---------------------------------------------------------------------
$jsonPath = $argv[1] ?? __DIR__ . '/hunderassen_all_vdh.json';
$outPath  = $argv[2] ?? __DIR__ . '/import_hunderassen.sql';

if (!is_file($jsonPath)) {
    fwrite(STDERR, "Eingabedatei nicht gefunden: {$jsonPath}\n");
    exit(1);
}

$raw = file_get_contents($jsonPath);
$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

if (!is_array($data)) {
    fwrite(STDERR, "JSON konnte nicht als Array gelesen werden.\n");
    exit(1);
}

// ---------------------------------------------------------------------
// 1) Hilfsfunktionen
// ---------------------------------------------------------------------

/** SQL-sicheres Escaping für String-Literale (Single-Quote-Verdopplung). */
function sqlStr(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return 'NULL';
    }
    $clean = str_replace("\0", '', $value);
    return "'" . str_replace("'", "''", $clean) . "'";
}

function sqlInt(?int $value): string
{
    return $value === null ? 'NULL' : (string) $value;
}

function sqlDecimal(?float $value): string
{
    return $value === null ? 'NULL' : number_format($value, 1, '.', '');
}

/**
 * Parst Strings wie "10-18 kg", "2,5-4 kg", "30 bis 38 cm", "21–23cm",
 * "30 cm" (Einzelwert), "Kg"/"KG" (Groß-/Kleinschreibung uneinheitlich)
 * in [min, max] als float. Gibt [null, null] zurück, wenn nichts
 * Brauchbares erkannt wird.
 *
 * @return array{0: float|null, 1: float|null}
 */
function parseRange(string $raw): array
{
    $s = trim($raw);
    if ($s === '') {
        return [null, null];
    }

    // Einheit entfernen (cm, kg, Kg, KG, mit/ohne Leerzeichen)
    $s = preg_replace('/\s*(cm|kg)\s*$/i', '', $s);
    $s = trim($s);

    // Trenner vereinheitlichen: "bis", "–" (en-dash), "-" (hyphen) -> "-"
    $s = preg_replace('/\s*bis\s*/iu', '-', $s);
    $s = str_replace('–', '-', $s);
    $s = str_replace('—', '-', $s);

    // Dezimal-Komma -> Punkt
    $s = str_replace(',', '.', $s);

    // Jetzt entweder "X-Y" oder nur "X"
    if (preg_match('/^(\d+(?:\.\d+)?)\s*-\s*(\d+(?:\.\d+)?)$/', $s, $m)) {
        return [(float) $m[1], (float) $m[2]];
    }

    if (preg_match('/^(\d+(?:\.\d+)?)$/', $s, $m)) {
        // Einzelwert ohne Spanne: min = max = Wert
        $v = (float) $m[1];
        return [$v, $v];
    }

    // Unbekanntes Format -> nichts erfinden
    return [null, null];
}

/**
 * Leitet die Größenklasse aus dem Mittelwert einer cm-Spanne ab.
 * Schwellenwerte mit dir abgestimmt: <30 klein, <45 mittel, <60 gross,
 * sonst sehr_gross.
 */
function deriveGroesseFromCm(float $minCm, float $maxCm): string
{
    $mid = ($minCm + $maxCm) / 2;

    if ($mid < 30) {
        return 'klein';
    }
    if ($mid < 45) {
        return 'mittel';
    }
    if ($mid < 60) {
        return 'gross';
    }
    return 'sehr_gross';
}

/**
 * Fallback-Ableitung aus dem Gewicht, falls keine Schulterhöhe vorliegt
 * (betrifft 23 Rassen in der Quelle, z.B. Chihuahua, Malteser, Mops).
 * Schwellenwerte mit dir abgestimmt: <5kg klein, <15kg mittel,
 * <30kg gross, sonst sehr_gross.
 */
function deriveGroesseFromKg(float $minKg, float $maxKg): string
{
    $mid = ($minKg + $maxKg) / 2;

    if ($mid < 5) {
        return 'klein';
    }
    if ($mid < 15) {
        return 'mittel';
    }
    if ($mid < 30) {
        return 'gross';
    }
    return 'sehr_gross';
}

/**
 * FCI-Klasse: nur 1-10 übernehmen, alles andere (leer, ungültig) -> NULL.
 */
function parseFciKlasse(string $raw): ?int
{
    $raw = trim($raw);
    if ($raw === '' || !ctype_digit($raw)) {
        return null;
    }
    $n = (int) $raw;
    return ($n >= 1 && $n <= 10) ? $n : null;
}

/**
 * Baut die zusammengeführte Beschreibung aus text_vorlage, text_charakter
 * und text_haltung mit Überschriften. Leere Teile werden ausgelassen.
 * text_charakter/text_haltung sind im Quelldaten oft nur abgeschnittene
 * Auszüge von text_vorlage (~500 Zeichen) -- wir nehmen sie trotzdem wie
 * geliefert, da das die mit dir abgestimmte Quelle ist.
 */
function buildBeschreibung(array $breed): ?string
{
    $parts = [];

    $vorlage = trim($breed['text_vorlage'] ?? '');
    if ($vorlage !== '') {
        $parts[] = $vorlage;
    }

    $charakter = trim($breed['text_charakter'] ?? '');
    if ($charakter !== '') {
        $parts[] = "## Charakter\n\n" . $charakter;
    }

    $haltung = trim($breed['text_haltung'] ?? '');
    if ($haltung !== '') {
        $parts[] = "## Haltung\n\n" . $haltung;
    }

    if (empty($parts)) {
        return null;
    }

    return implode("\n\n", $parts);
}

// ---------------------------------------------------------------------
// 2) Verarbeitung
// ---------------------------------------------------------------------

$breedInserts = [];
$sizeInserts  = [];

$stats = [
    'total'            => 0,
    'with_size_row'    => 0,
    'without_size_row' => 0,
    'with_fci'         => 0,
    'skipped_no_name'  => 0,
];

foreach ($data as $entry) {
    $stats['total']++;

    $name = trim($entry['name'] ?? '');
    if ($name === '') {
        $stats['skipped_no_name']++;
        continue;
    }

    $fciKlasse = parseFciKlasse((string) ($entry['fci_gruppe_nr'] ?? ''));
    if ($fciKlasse !== null) {
        $stats['with_fci']++;
    }

    $beschreibung = buildBeschreibung($entry);

    // breeds-Zeile. created_by bewusst NULL (Importdaten, kein Admin-User
    // hat sie manuell angelegt). created_at/updated_at = NOW().
    $breedInserts[] = sprintf(
        "(%s, %s, %s, %s, %s, NOW(), NOW(), NULL)",
        sqlStr($name),
        'NULL', // ursprungsland
        'NULL', // farben (im JSON nicht vorhanden)
        sqlInt($fciKlasse),
        sqlStr($beschreibung)
    );

    // breed_sizes-Zeile, nur wenn mindestens eine brauchbare cm-Angabe da ist.
    [$hMin, $hMax] = parseRange((string) ($entry['widerristhoehe_cm'] ?? ''));
    [$gMin, $gMax] = parseRange((string) ($entry['gewicht_kg'] ?? ''));

    if ($hMin !== null || $gMin !== null) {
        $stats['with_size_row']++;

        if ($hMin !== null) {
            // Primärquelle: Schulterhöhe.
            $groesse = deriveGroesseFromCm($hMin, $hMax);
        } elseif ($gMin !== null) {
            // Fallback: Schulterhöhe fehlt, aber Gewicht ist da.
            $groesse = deriveGroesseFromKg($gMin, $gMax);
        } else {
            // Sollte wegen der äußeren Bedingung nie erreicht werden.
            $groesse = 'mittel';
        }

        // Schulterhöhe als ganze cm (Schema: SMALLINT) -> runden.
        $hMinInt = $hMin !== null ? (int) round($hMin) : null;
        $hMaxInt = $hMax !== null ? (int) round($hMax) : null;

        $sizeInserts[] = [
            'breed_name' => $name,
            'groesse'    => $groesse,
            'h_min'      => $hMinInt,
            'h_max'      => $hMaxInt,
            'g_min'      => $gMin,
            'g_max'      => $gMax,
        ];
    } else {
        $stats['without_size_row']++;
    }
}

// ---------------------------------------------------------------------
// 3) SQL-Datei zusammenbauen
// ---------------------------------------------------------------------

$sql = [];
$sql[] = "-- =====================================================================";
$sql[] = "-- import_hunderassen.sql";
$sql[] = "-- Automatisch generiert aus hunderassen_all_vdh.json (VDH-Rasselexikon)";
$sql[] = "-- Generiert am: " . date('Y-m-d H:i:s');
$sql[] = "-- Datensätze in der Quelle: {$stats['total']}";
$sql[] = "-- ";
$sql[] = "-- WICHTIG: Dieses Skript löscht VORHER alle bestehenden Einträge in";
$sql[] = "-- breeds und breed_sizes (TRUNCATE), bevor es neu importiert -- ein";
$sql[] = "-- erneutes Ausführen überschreibt also den kompletten Datenbestand";
$sql[] = "-- dieser beiden Tabellen. breed_tags/breed_activities-Zuordnungen";
$sql[] = "-- hängen an breed_id und werden durch die FK-CASCADE beim TRUNCATE";
$sql[] = "-- mit gelöscht.";
$sql[] = "-- =====================================================================";
$sql[] = "";
$sql[] = "SET FOREIGN_KEY_CHECKS = 0;";
$sql[] = "";
$sql[] = "-- ---------------------------------------------------------------------";
$sql[] = "-- 0) Schema-Erweiterung: Gewicht gehört fachlich zur Größenklasse,";
$sql[] = "--    nicht zur Rasse selbst (analog zur Schulterhöhe-Begründung).";
$sql[] = "--    Portable Variante über INFORMATION_SCHEMA statt 'ADD COLUMN";
$sql[] = "--    IF NOT EXISTS', da MySQL (im Gegensatz zu MariaDB) diese";
$sql[] = "--    Syntax NICHT unterstützt (getestet bis MySQL 8.0.46 -> Fehler";
$sql[] = "--    1064). Diese Variante läuft auf MySQL UND MariaDB gleichermaßen";
$sql[] = "--    und ist bei wiederholtem Ausführen idempotent (keine Fehler,";
$sql[] = "--    falls die Spalten schon existieren).";
$sql[] = "-- ---------------------------------------------------------------------";
$sql[] = "SET @gms_exists := (";
$sql[] = "    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS";
$sql[] = "    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breed_sizes' AND COLUMN_NAME = 'gewicht_min_kg'";
$sql[] = ");";
$sql[] = "SET @gms_sql := IF(@gms_exists = 0,";
$sql[] = "    'ALTER TABLE `breed_sizes` ADD COLUMN `gewicht_min_kg` DECIMAL(5,1) NULL AFTER `schulterhoehe_haendin_max_cm`',";
$sql[] = "    'SELECT 1'";
$sql[] = ");";
$sql[] = "PREPARE gms_stmt FROM @gms_sql;";
$sql[] = "EXECUTE gms_stmt;";
$sql[] = "DEALLOCATE PREPARE gms_stmt;";
$sql[] = "";
$sql[] = "SET @gxs_exists := (";
$sql[] = "    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS";
$sql[] = "    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breed_sizes' AND COLUMN_NAME = 'gewicht_max_kg'";
$sql[] = ");";
$sql[] = "SET @gxs_sql := IF(@gxs_exists = 0,";
$sql[] = "    'ALTER TABLE `breed_sizes` ADD COLUMN `gewicht_max_kg` DECIMAL(5,1) NULL AFTER `gewicht_min_kg`',";
$sql[] = "    'SELECT 1'";
$sql[] = ");";
$sql[] = "PREPARE gxs_stmt FROM @gxs_sql;";
$sql[] = "EXECUTE gxs_stmt;";
$sql[] = "DEALLOCATE PREPARE gxs_stmt;";
$sql[] = "";
$sql[] = "-- ---------------------------------------------------------------------";
$sql[] = "-- 1) Vorherigen Importbestand entfernen (Reihenfolge wegen FK wichtig)";
$sql[] = "-- ---------------------------------------------------------------------";
$sql[] = "TRUNCATE TABLE `breed_sizes`;";
$sql[] = "TRUNCATE TABLE `breed_tags`;";
$sql[] = "TRUNCATE TABLE `breed_activities`;";
$sql[] = "TRUNCATE TABLE `breeds`;";
$sql[] = "";
$sql[] = "-- ---------------------------------------------------------------------";
$sql[] = "-- 2) breeds";
$sql[] = "--    Spalten: name, ursprungsland, farben, fci_klasse, beschreibung,";
$sql[] = "--             created_at, updated_at, created_by";
$sql[] = "-- ---------------------------------------------------------------------";

// In Batches von 100, damit kein einzelnes Riesen-INSERT-Statement entsteht.
$batches = array_chunk($breedInserts, 100);
foreach ($batches as $batch) {
    $sql[] = "INSERT INTO `breeds`";
    $sql[] = "    (`name`, `ursprungsland`, `farben`, `fci_klasse`, `beschreibung`, `created_at`, `updated_at`, `created_by`)";
    $sql[] = "VALUES";
    $sql[] = implode(",\n", array_map(fn($row) => "    {$row}", $batch)) . ";";
    $sql[] = "";
}

$sql[] = "-- ---------------------------------------------------------------------";
$sql[] = "-- 3) breed_sizes";
$sql[] = "--    Wir verknüpfen über den Rassennamen, da zum Zeitpunkt der";
$sql[] = "--    SQL-Erstellung die AUTO_INCREMENT-IDs noch nicht bekannt sind.";
$sql[] = "--    Schulterhöhe/Gewicht werden für Rüde UND Hündin identisch";
$sql[] = "--    übernommen, da die Quelle nicht nach Geschlecht trennt.";
$sql[] = "-- ---------------------------------------------------------------------";

foreach ($sizeInserts as $row) {
    $sql[] = sprintf(
        "INSERT INTO `breed_sizes` (`breed_id`, `groesse`, `schulterhoehe_ruede_min_cm`, `schulterhoehe_ruede_max_cm`, `schulterhoehe_haendin_min_cm`, `schulterhoehe_haendin_max_cm`, `gewicht_min_kg`, `gewicht_max_kg`)\nSELECT `id`, %s, %s, %s, %s, %s, %s, %s FROM `breeds` WHERE `name` = %s LIMIT 1;",
        sqlStr($row['groesse']),
        sqlInt($row['h_min']),
        sqlInt($row['h_max']),
        sqlInt($row['h_min']),
        sqlInt($row['h_max']),
        sqlDecimal($row['g_min']),
        sqlDecimal($row['g_max']),
        sqlStr($row['breed_name'])
    );
}

$sql[] = "";
$sql[] = "SET FOREIGN_KEY_CHECKS = 1;";
$sql[] = "";
$sql[] = "-- =====================================================================";
$sql[] = "-- Import-Statistik (zur Kontrolle, kein SQL):";
$sql[] = "-- Rassen gesamt:                  {$stats['total']}";
$sql[] = "-- Übersprungen (kein Name):       {$stats['skipped_no_name']}";
$sql[] = "-- Mit breed_sizes-Zeile:          {$stats['with_size_row']}";
$sql[] = "-- Ohne breed_sizes-Zeile:         {$stats['without_size_row']}";
$sql[] = "-- Mit erkannter FCI-Klasse:       {$stats['with_fci']}";
$sql[] = "-- =====================================================================";

file_put_contents($outPath, implode("\n", $sql) . "\n");

// ---------------------------------------------------------------------
// 4) Konsolen-Ausgabe für den Bediener des Generators
// ---------------------------------------------------------------------
echo "Fertig. SQL-Datei geschrieben nach: {$outPath}\n";
echo "Rassen gesamt:            {$stats['total']}\n";
echo "Übersprungen (kein Name): {$stats['skipped_no_name']}\n";
echo "Mit breed_sizes-Zeile:    {$stats['with_size_row']}\n";
echo "Ohne breed_sizes-Zeile:   {$stats['without_size_row']}\n";
echo "Mit erkannter FCI-Klasse: {$stats['with_fci']}\n";
