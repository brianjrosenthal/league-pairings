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
$errorCount = 0;
$errors = [];

try {
    $ctx = UserContext::getLoggedInUserContext();
    
    // Import each valid team (non-duplicate, no errors)
    foreach ($previewData as $item) {
        if ($item['is_duplicate'] || $item['has_error']) {
            continue; // Skip duplicates and errors
        }
        
        try {
            // Look up division by name
            $division = TeamManagement::findDivisionByName($item['division']);
            
            if (!$division) {
                throw new Exception("Division '{$item['division']}' not found");
            }
            
            // Create team with empty description (we only have name and division from CSV)
            TeamManagement::createTeam($ctx, (int)$division['id'], $item['name'], '', null);
            $successCount++;
            
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
    $msg = "Import complete! Added {$successCount} team(s).";
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
