<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CsvImportHelper.php';
require_once __DIR__ . '/../lib/TeamAvailabilityManagement.php';
Application::init();
require_login();

// Check if we have import data
if (!isset($_SESSION['team_availability_import']['preview_data'])) {
    header('Location: /teams/import_availability_step_1.php?err=' . urlencode('Please complete previous steps first.'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /teams/import_availability_step_3.php');
    exit;
}

require_csrf();

$importData = $_SESSION['team_availability_import'];
$filePath = $importData['file_path'] ?? null;

// Decode preview data from POST
$previewData = json_decode($_POST['preview_data'] ?? '[]', true);

$addedCount = 0;
$removedCount = 0;
$errorCount = 0;
$errors = [];
$details = [];

try {
    $ctx = UserContext::getLoggedInUserContext();
    
    // Process each team
    foreach ($previewData as $item) {
        if ($item['has_error']) {
            continue; // Skip teams with errors
        }
        
        $teamId = (int)$item['team_id'];
        $teamName = $item['team_name'];
        $teamDetails = [
            'team' => $teamName,
            'added' => [],
            'removed' => [],
            'errors' => []
        ];
        
        try {
            // Add available timeslots
            if (isset($item['available_timeslots'])) {
                foreach ($item['available_timeslots'] as $timeslotId) {
                    try {
                        // Check if already exists before adding
                        if (!TeamAvailabilityManagement::isAvailable($teamId, $timeslotId)) {
                            TeamAvailabilityManagement::addAvailability($ctx, $teamId, $timeslotId);
                            $teamDetails['added'][] = $timeslotId;
                            $addedCount++;
                        }
                    } catch (Exception $e) {
                        $teamDetails['errors'][] = "Failed to add timeslot {$timeslotId}: {$e->getMessage()}";
                        $errorCount++;
                    }
                }
            }
            
            // Remove unavailable timeslots
            if (isset($item['unavailable_timeslots'])) {
                foreach ($item['unavailable_timeslots'] as $timeslotId) {
                    try {
                        // Check if exists before removing
                        if (TeamAvailabilityManagement::isAvailable($teamId, $timeslotId)) {
                            TeamAvailabilityManagement::removeAvailability($ctx, $teamId, $timeslotId);
                            $teamDetails['removed'][] = $timeslotId;
                            $removedCount++;
                        }
                    } catch (Exception $e) {
                        $teamDetails['errors'][] = "Failed to remove timeslot {$timeslotId}: {$e->getMessage()}";
                        $errorCount++;
                    }
                }
            }
            
        } catch (Exception $e) {
            $teamDetails['errors'][] = $e->getMessage();
            $errorCount++;
        }
        
        // Only add to details if something happened
        if (!empty($teamDetails['added']) || !empty($teamDetails['removed']) || !empty($teamDetails['errors'])) {
            $details[] = $teamDetails;
        }
    }
    
    // Clean up temporary file
    if ($filePath && file_exists($filePath)) {
        CsvImportHelper::cleanupTempFile($filePath);
    }
    
    // Clear import session
    unset($_SESSION['team_availability_import']);
    
} catch (Exception $e) {
    $err = $e->getMessage();
}

header_html('Import Team Availability - Step 4');
?>

<h2>Import Team Availability - Step 4 of 4</h2>
<p class="small">Import complete</p>

<div class="card">
  <h3>Import Summary</h3>
  <p><strong>Successfully added:</strong> <?= (int)$addedCount ?> availability record(s)</p>
  <p><strong>Successfully removed:</strong> <?= (int)$removedCount ?> availability record(s)</p>
  <?php if ($errorCount > 0): ?>
    <p style="color:#d32f2f;"><strong>Errors:</strong> <?= (int)$errorCount ?></p>
  <?php endif; ?>
</div>

<?php if (!empty($details)): ?>
  <div class="card">
    <h3>Detailed Results</h3>
    <?php foreach ($details as $teamDetail): ?>
      <div style="margin-bottom:20px;padding:12px;background:#f5f5f5;border-radius:4px;">
        <h4 style="margin-top:0;"><?= h($teamDetail['team']) ?></h4>
        
        <?php if (!empty($teamDetail['added'])): ?>
          <p style="margin:4px 0;">
            <strong style="color:#388e3c;">✓ Added <?= count($teamDetail['added']) ?> availability record(s)</strong>
            <span class="small" style="color:#666;"> (Timeslot IDs: <?= implode(', ', $teamDetail['added']) ?>)</span>
          </p>
        <?php endif; ?>
        
        <?php if (!empty($teamDetail['removed'])): ?>
          <p style="margin:4px 0;">
            <strong style="color:#f57c00;">⚠️ Removed <?= count($teamDetail['removed']) ?> availability record(s)</strong>
            <span class="small" style="color:#666;"> (Timeslot IDs: <?= implode(', ', $teamDetail['removed']) ?>)</span>
          </p>
        <?php endif; ?>
        
        <?php if (!empty($teamDetail['errors'])): ?>
          <p style="margin:4px 0;color:#d32f2f;">
            <strong>❌ Errors:</strong>
          </p>
          <ul style="margin:4px 0;color:#d32f2f;">
            <?php foreach ($teamDetail['errors'] as $error): ?>
              <li><?= h($error) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="card">
  <div class="actions">
    <a class="button primary" href="/teams/">Return to Teams</a>
  </div>
</div>

<?php footer_html(); ?>
