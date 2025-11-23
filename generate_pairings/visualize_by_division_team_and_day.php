<?php
set_time_limit(120);

require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/SchedulingManagement.php';
Application::init();
require_login();

$me = current_user();

// Get job ID
$jobId = $_GET['job_id'] ?? '';

if (empty($jobId)) {
    header('Location: /generate_pairings/step1.php?err=' . urlencode('No job ID provided.'));
    exit;
}

// Get job result
$error = null;
$schedule = null;

try {
    $rawSchedule = SchedulingManagement::getJobResult($jobId);
    
    if (isset($rawSchedule['success']) && $rawSchedule['success'] === true) {
        $schedule = SchedulingManagement::enrichScheduleWithNames($rawSchedule);
    } else {
        $error = $rawSchedule['error'] ?? 'Failed to generate schedule';
    }
} catch (RuntimeException $e) {
    $error = $e->getMessage();
}

// Get parameters from job if available
$startDate = '';
$endDate = '';

if ($schedule) {
    $startDate = $schedule['metadata']['start_date'] ?? '';
    $endDate = $schedule['metadata']['end_date'] ?? '';
}

header_html('Schedule Visualization');
?>

<h2>Schedule Visualization: By Division, Team and Day</h2>

<div style="margin-bottom: 16px;">
    <button type="button" class="button" onclick="window.close()">Close Window</button>
</div>

<?php if ($error): ?>
    <div class="error" style="margin-bottom: 24px;">
        <strong>Error:</strong> <?= h($error) ?>
    </div>
<?php elseif (empty($schedule['schedule'])): ?>
    <div class="card">
        <p>No games in schedule to visualize.</p>
    </div>
<?php else: ?>
    <?php
    // Build visual charts
    
    // 1. Team Schedule Grid (per division)
    // Get all teams by division
    $teamsByDivision = SchedulingManagement::getTeamsByDivision();
    
    // Get all unique dates from schedule
    $allDates = [];
    foreach ($schedule['schedule'] as $game) {
        $allDates[] = $game['date'];
    }
    $allDates = array_unique($allDates);
    sort($allDates);
    
    // Build team game tracking: {team_id: {date: {game_num, location, modifier}}}
    $teamGames = [];
    foreach ($schedule['schedule'] as $game) {
        $date = $game['date'];
        $teamAId = $game['team_a_id'];
        $teamBId = $game['team_b_id'];
        $location = $game['location_name'] ?? '';
        $modifier = $game['modifier'] ?? '';
        
        // Determine week (Sunday-Saturday)
        $timestamp = strtotime($date);
        $dayOfWeek = (int)date('N', $timestamp); // 1=Monday, 7=Sunday
        // Calculate days to subtract to get to Sunday
        $daysToSunday = ($dayOfWeek == 7) ? 0 : $dayOfWeek;
        $weekStart = date('Y-m-d', strtotime("-{$daysToSunday} days", $timestamp));
        
        // Track game number in week for each team
        if (!isset($teamGames[$teamAId])) {
            $teamGames[$teamAId] = ['by_date' => [], 'week_counts' => []];
        }
        if (!isset($teamGames[$teamBId])) {
            $teamGames[$teamBId] = ['by_date' => [], 'week_counts' => []];
        }
        
        // Increment week count
        if (!isset($teamGames[$teamAId]['week_counts'][$weekStart])) {
            $teamGames[$teamAId]['week_counts'][$weekStart] = 0;
        }
        if (!isset($teamGames[$teamBId]['week_counts'][$weekStart])) {
            $teamGames[$teamBId]['week_counts'][$weekStart] = 0;
        }
        
        $teamGames[$teamAId]['week_counts'][$weekStart]++;
        $teamGames[$teamBId]['week_counts'][$weekStart]++;
        
        $gameNumA = $teamGames[$teamAId]['week_counts'][$weekStart];
        $gameNumB = $teamGames[$teamBId]['week_counts'][$weekStart];
        
        $teamGames[$teamAId]['by_date'][$date] = [
            'game_num' => $gameNumA,
            'location' => $location,
            'modifier' => $modifier
        ];
        $teamGames[$teamBId]['by_date'][$date] = [
            'game_num' => $gameNumB,
            'location' => $location,
            'modifier' => $modifier
        ];
    }
    
    ?>
    
    <!-- Team Schedule Grids (one per division) -->
    <?php foreach ($teamsByDivision as $divisionId => $division): ?>
        <div class="card" style="margin-bottom: 24px;">
            <h3>Team Schedule: <?= h($division['division_name']) ?></h3>
            <div style="overflow-x: auto;">
                <table style="border-collapse: collapse; width: 100%; min-width: 600px;">
                    <thead>
                        <tr>
                            <th style="border: 1px solid #ddd; padding: 8px; background: #f5f5f5; text-align: left; position: sticky; left: 0; background: #f5f5f5;">Team</th>
                            <?php foreach ($allDates as $date): ?>
                                <th style="border: 1px solid #ddd; padding: 8px; background: #f5f5f5; text-align: center; min-width: 80px;">
                                    <?= h(date('M j', strtotime($date))) ?><br>
                                    <span style="font-size: 0.8em; font-weight: normal;"><?= h(date('D', strtotime($date))) ?></span>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($division['teams'] as $team): ?>
                            <tr>
                                <td style="border: 1px solid #ddd; padding: 8px; position: sticky; left: 0; background: white;"><?= h($team['team_name']) ?></td>
                                <?php foreach ($allDates as $date): ?>
                                    <?php
                                    $teamId = $team['team_id'];
                                    $hasGame = isset($teamGames[$teamId]['by_date'][$date]);
                                    $gameInfo = $hasGame ? $teamGames[$teamId]['by_date'][$date] : null;
                                    
                                    // Determine color
                                    $bgColor = '#e0e0e0'; // gray for no game
                                    if ($hasGame) {
                                        $bgColor = ($gameInfo['game_num'] == 1) ? '#81c784' : '#64b5f6'; // green for first, blue for subsequent
                                    }
                                    ?>
                                    <td style="border: 1px solid #ddd; padding: 6px 4px; background: <?= $bgColor ?>; text-align: center; font-size: 0.85em; line-height: 1.3;">
                                        <?php if ($hasGame): ?>
                                            <div style="white-space: nowrap;">
                                                <?= h($gameInfo['location']) ?>
                                            </div>
                                            <div style="white-space: nowrap;">
                                                <?= h($gameInfo['modifier']) ?> (<?= h($gameInfo['game_num']) ?>)
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small" style="margin-top: 12px; color: #666;">
                <span style="display: inline-block; width: 16px; height: 16px; background: #81c784; border: 1px solid #ddd; vertical-align: middle;"></span> First game of week &nbsp;
                <span style="display: inline-block; width: 16px; height: 16px; background: #64b5f6; border: 1px solid #ddd; vertical-align: middle;"></span> Subsequent games &nbsp;
                <span style="display: inline-block; width: 16px; height: 16px; background: #e0e0e0; border: 1px solid #ddd; vertical-align: middle;"></span> No game
            </p>
        </div>
    <?php endforeach; ?>
    
<?php endif; ?>

<?php footer_html(); ?>
