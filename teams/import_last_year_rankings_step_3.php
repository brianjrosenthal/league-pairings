<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CsvImportHelper.php';
require_once __DIR__ . '/../lib/TeamManagement.php';
Application::init();
require_login();

// Check if we have import data in session
if (!isset($_SESSION['last_year_rankings_import']['column_mapping'])) {
    header('Location: /teams/import_last_year_rankings_step_1.php?err=' . urlencode('Please complete previous steps first.'));
    exit;
}

$importData = $_SESSION['last_year_rankings_import'];
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
        
        $teamName = trim($row[$mapping['name']] ?? '');
        $rankingStr = trim($row[$mapping['ranking']] ?? '');
        
        // Skip empty rows
        if ($teamName === '' && $rankingStr === '') {
            continue;
        }
        
        $item = [
            'line_number' => $lineNumber,
            'team_name' => $teamName,
            'ranking' => $rankingStr,
            'has_error' => false,
            'error_message' => null,
            'team_id' => null,
            'current_ranking' => null,
            'will_change' => false
        ];
        
        // Validate team name
        if ($teamName === '') {
            $item['has_error'] = true;
            $item['error_message'] = 'Team name is required';
            $hasErrors = true;
        } else {
            $team = TeamManagement::findByName($teamName);
            if (!$team) {
                $item['has_error'] = true;
                $item['error_message'] = "Team '{$teamName}' not found";
                $hasErrors = true;
            } else {
                $item['team_id'] = (int)$team['id'];
                $item['current_ranking'] = $team['previous_year_ranking'];
            }
        }
        
        // Validate ranking
        if (!$item['has_error']) {
            if ($rankingStr === '') {
                $item['has_error'] = true;
                $item['error_message'] = 'Ranking is required';
                $hasErrors = true;
            } elseif (!ctype_digit($rankingStr) || (int)$rankingStr <= 0) {
                $item['has_error'] = true;
                $item['error_message'] = 'Ranking must be a positive integer';
                $hasErrors = true;
            } else {
                $newRanking = (int)$rankingStr;
                $item['ranking'] = $newRanking;
                
                // Check if this will change the database
                if ($item['current_ranking'] !== $newRanking) {
                    $item['will_change'] = true;
                }
            }
        }
        
        $previewData[] = $item;
    }
    
    // Store preview data in session for step 4
    $_SESSION['last_year_rankings_import']['preview_data'] = $previewData;
    
} catch (Exception $e) {
    $err = $e->getMessage();
}

header_html('Import Last Year\'s Rankings - Step 3');
?>

<h2>Import Last Year's Rankings - Step 3 of 4</h2>
<p class="small">Review import preview</p>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <p><strong>File:</strong> <?= h($importData['original_filename']) ?></p>
  <p><strong>Total teams:</strong> <?= count($previewData) ?></p>
  <p><strong>Teams with errors:</strong> <?= count(array_filter($previewData, fn($item) => $item['has_error'])) ?></p>
  <p><strong>Will update:</strong> <?= count(array_filter($previewData, fn($item) => !$item['has_error'] && $item['will_change'])) ?></p>
  <p><strong>No change needed:</strong> <?= count(array_filter($previewData, fn($item) => !$item['has_error'] && !$item['will_change'])) ?></p>
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
            (Team: <?= h($item['team_name']) ?>, Ranking: <?= h($item['ranking']) ?>)
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
  <table class="list">
    <thead>
      <tr>
        <th>Line</th>
        <th>Team Name</th>
        <th>New Ranking</th>
        <th>Current Ranking</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($previewData as $item): ?>
        <tr style="<?= $item['has_error'] ? 'background:#ffebee;' : ($item['will_change'] ? 'background:#e8f5e9;' : '') ?>">
          <td><?= (int)$item['line_number'] ?></td>
          <td><?= h($item['team_name']) ?></td>
          <td><?= $item['has_error'] ? '-' : (int)$item['ranking'] ?></td>
          <td><?= $item['has_error'] ? '-' : ($item['current_ranking'] === null ? '(none)' : (int)$item['current_ranking']) ?></td>
          <td>
            <?php if ($item['has_error']): ?>
              <span style="color:#d32f2f;">❌ <?= h($item['error_message']) ?></span>
            <?php elseif ($item['will_change']): ?>
              <span style="color:#388e3c;">✓ Will Update</span>
            <?php else: ?>
              <span style="color:#666;">No Change</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="card">
  <form method="post" action="/teams/import_last_year_rankings_step_4.php">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="preview_data" value="<?=h(json_encode($previewData))?>">
    <div class="actions">
      <?php if (!$hasErrors): ?>
        <button class="primary" type="submit">Commit Import</button>
      <?php endif; ?>
      <a class="button" href="/teams/import_last_year_rankings_step_2.php">← Back</a>
      <a class="button" href="/teams/">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
