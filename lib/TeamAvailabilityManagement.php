<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

class TeamAvailabilityManagement {
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

    // Add team availability (team-timeslot relationship)
    public static function addAvailability(UserContext $ctx, int $teamId, int $timeslotId): bool {
        self::assertLoggedIn($ctx);
        
        if ($teamId <= 0 || $timeslotId <= 0) {
            throw new InvalidArgumentException('Valid team and timeslot are required.');
        }

        // Check if already exists
        if (self::isAvailable($teamId, $timeslotId)) {
            throw new InvalidArgumentException('This availability already exists.');
        }

        $st = self::pdo()->prepare(
            "INSERT INTO team_availability (team_id, timeslot_id) VALUES (?, ?)"
        );
        $ok = $st->execute([$teamId, $timeslotId]);
        
        if ($ok) {
            self::log('team_availability.add', [
                'team_id' => $teamId,
                'timeslot_id' => $timeslotId
            ]);
        }
        
        return $ok;
    }

    // Remove team availability
    public static function removeAvailability(UserContext $ctx, int $teamId, int $timeslotId): bool {
        self::assertLoggedIn($ctx);
        
        if ($teamId <= 0 || $timeslotId <= 0) {
            throw new InvalidArgumentException('Valid team and timeslot are required.');
        }

        $st = self::pdo()->prepare(
            "DELETE FROM team_availability WHERE team_id = ? AND timeslot_id = ?"
        );
        $ok = $st->execute([$teamId, $timeslotId]);
        
        if ($ok) {
            self::log('team_availability.remove', [
                'team_id' => $teamId,
                'timeslot_id' => $timeslotId
            ]);
        }
        
        return $ok;
    }

    // Check if team is available for a timeslot
    public static function isAvailable(int $teamId, int $timeslotId): bool {
        $st = self::pdo()->prepare(
            'SELECT 1 FROM team_availability WHERE team_id = ? AND timeslot_id = ? LIMIT 1'
        );
        $st->execute([$teamId, $timeslotId]);
        return (bool)$st->fetchColumn();
    }

    // Get all timeslots for a team (with timeslot details)
    public static function getTimeslotsForTeam(int $teamId): array {
        $sql = 'SELECT t.id, t.date, t.modifier 
                FROM timeslots t 
                INNER JOIN team_availability ta ON t.id = ta.timeslot_id 
                WHERE ta.team_id = ? 
                ORDER BY t.date ASC, t.modifier';

        $st = self::pdo()->prepare($sql);
        $st->execute([$teamId]);
        return $st->fetchAll();
    }

    // Get all teams for a timeslot (with team details)
    public static function getTeamsForTimeslot(int $timeslotId): array {
        $sql = 'SELECT tm.id, tm.name, tm.description, d.name as division_name 
                FROM teams tm 
                INNER JOIN team_availability ta ON tm.id = ta.team_id 
                INNER JOIN divisions d ON tm.division_id = d.id 
                WHERE ta.timeslot_id = ? 
                ORDER BY tm.name';

        $st = self::pdo()->prepare($sql);
        $st->execute([$timeslotId]);
        return $st->fetchAll();
    }

    // Get available timeslots for a team (excluding already assigned)
    public static function getAvailableTimeslotsForTeam(int $teamId): array {
        $sql = 'SELECT t.id, t.date, t.modifier 
                FROM timeslots t 
                WHERE t.id NOT IN (
                    SELECT timeslot_id FROM team_availability WHERE team_id = ?
                )
                ORDER BY t.date DESC, t.modifier';

        $st = self::pdo()->prepare($sql);
        $st->execute([$teamId]);
        return $st->fetchAll();
    }

    // Get available teams for a timeslot (excluding already assigned)
    public static function getAvailableTeamsForTimeslot(int $timeslotId): array {
        $sql = 'SELECT tm.id, tm.name, tm.description, d.name as division_name 
                FROM teams tm 
                INNER JOIN divisions d ON tm.division_id = d.id 
                WHERE tm.id NOT IN (
                    SELECT team_id FROM team_availability WHERE timeslot_id = ?
                )
                ORDER BY tm.name';

        $st = self::pdo()->prepare($sql);
        $st->execute([$timeslotId]);
        return $st->fetchAll();
    }
}
