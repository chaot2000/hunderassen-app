<?php
/**
 * models/Tenant.php
 *
 * Mandanten (Tenants). Jeder Mandant hat ein eindeutiges Kürzel
 * (Spalte `subdomain`), über das er beim Login bestimmt wird:
 * zuerst aus der Subdomain der aufgerufenen Adresse
 * (kunde1.example.de -> "kunde1"), sonst aus dem Feld "Mandant" im
 * Login-Formular.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

class Tenant
{
    /** Kürzel des Mandanten, der bei leerem Login-Feld gilt. */
    public const DEFAULT_SUBDOMAIN = 'default';

    /** @return array<int, array<string, mixed>> */
    public static function findAll(): array
    {
        $pdo = Database::getConnection();
        return $pdo->query(
            'SELECT t.id, t.name, t.subdomain, t.is_active, t.created_at,
                    (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id) AS user_count
             FROM tenants t
             ORDER BY t.id'
        )->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public static function findAllActive(): array
    {
        $pdo = Database::getConnection();
        return $pdo->query('SELECT id, name, subdomain FROM tenants WHERE is_active = 1 ORDER BY name')->fetchAll();
    }

    public static function findActiveBySubdomain(string $subdomain): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id, name, subdomain FROM tenants WHERE subdomain = :subdomain AND is_active = 1 LIMIT 1');
        $stmt->execute([':subdomain' => strtolower(trim($subdomain))]);
        $tenant = $stmt->fetch();
        return $tenant ?: null;
    }

    public static function countActive(): int
    {
        $pdo = Database::getConnection();
        return (int) $pdo->query('SELECT COUNT(*) FROM tenants WHERE is_active = 1')->fetchColumn();
    }

    /**
     * Liest das Mandanten-Kürzel aus dem Hostnamen. Nur bei mindestens
     * drei Labels (kunde1.example.de) und nicht bei "www", IP-Adressen
     * oder localhost. Ob es den Mandanten gibt, prüft der Aufrufer.
     */
    public static function subdomainFromHost(string $host): ?string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;

        if ($host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $labels = explode('.', $host);
        if (count($labels) < 3 || $labels[0] === 'www') {
            return null;
        }

        return $labels[0];
    }

    /**
     * Mandant aus der Adresse, falls die Subdomain zu einem aktiven
     * Mandanten passt — dann blendet login.php das Feld aus.
     */
    public static function resolveFromHost(string $host): ?array
    {
        $subdomain = self::subdomainFromHost($host);
        return $subdomain !== null ? self::findActiveBySubdomain($subdomain) : null;
    }

    /**
     * Bestimmt den Mandanten für einen Login-Versuch.
     * Reihenfolge: Subdomain der Adresse, sonst das eingegebene Kürzel,
     * sonst (Feld leer) der Mandant "default". Liefert null, wenn das
     * Kürzel unbekannt oder der Mandant deaktiviert ist.
     */
    public static function resolveForLogin(string $host, string $eingabe): ?array
    {
        $ausHost = self::resolveFromHost($host);
        if ($ausHost !== null) {
            return $ausHost;
        }

        $eingabe = clean_input($eingabe);
        return self::findActiveBySubdomain($eingabe !== '' ? $eingabe : self::DEFAULT_SUBDOMAIN);
    }

    /**
     * @return array{success: bool, message: string}
     */
    public static function create(string $name, string $subdomain): array
    {
        $name      = clean_input($name);
        $subdomain = strtolower(clean_input($subdomain));

        if ($name === '') {
            return ['success' => false, 'message' => 'Der Name darf nicht leer sein.'];
        }
        if (mb_strlen($name) > 100) {
            return ['success' => false, 'message' => 'Der Name darf maximal 100 Zeichen lang sein.'];
        }
        if ($subdomain === '') {
            return ['success' => false, 'message' => 'Das Kürzel darf nicht leer sein.'];
        }
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,48}[a-z0-9])?$/', $subdomain)) {
            return ['success' => false, 'message' => 'Das Kürzel darf nur Kleinbuchstaben, Zahlen und Bindestriche enthalten (max. 50 Zeichen, nicht mit Bindestrich beginnen oder enden).'];
        }
        if ($subdomain === 'www') {
            return ['success' => false, 'message' => 'Das Kürzel "www" ist reserviert.'];
        }

        $pdo = Database::getConnection();
        $check = $pdo->prepare('SELECT id FROM tenants WHERE subdomain = :subdomain LIMIT 1');
        $check->execute([':subdomain' => $subdomain]);
        if ($check->fetch()) {
            return ['success' => false, 'message' => 'Dieses Kürzel ist bereits vergeben.'];
        }

        $stmt = $pdo->prepare('INSERT INTO tenants (name, subdomain, is_active, created_at) VALUES (:name, :subdomain, 1, NOW())');
        $stmt->execute([':name' => $name, ':subdomain' => $subdomain]);

        return ['success' => true, 'message' => 'Mandant "' . $name . '" wurde angelegt.'];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public static function setActive(int $tenantId, bool $active, int $ownTenantId): array
    {
        if (!$active && $tenantId === $ownTenantId) {
            return ['success' => false, 'message' => 'Den eigenen Mandanten kannst du nicht deaktivieren.'];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE tenants SET is_active = :active WHERE id = :id');
        $stmt->execute([':active' => $active ? 1 : 0, ':id' => $tenantId]);

        if ($stmt->rowCount() === 0) {
            $exists = $pdo->prepare('SELECT 1 FROM tenants WHERE id = :id');
            $exists->execute([':id' => $tenantId]);
            if (!$exists->fetch()) {
                return ['success' => false, 'message' => 'Mandant nicht gefunden.'];
            }
        }

        return ['success' => true, 'message' => $active ? 'Mandant wurde aktiviert.' : 'Mandant wurde deaktiviert.'];
    }
}
