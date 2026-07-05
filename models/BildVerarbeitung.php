<?php
/**
 * models/BildVerarbeitung.php
 *
 * Validiert und verkleinert hochgeladene Rassebilder vor der Ablage
 * als BLOB in der Datenbank. Erlaubt JPG, PNG und WebP, verkleinert
 * serverseitig per GD auf maximal 1200px Breite (Höhe proportional),
 * um Speicherplatz in der DB und Ladezeit im Browser zu sparen.
 *
 * Bewusst als eigene, von Breed.php getrennte Klasse: reine
 * Bildverarbeitung hat nichts mit der Datenzugriffsschicht zu tun
 * und lässt sich so unabhängig testen.
 */

declare(strict_types=1);

class BildVerarbeitung
{
    private const MAX_BREITE_PX  = 1200;
    // 5 MB statt höher, da viele Shared-Hosting-Umgebungen (inkl.
    // PHP-Standardeinstellung) upload_max_filesize=2M ausliefern.
    // Größere Dateien werden von PHP selbst schon VOR diesem Code
    // abgelehnt (leeres $_FILES, error=UPLOAD_ERR_INI_SIZE). Falls auf
    // dem all-inkl-Server php.ini anpassbar ist (z.B. über eine eigene
    // php.ini im Hosting-Paket), kann sowohl upload_max_filesize als
    // auch dieser Wert hier erhöht werden — beide müssen zusammenpassen.
    private const MAX_UPLOAD_MB  = 5;
    private const JPEG_QUALITAET = 85;
    private const WEBP_QUALITAET = 85;
    private const PNG_KOMPRESSION = 6; // 0 (keine) bis 9 (max), 6 ist ein guter Mittelwert

    private const ERLAUBTE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /**
     * Verarbeitet einen Eintrag aus $_FILES: validiert Typ/Größe,
     * verkleinert bei Bedarf auf MAX_BREITE_PX und gibt die fertigen
     * Bytes + den MIME-Type zurück.
     *
     * @param array $file Ein einzelner $_FILES['feldname']-Eintrag
     *                     (also bereits $_FILES['bild'], nicht $_FILES selbst)
     *
     * @return array{success: bool, message?: string, blob?: string, mime?: string}
     */
    public static function verarbeiteUpload(array $file): array
    {
        // Kein Datei-Upload vorhanden (Feld leer gelassen) — kein Fehler,
        // sondern ein expliziter "nichts zu tun"-Zustand für den Aufrufer.
        if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['success' => true, 'blob' => null, 'mime' => null];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => self::fehlerTextFuer($file['error'])];
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            // Schutz gegen manipulierte Requests, die keinen echten
            // Upload-Vorgang durchlaufen haben.
            return ['success' => false, 'message' => 'Ungültiger Datei-Upload.'];
        }

        if ($file['size'] > self::MAX_UPLOAD_MB * 1024 * 1024) {
            return ['success' => false, 'message' => 'Das Bild ist zu groß (maximal ' . self::MAX_UPLOAD_MB . ' MB).'];
        }

        // MIME-Type NICHT aus dem Browser-Header übernehmen (leicht zu
        // fälschen), sondern serverseitig anhand der echten Bytes
        // bestimmen lassen.
        $mimeType = self::erkenneEchtenMimeType($file['tmp_name']);
        if ($mimeType === null || !in_array($mimeType, self::ERLAUBTE_MIME_TYPES, true)) {
            return ['success' => false, 'message' => 'Nur JPG-, PNG- oder WebP-Bilder sind erlaubt.'];
        }

        $bild = self::ladeBildRessource($file['tmp_name'], $mimeType);
        if ($bild === null) {
            return ['success' => false, 'message' => 'Das Bild konnte nicht gelesen werden. Bitte eine andere Datei versuchen.'];
        }

        $breite = imagesx($bild);
        $hoehe  = imagesy($bild);

        if ($breite > self::MAX_BREITE_PX) {
            $bild = self::verkleinere($bild, $breite, $hoehe, self::MAX_BREITE_PX);
        }

        $blob = self::kodiere($bild, $mimeType);
        imagedestroy($bild);

        if ($blob === null) {
            return ['success' => false, 'message' => 'Das Bild konnte nicht verarbeitet werden.'];
        }

        return ['success' => true, 'blob' => $blob, 'mime' => $mimeType];
    }

    /**
     * Bestimmt den echten MIME-Type einer Datei anhand ihrer Bytes
     * (nicht anhand der Dateiendung oder des vom Browser gesendeten
     * Headers, da beide manipulierbar sind).
     */
    private static function erkenneEchtenMimeType(string $tmpPath): ?string
    {
        $info = @getimagesize($tmpPath);
        if ($info === false || !isset($info['mime'])) {
            return null;
        }
        return $info['mime'];
    }

    /**
     * Lädt die Bilddatei als GD-Ressource passend zu ihrem MIME-Type.
     */
    private static function ladeBildRessource(string $tmpPath, string $mimeType): \GdImage|false|null
    {
        $bild = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($tmpPath),
            'image/png'  => @imagecreatefrompng($tmpPath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : false,
            default      => false,
        };

        return $bild !== false ? $bild : null;
    }

    /**
     * Skaliert ein Bild proportional auf die angegebene Zielbreite.
     */
    private static function verkleinere(\GdImage $original, int $breite, int $hoehe, int $zielBreite): \GdImage
    {
        $zielHoehe = (int) round($hoehe * ($zielBreite / $breite));

        $verkleinert = imagecreatetruecolor($zielBreite, $zielHoehe);

        // Transparenz für PNG/WebP erhalten, statt sie schwarz zu füllen.
        imagealphablending($verkleinert, false);
        imagesavealpha($verkleinert, true);
        $transparent = imagecolorallocatealpha($verkleinert, 0, 0, 0, 127);
        imagefilledrectangle($verkleinert, 0, 0, $zielBreite, $zielHoehe, $transparent);

        imagecopyresampled(
            $verkleinert, $original,
            0, 0, 0, 0,
            $zielBreite, $zielHoehe, $breite, $hoehe
        );

        imagedestroy($original);

        return $verkleinert;
    }

    /**
     * Kodiert eine GD-Bildressource zurück in Bytes, passend zum
     * ursprünglichen MIME-Type (Format bleibt erhalten, nur die
     * Abmessungen ändern sich).
     */
    private static function kodiere(\GdImage $bild, string $mimeType): ?string
    {
        ob_start();

        $erfolg = match ($mimeType) {
            'image/jpeg' => imagejpeg($bild, null, self::JPEG_QUALITAET),
            'image/png'  => imagepng($bild, null, self::PNG_KOMPRESSION),
            'image/webp' => function_exists('imagewebp') ? imagewebp($bild, null, self::WEBP_QUALITAET) : false,
            default      => false,
        };

        $bytes = ob_get_clean();

        return $erfolg ? $bytes : null;
    }

    /**
     * Übersetzt einen PHP UPLOAD_ERR_*-Code in eine verständliche
     * deutsche Fehlermeldung für die Anzeige im Formular.
     */
    private static function fehlerTextFuer(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Das Bild ist zu groß für den Upload.',
            UPLOAD_ERR_PARTIAL => 'Der Upload wurde abgebrochen. Bitte erneut versuchen.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Der Upload konnte serverseitig nicht verarbeitet werden.',
            UPLOAD_ERR_EXTENSION => 'Der Upload wurde durch eine Server-Erweiterung blockiert.',
            default => 'Beim Hochladen des Bildes ist ein unbekannter Fehler aufgetreten.',
        };
    }
}
