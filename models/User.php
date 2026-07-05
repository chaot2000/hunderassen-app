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
     * Versucht einen Login durchzuführen.
     * @return array{success: bool, message: string, user?: array}
     */
    public static function attemptLogin(string $username, string $password): array
    {
        $username = clean_input($username);

        $lockoutSeconds = get_lockout_remaining_seconds($username);
        if ($lockoutSeconds > 0) {
            $minutes = (int) ceil($lockoutSeconds / 60);
            return ['success' => false, 'message' => 'Zu viele fehlgeschlagene Login-Versuche. Bitte warten Sie noch ca. ' . $minutes . ' Minute(n).'];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id, username, password_hash, role, is_active FROM users WHERE username = :username LIMIT 1');
        $stmt->execute([':username' => $username]);
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
    public static function createByAdmin(string $username, string $password, string $role, int $createdByUserId): array
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
        $checkStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
        $checkStmt->execute([':username' => $username]);
        if ($checkStmt->fetch()) {
            return ['success' => false, 'message' => 'Dieser Benutzername ist bereits vergeben.'];
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $insertStmt = $pdo->prepare(
            'INSERT INTO users (username, password_hash, role, is_active, created_at, created_by) VALUES (:username, :hash, :role, 1, NOW(), :created_by)'
        );
        $insertStmt->execute([':username' => $username, ':hash' => $hash, ':role' => $role, ':created_by' => $createdByUserId]);

        return ['success' => true, 'message' => 'Benutzer "' . $username . '" wurde erfolgreich angelegt.'];
    }

    /**
     * Aktualisiert Benutzername und Rolle. Admin kann sich nicht selbst downgraden.
     * @return array{success: bool, message: string}
     */
    public static function update(int $id, string $username, string $role, int $currentAdminId): array
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
        if ($id === $currentAdminId && $role !== 'admin') {
            return ['success' => false, 'message' => 'Du kannst deine eigene Admin-Rolle nicht entfernen.'];
        }

        $pdo = Database::getConnection();
        $checkStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username AND id != :id LIMIT 1');
        $checkStmt->execute([':username' => $username, ':id' => $id]);
        if ($checkStmt->fetch()) {
            return ['success' => false, 'message' => 'Dieser Benutzername ist bereits vergeben.'];
        }

        $stmt = $pdo->prepare('UPDATE users SET username = :username, role = :role WHERE id = :id');
        $stmt->execute([':username' => $username, ':role' => $role, ':id' => $id]);

        return ['success' => true, 'message' => 'Benutzer "' . $username . '" wurde aktualisiert.'];
    }

    /**
     * Setzt das Passwort eines Benutzers zurück (Admin-Funktion).
     * @return array{success: bool, message: string}
     */
    public static function resetPassword(int $id, string $newPassword): array
    {
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
    public static function delete(int $id, int $currentAdminId): array
    {
        if ($id === $currentAdminId) {
            return ['success' => false, 'message' => 'Du kannst deinen eigenen Account nicht löschen.'];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return ['success' => true, 'message' => 'Benutzer wurde geloscht.'];
    }

    /** Alle Benutzer ohne Passwort-Hashes. */
    public static function findAll(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query('SELECT id, username, role, is_active, created_at, created_by FROM users ORDER BY created_at DESC');
        return $stmt->fetchAll();
    }

    public static function setActive(int $userId, bool $active): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE users SET is_active = :active WHERE id = :id');
        return $stmt->execute([':active' => $active ? 1 : 0, ':id' => $userId]);
    }

    public static function findById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id, username, role, is_active, created_at FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }
}
