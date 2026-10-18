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
 * Kandidaten werden je Rasse eine Woche in cache_thedogapi_bilder.json
 * gehalten. Das schont das Abrufkontingent, und beim Übernehmen kommt
 * die Bildadresse aus diesem Cache statt aus dem Formular.
 */

declare(strict_types=1);

require_once __DIR__ . '/TheDogApiFelder.php';

class TheDogApiBilder
{
    public const ANZAHL = 6;
    private const URL = 'https://api.thedogapi.com/v1/images/search';
    private const CACHE_DATEI = __DIR__ . '/../cache_thedogapi_bilder.json';
    private const CACHE_MAX_ALTER_SEK = 7 * 86400;
    private const MAX_BREITE = 1200;

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
        if (is_array($eintrag) && time() - (int) ($eintrag['zeit'] ?? 0) < self::CACHE_MAX_ALTER_SEK) {
            return ['bilder' => $eintrag['bilder'] ?? [], 'fehler' => null];
        }

        $key = TheDogApiFelder::schluessel();
        if ($key === '') {
            return ['bilder' => [], 'fehler' => 'Kein TheDogAPI-Schlüssel hinterlegt. Lege config/thedogapi.local.php nach der Vorlage config/thedogapi.local.example.php an.'];
        }
        $ch = curl_init(self::URL . '?' . http_build_query([
            'breed_ids' => $thedogapiId, 'limit' => self::ANZAHL, 'order' => 'ASC', 'include_breeds' => 1,
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
        $cache[$thedogapiId] = ['zeit' => time(), 'bilder' => $bilder];
        file_put_contents(self::CACHE_DATEI, json_encode($cache, JSON_UNESCAPED_SLASHES), LOCK_EX);
        return ['bilder' => $bilder, 'fehler' => null];
    }

    /**
     * Antwort von /v1/images/search prüfen: nur Bilder von TheDogAPI mit
     * gültiger ID. Nennt ein Bild Rassen, muss die gesuchte dabei sein.
     *
     * @return list<array{id: string, url: string, breite: int, hoehe: int}>
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
            if (!empty($b['breeds']) && is_array($b['breeds'])
                && !in_array($thedogapiId, array_map(fn($r) => (string) ($r['id'] ?? ''), $b['breeds']), true)) {
                continue;
            }
            $bilder[$id] ??= ['id' => $id, 'url' => $url, 'breite' => (int) ($b['width'] ?? 0), 'hoehe' => (int) ($b['height'] ?? 0)];
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

    /**
     * Lädt ein Bild von TheDogAPI, prüft das Format anhand der Bytes,
     * verkleinert auf höchstens 1200 px Breite und kodiert neu, wie bei
     * eigenen Uploads (BildVerarbeitung).
     *
     * @return array{success: bool, blob?: string, mime?: string, message?: string}
     */
    public static function laden(string $url): array
    {
        // Nur Bilder von TheDogAPI laden, sonst ließe sich der Server auf
        // beliebige Adressen schicken.
        if (!self::istTheDogApiUrl($url)) {
            return ['success' => false, 'message' => 'Bildadresse ist nicht von TheDogAPI.'];
        }
        $tmpDatei = tempnam(sys_get_temp_dir(), 'tdapi_');
        if ($tmpDatei === false) {
            return ['success' => false, 'message' => 'Konnte keine temporäre Datei anlegen.'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            // Weiterleitungen nur innerhalb von HTTPS, höchstens drei.
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $bildDaten = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($bildDaten === false || $httpCode !== 200) {
            @unlink($tmpDatei);
            return ['success' => false, 'message' => 'Download fehlgeschlagen (HTTP ' . $httpCode . ')'];
        }

        file_put_contents($tmpDatei, $bildDaten);
        // BildVerarbeitung::verarbeiteUpload() verlangt einen echten
        // HTTP-Upload (is_uploaded_file), deshalb hier dieselben Regeln
        // für eine Datei aus tempnam().
        $result = self::verarbeiten($tmpDatei);
        @unlink($tmpDatei);
        return $result;
    }

    private static function verarbeiten(string $pfad): array
    {
        $info = @getimagesize($pfad);
        if ($info === false || !isset($info['mime'])) {
            return ['success' => false, 'message' => 'Kein gültiges Bildformat.'];
        }

        $mime = $info['mime'];
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return ['success' => false, 'message' => 'Nicht unterstütztes Format: ' . $mime];
        }

        $bild = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($pfad),
            'image/png'  => @imagecreatefrompng($pfad),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($pfad) : false,
            default      => false,
        };
        if ($bild === false) {
            return ['success' => false, 'message' => 'Bild konnte nicht gelesen werden.'];
        }

        $breite = imagesx($bild);
        $hoehe = imagesy($bild);
        if ($breite > self::MAX_BREITE) {
            $zielHoehe = (int) round($hoehe * (self::MAX_BREITE / $breite));
            $verkleinert = imagecreatetruecolor(self::MAX_BREITE, $zielHoehe);
            imagealphablending($verkleinert, false);
            imagesavealpha($verkleinert, true);
            $transparent = imagecolorallocatealpha($verkleinert, 0, 0, 0, 127);
            imagefilledrectangle($verkleinert, 0, 0, self::MAX_BREITE, $zielHoehe, $transparent);
            imagecopyresampled($verkleinert, $bild, 0, 0, 0, 0, self::MAX_BREITE, $zielHoehe, $breite, $hoehe);
            imagedestroy($bild);
            $bild = $verkleinert;
        }

        ob_start();
        $erfolg = match ($mime) {
            'image/jpeg' => imagejpeg($bild, null, 85),
            'image/png'  => imagepng($bild, null, 6),
            'image/webp' => function_exists('imagewebp') ? imagewebp($bild, null, 85) : false,
            default      => false,
        };
        $blob = ob_get_clean();
        imagedestroy($bild);

        if (!$erfolg) {
            return ['success' => false, 'message' => 'Bild konnte nicht kodiert werden.'];
        }
        return ['success' => true, 'blob' => $blob, 'mime' => $mime];
    }

    private static function cacheLesen(): array
    {
        $daten = is_file(self::CACHE_DATEI) ? json_decode((string) file_get_contents(self::CACHE_DATEI), true) : null;
        return is_array($daten) ? $daten : [];
    }
}
