<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/TimeslotManagement.php';
require_once __DIR__ . '/../lib/LocationAvailabilityManagement.php';
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
if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
}
if (isset($_GET['err'])) {
    $err = $_GET['err'];
}

// Get locations for this timeslot
$locations = LocationAvailabilityManagement::getLocationsForTimeslot($id);

// Format timeslot display
$timeslotDisplay = TimeslotManagement::formatDate($timeslot['date']);
if ($timeslot['modifier']) {
    $timeslotDisplay .= ' - ' . $timeslot['modifier'];
}

header_html('Time Slot Locations');
?>

<h2>Time Slot locations: <?= h($timeslotDisplay) ?></h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px;">
  <a class="button" href="/timeslots/">← Back to Timeslots</a>
  <a class="button" href="/timeslots/location_add.php?timeslot_id=<?= (int)$id ?>">Add Location</a>
</div>

<?php if (empty($locations)): ?>
  <div class="card">
    <p class="small">No locations assigned to this timeslot.</p>
  </div>
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
            <td><?= h($location['name']) ?></td>
            <td><?= h($location['description'] ?? '') ?></td>
            <td class="small">
              <a href="/locations/availability_remove.php?location_id=<?= (int)$location['id'] ?>&timeslot_id=<?= (int)$id ?>&from=timeslot" 
                 onclick="return confirm('Remove this location from this timeslot?');" 
                 class="button small">Remove</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
