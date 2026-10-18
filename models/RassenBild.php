<?php
/**
 * models/RassenBild.php
 *
 * Bild einer Rasse setzen, entfernen, zurückstellen und die TheDogAPI-
 * Zuordnung ändern. Gemeinsame Schreibstelle für admin_rassenbilder.php
 * (Liste) und admin_rassenbild.php (Auswahl je Rasse).
 *
 * Zum Bild gehören Herkunft (bild_quelle, z. B. "TheDogAPI:<id>",
 * "Upload") und Bildnachweis (bild_urheber, bild_lizenz,
 * bild_lizenz_url). Ein neues Bild hebt ein Zurückstellen auf.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ContentRevision.php';

class RassenBild
{
    /** Herkunftsarten für Filter und Anzeige, nach dem Präfix von bild_quelle. */
    public const QUELLEN = ['thedogapi' => 'TheDogAPI', 'wikimedia' => 'Wikimedia', 'upload' => 'Eigenes Bild'];

    /**
     * Alle Rassen für die Liste, ohne Bilddaten.
     *
     * @return list<array<string, mixed>>
     */
    public static function liste(): array
    {
        return Database::getConnection()->query(
            'SELECT id, name, name_en, alternative_namen, thedogapi_id, wikidata_id,
                    bild_blob IS NOT NULL AS hat_bild, bild_updated_at, bild_quelle, bild_urheber, bild_zurueckgestellt
             FROM breeds ORDER BY name'
        )->fetchAll();
    }

    public static function finde(int $id): ?array
    {
        $stmt = Database::getConnection()->prepare(
            'SELECT id, name, name_en, alternative_namen, thedogapi_id, wikidata_id, wikipedia_de,
                    bild_blob IS NOT NULL AS hat_bild, bild_updated_at, bild_quelle, bild_urheber, bild_lizenz,
                    bild_lizenz_url, bild_zurueckgestellt
             FROM breeds WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Herkunftsart aus bild_quelle: thedogapi, wikimedia, upload oder '' (unbekannt). */
    public static function quellenArt(?string $quelle): string
    {
        $praefix = strtolower(strtok((string) $quelle, ':') ?: '');
        return match ($praefix) {
            'thedogapi' => 'thedogapi',
            'wikimedia' => 'wikimedia',
            'upload'    => 'upload',
            default     => '',
        };
    }

    /**
     * Bild speichern. Leere Nachweis-Felder werden zu NULL.
     *
     * @param array{urheber?: ?string, lizenz?: ?string, lizenz_url?: ?string} $nachweis
     */
    public static function speichern(int $id, string $blob, string $mime, string $quelle, array $nachweis = []): bool
    {
        $leer = fn(?string $v, int $max): ?string => ($v = trim((string) $v)) === '' ? null : mb_substr($v, 0, $max);
        $stmt = Database::getConnection()->prepare(
            'UPDATE breeds SET bild_blob = :blob, bild_mime = :mime, bild_updated_at = NOW(), bild_quelle = :quelle,
                    bild_urheber = :urheber, bild_lizenz = :lizenz, bild_lizenz_url = :lizenz_url, bild_zurueckgestellt = NULL
             WHERE id = :id'
        );
        $stmt->execute([
            ':blob' => $blob, ':mime' => $mime, ':quelle' => mb_substr($quelle, 0, 100),
            ':urheber' => $leer($nachweis['urheber'] ?? null, 255),
            ':lizenz' => $leer($nachweis['lizenz'] ?? null, 50),
            ':lizenz_url' => $leer($nachweis['lizenz_url'] ?? null, 255),
            ':id' => $id,
        ]);
        return $stmt->rowCount() === 1;
    }

    public static function entfernen(int $id): void
    {
        Database::getConnection()->prepare(
            'UPDATE breeds SET bild_blob = NULL, bild_mime = NULL, bild_updated_at = NULL, bild_quelle = NULL,
                    bild_urheber = NULL, bild_lizenz = NULL, bild_lizenz_url = NULL
             WHERE id = :id'
        )->execute([':id' => $id]);
    }

    /** Rasse nicht mehr als "ohne Bild" anbieten, weil keine Quelle etwas Passendes hat. */
    public static function zurueckstellen(int $id): void
    {
        Database::getConnection()->prepare('UPDATE breeds SET bild_zurueckgestellt = NOW() WHERE id = :id AND bild_zurueckgestellt IS NULL')
            ->execute([':id' => $id]);
    }

    public static function anbieten(int $id): void
    {
        Database::getConnection()->prepare('UPDATE breeds SET bild_zurueckgestellt = NULL WHERE id = :id')
            ->execute([':id' => $id]);
    }

    /**
     * TheDogAPI-Zuordnung setzen oder mit '' entfernen, als Version
     * gespeichert wie im TheDogAPI-Abgleich.
     */
    public static function theDogApiZuordnen(int $id, string $thedogapiId, int $userId): bool
    {
        if ($thedogapiId !== '' && !preg_match('/^\d{1,10}$/', $thedogapiId)) {
            return false;
        }
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $alt = $pdo->prepare('SELECT thedogapi_id FROM breeds WHERE id = :id FOR UPDATE');
            $alt->execute([':id' => $id]);
            $vorher = $alt->fetch();
            if (!$vorher) {
                $pdo->rollBack();
                return false;
            }
            $neu = $thedogapiId === '' ? null : $thedogapiId;
            $pdo->prepare('UPDATE breeds SET thedogapi_id = :t, thedogapi_stand = NOW() WHERE id = :id')
                ->execute([':t' => $neu, ':id' => $id]);
            ContentRevision::protokolliere($pdo, 'breeds', $id, ['thedogapi_id' => $vorher['thedogapi_id']], ['thedogapi_id' => $neu], $userId, 'bearbeitung');
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Wikidata-Zuordnung setzen (mit Titel des deutschen Wikipedia-Artikels)
     * oder mit '' entfernen, als Version gespeichert.
     */
    public static function wikidataZuordnen(int $id, string $qid, string $wikipediaDe, int $userId): bool
    {
        if ($qid !== '' && !preg_match('/^Q[1-9]\d{0,11}$/', $qid)) {
            return false;
        }
        $neu = [
            'wikidata_id'  => $qid === '' ? null : $qid,
            'wikipedia_de' => $qid === '' || trim($wikipediaDe) === '' ? null : mb_substr(trim($wikipediaDe), 0, 255),
        ];
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $alt = $pdo->prepare('SELECT wikidata_id, wikipedia_de FROM breeds WHERE id = :id FOR UPDATE');
            $alt->execute([':id' => $id]);
            $vorher = $alt->fetch();
            if (!$vorher) {
                $pdo->rollBack();
                return false;
            }
            $pdo->prepare('UPDATE breeds SET wikidata_id = :q, wikipedia_de = :w WHERE id = :id')
                ->execute([':q' => $neu['wikidata_id'], ':w' => $neu['wikipedia_de'], ':id' => $id]);
            ContentRevision::protokolliere($pdo, 'breeds', $id, $vorher, $neu, $userId, 'bearbeitung');
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Bildnachweis für die Anzeige: Text, Link zur Bildseite, Lizenz mit
     * Link. Null, wenn nichts bekannt ist.
     *
     * @return array{urheber: string, quelle: string, link: string, lizenz: string, lizenz_url: string}|null
     */
    public static function nachweis(array $rasse): ?array
    {
        $quelle = (string) ($rasse['bild_quelle'] ?? '');
        $urheber = trim((string) ($rasse['bild_urheber'] ?? ''));
        $art = self::quellenArt($quelle);
        if ($art === 'wikimedia') {
            $datei = substr($quelle, strlen('Wikimedia:'));
            return [
                'urheber' => $urheber, 'quelle' => 'Wikimedia Commons',
                'link' => 'https://commons.wikimedia.org/wiki/File:' . rawurlencode(str_replace(' ', '_', $datei)),
                'lizenz' => (string) ($rasse['bild_lizenz'] ?? ''), 'lizenz_url' => (string) ($rasse['bild_lizenz_url'] ?? ''),
            ];
        }
        if ($art === 'thedogapi') {
            return ['urheber' => $urheber, 'quelle' => 'TheDogAPI', 'link' => 'https://thedogapi.com', 'lizenz' => '', 'lizenz_url' => ''];
        }
        if ($urheber !== '') {
            return ['urheber' => $urheber, 'quelle' => '', 'link' => '', 'lizenz' => (string) ($rasse['bild_lizenz'] ?? ''), 'lizenz_url' => (string) ($rasse['bild_lizenz_url'] ?? '')];
        }
        return null;
    }

    /** Bildnachweis als eine Zeile, z. B. für einen Tooltip. */
    public static function nachweisText(array $rasse): string
    {
        $n = self::nachweis($rasse);
        return $n === null ? '' : 'Foto: ' . implode(' · ', array_filter([$n['urheber'], $n['lizenz'], $n['quelle']]));
    }

    /**
     * Andere Rassen mit derselben TheDogAPI-Nummer.
     *
     * @return list<string>
     */
    public static function gleicheTheDogApi(int $id, string $thedogapiId): array
    {
        if ($thedogapiId === '') {
            return [];
        }
        $stmt = Database::getConnection()->prepare('SELECT name FROM breeds WHERE thedogapi_id = :t AND id <> :id ORDER BY name');
        $stmt->execute([':t' => $thedogapiId, ':id' => $id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
