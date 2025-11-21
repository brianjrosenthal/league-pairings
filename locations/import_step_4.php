<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CsvImportHelper.php';
require_once __DIR__ . '/../lib/LocationManagement.php';
Application::init();
require_login();

// Check if we have import data in session
if (!isset($_SESSION['location_import']['preview_data'])) {
    header('Location: /locations/import_step_1.php?err=' . urlencode('Please complete previous steps first.'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /locations/import_step_3.php');
    exit;
}

require_csrf();

$importData = $_SESSION['location_import'];
$previewData = $importData['preview_data'];
$filePath = $importData['file_path'] ?? null;

$addedCount = 0;
$updatedCount = 0;
$errorCount = 0;
$errors = [];

try {
    $ctx = UserContext::getLoggedInUserContext();
    
    // Process each item based on its action
    foreach ($previewData as $item) {
        if ($item['is_duplicate']) {
            continue; // Skip duplicates
        }
        
        try {
            if ($item['action'] === 'add') {
                // Create new location
                LocationManagement::createLocation($ctx, $item['name'], $item['description']);
                $addedCount++;
            } elseif ($item['action'] === 'update') {
                // Update existing location's description
                $existingLocation = LocationManagement::findByName($item['name']);
                if ($existingLocation) {
                    LocationManagement::updateLocationDescription($ctx, (int)$existingLocation['id'], $item['description']);
                    $updatedCount++;
                } else {
                    throw new Exception("Location '{$item['name']}' not found for update");
                }
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
    unset($_SESSION['location_import']);
    
    // Redirect with success message
    $msg = "Import complete! Added {$addedCount} location(s), updated {$updatedCount} location(s).";
    if ($errorCount > 0) {
        $msg .= " {$errorCount} error(s) occurred.";
    }
    
    header('Location: /locations/?msg=' . urlencode($msg));
    exit;
    
} catch (Exception $e) {
    $err = $e->getMessage();
}

// If we get here, something went wrong
header_html('Import Locations - Step 4');
?>

<h2>Import Locations - Step 4 of 4</h2>
<p class="small">Processing import...</p>

<?php if (isset($err)): ?>
  <p class="error"><?=h($err)?></p>
  
  <div class="card">
    <h3>Import Results</h3>
    <p><strong>Successfully added:</strong> <?= (int)$addedCount ?></p>
    <p><strong>Successfully updated:</strong> <?= (int)$updatedCount ?></p>
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
