<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

class TimeslotManagement {
    private static function pdo(): PDO {
        return pdo();
    }

    private static function str(string $v): string {
        return trim($v);
    }

    // Activity logging
    private static function log(string $action, ?int $timeslotId, array $details = []): void {
        try {
            $ctx = UserContext::getLoggedInUserContext();
            $meta = $details;
            if ($timeslotId !== null && !array_key_exists('timeslot_id', $meta)) {
                $meta['timeslot_id'] = (int)$timeslotId;
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

    // Create a new timeslot
    public static function createTimeslot(UserContext $ctx, string $date, string $modifier): int {
        self::assertLoggedIn($ctx);
        
        $date = self::str($date);
        $modifier = self::str($modifier);
        
        if ($date === '') {
            throw new InvalidArgumentException('Date is required.');
        }

        // Validate date format
        if (!self::isValidDate($date)) {
            throw new InvalidArgumentException('Invalid date format.');
        }

        // Check if date+modifier combination already exists
        if (self::timeslotExists($date, $modifier)) {
            throw new InvalidArgumentException('A timeslot with this date and modifier already exists.');
        }

        $st = self::pdo()->prepare(
            "INSERT INTO timeslots (date, modifier) VALUES (?, ?)"
        );
        $st->execute([$date, $modifier]);
        $id = (int)self::pdo()->lastInsertId();
        
        self::log('timeslot.create', $id, ['date' => $date, 'modifier' => $modifier]);
        
        return $id;
    }

    // Validate date format
    private static function isValidDate(string $date): bool {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    // Normalize date to Y-m-d format (accepts multiple formats)
    public static function normalizeDate(string $date): ?string {
        $date = trim($date);
        
        // Try MM/DD/YYYY format first (common US format)
        $d = \DateTime::createFromFormat('m/d/Y', $date);
        if ($d && $d->format('m/d/Y') === $date) {
            return $d->format('Y-m-d');
        }
        
        // Try M/D/YYYY format (without leading zeros)
        $d = \DateTime::createFromFormat('n/j/Y', $date);
        if ($d && $d->format('n/j/Y') === $date) {
            return $d->format('Y-m-d');
        }
        
        // Try "January 3, 2025" format (full month name)
        $d = \DateTime::createFromFormat('F j, Y', $date);
        if ($d && $d->format('F j, Y') === $date) {
            return $d->format('Y-m-d');
        }
        
        // Try "Jan 3, 2025" format (abbreviated month name)
        $d = \DateTime::createFromFormat('M j, Y', $date);
        if ($d && $d->format('M j, Y') === $date) {
            return $d->format('Y-m-d');
        }
        
        // Try YYYY-MM-DD format (ISO format)
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        if ($d && $d->format('Y-m-d') === $date) {
            return $date; // Already in correct format
        }
        
        return null; // Invalid format
    }

    // Check if timeslot exists
    public static function timeslotExists(string $date, string $modifier, ?int $excludeId = null): bool {
        $date = self::str($date);
        $modifier = self::str($modifier);
        
        if ($excludeId === null) {
            $st = self::pdo()->prepare('SELECT 1 FROM timeslots WHERE date = ? AND modifier = ? LIMIT 1');
            $st->execute([$date, $modifier]);
        } else {
            $st = self::pdo()->prepare('SELECT 1 FROM timeslots WHERE date = ? AND modifier = ? AND id != ? LIMIT 1');
            $st->execute([$date, $modifier, $excludeId]);
        }
        
        return (bool)$st->fetchColumn();
    }

    // Find timeslot by ID
    public static function findById(int $id): ?array {
        $st = self::pdo()->prepare('SELECT * FROM timeslots WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // List all timeslots
    public static function listTimeslots(): array {
        $sql = 'SELECT * FROM timeslots ORDER BY date ASC, modifier';

        $st = self::pdo()->prepare($sql);
        $st->execute();
        return $st->fetchAll();
    }

    // Update timeslot
    public static function updateTimeslot(UserContext $ctx, int $id, string $date, string $modifier): bool {
        self::assertLoggedIn($ctx);
        
        $date = self::str($date);
        $modifier = self::str($modifier);
        
        if ($date === '') {
            throw new InvalidArgumentException('Date is required.');
        }

        // Validate date format
        if (!self::isValidDate($date)) {
            throw new InvalidArgumentException('Invalid date format.');
        }

        // Check if date+modifier combination already exists (excluding current timeslot)
        if (self::timeslotExists($date, $modifier, $id)) {
            throw new InvalidArgumentException('A timeslot with this date and modifier already exists.');
        }

        $st = self::pdo()->prepare('UPDATE timeslots SET date = ?, modifier = ? WHERE id = ?');
        $ok = $st->execute([$date, $modifier, $id]);
        
        if ($ok) {
            self::log('timeslot.update', $id, ['date' => $date, 'modifier' => $modifier]);
        }
        
        return $ok;
    }

    // Delete timeslot
    public static function deleteTimeslot(UserContext $ctx, int $id): bool {
        self::assertLoggedIn($ctx);
        
        // Get timeslot info before deleting for logging
        $timeslot = self::findById($id);
        
        $st = self::pdo()->prepare('DELETE FROM timeslots WHERE id = ?');
        $ok = $st->execute([$id]);
        
        if ($ok && $timeslot) {
            self::log('timeslot.delete', $id, ['date' => $timeslot['date'], 'modifier' => $timeslot['modifier']]);
        }
        
        return $ok;
    }

    // Format date for display
    public static function formatDate(string $date): string {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        if ($d) {
            return $d->format('l, F j, Y'); // e.g., "Saturday, December 6, 2025"
        }
        return $date;
    }

    // === Import-specific methods ===

    // Find timeslot by date and modifier
    public static function findByDateAndModifier(string $date, string $modifier): ?array {
        $date = self::str($date);
        $modifier = self::str($modifier);
        
        $st = self::pdo()->prepare('SELECT * FROM timeslots WHERE date = ? AND modifier = ? LIMIT 1');
        $st->execute([$date, $modifier]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // Find or create timeslot (for import)
    public static function findOrCreateTimeslot(UserContext $ctx, string $date, string $modifier): int {
        $date = self::str($date);
        $modifier = self::str($modifier);
        
        // Validate date format
        if (!self::isValidDate($date)) {
            throw new InvalidArgumentException('Invalid date format.');
        }
        
        // Try to find existing timeslot
        $existing = self::findByDateAndModifier($date, $modifier);
        if ($existing) {
            return (int)$existing['id'];
        }
        
        // Create new timeslot
        return self::createTimeslot($ctx, $date, $modifier);
    }

    // Delete all timeslots (and cascading deletes team_availability and location_availability)
    public static function deleteAllTimeslots(UserContext $ctx): int {
        self::assertLoggedIn($ctx);
        
        // Get count before deletion for logging
        $st = self::pdo()->prepare('SELECT COUNT(*) FROM timeslots');
        $st->execute();
        $count = (int)$st->fetchColumn();
        
        if ($count === 0) {
            return 0;
        }
        
        // Delete all timeslots (CASCADE will handle team_availability and location_availability)
        $st = self::pdo()->prepare('DELETE FROM timeslots');
        $st->execute();
        
        self::log('timeslots.delete_all', null, ['count' => $count]);
        
        return $count;
    }
}
