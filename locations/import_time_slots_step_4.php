<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CsvImportHelper.php';
require_once __DIR__ . '/../lib/LocationManagement.php';
require_once __DIR__ . '/../lib/TimeslotManagement.php';
require_once __DIR__ . '/../lib/LocationAvailabilityManagement.php';
Application::init();
require_login();

// Check if we have import data in session
if (!isset($_SESSION['location_timeslot_import']['preview_data'])) {
    header('Location: /locations/import_time_slots_step_1.php?err=' . urlencode('Please complete previous steps first.'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /locations/import_time_slots_step_3.php');
    exit;
}

require_csrf();

$importData = $_SESSION['location_timeslot_import'];
$previewData = $importData['preview_data'];
$filePath = $importData['file_path'] ?? null;

$successCount = 0;
$errorCount = 0;
$errors = [];

try {
    $ctx = UserContext::getLoggedInUserContext();
    
    // Get all location names to IDs mapping
    $locations = LocationManagement::listLocations();
    $locationNames = [];
    foreach ($locations as $loc) {
        $locationNames[$loc['name']] = (int)$loc['id'];
    }
    
    // Process each item
    foreach ($previewData as $item) {
        if ($item['is_duplicate'] || $item['has_error']) {
            continue; // Skip duplicates and errors
        }
        
        try {
            // Look up location ID
            if (!isset($locationNames[$item['location']])) {
                throw new Exception("Location '{$item['location']}' not found");
            }
            $locationId = $locationNames[$item['location']];
            
            // Find or create timeslot
            $timeslotId = TimeslotManagement::findOrCreateTimeslot($ctx, $item['date'], $item['modifier']);
            
            // Create location availability (skip if already exists)
            if (!LocationAvailabilityManagement::isAvailable($locationId, $timeslotId)) {
                LocationAvailabilityManagement::addAvailability($ctx, $locationId, $timeslotId);
                $successCount++;
            } else {
                // Already exists (shouldn't happen due to duplicate detection, but just in case)
                continue;
            }
            
        } catch (Exception $e) {
            $errorCount++;
            $errors[] = "Line {$item['line_number']}: {$e->getMessage()}";
        }
    }
    
    // Clean up temporary file
    if ($filePath && file_exists($filePath)) {
        CsvImportHelper::cleanupTempFile($filePath);
    }
    
    // Clear import session
    unset($_SESSION['location_timeslot_import']);
    
    // Redirect with success message
    $msg = "Import complete! Added {$successCount} location time slot(s).";
    if ($errorCount > 0) {
        $msg .= " {$errorCount} error(s) occurred.";
    }
    
    header('Location: /locations/?msg=' . urlencode($msg));
    exit;
    
} catch (Exception $e) {
    $err = $e->getMessage();
}

// If we get here, something went wrong
header_html('Import Location Time Slots - Step 4');
?>

<h2>Import Location Time Slots - Step 4 of 4</h2>
<p class="small">Processing import...</p>

<?php if (isset($err)): ?>
  <p class="error"><?=h($err)?></p>
  
  <div class="card">
    <h3>Import Results</h3>
    <p><strong>Successfully added:</strong> <?= (int)$successCount ?></p>
    <p><strong>Errors:</strong> <?= (int)$errorCount ?></p>
    
    <?php if (!empty($errors)): ?>
      <h4>Error Details:</h4>
      <ul>
        <?php foreach ($errors as $error): ?>
          <li><?= h($error) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    
    <div class="actions">
      <a class="button" href="/locations/">Return to Locations</a>
    </div>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
