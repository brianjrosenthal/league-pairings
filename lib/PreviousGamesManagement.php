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
        
        if ($team1Score === $team2Score) {
            throw new InvalidArgumentException('Tie games are not allowed. Teams must have different scores.');
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
        
        if ($team1Score === $team2Score) {
            throw new InvalidArgumentException('Tie games are not allowed. Teams must have different scores.');
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

    // Get teams by division for dropdowns
    public static function getTeamsByDivision(int $divisionId): array {
        $sql = 'SELECT t.id, t.name, d.name as division_name 
                FROM teams t 
                INNER JOIN divisions d ON t.division_id = d.id 
                WHERE t.division_id = ?
                ORDER BY t.name';
        
        $st = self::pdo()->prepare($sql);
        $st->execute([$divisionId]);
        return $st->fetchAll();
    }

    // === Import-specific methods ===

    // Find game by date and teams (for duplicate detection during import)
    public static function findGameByDateAndTeams(string $date, int $team1Id, int $team2Id): ?array {
        // Check both team orders since a game could be stored either way
        $sql = 'SELECT * FROM previous_games 
                WHERE date = ? 
                AND ((team_1_id = ? AND team_2_id = ?) OR (team_1_id = ? AND team_2_id = ?))
                LIMIT 1';
        
        $st = self::pdo()->prepare($sql);
        $st->execute([$date, $team1Id, $team2Id, $team2Id, $team1Id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // Create game with optional scores (for import)
    public static function createGameWithOptionalScores(UserContext $ctx, string $date, int $team1Id, int $team2Id, ?int $team1Score, ?int $team2Score): int {
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
        
        // Validate scores if provided
        if ($team1Score !== null && $team1Score < 0) {
            throw new InvalidArgumentException('Team 1 score must be non-negative.');
        }
        
        if ($team2Score !== null && $team2Score < 0) {
            throw new InvalidArgumentException('Team 2 score must be non-negative.');
        }

        $st = self::pdo()->prepare(
            "INSERT INTO previous_games (date, team_1_id, team_2_id, team_1_score, team_2_score) 
             VALUES (?, ?, ?, ?, ?)"
        );
        $st->execute([$date, $team1Id, $team2Id, $team1Score, $team2Score]);
        $id = (int)self::pdo()->lastInsertId();
        
        self::log('previous_game.create_import', $id, [
            'date' => $date,
            'team_1_id' => $team1Id,
            'team_2_id' => $team2Id,
            'team_1_score' => $team1Score,
            'team_2_score' => $team2Score
        ]);
        
        return $id;
    }

    // Update only scores for existing game (for import)
    public static function updateGameScores(UserContext $ctx, int $id, ?int $team1Score, ?int $team2Score): bool {
        self::assertLoggedIn($ctx);
        
        // Validate scores if provided
        if ($team1Score !== null && $team1Score < 0) {
            throw new InvalidArgumentException('Team 1 score must be non-negative.');
        }
        
        if ($team2Score !== null && $team2Score < 0) {
            throw new InvalidArgumentException('Team 2 score must be non-negative.');
        }

        $st = self::pdo()->prepare(
            'UPDATE previous_games 
             SET team_1_score = ?, team_2_score = ? 
             WHERE id = ?'
        );
        $ok = $st->execute([$team1Score, $team2Score, $id]);
        
        if ($ok) {
            self::log('previous_game.update_scores_import', $id, [
                'team_1_score' => $team1Score,
                'team_2_score' => $team2Score
            ]);
        }
        
        return $ok;
    }
}
