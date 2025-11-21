<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CsvImportHelper.php';
require_once __DIR__ . '/../lib/TeamManagement.php';
Application::init();
require_login();

// Check if we have import data
if (!isset($_SESSION['last_year_rankings_import']['preview_data'])) {
    header('Location: /teams/import_last_year_rankings_step_1.php?err=' . urlencode('Please complete previous steps first.'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /teams/import_last_year_rankings_step_3.php');
    exit;
}

require_csrf();

$importData = $_SESSION['last_year_rankings_import'];
$filePath = $importData['file_path'] ?? null;

// Decode preview data from POST
$previewData = json_decode($_POST['preview_data'] ?? '[]', true);

$updatedCount = 0;
$skippedCount = 0;
$errorCount = 0;
$details = [];

try {
    $ctx = UserContext::getLoggedInUserContext();
    
    // Process each team
    foreach ($previewData as $item) {
        if ($item['has_error']) {
            continue; // Skip teams with errors
        }
        
        $teamDetails = [
            'team' => $item['team_name'],
            'updated' => false,
            'error' => null,
            'old_ranking' => $item['current_ranking'],
            'new_ranking' => (int)$item['ranking']
        ];
        
        try {
            // Only update if there's a change
            if ($item['will_change']) {
                TeamManagement::updateTeamRanking($ctx, (int)$item['team_id'], (int)$item['ranking']);
                $teamDetails['updated'] = true;
                $updatedCount++;
            } else {
                $skippedCount++;
            }
            
        } catch (Exception $e) {
            $teamDetails['error'] = $e->getMessage();
            $errorCount++;
        }
        
        // Only add to details if something happened or there was an error
        if ($teamDetails['updated'] || $teamDetails['error']) {
            $details[] = $teamDetails;
        }
    }
    
    // Clean up temporary file
    if ($filePath && file_exists($filePath)) {
        CsvImportHelper::cleanupTempFile($filePath);
    }
    
    // Clear import session
    unset($_SESSION['last_year_rankings_import']);
    
} catch (Exception $e) {
    $err = $e->getMessage();
}

header_html('Import Last Year\'s Rankings - Step 4');
?>

<h2>Import Last Year's Rankings - Step 4 of 4</h2>
<p class="small">Import complete</p>

<div class="card">
  <h3>Import Summary</h3>
  <p><strong>Successfully updated:</strong> <?= (int)$updatedCount ?> team(s)</p>
  <p><strong>No change needed:</strong> <?= (int)$skippedCount ?> team(s)</p>
  <?php if ($errorCount > 0): ?>
    <p style="color:#d32f2f;"><strong>Errors:</strong> <?= (int)$errorCount ?></p>
  <?php endif; ?>
</div>

<?php if (!empty($details)): ?>
  <div class="card">
    <h3>Detailed Results</h3>
    <?php foreach ($details as $teamDetail): ?>
      <div style="margin-bottom:16px;padding:12px;background:#f5f5f5;border-radius:4px;">
        <h4 style="margin-top:0;"><?= h($teamDetail['team']) ?></h4>
        
        <?php if ($teamDetail['updated']): ?>
          <p style="margin:4px 0;color:#388e3c;">
            <strong>✓ Updated</strong> - 
            Ranking changed from <?= $teamDetail['old_ranking'] === null ? '(none)' : (int)$teamDetail['old_ranking'] ?>
            to <?= (int)$teamDetail['new_ranking'] ?>
          </p>
        <?php endif; ?>
        
        <?php if ($teamDetail['error']): ?>
          <p style="margin:4px 0;color:#d32f2f;">
            <strong>❌ Error:</strong> <?= h($teamDetail['error']) ?>
          </p>
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
