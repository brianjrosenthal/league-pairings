<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/TeamManagement.php';
require_once __DIR__ . '/../lib/TeamAvailabilityManagement.php';
require_once __DIR__ . '/../lib/TimeslotManagement.php';
Application::init();
require_login();

// Get team ID
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: /teams/?err=' . urlencode('Invalid team ID.'));
    exit;
}

// Load team
$team = TeamManagement::findById($id);
if (!$team) {
    header('Location: /teams/?err=' . urlencode('Team not found.'));
    exit;
}

$msg = null;
$err = null;
if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
}
if (isset($_GET['err'])) {
    $err = $_GET['err'];
}

// Get timeslots for this team
$timeslots = TeamAvailabilityManagement::getTimeslotsForTeam($id);

header_html('Team Availability');
?>

<h2>Team availability: <?= h($team['name']) ?></h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px;">
  <a class="button" href="/teams/">← Back to Teams</a>
  <a class="button" href="/teams/availability_add.php?team_id=<?= (int)$id ?>">Add Timeslot</a>
</div>

<?php if (empty($timeslots)): ?>
  <div class="card">
    <p class="small">No timeslots assigned to this team.</p>
  </div>
<?php else: ?>
  <div class="card">
    <table class="list">
      <thead>
        <tr>
          <th>Date</th>
          <th>Modifier</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($timeslots as $timeslot): ?>
          <tr>
            <td><?= h(TimeslotManagement::formatDate($timeslot['date'])) ?></td>
            <td><?= h($timeslot['modifier'] ?? '') ?></td>
            <td class="small">
              <a href="/teams/availability_remove.php?team_id=<?= (int)$id ?>&timeslot_id=<?= (int)$timeslot['id'] ?>" 
                 onclick="return confirm('Remove this timeslot from this team?');" 
                 class="button small">Remove</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
