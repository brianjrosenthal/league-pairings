<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/TimeslotManagement.php';
Application::init();
require_login();

// Get timeslot ID
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: /timeslots/?err=' . urlencode('Invalid timeslot ID.'));
    exit;
}

// Load timeslot
$timeslot = TimeslotManagement::findById($id);
if (!$timeslot) {
    header('Location: /timeslots/?err=' . urlencode('Timeslot not found.'));
    exit;
}

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
if (isset($_GET['date'])) {
    $form['date'] = $_GET['date'];
} else {
    $form['date'] = $timeslot['date'];
}

if (isset($_GET['modifier'])) {
    $form['modifier'] = $_GET['modifier'];
} else {
    $form['modifier'] = $timeslot['modifier'];
}

header_html('Edit Timeslot');
?>

<h2>Edit Timeslot</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/timeslots/edit_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?=(int)$id?>">
    
    <label>Date
      <input type="date" name="date" value="<?=h($form['date'])?>" required>
    </label>

    <label>Modifier (optional)
      <input type="text" name="modifier" value="<?=h($form['modifier'])?>" placeholder="e.g., 9:00 AM, Morning, Game 1">
    </label>

    <div class="actions">
      <button class="primary" type="submit">Save Changes</button>
      <a class="button" href="/timeslots/">Cancel</a>
    </div>
  </form>
</div>

<div class="card">
  <h3>Delete Timeslot</h3>
  <p>Deleting this timeslot is permanent and cannot be undone.</p>
  <form method="post" action="/timeslots/delete_eval.php" onsubmit="return confirm('Are you sure you want to delete this timeslot? This action cannot be undone.');" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?=(int)$id?>">
    
    <div class="actions">
      <button type="submit" class="button" style="background-color:#dc3545;color:white;">Delete Timeslot</button>
    </div>
  </form>
</div>

<?php footer_html(); ?>
