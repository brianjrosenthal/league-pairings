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

// Get all teams
$teams = TeamManagement::listTeams();

header_html('Teams');
?>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Teams</h2>
  <div style="display:flex;gap:8px;">
    <?php if (!empty($teams) && $me['is_admin']): ?>
      <form method="post" action="/teams/delete_all_teams_eval.php" style="display:inline;margin:0;" 
            onsubmit="return confirm('Are you sure you want to delete ALL teams? This will permanently delete all teams, their availability records, and previous games. This action cannot be undone.');">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <button type="submit" class="button" style="background:#d32f2f;color:white;border:none;cursor:pointer;">
          Delete All Teams
        </button>
      </form>
    <?php endif; ?>
    <a class="button" href="/teams/import_previous_games_step_1.php">Import Previous Games</a>
    <a class="button" href="/teams/import_last_year_rankings_step_1.php">Import Last Year's Rankings</a>
    <a class="button" href="/teams/import_availability_step_1.php">Import Team Availability</a>
    <a class="button" href="/teams/import_step_1.php">Import</a>
    <a class="button" href="/teams/add.php">Add</a>
  </div>
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
          <th>Preferred Location</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($teams as $team): ?>
          <tr>
            <td><?= h($team['name'] ?? '') ?></td>
            <td><?= h($team['division_name'] ?? '') ?></td>
            <td><?= !empty($team['preferred_location_name']) ? h($team['preferred_location_name']) : '<span style="color:#999;">—</span>' ?></td>
            <td class="small">
              <a class="button small" href="/teams/previous_games.php?id=<?= (int)$team['id'] ?>">Previous Games</a>
              <a class="button small" href="/teams/availability.php?id=<?= (int)$team['id'] ?>">Availability</a>
              <a class="button small" href="/teams/edit.php?id=<?= (int)$team['id'] ?>">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
