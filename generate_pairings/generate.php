<?php
set_time_limit(120); // 2 minutes for ILP scheduling operations

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
    <?php if (!empty($schedule['schedule'])): ?>
        <button type="button" class="button" onclick="showExportModal()">Export as CSV</button>
    <?php endif; ?>
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

<!-- CSV Export Modal -->
<?php if (!empty($schedule['schedule'])): ?>
<div id="exportModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h3 style="margin: 0;">Export Schedule as CSV</h3>
            <button type="button" class="modal-close" onclick="closeExportModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p class="small" style="margin-bottom: 12px;">
                Copy the text below and paste it into a CSV file or spreadsheet application.
            </p>
            <textarea id="csvOutput" readonly style="width: 100%; height: 400px; font-family: monospace; font-size: 12px; padding: 8px; border: 1px solid #ddd; border-radius: 4px; resize: vertical;"><?php
                // Generate CSV content
                echo "Date,Day,Division,Time,Location,Team A,Team B,Weight\n";
                foreach ($schedule['schedule'] as $game) {
                    $date = $game['date'];
                    $dayOfWeek = date('l', strtotime($date));
                    $formattedDate = date('m/d/Y', strtotime($date));
                    
                    // Escape CSV fields
                    $division = str_replace('"', '""', $game['division_name']);
                    $time = str_replace('"', '""', $game['time_modifier'] ?? '');
                    $location = str_replace('"', '""', $game['location_name']);
                    $teamA = str_replace('"', '""', $game['team_a_name']);
                    $teamB = str_replace('"', '""', $game['team_b_name']);
                    $weight = $game['weight'] !== null ? number_format($game['weight'], 2) : '';
                    
                    echo "\"{$formattedDate}\",\"{$dayOfWeek}\",\"{$division}\",\"{$time}\",\"{$location}\",\"{$teamA}\",\"{$teamB}\",{$weight}\n";
                }
            ?></textarea>
            <div style="margin-top: 12px;">
                <button type="button" class="button primary" onclick="copyCSV()">Copy to Clipboard</button>
                <button type="button" class="button" onclick="closeExportModal()">Close</button>
            </div>
            <div id="copySuccess" style="display: none; margin-top: 12px; padding: 8px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; color: #155724;">
                ✓ Copied to clipboard!
            </div>
        </div>
    </div>
</div>

<script>
function showExportModal() {
    document.getElementById('exportModal').style.display = 'flex';
    document.getElementById('copySuccess').style.display = 'none';
}

function closeExportModal() {
    document.getElementById('exportModal').style.display = 'none';
}

function copyCSV() {
    const textarea = document.getElementById('csvOutput');
    textarea.select();
    textarea.setSelectionRange(0, 99999); // For mobile devices
    
    try {
        document.execCommand('copy');
        const successMsg = document.getElementById('copySuccess');
        successMsg.style.display = 'block';
        
        // Hide success message after 3 seconds
        setTimeout(() => {
            successMsg.style.display = 'none';
        }, 3000);
    } catch (err) {
        alert('Failed to copy. Please manually select and copy the text.');
    }
}

// Close modal when clicking outside of it
window.onclick = function(event) {
    const modal = document.getElementById('exportModal');
    if (event.target === modal) {
        closeExportModal();
    }
}

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeExportModal();
    }
});
</script>
<?php endif; ?>

<?php footer_html(); ?>
