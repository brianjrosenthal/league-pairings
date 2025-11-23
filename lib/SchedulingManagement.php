<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

class SchedulingManagement {
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

    /**
     * Get system statistics for the scheduling overview
     * Returns: ['team_count', 'division_count', 'location_count', 'previous_games_count']
     */
    public static function getSystemStats(): array {
        $pdo = self::pdo();
        
        // Get team count
        $st = $pdo->query('SELECT COUNT(*) FROM teams');
        $teamCount = (int)$st->fetchColumn();
        
        // Get division count
        $st = $pdo->query('SELECT COUNT(DISTINCT division_id) FROM teams');
        $divisionCount = (int)$st->fetchColumn();
        
        // Get location count
        $st = $pdo->query('SELECT COUNT(*) FROM locations');
        $locationCount = (int)$st->fetchColumn();
        
        // Get previous games count
        $st = $pdo->query('SELECT COUNT(*) FROM previous_games');
        $previousGamesCount = (int)$st->fetchColumn();
        
        return [
            'team_count' => $teamCount,
            'division_count' => $divisionCount,
            'location_count' => $locationCount,
            'previous_games_count' => $previousGamesCount
        ];
    }

    /**
     * Get count of timeslot-location combinations available in the given date range
     */
    public static function getTimeslotLocationCombinations(string $startDate, string $endDate): int {
        $pdo = self::pdo();
        
        $sql = 'SELECT COUNT(DISTINCT CONCAT(t.id, "-", la.location_id)) as combo_count
                FROM timeslots t
                INNER JOIN location_availability la ON t.id = la.timeslot_id
                WHERE t.date BETWEEN ? AND ?';
        
        $st = $pdo->prepare($sql);
        $st->execute([$startDate, $endDate]);
        return (int)$st->fetchColumn();
    }

    /**
     * Get team availability grouped by division for debugging
     * Returns array of divisions with teams and their available timeslot counts
     */
    public static function getTeamAvailabilityByDivision(string $startDate, string $endDate): array {
        $pdo = self::pdo();
        
        $sql = 'SELECT 
                    d.id as division_id,
                    d.name as division_name,
                    t.id as team_id,
                    t.name as team_name,
                    COUNT(DISTINCT ts.id) as available_slots
                FROM divisions d
                INNER JOIN teams t ON d.id = t.division_id
                LEFT JOIN team_availability ta ON t.id = ta.team_id
                LEFT JOIN timeslots ts ON ta.timeslot_id = ts.id AND ts.date BETWEEN ? AND ?
                GROUP BY d.id, d.name, t.id, t.name
                ORDER BY d.name, t.name';
        
        $st = $pdo->prepare($sql);
        $st->execute([$startDate, $endDate]);
        $rows = $st->fetchAll();
        
        // Group by division
        $divisions = [];
        foreach ($rows as $row) {
            $divisionId = $row['division_id'];
            if (!isset($divisions[$divisionId])) {
                $divisions[$divisionId] = [
                    'division_name' => $row['division_name'],
                    'teams' => []
                ];
            }
            $divisions[$divisionId]['teams'][] = [
                'team_id' => $row['team_id'],
                'team_name' => $row['team_name'],
                'available_slots' => (int)$row['available_slots']
            ];
        }
        
        return array_values($divisions);
    }

    /**
     * Start an asynchronous scheduling job
     * Returns the job ID for tracking
     */
    public static function startAsyncScheduler(string $startDate, string $endDate, string $algorithm = 'greedy'): string {
        $port = defined('SCHEDULING_SERVICE_PORT') ? SCHEDULING_SERVICE_PORT : 5001;
        $url = 'http://localhost:' . $port . '/schedule/start';
        
        $params = http_build_query([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'algorithm' => $algorithm
        ]);
        
        $fullUrl = $url . '?' . $params;
        
        self::log('scheduling.async_request', [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'algorithm' => $algorithm
        ]);
        
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 10,
                'header' => 'Content-Type: application/json'
            ]
        ]);
        
        $response = @file_get_contents($fullUrl, false, $context);
        
        if ($response === false) {
            $error = error_get_last();
            self::log('scheduling.error', [
                'error' => 'Failed to connect to Python service',
                'details' => $error['message'] ?? 'Unknown error'
            ]);
            throw new RuntimeException('Failed to connect to scheduling service. Please ensure the Python service is running on port ' . $port . '.');
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid response from scheduling service.');
        }
        
        if (!isset($data['job_id'])) {
            throw new RuntimeException('Scheduling service did not return a job ID.');
        }
        
        return $data['job_id'];
    }

    /**
     * Get the status of an async scheduling job
     */
    public static function getJobStatus(string $jobId): array {
        $port = defined('SCHEDULING_SERVICE_PORT') ? SCHEDULING_SERVICE_PORT : 5001;
        $url = 'http://localhost:' . $port . '/schedule/status/' . urlencode($jobId);
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'header' => 'Content-Type: application/json'
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            throw new RuntimeException('Failed to get job status from scheduling service.');
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid response from scheduling service.');
        }
        
        return $data;
    }

    /**
     * Get the result of a completed async scheduling job
     */
    public static function getJobResult(string $jobId): array {
        $port = defined('SCHEDULING_SERVICE_PORT') ? SCHEDULING_SERVICE_PORT : 5001;
        $url = 'http://localhost:' . $port . '/schedule/result/' . urlencode($jobId);
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'header' => 'Content-Type: application/json'
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            throw new RuntimeException('Failed to get job result from scheduling service.');
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid response from scheduling service.');
        }
        
        return $data;
    }

    /**
     * Call the Python scheduling service (synchronous - for backward compatibility)
     * Returns the raw JSON response from the service
     */
    public static function callPythonScheduler(string $startDate, string $endDate, string $algorithm = 'greedy'): array {
        // Python service URL (port configured in config.local.php)
        $port = defined('SCHEDULING_SERVICE_PORT') ? SCHEDULING_SERVICE_PORT : 5001;
        $url = 'http://localhost:' . $port . '/schedule';
        
        // Build query parameters
        $params = http_build_query([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'algorithm' => $algorithm
        ]);
        
        $fullUrl = $url . '?' . $params;
        
        // Log the scheduling request
        self::log('scheduling.request', [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'algorithm' => $algorithm
        ]);
        
        // Make the request
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 30,
                'header' => 'Content-Type: application/json'
            ]
        ]);
        
        $response = @file_get_contents($fullUrl, false, $context);
        
        if ($response === false) {
            $error = error_get_last();
            self::log('scheduling.error', [
                'error' => 'Failed to connect to Python service',
                'details' => $error['message'] ?? 'Unknown error'
            ]);
            throw new RuntimeException('Failed to connect to scheduling service. Please ensure the Python service is running on port ' . $port . '.');
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::log('scheduling.error', [
                'error' => 'Invalid JSON response',
                'json_error' => json_last_error_msg()
            ]);
            throw new RuntimeException('Invalid response from scheduling service.');
        }
        
        if (!isset($data['success']) || $data['success'] !== true) {
            $errorMsg = $data['error'] ?? 'Unknown error occurred';
            self::log('scheduling.error', [
                'error' => $errorMsg
            ]);
            throw new RuntimeException('Scheduling failed: ' . $errorMsg);
        }
        
        self::log('scheduling.success', [
            'games_generated' => count($data['schedule'] ?? []),
            'algorithm' => $algorithm
        ]);
        
        return $data;
    }

    /**
     * Format schedule data from Python service for display
     * The Python service already returns enriched data, so we just reformat field names
     */
    public static function enrichScheduleWithNames(array $scheduleData): array {
        // Python service already returns enriched data with names
        // Just reformat the field names to match what our display expects
        $formattedSchedule = [];
        
        foreach ($scheduleData['schedule'] ?? [] as $game) {
            $formattedSchedule[] = [
                'date' => $game['date'] ?? '',
                'time_modifier' => $game['time_modifier'] ?? '',
                'location_id' => $game['location_id'] ?? null,
                'location_name' => $game['location'] ?? 'Unknown Location',
                'division_id' => $game['division_id'] ?? null,
                'division_name' => $game['division'] ?? 'Unknown Division',
                'team_a_id' => $game['team_a_id'] ?? null,
                'team_a_name' => $game['team_a'] ?? 'Unknown Team',
                'team_b_id' => $game['team_b_id'] ?? null,
                'team_b_name' => $game['team_b'] ?? 'Unknown Team',
                'weight' => $game['weight'] ?? null
            ];
        }
        
        return [
            'success' => true,
            'schedule' => $formattedSchedule,
            'metadata' => $scheduleData['metadata'] ?? [],
            'warnings' => $scheduleData['warnings'] ?? []
        ];
    }
}
