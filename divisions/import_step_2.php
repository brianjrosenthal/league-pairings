<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CsvImportHelper.php';
Application::init();
require_login();

// Check if we have import data in session
if (!isset($_SESSION['division_import']['file_path'])) {
    header('Location: /divisions/import_step_1.php?err=' . urlencode('Please upload a CSV file first.'));
    exit;
}

$importData = $_SESSION['division_import'];
$filePath = $importData['file_path'];
$delimiter = CsvImportHelper::getDelimiterChar($importData['delimiter']);

$msg = null;
$err = null;

try {
    // Get CSV headers
    $csvHeaders = CsvImportHelper::getCSVHeaders($filePath, $delimiter);
} catch (Exception $e) {
    $err = $e->getMessage();
    $csvHeaders = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    
    try {
        // Get column mapping
        $nameColumn = $_POST['name_column'] ?? '';
        
        if ($nameColumn === '') {
            throw new InvalidArgumentException('Please select a column for Division Name.');
        }
        
        // Store mapping in session
        $_SESSION['division_import']['column_mapping'] = [
            'name' => $nameColumn
        ];
        
        // Redirect to step 3
        header('Location: /divisions/import_step_3.php');
        exit;
        
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}

header_html('Import Divisions - Step 2');
?>

<h2>Import Divisions - Step 2 of 4</h2>
<p class="small">Map CSV columns to division fields</p>

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
    <p class="small">Select which column from your CSV file contains the division name.</p>
    
    <label>Division Name <span style="color:red;">*</span>
      <select name="name_column" required>
        <option value="">-- Select Column --</option>
        <?php foreach ($csvHeaders as $header): ?>
          <option value="<?= h($header) ?>"><?= h($header) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <div class="actions">
      <button class="primary" type="submit">Next Step →</button>
      <a class="button" href="/divisions/import_step_1.php">← Back</a>
      <a class="button" href="/divisions/">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php footer_html(); ?>
