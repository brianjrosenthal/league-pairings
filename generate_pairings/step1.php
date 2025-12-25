<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/SchedulingManagement.php';
Application::init();
require_login();

$me = current_user();

// Get system statistics
$stats = SchedulingManagement::getSystemStats();

// Get parameters from URL or use defaults
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
// Algorithm is now hard-coded to use TrueMultiPhaseScheduler (ortools)
$algorithm = 'ortools';
$timeout = (int)($_GET['timeout'] ?? 120);

// If no dates provided, calculate defaults: skip 6 days, then next Sunday to following Thursday
if (empty($startDate) || empty($endDate)) {
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

    $startDate = date('Y-m-d', $nextSunday);
    $endDate = date('Y-m-d', $followingThursday);
}

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
            <input type="date" name="start_date" value="<?= h($startDate) ?>" required>
        </label>
        
        <label>
            <span>End Date</span>
            <input type="date" name="end_date" value="<?= h($endDate) ?>" required>
        </label>
        
        <div style="padding: 12px 0; border-bottom: 1px solid #e0e0e0; margin-bottom: 16px;">
            <div class="small" style="color: #666; margin-bottom: 4px;">Algorithm</div>
            <div style="font-weight: 600;">TrueMultiPhaseScheduler</div>
        </div>
        
        <label>
            <span>Optimization Timeout (seconds)</span>
            <input type="number" name="timeout" value="<?= h($timeout) ?>" min="5" max="600" step="5" required>
            <div class="small" style="margin-top: 4px; color: #666;">
                Quick test: 15-30 seconds &middot; Full optimization: 120-300 seconds
            </div>
        </label>
        
        <div class="actions">
            <button type="submit" class="button primary">Continue to Review</button>
        </div>
    </form>
</div>

<div class="card">
    <h3>How it works</h3>
    <p class="small" style="margin-bottom: 24px;">
        The pairing generation system uses a sophisticated multi-phase scheduling algorithm to create optimal game schedules.
    </p>
    
    <div style="margin-bottom: 24px;">
        <h4 style="margin-bottom: 8px;">Step 1: Configuration</h4>
        <p class="small">
            You specify the date range for the schedule and set an optimization timeout. The system defaults to scheduling games from the next Sunday through the following Thursday (skipping the next 6 days to allow preparation time).
        </p>
    </div>
    
    <div style="margin-bottom: 24px;">
        <h4 style="margin-bottom: 8px;">Step 2: Review & Validation</h4>
        <p class="small">
            The system analyzes your data and shows:
        </p>
        <ul class="small" style="margin-left: 20px; margin-top: 8px;">
            <li>How many timeslot-location combinations are available in your date range</li>
            <li>Team availability for each division</li>
            <li>Warnings about teams with no available timeslots</li>
        </ul>
        <p class="small" style="margin-top: 8px;">
            This helps you catch data issues before generating the schedule.
        </p>
    </div>
    
    <div style="margin-bottom: 24px;">
        <h4 style="margin-bottom: 8px;">Step 3: Schedule Generation</h4>
        <p class="small" style="margin-bottom: 12px;">
            The system uses the <strong>TrueMultiPhaseScheduler</strong> algorithm, which runs in four phases:
        </p>
        
        <div style="margin-left: 16px; margin-bottom: 12px;">
            <div style="font-weight: 600; margin-bottom: 4px;" class="small">Phase 1A: Maximum Coverage (10% of time)</div>
            <ul class="small" style="margin-left: 20px;">
                <li>Goal: Get as many teams playing at least once per week</li>
                <li>Uses a greedy round-robin approach, processing divisions in order</li>
                <li>Prioritizes stronger teams and avoids recent rematches (within 3 weeks)</li>
                <li>Each team plays at most once per week in this phase</li>
            </ul>
        </div>
        
        <div style="margin-left: 16px; margin-bottom: 12px;">
            <div style="font-weight: 600; margin-bottom: 4px;" class="small">Phase 1B: Comprehensive Optimal (10% of time)</div>
            <ul class="small" style="margin-left: 20px;">
                <li>Goal: Optimize the overall schedule quality using mathematical optimization</li>
                <li>Uses Google OR-Tools to find the best possible schedule</li>
                <li>Balances multiple objectives: game quality, fairness, and constraint satisfaction</li>
                <li>Considers team strength, previous matchups, and availability</li>
            </ul>
        </div>
        
        <div style="margin-left: 16px; margin-bottom: 12px;">
            <div style="font-weight: 600; margin-bottom: 4px;" class="small">Phase 1C: Strategic Displacement (10% of time)</div>
            <ul class="small" style="margin-left: 20px;">
                <li>Goal: Make room for additional games by strategically moving existing games</li>
                <li>Identifies games that could be rescheduled to different timeslots</li>
                <li>Creates capacity for teams that haven't been scheduled yet</li>
                <li>Maintains all constraints while improving overall coverage</li>
            </ul>
        </div>
        
        <div style="margin-left: 16px; margin-bottom: 12px;">
            <div style="font-weight: 600; margin-bottom: 4px;" class="small">Phase 2: Greedy Capacity Filling (70% of time)</div>
            <ul class="small" style="margin-left: 20px;">
                <li>Goal: Fill remaining capacity with additional games</li>
                <li>Allows teams to play up to 2 games per week (respecting the max_games_per_week setting)</li>
                <li>Uses optimization to maximize the number of quality matchups</li>
                <li>Focuses on teams that still have available capacity</li>
            </ul>
        </div>
    </div>
    
    <div style="margin-bottom: 24px;">
        <h4 style="margin-bottom: 8px;">Step 4: Results</h4>
        <p class="small">
            You'll see the complete schedule with:
        </p>
        <ul class="small" style="margin-left: 20px; margin-top: 8px;">
            <li>All scheduled games organized by date and location</li>
            <li>Team assignments and matchup details</li>
            <li>Options to download as CSV or visualize the schedule</li>
        </ul>
    </div>
    
    <div style="padding-top: 16px; border-top: 1px solid #e0e0e0;">
        <p class="small" style="margin-bottom: 8px; font-weight: 600;">
            The algorithm respects all constraints including:
        </p>
        <ul class="small" style="margin-left: 20px;">
            <li>Team availability (teams only play when they're available)</li>
            <li>Location availability (games only scheduled at available locations)</li>
            <li>Maximum games per week and per day limits</li>
            <li>Previous game history (avoids recent rematches when possible)</li>
        </ul>
    </div>
</div>

<?php footer_html(); ?>
