<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CsvImportHelper.php';
Application::init();
require_login();

// Clear any existing import session
if (!isset($_POST['csrf'])) {
    unset($_SESSION['division_import']);
}

$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    
    try {
        // Validate file upload
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Please select a CSV file to upload.');
        }
        
        // Get delimiter
        $delimiter = $_POST['delimiter'] ?? 'comma';
        
        // Save uploaded file
        $tempFilePath = CsvImportHelper::saveUploadedFile($_FILES['csv_file']);
        
        // Store in session
        $_SESSION['division_import'] = [
            'file_path' => $tempFilePath,
            'delimiter' => $delimiter,
            'original_filename' => $_FILES['csv_file']['name']
        ];
        
        // Redirect to step 2
        header('Location: /divisions/import_step_2.php');
        exit;
        
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}

header_html('Import Divisions - Step 1');
?>

<h2>Import Divisions - Step 1 of 4</h2>
<p class="small">Upload a CSV file containing division names</p>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" enctype="multipart/form-data" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    
    <label>CSV File
      <input type="file" name="csv_file" accept=".csv,.txt" required>
      <small>Maximum file size: 10MB</small>
    </label>

    <label>Delimiter
      <select name="delimiter">
        <option value="comma" selected>Comma (,)</option>
        <option value="semicolon">Semicolon (;)</option>
        <option value="tab">Tab</option>
        <option value="pipe">Pipe (|)</option>
      </select>
    </label>

    <div class="actions">
      <button class="primary" type="submit">Next Step →</button>
      <a class="button" href="/divisions/">Cancel</a>
    </div>
  </form>
</div>

<div class="card">
  <h3>CSV File Format</h3>
  <p>Your CSV file should have a header row with column names, followed by data rows. For example:</p>
  <pre style="background:#f5f5f5;padding:12px;border-radius:4px;overflow-x:auto;">
Division Name
Grade 5-6
Grade 7-8
Grade 9-10</pre>
  <p class="small"><strong>Note:</strong> Duplicate division names (case-insensitive) will be skipped during import.</p>
</div>

<?php footer_html(); ?>
