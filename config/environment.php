<?php
/**
 * config/environment.php
 *
 * Erkennt, ob die App lokal (Laragon) oder produktiv (all-inkl) läuft,
 * und konfiguriert PHP entsprechend:
 *  - development: Fehler werden direkt im Browser angezeigt (wie bisher)
 *  - production:  Fehler werden NUR geloggt, dem Nutzer wird eine
 *                  generische Meldung gezeigt (kein Stack-Trace mit
 *                  Datei-Pfaden/SQL-Fragmenten mehr öffentlich sichtbar,
 *                  siehe der PDOException-Fehler von eben als Beispiel)
 *
 * WICHTIG: Erkennung ist bewusst "fail-safe" — wenn nicht eindeutig
 * erkennbar, dass es sich um die lokale Laragon-Umgebung handelt, wird
 * production angenommen. Lieber einmal zu vorsichtig auf dem Server,
 * als versehentlich Interna auf all-inkl offenzulegen.
 *
 * Muss VOR jeglicher Ausgabe geladen werden — wird deshalb ganz am
 * Anfang von config/security.php eingebunden, da diese Datei laut
 * eigenem Kommentar bereits "als eine der ersten Dateien in jedem
 * Request geladen werden MUSS".
 */

declare(strict_types=1);

if (!defined('APP_ENV')) {
    $host = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? php_sapi_name();
    $host = strtolower((string) $host);

    $istLokal = (
        str_contains($host, 'localhost')
        || str_contains($host, '127.0.0.1')
        || str_ends_with($host, '.test')     // gängige Laragon-Domain-Endung
        || str_ends_with($host, '.local')
        || php_sapi_name() === 'cli'          // Migrationsscripte etc.
    );

    define('APP_ENV', $istLokal ? 'development' : 'production');
}

if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
}

// -----------------------------------------------------------------
// Logging-Ziel: außerhalb des direkten Web-Zugriffs unmöglich zu
// erzwingen (flache Struktur, kein Verzeichnis oberhalb des
// Document-Roots verfügbar auf Shared Hosting) — daher wird der
// logs/-Ordner stattdessen per .htaccess für den Web-Zugriff gesperrt
// (siehe mitgelieferte .htaccess). Bitte NICHT vergessen, den Ordner
// beim Deployment mit anzulegen (PHP legt ihn sonst automatisch an,
// aber die .htaccess-Sperre muss von Hand mit hochgeladen werden).
// -----------------------------------------------------------------
const APP_LOG_DATEI = __DIR__ . '/../logs/app_errors.log';

if (!is_dir(__DIR__ . '/../logs')) {
    @mkdir(__DIR__ . '/../logs', 0755, true);
}

/**
 * Schreibt eine Fehlerzeile ins zentrale Log. Fängt selbst potenzielle
 * Schreibfehler ab (z. B. Berechtigungsprobleme auf dem Server) — ein
 * defektes Logging darf niemals eine zweite Fatal-Kaskade auslösen.
 */
function app_log_error(string $nachricht): void
{
    $zeile = '[' . date('Y-m-d H:i:s') . '] ' . $nachricht . PHP_EOL;
    @error_log($zeile, 3, APP_LOG_DATEI);
}

/**
 * Zeigt eine generische Fehlerseite (production) bzw. lässt PHP den
 * gewohnten Fehler ausgeben (development).
 */
function app_zeige_fehlerseite(): void
{
    if (APP_ENV === 'development') {
        return; // PHP zeigt den Fehler ohnehin an (display_errors=1)
    }

    if (!headers_sent()) {
        http_response_code(500);
    }

    echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">'
        . '<title>Fehler</title></head><body style="font-family:system-ui,sans-serif;'
        . 'max-width:500px;margin:4rem auto;text-align:center;color:#333;">'
        . '<h1 style="font-size:1.3rem;">Etwas ist schiefgelaufen</h1>'
        . '<p>Der Fehler wurde protokolliert. Bitte versuch es gleich noch einmal, '
        . 'oder wende dich an den Administrator, falls das Problem bestehen bleibt.</p>'
        . '</body></html>';
}

set_exception_handler(function (Throwable $e): void {
    app_log_error(sprintf(
        'Unbehandelte Exception: %s in %s:%d%sStack: %s',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        PHP_EOL,
        str_replace(PHP_EOL, ' | ', $e->getTraceAsString())
    ));
    app_zeige_fehlerseite();
});

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    // Mit @ unterdrückte Fehler (error_reporting() liefert dann 0)
    // nicht loggen, wie in PHP üblich.
    if (!(error_reporting() & $errno)) {
        return false;
    }

    app_log_error(sprintf('PHP-Fehler [%d]: %s in %s:%d', $errno, $errstr, $errfile, $errline));

    // false zurückgeben lässt PHPs internen Handler zusätzlich laufen
    // (respektiert display_errors, wie oben pro Umgebung gesetzt).
    return false;
});

register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        app_log_error(sprintf(
            'Fataler Fehler: %s in %s:%d',
            $error['message'],
            $error['file'],
            $error['line']
        ));
        app_zeige_fehlerseite();
    }
});
