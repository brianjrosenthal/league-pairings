<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CsvImportHelper.php';
require_once __DIR__ . '/../lib/DivisionManagement.php';
Application::init();
require_login();

// Check if we have import data in session
if (!isset($_SESSION['division_import']['file_path']) || !isset($_SESSION['division_import']['column_mapping'])) {
    header('Location: /divisions/import_step_1.php?err=' . urlencode('Please complete previous steps first.'));
    exit;
}

$importData = $_SESSION['division_import'];
$filePath = $importData['file_path'];
$delimiter = CsvImportHelper::getDelimiterChar($importData['delimiter']);
$columnMapping = $importData['column_mapping'];

$msg = null;
$err = null;
$previewData = [];

try {
    // Parse CSV
    $csvRows = CsvImportHelper::parseCSV($filePath, $delimiter);
    
    // Get existing division names (lowercase for case-insensitive comparison)
    $existingNames = DivisionManagement::getAllDivisionNames();
    
    // Process each row
    $newCount = 0;
    $duplicateCount = 0;
    
    foreach ($csvRows as $row) {
        $name = trim($row[$columnMapping['name']] ?? '');
        
        if ($name === '') {
            continue; // Skip empty rows
        }
        
        $nameLower = strtolower($name);
        $isDuplicate = in_array($nameLower, $existingNames, true);
        
        $previewData[] = [
            'name' => $name,
            'is_duplicate' => $isDuplicate,
            'line_number' => $row['_line_number']
        ];
        
        if ($isDuplicate) {
            $duplicateCount++;
        } else {
            $newCount++;
            // Add to existing names to catch duplicates within the CSV
            $existingNames[] = $nameLower;
        }
    }
    
    // Store preview data in session
    $_SESSION['division_import']['preview_data'] = $previewData;
    $_SESSION['division_import']['stats'] = [
        'total' => count($previewData),
        'new' => $newCount,
        'duplicate' => $duplicateCount
    ];
    
} catch (Exception $e) {
    $err = $e->getMessage();
}

header_html('Import Divisions - Step 3');
?>

<h2>Import Divisions - Step 3 of 4</h2>
<p class="small">Preview import and verify data</p>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<?php if (isset($_SESSION['division_import']['stats'])): 
    $stats = $_SESSION['division_import']['stats'];
?>
<div class="card">
  <h3>Import Summary</h3>
  <p><strong>Total rows:</strong> <?= (int)$stats['total'] ?></p>
  <p><strong style="color:green;">New divisions to add:</strong> <?= (int)$stats['new'] ?></p>
  <p><strong style="color:orange;">Duplicates to skip:</strong> <?= (int)$stats['duplicate'] ?></p>
</div>
<?php endif; ?>

<?php if (!empty($previewData)): ?>
<div class="card">
  <h3>Preview</h3>
  <div style="max-height:400px;overflow-y:auto;">
    <table class="list">
      <thead>
        <tr>
          <th>Line</th>
          <th>Division Name</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($previewData as $item): ?>
          <tr style="<?= $item['is_duplicate'] ? 'opacity:0.6;' : '' ?>">
            <td><?= (int)$item['line_number'] ?></td>
            <td><?= h($item['name']) ?></td>
            <td>
              <?php if ($item['is_duplicate']): ?>
                <span style="color:orange;">⚠ Duplicate - Will Skip</span>
              <?php else: ?>
                <span style="color:green;">✓ New - Will Add</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <form method="post" action="/divisions/import_step_4.php">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    
    <div class="actions">
      <button class="primary" type="submit" <?= $stats['new'] > 0 ? '' : 'disabled' ?>>
        Commit Import (Add <?= (int)$stats['new'] ?> Divisions)
      </button>
      <a class="button" href="/divisions/import_step_2.php">← Back</a>
      <a class="button" href="/divisions/">Cancel</a>
    </div>
  </form>
</div>
<?php else: ?>
<div class="card">
  <p>No valid data to import.</p>
  <div class="actions">
    <a class="button" href="/divisions/import_step_1.php">← Start Over</a>
    <a class="button" href="/divisions/">Cancel</a>
  </div>
</div>
<?php endif; ?>

<?php footer_html(); ?>
