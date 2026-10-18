<?php
/**
 * models/ExternesBild.php
 *
 * Lädt ein Bild von einer freigegebenen fremden Quelle (TheDogAPI,
 * Wikimedia), prüft das Format anhand der Bytes, verkleinert auf
 * höchstens 1200 px Breite und kodiert neu, wie bei eigenen Uploads
 * (BildVerarbeitung). Welche Adressen erlaubt sind, prüft der Aufrufer
 * (TheDogApiBilder::istTheDogApiUrl, WikimediaBilder::istWikimediaUrl),
 * hier gilt zusätzlich: nur HTTPS, auch bei Weiterleitungen.
 */

declare(strict_types=1);

class ExternesBild
{
    private const MAX_BREITE = 1200;
    private const MAX_BYTES = 15 * 1024 * 1024;
    // Wikimedia verlangt einen aussagekräftigen User-Agent.
    public const USER_AGENT = 'Hunderassen-Verwaltung/1.0 (https://github.com/chaot2000/hunderassen-app)';

    /** Rohdaten holen, nur HTTPS. @return array{daten: ?string, fehler: ?string} */
    public static function holen(string $url): array
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return ['daten' => null, 'fehler' => 'Nur HTTPS-Adressen.'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_MAXFILESIZE => self::MAX_BYTES,
        ]);
        $daten = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($daten) || $status !== 200 || strlen($daten) > self::MAX_BYTES) {
            return ['daten' => null, 'fehler' => 'Download fehlgeschlagen (HTTP ' . $status . ')'];
        }
        return ['daten' => $daten, 'fehler' => null];
    }

    /** @return array{success: bool, blob?: string, mime?: string, message?: string} */
    public static function laden(string $url): array
    {
        $r = self::holen($url);
        if ($r['daten'] === null) {
            return ['success' => false, 'message' => $r['fehler']];
        }
        $tmpDatei = tempnam(sys_get_temp_dir(), 'extbild_');
        if ($tmpDatei === false) {
            return ['success' => false, 'message' => 'Konnte keine temporäre Datei anlegen.'];
        }
        file_put_contents($tmpDatei, $r['daten']);
        $ergebnis = self::verarbeiten($tmpDatei);
        @unlink($tmpDatei);
        return $ergebnis;
    }

    // BildVerarbeitung::verarbeiteUpload() verlangt einen echten
    // HTTP-Upload (is_uploaded_file), deshalb hier dieselben Regeln für
    // eine Datei aus tempnam().
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
}
