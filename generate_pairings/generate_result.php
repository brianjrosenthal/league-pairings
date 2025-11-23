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
$algorithm = 'greedy';

if ($schedule) {
    $startDate = $schedule['metadata']['start_date'] ?? '';
    $endDate = $schedule['metadata']['end_date'] ?? '';
    $algorithm = $schedule['metadata']['algorithm'] ?? 'greedy';
}

header_html('Generated Schedule');
?>

<h2>Generated Game Pairings</h2>

<div style="margin-bottom: 16px;">
    <?php if ($startDate && $endDate): ?>
        <a href="/generate_pairings/step2.php?start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&algorithm=<?= urlencode($algorithm) ?>" class="button">← Back to Review</a>
        <a href="/generate_pairings/step1.php?start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&algorithm=<?= urlencode($algorithm) ?>" class="button">Start Over</a>
    <?php else: ?>
        <a href="/generate_pairings/step1.php" class="button">Start Over</a>
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
        
        <?php if (!empty($schedule['schedule'])): ?>
            <div style="margin-top: 16px;">
                <button type="button" class="button" onclick="showExportModal()">Export as CSV</button>
                <a href="/generate_pairings/visualize_by_division_team_and_day.php?job_id=<?= urlencode($jobId) ?>" target="_blank" class="button">Visualize by Division, Team and Day</a>
            </div>
        <?php endif; ?>
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
        // Group games by division only
        $groupedGames = [];
        foreach ($schedule['schedule'] as $game) {
            $division = $game['division_name'];
            if (!isset($groupedGames[$division])) {
                $groupedGames[$division] = [];
            }
            $groupedGames[$division][] = $game;
        }
        
        // Sort divisions
        ksort($groupedGames);
        
        // Sort games within each division by date and time
        foreach ($groupedGames as &$games) {
            usort($games, function($a, $b) {
                $dateCompare = strcmp($a['date'], $b['date']);
                if ($dateCompare !== 0) {
                    return $dateCompare;
                }
                return strcmp($a['time_modifier'] ?? '', $b['time_modifier'] ?? '');
            });
        }
        ?>
        
        <?php foreach ($groupedGames as $division => $games): ?>
            <div class="card" style="margin-bottom: 24px;">
                <h3><?= h($division) ?></h3>
                
                <table class="list">
                    <thead>
                        <tr>
                            <th>Date &amp; Time</th>
                            <th>Location</th>
                            <th>Team A</th>
                            <th>Team B</th>
                            <th style="text-align: right;">Weight</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($games as $game): ?>
                            <tr>
                                <td style="white-space: nowrap;">
                                    <?php
                                    // Format: "Tue Dec 2, 2025 7:00 PM"
                                    $timestamp = strtotime($game['date']);
                                    $dateTime = date('D M j, Y', $timestamp);
                                    if (!empty($game['time_modifier'])) {
                                        $dateTime .= ' ' . $game['time_modifier'];
                                    }
                                    echo h($dateTime);
                                    ?>
                                </td>
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
                
                // Sort schedule by date and time
                $csvGames = $schedule['schedule'];
                usort($csvGames, function($a, $b) {
                    // First compare dates
                    $dateCompare = strcmp($a['date'], $b['date']);
                    if ($dateCompare !== 0) {
                        return $dateCompare;
                    }
                    // If dates are equal, compare times
                    return strcmp($a['time_modifier'] ?? '', $b['time_modifier'] ?? '');
                });
                
                foreach ($csvGames as $game) {
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
