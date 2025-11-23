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
        $tempFilePath = CsvImportHelper::saveUploadedFile($uploadedFile);
        
        // Store import data in session
        $_SESSION['previous_games_import'] = [
            'file_path' => $tempFilePath,
            'original_filename' => $uploadedFile['name'],
            'delimiter' => $delimiter
        ];
        
        // Redirect to step 2
        header('Location: /teams/import_previous_games_step_2.php');
        exit;
        
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}

header_html('Import Previous Games - Step 1');
?>

<h2>Import Previous Games - Step 1 of 4</h2>
<p class="small">Upload CSV file with game dates, teams, and optional scores</p>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <h3>Upload CSV File</h3>
  <form method="post" enctype="multipart/form-data" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    
    <label>CSV File <span style="color:red;">*</span>
      <input type="file" name="csv_file" accept=".csv" required>
      <small>Select a CSV file containing previous game data</small>
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
    <li><strong>Date</strong> - The game date (various formats supported: "1/15/2025", "January 15, 2025", etc.)</li>
    <li><strong>Team 1</strong> - First team name (must match existing team)</li>
    <li><strong>Team 2</strong> - Second team name (must match existing team)</li>
    <li><strong>Team 1 Score</strong> - Score for Team 1 (optional)</li>
    <li><strong>Team 2 Score</strong> - Score for Team 2 (optional)</li>
  </ul>
  <p class="small">Example:</p>
  <pre style="background:#f5f5f5;padding:12px;border-radius:4px;">date,Team 1,Team 2,Team 1 score,Team 2 score
1/15/2025,Team A,Team B,5,3
1/15/2025,Team C,Team D,4,2
1/22/2025,Team A,Team C,,</pre>
  <p class="small"><strong>Note:</strong> Scores are optional. Leave blank if scores are not yet known.</p>
</div>

<?php footer_html(); ?>
