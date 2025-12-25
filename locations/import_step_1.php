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
        $_SESSION['location_import'] = [
            'file_path' => $tempPath,
            'original_filename' => $originalFilename,
            'delimiter' => $delimiter,
            'uploaded_at' => time()
        ];
        
        // Redirect to step 2
        header('Location: /locations/import_step_2.php');
        exit;
        
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}

header_html('Import Locations - Step 1');
?>

<h2>Import Locations - Step 1 of 4</h2>
<p class="small">Upload a CSV file containing location data</p>

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
        <small>Select a CSV file containing location data</small>
      </label>
    </div>
    
    <div id="paste-section" style="display:none;">
      <label>CSV Data <span style="color:red;">*</span>
        <textarea name="csv_content" id="csv_content" rows="10" placeholder="Paste your CSV data here...&#10;Example:&#10;name,description,division_affinities&#10;Court A,Main court,&quot;Division A, Division B&quot;&#10;Court B,Practice court,Division C" style="font-family:monospace;font-size:13px;"></textarea>
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
    <li><strong>name</strong> - The location name (required)</li>
    <li><strong>description</strong> - The location description (required)</li>
    <li><strong>division_affinities</strong> - Comma-separated list of division names (optional)</li>
  </ul>
  <p class="small">Example without division affinities:</p>
  <pre style="background:#f5f5f5;padding:12px;border-radius:4px;">name,description
Court A,Main competition court
Court B,Practice court
Field 1,Outdoor field</pre>
  
  <p class="small" style="margin-top:16px;">Example with division affinities:</p>
  <pre style="background:#f5f5f5;padding:12px;border-radius:4px;">name,description,division_affinities
Court A,Main competition court,"Division A, Division B"
Court B,Practice court,Division C
Field 1,Outdoor field,"Division A, Division C"</pre>
  
  <p class="small" style="margin-top:8px;"><strong>Note:</strong> If a location already exists, only the description will be updated. Division affinities will be added if they don't already exist.</p>
</div>

<?php footer_html(); ?>
