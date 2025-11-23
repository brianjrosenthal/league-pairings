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
$timeout = (int)($_GET['timeout'] ?? 120);

// Validate dates
if (empty($startDate) || empty($endDate)) {
    header('Location: /generate_pairings/step1.php?err=' . urlencode('Please provide both start and end dates.'));
    exit;
}

if ($startDate > $endDate) {
    header('Location: /generate_pairings/step1.php?err=' . urlencode('Start date must be before end date.'));
    exit;
}

// Get system statistics
$stats = SchedulingManagement::getSystemStats();

// Get timeslot-location combinations in date range
$timeslotLocationCount = SchedulingManagement::getTimeslotLocationCombinations($startDate, $endDate);

// Get team availability by division
$divisionData = SchedulingManagement::getTeamAvailabilityByDivision($startDate, $endDate);

header_html('Generate Pairings - Review');
?>

<h2>Generate Game Pairings - Review</h2>

<div style="margin-bottom: 16px;">
    <a href="/generate_pairings/step1.php" class="button">← Back to Configuration</a>
</div>

<div class="card" style="margin-bottom: 24px;">
    <h3>Schedule Summary</h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-top: 12px;">
        <div>
            <div class="small" style="color: #666;">Date Range</div>
            <div style="font-weight: 600; margin-top: 4px;">
                <?= h(date('M j, Y', strtotime($startDate))) ?> - <?= h(date('M j, Y', strtotime($endDate))) ?>
            </div>
        </div>
        
        <div>
            <div class="small" style="color: #666;">Teams</div>
            <div style="font-weight: 600; margin-top: 4px;">
                <?= $stats['team_count'] ?> across <?= $stats['division_count'] ?> <?= $stats['division_count'] === 1 ? 'division' : 'divisions' ?>
            </div>
        </div>
        
        <div>
            <div class="small" style="color: #666;">Locations</div>
            <div style="font-weight: 600; margin-top: 4px;">
                <?= $stats['location_count'] ?>
            </div>
        </div>
        
        <div>
            <div class="small" style="color: #666;">Available Timeslots</div>
            <div style="font-weight: 600; margin-top: 4px;">
                <?= $timeslotLocationCount ?> timeslot-location <?= $timeslotLocationCount === 1 ? 'combination' : 'combinations' ?>
            </div>
        </div>
    </div>
</div>

<?php if ($timeslotLocationCount === 0): ?>
    <div class="error" style="margin-bottom: 24px;">
        <strong>Warning:</strong> No timeslot-location combinations found in the selected date range. 
        Please ensure you have added timeslots and location availability for this period.
    </div>
<?php endif; ?>

<div class="card" style="margin-bottom: 24px;">
    <h3>Generate Schedule</h3>
    <p class="small" style="margin-bottom: 16px;">
        Algorithm: <strong><?= h(ucfirst($algorithm)) ?></strong>
    </p>
    
    <?php
    $hasWarnings = false;
    foreach ($divisionData as $division) {
        foreach ($division['teams'] as $team) {
            if ($team['available_slots'] === 0) {
                $hasWarnings = true;
                break 2;
            }
        }
    }
    ?>
    
    <?php if ($hasWarnings): ?>
        <div class="announcement" style="margin-bottom: 16px;">
            <strong>Note:</strong> Some teams have no available timeslots and will not be included in the generated schedule.
        </div>
    <?php endif; ?>
    
    <div class="actions">
        <a href="/generate_pairings/generate_async.php?start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&algorithm=<?= urlencode($algorithm) ?>&timeout=<?= urlencode($timeout) ?>" 
           class="button primary"
           <?= $timeslotLocationCount === 0 ? 'disabled style="opacity: 0.5; pointer-events: none;"' : '' ?>>
            Generate Pairings (<?= $timeout ?>s timeout)
        </a>
        <a href="/generate_pairings/step1.php" class="button">Cancel</a>
    </div>
</div>

<div class="card">
    <h3>Team Availability Debugging</h3>
    <p class="small" style="margin-bottom: 16px;">
        Review team availability to ensure all teams have timeslots they can play in.
        Teams with 0 available slots will not be scheduled.
    </p>
    
    <?php if (empty($divisionData)): ?>
        <p class="small" style="color: #999;">No teams found.</p>
    <?php else: ?>
        <?php foreach ($divisionData as $division): ?>
            <div style="margin-bottom: 24px;">
                <h4 style="margin-bottom: 12px;"><?= h($division['division_name']) ?></h4>
                <table class="list">
                    <thead>
                        <tr>
                            <th>Team</th>
                            <th style="text-align: right;">Available Timeslots</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($division['teams'] as $team): ?>
                            <tr <?= $team['available_slots'] === 0 ? 'style="background: #fff3cd;"' : '' ?>>
                                <td><?= h($team['team_name']) ?></td>
                                <td style="text-align: right;">
                                    <?php if ($team['available_slots'] === 0): ?>
                                        <span style="color: #856404; font-weight: 600;">⚠️ <?= $team['available_slots'] ?></span>
                                    <?php else: ?>
                                        <?= $team['available_slots'] ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php footer_html(); ?>
