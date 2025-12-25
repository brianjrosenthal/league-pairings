<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CsvImportHelper.php';
Application::init();
require_login();

// Check if we have import data in session
if (!isset($_SESSION['team_import']['file_path'])) {
    header('Location: /teams/import_step_1.php?err=' . urlencode('Please upload a CSV file first.'));
    exit;
}

$importData = $_SESSION['team_import'];
$filePath = $importData['file_path'];
$delimiter = CsvImportHelper::getDelimiterChar($importData['delimiter']);

$msg = null;
$err = null;

try {
    // Get CSV headers
    $csvHeaders = CsvImportHelper::getCSVHeaders($filePath, $delimiter);
    
    // Auto-detect "name", "division", and "preferred_location" columns (case-insensitive)
    $defaultNameColumn = '';
    $defaultDivisionColumn = '';
    $defaultPreferredLocationColumn = '';
    
    foreach ($csvHeaders as $header) {
        $headerLower = strtolower($header);
        if ($headerLower === 'name' && $defaultNameColumn === '') {
            $defaultNameColumn = $header;
        }
        if ($headerLower === 'division' && $defaultDivisionColumn === '') {
            $defaultDivisionColumn = $header;
        }
        if (in_array($headerLower, ['preferred_location', 'preferred location', 'location', 'home_gym']) && $defaultPreferredLocationColumn === '') {
            $defaultPreferredLocationColumn = $header;
        }
    }
} catch (Exception $e) {
    $err = $e->getMessage();
    $csvHeaders = [];
    $defaultNameColumn = '';
    $defaultDivisionColumn = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    
    try {
        // Get column mapping
        $nameColumn = $_POST['name_column'] ?? '';
        $divisionColumn = $_POST['division_column'] ?? '';
        $preferredLocationColumn = $_POST['preferred_location_column'] ?? '';
        
        if ($nameColumn === '') {
            throw new InvalidArgumentException('Please select a column for Team Name.');
        }
        
        if ($divisionColumn === '') {
            throw new InvalidArgumentException('Please select a column for Division.');
        }
        
        // Store mapping in session
        $_SESSION['team_import']['column_mapping'] = [
            'name' => $nameColumn,
            'division' => $divisionColumn,
            'preferred_location' => $preferredLocationColumn
        ];
        
        // Redirect to step 3
        header('Location: /teams/import_step_3.php');
        exit;
        
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}

header_html('Import Teams - Step 2');
?>

<h2>Import Teams - Step 2 of 4</h2>
<p class="small">Map CSV columns to team fields</p>

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
    </label>

    <label>Division <span style="color:red;">*</span>
      <select name="division_column" required>
        <option value="">-- Select Column --</option>
        <?php foreach ($csvHeaders as $header): ?>
          <option value="<?= h($header) ?>" <?= ($header === $defaultDivisionColumn) ? 'selected' : '' ?>><?= h($header) ?></option>
        <?php endforeach; ?>
      </select>
      <small>Division names must match existing divisions in the system</small>
    </label>

    <label>Preferred Location (optional)
      <select name="preferred_location_column">
        <option value="">-- Skip (Optional) --</option>
        <?php foreach ($csvHeaders as $header): ?>
          <option value="<?= h($header) ?>" <?= ($header === $defaultPreferredLocationColumn) ? 'selected' : '' ?>><?= h($header) ?></option>
        <?php endforeach; ?>
      </select>
      <small>Location names must match existing locations in the system</small>
    </label>

    <div class="actions">
      <button class="primary" type="submit">Next Step →</button>
      <a class="button" href="/teams/import_step_1.php">← Back</a>
      <a class="button" href="/teams/">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php footer_html(); ?>
