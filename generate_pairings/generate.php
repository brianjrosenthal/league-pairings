<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/SchedulingManagement.php';
Application::init();
require_login();

$me = current_user();

// Get parameters
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$algorithm = $_GET['algorithm'] ?? 'greedy';

// Validate dates
if (empty($startDate) || empty($endDate)) {
    header('Location: /generate_pairings/step1.php?err=' . urlencode('Please provide both start and end dates.'));
    exit;
}

// Call Python scheduling service
$error = null;
$schedule = null;

try {
    $rawSchedule = SchedulingManagement::callPythonScheduler($startDate, $endDate, $algorithm);
    $schedule = SchedulingManagement::enrichScheduleWithNames($rawSchedule);
} catch (RuntimeException $e) {
    $error = $e->getMessage();
}

header_html('Generated Schedule');
?>

<h2>Generated Game Pairings</h2>

<div style="margin-bottom: 16px;">
    <a href="/generate_pairings/step2.php?start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&algorithm=<?= urlencode($algorithm) ?>" class="button">← Back to Review</a>
    <a href="/generate_pairings/step1.php" class="button">Start Over</a>
</div>

<?php if ($error): ?>
    <div class="error" style="margin-bottom: 24px;">
        <strong>Error:</strong> <?= h($error) ?>
    </div>
    
    <div class="card">
        <h3>Troubleshooting</h3>
        <ul>
            <li>Ensure the Python scheduling service is running: <code>cd service && python server.py</code></li>
            <li>Verify the service is accessible at <code>http://localhost:5001</code></li>
            <li>Check that you have teams, timeslots, and location availability set up</li>
        </ul>
    </div>
<?php else: ?>
    
    <div class="card" style="margin-bottom: 24px;">
        <h3>Schedule Metadata</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-top: 12px;">
            <div>
                <div class="small" style="color: #666;">Date Range</div>
                <div style="font-weight: 600; margin-top: 4px;">
                    <?= h(date('M j, Y', strtotime($startDate))) ?> - <?= h(date('M j, Y', strtotime($endDate))) ?>
                </div>
            </div>
            
            <div>
                <div class="small" style="color: #666;">Games Generated</div>
                <div style="font-weight: 600; margin-top: 4px;">
                    <?= count($schedule['schedule'] ?? []) ?>
                </div>
            </div>
            
            <div>
                <div class="small" style="color: #666;">Algorithm Used</div>
                <div style="font-weight: 600; margin-top: 4px;">
                    <?= h(ucfirst($algorithm)) ?>
                </div>
            </div>
            
            <?php if (!empty($schedule['metadata']['feasible_games_count'])): ?>
            <div>
                <div class="small" style="color: #666;">Feasible Games</div>
                <div style="font-weight: 600; margin-top: 4px;">
                    <?= (int)$schedule['metadata']['feasible_games_count'] ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if (!empty($schedule['warnings'])): ?>
        <div class="announcement" style="margin-bottom: 24px;">
            <strong>Warnings:</strong>
            <ul style="margin: 8px 0 0 0; padding-left: 20px;">
                <?php foreach ($schedule['warnings'] as $warning): ?>
                    <li><?= h($warning) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <?php if (empty($schedule['schedule'])): ?>
        <div class="card">
            <p>No games could be scheduled for the selected date range.</p>
            <p class="small">This may be because:</p>
            <ul class="small">
                <li>No teams have availability during this period</li>
                <li>No location-timeslot combinations are available</li>
                <li>The constraints are too restrictive</li>
            </ul>
        </div>
    <?php else: ?>
        
        <?php
        // Group games by division and date
        $groupedGames = [];
        foreach ($schedule['schedule'] as $game) {
            $division = $game['division_name'];
            $date = $game['date'];
            if (!isset($groupedGames[$division])) {
                $groupedGames[$division] = [];
            }
            if (!isset($groupedGames[$division][$date])) {
                $groupedGames[$division][$date] = [];
            }
            $groupedGames[$division][$date][] = $game;
        }
        
        // Sort divisions and dates
        ksort($groupedGames);
        foreach ($groupedGames as &$dates) {
            ksort($dates);
        }
        ?>
        
        <?php foreach ($groupedGames as $division => $dates): ?>
            <div class="card" style="margin-bottom: 24px;">
                <h3><?= h($division) ?></h3>
                
                <?php foreach ($dates as $date => $games): ?>
                    <h4 style="margin-top: 16px; margin-bottom: 12px; color: #666;">
                        <?= h(date('l, F j, Y', strtotime($date))) ?>
                    </h4>
                    
                    <table class="list">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Location</th>
                                <th>Team A</th>
                                <th>Team B</th>
                                <th style="text-align: right;">Weight</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($games as $game): ?>
                                <tr>
                                    <td style="white-space: nowrap;"><?= h($game['time_modifier'] ?? '') ?></td>
                                    <td><?= h($game['location_name']) ?></td>
                                    <td><?= h($game['team_a_name']) ?></td>
                                    <td><?= h($game['team_b_name']) ?></td>
                                    <td style="text-align: right;">
                                        <?php if ($game['weight'] !== null): ?>
                                            <?= number_format($game['weight'], 2) ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        
        <div class="card">
            <h3>Next Steps</h3>
            <ul>
                <li>Review the generated schedule above</li>
                <li>Record actual game results in <a href="/teams/">Teams → Previous Games</a></li>
                <li>Generate future schedules will take these results into account</li>
            </ul>
        </div>
        
    <?php endif; ?>
    
<?php endif; ?>

<?php footer_html(); ?>
