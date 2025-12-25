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

    // === Import-specific methods ===

    // Find location by name (for duplicate detection during import)
    public static function findByName(string $name): ?array {
        $name = self::str($name);
        $st = self::pdo()->prepare('SELECT * FROM locations WHERE name = ? LIMIT 1');
        $st->execute([$name]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // Get all existing location names with descriptions for batch duplicate checking
    public static function getAllLocationData(): array {
        $sql = 'SELECT name, description FROM locations';
        $st = self::pdo()->prepare($sql);
        $st->execute();
        $results = [];
        while ($row = $st->fetch()) {
            $results[$row['name']] = $row['description'];
        }
        return $results;
    }

    // Update location description only (for import updates)
    public static function updateLocationDescription(UserContext $ctx, int $id, string $description): bool {
        self::assertLoggedIn($ctx);
        
        $description = self::str($description);
        
        $st = self::pdo()->prepare('UPDATE locations SET description = ? WHERE id = ?');
        $ok = $st->execute([$description, $id]);
        
        if ($ok) {
            self::log('location.update_description', $id, ['description' => $description]);
        }
        
        return $ok;
    }

    // === Division Affinity Management ===

    // Get all division affinities for a location
    public static function getAffinitiesForLocation(int $locationId): array {
        $sql = '
            SELECT d.id, d.name
            FROM location_division_affinities lda
            JOIN divisions d ON lda.division_id = d.id
            WHERE lda.location_id = ?
            ORDER BY d.name
        ';
        $st = self::pdo()->prepare($sql);
        $st->execute([$locationId]);
        return $st->fetchAll();
    }

    // Check if an affinity exists
    public static function hasAffinity(int $locationId, int $divisionId): bool {
        $st = self::pdo()->prepare('SELECT 1 FROM location_division_affinities WHERE location_id = ? AND division_id = ? LIMIT 1');
        $st->execute([$locationId, $divisionId]);
        return (bool)$st->fetchColumn();
    }

    // Add an affinity
    public static function addAffinity(UserContext $ctx, int $locationId, int $divisionId): bool {
        self::assertLoggedIn($ctx);
        
        // Check if affinity already exists
        if (self::hasAffinity($locationId, $divisionId)) {
            throw new InvalidArgumentException('This division affinity already exists for this location.');
        }

        $st = self::pdo()->prepare('INSERT INTO location_division_affinities (location_id, division_id) VALUES (?, ?)');
        $ok = $st->execute([$locationId, $divisionId]);
        
        if ($ok) {
            self::log('location.add_affinity', $locationId, ['division_id' => $divisionId]);
        }
        
        return $ok;
    }

    // Remove an affinity
    public static function removeAffinity(UserContext $ctx, int $locationId, int $divisionId): bool {
        self::assertLoggedIn($ctx);
        
        $st = self::pdo()->prepare('DELETE FROM location_division_affinities WHERE location_id = ? AND division_id = ?');
        $ok = $st->execute([$locationId, $divisionId]);
        
        if ($ok) {
            self::log('location.remove_affinity', $locationId, ['division_id' => $divisionId]);
        }
        
        return $ok;
    }

    // Get divisions not yet assigned to this location (for dropdown in add form)
    public static function getAvailableDivisionsForLocation(int $locationId): array {
        $sql = '
            SELECT d.id, d.name
            FROM divisions d
            WHERE d.id NOT IN (
                SELECT division_id 
                FROM location_division_affinities 
                WHERE location_id = ?
            )
            ORDER BY d.name
        ';
        $st = self::pdo()->prepare($sql);
        $st->execute([$locationId]);
        return $st->fetchAll();
    }

    // === Import Helper Methods ===

    // Get all divisions as a lookup map (name => id) for import validation
    public static function getAllDivisionsMap(): array {
        $sql = 'SELECT id, name FROM divisions ORDER BY name';
        $st = self::pdo()->prepare($sql);
        $st->execute();
        $divisions = $st->fetchAll();
        
        $map = [];
        foreach ($divisions as $division) {
            // Store with lowercase key for case-insensitive lookup
            $map[strtolower($division['name'])] = (int)$division['id'];
        }
        return $map;
    }

    // Get division ID by name (case-insensitive)
    public static function getDivisionIdByName(string $name): ?int {
        $name = self::str($name);
        if ($name === '') return null;
        
        $st = self::pdo()->prepare('SELECT id FROM divisions WHERE LOWER(name) = LOWER(?) LIMIT 1');
        $st->execute([$name]);
        $result = $st->fetchColumn();
        return $result ? (int)$result : null;
    }
}
