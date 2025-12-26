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
        $_SESSION['team_availability_import'] = [
            'file_path' => $tempPath,
            'original_filename' => $originalFilename,
            'delimiter' => $delimiter,
            'uploaded_at' => time()
        ];
        
        // Redirect to step 2
        header('Location: /teams/import_availability_step_2.php');
        exit;
        
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}

header_html('Import Team Availability - Step 1');
?>

<h2>Import Team Availability - Step 1 of 4</h2>
<p class="small">Upload a CSV file containing team availability data</p>

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
        <small>Select a CSV file with team names, divisions, and availability columns</small>
      </label>
    </div>
    
    <div id="paste-section" style="display:none;">
      <label>CSV Data <span style="color:red;">*</span>
        <textarea name="csv_content" id="csv_content" rows="10" placeholder="Paste your CSV data here...&#10;Example:&#10;Team Initials,Team Division,Please select... [Sun Jan 25, 2026 - AM]&#10;Team A,Division 1,Available&#10;Team B,Division 1,Not Available" style="font-family:monospace;font-size:13px;"></textarea>
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
      <a class="button" href="/teams/">Cancel</a>
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
  <p>Your CSV file should contain:</p>
  <ul>
    <li><strong>Team Initials</strong> - The team name (must match existing team, required)</li>
    <li><strong>Team Division</strong> - The division name (must match team's division, required)</li>
    <li><strong>Availability columns</strong> - Columns starting with "Please select your team's availability for each possible game date" with date/time in brackets</li>
  </ul>
  <p class="small">Example column formats:</p>
  <pre style="background:#f5f5f5;padding:12px;border-radius:4px;">Team Initials,Team Division,Please select your team's availability for each possible game date [Sun Jan 25, 2026 - AM],Please select your team's availability for each possible game date [Tue Jan 27, 2026 - 7:00 PM]
Team A,Division 1,Available,Not Available
Team B,Division 1,Available,Available</pre>
</div>

<?php footer_html(); ?>
