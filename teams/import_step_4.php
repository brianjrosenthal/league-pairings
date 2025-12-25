<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CsvImportHelper.php';
require_once __DIR__ . '/../lib/TeamManagement.php';
Application::init();
require_login();

// Check if we have import data in session
if (!isset($_SESSION['team_import']['preview_data'])) {
    header('Location: /teams/import_step_1.php?err=' . urlencode('Please complete previous steps first.'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /teams/import_step_3.php');
    exit;
}

require_csrf();

$importData = $_SESSION['team_import'];
$previewData = $importData['preview_data'];
$filePath = $importData['file_path'] ?? null;

$successCount = 0;
$updateCount = 0;
$errorCount = 0;
$errors = [];
$locationsSetCount = 0;

try {
    $ctx = UserContext::getLoggedInUserContext();
    
    // Get locations map for lookup
    $existingLocations = TeamManagement::getAllLocations();
    $locationsMap = [];
    foreach ($existingLocations as $location) {
        $locationsMap[strtolower($location['name'])] = (int)$location['id'];
    }
    
    // Import each valid team
    foreach ($previewData as $item) {
        // Skip errors
        if ($item['has_error']) {
            continue;
        }
        
        // Skip duplicates unless they have a preferred location to update
        if ($item['is_duplicate'] && empty($item['will_update'])) {
            continue;
        }
        
        try {
            // Look up division by name
            $division = TeamManagement::findDivisionByName($item['division']);
            
            if (!$division) {
                throw new Exception("Division '{$item['division']}' not found");
            }
            
            // Look up preferred location ID if provided
            $preferredLocationId = null;
            if (!empty($item['preferred_location'])) {
                $locationNameLower = strtolower($item['preferred_location']);
                $preferredLocationId = $locationsMap[$locationNameLower] ?? null;
                if ($preferredLocationId) {
                    $locationsSetCount++;
                }
            }
            
            if ($item['is_duplicate'] && !empty($item['will_update'])) {
                // Update existing team's preferred location
                $existingTeam = TeamManagement::findByName($item['name']);
                if ($existingTeam) {
                    TeamManagement::updateTeam($ctx, (int)$existingTeam['id'], (int)$division['id'], 
                        $item['name'], $existingTeam['description'] ?? '', 
                        $existingTeam['previous_year_ranking'] ?? null, $preferredLocationId);
                    $updateCount++;
                }
            } else {
                // Create new team with empty description and optional preferred location
                TeamManagement::createTeam($ctx, (int)$division['id'], $item['name'], '', null, $preferredLocationId);
                $successCount++;
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
    unset($_SESSION['team_import']);
    
    // Redirect with success message
    $msg = "Import complete!";
    if ($successCount > 0) {
        $msg .= " Added {$successCount} team(s).";
    }
    if ($updateCount > 0) {
        $msg .= " Updated {$updateCount} team(s).";
    }
    if ($locationsSetCount > 0) {
        $msg .= " Set {$locationsSetCount} preferred location(s).";
    }
    if ($errorCount > 0) {
        $msg .= " {$errorCount} error(s) occurred.";
    }
    
    header('Location: /teams/?msg=' . urlencode($msg));
    exit;
    
} catch (Exception $e) {
    $err = $e->getMessage();
}

// If we get here, something went wrong
header_html('Import Teams - Step 4');
?>

<h2>Import Teams - Step 4 of 4</h2>
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
      <a class="button" href="/teams/">Return to Teams</a>
    </div>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
