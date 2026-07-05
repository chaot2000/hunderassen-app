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

secure_session_start();

// Bereits eingeloggte Benutzer direkt zum Dashboard weiterleiten
if (is_logged_in()) {
    header('Location: /dashboard.php');
    exit;
}

$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $result = User::attemptLogin($username, $password);

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
