<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LocationManagement.php';
Application::init();
require_login();

// Get location ID
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: /locations/?err=' . urlencode('Invalid location ID.'));
    exit;
}

// Load location
$location = LocationManagement::findById($id);
if (!$location) {
    header('Location: /locations/?err=' . urlencode('Location not found.'));
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
    $form['name'] = $location['name'];
}

if (isset($_GET['description'])) {
    $form['description'] = $_GET['description'];
} else {
    $form['description'] = $location['description'];
}

header_html('Edit Location');
?>

<h2>Edit Location</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/locations/edit_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?=(int)$id?>">
    
    <label>Location Name
      <input type="text" name="name" value="<?=h($form['name'])?>" required placeholder="e.g., Central Park Field">
    </label>

    <label>Description (optional)
      <textarea name="description" rows="3" placeholder="Optional description"><?=h($form['description'])?></textarea>
    </label>

    <div class="actions">
      <button class="primary" type="submit">Save Changes</button>
      <a class="button" href="/locations/">Cancel</a>
    </div>
  </form>
</div>

<div class="card">
  <h3>Delete Location</h3>
  <p>Deleting this location is permanent and cannot be undone.</p>
  <form method="post" action="/locations/delete_eval.php" onsubmit="return confirm('Are you sure you want to delete this location? This action cannot be undone.');" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?=(int)$id?>">
    
    <div class="actions">
      <button type="submit" class="button" style="background-color:#dc3545;color:white;">Delete Location</button>
    </div>
  </form>
</div>

<?php footer_html(); ?>
