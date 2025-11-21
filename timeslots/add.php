<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/TimeslotManagement.php';
Application::init();
require_login();

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
}
if (isset($_GET['modifier'])) {
    $form['modifier'] = $_GET['modifier'];
}

header_html('Add Timeslot');
?>

<h2>Add Timeslot</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/timeslots/add_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    
    <label>Date
      <input type="date" name="date" value="<?=h($form['date'] ?? '')?>" required>
    </label>

    <label>Modifier (optional)
      <input type="text" name="modifier" value="<?=h($form['modifier'] ?? '')?>" placeholder="e.g., 9:00 AM, Morning, Game 1">
    </label>

    <div class="actions">
      <button class="primary" type="submit">Add Timeslot</button>
      <a class="button" href="/timeslots/">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
