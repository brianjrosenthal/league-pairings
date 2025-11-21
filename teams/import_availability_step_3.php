<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CsvImportHelper.php';
require_once __DIR__ . '/../lib/TeamManagement.php';
require_once __DIR__ . '/../lib/TimeslotManagement.php';
Application::init();
require_login();

// Check if we have import data in session
if (!isset($_SESSION['team_availability_import']['availability_columns'])) {
    header('Location: /teams/import_availability_step_1.php?err=' . urlencode('Please complete previous steps first.'));
    exit;
}

$importData = $_SESSION['team_availability_import'];
$filePath = $importData['file_path'];
$delimiter = CsvImportHelper::getDelimiterChar($importData['delimiter']);
$mapping = $importData['column_mapping'];
$availabilityColumns = $importData['availability_columns'];

$msg = null;
$err = null;
$previewData = [];
$hasErrors = false;

// Helper function to parse time to 24-hour format
function parseTimeToMinutes($timeStr) {
    $timeStr = trim($timeStr);
    
    // Handle AM/PM
    if (strtoupper($timeStr) === 'AM' || strtoupper($timeStr) === 'PM') {
        return null; // Special case, not a specific time
    }
    
    // Parse time like "7:00 PM" or "7:30 AM"
    if (preg_match('/(\d+):(\d+)\s*(AM|PM)/i', $timeStr, $matches)) {
        $hour = (int)$matches[1];
        $minute = (int)$matches[2];
        $ampm = strtoupper($matches[3]);
        
        // Convert to 24-hour format
        if ($ampm === 'PM' && $hour !== 12) {
            $hour += 12;
        } elseif ($ampm === 'AM' && $hour === 12) {
            $hour = 0;
        }
        
        return $hour * 60 + $minute; // Return total minutes from midnight
    }
    
    return null;
}

try {
    // Load all timeslots grouped by date
    $allTimeslots = TimeslotManagement::listTimeslots();
    $timeslotsByDate = [];
    foreach ($allTimeslots as $ts) {
        $date = $ts['date'];
        if (!isset($timeslotsByDate[$date])) {
            $timeslotsByDate[$date] = [];
        }
        $timeslotsByDate[$date][] = [
            'id' => (int)$ts['id'],
            'modifier' => $ts['modifier'],
            'minutes' => parseTimeToMinutes($ts['modifier'])
        ];
    }
    
    // Read CSV file
    $csvData = CsvImportHelper::parseCSV($filePath, $delimiter);
    
    $lineNumber = 1; // Start at 1 for header
    foreach ($csvData as $row) {
        $lineNumber++;
        
        $teamName = trim($row[$mapping['team']] ?? '');
        $divisionName = trim($row[$mapping['division']] ?? '');
        
        // Skip empty rows
        if ($teamName === '' && $divisionName === '') {
            continue;
        }
        
        $item = [
            'line_number' => $lineNumber,
            'team_name' => $teamName,
            'division_name' => $divisionName,
            'has_error' => false,
            'error_message' => null,
            'team_id' => null,
            'available_count' => 0,
            'unavailable_count' => 0
        ];
        
        // Validate team exists
        if ($teamName === '') {
            $item['has_error'] = true;
            $item['error_message'] = 'Team name is required';
            $hasErrors = true;
        } else {
            $team = TeamManagement::findByName($teamName);
            if (!$team) {
                $item['has_error'] = true;
                $item['error_message'] = "Team '{$teamName}' not found";
                $hasErrors = true;
            } else {
                $item['team_id'] = (int)$team['id'];
                // Division validation removed - ignoring division column for flexibility
            }
        }
        
        // Process availability columns if team is valid
        if (!$item['has_error'] && $item['team_id']) {
            $availableTimeslots = [];
            $unavailableTimeslots = [];
            $debugInfo = []; // Store debugging information
            
            foreach ($availabilityColumns as $col) {
                $cellValue = trim($row[$col['original_header']] ?? '');
                
                if ($cellValue === '') {
                    continue; // Skip empty cells
                }
                
                $date = $col['date'];
                $modifier = $col['modifier'];
                
                // Find matching timeslots for this date/modifier combination
                $matchedTimeslotIds = [];
                
                if (isset($timeslotsByDate[$date])) {
                    $modifierUpper = strtoupper(trim($modifier));
                    
                    foreach ($timeslotsByDate[$date] as $ts) {
                        $tsModifier = trim($ts['modifier']);
                        $tsMinutes = $ts['minutes'];
                        
                        if ($modifierUpper === 'AM') {
                            // Match timeslots <= 11:30 AM (690 minutes)
                            if ($tsMinutes !== null && $tsMinutes <= 690) {
                                $matchedTimeslotIds[] = $ts['id'];
                            }
                        } elseif ($modifierUpper === 'PM') {
                            // Match timeslots >= 12:00 PM (720 minutes)
                            if ($tsMinutes !== null && $tsMinutes >= 720) {
                                $matchedTimeslotIds[] = $ts['id'];
                            }
                        } else {
                            // Specific time - match within +30 minutes (forward only)
                            $targetMinutes = parseTimeToMinutes($modifier);
                            if ($targetMinutes !== null && $tsMinutes !== null) {
                                // Match if timeslot is between target time and target time + 30 minutes
                                if ($tsMinutes >= $targetMinutes && $tsMinutes <= $targetMinutes + 30) {
                                    $matchedTimeslotIds[] = $ts['id'];
                                }
                            }
                        }
                    }
                }
                
                // Store debug info for this column
                $action = '';
                if (strtolower($cellValue) === 'available') {
                    $action = 'ADD';
                    $availableTimeslots = array_merge($availableTimeslots, $matchedTimeslotIds);
                } elseif (strtolower($cellValue) === 'not available') {
                    $action = 'REMOVE';
                    $unavailableTimeslots = array_merge($unavailableTimeslots, $matchedTimeslotIds);
                }
                
                // Store debugging information
                if (!empty($matchedTimeslotIds) || $cellValue !== '') {
                    $debugInfo[] = [
                        'column_header' => $col['original_header'],
                        'date' => $date,
                        'modifier' => $modifier,
                        'cell_value' => $cellValue,
                        'matched_ids' => $matchedTimeslotIds,
                        'action' => $action
                    ];
                }
            }
            
            // Remove duplicates and store counts
            $item['available_timeslots'] = array_unique($availableTimeslots);
            $item['unavailable_timeslots'] = array_unique($unavailableTimeslots);
            $item['available_count'] = count($item['available_timeslots']);
            $item['unavailable_count'] = count($item['unavailable_timeslots']);
            $item['debug_info'] = $debugInfo; // Store debug info
        }
        
        $previewData[] = $item;
    }
    
    // Store preview data in session for step 4
    $_SESSION['team_availability_import']['preview_data'] = $previewData;
    
} catch (Exception $e) {
    $err = $e->getMessage();
}

header_html('Import Team Availability - Step 3');
?>

<h2>Import Team Availability - Step 3 of 4</h2>
<p class="small">Review import preview</p>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <p><strong>File:</strong> <?= h($importData['original_filename']) ?></p>
  <p><strong>Total teams:</strong> <?= count($previewData) ?></p>
  <p><strong>Teams with errors:</strong> <?= count(array_filter($previewData, fn($item) => $item['has_error'])) ?></p>
  <p><strong>Valid teams:</strong> <?= count(array_filter($previewData, fn($item) => !$item['has_error'])) ?></p>
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
            (Team: <?= h($item['team_name']) ?>, Division: <?= h($item['division_name']) ?>)
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
        <th>Team</th>
        <th>Division</th>
        <th>Will Add</th>
        <th>Will Remove</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($previewData as $item): ?>
        <tr style="<?= $item['has_error'] ? 'background:#ffebee;' : '' ?>">
          <td><?= (int)$item['line_number'] ?></td>
          <td><?= h($item['team_name']) ?></td>
          <td><?= h($item['division_name']) ?></td>
          <td><?= $item['has_error'] ? '-' : (int)$item['available_count'] ?></td>
          <td><?= $item['has_error'] ? '-' : (int)$item['unavailable_count'] ?></td>
          <td>
            <?php if ($item['has_error']): ?>
              <span style="color:#d32f2f;">❌ <?= h($item['error_message']) ?></span>
            <?php else: ?>
              <span style="color:#388e3c;">✓ Ready</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Debugging Information -->
<?php 
$teamsWithDebugInfo = array_filter($previewData, fn($item) => !empty($item['debug_info']));
if (!empty($teamsWithDebugInfo)): 
?>
<div class="card">
  <h3>🔍 Column Mapping Debug Information</h3>
  <p class="small">Detailed view of how CSV columns map to timeslots for each team</p>
  
  <?php foreach ($teamsWithDebugInfo as $item): ?>
    <div style="margin-bottom:24px;padding:12px;background:#f5f5f5;border-radius:4px;">
      <h4 style="margin-top:0;margin-bottom:12px;">
        <?= h($item['team_name']) ?> 
        <span style="color:#666;font-weight:normal;font-size:0.9em;">(Line <?= (int)$item['line_number'] ?>)</span>
      </h4>
      
      <?php if (!empty($item['debug_info'])): ?>
        <table style="width:100%;border-collapse:collapse;font-size:0.85em;">
          <thead>
            <tr style="background:#e0e0e0;">
              <th style="padding:8px;text-align:left;border:1px solid #ccc;">CSV Column</th>
              <th style="padding:8px;text-align:left;border:1px solid #ccc;">Parsed Date</th>
              <th style="padding:8px;text-align:left;border:1px solid #ccc;">Parsed Modifier</th>
              <th style="padding:8px;text-align:left;border:1px solid #ccc;">Cell Value</th>
              <th style="padding:8px;text-align:left;border:1px solid #ccc;">Matched Timeslot IDs</th>
              <th style="padding:8px;text-align:left;border:1px solid #ccc;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($item['debug_info'] as $debug): ?>
              <tr>
                <td style="padding:8px;border:1px solid #ccc;font-size:0.75em;max-width:200px;word-wrap:break-word;">
                  <?= h($debug['column_header']) ?>
                </td>
                <td style="padding:8px;border:1px solid #ccc;">
                  <strong><?= h($debug['date']) ?></strong>
                </td>
                <td style="padding:8px;border:1px solid #ccc;">
                  <strong><?= h($debug['modifier']) ?></strong>
                </td>
                <td style="padding:8px;border:1px solid #ccc;">
                  <?= h($debug['cell_value']) ?>
                </td>
                <td style="padding:8px;border:1px solid #ccc;">
                  <?php if (!empty($debug['matched_ids'])): ?>
                    <span style="color:#1976d2;"><?= implode(', ', $debug['matched_ids']) ?></span>
                  <?php else: ?>
                    <span style="color:#999;">No matches</span>
                  <?php endif; ?>
                </td>
                <td style="padding:8px;border:1px solid #ccc;">
                  <?php if ($debug['action'] === 'ADD'): ?>
                    <span style="color:#388e3c;font-weight:bold;">➕ ADD</span>
                  <?php elseif ($debug['action'] === 'REMOVE'): ?>
                    <span style="color:#f57c00;font-weight:bold;">➖ REMOVE</span>
                  <?php else: ?>
                    <span style="color:#999;">-</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p style="color:#999;font-style:italic;">No availability data for this team</p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="card">
  <form method="post" action="/teams/import_availability_step_4.php" onsubmit="return handleImportSubmit(this);">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <?php
    // Remove debug_info from preview data before passing to step 4
    $previewDataForStep4 = array_map(function($item) {
        unset($item['debug_info']);
        return $item;
    }, $previewData);
    ?>
    <input type="hidden" name="preview_data" value="<?=h(json_encode($previewDataForStep4))?>">
    <div class="actions">
      <?php if (!$hasErrors): ?>
        <button class="primary" type="submit" id="commitImportBtn">Commit Import</button>
      <?php endif; ?>
      <a class="button" href="/teams/import_availability_step_2.php">← Back</a>
      <a class="button" href="/teams/">Cancel</a>
    </div>
  </form>
</div>

<script>
function handleImportSubmit(form) {
  const btn = document.getElementById('commitImportBtn');
  if (btn.disabled) {
    return false; // Already submitting
  }
  btn.disabled = true;
  btn.textContent = 'Processing...';
  btn.style.opacity = '0.6';
  return true;
}
</script>

<?php footer_html(); ?>
