<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LocationManagement.php';
Application::init();
require_login();

$me = current_user();

// Handle messages from add/edit operations
$msg = null;
$err = null;
if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
}
if (isset($_GET['err'])) {
    $err = $_GET['err'];
}

// Get all locations
$locations = LocationManagement::listLocations();

header_html('Locations');
?>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Locations</h2>
  <a class="button" href="/locations/add.php">Add</a>
</div>

<?php if (empty($locations)): ?>
  <p class="small">No locations found.</p>
<?php else: ?>
  <div class="card">
    <table class="list">
      <thead>
        <tr>
          <th>Name</th>
          <th>Description</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($locations as $location): ?>
          <tr>
            <td><?= h($location['name'] ?? '') ?></td>
            <td><?= h($location['description'] ?? '') ?></td>
            <td class="small">
              <a class="button small" href="/locations/edit.php?id=<?= (int)$location['id'] ?>">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
