<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/TeamManagement.php';
require_once __DIR__ . '/../lib/TeamAvailabilityManagement.php';
require_once __DIR__ . '/../lib/TimeslotManagement.php';
Application::init();
require_login();

// Get team ID
$teamId = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
if ($teamId <= 0) {
    header('Location: /teams/?err=' . urlencode('Invalid team ID.'));
    exit;
}

// Load team
$team = TeamManagement::findById($teamId);
if (!$team) {
    header('Location: /teams/?err=' . urlencode('Team not found.'));
    exit;
}

$msg = null;
$err = null;

// Handle messages
if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
}
if (isset($_GET['err'])) {
    $err = $_GET['err'];
}

// Get available timeslots (not already assigned to this team)
$availableTimeslots = TeamAvailabilityManagement::getAvailableTimeslotsForTeam($teamId);

header_html('Add Timeslot to Team');
?>

<h2>Add Timeslot to Team: <?= h($team['name']) ?></h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <?php if (empty($availableTimeslots)): ?>
    <p class="small">No more timeslots available to add to this team.</p>
    <div class="actions">
      <a class="button" href="/teams/availability.php?id=<?= (int)$teamId ?>">← Back to Availability</a>
    </div>
  <?php else: ?>
    <form method="post" action="/teams/availability_add_eval.php" class="stack">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="team_id" value="<?= (int)$teamId ?>">
      
      <label>Select Timeslot
        <select name="timeslot_id" required>
          <option value="">Select a timeslot</option>
          <?php foreach ($availableTimeslots as $timeslot): ?>
            <option value="<?= (int)$timeslot['id'] ?>">
              <?= h(TimeslotManagement::formatDate($timeslot['date'])) ?><?= $timeslot['modifier'] ? ' - ' . h($timeslot['modifier']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <div class="actions">
        <button class="primary" type="submit">Add Timeslot</button>
        <a class="button" href="/teams/availability.php?id=<?= (int)$teamId ?>">Cancel</a>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php footer_html(); ?>
