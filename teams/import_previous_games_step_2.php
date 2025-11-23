<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CsvImportHelper.php';
Application::init();
require_login();

// Check if we have import data in session
if (!isset($_SESSION['previous_games_import']['file_path'])) {
    header('Location: /teams/import_previous_games_step_1.php?err=' . urlencode('Please upload a CSV file first.'));
    exit;
}

$importData = $_SESSION['previous_games_import'];
$filePath = $importData['file_path'];
$delimiter = CsvImportHelper::getDelimiterChar($importData['delimiter']);

$msg = null;
$err = null;

try {
    // Get CSV headers
    $csvHeaders = CsvImportHelper::getCSVHeaders($filePath, $delimiter);
    
    // Auto-detect columns
    $defaultDateColumn = '';
    $defaultTeam1Column = '';
    $defaultTeam2Column = '';
    $defaultTeam1ScoreColumn = '';
    $defaultTeam2ScoreColumn = '';
    
    foreach ($csvHeaders as $header) {
        $headerLower = strtolower(trim($header));
        
        // Date column
        if (($headerLower === 'date' || $headerLower === 'game date') && $defaultDateColumn === '') {
            $defaultDateColumn = $header;
        }
        
        // Team 1 column
        if ($headerLower === 'team 1' && $defaultTeam1Column === '') {
            $defaultTeam1Column = $header;
        }
        
        // Team 2 column
        if ($headerLower === 'team 2' && $defaultTeam2Column === '') {
            $defaultTeam2Column = $header;
        }
        
        // Team 1 score columns
        if (($headerLower === 'team 1 score' || $headerLower === 'team 1 score (assumed)') && $defaultTeam1ScoreColumn === '') {
            $defaultTeam1ScoreColumn = $header;
        }
        
        // Team 2 score columns
        if (($headerLower === 'team 2 score' || $headerLower === 'team 2 score (assumed)') && $defaultTeam2ScoreColumn === '') {
            $defaultTeam2ScoreColumn = $header;
        }
    }
    
} catch (Exception $e) {
    $err = $e->getMessage();
    $csvHeaders = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    
    try {
        // Get column mapping
        $dateColumn = $_POST['date_column'] ?? '';
        $team1Column = $_POST['team1_column'] ?? '';
        $team2Column = $_POST['team2_column'] ?? '';
        $team1ScoreColumn = $_POST['team1_score_column'] ?? '';
        $team2ScoreColumn = $_POST['team2_score_column'] ?? '';
        
        if ($dateColumn === '') {
            throw new InvalidArgumentException('Please select a column for Date.');
        }
        
        if ($team1Column === '') {
            throw new InvalidArgumentException('Please select a column for Team 1.');
        }
        
        if ($team2Column === '') {
            throw new InvalidArgumentException('Please select a column for Team 2.');
        }
        
        // Store mapping in session
        $_SESSION['previous_games_import']['column_mapping'] = [
            'date' => $dateColumn,
            'team1' => $team1Column,
            'team2' => $team2Column,
            'team1_score' => $team1ScoreColumn,
            'team2_score' => $team2ScoreColumn
        ];
        
        // Redirect to step 3
        header('Location: /teams/import_previous_games_step_3.php');
        exit;
        
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}

header_html('Import Previous Games - Step 2');
?>

<h2>Import Previous Games - Step 2 of 4</h2>
<p class="small">Map CSV columns to game data</p>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <p><strong>File:</strong> <?= h($importData['original_filename']) ?></p>
  <p><strong>Delimiter:</strong> <?= h(ucfirst($importData['delimiter'])) ?></p>
  <p><strong>Columns found:</strong> <?= count($csvHeaders) ?></p>
</div>

<?php if (!empty($csvHeaders)): ?>
<div class="card">
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    
    <h3>Column Mapping</h3>
    <p class="small">Select which columns from your CSV file contain the game data.</p>
    
    <label>Date <span style="color:red;">*</span>
      <select name="date_column" required>
        <option value="">-- Select Column --</option>
        <?php foreach ($csvHeaders as $header): ?>
          <option value="<?= h($header) ?>" <?= ($header === $defaultDateColumn) ? 'selected' : '' ?>><?= h($header) ?></option>
        <?php endforeach; ?>
      </select>
      <small>Game date (various formats supported)</small>
    </label>

    <label>Team 1 <span style="color:red;">*</span>
      <select name="team1_column" required>
        <option value="">-- Select Column --</option>
        <?php foreach ($csvHeaders as $header): ?>
          <option value="<?= h($header) ?>" <?= ($header === $defaultTeam1Column) ? 'selected' : '' ?>><?= h($header) ?></option>
        <?php endforeach; ?>
      </select>
      <small>First team name (must match existing team)</small>
    </label>

    <label>Team 2 <span style="color:red;">*</span>
      <select name="team2_column" required>
        <option value="">-- Select Column --</option>
        <?php foreach ($csvHeaders as $header): ?>
          <option value="<?= h($header) ?>" <?= ($header === $defaultTeam2Column) ? 'selected' : '' ?>><?= h($header) ?></option>
        <?php endforeach; ?>
      </select>
      <small>Second team name (must match existing team)</small>
    </label>

    <label>Team 1 Score <span style="color:#666;">(Optional)</span>
      <select name="team1_score_column">
        <option value="">-- None / Skip --</option>
        <?php foreach ($csvHeaders as $header): ?>
          <option value="<?= h($header) ?>" <?= ($header === $defaultTeam1ScoreColumn) ? 'selected' : '' ?>><?= h($header) ?></option>
        <?php endforeach; ?>
      </select>
      <small>Score for Team 1 (leave empty if not available)</small>
    </label>

    <label>Team 2 Score <span style="color:#666;">(Optional)</span>
      <select name="team2_score_column">
        <option value="">-- None / Skip --</option>
        <?php foreach ($csvHeaders as $header): ?>
          <option value="<?= h($header) ?>" <?= ($header === $defaultTeam2ScoreColumn) ? 'selected' : '' ?>><?= h($header) ?></option>
        <?php endforeach; ?>
      </select>
      <small>Score for Team 2 (leave empty if not available)</small>
    </label>

    <div class="actions">
      <button class="primary" type="submit">Next Step →</button>
      <a class="button" href="/teams/import_previous_games_step_1.php">← Back</a>
      <a class="button" href="/teams/">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php footer_html(); ?>
