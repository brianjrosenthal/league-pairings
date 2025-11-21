<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/TeamManagement.php';
Application::init();
require_login();

$me = current_user();

// Handle messages from add/edit operations
$msg = null;
$err = null;
if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
}
if (isset($_GET['err'])) {
    $err = $_GET['err'];
}

// Get all teams with records
$teams = TeamManagement::listTeamsWithRecords();

header_html('Teams');
?>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Teams</h2>
  <a class="button" href="/teams/add.php">Add</a>
</div>

<?php if (empty($teams)): ?>
  <p class="small">No teams found.</p>
<?php else: ?>
  <div class="card">
    <table class="list">
      <thead>
        <tr>
          <th>Name</th>
          <th>Division</th>
          <th>Description</th>
          <th>Won</th>
          <th>Lost</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($teams as $team): ?>
          <tr>
            <td><?= h($team['name'] ?? '') ?></td>
            <td><?= h($team['division_name'] ?? '') ?></td>
            <td><?= h($team['description'] ?? '') ?></td>
            <td><?= isset($team['games_won']) ? (int)$team['games_won'] : '' ?></td>
            <td><?= isset($team['games_lost']) ? (int)$team['games_lost'] : '' ?></td>
            <td class="small">
              <a class="button small" href="/teams/previous_games.php?id=<?= (int)$team['id'] ?>">Previous Games</a>
              <a class="button small" href="/teams/availability.php?id=<?= (int)$team['id'] ?>">Availability</a>
              <a class="button small" href="/teams/edit_record.php?id=<?= (int)$team['id'] ?>">Edit Record</a>
              <a class="button small" href="/teams/edit.php?id=<?= (int)$team['id'] ?>">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
