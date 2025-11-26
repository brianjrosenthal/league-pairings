<?php
set_time_limit(120);

require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/CsvScheduleParser.php';
Application::init();
require_login();

$error = null;
$schedule = null;
$stats = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    
    // Get CSV content and mapping
    $csvContent = $_POST['csv_content'] ?? '';
    $mapping = $_POST['mapping'] ?? [];
    
    if (empty($csvContent)) {
        $error = 'No CSV data provided.';
    } elseif (empty($mapping)) {
        $error = 'No column mapping provided.';
    } else {
        // Parse CSV
        $parser = new CsvScheduleParser();
        if (!$parser->parseCSV($csvContent)) {
            $error = 'Failed to parse CSV data.';
        } else {
            // Validate mapping
            $validationErrors = $parser->validateMapping($mapping);
            if (!empty($validationErrors)) {
                $error = 'Invalid column mapping: ' . implode(', ', $validationErrors);
            } else {
                // Convert to schedule format
                $schedule = $parser->convertToScheduleFormat($mapping);
                
                if (empty($schedule)) {
                    $error = 'No valid games found in CSV. Please check the data format.';
                } else {
                    // Get statistics
                    $stats = $parser->getStatistics($schedule);
                }
            }
        }
    }
} else {
    header('Location: /visualize_schedule.php');
    exit;
}

header_html('Schedule Visualization');
?>

<h2>Schedule Visualization: By Division, Team and Day</h2>

<div style="margin-bottom: 16px;">
    <a href="/visualize_schedule.php" class="button">← Upload New CSV</a>
</div>

<?php if ($error): ?>
    <div class="error" style="margin-bottom: 24px;">
        <strong>Error:</strong> <?= h($error) ?>
    </div>
<?php elseif (empty($schedule)): ?>
    <div class="card">
        <p>No games in schedule to visualize.</p>
    </div>
<?php else: ?>
    
    <div class="card" style="margin-bottom: 24px;">
        <h3>Schedule Statistics</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-top: 12px;">
            <div>
                <div class="small" style="color: #666;">Total Games</div>
                <div style="font-weight: 600; margin-top: 4px;"><?= $stats['total_games'] ?></div>
            </div>
            <div>
                <div class="small" style="color: #666;">Divisions</div>
                <div style="font-weight: 600; margin-top: 4px;"><?= $stats['divisions'] ?></div>
            </div>
            <div>
                <div class="small" style="color: #666;">Teams</div>
                <div style="font-weight: 600; margin-top: 4px;"><?= $stats['teams'] ?></div>
            </div>
            <div>
                <div class="small" style="color: #666;">Dates</div>
                <div style="font-weight: 600; margin-top: 4px;"><?= $stats['dates'] ?></div>
            </div>
        </div>
    </div>
    
    <?php
    // Build visualization data structures (similar to visualize_by_division_team_and_day.php)
    
    // Group schedule by division
    $scheduleByDivision = [];
    foreach ($schedule as $game) {
        $divisionName = $game['division_name'];
        if (!isset($scheduleByDivision[$divisionName])) {
            $scheduleByDivision[$divisionName] = [];
        }
        $scheduleByDivision[$divisionName][] = $game;
    }
    
    // Get all unique dates from schedule
    $allDates = [];
    foreach ($schedule as $game) {
        $allDates[] = $game['date'];
    }
    $allDates = array_unique($allDates);
    sort($allDates);
    
    // Build team lists per division
    $teamsByDivision = [];
    foreach ($schedule as $game) {
        $divisionName = $game['division_name'];
        if (!isset($teamsByDivision[$divisionName])) {
            $teamsByDivision[$divisionName] = [];
        }
        $teamsByDivision[$divisionName][$game['team_a_name']] = true;
        $teamsByDivision[$divisionName][$game['team_b_name']] = true;
    }
    
    // Convert to sorted arrays
    foreach ($teamsByDivision as $division => $teams) {
        $teamNames = array_keys($teams);
        sort($teamNames);
        $teamsByDivision[$division] = $teamNames;
    }
    
    // Sort schedule by date to ensure correct game numbering
    $sortedSchedule = $schedule;
    usort($sortedSchedule, function($a, $b) {
        return strcmp($a['date'], $b['date']);
    });
    
    // Group dates by team and week - track unique dates per team per week
    $teamWeekDates = [];
    foreach ($sortedSchedule as $game) {
        $date = $game['date'];
        $timestamp = strtotime($date);
        $dayOfWeek = (int)date('N', $timestamp); // 1=Monday, 7=Sunday
        $daysToSunday = ($dayOfWeek == 7) ? 0 : $dayOfWeek;
        $weekStart = date('Y-m-d', strtotime("-{$daysToSunday} days", $timestamp));
        
        $teamAName = $game['team_a_name'];
        $teamBName = $game['team_b_name'];
        
        if (!isset($teamWeekDates[$teamAName])) {
            $teamWeekDates[$teamAName] = [];
        }
        if (!isset($teamWeekDates[$teamBName])) {
            $teamWeekDates[$teamBName] = [];
        }
        if (!isset($teamWeekDates[$teamAName][$weekStart])) {
            $teamWeekDates[$teamAName][$weekStart] = [];
        }
        if (!isset($teamWeekDates[$teamBName][$weekStart])) {
            $teamWeekDates[$teamBName][$weekStart] = [];
        }
        
        $teamWeekDates[$teamAName][$weekStart][] = $date;
        $teamWeekDates[$teamBName][$weekStart][] = $date;
    }
    
    // Deduplicate and sort dates for each team/week
    foreach ($teamWeekDates as $teamName => $weeks) {
        foreach ($weeks as $weekStart => $dates) {
            $teamWeekDates[$teamName][$weekStart] = array_unique($dates);
            sort($teamWeekDates[$teamName][$weekStart]);
        }
    }
    
    // Build the display structure with correct game numbers
    $teamGames = [];
    foreach ($sortedSchedule as $game) {
        $date = $game['date'];
        $timestamp = strtotime($date);
        $dayOfWeek = (int)date('N', $timestamp);
        $daysToSunday = ($dayOfWeek == 7) ? 0 : $dayOfWeek;
        $weekStart = date('Y-m-d', strtotime("-{$daysToSunday} days", $timestamp));
        
        $teamAName = $game['team_a_name'];
        $teamBName = $game['team_b_name'];
        $location = $game['location_name'] ?? '';
        $modifier = $game['time_modifier'] ?? '';
        
        if (!isset($teamGames[$teamAName])) {
            $teamGames[$teamAName] = ['by_date' => []];
        }
        if (!isset($teamGames[$teamBName])) {
            $teamGames[$teamBName] = ['by_date' => []];
        }
        
        // Calculate game number
        $gameNumA = array_search($date, $teamWeekDates[$teamAName][$weekStart]) + 1;
        $gameNumB = array_search($date, $teamWeekDates[$teamBName][$weekStart]) + 1;
        
        // Only store if not already stored for this date
        if (!isset($teamGames[$teamAName]['by_date'][$date])) {
            $teamGames[$teamAName]['by_date'][$date] = [
                'game_num' => $gameNumA,
                'location' => $location,
                'modifier' => $modifier,
                'opponent' => $teamBName
            ];
        }
        if (!isset($teamGames[$teamBName]['by_date'][$date])) {
            $teamGames[$teamBName]['by_date'][$date] = [
                'game_num' => $gameNumB,
                'location' => $location,
                'modifier' => $modifier,
                'opponent' => $teamAName
            ];
        }
    }
    ?>
    
    <!-- Team Schedule Grids (one per division) -->
    <?php foreach ($scheduleByDivision as $divisionName => $divisionGames): ?>
        <div class="card" style="margin-bottom: 24px;">
            <h3>Team Schedule: <?= h($divisionName) ?></h3>
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
                        <?php foreach ($teamsByDivision[$divisionName] as $teamName): ?>
                            <tr>
                                <td style="border: 1px solid #ddd; padding: 8px; position: sticky; left: 0; background: white;"><?= h($teamName) ?></td>
                                <?php foreach ($allDates as $date): ?>
                                    <?php
                                    $hasGame = isset($teamGames[$teamName]['by_date'][$date]);
                                    $gameInfo = $hasGame ? $teamGames[$teamName]['by_date'][$date] : null;
                                    
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
                                            <?php if (!empty($gameInfo['modifier'])): ?>
                                            <div style="white-space: nowrap;">
                                                <?= h($gameInfo['modifier']) ?> (<?= h($gameInfo['game_num']) ?>)
                                            </div>
                                            <?php else: ?>
                                            <div style="white-space: nowrap;">
                                                Game <?= h($gameInfo['game_num']) ?>
                                            </div>
                                            <?php endif; ?>
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
