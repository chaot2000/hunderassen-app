<?php
/**
 * models/RassentextUmstellung.php
 *
 * Wendet RassentextGliederung auf die Rassen in der Datenbank an
 * (admin_rassentexte.php). Regeln:
 *
 * - Umgestellt wird nur eine Rasse, deren Beschreibung seit dem Original
 *   unverändert ist (beschreibung <=> beschreibung_original) und die
 *   noch nicht gegliedert ist. Von Hand geänderte Texte bleiben, wie sie
 *   sind.
 * - Felder werden nur gefüllt, wenn sie leer sind. Ausnahme: der Text
 *   selbst (beschreibung) und die Gliederung.
 * - Jede Änderung wird als Version gespeichert (Anlass
 *   'umstrukturierung'). rueckgaengig() setzt jedes Feld zurück, dessen
 *   aktueller Wert noch dem Ergebnis der Umstellung entspricht.
 * - Rassen, bei denen die Prüfung einen verlorenen Absatz meldet,
 *   werden übersprungen.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ContentRevision.php';
require_once __DIR__ . '/RassentextGliederung.php';

class RassentextUmstellung
{
    /** Felder, die die Umstellung setzen kann. */
    private const FELDER = [
        'beschreibung', 'fci_klasse', 'fci_sektion', 'fci_sektion_name', 'arbeitspruefung',
        'fci_nr', 'quelle_url', 'quelle_stand', 'gliederung',
    ];

    /**
     * Status jeder Rasse für die Übersicht.
     *
     * @return array<int, array{id: int, name: string, status: string, hinweise: array<int, string>}>
     */
    public static function uebersicht(): array
    {
        $pdo = Database::getConnection();
        $rows = $pdo->query(
            'SELECT id, name, beschreibung, beschreibung_original, gliederung FROM breeds ORDER BY name'
        )->fetchAll();

        $liste = [];
        foreach ($rows as $r) {
            $liste[] = ['id' => (int) $r['id'], 'name' => (string) $r['name']] + self::status($r);
        }
        return $liste;
    }

    /**
     * Vorschau für eine Rasse: aktueller Stand und Ergebnis der Umstellung.
     *
     * @return array{rasse: array<string, mixed>, neu: array<string, mixed>, status: string, hinweise: array<int, string>}|null
     */
    public static function vorschau(int $breedId): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id, name, ' . implode(', ', self::FELDER) . ', beschreibung_original FROM breeds WHERE id = :id');
        $stmt->execute([':id' => $breedId]);
        $r = $stmt->fetch();
        if (!$r) {
            return null;
        }
        $s = self::status($r);
        return ['rasse' => $r, 'neu' => self::neueWerte($r), 'status' => $s['status'], 'hinweise' => $s['hinweise']];
    }

    /**
     * Stellt alle umstellbaren Rassen um.
     *
     * @return array{umgestellt: int, nur_daten: int, uebersprungen: int}
     */
    public static function alleUmstellen(int $userId): array
    {
        $pdo = Database::getConnection();
        $ids = $pdo->query(
            "SELECT id FROM breeds WHERE gliederung = 'keine' ORDER BY id"
        )->fetchAll(PDO::FETCH_COLUMN);

        $ergebnis = ['umgestellt' => 0, 'nur_daten' => 0, 'uebersprungen' => 0];
        foreach ($ids as $id) {
            $art = self::umstellen((int) $id, $userId);
            $ergebnis[$art]++;
        }
        return $ergebnis;
    }

    /**
     * @return 'umgestellt'|'nur_daten'|'uebersprungen'
     */
    public static function umstellen(int $breedId, int $userId): string
    {
        $pdo = Database::getConnection();
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT id, name, ' . implode(', ', self::FELDER) . ', beschreibung_original FROM breeds WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $breedId]);
            $alt = $stmt->fetch();

            // Nur 'umstellbar' bekommt einen neuen Text (neueWerte() prüft
            // das zusätzlich), die anderen nur leere Felder gefüllt.
            $status = $alt ? self::status($alt)['status'] : 'fehlt';
            if (!in_array($status, ['umstellbar', 'ohne_text', 'von_hand', 'kein_original'], true)) {
                $pdo->rollBack();
                return 'uebersprungen';
            }

            $neu = self::neueWerte($alt);
            $geaendert = array_filter($neu, fn($wert, $feld) => (string) ($alt[$feld] ?? '') !== (string) ($wert ?? '') || ($alt[$feld] === null) !== ($wert === null), ARRAY_FILTER_USE_BOTH);

            if (!empty($geaendert)) {
                $set = implode(', ', array_map(fn($f) => "`{$f}` = :{$f}", array_keys($geaendert)));
                $upd = $pdo->prepare("UPDATE breeds SET {$set}, updated_at = NOW() WHERE id = :id");
                $params = [':id' => $breedId];
                foreach ($geaendert as $f => $w) {
                    $params[':' . $f] = $w;
                }
                $upd->execute($params);
                ContentRevision::protokolliere($pdo, 'breeds', $breedId, $alt, $geaendert, $userId, 'umstrukturierung');
            }

            $pdo->commit();
            return $status === 'umstellbar' ? 'umgestellt' : 'nur_daten';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Rassentext-Umstellung {$breedId} fehlgeschlagen: " . $e->getMessage());
            return 'uebersprungen';
        }
    }

    /**
     * Macht die Umstellung rückgängig: Jedes Feld, dessen aktueller Wert
     * noch dem Ergebnis der letzten Umstellung entspricht, bekommt seinen
     * Wert davor zurück. Felder, die seitdem von Hand geändert wurden,
     * bleiben.
     *
     * @return array{zurueckgesetzt: int, felder_behalten: int}
     */
    public static function alleRueckgaengig(int $userId): array
    {
        $pdo = Database::getConnection();
        $ids = $pdo->query(
            "SELECT DISTINCT datensatz_id FROM content_revisions WHERE tabelle = 'breeds' AND anlass = 'umstrukturierung'"
        )->fetchAll(PDO::FETCH_COLUMN);

        $ergebnis = ['zurueckgesetzt' => 0, 'felder_behalten' => 0];
        foreach ($ids as $id) {
            $id = (int) $id;
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare('SELECT ' . implode(', ', self::FELDER) . ' FROM breeds WHERE id = :id FOR UPDATE');
                $stmt->execute([':id' => $id]);
                $aktuell = $stmt->fetch();
                if (!$aktuell) {
                    $pdo->rollBack();
                    continue;
                }

                // Je Feld die jüngste Umstellungs-Version
                $rev = $pdo->prepare(
                    "SELECT feld, alter_wert, neuer_wert FROM content_revisions
                     WHERE tabelle = 'breeds' AND datensatz_id = :id AND anlass = 'umstrukturierung'
                     ORDER BY id DESC"
                );
                $rev->execute([':id' => $id]);
                $zurueck = [];
                foreach ($rev->fetchAll() as $v) {
                    if (array_key_exists($v['feld'], $zurueck) || !in_array($v['feld'], self::FELDER, true)) {
                        continue;
                    }
                    $jetzt = (string) ($aktuell[$v['feld']] ?? '');
                    if ($jetzt === (string) ($v['neuer_wert'] ?? '')) {
                        $zurueck[$v['feld']] = $v['alter_wert'];
                    } elseif ($jetzt !== (string) ($v['alter_wert'] ?? '')) {
                        // seit der Umstellung von Hand geändert: bleibt
                        $ergebnis['felder_behalten']++;
                    }
                }
                // Text zurück auf das Original heißt: keine Gliederung mehr,
                // auch wenn sie inzwischen als geprüft markiert war.
                if (array_key_exists('beschreibung', $zurueck)) {
                    $zurueck['gliederung'] = 'keine';
                }

                $zurueck = array_filter($zurueck, fn($w, $f) => (string) ($aktuell[$f] ?? '') !== (string) ($w ?? '') || ($aktuell[$f] === null) !== ($w === null), ARRAY_FILTER_USE_BOTH);
                if (!empty($zurueck)) {
                    $set = implode(', ', array_map(fn($f) => "`{$f}` = :{$f}", array_keys($zurueck)));
                    $upd = $pdo->prepare("UPDATE breeds SET {$set}, updated_at = NOW() WHERE id = :id");
                    $params = [':id' => $id];
                    foreach ($zurueck as $f => $w) {
                        $params[':' . $f] = $w;
                    }
                    $upd->execute($params);
                    ContentRevision::protokolliere($pdo, 'breeds', $id, $aktuell, $zurueck, $userId, 'wiederherstellung');
                    $ergebnis['zurueckgesetzt']++;
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Rückgängig für Rasse {$id} fehlgeschlagen: " . $e->getMessage());
            }
        }
        return $ergebnis;
    }

    /**
     * @param array<string, mixed> $r Zeile mit beschreibung, beschreibung_original, gliederung
     * @return array{status: string, hinweise: array<int, string>}
     */
    private static function status(array $r): array
    {
        if (($r['gliederung'] ?? 'keine') !== 'keine') {
            return ['status' => 'gegliedert', 'hinweise' => []];
        }
        if ($r['beschreibung_original'] === null) {
            return ['status' => $r['beschreibung'] === null ? 'ohne_text' : 'kein_original', 'hinweise' => []];
        }
        $z = RassentextGliederung::zerlege((string) $r['beschreibung_original']);
        if ($r['beschreibung'] !== null && (string) $r['beschreibung'] !== (string) $r['beschreibung_original']) {
            return ['status' => 'von_hand', 'hinweise' => ['Beschreibung wurde seit dem Original geändert und bleibt unverändert.']];
        }
        if ($z['text'] === null) {
            return ['status' => 'ohne_text', 'hinweise' => $z['hinweise']];
        }
        if ($r['beschreibung'] === null) {
            return ['status' => 'von_hand', 'hinweise' => ['Beschreibung wurde geleert und bleibt leer.']];
        }
        if (RassentextGliederung::verloreneAbsaetze((string) $r['beschreibung_original'], $z['text']) !== []) {
            return ['status' => 'verlust', 'hinweise' => ['Prüfung meldet fehlende Absätze, Rasse wird nicht umgestellt.']];
        }
        return ['status' => 'umstellbar', 'hinweise' => $z['hinweise']];
    }

    /**
     * Zielwerte der Umstellung. Strukturfelder nur, wenn sie leer sind.
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function neueWerte(array $r): array
    {
        $neu = [];
        $z = RassentextGliederung::zerlege((string) ($r['beschreibung_original'] ?? ''));

        $unveraendert = $r['beschreibung'] !== null && (string) $r['beschreibung'] === (string) $r['beschreibung_original'];
        if ($z['text'] !== null && $unveraendert) {
            $neu['beschreibung'] = $z['text'];
            $neu['gliederung'] = 'automatisch';
        } elseif ($z['text'] === null && $unveraendert) {
            // Nur Restsatz der VDH-Seite, kein echter Text: leeren.
            // Das Original bleibt in beschreibung_original.
            $neu['beschreibung'] = null;
        }

        $leer = fn(string $f): bool => !isset($r[$f]) || $r[$f] === null || $r[$f] === '';
        if ($leer('fci_klasse') && $z['fci_gruppe'] !== null) {
            $neu['fci_klasse'] = $z['fci_gruppe'];
        }
        foreach (['fci_sektion', 'fci_sektion_name', 'arbeitspruefung'] as $f) {
            if ($leer($f) && $z[$f] !== null) {
                $neu[$f] = $z[$f];
            }
        }

        $meta = self::metadaten()[(string) $r['name']] ?? null;
        if ($meta !== null) {
            foreach (['fci_nr', 'quelle_url', 'quelle_stand'] as $f) {
                if ($leer($f) && $meta[$f] !== null) {
                    $neu[$f] = $meta[$f];
                }
            }
        }
        return $neu;
    }

    /** @return array<string, array{fci_nr: ?string, quelle_url: ?string, quelle_stand: ?string}> */
    private static function metadaten(): array
    {
        static $daten = null;
        if ($daten === null) {
            $datei = __DIR__ . '/../daten/vdh_metadaten.php';
            $daten = is_file($datei) ? require $datei : [];
        }
        return $daten;
    }
}
