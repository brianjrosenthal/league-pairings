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
        // Validate file upload
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Please select a valid CSV file.');
        }
        
        $file = $_FILES['csv_file'];
        $delimiter = $_POST['delimiter'] ?? 'comma';
        
        // Validate file type
        $allowedExtensions = ['csv', 'txt'];
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($fileExtension, $allowedExtensions)) {
            throw new InvalidArgumentException('Only CSV files are allowed.');
        }
        
        // Store file temporarily
        $tempPath = CsvImportHelper::saveUploadedFile($file);
        
        // Store import data in session
        $_SESSION['location_timeslot_import'] = [
            'file_path' => $tempPath,
            'original_filename' => $file['name'],
            'delimiter' => $delimiter,
            'uploaded_at' => time()
        ];
        
        // Redirect to step 2
        header('Location: /locations/import_time_slots_step_2.php');
        exit;
        
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}

header_html('Import Location Time Slots - Step 1');
?>

<h2>Import Location Time Slots - Step 1 of 4</h2>
<p class="small">Upload a CSV file containing location time slot data</p>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" enctype="multipart/form-data" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    
    <label>CSV File <span style="color:red;">*</span>
      <input type="file" name="csv_file" accept=".csv,.txt" required>
      <small>Select a CSV file containing location names, dates, and time modifiers</small>
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
      <a class="button" href="/locations/">Cancel</a>
    </div>
  </form>
</div>

<div class="card">
  <h3>CSV Format Requirements</h3>
  <p>Your CSV file should contain the following columns:</p>
  <ul>
    <li><strong>location</strong> - The location name (must match existing location, required)</li>
    <li><strong>date</strong> or <strong>Game Date</strong> - The date in YYYY-MM-DD format (required)</li>
    <li><strong>modifier</strong> or <strong>Start Time</strong> - Time modifier like "6:00 PM" (optional)</li>
  </ul>
  <p class="small">Example:</p>
  <pre style="background:#f5f5f5;padding:12px;border-radius:4px;">location,Game Date,Start Time
Court A,2025-12-15,6:00 PM
Court B,2025-12-15,7:30 PM
Court A,2025-12-22,6:00 PM</pre>
</div>

<?php footer_html(); ?>
