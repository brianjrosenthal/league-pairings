<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/TimeslotManagement.php';
require_once __DIR__ . '/../lib/TeamAvailabilityManagement.php';
Application::init();
require_login();

// Get timeslot ID
$timeslotId = isset($_GET['timeslot_id']) ? (int)$_GET['timeslot_id'] : 0;
if ($timeslotId <= 0) {
    header('Location: /timeslots/?err=' . urlencode('Invalid timeslot ID.'));
    exit;
}

// Load timeslot
$timeslot = TimeslotManagement::findById($timeslotId);
if (!$timeslot) {
    header('Location: /timeslots/?err=' . urlencode('Timeslot not found.'));
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

// Get available teams (not already assigned to this timeslot)
$availableTeams = TeamAvailabilityManagement::getAvailableTeamsForTimeslot($timeslotId);

// Format timeslot display
$timeslotDisplay = TimeslotManagement::formatDate($timeslot['date']);
if ($timeslot['modifier']) {
    $timeslotDisplay .= ' - ' . $timeslot['modifier'];
}

header_html('Add Team to Timeslot');
?>

<h2>Add Team to Timeslot: <?= h($timeslotDisplay) ?></h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <?php if (empty($availableTeams)): ?>
    <p class="small">No more teams available to add to this timeslot.</p>
    <div class="actions">
      <a class="button" href="/timeslots/teams.php?id=<?= (int)$timeslotId ?>">← Back to Teams</a>
    </div>
  <?php else: ?>
    <form method="post" action="/timeslots/team_add_eval.php" class="stack">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="timeslot_id" value="<?= (int)$timeslotId ?>">
      
      <label>Select Team
        <select name="team_id" required>
          <option value="">Select a team</option>
          <?php foreach ($availableTeams as $team): ?>
            <option value="<?= (int)$team['id'] ?>">
              <?= h($team['name']) ?> (<?= h($team['division_name']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <div class="actions">
        <button class="primary" type="submit">Add Team</button>
        <a class="button" href="/timeslots/teams.php?id=<?= (int)$timeslotId ?>">Cancel</a>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php footer_html(); ?>
