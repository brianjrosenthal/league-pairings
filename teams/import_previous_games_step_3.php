<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CsvImportHelper.php';
require_once __DIR__ . '/../lib/TeamManagement.php';
require_once __DIR__ . '/../lib/TimeslotManagement.php';
require_once __DIR__ . '/../lib/PreviousGamesManagement.php';
Application::init();
require_login();

// Check if we have import data in session
if (!isset($_SESSION['previous_games_import']['column_mapping'])) {
    header('Location: /teams/import_previous_games_step_1.php?err=' . urlencode('Please complete previous steps first.'));
    exit;
}

$importData = $_SESSION['previous_games_import'];
$filePath = $importData['file_path'];
$delimiter = CsvImportHelper::getDelimiterChar($importData['delimiter']);
$mapping = $importData['column_mapping'];

$msg = null;
$err = null;
$previewData = [];
$hasErrors = false;

try {
    // Read CSV file
    $csvData = CsvImportHelper::parseCSV($filePath, $delimiter);
    
    $lineNumber = 1; // Start at 1 for header
    foreach ($csvData as $row) {
        $lineNumber++;
        
        $dateStr = trim($row[$mapping['date']] ?? '');
        $team1Name = trim($row[$mapping['team1']] ?? '');
        $team2Name = trim($row[$mapping['team2']] ?? '');
        $team1ScoreStr = trim($row[$mapping['team1_score']] ?? '');
        $team2ScoreStr = trim($row[$mapping['team2_score']] ?? '');
        
        // Skip empty rows
        if ($dateStr === '' && $team1Name === '' && $team2Name === '') {
            continue;
        }
        
        $item = [
            'line_number' => $lineNumber,
            'date_str' => $dateStr,
            'date' => null,
            'team_1_name' => $team1Name,
            'team_2_name' => $team2Name,
            'team_1_id' => null,
            'team_2_id' => null,
            'team_1_score' => null,
            'team_2_score' => null,
            'has_error' => false,
            'error_message' => null,
            'existing_game_id' => null,
            'will_add' => false,
            'will_update' => false,
            'no_change' => false
        ];
        
        // Validate and parse date
        if ($dateStr === '') {
            $item['has_error'] = true;
            $item['error_message'] = 'Date is required';
            $hasErrors = true;
        } else {
            $normalizedDate = TimeslotManagement::normalizeDate($dateStr);
            if ($normalizedDate === null) {
                $item['has_error'] = true;
                $item['error_message'] = "Cannot parse date: '{$dateStr}'";
                $hasErrors = true;
            } else {
                $item['date'] = $normalizedDate;
            }
        }
        
        // Validate Team 1
        if (!$item['has_error']) {
            if ($team1Name === '') {
                $item['has_error'] = true;
                $item['error_message'] = 'Team 1 is required';
                $hasErrors = true;
            } else {
                $team1 = TeamManagement::findByName($team1Name);
                if (!$team1) {
                    $item['has_error'] = true;
                    $item['error_message'] = "Team 1 '{$team1Name}' not found";
                    $hasErrors = true;
                } else {
                    $item['team_1_id'] = (int)$team1['id'];
                }
            }
        }
        
        // Validate Team 2
        if (!$item['has_error']) {
            if ($team2Name === '') {
                $item['has_error'] = true;
                $item['error_message'] = 'Team 2 is required';
                $hasErrors = true;
            } else {
                $team2 = TeamManagement::findByName($team2Name);
                if (!$team2) {
                    $item['has_error'] = true;
                    $item['error_message'] = "Team 2 '{$team2Name}' not found";
                    $hasErrors = true;
                } else {
                    $item['team_2_id'] = (int)$team2['id'];
                }
            }
        }
        
        // Check for same team
        if (!$item['has_error'] && $item['team_1_id'] === $item['team_2_id']) {
            $item['has_error'] = true;
            $item['error_message'] = 'A team cannot play against itself';
            $hasErrors = true;
        }
        
        // Parse scores (optional)
        if (!$item['has_error']) {
            if ($team1ScoreStr !== '') {
                if (!ctype_digit($team1ScoreStr) || (int)$team1ScoreStr < 0) {
                    $item['has_error'] = true;
                    $item['error_message'] = 'Team 1 score must be a non-negative integer';
                    $hasErrors = true;
                } else {
                    $item['team_1_score'] = (int)$team1ScoreStr;
                }
            }
            
            if ($team2ScoreStr !== '' && !$item['has_error']) {
                if (!ctype_digit($team2ScoreStr) || (int)$team2ScoreStr < 0) {
                    $item['has_error'] = true;
                    $item['error_message'] = 'Team 2 score must be a non-negative integer';
                    $hasErrors = true;
                } else {
                    $item['team_2_score'] = (int)$team2ScoreStr;
                }
            }
        }
        
        // Check for existing game
        if (!$item['has_error']) {
            $existingGame = PreviousGamesManagement::findGameByDateAndTeams(
                $item['date'],
                $item['team_1_id'],
                $item['team_2_id']
            );
            
            if ($existingGame) {
                $item['existing_game_id'] = (int)$existingGame['id'];
                
                // Check if scores are different
                $existingScore1 = $existingGame['team_1_score'];
                $existingScore2 = $existingGame['team_2_score'];
                
                if ($item['team_1_score'] !== $existingScore1 || $item['team_2_score'] !== $existingScore2) {
                    $item['will_update'] = true;
                } else {
                    $item['no_change'] = true;
                }
            } else {
                $item['will_add'] = true;
            }
        }
        
        $previewData[] = $item;
    }
    
    // Store preview data in session for step 4
    $_SESSION['previous_games_import']['preview_data'] = $previewData;
    
} catch (Exception $e) {
    $err = $e->getMessage();
}

header_html('Import Previous Games - Step 3');
?>

<h2>Import Previous Games - Step 3 of 4</h2>
<p class="small">Review import preview</p>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <p><strong>File:</strong> <?= h($importData['original_filename']) ?></p>
  <p><strong>Total games:</strong> <?= count($previewData) ?></p>
  <p><strong>Games with errors:</strong> <?= count(array_filter($previewData, fn($item) => $item['has_error'])) ?></p>
  <p><strong>Will add:</strong> <?= count(array_filter($previewData, fn($item) => !$item['has_error'] && $item['will_add'])) ?></p>
  <p><strong>Will update:</strong> <?= count(array_filter($previewData, fn($item) => !$item['has_error'] && $item['will_update'])) ?></p>
  <p><strong>No change needed:</strong> <?= count(array_filter($previewData, fn($item) => !$item['has_error'] && $item['no_change'])) ?></p>
</div>

<?php if ($hasErrors): ?>
  <div class="card" style="border-left: 4px solid #d32f2f;">
    <h3 style="color:#d32f2f;">⚠️ Import Cannot Proceed</h3>
    <p>The following errors must be resolved before importing:</p>
    <ul>
      <?php foreach ($previewData as $item): ?>
        <?php if ($item['has_error']): ?>
          <li>
            <strong>Line <?= (int)$item['line_number'] ?>:</strong>
            <?= h($item['error_message']) ?>
            (Date: <?= h($item['date_str']) ?>, Team 1: <?= h($item['team_1_name']) ?>, Team 2: <?= h($item['team_2_name']) ?>)
          </li>
        <?php endif; ?>
      <?php endforeach; ?>
    </ul>
    <p><strong>Please go back and fix your CSV file, then re-upload it.</strong></p>
  </div>
<?php endif; ?>

<?php if (!empty($previewData)): ?>
<div class="card">
  <h3>Preview</h3>
  <div style="overflow-x:auto;">
    <table class="list">
      <thead>
        <tr>
          <th>Line</th>
          <th>Date</th>
          <th>Team 1</th>
          <th>Team 2</th>
          <th>Score</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($previewData as $item): ?>
          <tr style="<?= $item['has_error'] ? 'background:#ffebee;' : ($item['will_add'] ? 'background:#e8f5e9;' : ($item['will_update'] ? 'background:#fff3e0;' : '')) ?>">
            <td><?= (int)$item['line_number'] ?></td>
            <td><?= $item['date'] ? h($item['date']) : h($item['date_str']) ?></td>
            <td><?= h($item['team_1_name']) ?></td>
            <td><?= h($item['team_2_name']) ?></td>
            <td>
              <?php if ($item['has_error']): ?>
                -
              <?php else: ?>
                <?= $item['team_1_score'] !== null ? (int)$item['team_1_score'] : '-' ?> 
                - 
                <?= $item['team_2_score'] !== null ? (int)$item['team_2_score'] : '-' ?>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($item['has_error']): ?>
                <span style="color:#d32f2f;">❌ <?= h($item['error_message']) ?></span>
              <?php elseif ($item['will_add']): ?>
                <span style="color:#388e3c;">✓ Will Add</span>
              <?php elseif ($item['will_update']): ?>
                <span style="color:#f57c00;">⚡ Will Update Scores</span>
              <?php else: ?>
                <span style="color:#666;">No Change</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <form method="post" action="/teams/import_previous_games_step_4.php">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="preview_data" value="<?=h(json_encode($previewData))?>">
    <div class="actions">
      <?php if (!$hasErrors): ?>
        <button class="primary" type="submit">Commit Import</button>
      <?php endif; ?>
      <a class="button" href="/teams/import_previous_games_step_2.php">← Back</a>
      <a class="button" href="/teams/">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
