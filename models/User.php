<?php
/**
 * models/User.php
 *
 * Authentifizierung und Benutzerverwaltung.
 * Registrierung erfolgt AUSSCHLIESSLICH durch einen eingeloggten Admin.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

class User
{
    /**
     * Versucht einen Login im angegebenen Mandanten durchzuführen.
     * Der Benutzername ist nur pro Mandant eindeutig (uq_tenant_username),
     * deshalb wird immer mit Mandant gesucht. Deaktivierte Mandanten
     * lassen keinen Login zu.
     * @return array{success: bool, message: string, user?: array}
     */
    public static function attemptLogin(string $username, string $password, int $tenantId): array
    {
        $username = clean_input($username);

        $lockoutSeconds = get_lockout_remaining_seconds($username);
        if ($lockoutSeconds > 0) {
            $minutes = (int) ceil($lockoutSeconds / 60);
            return ['success' => false, 'message' => 'Zu viele fehlgeschlagene Login-Versuche. Bitte warten Sie noch ca. ' . $minutes . ' Minute(n).'];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT u.id, u.tenant_id, u.username, u.password_hash, u.role, u.is_super_admin, u.can_manage_breeds, u.can_manage_tests, u.is_active
             FROM users u
             INNER JOIN tenants t ON t.id = u.tenant_id AND t.is_active = 1
             WHERE u.username = :username AND u.tenant_id = :tenant_id
             LIMIT 1'
        );
        $stmt->execute([':username' => $username, ':tenant_id' => $tenantId]);
        $user = $stmt->fetch();

        $genericError = 'Benutzername oder Passwort ist falsch.';

        if (!$user) {
            log_login_attempt($username, false);
            return ['success' => false, 'message' => $genericError];
        }

        if ((int) $user['is_active'] !== 1) {
            log_login_attempt($username, false);
            return ['success' => false, 'message' => 'Dieser Account wurde deaktiviert. Bitte wenden Sie sich an einen Administrator.'];
        }

        if (!password_verify($password, $user['password_hash'])) {
            log_login_attempt($username, false);
            return ['success' => false, 'message' => $genericError];
        }

        log_login_attempt($username, true);
        unset($user['password_hash']);

        return ['success' => true, 'message' => 'Login erfolgreich.', 'user' => $user];
    }

    /**
     * Legt einen neuen Benutzer an (nur durch Admin).
     * @return array{success: bool, message: string}
     */
    public static function createByAdmin(string $username, string $password, string $role, int $createdByUserId, int $tenantId, bool $canManageBreeds = false, bool $canManageTests = false): array
    {
        $username = clean_input($username);

        if ($username === '') {
            return ['success' => false, 'message' => 'Benutzername darf nicht leer sein.'];
        }
        if (mb_strlen($username) > 50) {
            return ['success' => false, 'message' => 'Benutzername ist zu lang (max. 50 Zeichen).'];
        }
        if (!in_array($role, ['admin', 'user'], true)) {
            return ['success' => false, 'message' => 'Ungültige Rolle.'];
        }
        if (mb_strlen($password) < 10) {
            return ['success' => false, 'message' => 'Das Passwort muss mindestens 10 Zeichen lang sein.'];
        }

        $pdo = Database::getConnection();
        $checkStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username AND tenant_id = :tenant_id LIMIT 1');
        $checkStmt->execute([':username' => $username, ':tenant_id' => $tenantId]);
        if ($checkStmt->fetch()) {
            return ['success' => false, 'message' => 'Dieser Benutzername ist bereits vergeben.'];
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $insertStmt = $pdo->prepare(
            'INSERT INTO users (tenant_id, username, password_hash, role, can_manage_breeds, can_manage_tests, is_active, created_at, created_by)
             VALUES (:tenant_id, :username, :hash, :role, :can_manage_breeds, :can_manage_tests, 1, NOW(), :created_by)'
        );
        $insertStmt->execute([
            ':tenant_id'         => $tenantId,
            ':username'          => $username,
            ':hash'              => $hash,
            ':role'              => $role,
            ':can_manage_breeds' => $canManageBreeds ? 1 : 0,
            ':can_manage_tests'  => $canManageTests ? 1 : 0,
            ':created_by'        => $createdByUserId,
        ]);

        return ['success' => true, 'message' => 'Benutzer "' . $username . '" wurde erfolgreich angelegt.'];
    }

    /**
     * Aktualisiert Benutzername, Rolle und die granularen Portal-Rechte.
     * Admin kann sich nicht selbst downgraden. Die Portal-Rechte werden
     * unabhängig von der Rolle gespeichert, sind aber nur bei role='user'
     * wirksam — ein Admin hat can_manage_breeds/can_manage_tests ohnehin
     * implizit immer (siehe config/auth.php).
     *
     * @param int|null $tenantScope Mandant des handelnden Admins, null nur
     *        für Super-Admins (siehe user_admin_scope() in config/auth.php)
     * @return array{success: bool, message: string}
     */
    public static function update(int $id, string $username, string $role, int $currentAdminId, ?int $tenantScope, bool $canManageBreeds = false, bool $canManageTests = false): array
    {
        $target = self::findManageable($id, $tenantScope);
        if ($target === null) {
            return ['success' => false, 'message' => 'Benutzer nicht gefunden.'];
        }

        $username = clean_input($username);

        if ($username === '') {
            return ['success' => false, 'message' => 'Benutzername darf nicht leer sein.'];
        }
        if (mb_strlen($username) > 50) {
            return ['success' => false, 'message' => 'Benutzername ist zu lang (max. 50 Zeichen).'];
        }
        if (!in_array($role, ['admin', 'user'], true)) {
            return ['success' => false, 'message' => 'Ungültige Rolle.'];
        }
        if ($id === $currentAdminId && $role !== 'admin') {
            return ['success' => false, 'message' => 'Du kannst deine eigene Admin-Rolle nicht entfernen.'];
        }

        $pdo = Database::getConnection();
        $checkStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username AND tenant_id = :tenant_id AND id != :id LIMIT 1');
        $checkStmt->execute([':username' => $username, ':tenant_id' => (int) $target['tenant_id'], ':id' => $id]);
        if ($checkStmt->fetch()) {
            return ['success' => false, 'message' => 'Dieser Benutzername ist bereits vergeben.'];
        }

        $stmt = $pdo->prepare(
            'UPDATE users SET username = :username, role = :role, can_manage_breeds = :can_manage_breeds, can_manage_tests = :can_manage_tests WHERE id = :id'
        );
        $stmt->execute([
            ':username'          => $username,
            ':role'              => $role,
            ':can_manage_breeds' => $canManageBreeds ? 1 : 0,
            ':can_manage_tests'  => $canManageTests ? 1 : 0,
            ':id'                => $id,
        ]);

        return ['success' => true, 'message' => 'Benutzer "' . $username . '" wurde aktualisiert.'];
    }

    /**
     * Setzt das Passwort eines Benutzers zurück (Admin-Funktion).
     * @return array{success: bool, message: string}
     */
    public static function resetPassword(int $id, string $newPassword, ?int $tenantScope): array
    {
        if (self::findManageable($id, $tenantScope) === null) {
            return ['success' => false, 'message' => 'Benutzer nicht gefunden.'];
        }
        if (mb_strlen($newPassword) < 10) {
            return ['success' => false, 'message' => 'Das neue Passwort muss mindestens 10 Zeichen lang sein.'];
        }

        $pdo = Database::getConnection();
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $stmt->execute([':hash' => $hash, ':id' => $id]);

        return ['success' => true, 'message' => 'Passwort wurde erfolgreich zurückgesetzt.'];
    }

    /**
     * Ändert das eigene Passwort eines eingeloggten Nutzers. Anders als
     * resetPassword() (Admin-Funktion, kein Nachweis des alten
     * Passworts nötig) verlangt diese Methode bewusst das aktuelle
     * Passwort — schützt z.B. bei einer kurz unbeaufsichtigten,
     * eingeloggten Session davor, dass jemand anders das Passwort
     * unbemerkt austauscht.
     *
     * @return array{success: bool, message: string}
     */
    public static function changeOwnPassword(int $id, string $currentPassword, string $newPassword, string $newPasswordRepeat): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return ['success' => false, 'message' => 'Benutzer nicht gefunden.'];
        }

        if (!password_verify($currentPassword, $row['password_hash'])) {
            return ['success' => false, 'message' => 'Das aktuelle Passwort ist nicht korrekt.'];
        }

        if ($newPassword !== $newPasswordRepeat) {
            return ['success' => false, 'message' => 'Die beiden neuen Passwörter stimmen nicht überein.'];
        }

        if (mb_strlen($newPassword) < 10) {
            return ['success' => false, 'message' => 'Das neue Passwort muss mindestens 10 Zeichen lang sein.'];
        }

        if ($newPassword === $currentPassword) {
            return ['success' => false, 'message' => 'Das neue Passwort muss sich vom aktuellen unterscheiden.'];
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $updateStmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $updateStmt->execute([':hash' => $hash, ':id' => $id]);

        return ['success' => true, 'message' => 'Dein Passwort wurde erfolgreich geändert.'];
    }

    /**
     * Löscht einen Benutzer. Admin kann sich nicht selbst löschen.
     * @return array{success: bool, message: string}
     */
    public static function delete(int $id, int $currentAdminId, ?int $tenantScope): array
    {
        if ($id === $currentAdminId) {
            return ['success' => false, 'message' => 'Du kannst deinen eigenen Account nicht löschen.'];
        }
        if (self::findManageable($id, $tenantScope) === null) {
            return ['success' => false, 'message' => 'Benutzer nicht gefunden.'];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return ['success' => true, 'message' => 'Benutzer wurde gelöscht.'];
    }

    /**
     * Alle Benutzer ohne Passwort-Hashes, begrenzt auf einen Mandanten.
     * $tenantScope = null liefert alle Mandanten (nur für Super-Admins).
     */
    public static function findAll(?int $tenantScope): array
    {
        $pdo = Database::getConnection();
        $sql = 'SELECT u.id, u.tenant_id, t.name AS tenant_name, u.username, u.role, u.is_super_admin,
                       u.can_manage_breeds, u.can_manage_tests, u.is_active, u.created_at, u.created_by
                FROM users u
                INNER JOIN tenants t ON t.id = u.tenant_id';
        $params = [];
        if ($tenantScope !== null) {
            $sql .= ' WHERE u.tenant_id = :tenant_id';
            $params[':tenant_id'] = $tenantScope;
        }
        $sql .= ' ORDER BY u.created_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Aktiviert/deaktiviert einen Benutzer. Liefert false, wenn der
     * Benutzer außerhalb des Mandanten liegt oder geschützt ist.
     */
    public static function setActive(int $userId, bool $active, ?int $tenantScope): bool
    {
        if (self::findManageable($userId, $tenantScope) === null) {
            return false;
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE users SET is_active = :active WHERE id = :id');
        return $stmt->execute([':active' => $active ? 1 : 0, ':id' => $userId]);
    }

    public static function findById(int $id, ?int $tenantScope): ?array
    {
        $pdo = Database::getConnection();
        $sql = 'SELECT id, tenant_id, username, role, is_super_admin, can_manage_breeds, can_manage_tests, is_active, created_at
                FROM users WHERE id = :id';
        $params = [':id' => $id];
        if ($tenantScope !== null) {
            $sql .= ' AND tenant_id = :tenant_id';
            $params[':tenant_id'] = $tenantScope;
        }
        $stmt = $pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    /**
     * Darf ein Admin mit diesem Scope den Benutzer ändern? Nur innerhalb
     * des eigenen Mandanten, und Super-Admin-Konten bleiben für normale
     * Admins tabu (sonst könnte der Admin von Mandant 1 das Passwort des
     * Super-Admins zurücksetzen).
     */
    private static function findManageable(int $id, ?int $tenantScope): ?array
    {
        $user = self::findById($id, $tenantScope);
        if ($user === null) {
            return null;
        }
        if ($tenantScope !== null && !empty($user['is_super_admin'])) {
            return null;
        }
        return $user;
    }
}
