<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CsvImportHelper.php';
Application::init();
require_login();

// Check if we have import data in session
if (!isset($_SESSION['last_year_rankings_import']['file_path'])) {
    header('Location: /teams/import_last_year_rankings_step_1.php?err=' . urlencode('Please upload a CSV file first.'));
    exit;
}

$importData = $_SESSION['last_year_rankings_import'];
$filePath = $importData['file_path'];
$delimiter = CsvImportHelper::getDelimiterChar($importData['delimiter']);

$msg = null;
$err = null;

try {
    // Get CSV headers
    $csvHeaders = CsvImportHelper::getCSVHeaders($filePath, $delimiter);
    
    // Auto-detect name and ranking columns
    $defaultNameColumn = '';
    $defaultRankingColumn = '';
    
    foreach ($csvHeaders as $header) {
        $headerLower = strtolower(trim($header));
        
        if ($headerLower === 'name' && $defaultNameColumn === '') {
            $defaultNameColumn = $header;
        }
        
        if ($headerLower === 'last_year_ranking' && $defaultRankingColumn === '') {
            $defaultRankingColumn = $header;
        }
    }
    
} catch (Exception $e) {
    $err = $e->getMessage();
    $csvHeaders = [];
    $defaultNameColumn = '';
    $defaultRankingColumn = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    
    try {
        // Get column mapping
        $nameColumn = $_POST['name_column'] ?? '';
        $rankingColumn = $_POST['ranking_column'] ?? '';
        
        if ($nameColumn === '') {
            throw new InvalidArgumentException('Please select a column for Team Name.');
        }
        
        if ($rankingColumn === '') {
            throw new InvalidArgumentException('Please select a column for Last Year Ranking.');
        }
        
        // Store mapping in session
        $_SESSION['last_year_rankings_import']['column_mapping'] = [
            'name' => $nameColumn,
            'ranking' => $rankingColumn
        ];
        
        // Redirect to step 3
        header('Location: /teams/import_last_year_rankings_step_3.php');
        exit;
        
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}

header_html('Import Last Year\'s Rankings - Step 2');
?>

<h2>Import Last Year's Rankings - Step 2 of 4</h2>
<p class="small">Map CSV columns to team data</p>

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
    <p class="small">Select which columns from your CSV file contain the team data.</p>
    
    <label>Team Name <span style="color:red;">*</span>
      <select name="name_column" required>
        <option value="">-- Select Column --</option>
        <?php foreach ($csvHeaders as $header): ?>
          <option value="<?= h($header) ?>" <?= ($header === $defaultNameColumn) ? 'selected' : '' ?>><?= h($header) ?></option>
        <?php endforeach; ?>
      </select>
      <small>Team name must match an existing team in the database</small>
    </label>

    <label>Last Year Ranking <span style="color:red;">*</span>
      <select name="ranking_column" required>
        <option value="">-- Select Column --</option>
        <?php foreach ($csvHeaders as $header): ?>
          <option value="<?= h($header) ?>" <?= ($header === $defaultRankingColumn) ? 'selected' : '' ?>><?= h($header) ?></option>
        <?php endforeach; ?>
      </select>
      <small>Ranking must be a positive integer</small>
    </label>

    <div class="actions">
      <button class="primary" type="submit">Next Step →</button>
      <a class="button" href="/teams/import_last_year_rankings_step_1.php">← Back</a>
      <a class="button" href="/teams/">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php footer_html(); ?>
