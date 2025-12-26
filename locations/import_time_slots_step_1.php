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
        $delimiter = $_POST['delimiter'] ?? 'comma';
        $inputMethod = $_POST['input_method'] ?? 'file';
        
        $tempPath = null;
        $originalFilename = 'pasted_data.csv';
        
        if ($inputMethod === 'file') {
            // File upload method
            if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Please select a valid CSV file.');
            }
            
            $file = $_FILES['csv_file'];
            
            // Validate file type
            $allowedExtensions = ['csv', 'txt'];
            $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (!in_array($fileExtension, $allowedExtensions)) {
                throw new InvalidArgumentException('Only CSV files are allowed.');
            }
            
            // Store file temporarily
            $tempPath = CsvImportHelper::saveUploadedFile($file);
            $originalFilename = $file['name'];
            
        } else {
            // Paste method
            $csvContent = $_POST['csv_content'] ?? '';
            
            if (trim($csvContent) === '') {
                throw new InvalidArgumentException('Please paste CSV data.');
            }
            
            // Save pasted content to temporary file
            $tempPath = CsvImportHelper::savePastedCsv($csvContent);
        }
        
        // Store import data in session
        $_SESSION['location_timeslot_import'] = [
            'file_path' => $tempPath,
            'original_filename' => $originalFilename,
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
  <form method="post" enctype="multipart/form-data" class="stack" id="import-form">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="input_method" id="input_method" value="file">
    
    <div style="display:flex;align-items:center;gap:20px;margin-bottom:0px;">
      <label style="margin:0;">Input Method</label>
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin:0;">
        <input type="radio" name="input_method_radio" value="file" checked onchange="toggleInputMethod('file')">
        <span>Upload File</span>
      </label>
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin:0;">
        <input type="radio" name="input_method_radio" value="paste" onchange="toggleInputMethod('paste')">
        <span>Paste CSV Data</span>
      </label>
    </div>
    
    <div id="file-upload-section">
      <label>CSV File <span style="color:red;">*</span>
        <input type="file" name="csv_file" id="csv_file" accept=".csv,.txt">
        <small>Select a CSV file containing location names, dates, and time modifiers</small>
      </label>
    </div>
    
    <div id="paste-section" style="display:none;">
      <label>CSV Data <span style="color:red;">*</span>
        <textarea name="csv_content" id="csv_content" rows="10" placeholder="Paste your CSV data here...&#10;Example:&#10;location,Game Date,Start Time&#10;Court A,2025-12-15,6:00 PM&#10;Court B,2025-12-15,7:30 PM" style="font-family:monospace;font-size:13px;"></textarea>
        <small>Paste CSV data with headers in the first row</small>
      </label>
    </div>

    <label>Delimiter
      <select name="delimiter">
        <option value="comma" selected>Comma (,)</option>
        <option value="semicolon">Semicolon (;)</option>
        <option value="tab">Tab</option>
        <option value="pipe">Pipe (|)</option>
      </select>
      <small>Character used to separate columns in your CSV data</small>
    </label>

    <div class="actions">
      <button class="primary" type="submit">Next Step →</button>
      <a class="button" href="/locations/">Cancel</a>
    </div>
  </form>
</div>

<script>
function toggleInputMethod(method) {
  const fileSection = document.getElementById('file-upload-section');
  const pasteSection = document.getElementById('paste-section');
  const inputMethodField = document.getElementById('input_method');
  const fileInput = document.getElementById('csv_file');
  const pasteInput = document.getElementById('csv_content');
  
  if (method === 'file') {
    fileSection.style.display = 'block';
    pasteSection.style.display = 'none';
    inputMethodField.value = 'file';
    fileInput.required = true;
    pasteInput.required = false;
  } else {
    fileSection.style.display = 'none';
    pasteSection.style.display = 'block';
    inputMethodField.value = 'paste';
    fileInput.required = false;
    pasteInput.required = true;
  }
}
</script>

<div class="card">
  <h3>CSV Format Requirements</h3>
  <p>Your CSV file should contain the following columns:</p>
  <ul>
    <li><strong>location</strong> - The location name (must match existing location, required)</li>
    <li><strong>date</strong> or <strong>Game Date</strong> - Date in multiple formats accepted: MM/DD/YYYY, YYYY-MM-DD, or "January 3, 2025" (required)</li>
    <li><strong>modifier</strong> or <strong>Start Time</strong> - Time modifier like "6:00 PM" (optional)</li>
  </ul>
  <p class="small">Example with YYYY-MM-DD format:</p>
  <pre style="background:#f5f5f5;padding:12px;border-radius:4px;">location,Game Date,Start Time
Court A,2025-12-15,6:00 PM
Court B,2025-12-15,7:30 PM
Court A,2025-12-22,6:00 PM</pre>
  <p class="small">Example with MM/DD/YYYY format:</p>
  <pre style="background:#f5f5f5;padding:12px;border-radius:4px;">location,Game Date,Start Time
Court A,12/15/2025,6:00 PM
Court B,12/15/2025,7:30 PM
Court A,12/22/2025,6:00 PM</pre>
  <p class="small">Example with natural language format:</p>
  <pre style="background:#f5f5f5;padding:12px;border-radius:4px;">location,Game Date,Start Time
Court A,December 15, 2025,6:00 PM
Court B,December 15, 2025,7:30 PM
Court A,December 22, 2025,6:00 PM</pre>
</div>

<?php footer_html(); ?>
