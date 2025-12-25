<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LocationManagement.php';
require_once __DIR__ . '/../lib/DivisionManagement.php';
Application::init();
require_login();

// Get location ID
$locationId = isset($_GET['location_id']) ? (int)$_GET['location_id'] : 0;
if ($locationId <= 0) {
    header('Location: /locations/?err=' . urlencode('Invalid location ID.'));
    exit;
}

// Load location
$location = LocationManagement::findById($locationId);
if (!$location) {
    header('Location: /locations/?err=' . urlencode('Location not found.'));
    exit;
}

// Get available divisions (not already assigned)
$availableDivisions = LocationManagement::getAvailableDivisionsForLocation($locationId);

header_html('Add Division Affinity');
?>

<h2>Add Division Affinity: <?= h($location['name']) ?></h2>

<div style="margin-bottom:20px;">
  <a class="button" href="/locations/edit.php?id=<?= (int)$locationId ?>">← Back to Location</a>
</div>

<?php if (empty($availableDivisions)): ?>
  <div class="card">
    <p>All divisions have already been assigned to this location.</p>
    <a class="button" href="/locations/edit.php?id=<?= (int)$locationId ?>">Back to Location</a>
  </div>
<?php else: ?>
  <div class="card">
    <form method="post" action="/locations/affinity_add_eval.php" class="stack">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="location_id" value="<?=(int)$locationId?>">
      
      <label>Division
        <select name="division_id" required>
          <option value="">-- Select Division --</option>
          <?php foreach ($availableDivisions as $division): ?>
            <option value="<?= (int)$division['id'] ?>"><?= h($division['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <div class="actions">
        <button class="primary" type="submit">Add Division Affinity</button>
        <a class="button" href="/locations/edit.php?id=<?= (int)$locationId ?>">Cancel</a>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
