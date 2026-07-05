<?php
/**
 * config/database.php
 *
 * Stellt eine sichere PDO-Verbindung als Singleton bereit.
 * Alle DB-Zugriffe der Anwendung laufen ausschließlich über diese Klasse.
 *
 * ZUGANGSDATEN: bewusst NICHT hier im Code, sondern in
 * config/database.local.php — diese Datei ist NICHT Teil des Git-Repos
 * (siehe .gitignore) und wird pro Umgebung (lokal/Prod) individuell
 * angelegt. So ist ein- und dasselbe Repo auf allen Umgebungen
 * einsetzbar, ohne Zugangsdaten zu committen oder Code zu ändern.
 * Vorlage: config/database.local.example.php.
 *
 * WICHTIG: config/ NICHT öffentlich erreichbar machen — enthält
 * Zugangsdaten. Bei all-inkl liegt sie außerhalb des Document Root
 * (also außerhalb von /public), sodass sie per URL nicht direkt
 * aufrufbar ist. Nur per include/require aus PHP-Dateien erreichbar.
 * Zusätzlich per .htaccess gesperrt (siehe Root-.htaccess).
 */

declare(strict_types=1);

class Database
{
    private static ?PDO $instance = null;

    // Verhindert Instanziierung von außen — reine Utility-Klasse.
    private function __construct()
    {
    }

    /**
     * Liefert die einzige PDO-Instanz der Anwendung (Lazy Init).
     */
    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $config = self::loadConfig();

            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                $config['host'],
                $config['name'],
                $config['charset']
            );

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => false,
            ];

            try {
                self::$instance = new PDO($dsn, $config['user'], $config['pass'], $options);
            } catch (PDOException $e) {
                error_log('Datenbankverbindung fehlgeschlagen: ' . $e->getMessage());
                http_response_code(500);
                die('Ein interner Fehler ist aufgetreten. Bitte versuchen Sie es später erneut.');
            }
        }

        return self::$instance;
    }

    /**
     * Lädt die Zugangsdaten aus config/database.local.php. Bricht mit
     * einer klaren Meldung ab, falls diese Datei fehlt — besser eine
     * verständliche Fehlermeldung beim Deployment als ein kryptischer
     * "Undefined array key"-Fehler.
     *
     * @return array{host: string, name: string, user: string, pass: string, charset: string}
     */
    private static function loadConfig(): array
    {
        $localConfigFile = __DIR__ . '/database.local.php';

        if (!is_file($localConfigFile)) {
            error_log('config/database.local.php fehlt — siehe config/database.local.example.php als Vorlage.');
            http_response_code(500);
            die('Konfigurationsfehler: Datenbank-Zugangsdaten wurden nicht gefunden. Bitte config/database.local.php gemäß config/database.local.example.php anlegen.');
        }

        /** @var array<string, string> $dbConfig */
        $dbConfig = require $localConfigFile;

        return [
            'host'    => $dbConfig['host']    ?? 'localhost',
            'name'    => $dbConfig['name']    ?? '',
            'user'    => $dbConfig['user']    ?? '',
            'pass'    => $dbConfig['pass']    ?? '',
            'charset' => $dbConfig['charset'] ?? 'utf8mb4',
        ];
    }
}
