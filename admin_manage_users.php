<?php
/**
 * admin_manage_users.php
 *
 * Benutzerliste mit Inline-Bearbeitung (Name/Rolle) und Passwort-Reset.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/User.php';

secure_session_start();
require_admin();

$currentAdminId = (int) current_user()['id'];
$errorMessage   = null;
$successMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'update_user':
            $result = User::update(
                (int) ($_POST['user_id'] ?? 0),
                $_POST['username'] ?? '',
                $_POST['role'] ?? '',
                $currentAdminId
            );
            $result['success'] ? $successMessage = $result['message'] : $errorMessage = $result['message'];
            break;

        case 'reset_password':
            $result = User::resetPassword(
                (int) ($_POST['user_id'] ?? 0),
                $_POST['new_password'] ?? ''
            );
            $result['success'] ? $successMessage = $result['message'] : $errorMessage = $result['message'];
            break;

        case 'toggle_active':
            $userId = (int) ($_POST['user_id'] ?? 0);
            $active = ($_POST['active'] ?? '0') === '1';
            User::setActive($userId, $active);
            header('Location: /admin_manage_users.php');
            exit;

        case 'delete_user':
            $result = User::delete((int) ($_POST['user_id'] ?? 0), $currentAdminId);
            if ($result['success']) {
                header('Location: /admin_manage_users.php');
                exit;
            }
            $errorMessage = $result['message'];
            break;

        default:
            $errorMessage = 'Unbekannte Aktion.';
    }
}

$users = User::findAll();

$pageTitle = 'Benutzerverwaltung';
require __DIR__ . '/views/partials/header.php';
?>

<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-extrabold text-fellDk">👥 Benutzerverwaltung</h1>
    <a href="/admin_add_user.php" class="bg-tanne hover:bg-tanneDk text-creme text-sm font-bold px-4 py-2 rounded-full transition-colors">
        + Neuer Benutzer
    </a>
</div>

<?php if ($successMessage !== null): ?>
    <div class="bg-tanne/10 border border-tanne/30 text-tanneDk text-sm rounded-lg px-4 py-3 mb-6" role="status"><?= e($successMessage) ?></div>
<?php endif; ?>
<?php if ($errorMessage !== null): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3 mb-6" role="alert"><?= e($errorMessage) ?></div>
<?php endif; ?>

<div class="space-y-3">
    <?php foreach ($users as $user):
        $uid      = (int) $user['id'];
        $isSelf   = $uid === $currentAdminId;
        $isActive = (int) $user['is_active'] === 1;
        // Alle Daten als JS-Literale vorbereiten — sicher mit JSON_HEX_APOS,
        // da x-data in einfachen Anführungszeichen steht.
        // @click-Attribute werden ebenfalls in einfachen Anführungszeichen
        // gerendert (via :attribute Binding) — dadurch entfällt jede
        // json_encode-Inline-Interpolation in doppelten Attribut-Quotes.
        $jsUsername = json_encode($user['username'], JSON_HEX_APOS);
        $jsRole     = json_encode($user['role'], JSON_HEX_APOS);
    ?>
        <div
            x-data='{
                editMode: false,
                pwMode: false,
                username: <?= $jsUsername ?>,
                role: <?= $jsRole ?>,
                originalUsername: <?= $jsUsername ?>,
                originalRole: <?= $jsRole ?>,
                resetEditForm() { this.username = this.originalUsername; this.role = this.originalRole; this.editMode = false; }
            }'
            class="bg-white/80 rounded-2xl shadow border border-sand p-5 <?= !$isActive ? 'opacity-60' : '' ?>"
        >
            <!-- Anzeige-Modus -->
            <div x-show="!editMode && !pwMode">
                <div class="flex items-center gap-3 flex-wrap">
                    <div class="flex items-center gap-2 flex-1 min-w-0">
                        <span class="text-base font-bold text-fellDk truncate"><?= e($user['username']) ?></span>
                        <span class="text-xs px-2 py-0.5 rounded-full font-semibold <?= $user['role'] === 'admin' ? 'bg-pfote/20 text-fell' : 'bg-sand text-fellDk/70' ?>">
                            <?= $user['role'] === 'admin' ? 'Admin' : 'Benutzer' ?>
                        </span>
                        <?php if (!$isActive): ?>
                            <span class="text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-600 font-semibold">Deaktiviert</span>
                        <?php endif; ?>
                        <?php if ($isSelf): ?>
                            <span class="text-xs text-fellDk/40">(du)</span>
                        <?php endif; ?>
                    </div>

                    <p class="text-xs text-fellDk/40 w-full sm:w-auto">
                        Erstellt: <?= date('d.m.Y', strtotime($user['created_at'])) ?>
                    </p>

                    <div class="flex gap-2 flex-wrap">
                        <button type="button" @click="editMode = true"
                            class="text-xs bg-pfote/10 text-fell font-semibold px-3 py-1.5 rounded-full hover:bg-pfote/20 transition-colors">
                            Bearbeiten
                        </button>
                        <button type="button" @click="pwMode = true"
                            class="text-xs bg-sand text-fellDk font-semibold px-3 py-1.5 rounded-full hover:bg-sand/70 transition-colors">
                            Passwort
                        </button>

                        <?php if (!$isSelf): ?>
                            <form method="post" action="/admin_manage_users.php" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                <input type="hidden" name="active" value="<?= $isActive ? '0' : '1' ?>">
                                <button type="submit"
                                    class="text-xs font-semibold px-3 py-1.5 rounded-full transition-colors <?= $isActive ? 'bg-yellow-50 text-yellow-700 hover:bg-yellow-100' : 'bg-tanne/10 text-tanneDk hover:bg-tanne/20' ?>">
                                    <?= $isActive ? 'Deaktivieren' : 'Aktivieren' ?>
                                </button>
                            </form>

                            <form method="post" action="/admin_manage_users.php" class="inline"
                                onsubmit="return confirm('Benutzer wirklich löschen?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                <button type="submit"
                                    class="text-xs bg-red-50 text-red-600 font-semibold px-3 py-1.5 rounded-full hover:bg-red-100 transition-colors">
                                    Löschen
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Bearbeitungs-Modus -->
            <form x-show="editMode" x-cloak method="post" action="/admin_manage_users.php" class="space-y-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" value="<?= $uid ?>">

                <p class="text-sm font-bold text-fellDk mb-2">Benutzer bearbeiten</p>

                <div class="flex flex-col sm:flex-row gap-3">
                    <div class="flex-1">
                        <label class="block text-xs font-semibold mb-1">Benutzername</label>
                        <input type="text" name="username" x-model="username" required maxlength="50"
                            class="w-full rounded-lg border border-sand px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
                    </div>
                    <div class="w-full sm:w-36">
                        <label class="block text-xs font-semibold mb-1">Rolle</label>
                        <?php if ($isSelf): ?>
                            <input type="text" value="Admin" disabled
                                class="w-full rounded-lg border border-sand px-3 py-2 text-sm opacity-60 cursor-not-allowed bg-sand/30">
                            <input type="hidden" name="role" value="admin">
                        <?php else: ?>
                            <select name="role" x-model="role"
                                class="w-full rounded-lg border border-sand px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
                                <option value="user">Benutzer</option>
                                <option value="admin">Admin</option>
                            </select>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="flex gap-2">
                    <button type="submit"
                        class="text-sm bg-tanne hover:bg-tanneDk text-creme font-bold px-4 py-2 rounded-full transition-colors">
                        Speichern
                    </button>
                    <button type="button" @click="resetEditForm()"
                        class="text-sm text-fellDk/60 hover:text-fellDk font-semibold px-4 py-2">
                        Abbrechen
                    </button>
                </div>
            </form>

            <!-- Passwort-Modus -->
            <form x-show="pwMode" x-cloak method="post" action="/admin_manage_users.php" class="space-y-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" value="<?= $uid ?>">

                <p class="text-sm font-bold text-fellDk mb-2">Passwort zurücksetzen</p>
                <p class="text-xs text-fellDk/60">Mindestens 10 Zeichen.</p>

                <div>
                    <label class="block text-xs font-semibold mb-1">Neues Passwort</label>
                    <input type="password" name="new_password" required minlength="10" autocomplete="new-password"
                        class="w-full rounded-lg border border-sand px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
                </div>

                <div class="flex gap-2">
                    <button type="submit"
                        class="text-sm bg-tanne hover:bg-tanneDk text-creme font-bold px-4 py-2 rounded-full transition-colors">
                        Passwort setzen
                    </button>
                    <button type="button" @click="pwMode = false"
                        class="text-sm text-fellDk/60 hover:text-fellDk font-semibold px-4 py-2">
                        Abbrechen
                    </button>
                </div>
            </form>
        </div>
    <?php endforeach; ?>

    <?php if (empty($users)): ?>
        <p class="text-sm text-fellDk/50">Keine Benutzer vorhanden.</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>