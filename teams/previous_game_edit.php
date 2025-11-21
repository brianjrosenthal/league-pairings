<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/PreviousGamesManagement.php';
Application::init();
require_login();

// Get game ID and team ID (for navigation back)
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$teamId = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;

if ($id <= 0) {
    header('Location: /teams/?err=' . urlencode('Invalid game ID.'));
    exit;
}

if ($teamId <= 0) {
    header('Location: /teams/?err=' . urlencode('Invalid team ID.'));
    exit;
}

// Load game
$game = PreviousGamesManagement::findGameById($id);
if (!$game) {
    header('Location: /teams/previous_games.php?id=' . $teamId . '&err=' . urlencode('Game not found.'));
    exit;
}

// Get all teams for dropdowns
$allTeams = PreviousGamesManagement::getAllTeams();

// Handle pre-populated form data from validation errors
$date = isset($_GET['date']) ? $_GET['date'] : $game['date'];
$team1Id = isset($_GET['team_1_id']) ? (int)$_GET['team_1_id'] : (int)$game['team_1_id'];
$team2Id = isset($_GET['team_2_id']) ? (int)$_GET['team_2_id'] : (int)$game['team_2_id'];
$team1Score = isset($_GET['team_1_score']) ? (int)$_GET['team_1_score'] : (int)$game['team_1_score'];
$team2Score = isset($_GET['team_2_score']) ? (int)$_GET['team_2_score'] : (int)$game['team_2_score'];

$msg = null;
$err = null;
if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
}
if (isset($_GET['err'])) {
    $err = $_GET['err'];
}

header_html('Edit Previous Game');
?>

<h2>Edit Previous Game</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/teams/previous_game_edit_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <input type="hidden" name="team_id" value="<?= (int)$teamId ?>">
    
    <label>Date
      <input type="date" name="date" value="<?= h($date) ?>" required>
    </label>

    <label>Team 1
      <select name="team_1_id" required>
        <?php foreach ($allTeams as $t): ?>
          <option value="<?= (int)$t['id'] ?>" <?= $team1Id === (int)$t['id'] ? 'selected' : '' ?>>
            <?= h($t['name']) ?> (<?= h($t['division_name']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>Team 1 Score
      <input type="number" name="team_1_score" value="<?= (int)$team1Score ?>" min="0" required>
    </label>

    <label>Team 2
      <select name="team_2_id" required>
        <?php foreach ($allTeams as $t): ?>
          <option value="<?= (int)$t['id'] ?>" <?= $team2Id === (int)$t['id'] ? 'selected' : '' ?>>
            <?= h($t['name']) ?> (<?= h($t['division_name']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>Team 2 Score
      <input type="number" name="team_2_score" value="<?= (int)$team2Score ?>" min="0" required>
    </label>

    <div class="actions">
      <button class="primary" type="submit">Save Game</button>
      <a class="button" href="/teams/previous_games.php?id=<?= (int)$teamId ?>">Cancel</a>
    </div>
  </form>

  <hr style="margin: 20px 0;">
  
  <form method="post" action="/teams/previous_game_delete.php" onsubmit="return confirm('Are you sure you want to delete this game?');">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <input type="hidden" name="team_id" value="<?= (int)$teamId ?>">
    <button type="submit" class="button">Delete Game</button>
  </form>
</div>

<?php footer_html(); ?>
