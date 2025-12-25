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

// Get divisions and locations for dropdowns
$divisions = TeamManagement::getAllDivisions();
$locations = TeamManagement::getAllLocations();

$msg = null;
$err = null;

// Handle messages from evaluation script
if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
}
if (isset($_GET['err'])) {
    $err = $_GET['err'];
}

// For repopulating form after errors
$form = [];
if (isset($_GET['name'])) {
    $form['name'] = $_GET['name'];
} else {
    $form['name'] = $team['name'];
}

if (isset($_GET['division_id'])) {
    $form['division_id'] = $_GET['division_id'];
} else {
    $form['division_id'] = $team['division_id'];
}

if (isset($_GET['description'])) {
    $form['description'] = $_GET['description'];
} else {
    $form['description'] = $team['description'];
}

if (isset($_GET['previous_year_ranking'])) {
    $form['previous_year_ranking'] = $_GET['previous_year_ranking'];
} else {
    $form['previous_year_ranking'] = $team['previous_year_ranking'] ?? '';
}

if (isset($_GET['preferred_location_id'])) {
    $form['preferred_location_id'] = $_GET['preferred_location_id'];
} else {
    $form['preferred_location_id'] = $team['preferred_location_id'] ?? '';
}

header_html('Edit Team');
?>

<h2>Edit Team</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/teams/edit_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?=(int)$id?>">
    
    <label>Team Name
      <input type="text" name="name" value="<?=h($form['name'])?>" required placeholder="e.g., Red Sox">
    </label>

    <label>Division
      <select name="division_id" required>
        <option value="">Select a division</option>
        <?php foreach ($divisions as $division): ?>
          <option value="<?= (int)$division['id'] ?>" <?= ((int)$form['division_id'] === (int)$division['id']) ? 'selected' : '' ?>>
            <?= h($division['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>Description (optional)
      <textarea name="description" rows="3" placeholder="Optional description"><?=h($form['description'])?></textarea>
    </label>

    <label>Previous Year Ranking (optional)
      <input type="number" name="previous_year_ranking" value="<?=h($form['previous_year_ranking'])?>" min="1" placeholder="e.g., 1, 2, 3...">
    </label>

    <label>Preferred Location (optional)
      <select name="preferred_location_id">
        <option value="">-- None --</option>
        <?php foreach ($locations as $location): ?>
          <option value="<?= (int)$location['id'] ?>" <?= ((string)$form['preferred_location_id'] !== '' && (int)$form['preferred_location_id'] === (int)$location['id']) ? 'selected' : '' ?>>
            <?= h($location['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <small>The location where this team prefers to play (home gym)</small>
    </label>

    <div class="actions">
      <button class="primary" type="submit">Save Changes</button>
      <a class="button" href="/teams/">Cancel</a>
    </div>
  </form>
</div>

<div class="card">
  <h3>Delete Team</h3>
  <p>Deleting this team is permanent and cannot be undone.</p>
  <form method="post" action="/teams/delete_eval.php" onsubmit="return confirm('Are you sure you want to delete this team? This action cannot be undone.');" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?=(int)$id?>">
    
    <div class="actions">
      <button type="submit" class="button" style="background-color:#dc3545;color:white;">Delete Team</button>
    </div>
  </form>
</div>

<?php footer_html(); ?>
