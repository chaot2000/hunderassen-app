<?php
/**
 * admin_manage_tenants.php
 *
 * Verwaltung von Mandanten (Tenants): anlegen, aktivieren, deaktivieren.
 * Nur für Super-Admins (siehe is_super_admin() in config/auth.php).
 *
 * Deaktivieren sperrt den Login für alle Benutzer des Mandanten
 * (User::attemptLogin()) und beendet laufende Sessions beim nächsten
 * Seitenaufruf (refresh_session_user()). Löschen gibt es bewusst
 * nicht: die Daten hängen per ON DELETE RESTRICT am Mandanten.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Tenant.php';

secure_session_start();
require_super_admin();

$ownTenantId    = current_tenant_id();
$successMessage = null;
$errorMessage   = null;
$formName       = '';
$formSubdomain  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $formName      = (string) ($_POST['name'] ?? '');
        $formSubdomain = (string) ($_POST['subdomain'] ?? '');
        $result = Tenant::create($formName, $formSubdomain);
        if ($result['success']) {
            $formName = $formSubdomain = '';
        }
    } elseif ($action === 'set_active') {
        // Zielzustand kommt explizit als 0/1 aus dem Formular (früher war
        // das Feld immer gesetzt und der Schalter hat immer aktiviert).
        $result = Tenant::setActive(
            (int) ($_POST['tenant_id'] ?? 0),
            ($_POST['active'] ?? '') === '1',
            $ownTenantId
        );
    } else {
        $result = ['success' => false, 'message' => 'Unbekannte Aktion.'];
    }

    $result['success'] ? $successMessage = $result['message'] : $errorMessage = $result['message'];
}

$tenants = Tenant::findAll();

$pageTitle = 'Mandanten verwalten';
require __DIR__ . '/views/partials/header.php';
?>

<h1 class="text-2xl font-extrabold text-fellDk mb-6">Mandanten verwalten</h1>

<?php if ($successMessage !== null): ?>
    <div class="bg-tanne/10 border border-tanne/30 text-tanneDk text-sm rounded-lg px-4 py-3 mb-6" role="status"><?= e($successMessage) ?></div>
<?php endif; ?>
<?php if ($errorMessage !== null): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3 mb-6" role="alert"><?= e($errorMessage) ?></div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

    <div class="bg-white/80 rounded-2xl shadow border border-sand p-6 lg:col-span-1">
        <h2 class="text-lg font-bold text-fellDk mb-4">Neuen Mandanten anlegen</h2>

        <form method="post" action="/admin_manage_tenants.php" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">

            <div>
                <label for="name" class="block text-sm font-semibold mb-1">Name</label>
                <input type="text" id="name" name="name" required maxlength="100" value="<?= e($formName) ?>"
                    class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne">
            </div>

            <div>
                <label for="subdomain" class="block text-sm font-semibold mb-1">Kürzel (Subdomain)</label>
                <input type="text" id="subdomain" name="subdomain" required maxlength="50" pattern="[a-z0-9][a-z0-9\-]*"
                    value="<?= e($formSubdomain) ?>" placeholder="z. B. verein-nord" autocapitalize="none" spellcheck="false"
                    aria-describedby="subdomain-hinweis"
                    class="w-full rounded-lg border border-sand px-3 py-2 font-mono focus:outline-none focus:ring-2 focus:ring-tanne">
                <p id="subdomain-hinweis" class="text-xs text-fellDk/60 mt-1">
                    Kleinbuchstaben, Zahlen, Bindestrich. Wird als Subdomain genutzt (verein-nord.deine-domain.de)
                    und ist das Kürzel im Login-Feld „Mandant“.
                </p>
            </div>

            <button type="submit"
                class="w-full bg-tanne hover:bg-tanneDk text-creme font-bold py-2.5 rounded-full transition-colors">
                Mandanten anlegen
            </button>
        </form>
    </div>

    <div class="bg-white/80 rounded-2xl shadow border border-sand overflow-hidden lg:col-span-2">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-sand/30 text-left text-xs font-semibold uppercase tracking-wide text-fellDk/60">
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Kürzel</th>
                        <th class="px-4 py-3">Benutzer</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Angelegt</th>
                        <th class="px-4 py-3 text-right">Aktion</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-sand">
                    <?php foreach ($tenants as $tenant):
                        $tid      = (int) $tenant['id'];
                        $isActive = (int) $tenant['is_active'] === 1;
                        $isOwn    = $tid === $ownTenantId;
                    ?>
                        <tr class="hover:bg-creme/60 transition-colors <?= $isActive ? '' : 'opacity-60' ?>">
                            <td class="px-4 py-3 font-semibold">
                                <?= e($tenant['name']) ?>
                                <?php if ($isOwn): ?><span class="text-xs text-fellDk/50 font-normal">(dein Mandant)</span><?php endif; ?>
                            </td>
                            <td class="px-4 py-3 font-mono text-fellDk/80"><?= e($tenant['subdomain']) ?></td>
                            <td class="px-4 py-3"><?= (int) $tenant['user_count'] ?></td>
                            <td class="px-4 py-3">
                                <?php if ($isActive): ?>
                                    <span class="text-xs px-2 py-0.5 rounded-full bg-tanne/10 text-tanneDk font-semibold">Aktiv</span>
                                <?php else: ?>
                                    <span class="text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-600 font-semibold">Deaktiviert</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-fellDk/70 whitespace-nowrap"><?= e(date('d.m.Y', strtotime((string) $tenant['created_at']))) ?></td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <?php if ($isOwn): ?>
                                    <span class="text-xs text-fellDk/50">—</span>
                                <?php else: ?>
                                    <form method="post" action="/admin_manage_tenants.php" class="inline"
                                        <?php if ($isActive): ?>onsubmit="return confirm(<?= e(json_encode('Mandant „' . $tenant['name'] . '“ deaktivieren? Seine Benutzer werden sofort abgemeldet und können sich nicht mehr anmelden.', JSON_UNESCAPED_UNICODE)) ?>);"<?php endif; ?>>
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_active">
                                        <input type="hidden" name="tenant_id" value="<?= $tid ?>">
                                        <input type="hidden" name="active" value="<?= $isActive ? '0' : '1' ?>">
                                        <button type="submit"
                                            class="text-xs font-semibold px-3 py-1.5 rounded-full transition-colors <?= $isActive ? 'bg-yellow-50 text-yellow-700 hover:bg-yellow-100' : 'bg-tanne/10 text-tanneDk hover:bg-tanne/20' ?>">
                                            <?= $isActive ? 'Deaktivieren' : 'Aktivieren' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
