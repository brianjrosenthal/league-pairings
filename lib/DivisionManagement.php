<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

class DivisionManagement {
    private static function pdo(): PDO {
        return pdo();
    }

    private static function str(string $v): string {
        return trim($v);
    }

    // Activity logging
    private static function log(string $action, ?int $divisionId, array $details = []): void {
        try {
            $ctx = UserContext::getLoggedInUserContext();
            $meta = $details;
            if ($divisionId !== null && !array_key_exists('division_id', $meta)) {
                $meta['division_id'] = (int)$divisionId;
            }
            ActivityLog::log($ctx, (string)$action, (array)$meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }

    private static function assertLoggedIn(?UserContext $ctx): void {
        if (!$ctx) { 
            throw new RuntimeException('Login required'); 
        }
    }

    // Create a new division
    public static function createDivision(UserContext $ctx, string $name): int {
        self::assertLoggedIn($ctx);
        
        $name = self::str($name);
        
        if ($name === '') {
            throw new InvalidArgumentException('Division name is required.');
        }

        // Check if name already exists
        if (self::divisionNameExists($name)) {
            throw new InvalidArgumentException('A division with this name already exists.');
        }

        $st = self::pdo()->prepare(
            "INSERT INTO divisions (name) VALUES (?)"
        );
        $st->execute([$name]);
        $id = (int)self::pdo()->lastInsertId();
        
        self::log('division.create', $id, ['name' => $name]);
        
        return $id;
    }

    // Check if division name exists
    public static function divisionNameExists(string $name, ?int $excludeId = null): bool {
        $name = self::str($name);
        if ($name === '') return false;
        
        if ($excludeId === null) {
            $st = self::pdo()->prepare('SELECT 1 FROM divisions WHERE name = ? LIMIT 1');
            $st->execute([$name]);
        } else {
            $st = self::pdo()->prepare('SELECT 1 FROM divisions WHERE name = ? AND id != ? LIMIT 1');
            $st->execute([$name, $excludeId]);
        }
        
        return (bool)$st->fetchColumn();
    }

    // Find division by ID
    public static function findById(int $id): ?array {
        $st = self::pdo()->prepare('SELECT * FROM divisions WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // List all divisions
    public static function listDivisions(string $search = ''): array {
        $sql = 'SELECT id, name, created_at FROM divisions';
        $params = [];

        if ($search !== '') {
            $sql .= ' WHERE name LIKE ?';
            $searchTerm = '%' . $search . '%';
            $params = [$searchTerm];
        }

        $sql .= ' ORDER BY name';

        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    // Update division
    public static function updateDivision(UserContext $ctx, int $id, string $name): bool {
        self::assertLoggedIn($ctx);
        
        $name = self::str($name);
        
        if ($name === '') {
            throw new InvalidArgumentException('Division name is required.');
        }

        // Check if name already exists (excluding current division)
        if (self::divisionNameExists($name, $id)) {
            throw new InvalidArgumentException('A division with this name already exists.');
        }

        $st = self::pdo()->prepare('UPDATE divisions SET name = ? WHERE id = ?');
        $ok = $st->execute([$name, $id]);
        
        if ($ok) {
            self::log('division.update', $id, ['name' => $name]);
        }
        
        return $ok;
    }

    // Delete division
    public static function deleteDivision(UserContext $ctx, int $id): bool {
        self::assertLoggedIn($ctx);
        
        // Get division info before deleting for logging
        $division = self::findById($id);
        
        $st = self::pdo()->prepare('DELETE FROM divisions WHERE id = ?');
        $ok = $st->execute([$id]);
        
        if ($ok && $division) {
            self::log('division.delete', $id, ['name' => $division['name']]);
        }
        
        return $ok;
    }

    // Check if division name exists (case-insensitive)
    public static function isDuplicateName(string $name): bool {
        $name = self::str($name);
        if ($name === '') return false;
        
        $st = self::pdo()->prepare('SELECT 1 FROM divisions WHERE LOWER(name) = LOWER(?) LIMIT 1');
        $st->execute([$name]);
        return (bool)$st->fetchColumn();
    }

    // Get all existing division names (for duplicate checking during import)
    public static function getAllDivisionNames(): array {
        $st = self::pdo()->prepare('SELECT LOWER(name) as name_lower FROM divisions');
        $st->execute();
        $results = $st->fetchAll();
        return array_column($results, 'name_lower');
    }
}
