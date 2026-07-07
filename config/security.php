<?php
/**
 * config/security.php
 *
 * Zentrale Sicherheitsfunktionen der Anwendung:
 *  - Sichere Session-Konfiguration & Start
 *  - Inaktivitäts-Timeout (automatischer Logout nach 30 Minuten)
 *  - CSRF-Token-Erzeugung und -Prüfung
 *  - XSS-Escaping-Helfer (Context-Aware HTML-Encoding)
 *  - Brute-Force-Schutz (5 Fehlversuche -> 15 Minuten Sperre)
 *
 * Diese Datei MUSS als eine der ersten Dateien in jedem Request
 * geladen werden (vor jeglicher Ausgabe), da hier die Session
 * konfiguriert und gestartet wird.
 */

declare(strict_types=1);

require_once __DIR__ . '/environment.php';
require_once __DIR__ . '/database.php';

// =====================================================================
// 1. Sicheres Session-Management
// =====================================================================

// Nach so vielen Sekunden ohne Request wird ein eingeloggter Benutzer
// automatisch abgemeldet (siehe secure_session_start()).
const SESSION_IDLE_TIMEOUT_SECONDS = 1800; // 30 Minuten

/**
 * Startet die Session mit gehärteten Cookie-Einstellungen.
 * Muss aufgerufen werden, BEVOR irgendein Output gesendet wurde.
 */
function secure_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Erkennt, ob die Verbindung über HTTPS läuft (inkl. Proxy-Header,
    // falls all-inkl SSL-Terminierung vor dem PHP-Prozess durchführt).
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    );

    session_set_cookie_params([
        'lifetime' => 0,           // Cookie endet mit Schließen des Browsers
        'path'     => '/',
        'domain'   => '',          // aktuelle Domain
        'secure'   => $isHttps,    // Cookie nur über HTTPS übertragen
        'httponly' => true,        // kein Zugriff per JavaScript (XSS-Schutz)
        'samesite' => 'Lax',       // CSRF-Grundschutz, ohne Cross-Site-Links zu brechen
    ]);

    session_name('HRA_SESSID'); // generischer Name statt Standard PHPSESSID
    session_start();

    // Inaktivitäts-Timeout: War der Benutzer eingeloggt, aber der letzte
    // Request liegt länger als SESSION_IDLE_TIMEOUT_SECONDS zurück, wird
    // die Session verworfen. is_logged_in() liefert danach false, sodass
    // require_login() ganz regulär zur Login-Seite umleitet.
    if (
        isset($_SESSION['user'], $_SESSION['_last_activity'])
        && (time() - $_SESSION['_last_activity']) > SESSION_IDLE_TIMEOUT_SECONDS
    ) {
        destroy_session();
        session_start();
        session_regenerate_id(true);
        $_SESSION['_last_regeneration'] = time();
    }

    $_SESSION['_last_activity'] = time();

    // Session-Fixation-Schutz: regelmäßige Regeneration der Session-ID
    if (!isset($_SESSION['_last_regeneration'])) {
        $_SESSION['_last_regeneration'] = time();
    } elseif (time() - $_SESSION['_last_regeneration'] > 900) {
        // alle 15 Minuten neu generieren, alte Session-ID verwerfen
        session_regenerate_id(true);
        $_SESSION['_last_regeneration'] = time();
    }
}

/**
 * Regeneriert die Session-ID explizit — zwingend nach erfolgreichem
 * Login aufzurufen, um Session-Fixation-Angriffe zu verhindern.
 */
function regenerate_session_after_login(): void
{
    session_regenerate_id(true);
    $_SESSION['_last_regeneration'] = time();
}

/**
 * Beendet die Session vollständig (Logout) inkl. Cookie-Löschung.
 */
function destroy_session(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

// =====================================================================
// 2. CSRF-Schutz
// =====================================================================

/**
 * Liefert das aktuelle CSRF-Token für die Session und erzeugt bei
 * Bedarf ein neues. In jedes Formular als hidden field einbetten.
 */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

/**
 * Gibt ein fertiges <input type="hidden"> Feld mit CSRF-Token aus,
 * zur direkten Einbettung in <form>-Elemente.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Prüft das übermittelte CSRF-Token gegen das in der Session
 * gespeicherte Token. Nutzt timing-safe Vergleich.
 *
 * Bei jedem zustandsändernden POST-Request aufzurufen, BEVOR die
 * eigentliche Aktion ausgeführt wird.
 */
function csrf_verify(?string $submittedToken): bool
{
    if (empty($_SESSION['_csrf_token']) || empty($submittedToken)) {
        return false;
    }

    return hash_equals($_SESSION['_csrf_token'], $submittedToken);
}

/**
 * Bricht den Request mit HTTP 403 ab, falls das CSRF-Token ungültig
 * ist. Komfort-Wrapper für csrf_verify() in POST-Handlern.
 */
function csrf_require_valid(): void
{
    $submitted = $_POST['csrf_token'] ?? null;

    if (!csrf_verify($submitted)) {
        http_response_code(403);
        die('Ungültige oder abgelaufene Anfrage (CSRF-Token fehlt oder ist falsch). Bitte Seite neu laden und erneut versuchen.');
    }
}

// =====================================================================
// 3. XSS-Schutz — Context-Aware Output-Encoding
// =====================================================================

/**
 * Escaped einen String für die sichere Ausgabe in HTML-Kontext
 * (zwischen Tags oder in Attributwerten mit doppelten Anführungszeichen).
 *
 * Kurzname `e()` bewusst gewählt, damit Views nicht mit
 * htmlspecialchars(...) überladen werden.
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Validiert und trimmt einen rohen String-Input grundsätzlich
 * (Whitespace entfernen, Null-Bytes killen). Ersetzt KEIN
 * Output-Encoding — e() bleibt für die Ausgabe zwingend.
 */
function clean_input(?string $value): string
{
    $value = $value ?? '';
    $value = str_replace("\0", '', $value); // Null-Byte-Injection verhindern
    return trim($value);
}

// =====================================================================
// 4. Brute-Force-Schutz
// =====================================================================

const MAX_LOGIN_ATTEMPTS  = 5;
const LOCKOUT_MINUTES     = 15;

/**
 * Ermittelt die Client-IP-Adresse. Berücksichtigt ggf. einen
 * Proxy-Header, falls all-inkl Requests über einen Loadbalancer
 * durchreicht (Header wird nur genutzt, wenn vorhanden).
 */
function get_client_ip(): string
{
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Prüft, ob der angegebene Username ODER die aktuelle IP aktuell
 * gesperrt ist (>= MAX_LOGIN_ATTEMPTS Fehlversuche innerhalb der
 * letzten LOCKOUT_MINUTES).
 *
 * Gibt die verbleibende Sperrzeit in Sekunden zurück, oder 0 falls
 * keine Sperre aktiv ist.
 */
function get_lockout_remaining_seconds(string $username): int
{
    $pdo = Database::getConnection();
    $ip  = get_client_ip();

    $stmt = $pdo->prepare(
        'SELECT attempted_at FROM login_attempts
         WHERE success = 0
           AND (username = :username OR ip_address = :ip)
           AND attempted_at >= (NOW() - INTERVAL :lockout_minutes MINUTE)
         ORDER BY attempted_at DESC
         LIMIT :max_attempts'
    );
    $stmt->bindValue(':username', $username, PDO::PARAM_STR);
    $stmt->bindValue(':ip', $ip, PDO::PARAM_STR);
    $stmt->bindValue(':lockout_minutes', LOCKOUT_MINUTES, PDO::PARAM_INT);
    $stmt->bindValue(':max_attempts', MAX_LOGIN_ATTEMPTS, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();

    if (count($rows) < MAX_LOGIN_ATTEMPTS) {
        return 0; // noch nicht genug Fehlversuche für eine Sperre
    }

    // Älteste der letzten N Fehlversuche bestimmt, wann die Sperre endet
    $oldestRelevant = end($rows)['attempted_at'];
    $lockoutEndsAt  = strtotime($oldestRelevant) + (LOCKOUT_MINUTES * 60);
    $remaining      = $lockoutEndsAt - time();

    return max(0, $remaining);
}

/**
 * Protokolliert einen Login-Versuch (erfolgreich oder fehlgeschlagen).
 */
function log_login_attempt(string $username, bool $success): void
{
    $pdo = Database::getConnection();

    $stmt = $pdo->prepare(
        'INSERT INTO login_attempts (username, ip_address, success, attempted_at)
         VALUES (:username, :ip, :success, NOW())'
    );
    $stmt->execute([
        ':username' => $username,
        ':ip'       => get_client_ip(),
        ':success'  => $success ? 1 : 0,
    ]);
}

/**
 * Setzt den Fehlversuchs-Zähler für einen Username zurück, indem
 * ein erfolgreicher Login-Eintrag geschrieben wird (log_login_attempt
 * mit success=true erledigt das implizit, da get_lockout_remaining_seconds
 * nur success=0-Einträge zählt — diese Funktion ist daher nur ein
 * sprechender Alias für den Login-Erfolgsfall).
 */
function reset_login_attempts(string $username): void
{
    log_login_attempt($username, true);
}
