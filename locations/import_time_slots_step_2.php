<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CsvImportHelper.php';
Application::init();
require_login();

// Check if we have import data in session
if (!isset($_SESSION['location_timeslot_import']['file_path'])) {
    header('Location: /locations/import_time_slots_step_1.php?err=' . urlencode('Please upload a CSV file first.'));
    exit;
}

$importData = $_SESSION['location_timeslot_import'];
$filePath = $importData['file_path'];
$delimiter = CsvImportHelper::getDelimiterChar($importData['delimiter']);

$msg = null;
$err = null;

try {
    // Get CSV headers
    $csvHeaders = CsvImportHelper::getCSVHeaders($filePath, $delimiter);
    
    // Auto-detect columns with smart defaults
    $defaultLocationColumn = '';
    $defaultDateColumn = '';
    $defaultModifierColumn = '';
    
    foreach ($csvHeaders as $header) {
        $headerLower = strtolower(trim($header));
        
        // Location column detection
        if ($headerLower === 'location' && $defaultLocationColumn === '') {
            $defaultLocationColumn = $header;
        }
        
        // Date column detection - "Game Date" or "date"
        if (($headerLower === 'game date' || $headerLower === 'date') && $defaultDateColumn === '') {
            $defaultDateColumn = $header;
        }
        
        // Modifier column detection - "Start Time" or "modifier"
        if (($headerLower === 'start time' || $headerLower === 'modifier') && $defaultModifierColumn === '') {
            $defaultModifierColumn = $header;
        }
    }
} catch (Exception $e) {
    $err = $e->getMessage();
    $csvHeaders = [];
    $defaultLocationColumn = '';
    $defaultDateColumn = '';
    $defaultModifierColumn = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    
    try {
        // Get column mapping
        $locationColumn = $_POST['location_column'] ?? '';
        $dateColumn = $_POST['date_column'] ?? '';
        $modifierColumn = $_POST['modifier_column'] ?? '';
        
        if ($locationColumn === '') {
            throw new InvalidArgumentException('Please select a column for Location.');
        }
        
        if ($dateColumn === '') {
            throw new InvalidArgumentException('Please select a column for Date.');
        }
        
        if ($modifierColumn === '') {
            throw new InvalidArgumentException('Please select a column for Modifier.');
        }
        
        // Store mapping in session
        $_SESSION['location_timeslot_import']['column_mapping'] = [
            'location' => $locationColumn,
            'date' => $dateColumn,
            'modifier' => $modifierColumn
        ];
        
        // Redirect to step 3
        header('Location: /locations/import_time_slots_step_3.php');
        exit;
        
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}

header_html('Import Location Time Slots - Step 2');
?>

<h2>Import Location Time Slots - Step 2 of 4</h2>
<p class="small">Map CSV columns to location time slot fields</p>

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
    <p class="small">Select which columns from your CSV file contain the location time slot data.</p>
    
    <label>Location <span style="color:red;">*</span>
      <select name="location_column" required>
        <option value="">-- Select Column --</option>
        <?php foreach ($csvHeaders as $header): ?>
          <option value="<?= h($header) ?>" <?= ($header === $defaultLocationColumn) ? 'selected' : '' ?>><?= h($header) ?></option>
        <?php endforeach; ?>
      </select>
      <small>Location name must match an existing location in the system</small>
    </label>

    <label>Date <span style="color:red;">*</span>
      <select name="date_column" required>
        <option value="">-- Select Column --</option>
        <?php foreach ($csvHeaders as $header): ?>
          <option value="<?= h($header) ?>" <?= ($header === $defaultDateColumn) ? 'selected' : '' ?>><?= h($header) ?></option>
        <?php endforeach; ?>
      </select>
      <small>Date in YYYY-MM-DD format (e.g., 2025-12-15)</small>
    </label>

    <label>Modifier <span style="color:red;">*</span>
      <select name="modifier_column" required>
        <option value="">-- Select Column --</option>
        <?php foreach ($csvHeaders as $header): ?>
          <option value="<?= h($header) ?>" <?= ($header === $defaultModifierColumn) ? 'selected' : '' ?>><?= h($header) ?></option>
        <?php endforeach; ?>
      </select>
      <small>Time or other modifier (e.g., "6:00 PM", "Morning", etc.)</small>
    </label>

    <div class="actions">
      <button class="primary" type="submit">Next Step →</button>
      <a class="button" href="/locations/import_time_slots_step_1.php">← Back</a>
      <a class="button" href="/locations/">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php footer_html(); ?>
