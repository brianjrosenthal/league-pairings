<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LocationManagement.php';
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
if (isset($_GET['description'])) {
    $form['description'] = $_GET['description'];
}

header_html('Add Location');
?>

<h2>Add Location</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/locations/add_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    
    <label>Location Name
      <input type="text" name="name" value="<?=h($form['name'] ?? '')?>" required placeholder="e.g., Central Park Field">
    </label>

    <label>Description (optional)
      <textarea name="description" rows="3" placeholder="Optional description"><?=h($form['description'] ?? '')?></textarea>
    </label>

    <div class="actions">
      <button class="primary" type="submit">Add Location</button>
      <a class="button" href="/locations/">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
