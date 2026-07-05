<?php
/**
 * account.php
 *
 * Eigenes Konto: hier kann sich jeder eingeloggte Nutzer selbst ein
 * neues Passwort vergeben. Bewusst getrennt von den Admin-Funktionen
 * in admin_manage_users.php (dort setzt ein Admin fremde Passwörter
 * OHNE Kenntnis des alten zurück; hier verlangt
 * User::changeOwnPassword() zwingend das aktuelle Passwort — siehe
 * Kommentar dort).
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/User.php';

secure_session_start();
require_login();

$currentUserId = (int) current_user()['id'];

$errorMessage   = null;
$successMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $currentPassword    = (string) ($_POST['current_password'] ?? '');
    $newPassword        = (string) ($_POST['new_password'] ?? '');
    $newPasswordRepeat  = (string) ($_POST['new_password_repeat'] ?? '');

    $result = User::changeOwnPassword($currentUserId, $currentPassword, $newPassword, $newPasswordRepeat);

    if ($result['success']) {
        $successMessage = $result['message'];
    } else {
        $errorMessage = $result['message'];
    }
}

$pageTitle = 'Mein Konto';
require __DIR__ . '/views/partials/header.php';
?>

<div class="max-w-md mx-auto">
    <div class="flex items-center gap-3 mb-6">
        <span class="text-4xl" aria-hidden="true">🐾</span>
        <div>
            <h1 class="text-2xl font-extrabold text-fellDk">Mein Konto</h1>
            <p class="text-sm text-fellDk/60">Angemeldet als <?= e(current_user()['username'] ?? '') ?></p>
        </div>
    </div>

    <div class="bg-white/80 rounded-2xl shadow border border-sand p-6 sm:p-8">
        <h2 class="font-extrabold text-fellDk mb-4">Passwort ändern</h2>

        <?php if ($successMessage !== null): ?>
            <div class="bg-green-50 border border-green-200 text-green-700 text-sm rounded-lg px-4 py-3 mb-4" role="status">
                <?= e($successMessage) ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage !== null): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3 mb-4" role="alert">
                <?= e($errorMessage) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="/account.php" class="space-y-4">
            <?= csrf_field() ?>

            <div>
                <label for="current_password" class="block text-sm font-semibold mb-1">Aktuelles Passwort</label>
                <input
                    type="password"
                    id="current_password"
                    name="current_password"
                    required
                    autocomplete="current-password"
                    autofocus
                    class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"
                >
            </div>

            <div>
                <label for="new_password" class="block text-sm font-semibold mb-1">Neues Passwort</label>
                <input
                    type="password"
                    id="new_password"
                    name="new_password"
                    required
                    minlength="10"
                    autocomplete="new-password"
                    class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"
                >
                <p class="text-xs text-fellDk/50 mt-1">Mindestens 10 Zeichen.</p>
            </div>

            <div>
                <label for="new_password_repeat" class="block text-sm font-semibold mb-1">Neues Passwort wiederholen</label>
                <input
                    type="password"
                    id="new_password_repeat"
                    name="new_password_repeat"
                    required
                    minlength="10"
                    autocomplete="new-password"
                    class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"
                >
            </div>

            <button
                type="submit"
                class="w-full bg-tanne hover:bg-tanneDk text-creme font-bold py-2.5 rounded-full transition-colors"
            >
                Passwort ändern
            </button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
