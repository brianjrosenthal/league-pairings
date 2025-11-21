<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

class PreviousGamesManagement {
    private static function pdo(): PDO {
        return pdo();
    }

    // Activity logging
    private static function log(string $action, ?int $gameId, array $details = []): void {
        try {
            $ctx = UserContext::getLoggedInUserContext();
            $meta = $details;
            if ($gameId !== null && !array_key_exists('game_id', $meta)) {
                $meta['game_id'] = (int)$gameId;
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

    // Create a new game
    public static function createGame(UserContext $ctx, string $date, int $team1Id, int $team2Id, int $team1Score, int $team2Score): int {
        self::assertLoggedIn($ctx);
        
        // Validation
        if ($date === '') {
            throw new InvalidArgumentException('Date is required.');
        }
        
        if ($team1Id <= 0 || $team2Id <= 0) {
            throw new InvalidArgumentException('Valid teams are required.');
        }
        
        if ($team1Id === $team2Id) {
            throw new InvalidArgumentException('A team cannot play against itself.');
        }
        
        if ($team1Score < 0 || $team2Score < 0) {
            throw new InvalidArgumentException('Scores must be non-negative integers.');
        }

        $st = self::pdo()->prepare(
            "INSERT INTO previous_games (date, team_1_id, team_2_id, team_1_score, team_2_score) 
             VALUES (?, ?, ?, ?, ?)"
        );
        $st->execute([$date, $team1Id, $team2Id, $team1Score, $team2Score]);
        $id = (int)self::pdo()->lastInsertId();
        
        self::log('previous_game.create', $id, [
            'date' => $date,
            'team_1_id' => $team1Id,
            'team_2_id' => $team2Id,
            'team_1_score' => $team1Score,
            'team_2_score' => $team2Score
        ]);
        
        return $id;
    }

    // Find game by ID with team names
    public static function findGameById(int $id): ?array {
        $sql = 'SELECT pg.*, 
                       t1.name as team_1_name, 
                       t2.name as team_2_name
                FROM previous_games pg
                INNER JOIN teams t1 ON pg.team_1_id = t1.id
                INNER JOIN teams t2 ON pg.team_2_id = t2.id
                WHERE pg.id = ? 
                LIMIT 1';
        
        $st = self::pdo()->prepare($sql);
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // Get all games for a specific team (where team is either team_1 or team_2)
    public static function getGamesForTeam(int $teamId): array {
        $sql = 'SELECT pg.*, 
                       t1.name as team_1_name, 
                       t2.name as team_2_name
                FROM previous_games pg
                INNER JOIN teams t1 ON pg.team_1_id = t1.id
                INNER JOIN teams t2 ON pg.team_2_id = t2.id
                WHERE pg.team_1_id = ? OR pg.team_2_id = ?
                ORDER BY pg.date DESC';
        
        $st = self::pdo()->prepare($sql);
        $st->execute([$teamId, $teamId]);
        return $st->fetchAll();
    }

    // Update game
    public static function updateGame(UserContext $ctx, int $id, string $date, int $team1Id, int $team2Id, int $team1Score, int $team2Score): bool {
        self::assertLoggedIn($ctx);
        
        // Validation
        if ($date === '') {
            throw new InvalidArgumentException('Date is required.');
        }
        
        if ($team1Id <= 0 || $team2Id <= 0) {
            throw new InvalidArgumentException('Valid teams are required.');
        }
        
        if ($team1Id === $team2Id) {
            throw new InvalidArgumentException('A team cannot play against itself.');
        }
        
        if ($team1Score < 0 || $team2Score < 0) {
            throw new InvalidArgumentException('Scores must be non-negative integers.');
        }

        $st = self::pdo()->prepare(
            'UPDATE previous_games 
             SET date = ?, team_1_id = ?, team_2_id = ?, team_1_score = ?, team_2_score = ? 
             WHERE id = ?'
        );
        $ok = $st->execute([$date, $team1Id, $team2Id, $team1Score, $team2Score, $id]);
        
        if ($ok) {
            self::log('previous_game.update', $id, [
                'date' => $date,
                'team_1_id' => $team1Id,
                'team_2_id' => $team2Id,
                'team_1_score' => $team1Score,
                'team_2_score' => $team2Score
            ]);
        }
        
        return $ok;
    }

    // Delete game
    public static function deleteGame(UserContext $ctx, int $id): bool {
        self::assertLoggedIn($ctx);
        
        // Get game info before deleting for logging
        $game = self::findGameById($id);
        
        $st = self::pdo()->prepare('DELETE FROM previous_games WHERE id = ?');
        $ok = $st->execute([$id]);
        
        if ($ok && $game) {
            self::log('previous_game.delete', $id, [
                'date' => $game['date'],
                'team_1_id' => $game['team_1_id'],
                'team_2_id' => $game['team_2_id']
            ]);
        }
        
        return $ok;
    }

    // Get all teams for dropdowns
    public static function getAllTeams(): array {
        $sql = 'SELECT t.id, t.name, d.name as division_name 
                FROM teams t 
                INNER JOIN divisions d ON t.division_id = d.id 
                ORDER BY t.name';
        
        $st = self::pdo()->prepare($sql);
        $st->execute();
        return $st->fetchAll();
    }
}
