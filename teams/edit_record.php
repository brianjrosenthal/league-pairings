<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/TeamManagement.php';
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

// Get existing record if it exists
$record = TeamManagement::getTeamRecord($id);
$gamesWon = $record ? (int)$record['games_won'] : 0;
$gamesLost = $record ? (int)$record['games_lost'] : 0;

// Handle pre-populated form data from validation errors
if (isset($_GET['games_won'])) {
    $gamesWon = (int)$_GET['games_won'];
}
if (isset($_GET['games_lost'])) {
    $gamesLost = (int)$_GET['games_lost'];
}

$msg = null;
$err = null;
if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
}
if (isset($_GET['err'])) {
    $err = $_GET['err'];
}

header_html('Edit Team Record');
?>

<h2>Edit Team Record: <?= h($team['name']) ?></h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/teams/edit_record_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    
    <label>Games Won
      <input type="number" name="games_won" value="<?= (int)$gamesWon ?>" min="0" required>
    </label>

    <label>Games Lost
      <input type="number" name="games_lost" value="<?= (int)$gamesLost ?>" min="0" required>
    </label>

    <div class="actions">
      <button class="primary" type="submit">Save Record</button>
      <a class="button" href="/teams/">Cancel</a>
    </div>
  </form>

  <?php if ($record): ?>
    <hr style="margin: 20px 0;">
    <form method="post" action="/teams/clear_record_eval.php" onsubmit="return confirm('Are you sure you want to clear this team\'s record?');">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <button type="submit" class="button">Clear Record</button>
    </form>
  <?php endif; ?>
</div>

<?php footer_html(); ?>
