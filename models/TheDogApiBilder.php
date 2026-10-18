<?php
/**
 * models/TheDogApiBilder.php
 *
 * Rassenbilder aus TheDogAPI: Kandidaten je Rasse holen und ein
 * ausgewähltes Bild laden und verkleinern (admin_rassenbilder.php,
 * admin_thedogapi_apply.php).
 *
 * Die Rassenliste /v1/breeds enthält keine Bildreferenzen mehr
 * (reference_image_id ist überall null, Stand Oktober 2026). Bilder je
 * Rasse gibt es nur über /v1/images/search?breed_ids=… und nur mit
 * Schlüssel. Ohne Schlüssel ignoriert die API den Filter und liefert
 * irgendein Hundebild, deshalb wird ohne Schlüssel gar nicht gesucht.
 *
 * Gesucht wird nur nach Bildern mit Rassenangabe (has_breeds), und die
 * Angabe muss die gesuchte Rasse nennen (auswerten()).
 *
 * Kandidaten werden je Rasse eine Woche in cache_thedogapi_bilder.json
 * gehalten. Das schont das Abrufkontingent, und beim Übernehmen kommt
 * die Bildadresse aus diesem Cache statt aus dem Formular.
 */

declare(strict_types=1);

require_once __DIR__ . '/TheDogApiFelder.php';
require_once __DIR__ . '/ExternesBild.php';

class TheDogApiBilder
{
    public const ANZAHL = 6;
    private const URL = 'https://api.thedogapi.com/v1/images/search';
    private const CACHE_DATEI = __DIR__ . '/../cache_thedogapi_bilder.json';
    private const CACHE_MAX_ALTER_SEK = 7 * 86400;
    // Aufbau der Cache-Einträge. Ältere Einträge (ohne Rassenangabe je
    // Bild) gelten als veraltet und werden neu abgerufen.
    private const CACHE_VERSION = 2;

    /**
     * Bildkandidaten für eine TheDogAPI-Rasse.
     *
     * @return array{bilder: list<array{id: string, url: string, breite: int, hoehe: int}>, fehler: ?string}
     */
    public static function kandidaten(string $thedogapiId): array
    {
        if (!preg_match('/^\d{1,10}$/', $thedogapiId)) {
            return ['bilder' => [], 'fehler' => 'Ungültige TheDogAPI-Zuordnung.'];
        }
        $cache = self::cacheLesen();
        $eintrag = $cache[$thedogapiId] ?? null;
        if (is_array($eintrag) && ($eintrag['v'] ?? 1) === self::CACHE_VERSION
            && time() - (int) ($eintrag['zeit'] ?? 0) < self::CACHE_MAX_ALTER_SEK) {
            return ['bilder' => $eintrag['bilder'] ?? [], 'fehler' => null];
        }

        $key = TheDogApiFelder::schluessel();
        if ($key === '') {
            return ['bilder' => [], 'fehler' => 'Kein TheDogAPI-Schlüssel hinterlegt. Lege config/thedogapi.local.php nach der Vorlage config/thedogapi.local.example.php an.'];
        }
        $ch = curl_init(self::URL . '?' . http_build_query([
            'breed_ids' => $thedogapiId, 'limit' => self::ANZAHL, 'order' => 'ASC', 'has_breeds' => 1, 'include_breeds' => 1,
        ]));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['x-api-key: ' . $key], CURLOPT_TIMEOUT => 15]);
        $antwort = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $daten = is_string($antwort) && $status === 200 ? json_decode($antwort, true) : null;
        if (!is_array($daten)) {
            return ['bilder' => [], 'fehler' => 'Fehler beim Abruf von TheDogAPI (HTTP ' . $status . ').'];
        }

        $bilder = self::auswerten($daten, $thedogapiId);
        $cache[$thedogapiId] = ['v' => self::CACHE_VERSION, 'zeit' => time(), 'bilder' => $bilder];
        file_put_contents(self::CACHE_DATEI, json_encode($cache, JSON_UNESCAPED_SLASHES), LOCK_EX);
        return ['bilder' => $bilder, 'fehler' => null];
    }

    /**
     * Antwort von /v1/images/search prüfen: nur Bilder von TheDogAPI mit
     * gültiger ID, die laut TheDogAPI die gesuchte Rasse zeigen. Bilder
     * ohne Rassenangabe fallen weg, das waren oft beliebige Hunde.
     * "rasse" ist der englische Name aus der Rassenangabe des Bildes.
     *
     * @return list<array{id: string, url: string, breite: int, hoehe: int, rasse: string}>
     */
    public static function auswerten(array $daten, string $thedogapiId): array
    {
        $bilder = [];
        foreach ($daten as $b) {
            if (!is_array($b)) {
                continue;
            }
            $id = (string) ($b['id'] ?? '');
            $url = (string) ($b['url'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id) || !self::istTheDogApiUrl($url)) {
                continue;
            }
            $rasse = null;
            foreach (is_array($b['breeds'] ?? null) ? $b['breeds'] : [] as $r) {
                if (is_array($r) && (string) ($r['id'] ?? '') === $thedogapiId) {
                    $rasse = trim((string) ($r['name'] ?? ''));
                    break;
                }
            }
            if ($rasse === null) {
                continue;
            }
            $bilder[$id] ??= ['id' => $id, 'url' => $url, 'breite' => (int) ($b['width'] ?? 0), 'hoehe' => (int) ($b['height'] ?? 0), 'rasse' => $rasse];
        }
        return array_values($bilder);
    }

    /**
     * Kandidaten dieser Rassen aus dem Cache werfen, damit beim nächsten
     * Anzeigen neu bei TheDogAPI gesucht wird.
     *
     * @param list<string> $thedogapiIds
     */
    public static function vergessen(array $thedogapiIds): void
    {
        $cache = self::cacheLesen();
        foreach ($thedogapiIds as $id) {
            unset($cache[(string) $id]);
        }
        file_put_contents(self::CACHE_DATEI, json_encode($cache, JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /** Ein Kandidat aus dem Cache, nur wenn er zur Rasse gehört. */
    public static function kandidat(string $thedogapiId, string $bildId): ?array
    {
        foreach (self::kandidaten($thedogapiId)['bilder'] as $b) {
            if ($b['id'] === $bildId) {
                return $b;
            }
        }
        return null;
    }

    /** Wert für breeds.bild_quelle. */
    public static function quelle(string $bildId): string
    {
        return 'TheDogAPI:' . $bildId;
    }

    /** Bild-ID aus einer CDN-Adresse wie https://cdn2.thedogapi.com/images/BJa4kxc4X.jpg */
    public static function idAusUrl(string $url): ?string
    {
        $name = pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);
        $name = preg_replace('/_\d+$/', '', $name);
        return preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string) $name) ? $name : null;
    }

    public static function istTheDogApiUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return parse_url($url, PHP_URL_SCHEME) === 'https' && (bool) preg_match('/(^|\.)thedogapi\.com$/', $host);
    }

    /** Lädt ein Bild von TheDogAPI und verkleinert es (models/ExternesBild.php). */
    public static function laden(string $url): array
    {
        return self::istTheDogApiUrl($url)
            ? ExternesBild::laden($url)
            : ['success' => false, 'message' => 'Bildadresse ist nicht von TheDogAPI.'];
    }

    private static function cacheLesen(): array
    {
        $daten = is_file(self::CACHE_DATEI) ? json_decode((string) file_get_contents(self::CACHE_DATEI), true) : null;
        return is_array($daten) ? $daten : [];
    }
}
