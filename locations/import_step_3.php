<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CsvImportHelper.php';
require_once __DIR__ . '/../lib/LocationManagement.php';
Application::init();
require_login();

// Check if we have import data in session
if (!isset($_SESSION['location_import']['column_mapping'])) {
    header('Location: /locations/import_step_1.php?err=' . urlencode('Please complete previous steps first.'));
    exit;
}

$importData = $_SESSION['location_import'];
$filePath = $importData['file_path'];
$delimiter = CsvImportHelper::getDelimiterChar($importData['delimiter']);
$mapping = $importData['column_mapping'];

$msg = null;
$err = null;
$previewData = [];

try {
    // Get existing locations
    $existingLocations = LocationManagement::getAllLocationData(); // Returns array [name => description]
    
    // Read CSV file
    $csvData = CsvImportHelper::parseCSV($filePath, $delimiter);
    
    $lineNumber = 1; // Start at 1 for header
    foreach ($csvData as $row) {
        $lineNumber++;
        
        $name = trim($row[$mapping['name']] ?? '');
        $description = trim($row[$mapping['description']] ?? '');
        
        // Skip empty rows
        if ($name === '' && $description === '') {
            continue;
        }
        
        $item = [
            'line_number' => $lineNumber,
            'name' => $name,
            'description' => $description,
            'is_duplicate' => false,
            'will_update' => false,
            'action' => 'add' // add, duplicate, or update
        ];
        
        // Validate required fields
        if ($name === '') {
            // Skip rows without names but don't error
            continue;
        }
        
        // Check if location already exists
        if (isset($existingLocations[$name])) {
            $existingDescription = $existingLocations[$name];
            
            if ($existingDescription === $description) {
                // Same name, same description - duplicate (will be ignored)
                $item['is_duplicate'] = true;
                $item['action'] = 'duplicate';
            } else {
                // Same name, different description - will update
                $item['will_update'] = true;
                $item['action'] = 'update';
            }
        }
        
        $previewData[] = $item;
    }
    
    // Store preview data in session for step 4
    $_SESSION['location_import']['preview_data'] = $previewData;
    
} catch (Exception $e) {
    $err = $e->getMessage();
}

header_html('Import Locations - Step 3');
?>

<h2>Import Locations - Step 3 of 4</h2>
<p class="small">Review import preview</p>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <p><strong>File:</strong> <?= h($importData['original_filename']) ?></p>
  <p><strong>Total rows:</strong> <?= count($previewData) ?></p>
  <p><strong>New locations:</strong> <?= count(array_filter($previewData, fn($item) => $item['action'] === 'add')) ?></p>
  <p><strong>Will update description:</strong> <?= count(array_filter($previewData, fn($item) => $item['will_update'])) ?></p>
  <p><strong>Duplicates (will be ignored):</strong> <?= count(array_filter($previewData, fn($item) => $item['is_duplicate'])) ?></p>
</div>

<?php if (!empty($previewData)): ?>
<div class="card">
  <h3>Preview</h3>
  <table class="list">
    <thead>
      <tr>
        <th>Line</th>
        <th>Location Name</th>
        <th>Description</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($previewData as $item): ?>
        <tr style="<?= $item['will_update'] ? 'background:#e3f2fd;' : ($item['is_duplicate'] ? 'background:#fff3e0;' : '') ?>">
          <td><?= (int)$item['line_number'] ?></td>
          <td><?= h($item['name']) ?></td>
          <td><?= h($item['description']) ?></td>
          <td>
            <?php if ($item['will_update']): ?>
              <span style="color:#1976d2;">🔄 Will update description</span>
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
  <form method="post" action="/locations/import_step_4.php">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <div class="actions">
      <button class="primary" type="submit">Commit Import</button>
      <a class="button" href="/locations/import_step_2.php">← Back</a>
      <a class="button" href="/locations/">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
