<?php
/**
 * models/Tag.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class Tag
{
    public static function findAll(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query('SELECT id, name, category, description FROM tags ORDER BY category, name');
        return $stmt->fetchAll();
    }

    public static function findAllGroupedByCategory(): array
    {
        $tags = self::findAll();
        $grouped = [];
        foreach ($tags as $tag) {
            $category = $tag['category'] ?: 'Sonstiges';
            $grouped[$category][] = $tag;
        }
        return $grouped;
    }

    public static function findById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id, name, category, description FROM tags WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $tag = $stmt->fetch();
        return $tag ?: null;
    }

    /** @return array{success: bool, message: string, tag_id?: int} */
    public static function create(string $name, ?string $category, ?string $description): array
    {
        $name = clean_input($name);
        if ($name === '') {
            return ['success' => false, 'message' => 'Der Name des Tags darf nicht leer sein.'];
        }

        $pdo = Database::getConnection();
        $checkStmt = $pdo->prepare('SELECT id FROM tags WHERE name = :name LIMIT 1');
        $checkStmt->execute([':name' => $name]);
        if ($checkStmt->fetch()) {
            return ['success' => false, 'message' => 'Der Tag "' . $name . '" existiert bereits.'];
        }

        $stmt = $pdo->prepare('INSERT INTO tags (name, category, description) VALUES (:name, :category, :description)');
        $stmt->execute([
            ':name'        => $name,
            ':category'    => $category !== null ? (clean_input($category) ?: null) : null,
            ':description' => $description !== null ? (clean_input($description) ?: null) : null,
        ]);

        return ['success' => true, 'message' => 'Tag "' . $name . '" wurde angelegt.', 'tag_id' => (int) $pdo->lastInsertId()];
    }

    /** @return array{success: bool, message: string} */
    public static function update(int $id, string $name, ?string $category, ?string $description = null): array
    {
        $name = clean_input($name);
        if ($name === '') {
            return ['success' => false, 'message' => 'Der Name darf nicht leer sein.'];
        }

        $pdo = Database::getConnection();
        $checkStmt = $pdo->prepare('SELECT id FROM tags WHERE name = :name AND id != :id LIMIT 1');
        $checkStmt->execute([':name' => $name, ':id' => $id]);
        if ($checkStmt->fetch()) {
            return ['success' => false, 'message' => 'Der Tag "' . $name . '" existiert bereits.'];
        }

        $stmt = $pdo->prepare('UPDATE tags SET name = :name, category = :category, description = :description WHERE id = :id');
        $stmt->execute([
            ':name'        => $name,
            ':category'    => $category !== null ? (clean_input($category) ?: null) : null,
            ':description' => $description !== null ? (clean_input($description) ?: null) : null,
            ':id'          => $id,
        ]);

        return ['success' => true, 'message' => 'Tag "' . $name . '" wurde aktualisiert.'];
    }

    public static function delete(int $id): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM tags WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Liefert die Anzahl zugeordneter Rassen pro Tag.
     * @return array<int, int>  [tag_id => breed_count]
     */
    public static function getBreedCounts(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query(
            'SELECT t.id, COUNT(bt.breed_id) AS breed_count
             FROM tags t
             LEFT JOIN breed_tags bt ON bt.tag_id = t.id
             GROUP BY t.id'
        );
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(int) $row['id']] = (int) $row['breed_count'];
        }
        return $counts;
    }
}