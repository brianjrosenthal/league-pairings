<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CsvImportHelper.php';
require_once __DIR__ . '/../lib/PreviousGamesManagement.php';
Application::init();
require_login();

// Check if we have import data
if (!isset($_SESSION['previous_games_import']['preview_data'])) {
    header('Location: /teams/import_previous_games_step_1.php?err=' . urlencode('Please complete previous steps first.'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /teams/import_previous_games_step_3.php');
    exit;
}

require_csrf();

$importData = $_SESSION['previous_games_import'];
$filePath = $importData['file_path'] ?? null;

// Decode preview data from POST
$previewData = json_decode($_POST['preview_data'] ?? '[]', true);

$addedCount = 0;
$updatedCount = 0;
$skippedCount = 0;
$errorCount = 0;
$details = [];

try {
    $ctx = UserContext::getLoggedInUserContext();
    
    // Process each game
    foreach ($previewData as $item) {
        if ($item['has_error']) {
            continue; // Skip games with errors
        }
        
        $gameDetails = [
            'date' => $item['date'],
            'team_1' => $item['team_1_name'],
            'team_2' => $item['team_2_name'],
            'score' => ($item['team_1_score'] !== null && $item['team_2_score'] !== null) 
                ? "{$item['team_1_score']} - {$item['team_2_score']}" 
                : 'No scores',
            'action' => null,
            'error' => null
        ];
        
        try {
            if ($item['will_add']) {
                // Add new game
                echo "<!-- DEBUG: Adding game - Date: {$item['date']}, Team1 ID: {$item['team_1_id']}, Team2 ID: {$item['team_2_id']}, Score1: " . ($item['team_1_score'] ?? 'null') . ", Score2: " . ($item['team_2_score'] ?? 'null') . " -->\n";
                
                PreviousGamesManagement::createGameWithOptionalScores(
                    $ctx,
                    $item['date'],
                    (int)$item['team_1_id'],
                    (int)$item['team_2_id'],
                    $item['team_1_score'],
                    $item['team_2_score']
                );
                
                $gameDetails['action'] = 'Added';
                $addedCount++;
                $details[] = $gameDetails;
                
            } elseif ($item['will_update']) {
                // Update existing game scores
                echo "<!-- DEBUG: Updating game ID: {$item['existing_game_id']}, New Score1: " . ($item['team_1_score'] ?? 'null') . ", New Score2: " . ($item['team_2_score'] ?? 'null') . " -->\n";
                
                PreviousGamesManagement::updateGameScores(
                    $ctx,
                    (int)$item['existing_game_id'],
                    $item['team_1_score'],
                    $item['team_2_score']
                );
                
                $gameDetails['action'] = 'Updated scores';
                $updatedCount++;
                $details[] = $gameDetails;
                
            } else {
                // No change needed
                $skippedCount++;
            }
            
        } catch (Exception $e) {
            echo "<!-- DEBUG ERROR: " . $e->getMessage() . " -->\n";
            $gameDetails['error'] = $e->getMessage();
            $gameDetails['action'] = 'Error';
            $errorCount++;
            $details[] = $gameDetails;
        }
    }
    
    // Clean up temporary file
    if ($filePath && file_exists($filePath)) {
        CsvImportHelper::cleanupTempFile($filePath);
    }
    
    // Clear import session
    unset($_SESSION['previous_games_import']);
    
} catch (Exception $e) {
    $err = $e->getMessage();
}

header_html('Import Previous Games - Step 4');
?>

<h2>Import Previous Games - Step 4 of 4</h2>
<p class="small">Import complete</p>

<div class="card">
  <h3>Import Summary</h3>
  <p><strong>Successfully added:</strong> <?= (int)$addedCount ?> game(s)</p>
  <p><strong>Successfully updated:</strong> <?= (int)$updatedCount ?> game(s)</p>
  <p><strong>No change needed:</strong> <?= (int)$skippedCount ?> game(s)</p>
  <?php if ($errorCount > 0): ?>
    <p style="color:#d32f2f;"><strong>Errors:</strong> <?= (int)$errorCount ?></p>
  <?php endif; ?>
</div>

<?php if (!empty($details)): ?>
  <div class="card">
    <h3>Detailed Results</h3>
    <?php foreach ($details as $gameDetail): ?>
      <div style="margin-bottom:16px;padding:12px;background:#f5f5f5;border-radius:4px;">
        <h4 style="margin-top:0;">
          <?= h($gameDetail['team_1']) ?> vs <?= h($gameDetail['team_2']) ?>
          <span style="color:#666;font-weight:normal;font-size:0.9em;">(<?= h($gameDetail['date']) ?>)</span>
        </h4>
        
        <p style="margin:4px 0;">
          <strong>Score:</strong> <?= h($gameDetail['score']) ?>
        </p>
        
        <?php if ($gameDetail['action'] === 'Added'): ?>
          <p style="margin:4px 0;color:#388e3c;">
            <strong>✓ Added:</strong> Game successfully added to database
          </p>
        <?php elseif ($gameDetail['action'] === 'Updated scores'): ?>
          <p style="margin:4px 0;color:#f57c00;">
            <strong>⚡ Updated:</strong> Game scores updated
          </p>
        <?php endif; ?>
        
        <?php if ($gameDetail['error']): ?>
          <p style="margin:4px 0;color:#d32f2f;">
            <strong>❌ Error:</strong> <?= h($gameDetail['error']) ?>
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
