<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/TimeslotManagement.php';
require_once __DIR__ . '/../lib/LocationAvailabilityManagement.php';
Application::init();
require_login();

// Get timeslot ID
$timeslotId = isset($_GET['timeslot_id']) ? (int)$_GET['timeslot_id'] : 0;
if ($timeslotId <= 0) {
    header('Location: /timeslots/?err=' . urlencode('Invalid timeslot ID.'));
    exit;
}

// Load timeslot
$timeslot = TimeslotManagement::findById($timeslotId);
if (!$timeslot) {
    header('Location: /timeslots/?err=' . urlencode('Timeslot not found.'));
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

// Get available locations (not already assigned to this timeslot)
$availableLocations = LocationAvailabilityManagement::getAvailableLocationsForTimeslot($timeslotId);

// Format timeslot display
$timeslotDisplay = TimeslotManagement::formatDate($timeslot['date']);
if ($timeslot['modifier']) {
    $timeslotDisplay .= ' - ' . $timeslot['modifier'];
}

header_html('Add Location to Timeslot');
?>

<h2>Add Location to Timeslot: <?= h($timeslotDisplay) ?></h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <?php if (empty($availableLocations)): ?>
    <p class="small">No more locations available to add to this timeslot.</p>
    <div class="actions">
      <a class="button" href="/timeslots/locations.php?id=<?= (int)$timeslotId ?>">← Back to Locations</a>
    </div>
  <?php else: ?>
    <form method="post" action="/timeslots/location_add_eval.php" class="stack">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="timeslot_id" value="<?= (int)$timeslotId ?>">
      
      <label>Select Location
        <select name="location_id" required>
          <option value="">Select a location</option>
          <?php foreach ($availableLocations as $location): ?>
            <option value="<?= (int)$location['id'] ?>">
              <?= h($location['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <div class="actions">
        <button class="primary" type="submit">Add Location</button>
        <a class="button" href="/timeslots/locations.php?id=<?= (int)$timeslotId ?>">Cancel</a>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php footer_html(); ?>
