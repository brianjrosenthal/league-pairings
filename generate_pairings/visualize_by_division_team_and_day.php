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
    
    // Build team game tracking: {team_id: {date: {game_num, location, modifier, opponent}}}
    // First, sort games by date to ensure correct game numbering
    $sortedSchedule = $schedule['schedule'];
    usort($sortedSchedule, function($a, $b) {
        return strcmp($a['date'], $b['date']);
    });
    
    // Group dates by team and week - track unique dates per team per week
    $teamWeekDates = []; // {team_id: {week_start: [dates...]}}
    foreach ($sortedSchedule as $game) {
        $date = $game['date'];
        $timestamp = strtotime($date);
        $dayOfWeek = (int)date('N', $timestamp); // 1=Monday, 7=Sunday
        $daysToSunday = ($dayOfWeek == 7) ? 0 : $dayOfWeek;
        $weekStart = date('Y-m-d', strtotime("-{$daysToSunday} days", $timestamp));
        
        $teamAId = $game['team_a_id'];
        $teamBId = $game['team_b_id'];
        
        if (!isset($teamWeekDates[$teamAId])) {
            $teamWeekDates[$teamAId] = [];
        }
        if (!isset($teamWeekDates[$teamBId])) {
            $teamWeekDates[$teamBId] = [];
        }
        if (!isset($teamWeekDates[$teamAId][$weekStart])) {
            $teamWeekDates[$teamAId][$weekStart] = [];
        }
        if (!isset($teamWeekDates[$teamBId][$weekStart])) {
            $teamWeekDates[$teamBId][$weekStart] = [];
        }
        
        // Add date to the set (array_unique will deduplicate later)
        $teamWeekDates[$teamAId][$weekStart][] = $date;
        $teamWeekDates[$teamBId][$weekStart][] = $date;
    }
    
    // Deduplicate and sort dates for each team/week
    foreach ($teamWeekDates as $teamId => $weeks) {
        foreach ($weeks as $weekStart => $dates) {
            $teamWeekDates[$teamId][$weekStart] = array_unique($dates);
            sort($teamWeekDates[$teamId][$weekStart]);
        }
    }
    
    // Now build the display structure with correct game numbers
    $teamGames = [];
    foreach ($sortedSchedule as $game) {
        $date = $game['date'];
        $timestamp = strtotime($date);
        $dayOfWeek = (int)date('N', $timestamp);
        $daysToSunday = ($dayOfWeek == 7) ? 0 : $dayOfWeek;
        $weekStart = date('Y-m-d', strtotime("-{$daysToSunday} days", $timestamp));
        
        $teamAId = $game['team_a_id'];
        $teamBId = $game['team_b_id'];
        $teamAName = $game['team_a_name'] ?? '';
        $teamBName = $game['team_b_name'] ?? '';
        $location = $game['location_name'] ?? '';
        $modifier = $game['time_modifier'] ?? '';
        
        if (!isset($teamGames[$teamAId])) {
            $teamGames[$teamAId] = ['by_date' => []];
        }
        if (!isset($teamGames[$teamBId])) {
            $teamGames[$teamBId] = ['by_date' => []];
        }
        
        // Calculate game number by finding position of this date in sorted unique dates for this week
        $gameNumA = array_search($date, $teamWeekDates[$teamAId][$weekStart]) + 1;
        $gameNumB = array_search($date, $teamWeekDates[$teamBId][$weekStart]) + 1;
        
        // Only store if not already stored for this date (avoid duplicates)
        if (!isset($teamGames[$teamAId]['by_date'][$date])) {
            $teamGames[$teamAId]['by_date'][$date] = [
                'game_num' => $gameNumA,
                'location' => $location,
                'modifier' => $modifier,
                'opponent' => $teamBName
            ];
        }
        if (!isset($teamGames[$teamBId]['by_date'][$date])) {
            $teamGames[$teamBId]['by_date'][$date] = [
                'game_num' => $gameNumB,
                'location' => $location,
                'modifier' => $modifier,
                'opponent' => $teamAName
            ];
        }
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
                                    $teamName = $team['team_name'];
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
                                                at <?= h($gameInfo['location']) ?>
                                            </div>
                                            <div style="white-space: nowrap;">
                                                <?= h($teamName) ?> vs <?= h($gameInfo['opponent']) ?>
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
