<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CsvImportHelper.php';
require_once __DIR__ . '/../lib/LocationManagement.php';
require_once __DIR__ . '/../lib/TimeslotManagement.php';
require_once __DIR__ . '/../lib/LocationAvailabilityManagement.php';
Application::init();
require_login();

// Check if we have import data in session
if (!isset($_SESSION['location_timeslot_import']['column_mapping'])) {
    header('Location: /locations/import_time_slots_step_1.php?err=' . urlencode('Please complete previous steps first.'));
    exit;
}

$importData = $_SESSION['location_timeslot_import'];
$filePath = $importData['file_path'];
$delimiter = CsvImportHelper::getDelimiterChar($importData['delimiter']);
$mapping = $importData['column_mapping'];

$msg = null;
$err = null;
$previewData = [];
$hasErrors = false;

try {
    // Get all existing locations
    $locations = LocationManagement::listLocations();
    $locationNames = [];
    foreach ($locations as $loc) {
        $locationNames[$loc['name']] = (int)$loc['id'];
    }
    
    // Get all existing location-timeslot combinations
    $existingCombinations = LocationAvailabilityManagement::getAllLocationTimeslots();
    
    // Read CSV file
    $csvData = CsvImportHelper::parseCSV($filePath, $delimiter);
    
    $lineNumber = 1; // Start at 1 for header
    foreach ($csvData as $row) {
        $lineNumber++;
        
        $location = trim($row[$mapping['location']] ?? '');
        $date = trim($row[$mapping['date']] ?? '');
        $modifier = trim($row[$mapping['modifier']] ?? '');
        
        // Convert null to empty string for modifier
        if ($modifier === 'null' || $modifier === 'NULL') {
            $modifier = '';
        }
        
        // Skip empty rows
        if ($location === '' && $date === '' && $modifier === '') {
            continue;
        }
        
        $item = [
            'line_number' => $lineNumber,
            'location' => $location,
            'date' => $date,
            'modifier' => $modifier,
            'is_duplicate' => false,
            'has_error' => false,
            'error_message' => null
        ];
        
        // Validate required fields
        if ($location === '') {
            $item['has_error'] = true;
            $item['error_message'] = 'Location is required';
            $hasErrors = true;
        } elseif ($date === '') {
            $item['has_error'] = true;
            $item['error_message'] = 'Date is required';
            $hasErrors = true;
        } else {
            // Validate location exists
            if (!isset($locationNames[$location])) {
                $item['has_error'] = true;
                $item['error_message'] = "Location '{$location}' does not exist in the system";
                $hasErrors = true;
            }
            
            // Validate date format
            $d = \DateTime::createFromFormat('Y-m-d', $date);
            if (!$d || $d->format('Y-m-d') !== $date) {
                $item['has_error'] = true;
                $item['error_message'] = "Invalid date format (use YYYY-MM-DD)";
                $hasErrors = true;
            }
            
            // Check for duplicates (only if no errors so far)
            if (!$item['has_error']) {
                $key = $location . '|' . $date . '|' . $modifier;
                if (isset($existingCombinations[$key])) {
                    $item['is_duplicate'] = true;
                }
            }
        }
        
        $previewData[] = $item;
    }
    
    // Store preview data in session for step 4
    $_SESSION['location_timeslot_import']['preview_data'] = $previewData;
    
} catch (Exception $e) {
    $err = $e->getMessage();
}

header_html('Import Location Time Slots - Step 3');
?>

<h2>Import Location Time Slots - Step 3 of 4</h2>
<p class="small">Review import preview</p>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <p><strong>File:</strong> <?= h($importData['original_filename']) ?></p>
  <p><strong>Total rows:</strong> <?= count($previewData) ?></p>
  <p><strong>New location time slots:</strong> <?= count(array_filter($previewData, fn($item) => !$item['is_duplicate'] && !$item['has_error'])) ?></p>
  <p><strong>Duplicates (will be ignored):</strong> <?= count(array_filter($previewData, fn($item) => $item['is_duplicate'])) ?></p>
  <p><strong>Errors:</strong> <?= count(array_filter($previewData, fn($item) => $item['has_error'])) ?></p>
</div>

<?php if ($hasErrors): ?>
  <div class="card" style="border-left: 4px solid #d32f2f;">
    <h3 style="color:#d32f2f;">⚠️ Import Cannot Proceed</h3>
    <p>The following errors must be resolved before importing:</p>
    <ul>
      <?php foreach ($previewData as $item): ?>
        <?php if ($item['has_error']): ?>
          <li>
            <strong>Line <?= (int)$item['line_number'] ?>:</strong>
            <?= h($item['error_message']) ?>
            (Location: <?= h($item['location']) ?>, Date: <?= h($item['date']) ?>, Modifier: <?= h($item['modifier']) ?>)
          </li>
        <?php endif; ?>
      <?php endforeach; ?>
    </ul>
    <p><strong>Please go back and fix your CSV file, then re-upload it.</strong></p>
  </div>
<?php endif; ?>

<?php if (!empty($previewData)): ?>
<div class="card">
  <h3>Preview</h3>
  <table class="list">
    <thead>
      <tr>
        <th>Line</th>
        <th>Location</th>
        <th>Date</th>
        <th>Modifier</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($previewData as $item): ?>
        <tr style="<?= $item['has_error'] ? 'background:#ffebee;' : ($item['is_duplicate'] ? 'background:#fff3e0;' : '') ?>">
          <td><?= (int)$item['line_number'] ?></td>
          <td><?= h($item['location']) ?></td>
          <td><?= h($item['date']) ?></td>
          <td><?= h($item['modifier']) ?></td>
          <td>
            <?php if ($item['has_error']): ?>
              <span style="color:#d32f2f;">❌ Error: <?= h($item['error_message']) ?></span>
            <?php elseif ($item['is_duplicate']): ?>
              <span style="color:#f57c00;">⚠️ Duplicate (will be ignored)</span>
            <?php else: ?>
              <span style="color:#388e3c;">✓ Will be added</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="card">
  <form method="post" action="/locations/import_time_slots_step_4.php">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <div class="actions">
      <?php if (!$hasErrors): ?>
        <button class="primary" type="submit">Commit Import</button>
      <?php endif; ?>
      <a class="button" href="/locations/import_time_slots_step_2.php">← Back</a>
      <a class="button" href="/locations/">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
