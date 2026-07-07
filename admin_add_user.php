<?php
/**
 * admin_add_user.php
 *
 * Oberfläche, über die ausschließlich Admins neue Benutzer anlegen
 * können. Es gibt KEINE öffentliche Registrierung — dies ist der
 * einzige Weg, neue Accounts zu erstellen.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';

secure_session_start();
require_admin();

$successMessage = null;
$errorMessage   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    if (isset($_POST['toggle_active_user_id'])) {
        // Aktivieren/Deaktivieren eines bestehenden Accounts
        $targetId = (int) $_POST['toggle_active_user_id'];
        $newState = $_POST['new_state'] === '1';

        if ($targetId === (int) current_user()['id'] && !$newState) {
            $errorMessage = 'Du kannst deinen eigenen Account nicht deaktivieren.';
        } else {
            User::setActive($targetId, $newState);
            header('Location: /admin_add_user.php');
            exit;
        }
    } else {
        // Neuen Benutzer anlegen
        $username        = $_POST['username'] ?? '';
        $password        = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';
        $role            = $_POST['role'] ?? 'user';
        $canManageBreeds = isset($_POST['can_manage_breeds']);
        $canManageTests  = isset($_POST['can_manage_tests']);

        if ($password !== $passwordConfirm) {
            $errorMessage = 'Die Passwörter stimmen nicht überein.';
        } else {
            $result = User::createByAdmin($username, $password, $role, (int) current_user()['id'], $canManageBreeds, $canManageTests);

            if ($result['success']) {
                $successMessage = $result['message'];
            } else {
                $errorMessage = $result['message'];
            }
        }
    }
}

$allUsers = User::findAll();

$pageTitle = 'Benutzer verwalten';
require __DIR__ . '/views/partials/header.php';
?>

<h1 class="text-2xl font-extrabold text-fellDk mb-6">👤 Benutzer verwalten</h1>

<?php if ($successMessage !== null): ?>
    <div class="bg-tanne/10 border border-tanne/30 text-tanneDk text-sm rounded-lg px-4 py-3 mb-6" role="status">
        <?= e($successMessage) ?>
    </div>
<?php endif; ?>

<?php if ($errorMessage !== null): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3 mb-6" role="alert">
        <?= e($errorMessage) ?>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-8">

    <!-- Neuen Benutzer anlegen -->
    <div class="bg-white/80 rounded-2xl shadow border border-sand p-6">
        <h2 class="text-lg font-bold text-fellDk mb-4">Neuen Benutzer anlegen</h2>

        <form method="post" action="/admin_add_user.php" x-data="{ role: 'user' }" class="space-y-4">
            <?= csrf_field() ?>

            <div>
                <label for="username" class="block text-sm font-semibold mb-1">Benutzername</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    required
                    maxlength="50"
                    class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"
                >
            </div>

            <div>
                <label for="password" class="block text-sm font-semibold mb-1">Passwort (mind. 10 Zeichen)</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    required
                    minlength="10"
                    autocomplete="new-password"
                    class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"
                >
            </div>

            <div>
                <label for="password_confirm" class="block text-sm font-semibold mb-1">Passwort bestätigen</label>
                <input
                    type="password"
                    id="password_confirm"
                    name="password_confirm"
                    required
                    minlength="10"
                    autocomplete="new-password"
                    class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"
                >
            </div>

            <div>
                <label for="role" class="block text-sm font-semibold mb-1">Rolle</label>
                <select
                    id="role"
                    name="role"
                    x-model="role"
                    class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"
                >
                    <option value="user">Benutzer (Portal, nur eigene Einträge)</option>
                    <option value="admin">Administrator (volle Rechte)</option>
                </select>
            </div>

            <div x-show="role === 'user'" x-cloak class="space-y-2 border border-sand rounded-lg p-3 bg-creme/40">
                <p class="text-xs font-semibold text-fellDk/70">Zusätzliche Portal-Rechte (geteilte Kataloge)</p>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="can_manage_breeds" class="rounded border-sand">
                    Rassen verwalten (Rassen anlegen, Eigenschaften &amp; Aktivitäten)
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="can_manage_tests" class="rounded border-sand">
                    Tests verwalten (Test-Katalog anlegen/bearbeiten)
                </label>
            </div>

            <button
                type="submit"
                class="w-full bg-tanne hover:bg-tanneDk text-creme font-bold py-2.5 rounded-full transition-colors"
            >
                Benutzer anlegen
            </button>
        </form>
    </div>

    <!-- Bestehende Benutzer -->
    <div class="bg-white/80 rounded-2xl shadow border border-sand p-6">
        <h2 class="text-lg font-bold text-fellDk mb-4">Bestehende Benutzer</h2>

        <div class="space-y-3 max-h-[28rem] overflow-y-auto pr-1">
            <?php foreach ($allUsers as $u): ?>
                <div class="flex items-center justify-between border border-sand rounded-lg px-4 py-3">
                    <div>
                        <p class="font-semibold text-sm">
                            <?= e($u['username']) ?>
                            <?php if ((int) $u['id'] === (int) current_user()['id']): ?>
                                <span class="text-xs text-fellDk/40">(du)</span>
                            <?php endif; ?>
                        </p>
                        <p class="text-xs text-fellDk/50">
                            <?= $u['role'] === 'admin' ? 'Administrator' : 'Benutzer' ?>
                            &middot;
                            <?= (int) $u['is_active'] === 1
                                ? '<span class="text-tanneDk">Aktiv</span>'
                                : '<span class="text-red-500">Deaktiviert</span>' ?>
                        </p>
                    </div>

                    <form method="post" action="/admin_add_user.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="toggle_active_user_id" value="<?= (int) $u['id'] ?>">
                        <input type="hidden" name="new_state" value="<?= (int) $u['is_active'] === 1 ? '0' : '1' ?>">
                        <button
                            type="submit"
                            class="text-xs font-semibold px-3 py-1.5 rounded-full transition-colors <?= (int) $u['is_active'] === 1
                                ? 'bg-red-50 text-red-600 hover:bg-red-100'
                                : 'bg-tanne/10 text-tanneDk hover:bg-tanne/20' ?>"
                        >
                            <?= (int) $u['is_active'] === 1 ? 'Deaktivieren' : 'Aktivieren' ?>
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
