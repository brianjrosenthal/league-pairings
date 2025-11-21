<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/TeamManagement.php';
require_once __DIR__ . '/../lib/PreviousGamesManagement.php';
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

// Get games for this team
$games = PreviousGamesManagement::getGamesForTeam($id);

header_html('Previous Games');
?>

<h2>Previous Games: <?= h($team['name']) ?></h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px;">
  <a class="button" href="/teams/">← Back to Teams</a>
  <a class="button" href="/teams/previous_game_add.php?team_id=<?= (int)$id ?>">Add Game</a>
</div>

<?php if (empty($games)): ?>
  <div class="card">
    <p class="small">No previous games found for this team.</p>
  </div>
<?php else: ?>
  <div class="card">
    <table class="list">
      <thead>
        <tr>
          <th>Date</th>
          <th>Team 1</th>
          <th>Team 2</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($games as $game): ?>
          <tr>
            <td><?= h($game['date']) ?></td>
            <td><?= h($game['team_1_name']) ?> (<?= (int)$game['team_1_score'] ?>)</td>
            <td><?= h($game['team_2_name']) ?> (<?= (int)$game['team_2_score'] ?>)</td>
            <td class="small">
              <a class="button small" href="/teams/previous_game_edit.php?id=<?= (int)$game['id'] ?>&team_id=<?= (int)$id ?>">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
