<?php
/**
 * login.php
 *
 * Sicheres Login-Formular. Kein Registrierungslink — Benutzer werden
 * ausschließlich von Admins angelegt (siehe admin_add_user.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Tenant.php';

secure_session_start();

// Bereits eingeloggte Benutzer direkt zum Dashboard weiterleiten
if (is_logged_in()) {
    header('Location: /dashboard.php');
    exit;
}

$errorMessage = null;

// Mandant: zuerst aus der Subdomain (kunde1.example.de), sonst über
// das Feld "Mandant". Gibt es nur einen aktiven Mandanten, entfällt
// das Feld ganz (Einzelinstallation wie bisher).
$host             = (string) ($_SERVER['HTTP_HOST'] ?? '');
$hostTenant       = Tenant::resolveFromHost($host);
$zeigeMandantFeld = $hostTenant === null && Tenant::countActive() > 1;
$mandantEingabe   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $username       = $_POST['username'] ?? '';
    $password       = $_POST['password'] ?? '';
    $mandantEingabe = $zeigeMandantFeld ? (string) ($_POST['mandant'] ?? '') : '';

    $tenant = $hostTenant;
    if ($tenant === null) {
        $tenant = $zeigeMandantFeld
            ? Tenant::resolveForLogin($host, $mandantEingabe)
            : (Tenant::findAllActive()[0] ?? null);
    }

    // Unbekannter Mandant -> gleiche Meldung wie falsches Passwort,
    // damit sich Mandanten-Kürzel nicht durchprobieren lassen.
    $result = $tenant !== null
        ? User::attemptLogin($username, $password, (int) $tenant['id'])
        : ['success' => false, 'message' => 'Benutzername oder Passwort ist falsch.'];

    if ($result['success']) {
        login_user($result['user']);
        header('Location: /dashboard.php');
        exit;
    }

    $errorMessage = $result['message'];
}

$pageTitle = 'Anmelden';
require __DIR__ . '/views/partials/header.php';
?>

<div class="max-w-md mx-auto bg-white/70 rounded-2xl shadow-lg p-8 mt-8 border border-sand">
    <div class="text-center mb-6">
        <span class="text-5xl" aria-hidden="true">🐶</span>
        <h1 class="text-2xl font-extrabold text-fellDk mt-2">Willkommen zurück!</h1>
        <p class="text-fellDk/70 text-sm mt-1">Bitte melde dich an, um fortzufahren.</p>
    </div>

    <?php if ($errorMessage !== null): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3 mb-4" role="alert">
            <?= e($errorMessage) ?>
        </div>
    <?php endif; ?>

    <form method="post" action="/login.php" class="space-y-4">
        <?= csrf_field() ?>

        <?php if ($hostTenant !== null): ?>
            <p class="text-sm text-fellDk/70">Mandant: <strong><?= e($hostTenant['name']) ?></strong></p>
        <?php elseif ($zeigeMandantFeld): ?>
            <div>
                <label for="mandant" class="block text-sm font-semibold mb-1">Mandant</label>
                <input
                    type="text"
                    id="mandant"
                    name="mandant"
                    value="<?= e($mandantEingabe) ?>"
                    autocomplete="organization"
                    autocapitalize="none"
                    spellcheck="false"
                    maxlength="50"
                    aria-describedby="mandant-hinweis"
                    class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"
                >
                <p id="mandant-hinweis" class="text-xs text-fellDk/60 mt-1">Kürzel deines Vereins oder Teams. Leer lassen, wenn du keins bekommen hast.</p>
            </div>
        <?php endif; ?>

        <div>
            <label for="username" class="block text-sm font-semibold mb-1">Benutzername</label>
            <input
                type="text"
                id="username"
                name="username"
                required
                autocomplete="username"
                autofocus
                class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"
            >
        </div>

        <div>
            <label for="password" class="block text-sm font-semibold mb-1">Passwort</label>
            <input
                type="password"
                id="password"
                name="password"
                required
                autocomplete="current-password"
                class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"
            >
        </div>

        <button
            type="submit"
            class="w-full bg-tanne hover:bg-tanneDk text-creme font-bold py-2.5 rounded-full transition-colors"
        >
            Anmelden
        </button>
    </form>

    <p class="text-xs text-fellDk/50 text-center mt-6">
        Noch keinen Zugang? Bitte wende dich an eine Administratorin oder einen Administrator.
    </p>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
