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

<h3 style="margin-top: 32px; margin-bottom: 16px;">How it works</h3>
<p style="margin-bottom: 24px;">
    The pairing generation system uses a sophisticated multi-phase scheduling algorithm to create optimal game schedules.
</p>

<div class="card" style="margin-bottom: 16px;">
    <h4>Step 1: Configuration</h4>
    <p>
        You specify the date range for the schedule and set an optimization timeout. The system defaults to scheduling games from the next Sunday through the following Thursday (skipping the next 6 days to allow preparation time).
    </p>
</div>

<div class="card" style="margin-bottom: 16px;">
    <h4>Step 2: Review & Validation</h4>
    <p>
        The system analyzes your data and shows:
    </p>
    <ul class="small" style="margin-left: 20px; margin-top: 8px;">
        <li>How many timeslot-location combinations are available in your date range</li>
        <li>Team availability for each division</li>
        <li>Warnings about teams with no available timeslots</li>
    </ul>
    <p style="margin-top: 8px;">
        This helps you catch data issues before generating the schedule.
    </p>
</div>

<h4 style="margin-top: 24px; margin-bottom: 8px;">Step 3: Schedule Generation</h4>
<p style="margin-bottom: 16px;">
    The system uses the <strong>TrueMultiPhaseScheduler</strong> algorithm, which runs in four phases:
</p>

<h4 style="margin-top: 24px; margin-bottom: 8px;">Step 3 - Phase 1: Try to schedule at least one game per team.</h4>
<p style="margin-bottom: 16px;">
    The goal of Phase 1 is to make sure each team (for a particular bracket) plays one game during the week.
</p>

<div class="card" style="margin-bottom: 16px;">
    <h5>Phase 1A: Maximum Coverage (10% of time)</h5>
    <p style="margin-bottom: 8px;"><strong>Goal:</strong> Maximize the number of teams that play at least once per week.</p>
    <p style="margin-bottom: 8px;"><strong>Algorithm:</strong> Greedy round-robin approach</p>
    <p style="margin-bottom: 8px;"><strong>Process:</strong></p>
    <ol class="small" style="margin-left: 20px;">
        <li><strong>Round-robin scheduling:</strong> The algorithm processes divisions in rounds, attempting to schedule one game per division in each round</li>
        <li><strong>Team selection:</strong> Within each division, teams are sorted by strength (using previous year ranking adjusted by current season wins/losses)</li>
        <li><strong>Matchup selection:</strong> For each unscheduled team, starting with the strongest:
            <ul style="margin-left: 20px; margin-top: 4px;">
                <li><strong>First pass:</strong> Try to pair with teams that haven't played each other in the last 3 weeks</li>
                <li><strong>Second pass:</strong> If no recent-free matchups exist, pair with any available team</li>
            </ul>
        </li>
        <li><strong>Timeslot assignment:</strong> Once a matchup is found:
            <ul style="margin-left: 20px; margin-top: 4px;">
                <li>Identify timeslots where both teams are available</li>
                <li>Filter to timeslots in the current week that haven't been used yet</li>
                <li><strong>6-tier cascading priority system:</strong>
                    <ol style="margin-left: 20px; margin-top: 4px;">
                        <li><strong>Team Preferred location + Sunday</strong> (ideal: team's home gym on Sunday)</li>
                        <li><strong>Division Preferred location + Sunday</strong> (division's preferred gym on Sunday)</li>
                        <li><strong>Sunday</strong> (any available location on Sunday)</li>
                        <li><strong>Team Preferred location</strong> (team's home gym on any day)</li>
                        <li><strong>Division Preferred location</strong> (division's preferred gym on any day)</li>
                        <li><strong>Any available timeslot-location</strong> (fallback)</li>
                    </ol>
                </li>
                <li>If both teams have different preferred locations, both are considered equally</li>
                <li>All Sunday scenarios are prioritized before weekday scenarios</li>
            </ul>
        </li>
        <li><strong>Constraint enforcement:</strong> Each team plays at most once per week in this phase</li>
        <li><strong>Termination:</strong> Continue rounds until no more games can be scheduled</li>
    </ol>
    <p class="small" style="margin-top: 12px;"><strong>Key features:</strong></p>
    <ul class="small" style="margin-left: 20px;">
        <li>Ensures strongest teams are prioritized for scheduling</li>
        <li>Avoids recent rematches when possible</li>
        <li>Respects all team and location availability constraints</li>
        <li>Provides good initial coverage before optimization phases</li>
    </ul>
</div>

<div class="card" style="margin-bottom: 16px;">
    <h5>Phase 1B: Comprehensive Optimal (10% of time)</h5>
    <p style="margin-bottom: 8px;"><strong>Goal:</strong> Schedule as many unscheduled teams as possible (teams that didn't get scheduled in Phase 1A).</p>
    <p style="margin-bottom: 8px;"><strong>Algorithm:</strong> Fill-in scheduling using greedy approach</p>
    <p style="margin-bottom: 8px;"><strong>Process:</strong></p>
    <ol class="small" style="margin-left: 20px;">
        <li><strong>Identify unscheduled teams:</strong> For each division, find teams that have no games scheduled this week</li>
        <li><strong>Process each unscheduled team:</strong> For each team without a game:
            <ul style="margin-left: 20px; margin-top: 4px;">
                <li><strong>Find available partners:</strong> Look for any team in the division that has fewer than max games per week</li>
                <li><strong>Two-pass matchup selection:</strong>
                    <ul style="margin-left: 20px; margin-top: 4px;">
                        <li><strong>First pass:</strong> Try to pair with teams that haven't played each other in the last 3 weeks</li>
                        <li><strong>Second pass:</strong> If no recent-free matchups exist, pair with any available team</li>
                    </ul>
                </li>
            </ul>
        </li>
        <li><strong>Timeslot assignment:</strong> Once a matchup is found:
            <ul style="margin-left: 20px; margin-top: 4px;">
                <li>Identify timeslots where both teams are available</li>
                <li>Filter to timeslots in the current week that haven't been used yet</li>
                <li><strong>6-tier cascading priority system:</strong>
                    <ol style="margin-left: 20px; margin-top: 4px;">
                        <li><strong>Team Preferred location + Sunday</strong> (ideal: team's home gym on Sunday)</li>
                        <li><strong>Division Preferred location + Sunday</strong> (division's preferred gym on Sunday)</li>
                        <li><strong>Sunday</strong> (any available location on Sunday)</li>
                        <li><strong>Team Preferred location</strong> (team's home gym on any day)</li>
                        <li><strong>Division Preferred location</strong> (division's preferred gym on any day)</li>
                        <li><strong>Any available timeslot-location</strong> (fallback)</li>
                    </ol>
                </li>
                <li>If both teams have different preferred locations, both are considered equally</li>
                <li>All Sunday scenarios are prioritized before weekday scenarios</li>
            </ul>
        </li>
        <li><strong>Weekly limit enforcement:</strong> Teams can have up to max_games_per_week (allows second game if capacity exists)</li>
    </ol>
    <p class="small" style="margin-top: 12px;"><strong>Key features:</strong></p>
    <ul class="small" style="margin-left: 20px;">
        <li>Targets teams that were completely left out in Phase 1A</li>
        <li>More flexible than 1A (allows pairing with already-scheduled teams)</li>
        <li>Maintains preference for avoiding recent rematches</li>
        <li>Respects all availability and capacity constraints</li>
    </ul>
</div>

<div class="card" style="margin-bottom: 16px;">
    <h5>Phase 1C: Strategic Displacement (10% of time)</h5>
    <p style="margin-bottom: 8px;"><strong>Goal:</strong> Schedule remaining unscheduled teams by substituting them into existing games.</p>
    <p style="margin-bottom: 8px;"><strong>Algorithm:</strong> Team substitution with guaranteed rescheduling</p>
    <p style="margin-bottom: 8px;"><strong>Process:</strong></p>
    <ol class="small" style="margin-left: 20px;">
        <li><strong>Identify unscheduled teams:</strong> For each division, find teams still without games this week</li>
        <li><strong>Find substitution opportunities:</strong> For each unscheduled team:
            <ul style="margin-left: 20px; margin-top: 4px;">
                <li><strong>Examine existing games</strong> in the same division</li>
                <li>For each game, consider substituting the unscheduled team for either team in the game</li>
            </ul>
        </li>
        <li><strong>Validate substitution:</strong> Before swapping, verify:
            <ul style="margin-left: 20px; margin-top: 4px;">
                <li>The unscheduled team is available for the game's timeslot</li>
                <li>The team being displaced can be <strong>immediately rescheduled</strong> with a different partner</li>
                <li>The displaced team's new partner hasn't played them recently (prefer fresh matchups)</li>
            </ul>
        </li>
        <li><strong>Execute swap atomically:</strong>
            <ul style="margin-left: 20px; margin-top: 4px;">
                <li>Remove the original game</li>
                <li>Add the modified game (with substituted team)</li>
                <li>Add the new game (for displaced team)</li>
                <li>If any step fails, roll back all changes</li>
            </ul>
        </li>
        <li><strong>Avoid cascading disruption:</strong> Only perform swaps that maintain or improve schedule quality</li>
    </ol>
    <p class="small" style="margin-top: 12px;"><strong>Key features:</strong></p>
    <ul class="small" style="margin-left: 20px;">
        <li>Creates scheduling opportunities through strategic reshuffling</li>
        <li>Guarantees displaced teams are immediately rescheduled (no team left worse off)</li>
        <li>Maintains all constraints while improving coverage</li>
        <li>Uses atomic transactions to ensure schedule integrity</li>
    </ul>
</div>

<div class="card" style="margin-bottom: 16px;">
    <h5>Phase 2: Greedy Capacity Filling (70% of time)</h5>
    <p style="margin-bottom: 8px;"><strong>Goal:</strong> Maximize timeslot-location utilization while ensuring fair distribution across all teams.</p>
    <p style="margin-bottom: 8px;"><strong>Algorithm:</strong> Round-robin with fair team selection and strength-based matching</p>
    <p style="margin-bottom: 8px;"><strong>Process:</strong></p>
    <ol class="small" style="margin-left: 20px;">
        <li><strong>Round-robin scheduling:</strong> Process divisions in rounds, attempting to schedule one game per division in each round</li>
        <li><strong>Fair team selection (within each division):</strong>
            <ul style="margin-left: 20px; margin-top: 4px;">
                <li>Group teams by current game count this week</li>
                <li>Select from group with fewest games (prioritizes underserved teams)</li>
                <li>Randomly choose within that group</li>
                <li>Track teams that couldn't be scheduled to avoid infinite loops</li>
            </ul>
        </li>
        <li><strong>Strength-based opponent matching:</strong>
            <ul style="margin-left: 20px; margin-top: 4px;">
                <li>Calculate strength distance between focal team and all available opponents</li>
                <li>Sort opponents by similarity (closest strength first)</li>
                <li>Creates more competitive, balanced matchups</li>
            </ul>
        </li>
        <li><strong>Two-pass matchup selection:</strong>
            <ul style="margin-left: 20px; margin-top: 4px;">
                <li><strong>Pass 1:</strong> Try to pair with teams not played in last 3 weeks</li>
                <li><strong>Pass 2:</strong> Allow any pairing if no recent-free matchups exist</li>
            </ul>
        </li>
        <li><strong>Timeslot assignment:</strong> Use same 6-tier location priority as Phase 1:
            <ul style="margin-left: 20px; margin-top: 4px;">
                <li><strong>Tier 1:</strong> Team Preferred + Sunday</li>
                <li><strong>Tier 2:</strong> Division Preferred + Sunday</li>
                <li><strong>Tier 3:</strong> Sunday (any location)</li>
                <li><strong>Tier 4:</strong> Team Preferred (any day)</li>
                <li><strong>Tier 5:</strong> Division Preferred (any day)</li>
                <li><strong>Tier 6:</strong> Any available TSL</li>
            </ul>
        </li>
        <li><strong>Continue rounds</strong> until multiple consecutive rounds produce no new games</li>
        <li><strong>Exhaustive final check:</strong> After round-robin completes:
            <ul style="margin-left: 20px; margin-top: 4px;">
                <li>Systematically check every team pair in every division</li>
                <li>For each pair, check every available timeslot-location</li>
                <li>Ignores soft constraints (recent play, strength matching, team/division preference)</li>
                <li>Enforces only hard constraints (availability, game limits)</li>
                <li>Catches any remaining opportunities for maximum utilization</li>
            </ul>
        </li>
    </ol>
    <p class="small" style="margin-top: 12px;"><strong>Key features:</strong></p>
    <ul class="small" style="margin-left: 20px;">
        <li>Allocates majority of time budget (70%) for maximum game coverage</li>
        <li><strong>Fair distribution:</strong> Teams with fewer games get priority, preventing weaker teams from being underserved</li>
        <li><strong>Balanced matchups:</strong> Strength-based pairing creates more competitive games</li>
        <li>Generator-based approach maintains position across rounds for efficiency</li>
        <li>Exhaustive final check ensures no opportunities are missed</li>
        <li>Respects all hard constraints: daily limits (max 1 game/day per team), weekly limits (max 2 games/week per team)</li>
    </ul>
</div>

<div class="card" style="margin-bottom: 16px;">
    <h4>Step 4: Results</h4>
    <p class="small">
        You'll see the complete schedule with:
    </p>
    <ul class="small" style="margin-left: 20px; margin-top: 8px;">
        <li>All scheduled games organized by date and location</li>
        <li>Team assignments and matchup details</li>
        <li>Options to download as CSV or visualize the schedule</li>
    </ul>
</div>

<div class="card">
    <h4>Constraints</h4>
    <p class="small" style="margin-bottom: 8px;">
        The algorithm respects all constraints including:
    </p>
    <ul class="small" style="margin-left: 20px;">
        <li>Team availability (teams only play when they're available)</li>
        <li>Location availability (games only scheduled at available locations)</li>
        <li>Maximum games per week and per day limits</li>
        <li>Previous game history (avoids recent rematches when possible)</li>
    </ul>
</div>

<?php footer_html(); ?>
