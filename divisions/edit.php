<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/DivisionManagement.php';
Application::init();
require_login();

// Get division ID
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: /divisions.php?err=' . urlencode('Invalid division ID.'));
    exit;
}

// Load division
$division = DivisionManagement::findById($id);
if (!$division) {
    header('Location: /divisions.php?err=' . urlencode('Division not found.'));
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
if (isset($_GET['name'])) {
    $form['name'] = $_GET['name'];
} else {
    $form['name'] = $division['name'];
}

header_html('Edit Division');
?>

<h2>Edit Division</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/divisions/edit_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?=(int)$id?>">
    
    <label>Division Name
      <input type="text" name="name" value="<?=h($form['name'])?>" required placeholder="e.g., Grade 5-6">
    </label>

    <div class="actions">
      <button class="primary" type="submit">Save Changes</button>
      <a class="button" href="/divisions.php">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
