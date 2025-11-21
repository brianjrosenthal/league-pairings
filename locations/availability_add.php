<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LocationManagement.php';
require_once __DIR__ . '/../lib/LocationAvailabilityManagement.php';
require_once __DIR__ . '/../lib/TimeslotManagement.php';
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

$msg = null;
$err = null;

// Handle messages
if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
}
if (isset($_GET['err'])) {
    $err = $_GET['err'];
}

// Get available timeslots (not already assigned to this location)
$availableTimeslots = LocationAvailabilityManagement::getAvailableTimeslotsForLocation($locationId);

header_html('Add Timeslot to Location');
?>

<h2>Add Timeslot to Location: <?= h($location['name']) ?></h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <?php if (empty($availableTimeslots)): ?>
    <p class="small">No more timeslots available to add to this location.</p>
    <div class="actions">
      <a class="button" href="/locations/availability.php?id=<?= (int)$locationId ?>">← Back to Availability</a>
    </div>
  <?php else: ?>
    <form method="post" action="/locations/availability_add_eval.php" class="stack">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="location_id" value="<?= (int)$locationId ?>">
      
      <label>Select Timeslot
        <select name="timeslot_id" required>
          <option value="">Select a timeslot</option>
          <?php foreach ($availableTimeslots as $timeslot): ?>
            <option value="<?= (int)$timeslot['id'] ?>">
              <?= h(TimeslotManagement::formatDate($timeslot['date'])) ?><?= $timeslot['modifier'] ? ' - ' . h($timeslot['modifier']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <div class="actions">
        <button class="primary" type="submit">Add Timeslot</button>
        <a class="button" href="/locations/availability.php?id=<?= (int)$locationId ?>">Cancel</a>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php footer_html(); ?>
