<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

class LocationAvailabilityManagement {
    private static function pdo(): PDO {
        return pdo();
    }

    // Activity logging
    private static function log(string $action, array $details = []): void {
        try {
            $ctx = UserContext::getLoggedInUserContext();
            ActivityLog::log($ctx, (string)$action, (array)$details);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }

    private static function assertLoggedIn(?UserContext $ctx): void {
        if (!$ctx) { 
            throw new RuntimeException('Login required'); 
        }
    }

    // Add location availability (location-timeslot relationship)
    public static function addAvailability(UserContext $ctx, int $locationId, int $timeslotId): bool {
        self::assertLoggedIn($ctx);
        
        if ($locationId <= 0 || $timeslotId <= 0) {
            throw new InvalidArgumentException('Valid location and timeslot are required.');
        }

        // Check if already exists
        if (self::isAvailable($locationId, $timeslotId)) {
            throw new InvalidArgumentException('This availability already exists.');
        }

        $st = self::pdo()->prepare(
            "INSERT INTO location_availability (location_id, timeslot_id) VALUES (?, ?)"
        );
        $ok = $st->execute([$locationId, $timeslotId]);
        
        if ($ok) {
            self::log('location_availability.add', [
                'location_id' => $locationId,
                'timeslot_id' => $timeslotId
            ]);
        }
        
        return $ok;
    }

    // Remove location availability
    public static function removeAvailability(UserContext $ctx, int $locationId, int $timeslotId): bool {
        self::assertLoggedIn($ctx);
        
        if ($locationId <= 0 || $timeslotId <= 0) {
            throw new InvalidArgumentException('Valid location and timeslot are required.');
        }

        $st = self::pdo()->prepare(
            "DELETE FROM location_availability WHERE location_id = ? AND timeslot_id = ?"
        );
        $ok = $st->execute([$locationId, $timeslotId]);
        
        if ($ok) {
            self::log('location_availability.remove', [
                'location_id' => $locationId,
                'timeslot_id' => $timeslotId
            ]);
        }
        
        return $ok;
    }

    // Check if location is available for a timeslot
    public static function isAvailable(int $locationId, int $timeslotId): bool {
        $st = self::pdo()->prepare(
            'SELECT 1 FROM location_availability WHERE location_id = ? AND timeslot_id = ? LIMIT 1'
        );
        $st->execute([$locationId, $timeslotId]);
        return (bool)$st->fetchColumn();
    }

    // Get all timeslots for a location (with timeslot details)
    public static function getTimeslotsForLocation(int $locationId): array {
        $sql = 'SELECT t.id, t.date, t.modifier 
                FROM timeslots t 
                INNER JOIN location_availability la ON t.id = la.timeslot_id 
                WHERE la.location_id = ? 
                ORDER BY t.date DESC, t.modifier';

        $st = self::pdo()->prepare($sql);
        $st->execute([$locationId]);
        return $st->fetchAll();
    }

    // Get all locations for a timeslot (with location details)
    public static function getLocationsForTimeslot(int $timeslotId): array {
        $sql = 'SELECT l.id, l.name, l.description 
                FROM locations l 
                INNER JOIN location_availability la ON l.id = la.location_id 
                WHERE la.timeslot_id = ? 
                ORDER BY l.name';

        $st = self::pdo()->prepare($sql);
        $st->execute([$timeslotId]);
        return $st->fetchAll();
    }

    // Get available timeslots for a location (excluding already assigned)
    public static function getAvailableTimeslotsForLocation(int $locationId): array {
        $sql = 'SELECT t.id, t.date, t.modifier 
                FROM timeslots t 
                WHERE t.id NOT IN (
                    SELECT timeslot_id FROM location_availability WHERE location_id = ?
                )
                ORDER BY t.date DESC, t.modifier';

        $st = self::pdo()->prepare($sql);
        $st->execute([$locationId]);
        return $st->fetchAll();
    }

    // Get available locations for a timeslot (excluding already assigned)
    public static function getAvailableLocationsForTimeslot(int $timeslotId): array {
        $sql = 'SELECT l.id, l.name, l.description 
                FROM locations l 
                WHERE l.id NOT IN (
                    SELECT location_id FROM location_availability WHERE timeslot_id = ?
                )
                ORDER BY l.name';

        $st = self::pdo()->prepare($sql);
        $st->execute([$timeslotId]);
        return $st->fetchAll();
    }

    // === Import-specific methods ===

    // Get all existing location-timeslot combinations for duplicate checking
    public static function getAllLocationTimeslots(): array {
        $sql = 'SELECT la.location_id, la.timeslot_id, l.name as location_name, t.date, t.modifier
                FROM location_availability la
                INNER JOIN locations l ON la.location_id = l.id
                INNER JOIN timeslots t ON la.timeslot_id = t.id';
        
        $st = self::pdo()->prepare($sql);
        $st->execute();
        
        $results = [];
        while ($row = $st->fetch()) {
            $key = $row['location_name'] . '|' . $row['date'] . '|' . $row['modifier'];
            $results[$key] = [
                'location_id' => $row['location_id'],
                'timeslot_id' => $row['timeslot_id']
            ];
        }
        return $results;
    }
}
