<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CsvImportHelper.php';
require_once __DIR__ . '/../lib/TeamManagement.php';
Application::init();
require_login();

// Check if we have import data in session
if (!isset($_SESSION['team_import']['column_mapping'])) {
    header('Location: /teams/import_step_1.php?err=' . urlencode('Please complete previous steps first.'));
    exit;
}

$importData = $_SESSION['team_import'];
$filePath = $importData['file_path'];
$delimiter = CsvImportHelper::getDelimiterChar($importData['delimiter']);
$mapping = $importData['column_mapping'];

$msg = null;
$err = null;
$previewData = [];
$hasErrors = false;

try {
    // Get existing teams, divisions, and locations
    $existingTeams = TeamManagement::getAllTeamNames(); // Returns array [name => division_name]
    $existingDivisions = TeamManagement::getAllDivisionNames(); // Returns array of division names
    $existingLocations = TeamManagement::getAllLocations(); // Returns array of location objects
    
    // Create a map of location names (lowercase) to location IDs for validation
    $locationsMap = [];
    foreach ($existingLocations as $location) {
        $locationsMap[strtolower($location['name'])] = (int)$location['id'];
    }
    
    // Read CSV file
    $csvData = CsvImportHelper::parseCSV($filePath, $delimiter);
    
    $lineNumber = 1; // Start at 1 for header
    foreach ($csvData as $row) {
        $lineNumber++;
        
        $name = trim($row[$mapping['name']] ?? '');
        $divisionName = trim($row[$mapping['division']] ?? '');
        $preferredLocationName = !empty($mapping['preferred_location']) ? trim($row[$mapping['preferred_location']] ?? '') : '';
        
        // Skip empty rows
        if ($name === '' && $divisionName === '') {
            continue;
        }
        
        $item = [
            'line_number' => $lineNumber,
            'name' => $name,
            'division' => $divisionName,
            'preferred_location' => $preferredLocationName,
            'preferred_location_valid' => true,
            'is_duplicate' => false,
            'has_error' => false,
            'error_message' => null
        ];
        
        // Validate preferred location if provided
        if ($preferredLocationName !== '' && !isset($locationsMap[strtolower($preferredLocationName)])) {
            $item['has_error'] = true;
            $item['preferred_location_valid'] = false;
            $item['error_message'] = "Preferred location '{$preferredLocationName}' does not exist";
            $hasErrors = true;
        }
        
        // Validate required fields
        if ($name === '') {
            $item['has_error'] = true;
            $item['error_message'] = 'Team name is required';
            $hasErrors = true;
        } elseif ($divisionName === '') {
            $item['has_error'] = true;
            $item['error_message'] = 'Division is required';
            $hasErrors = true;
        } else {
            // Check if division exists
            if (!in_array($divisionName, $existingDivisions)) {
                $item['has_error'] = true;
                $item['error_message'] = "Division '{$divisionName}' does not exist";
                $hasErrors = true;
            } else {
                // Check for duplicate team name
                if (isset($existingTeams[$name])) {
                    $existingDivision = $existingTeams[$name];
                    
                    if ($existingDivision === $divisionName) {
                        // Same team, same division - mark as duplicate for updating
                        $item['is_duplicate'] = true;
                        // But if there's a preferred location, we can update it
                        if (!empty($item['preferred_location']) && $item['preferred_location_valid']) {
                            $item['will_update'] = true;
                        }
                    } else {
                        // Same team name but different division - this is an error
                        $item['has_error'] = true;
                        $item['error_message'] = "Team '{$name}' already exists in division '{$existingDivision}'";
                        $hasErrors = true;
                    }
                }
            }
        }
        
        $previewData[] = $item;
    }
    
    // Store preview data in session for step 4
    $_SESSION['team_import']['preview_data'] = $previewData;
    
} catch (Exception $e) {
    $err = $e->getMessage();
}

header_html('Import Teams - Step 3');
?>

<h2>Import Teams - Step 3 of 4</h2>
<p class="small">Review import preview</p>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <p><strong>File:</strong> <?= h($importData['original_filename']) ?></p>
  <p><strong>Total rows:</strong> <?= count($previewData) ?></p>
  <p><strong>New teams:</strong> <?= count(array_filter($previewData, fn($item) => !$item['is_duplicate'] && !$item['has_error'])) ?></p>
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
            (Team: <?= h($item['name']) ?>, Division: <?= h($item['division']) ?>)
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
        <th>Team Name</th>
        <th>Division</th>
        <th>Preferred Location</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($previewData as $item): ?>
        <tr style="<?= $item['has_error'] ? 'background:#ffebee;' : ($item['is_duplicate'] ? 'background:#fff3e0;' : '') ?>">
          <td><?= (int)$item['line_number'] ?></td>
          <td><?= h($item['name']) ?></td>
          <td><?= h($item['division']) ?></td>
          <td>
            <?php if (!empty($item['preferred_location'])): ?>
              <?php if ($item['preferred_location_valid']): ?>
                <span style="color:#388e3c;">✓ <?= h($item['preferred_location']) ?></span>
              <?php else: ?>
                <span style="color:#d32f2f;">❌ <?= h($item['preferred_location']) ?> (invalid)</span>
              <?php endif; ?>
            <?php else: ?>
              <span style="color:#999;">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($item['has_error']): ?>
              <span style="color:#d32f2f;">❌ Error: <?= h($item['error_message']) ?></span>
            <?php elseif ($item['is_duplicate']): ?>
              <?php if (!empty($item['will_update'])): ?>
                <span style="color:#1976d2;">🔄 Will update preferred location</span>
              <?php else: ?>
                <span style="color:#f57c00;">⚠️ Duplicate (will be ignored)</span>
              <?php endif; ?>
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
  <form method="post" action="/teams/import_step_4.php">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <div class="actions">
      <?php if (!$hasErrors): ?>
        <button class="primary" type="submit">Commit Import</button>
      <?php endif; ?>
      <a class="button" href="/teams/import_step_2.php">← Back</a>
      <a class="button" href="/teams/">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
