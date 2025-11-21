<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/DivisionManagement.php';
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
if (isset($_GET['name'])) {
    $form['name'] = $_GET['name'];
}

header_html('Add Division');
?>

<h2>Add Division</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/divisions/add_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    
    <label>Division Name
      <input type="text" name="name" value="<?=h($form['name'] ?? '')?>" required placeholder="e.g., Grade 5-6">
    </label>

    <div class="actions">
      <button class="primary" type="submit">Add Division</button>
      <a class="button" href="/divisions.php">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
