<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CsvImportHelper.php';
Application::init();
require_login();

$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    
    try {
        // Handle file upload
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Please upload a valid CSV file.');
        }
        
        $delimiter = $_POST['delimiter'] ?? 'comma';
        
        // Store file in temporary location
        $uploadedFile = $_FILES['csv_file'];
        $tempFilePath = CsvImportHelper::saveTempFile($uploadedFile);
        
        // Store import data in session
        $_SESSION['last_year_rankings_import'] = [
            'file_path' => $tempFilePath,
            'original_filename' => $uploadedFile['name'],
            'delimiter' => $delimiter
        ];
        
        // Redirect to step 2
        header('Location: /teams/import_last_year_rankings_step_2.php');
        exit;
        
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}

header_html('Import Last Year\'s Rankings - Step 1');
?>

<h2>Import Last Year's Rankings - Step 1 of 4</h2>
<p class="small">Upload CSV file with team names and rankings</p>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <h3>Upload CSV File</h3>
  <form method="post" enctype="multipart/form-data" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    
    <label>CSV File <span style="color:red;">*</span>
      <input type="file" name="csv_file" accept=".csv" required>
      <small>Select a CSV file containing team names and rankings</small>
    </label>
    
    <label>Delimiter <span style="color:red;">*</span>
      <select name="delimiter" required>
        <option value="comma" selected>Comma (,)</option>
        <option value="semicolon">Semicolon (;)</option>
        <option value="tab">Tab</option>
      </select>
      <small>Character used to separate columns in your CSV file</small>
    </label>
    
    <div class="actions">
      <button class="primary" type="submit">Next Step →</button>
      <a class="button" href="/teams/">Cancel</a>
    </div>
  </form>
</div>

<div class="card">
  <h3>CSV File Format</h3>
  <p class="small">Your CSV file should contain the following columns:</p>
  <ul>
    <li><strong>Team Name</strong> - The name of the team (must match existing team)</li>
    <li><strong>Last Year Ranking</strong> - The ranking from last year (positive integer)</li>
  </ul>
  <p class="small">Example:</p>
  <pre style="background:#f5f5f5;padding:12px;border-radius:4px;">name,last_year_ranking
Team A,1
Team B,2
Team C,3</pre>
</div>

<?php footer_html(); ?>
