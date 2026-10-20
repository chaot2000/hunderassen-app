<?php
/**
 * models/Datenpruefung.php
 *
 * Lesende Prüfungen für die Fehlersuche (Issue #26), siehe
 * admin_datenpruefung.php.
 *
 * - Katalog: der geteilte Rassenkatalog, nur für den Super-Admin.
 * - Organisation: Hunde, Halter und Züchter der eigenen Organisation,
 *   für alle Admins. Jede Abfrage filtert auf tenant_id, fremde Daten
 *   tauchen nicht auf, auch nicht als Zahl.
 *
 * Jede Prüfung liefert Zeilen mit id, name, problem und link. Nichts
 * wird geändert.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Groessenklasse.php';

class Datenpruefung
{
    /** Pflichtmerkmale für die Sammelprüfung (Entscheidung 17.10.2026). */
    public const PFLICHTMERKMALE = [
        'schulterhoehe', 'gewicht', 'groessenklasse', 'lebenserwartung', 'ursprungsland', 'farben', 'fellart',
    ];

    /** Textabschnitte, die jede Rasse in DE und EN haben soll. */
    public const TEXTABSCHNITTE = ['kurzportraet', 'wesen', 'haltung', 'pflege', 'aussehen', 'herkunft'];

    /** Grenzen für „unplausible Werte“. */
    private const HOEHE_MIN_CM   = 10;
    private const HOEHE_MAX_CM   = 110;
    private const GEWICHT_MAX_KG = 120;


    /** Prüfungen des Rassenkatalogs: Schlüssel => Sprachschlüssel des Titels. */
    public const KATALOG = [
        'ohne_bild'         => 'dp.k_ohne_bild',
        'pflichtmerkmale'   => 'dp.k_pflichtmerkmale',
        'merkmal'           => 'dp.k_merkmal',
        'texte'             => 'dp.k_texte',
        'ohne_name_en'      => 'dp.k_ohne_name_en',
        'ohne_fci_nr'       => 'dp.k_ohne_fci_nr',
        'namen'             => 'dp.k_namen',
        'doppel'            => 'dp.k_doppel',
        'unplausibel'       => 'dp.k_unplausibel',
    ];

    /** Prüfungen der eigenen Organisation. */
    public const ORGANISATION = [
        'hund_ohne_bild'     => 'dp.o_hund_ohne_bild',
        'hund_ohne_rasse'    => 'dp.o_hund_ohne_rasse',
        'hund_ohne_geburt'   => 'dp.o_hund_ohne_geburt',
        'hund_ohne_halter'   => 'dp.o_hund_ohne_halter',
        'halter_ohne_kontakt' => 'dp.o_halter_ohne_kontakt',
        'zuechter_ohne_kontakt' => 'dp.o_zuechter_ohne_kontakt',
    ];

    /**
     * Ergebniszeilen einer Katalogprüfung. Nur für den Super-Admin.
     *
     * @param string|null $merkmal Schlüssel für die Prüfung „merkmal“
     * @return array<int, array{id: int, name: string, problem: string, link: string}>
     */
    public static function katalog(string $pruefung, ?string $merkmal = null): array
    {
        if (!function_exists('is_super_admin') || !is_super_admin()) {
            return [];
        }
        $pdo = Database::getConnection();

        switch ($pruefung) {
            case 'ohne_bild':
                $rows = $pdo->query('SELECT id, name FROM breeds WHERE bild_blob IS NULL ORDER BY name')->fetchAll();
                return self::rassen($rows, fn($r) => t('dp.p_kein_bild'));

            case 'ohne_name_en':
                $rows = $pdo->query("SELECT id, name FROM breeds WHERE name_en IS NULL OR TRIM(name_en) = '' ORDER BY name")->fetchAll();
                return self::rassen($rows, fn($r) => t('dp.p_kein_name_en'));

            case 'ohne_fci_nr':
                $rows = $pdo->query("SELECT id, name FROM breeds WHERE fci_nr IS NULL OR TRIM(fci_nr) = '' ORDER BY name")->fetchAll();
                return self::rassen($rows, fn($r) => t('dp.p_keine_fci_nr'));

            case 'namen':
                $rows = $pdo->query(
                    "SELECT id, name FROM breeds
                     WHERE name LIKE '%…%' OR name LIKE '%  %' OR name LIKE '%,%' OR name <> TRIM(name)
                     ORDER BY name"
                )->fetchAll();
                return self::rassen($rows, function ($r) {
                    $n = (string) $r['name'];
                    if (str_contains($n, '…')) {
                        return t('dp.p_name_abgeschnitten');
                    }
                    if (str_contains($n, '  ') || $n !== trim($n)) {
                        return t('dp.p_name_leerzeichen');
                    }
                    return t('dp.p_name_komma');
                });

            case 'doppel':
                return self::doppel($pdo);

            case 'pflichtmerkmale':
                return self::fehlendeMerkmale($pdo, self::PFLICHTMERKMALE);

            case 'merkmal':
                $alle = self::merkmalNamen();
                if ($merkmal === null || !isset($alle[$merkmal])) {
                    $merkmal = 'schulterhoehe';
                }
                return self::fehlendeMerkmale($pdo, [$merkmal]);

            case 'texte':
                return self::fehlendeTexte($pdo);

            case 'unplausibel':
                return self::unplausibel($pdo);
        }
        return [];
    }

    /**
     * Ergebniszeilen einer Prüfung der eigenen Organisation.
     *
     * @return array<int, array{id: int, name: string, problem: string, link: string}>
     */
    public static function organisation(string $pruefung, int $tenantId): array
    {
        $pdo = Database::getConnection();
        $sql = match ($pruefung) {
            'hund_ohne_bild'   => 'SELECT id, name FROM dogs WHERE tenant_id = :t AND bild_blob IS NULL ORDER BY name',
            'hund_ohne_rasse'  => 'SELECT id, name FROM dogs WHERE tenant_id = :t AND breed_id IS NULL ORDER BY name',
            'hund_ohne_geburt' => 'SELECT id, name FROM dogs WHERE tenant_id = :t AND geburtsdatum IS NULL ORDER BY name',
            'hund_ohne_halter' => 'SELECT d.id, d.name FROM dogs d LEFT JOIN dog_halter dh ON dh.dog_id = d.id
                                   WHERE d.tenant_id = :t AND dh.dog_id IS NULL ORDER BY d.name',
            'halter_ohne_kontakt' => "SELECT id, name FROM halter WHERE tenant_id = :t
                                      AND (telefon IS NULL OR TRIM(telefon) = '') AND (email IS NULL OR TRIM(email) = '') ORDER BY name",
            'zuechter_ohne_kontakt' => "SELECT id, zuchtname AS name FROM zuechter WHERE tenant_id = :t
                                        AND (telefon IS NULL OR TRIM(telefon) = '') AND (email IS NULL OR TRIM(email) = '') ORDER BY zuchtname",
            default => null,
        };
        if ($sql === null) {
            return [];
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':t' => $tenantId]);

        [$link, $problem] = match ($pruefung) {
            'hund_ohne_bild'        => ['/dog_form.php?id=', t('dp.p_kein_bild')],
            'hund_ohne_rasse'       => ['/dog_form.php?id=', t('dp.p_keine_rasse')],
            'hund_ohne_geburt'      => ['/dog_form.php?id=', t('dp.p_kein_geburtsdatum')],
            'hund_ohne_halter'      => ['/dog_form.php?id=', t('dp.p_kein_halter')],
            'halter_ohne_kontakt'   => ['/halter_form.php?id=', t('dp.p_kein_kontakt')],
            'zuechter_ohne_kontakt' => ['/zuechter_form.php?id=', t('dp.p_kein_kontakt')],
        };
        return array_map(fn($r) => [
            'id'      => (int) $r['id'],
            'name'    => (string) $r['name'],
            'problem' => $problem,
            'link'    => $link . (int) $r['id'],
        ], $stmt->fetchAll());
    }

    /**
     * Vollständigkeit je Merkmal: wie viele Rassen haben einen Wert.
     * Nur für den Super-Admin.
     *
     * @return array<int, array{schluessel: string, name: string, mit: int, gesamt: int, prozent: int}>
     */
    public static function vollstaendigkeit(): array
    {
        if (!function_exists('is_super_admin') || !is_super_admin()) {
            return [];
        }
        $pdo = Database::getConnection();
        $gesamt = (int) $pdo->query('SELECT COUNT(*) FROM breeds')->fetchColumn();
        $rows = $pdo->query(
            'SELECT m.schluessel, COUNT(DISTINCT rm.breed_id) AS mit
             FROM merkmale m LEFT JOIN rasse_merkmale rm ON rm.merkmal_id = m.id
             GROUP BY m.id, m.schluessel, m.reihenfolge
             ORDER BY m.reihenfolge, m.id'
        )->fetchAll();
        $namen = self::merkmalNamen();
        return array_map(fn($r) => [
            'schluessel' => (string) $r['schluessel'],
            'name'       => $namen[$r['schluessel']] ?? (string) $r['schluessel'],
            'mit'        => (int) $r['mit'],
            'gesamt'     => $gesamt,
            'prozent'    => $gesamt > 0 ? (int) round(100 * (int) $r['mit'] / $gesamt) : 0,
        ], $rows);
    }

    /**
     * Merkmale nach Schlüssel mit Namen in der aktuellen Sprache.
     *
     * @return array<string, string>
     */
    public static function merkmalNamen(): array
    {
        $englisch = function_exists('aktuelle_sprache') && aktuelle_sprache() === 'en';
        $rows = Database::getConnection()->query('SELECT schluessel, name_de, name_en FROM merkmale ORDER BY reihenfolge, id')->fetchAll();
        $namen = [];
        foreach ($rows as $r) {
            $namen[(string) $r['schluessel']] = $englisch && $r['name_en'] ? (string) $r['name_en'] : (string) $r['name_de'];
        }
        return $namen;
    }

    // -------------------------------------------------------------
    // Private Hilfsmethoden
    // -------------------------------------------------------------

    /** Rassenzeilen ins Ergebnisformat bringen. */
    private static function rassen(array $rows, callable $problem): array
    {
        return array_map(fn($r) => [
            'id'      => (int) $r['id'],
            'name'    => (string) $r['name'],
            'problem' => (string) $problem($r),
            'link'    => '/admin_add_breed.php?id=' . (int) $r['id'],
        ], $rows);
    }

    /** Rassen, denen mindestens eines der Merkmale fehlt. Eine Zeile je Rasse. */
    private static function fehlendeMerkmale(PDO $pdo, array $schluessel): array
    {
        $namen = self::merkmalNamen();
        $platz = implode(',', array_fill(0, count($schluessel), '?'));
        $stmt = $pdo->prepare(
            "SELECT b.id, b.name, m.schluessel
             FROM breeds b
             CROSS JOIN merkmale m
             LEFT JOIN rasse_merkmale rm ON rm.breed_id = b.id AND rm.merkmal_id = m.id
             WHERE m.schluessel IN ({$platz}) AND rm.id IS NULL
             ORDER BY b.name, m.reihenfolge, m.id"
        );
        $stmt->execute(array_values($schluessel));

        $proRasse = [];
        foreach ($stmt->fetchAll() as $r) {
            $id = (int) $r['id'];
            $proRasse[$id]['name'] = (string) $r['name'];
            $proRasse[$id]['fehlt'][] = $namen[$r['schluessel']] ?? (string) $r['schluessel'];
        }
        $out = [];
        foreach ($proRasse as $id => $d) {
            $out[] = [
                'id'      => $id,
                'name'    => $d['name'],
                'problem' => t('dp.p_fehlt', ['was' => implode(', ', $d['fehlt'])]),
                'link'    => '/admin_add_breed.php?id=' . $id,
            ];
        }
        return $out;
    }

    /** Rassen mit Textabschnitten, die fehlen oder nur in einer Sprache vorhanden sind. */
    private static function fehlendeTexte(PDO $pdo): array
    {
        $vorhanden = [];
        $rows = $pdo->query("SELECT breed_id, sprache, abschnitt FROM rasse_texte WHERE TRIM(text) <> ''")->fetchAll();
        foreach ($rows as $r) {
            $vorhanden[(int) $r['breed_id']][(string) $r['abschnitt']][(string) $r['sprache']] = true;
        }

        $out = [];
        foreach ($pdo->query('SELECT id, name FROM breeds ORDER BY name')->fetchAll() as $b) {
            $id = (int) $b['id'];
            $probleme = [];
            foreach (self::TEXTABSCHNITTE as $abschnitt) {
                $de = isset($vorhanden[$id][$abschnitt]['de']);
                $en = isset($vorhanden[$id][$abschnitt]['en']);
                if ($de && $en) {
                    continue;
                }
                $label = t('abschnitt.' . $abschnitt);
                $probleme[] = $label . ': ' . (!$de && !$en ? t('dp.p_text_fehlt') : ($de ? t('dp.p_text_nur_de') : t('dp.p_text_nur_en')));
            }
            if ($probleme) {
                $out[] = [
                    'id'      => $id,
                    'name'    => (string) $b['name'],
                    'problem' => implode('; ', $probleme),
                    'link'    => '/admin_add_breed.php?id=' . $id,
                ];
            }
        }
        return $out;
    }

    /** Rassen mit gleichem Namen oder gleichem englischen Namen. */
    private static function doppel(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT b.id, b.name, 'name' AS art FROM breeds b
             JOIN (SELECT LOWER(TRIM(name)) AS n FROM breeds GROUP BY LOWER(TRIM(name)) HAVING COUNT(*) > 1) d
               ON d.n = LOWER(TRIM(b.name))
             UNION
             SELECT b.id, b.name, 'name_en' AS art FROM breeds b
             JOIN (SELECT LOWER(TRIM(name_en)) AS n FROM breeds WHERE name_en IS NOT NULL AND TRIM(name_en) <> ''
                   GROUP BY LOWER(TRIM(name_en)) HAVING COUNT(*) > 1) d
               ON d.n = LOWER(TRIM(b.name_en))
             ORDER BY name, id"
        )->fetchAll();
        return self::rassen($rows, fn($r) => $r['art'] === 'name' ? t('dp.p_doppel_name') : t('dp.p_doppel_name_en'));
    }

    /** Schulterhöhe oder Gewicht außerhalb der Grenzen, Von größer als Bis, Größenklasse passt nicht. */
    private static function unplausibel(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT b.id, b.name, m.schluessel, rm.zahl_von, rm.zahl_bis, w.schluessel AS wert
             FROM rasse_merkmale rm
             JOIN merkmale m ON m.id = rm.merkmal_id
             JOIN breeds b ON b.id = rm.breed_id
             LEFT JOIN merkmal_werte w ON w.id = rm.wert_id
             WHERE m.schluessel IN ('schulterhoehe', 'gewicht', 'groessenklasse')"
        )->fetchAll();

        $daten = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $daten[$id]['name'] = (string) $r['name'];
            if ($r['schluessel'] === 'groessenklasse') {
                $daten[$id]['klassen'][] = (string) $r['wert'];
                continue;
            }
            $von = $r['zahl_von'] !== null ? (float) $r['zahl_von'] : null;
            $bis = $r['zahl_bis'] !== null ? (float) $r['zahl_bis'] : null;
            $daten[$id][$r['schluessel']][] = [$von, $bis];
        }

        $out = [];
        foreach ($daten as $id => $d) {
            $probleme = [];
            $hoeheMax = null;
            foreach ($d['schulterhoehe'] ?? [] as [$von, $bis]) {
                if ($von !== null && $bis !== null && $von > $bis) {
                    $probleme[] = t('dp.p_von_bis', ['was' => t('dp.schulterhoehe')]);
                }
                foreach ([$von, $bis] as $wert) {
                    if ($wert === null) {
                        continue;
                    }
                    $hoeheMax = max($hoeheMax ?? $wert, $wert);
                    if ($wert < self::HOEHE_MIN_CM || $wert > self::HOEHE_MAX_CM) {
                        $probleme[] = t('dp.p_hoehe', ['wert' => self::zahl($wert)]);
                    }
                }
            }
            foreach ($d['gewicht'] ?? [] as [$von, $bis]) {
                if ($von !== null && $bis !== null && $von > $bis) {
                    $probleme[] = t('dp.p_von_bis', ['was' => t('dp.gewicht')]);
                }
                foreach ([$von, $bis] as $wert) {
                    if ($wert !== null && ($wert <= 0 || $wert > self::GEWICHT_MAX_KG)) {
                        $probleme[] = t('dp.p_gewicht', ['wert' => self::zahl($wert)]);
                    }
                }
            }
            // Grenzen wie bei der Berechnung, auffallen kann nur eine von Hand gesetzte Klasse
            if ($hoeheMax !== null && !empty($d['klassen'])) {
                $passt = (bool) array_intersect($d['klassen'], Groessenklasse::ausSpannen($d['schulterhoehe'] ?? []));
                if (!$passt) {
                    $probleme[] = t('dp.p_groessenklasse', ['wert' => self::zahl($hoeheMax)]);
                }
            }
            if ($probleme) {
                $out[] = [
                    'id'      => $id,
                    'name'    => $d['name'],
                    'problem' => implode('; ', array_unique($probleme)),
                    'link'    => '/admin_add_breed.php?id=' . $id,
                ];
            }
        }
        usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
        return $out;
    }

    private static function zahl(float $wert): string
    {
        return rtrim(rtrim(number_format($wert, 1, ',', ''), '0'), ',');
    }
}
