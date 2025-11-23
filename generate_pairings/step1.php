<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/SchedulingManagement.php';
Application::init();
require_login();

$me = current_user();

// Get system statistics
$stats = SchedulingManagement::getSystemStats();

// Default dates: skip 6 days, then next Sunday to following Thursday
$sixDaysOut = strtotime('+6 days');

// Find the next Sunday at or after 6 days out
$dayOfWeek = date('N', $sixDaysOut); // 1 (Monday) through 7 (Sunday)
if ($dayOfWeek == 7) {
    // Already a Sunday
    $nextSunday = $sixDaysOut;
} else {
    // Calculate days until next Sunday (7 - dayOfWeek)
    $daysUntilSunday = 7 - $dayOfWeek;
    $nextSunday = strtotime("+{$daysUntilSunday} days", $sixDaysOut);
}

// End date is the following Thursday (4 days after Sunday)
$followingThursday = strtotime('+4 days', $nextSunday);

$defaultStartDate = date('Y-m-d', $nextSunday);
$defaultEndDate = date('Y-m-d', $followingThursday);

header_html('Generate Pairings');
?>

<h2>Generate Game Pairings</h2>

<div class="card" style="margin-bottom: 24px;">
    <h3>System Overview</h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-top: 12px;">
        <div>
            <div class="small" style="color: #666;">Teams</div>
            <div style="font-size: 24px; font-weight: 600; margin-top: 4px;">
                <?= $stats['team_count'] ?>
            </div>
            <div class="small" style="margin-top: 4px;">
                Across <?= $stats['division_count'] ?> <?= $stats['division_count'] === 1 ? 'division' : 'divisions' ?>
                <?php if ($me['is_admin']): ?>
                    &middot; <a href="/teams/">Manage Teams</a>
                <?php endif; ?>
            </div>
        </div>
        
        <div>
            <div class="small" style="color: #666;">Locations</div>
            <div style="font-size: 24px; font-weight: 600; margin-top: 4px;">
                <?= $stats['location_count'] ?>
            </div>
            <?php if ($me['is_admin']): ?>
                <div class="small" style="margin-top: 4px;">
                    <a href="/locations/">Manage Locations</a>
                </div>
            <?php endif; ?>
        </div>
        
        <div>
            <div class="small" style="color: #666;">Previous Games This Season</div>
            <div style="font-size: 24px; font-weight: 600; margin-top: 4px;">
                <?= $stats['previous_games_count'] ?>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <h3>Schedule Configuration</h3>
    <p class="small" style="margin-bottom: 16px;">
        Specify the time period over which you would like to generate the next set of pairings.
    </p>
    
    <form method="get" action="/generate_pairings/step2.php" class="stack">
        <label>
            <span>Start Date</span>
            <input type="date" name="start_date" value="<?= h($defaultStartDate) ?>" required>
        </label>
        
        <label>
            <span>End Date</span>
            <input type="date" name="end_date" value="<?= h($defaultEndDate) ?>" required>
        </label>
        
        <label>
            <span>Algorithm</span>
            <select name="algorithm">
                <option value="greedy" selected>Greedy (Fastest, Near-Optimal)</option>
                <option value="ortools">Google OR-Tools (Fast, Optimal)</option>
                <option value="ilp">PuLP ILP (Slower, Optimal)</option>
            </select>
        </label>
        
        <div class="actions">
            <button type="submit" class="button primary">Continue to Review</button>
        </div>
    </form>
</div>

<?php footer_html(); ?>
