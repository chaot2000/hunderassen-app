<?php
/**
 * models/TheDogApiFelder.php
 *
 * Welche TheDogAPI-Werte in welches Feld von breeds gehen. Gemeinsame
 * Liste für admin_thedogapi_review.php und admin_thedogapi_apply.php.
 * Dazu das Laden der Rassenliste mit Datei-Cache (breeds()).
 *
 * TheDogAPI ist englisch, deshalb landen Texte in den *_en-Feldern.
 * Das deutsche Temperament entsteht daraus über das Wörterbuch
 * (models/Uebersetzung.php), alles andere übersetzt man von Hand.
 */

declare(strict_types=1);

class TheDogApiFelder
{
    /** Unser Feld => Beschriftung */
    public const TEXT = [
        'name_en'         => 'Name (englisch)',
        'temperament_en'  => 'Temperament (englisch)',
        'lebenserwartung' => 'Lebenserwartung',
        'zuchtzweck_en'   => 'Zuchtzweck (englisch)',
        'beschreibung_en' => 'Beschreibung (englisch)',
    ];

    /** Wert aus einer TheDogAPI-Rasse für eines unserer Felder. */
    public static function wert(array $api, string $feld): string
    {
        $t = fn(string $schluessel): string => trim((string) ($api[$schluessel] ?? ''));
        return match ($feld) {
            'name_en'         => $t('name'),
            'temperament_en'  => $t('temperament'),
            'lebenserwartung' => $t('life_span'),
            'zuchtzweck_en'   => $t('bred_for'),
            'beschreibung_en' => trim($t('description') . "\n\n" . $t('history')),
            default           => '',
        };
    }

    private const URL = 'https://api.thedogapi.com/v1/breeds';
    private const CACHE_DATEI = __DIR__ . '/../cache_thedogapi_breeds.json';
    private const CACHE_MAX_ALTER_SEK = 86400; // 1 Tag, TheDogAPI ändert sich selten

    /**
     * Alle Rassen von TheDogAPI. Frischer Cache gewinnt, sonst Abruf mit
     * dem Schlüssel aus config/thedogapi.local.php (nie im Repo). Geht der
     * Abruf nicht, dient ein alter Cache als Rückfall.
     *
     * @return array{breeds: list<array>, fehler: ?string}
     */
    public static function breeds(): array
    {
        $cache = is_file(self::CACHE_DATEI) ? json_decode((string) file_get_contents(self::CACHE_DATEI), true) : null;
        if (is_array($cache) && (time() - filemtime(self::CACHE_DATEI)) < self::CACHE_MAX_ALTER_SEK) {
            return ['breeds' => $cache, 'fehler' => null];
        }
        $configDatei = __DIR__ . '/../config/thedogapi.local.php';
        $config = is_file($configDatei) ? require $configDatei : [];
        $key = trim((string) ($config['api_key'] ?? ''));
        $fehler = null;
        if ($key === '') {
            $fehler = 'Kein TheDogAPI-Schlüssel hinterlegt. Lege config/thedogapi.local.php nach der Vorlage config/thedogapi.local.example.php an.';
        } else {
            $ch = curl_init(self::URL);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['x-api-key: ' . $key], CURLOPT_TIMEOUT => 20]);
            $antwort = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlFehler = curl_error($ch);
            curl_close($ch);
            $daten = is_string($antwort) && $status === 200 ? json_decode($antwort, true) : null;
            if (is_array($daten)) {
                file_put_contents(self::CACHE_DATEI, $antwort);
                return ['breeds' => $daten, 'fehler' => null];
            }
            $fehler = 'Fehler beim Abruf von TheDogAPI (HTTP ' . $status . ')' . ($curlFehler !== '' ? ': ' . $curlFehler : '') . '.';
        }
        if (is_array($cache)) {
            return ['breeds' => $cache, 'fehler' => null];
        }
        return ['breeds' => [], 'fehler' => $fehler];
    }
}
