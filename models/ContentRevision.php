<?php
/**
 * models/ContentRevision.php
 *
 * Versionierung der Rassen- und Testtexte (Tabelle content_revisions).
 * Jede Änderung eines beobachteten Feldes wird mit altem und neuem Wert
 * festgehalten, egal ob sie aus der Bearbeitungsmaske, dem
 * TheDogAPI-Import, einem Löschvorgang oder einer Wiederherstellung
 * kommt. Damit ist jeder frühere Stand eines Textes wiederherstellbar.
 *
 * Bewusst ohne FK auf breeds/tests: Versionen sollen das Löschen des
 * Datensatzes überleben, damit sich der Text danach noch nachlesen
 * lässt.
 *
 * protokolliere() wird INNERHALB der Transaktion des Aufrufers
 * aufgerufen (gleiche PDO-Instanz), damit Änderung und Version
 * gemeinsam gespeichert oder gemeinsam verworfen werden.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class ContentRevision
{
    /**
     * Beobachtete Felder je Tabelle, mit Anzeigename. Nur diese Felder
     * werden versioniert und lassen sich wiederherstellen.
     */
    public const FELDER = [
        'breeds' => [
            'name'              => 'Name',
            'name_en'           => 'Name (englisch)',
            'alternative_namen' => 'Alternative Namen',
            'namenszusatz'      => 'Namenszusatz',
            'ursprungsland'     => 'Ursprungsland',
            'farben'            => 'Farben',
            'fci_klasse'        => 'FCI-Gruppe',
            'fci_nr'            => 'FCI-Standard-Nr.',
            'fci_sektion'       => 'FCI-Sektion',
            'fci_sektion_name'  => 'Name der Sektion',
            'arbeitspruefung'   => 'Arbeitsprüfung',
            'beschreibung'      => 'Beschreibung',
            'beschreibung_en'   => 'Beschreibung (englisch)',
            'temperament'       => 'Temperament',
            'temperament_en'    => 'Temperament (englisch)',
            'lebenserwartung'   => 'Lebenserwartung',
            'zuchtzweck'        => 'Zuchtzweck',
            'zuchtzweck_en'     => 'Zuchtzweck (englisch)',
            'thedogapi_id'      => 'TheDogAPI-Zuordnung',
            'quelle_url'        => 'Quelle',
            'quelle_stand'      => 'Stand der Quelle',
            'gliederung'        => 'Gliederung',
        ],
        'tests' => [
            'name'         => 'Name',
            'beschreibung' => 'Beschreibung',
            'zielgruppe'   => 'Zielgruppe',
            // Snapshot der Aufgaben-/Ergebnisstruktur als JSON. Nur zum
            // Nachlesen, nicht per Knopf wiederherstellbar (die Struktur
            // hängt an IDs, auf die Testdurchführungen verweisen).
            'aufgaben'     => 'Aufgaben (Struktur)',
        ],
    ];

    /** Felder, die nicht per Knopf wiederhergestellt werden können. */
    private const NUR_LESBAR = [
        'tests' => ['aufgaben'],
    ];

    public const ANLAESSE = [
        'bearbeitung'       => 'Bearbeitet',
        'import'            => 'Import',
        'loeschung'         => 'Gelöscht',
        'wiederherstellung' => 'Wiederhergestellt',
        'umstrukturierung'  => 'Automatisch gegliedert',
    ];

    /**
     * Schreibt für jedes beobachtete Feld, dessen Wert sich zwischen
     * $alt und $neu unterscheidet, eine Version. Felder, die in $neu
     * fehlen, gelten als unverändert. Für eine Löschung $neu = [] und
     * $anlass = 'loeschung' übergeben: dann wird jedes nicht leere Feld
     * aus $alt mit neuer_wert = NULL festgehalten.
     *
     * @param array<string, mixed> $alt
     * @param array<string, mixed> $neu
     * @return int Anzahl geschriebener Versionen
     */
    public static function protokolliere(
        PDO $pdo,
        string $tabelle,
        int $datensatzId,
        array $alt,
        array $neu,
        ?int $geaendertVon,
        string $anlass,
        ?int $tenantId = null
    ): int {
        if (!isset(self::FELDER[$tabelle]) || !isset(self::ANLAESSE[$anlass])) {
            throw new InvalidArgumentException("Unbekannte Tabelle oder unbekannter Anlass: {$tabelle}/{$anlass}");
        }

        $stmt = $pdo->prepare(
            'INSERT INTO content_revisions (tenant_id, tabelle, datensatz_id, feld, alter_wert, neuer_wert, anlass, geaendert_von)
             VALUES (:tenant_id, :tabelle, :datensatz_id, :feld, :alter_wert, :neuer_wert, :anlass, :geaendert_von)'
        );

        $anzahl = 0;
        foreach (array_keys(self::FELDER[$tabelle]) as $feld) {
            $alterWert = self::alsText($alt[$feld] ?? null);

            if ($anlass === 'loeschung') {
                if ($alterWert === null) {
                    continue;
                }
                $neuerWert = null;
            } else {
                if (!array_key_exists($feld, $neu)) {
                    continue;
                }
                $neuerWert = self::alsText($neu[$feld]);
                // Unterschied nur im Zeilenende (\r\n gegen \n) ist keine Änderung.
                if ($alterWert === $neuerWert
                    || ($alterWert !== null && $neuerWert !== null
                        && str_replace("\r", '', $alterWert) === str_replace("\r", '', $neuerWert))) {
                    continue;
                }
            }

            $stmt->execute([
                ':tenant_id'     => $tenantId,
                ':tabelle'       => $tabelle,
                ':datensatz_id'  => $datensatzId,
                ':feld'          => $feld,
                ':alter_wert'    => $alterWert,
                ':neuer_wert'    => $neuerWert,
                ':anlass'        => $anlass,
                ':geaendert_von' => $geaendertVon,
            ]);
            $anzahl++;
        }

        return $anzahl;
    }

    /**
     * Alle Versionen eines Datensatzes, neueste zuerst, inkl.
     * Benutzername. $tenantId: bei Tests Pflicht (Mandanten-Scoping),
     * bei Rassen null (geteilter Katalog).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fuerDatensatz(string $tabelle, int $datensatzId, ?int $tenantId): array
    {
        if (!isset(self::FELDER[$tabelle])) {
            return [];
        }

        $pdo = Database::getConnection();
        $sql = 'SELECT r.id, r.feld, r.alter_wert, r.neuer_wert, r.anlass, r.geaendert_am, u.username
                FROM content_revisions r
                LEFT JOIN users u ON u.id = r.geaendert_von
                WHERE r.tabelle = :tabelle AND r.datensatz_id = :id';
        $params = [':tabelle' => $tabelle, ':id' => $datensatzId];

        if ($tenantId === null) {
            $sql .= ' AND r.tenant_id IS NULL';
        } else {
            $sql .= ' AND r.tenant_id = :tenant_id';
            $params[':tenant_id'] = $tenantId;
        }
        $sql .= ' ORDER BY r.geaendert_am DESC, r.id DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Anzahl Versionen eines Datensatzes, für "Versionen (n)"-Links.
     */
    public static function zaehle(string $tabelle, int $datensatzId, ?int $tenantId): int
    {
        $pdo = Database::getConnection();
        $sql = 'SELECT COUNT(*) FROM content_revisions WHERE tabelle = :tabelle AND datensatz_id = :id';
        $params = [':tabelle' => $tabelle, ':id' => $datensatzId];
        if ($tenantId === null) {
            $sql .= ' AND tenant_id IS NULL';
        } else {
            $sql .= ' AND tenant_id = :tenant_id';
            $params[':tenant_id'] = $tenantId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Stammt der aktuelle Wert eines Feldes aus einem Import? Verglichen
     * wird mit dem zuletzt importierten Wert (ohne \r), nicht mit dem
     * Anlass der letzten Version: Ein Speichern ohne inhaltliche Änderung
     * oder eine Wiederherstellung ändert die Herkunft nicht.
     */
    public static function stammtAusImport(string $tabelle, int $datensatzId, string $feld, ?string $aktuell, ?int $tenantId): bool
    {
        if ($aktuell === null || trim($aktuell) === '') {
            return false;
        }
        $sql = "SELECT neuer_wert FROM content_revisions WHERE tabelle = :tabelle AND datensatz_id = :id AND feld = :feld AND anlass = 'import'";
        $params = [':tabelle' => $tabelle, ':id' => $datensatzId, ':feld' => $feld];
        if ($tenantId === null) {
            $sql .= ' AND tenant_id IS NULL';
        } else {
            $sql .= ' AND tenant_id = :tenant_id';
            $params[':tenant_id'] = $tenantId;
        }
        $stmt = Database::getConnection()->prepare($sql . ' ORDER BY id DESC LIMIT 1');
        $stmt->execute($params);
        $importiert = $stmt->fetchColumn();
        return $importiert !== false
            && trim(str_replace("\r", '', (string) $importiert)) === trim(str_replace("\r", '', $aktuell));
    }

    public static function istWiederherstellbar(string $tabelle, string $feld): bool
    {
        if ($tabelle === 'breeds' && str_starts_with($feld, 'text_')) {
            require_once __DIR__ . '/RassenDatenSchreiben.php';
            return RassenDatenSchreiben::textFeld($feld) !== null;
        }
        return isset(self::FELDER[$tabelle][$feld])
            && !in_array($feld, self::NUR_LESBAR[$tabelle] ?? [], true);
    }

    /**
     * Beschriftung eines Feldes für die Versionsseite. Kennt auch die
     * Felder des Merkmalsmodells (text_de_wesen, merkmal_farben …).
     */
    public static function feldLabel(string $tabelle, string $feld): string
    {
        if (isset(self::FELDER[$tabelle][$feld])) {
            return self::FELDER[$tabelle][$feld];
        }
        if ($tabelle === 'breeds') {
            require_once __DIR__ . '/RassenDatenSchreiben.php';
            return RassenDatenSchreiben::feldLabel($feld) ?? $feld;
        }
        return $feld;
    }

    /**
     * Eine Version für ein einzelnes Feld, das nicht in FELDER steht
     * (Merkmalsmodell). Schreibt nur, wenn sich der Wert ändert, Zeilenende
     * nicht mitgezählt. Muss in der Transaktion der Änderung laufen.
     */
    public static function protokolliereFeld(PDO $pdo, string $tabelle, int $datensatzId, string $feld, ?string $alt, ?string $neu, ?int $geaendertVon, string $anlass, ?int $tenantId = null): void
    {
        if (!isset(self::ANLAESSE[$anlass])) {
            throw new InvalidArgumentException("Unbekannter Anlass: {$anlass}");
        }
        if (str_replace("\r", '', (string) $alt) === str_replace("\r", '', (string) $neu) && ($alt === null) === ($neu === null)) {
            return;
        }
        $pdo->prepare(
            'INSERT INTO content_revisions (tenant_id, tabelle, datensatz_id, feld, alter_wert, neuer_wert, anlass, geaendert_von)
             VALUES (:tenant_id, :tabelle, :datensatz_id, :feld, :alter_wert, :neuer_wert, :anlass, :geaendert_von)'
        )->execute([
            ':tenant_id' => $tenantId, ':tabelle' => $tabelle, ':datensatz_id' => $datensatzId, ':feld' => $feld,
            ':alter_wert' => $alt, ':neuer_wert' => $neu, ':anlass' => $anlass, ':geaendert_von' => $geaendertVon,
        ]);
    }

    /**
     * Setzt das Feld einer Version auf den Wert VOR dieser Änderung
     * zurück (alter_wert). Die Wiederherstellung wird selbst wieder als
     * Version gespeichert und lässt sich damit ebenfalls rückgängig
     * machen.
     *
     * @return array{success: bool, message: string}
     */
    public static function wiederherstellen(int $revisionId, string $tabelle, int $datensatzId, ?int $tenantId, int $userId): array
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare(
            'SELECT id, tenant_id, tabelle, datensatz_id, feld, alter_wert FROM content_revisions WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $revisionId]);
        $rev = $stmt->fetch();

        // Version muss zu genau diesem Datensatz und Mandanten gehören,
        // sonst ließe sich über eine fremde Versions-ID ein anderer
        // Datensatz verändern.
        $passt = $rev
            && $rev['tabelle'] === $tabelle
            && (int) $rev['datensatz_id'] === $datensatzId
            && ($tenantId === null ? $rev['tenant_id'] === null : (int) $rev['tenant_id'] === $tenantId);
        if (!$passt) {
            return ['success' => false, 'message' => 'Diese Version wurde nicht gefunden.'];
        }

        return self::setzeFeld($pdo, $tabelle, $datensatzId, $tenantId, (string) $rev['feld'], $rev['alter_wert'], $userId);
    }

    /**
     * Setzt breeds.beschreibung zurück auf beschreibung_original.
     *
     * @return array{success: bool, message: string}
     */
    public static function originalWiederherstellen(int $breedId, int $userId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT beschreibung_original FROM breeds WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $breedId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['success' => false, 'message' => 'Diese Rasse wurde nicht gefunden.'];
        }
        if ($row['beschreibung_original'] === null) {
            return ['success' => false, 'message' => 'Für diese Rasse ist kein Originaltext gespeichert.'];
        }

        return self::setzeFeld($pdo, 'breeds', $breedId, null, 'beschreibung', $row['beschreibung_original'], $userId);
    }

    /**
     * @return array{success: bool, message: string}
     */
    private static function setzeFeld(PDO $pdo, string $tabelle, int $datensatzId, ?int $tenantId, string $feld, ?string $wert, int $userId): array
    {
        if (!self::istWiederherstellbar($tabelle, $feld)) {
            return ['success' => false, 'message' => 'Dieses Feld lässt sich nicht automatisch wiederherstellen.'];
        }
        // Textabschnitte des Merkmalsmodells stehen nicht in breeds
        if ($tabelle === 'breeds' && str_starts_with($feld, 'text_')) {
            return RassenDatenSchreiben::textWiederherstellen($datensatzId, $feld, $wert, $userId);
        }

        $pruefung = self::pruefeWert($pdo, $tabelle, $datensatzId, $tenantId, $feld, $wert);
        if ($pruefung !== null) {
            return ['success' => false, 'message' => $pruefung];
        }

        // Tabellen- und Feldname stammen aus der FELDER-Whitelist (siehe
        // istWiederherstellbar()), nicht aus der Anfrage.
        $tenantSql = $tenantId === null ? '' : ' AND tenant_id = :tenant_id';
        $tenantParam = $tenantId === null ? [] : [':tenant_id' => $tenantId];

        try {
            $pdo->beginTransaction();

            $sel = $pdo->prepare("SELECT `{$feld}` FROM `{$tabelle}` WHERE id = :id{$tenantSql} FOR UPDATE");
            $sel->execute([':id' => $datensatzId] + $tenantParam);
            $aktuell = $sel->fetch();
            if (!$aktuell) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'Der Datensatz existiert nicht mehr. Den Text kannst du aus der Versionsliste kopieren.'];
            }

            if (self::alsText($aktuell[$feld]) === $wert) {
                $pdo->rollBack();
                return ['success' => true, 'message' => 'Dieser Stand ist bereits aktuell, es wurde nichts geändert.'];
            }

            $upd = $pdo->prepare("UPDATE `{$tabelle}` SET `{$feld}` = :wert, updated_at = NOW() WHERE id = :id{$tenantSql}");
            $upd->execute([':wert' => $wert, ':id' => $datensatzId] + $tenantParam);

            self::protokolliere($pdo, $tabelle, $datensatzId, [$feld => $aktuell[$feld]], [$feld => $wert], $userId, 'wiederherstellung', $tenantId);

            $pdo->commit();
            return ['success' => true, 'message' => self::FELDER[$tabelle][$feld] . ' wurde wiederhergestellt.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Fehler bei der Wiederherstellung einer Version: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Beim Wiederherstellen ist ein Fehler aufgetreten. Es wurde nichts geändert.'];
        }
    }

    /**
     * Fachliche Regeln, die auch die Bearbeitungsmasken prüfen. Liefert
     * eine Fehlermeldung oder null.
     */
    private static function pruefeWert(PDO $pdo, string $tabelle, int $datensatzId, ?int $tenantId, string $feld, ?string $wert): ?string
    {
        if ($feld === 'name' && ($wert === null || trim($wert) === '')) {
            return 'Ein leerer Name kann nicht wiederhergestellt werden.';
        }
        if ($tabelle === 'breeds' && $feld === 'fci_klasse' && $wert !== null
            && (!ctype_digit($wert) || (int) $wert < 1 || (int) $wert > 10)) {
            return 'Die FCI-Gruppe muss zwischen 1 und 10 liegen.';
        }
        if ($tabelle === 'tests' && $feld === 'zielgruppe' && !in_array($wert, ['welpe', 'erwachsen', 'beide'], true)) {
            return 'Ungültige Zielgruppe.';
        }
        if ($tabelle === 'breeds' && $feld === 'arbeitspruefung' && $wert !== null && !in_array($wert, ['mit', 'ohne', 'fakultativ'], true)) {
            return 'Ungültiger Wert für die Arbeitsprüfung.';
        }
        if ($tabelle === 'breeds' && $feld === 'gliederung' && !in_array($wert, ['keine', 'automatisch', 'geprueft'], true)) {
            return 'Ungültiger Wert für die Gliederung.';
        }
        if ($tabelle === 'tests' && $feld === 'name') {
            $chk = $pdo->prepare('SELECT id FROM tests WHERE name = :name AND tenant_id = :tenant_id AND id != :id LIMIT 1');
            $chk->execute([':name' => $wert, ':tenant_id' => $tenantId, ':id' => $datensatzId]);
            if ($chk->fetch()) {
                return 'Ein anderer Test heißt inzwischen "' . $wert . '". Bitte erst umbenennen.';
            }
        }
        return null;
    }

    /**
     * Einheitliche Textform für den Vergleich: null bleibt null, Zahlen
     * werden zu Strings, Arrays (Aufgaben-Struktur) zu JSON.
     */
    private static function alsText($wert): ?string
    {
        if ($wert === null) {
            return null;
        }
        if (is_array($wert)) {
            return json_encode($wert, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
        return (string) $wert;
    }
}
