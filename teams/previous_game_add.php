<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/TeamManagement.php';
require_once __DIR__ . '/../lib/PreviousGamesManagement.php';
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

// Get teams in the same division for Team 2 dropdown
$divisionTeams = PreviousGamesManagement::getTeamsByDivision((int)$team['division_id']);

// Handle pre-populated form data from validation errors
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$team1Id = isset($_GET['team_1_id']) ? (int)$_GET['team_1_id'] : $teamId;
$team2Id = isset($_GET['team_2_id']) ? (int)$_GET['team_2_id'] : 0;
$team1Score = isset($_GET['team_1_score']) ? (int)$_GET['team_1_score'] : 0;
$team2Score = isset($_GET['team_2_score']) ? (int)$_GET['team_2_score'] : 0;

$msg = null;
$err = null;
if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
}
if (isset($_GET['err'])) {
    $err = $_GET['err'];
}

header_html('Add Previous Game');
?>

<h2>Add Previous Game for: <?= h($team['name']) ?></h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/teams/previous_game_add_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="team_id" value="<?= (int)$teamId ?>">
    
    <label>Date
      <input type="date" name="date" value="<?= h($date) ?>" required>
    </label>

    <div style="margin-bottom: 20px;">
      <strong>Team 1:</strong> <?= h($team['name']) ?>
      <input type="hidden" name="team_1_id" value="<?= (int)$teamId ?>">
    </div>

    <label>Team 1 Score
      <input type="number" name="team_1_score" value="<?= (int)$team1Score ?>" min="0" required>
    </label>

    <label>Team 2
      <select name="team_2_id" required>
        <option value="">Select team</option>
        <?php foreach ($divisionTeams as $t): ?>
          <?php if ((int)$t['id'] !== $teamId): ?>
            <option value="<?= (int)$t['id'] ?>" <?= $team2Id === (int)$t['id'] ? 'selected' : '' ?>>
              <?= h($t['name']) ?>
            </option>
          <?php endif; ?>
        <?php endforeach; ?>
      </select>
    </label>

    <label>Team 2 Score
      <input type="number" name="team_2_score" value="<?= (int)$team2Score ?>" min="0" required>
    </label>

    <div class="actions">
      <button class="primary" type="submit">Add Game</button>
      <a class="button" href="/teams/previous_games.php?id=<?= (int)$teamId ?>">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
