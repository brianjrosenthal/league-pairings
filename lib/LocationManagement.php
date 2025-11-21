<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

class LocationManagement {
    private static function pdo(): PDO {
        return pdo();
    }

    private static function str(string $v): string {
        return trim($v);
    }

    // Activity logging
    private static function log(string $action, ?int $locationId, array $details = []): void {
        try {
            $ctx = UserContext::getLoggedInUserContext();
            $meta = $details;
            if ($locationId !== null && !array_key_exists('location_id', $meta)) {
                $meta['location_id'] = (int)$locationId;
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

    // Create a new location
    public static function createLocation(UserContext $ctx, string $name, string $description): int {
        self::assertLoggedIn($ctx);
        
        $name = self::str($name);
        $description = self::str($description);
        
        if ($name === '') {
            throw new InvalidArgumentException('Location name is required.');
        }

        // Check if name already exists
        if (self::locationNameExists($name)) {
            throw new InvalidArgumentException('A location with this name already exists.');
        }

        $st = self::pdo()->prepare(
            "INSERT INTO locations (name, description) VALUES (?, ?)"
        );
        $st->execute([$name, $description]);
        $id = (int)self::pdo()->lastInsertId();
        
        self::log('location.create', $id, ['name' => $name]);
        
        return $id;
    }

    // Check if location name exists
    public static function locationNameExists(string $name, ?int $excludeId = null): bool {
        $name = self::str($name);
        if ($name === '') return false;
        
        if ($excludeId === null) {
            $st = self::pdo()->prepare('SELECT 1 FROM locations WHERE name = ? LIMIT 1');
            $st->execute([$name]);
        } else {
            $st = self::pdo()->prepare('SELECT 1 FROM locations WHERE name = ? AND id != ? LIMIT 1');
            $st->execute([$name, $excludeId]);
        }
        
        return (bool)$st->fetchColumn();
    }

    // Find location by ID
    public static function findById(int $id): ?array {
        $st = self::pdo()->prepare('SELECT * FROM locations WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // List all locations
    public static function listLocations(): array {
        $sql = 'SELECT id, name, description, created_at FROM locations ORDER BY name';

        $st = self::pdo()->prepare($sql);
        $st->execute();
        return $st->fetchAll();
    }

    // Update location
    public static function updateLocation(UserContext $ctx, int $id, string $name, string $description): bool {
        self::assertLoggedIn($ctx);
        
        $name = self::str($name);
        $description = self::str($description);
        
        if ($name === '') {
            throw new InvalidArgumentException('Location name is required.');
        }

        // Check if name already exists (excluding current location)
        if (self::locationNameExists($name, $id)) {
            throw new InvalidArgumentException('A location with this name already exists.');
        }

        $st = self::pdo()->prepare('UPDATE locations SET name = ?, description = ? WHERE id = ?');
        $ok = $st->execute([$name, $description, $id]);
        
        if ($ok) {
            self::log('location.update', $id, ['name' => $name]);
        }
        
        return $ok;
    }

    // Delete location
    public static function deleteLocation(UserContext $ctx, int $id): bool {
        self::assertLoggedIn($ctx);
        
        // Get location info before deleting for logging
        $location = self::findById($id);
        
        $st = self::pdo()->prepare('DELETE FROM locations WHERE id = ?');
        $ok = $st->execute([$id]);
        
        if ($ok && $location) {
            self::log('location.delete', $id, ['name' => $location['name']]);
        }
        
        return $ok;
    }
}
