<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

class TeamManagement {
    private static function pdo(): PDO {
        return pdo();
    }

    private static function str(string $v): string {
        return trim($v);
    }

    // Activity logging
    private static function log(string $action, ?int $teamId, array $details = []): void {
        try {
            $ctx = UserContext::getLoggedInUserContext();
            $meta = $details;
            if ($teamId !== null && !array_key_exists('team_id', $meta)) {
                $meta['team_id'] = (int)$teamId;
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

    // Create a new team
    public static function createTeam(UserContext $ctx, int $divisionId, string $name, string $description): int {
        self::assertLoggedIn($ctx);
        
        $name = self::str($name);
        $description = self::str($description);
        
        if ($name === '') {
            throw new InvalidArgumentException('Team name is required.');
        }

        if ($divisionId <= 0) {
            throw new InvalidArgumentException('Valid division is required.');
        }

        // Verify division exists
        if (!self::divisionExists($divisionId)) {
            throw new InvalidArgumentException('Selected division does not exist.');
        }

        $st = self::pdo()->prepare(
            "INSERT INTO teams (division_id, name, description) VALUES (?, ?, ?)"
        );
        $st->execute([$divisionId, $name, $description]);
        $id = (int)self::pdo()->lastInsertId();
        
        self::log('team.create', $id, ['name' => $name, 'division_id' => $divisionId]);
        
        return $id;
    }

    // Check if division exists
    private static function divisionExists(int $divisionId): bool {
        $st = self::pdo()->prepare('SELECT 1 FROM divisions WHERE id = ? LIMIT 1');
        $st->execute([$divisionId]);
        return (bool)$st->fetchColumn();
    }

    // Find team by ID
    public static function findById(int $id): ?array {
        $st = self::pdo()->prepare('SELECT * FROM teams WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // List all teams with division names
    public static function listTeams(): array {
        $sql = 'SELECT t.id, t.name, t.description, t.division_id, d.name as division_name, t.created_at 
                FROM teams t 
                INNER JOIN divisions d ON t.division_id = d.id 
                ORDER BY d.name, t.name';

        $st = self::pdo()->prepare($sql);
        $st->execute();
        return $st->fetchAll();
    }

    // Get all divisions for dropdown
    public static function getAllDivisions(): array {
        $sql = 'SELECT id, name FROM divisions ORDER BY name';
        $st = self::pdo()->prepare($sql);
        $st->execute();
        return $st->fetchAll();
    }

    // Update team
    public static function updateTeam(UserContext $ctx, int $id, int $divisionId, string $name, string $description): bool {
        self::assertLoggedIn($ctx);
        
        $name = self::str($name);
        $description = self::str($description);
        
        if ($name === '') {
            throw new InvalidArgumentException('Team name is required.');
        }

        if ($divisionId <= 0) {
            throw new InvalidArgumentException('Valid division is required.');
        }

        // Verify division exists
        if (!self::divisionExists($divisionId)) {
            throw new InvalidArgumentException('Selected division does not exist.');
        }

        $st = self::pdo()->prepare('UPDATE teams SET division_id = ?, name = ?, description = ? WHERE id = ?');
        $ok = $st->execute([$divisionId, $name, $description, $id]);
        
        if ($ok) {
            self::log('team.update', $id, ['name' => $name, 'division_id' => $divisionId]);
        }
        
        return $ok;
    }

    // Delete team
    public static function deleteTeam(UserContext $ctx, int $id): bool {
        self::assertLoggedIn($ctx);
        
        // Get team info before deleting for logging
        $team = self::findById($id);
        
        $st = self::pdo()->prepare('DELETE FROM teams WHERE id = ?');
        $ok = $st->execute([$id]);
        
        if ($ok && $team) {
            self::log('team.delete', $id, ['name' => $team['name'], 'division_id' => $team['division_id']]);
        }
        
        return $ok;
    }
}
