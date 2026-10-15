<?php
/**
 * admin_manage_tenants.php
 *
 * Verwaltung von Mandanten (Tenants) für Multi-Tenancy.
 * Nur für Super-Admins zugänglich (siehe is_super_admin() in config/auth.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/config/environment.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';

secure_session_start();
require_super_admin();

$pdo = Database::getConnection();

// Tenant erstellen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $name = clean_input($_POST['name'] ?? '');
    $subdomain = clean_input($_POST['subdomain'] ?? '');
    
    if ($name === '') {
        $error = 'Der Name darf nicht leer sein.';
    } elseif (mb_strlen($name) > 100) {
        $error = 'Der Name darf maximal 100 Zeichen lang sein.';
    } elseif ($subdomain === '') {
        $error = 'Die Subdomain darf nicht leer sein.';
    } elseif (!preg_match('/^[a-z0-9-]+$/', $subdomain)) {
        $error = 'Die Subdomain darf nur Kleinbuchstaben, Zahlen und Bindestriche enthalten.';
    } elseif (mb_strlen($subdomain) > 50) {
        $error = 'Die Subdomain darf maximal 50 Zeichen lang sein.';
    } else {
        $checkStmt = $pdo->prepare('SELECT id FROM tenants WHERE subdomain = :subdomain LIMIT 1');
        $checkStmt->execute([':subdomain' => $subdomain]);
        if ($checkStmt->fetch()) {
            $error = 'Diese Subdomain ist bereits vergeben.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO tenants (name, subdomain, is_active, created_at) VALUES (:name, :subdomain, 1, NOW())');
            $stmt->execute([':name' => $name, ':subdomain' => $subdomain]);
            $success = 'Mandant "' . $name . '" wurde erfolgreich angelegt.';
        }
    }
}

// Tenant deaktivieren/aktivieren
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_active') {
    $tenantId = (int) ($_POST['tenant_id'] ?? 0);
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    
    // Default-Tenant (ID 1) darf nicht deaktiviert werden
    if ($tenantId === 1) {
        $error = 'Der Default-Mandant kann nicht deaktiviert werden.';
    } else {
        $stmt = $pdo->prepare('UPDATE tenants SET is_active = :is_active WHERE id = :id');
        $stmt->execute([':is_active' => $isActive, ':id' => $tenantId]);
        $success = $isActive ? 'Mandant wurde aktiviert.' : 'Mandant wurde deaktiviert.';
    }
}

// Alle Tenants laden
$tenantsStmt = $pdo->query('SELECT id, name, subdomain, is_active, created_at FROM tenants ORDER BY created_at DESC');
$tenants = $tenantsStmt->fetchAll();

// User-Count pro Tenant
$userCounts = [];
foreach ($tenants as $tenant) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE tenant_id = :tenant_id');
    $stmt->execute([':tenant_id' => $tenant['id']]);
    $userCounts[$tenant['id']] = (int) $stmt->fetchColumn();
}

require_once __DIR__ . '/views/partials/header.php';
?>

<div class="container mt-4">
    <h1>Mandanten verwalten</h1>
    
    <?php if (isset($success)): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>
    
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Neuen Mandanten anlegen</h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="create">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="name" class="form-label">Name</label>
                        <input type="text" class="form-control" id="name" name="name" required maxlength="100">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="subdomain" class="form-label">Subdomain</label>
                        <input type="text" class="form-control" id="subdomain" name="subdomain" required maxlength="50" pattern="[a-z0-9-]+" placeholder="z.B. kunde1">
                        <small class="text-muted">Nur Kleinbuchstaben, Zahlen und Bindestriche</small>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Mandanten anlegen</button>
            </form>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Vorhandene Mandanten</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Subdomain</th>
                            <th>Benutzer</th>
                            <th>Status</th>
                            <th>Erstellt am</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tenants as $tenant): ?>
                            <tr>
                                <td><?= $tenant['id'] ?></td>
                                <td><?= e($tenant['name']) ?></td>
                                <td><code><?= e($tenant['subdomain']) ?></code></td>
                                <td><?= $userCounts[$tenant['id']] ?? 0 ?></td>
                                <td>
                                    <?php if ($tenant['is_active']): ?>
                                        <span class="badge bg-success">Aktiv</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Inaktiv</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('d.m.Y H:i', strtotime($tenant['created_at'])) ?></td>
                                <td>
                                    <?php if ($tenant['id'] !== 1): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="toggle_active">
                                            <input type="hidden" name="tenant_id" value="<?= $tenant['id'] ?>">
                                            <input type="hidden" name="is_active" value="<?= $tenant['is_active'] ? 0 : 1 ?>">
                                            <button type="submit" class="btn btn-sm btn-<?= $tenant['is_active'] ? 'warning' : 'success' ?>">
                                                <?= $tenant['is_active'] ? 'Deaktivieren' : 'Aktivieren' ?>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted">Default</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/views/partials/footer.php'; ?>
