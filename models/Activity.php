<?php
/**
 * models/Activity.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class Activity
{
    public static function findAll(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query('SELECT id, name, description FROM activities ORDER BY name');
        return $stmt->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id, name, description FROM activities WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $activity = $stmt->fetch();
        return $activity ?: null;
    }

    /** @return array{success: bool, message: string, activity_id?: int} */
    public static function create(string $name, ?string $description): array
    {
        $name = clean_input($name);
        if ($name === '') {
            return ['success' => false, 'message' => 'Der Name der Aktivität darf nicht leer sein.'];
        }

        $pdo = Database::getConnection();
        $checkStmt = $pdo->prepare('SELECT id FROM activities WHERE name = :name LIMIT 1');
        $checkStmt->execute([':name' => $name]);
        if ($checkStmt->fetch()) {
            return ['success' => false, 'message' => 'Die Aktivität "' . $name . '" existiert bereits.'];
        }

        $stmt = $pdo->prepare('INSERT INTO activities (name, description) VALUES (:name, :description)');
        $stmt->execute([
            ':name'        => $name,
            ':description' => $description !== null ? (clean_input($description) ?: null) : null,
        ]);

        return ['success' => true, 'message' => 'Aktivität "' . $name . '" wurde angelegt.', 'activity_id' => (int) $pdo->lastInsertId()];
    }

    /** @return array{success: bool, message: string} */
    public static function update(int $id, string $name, ?string $description = null): array
    {
        $name = clean_input($name);
        if ($name === '') {
            return ['success' => false, 'message' => 'Der Name darf nicht leer sein.'];
        }

        $pdo = Database::getConnection();
        $checkStmt = $pdo->prepare('SELECT id FROM activities WHERE name = :name AND id != :id LIMIT 1');
        $checkStmt->execute([':name' => $name, ':id' => $id]);
        if ($checkStmt->fetch()) {
            return ['success' => false, 'message' => 'Die Aktivität "' . $name . '" existiert bereits.'];
        }

        $stmt = $pdo->prepare('UPDATE activities SET name = :name, description = :description WHERE id = :id');
        $stmt->execute([
            ':name'        => $name,
            ':description' => $description !== null ? (clean_input($description) ?: null) : null,
            ':id'          => $id,
        ]);

        return ['success' => true, 'message' => 'Aktivität "' . $name . '" wurde aktualisiert.'];
    }

    public static function delete(int $id): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM activities WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Liefert die Anzahl zugeordneter Rassen pro Aktivität.
     * @return array<int, int>  [activity_id => breed_count]
     */
    public static function getBreedCounts(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query(
            'SELECT a.id, COUNT(ba.breed_id) AS breed_count
             FROM activities a
             LEFT JOIN breed_activities ba ON ba.activity_id = a.id
             GROUP BY a.id'
        );
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(int) $row['id']] = (int) $row['breed_count'];
        }
        return $counts;
    }
}