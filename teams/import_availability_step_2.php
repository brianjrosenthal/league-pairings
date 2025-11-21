<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CsvImportHelper.php';
Application::init();
require_login();

// Check if we have import data in session
if (!isset($_SESSION['team_availability_import']['file_path'])) {
    header('Location: /teams/import_availability_step_1.php?err=' . urlencode('Please upload a CSV file first.'));
    exit;
}

$importData = $_SESSION['team_availability_import'];
$filePath = $importData['file_path'];
$delimiter = CsvImportHelper::getDelimiterChar($importData['delimiter']);

$msg = null;
$err = null;

try {
    // Get CSV headers
    $csvHeaders = CsvImportHelper::getCSVHeaders($filePath, $delimiter);
    
    // Auto-detect team and division columns
    $defaultTeamColumn = '';
    $defaultDivisionColumn = '';
    
    foreach ($csvHeaders as $header) {
        $headerLower = strtolower(trim($header));
        
        if ($headerLower === 'team initials' && $defaultTeamColumn === '') {
            $defaultTeamColumn = $header;
        }
        
        if ($headerLower === 'team division' && $defaultDivisionColumn === '') {
            $defaultDivisionColumn = $header;
        }
    }
    
    // Find and parse availability columns
    $availabilityColumns = [];
    foreach ($csvHeaders as $header) {
        // Use preg_match to handle stylized quotes (') vs straight quotes (')
        // Match "Please select your team.s availability" where . is any character
        if (preg_match('/^Please select your team.s availability for each possible game date/i', $header)) {
            // Extract date and modifier from column name
            // Pattern: [Day Month DD, YYYY - MODIFIER]
            // Examples: [Tue Jan 27, 2026 - 7:00 PM] or [Sun Jan 25, 2026 - AM]
            if (preg_match('/\[([A-Za-z]+)\s+([A-Za-z]+)\s+(\d+),\s+(\d{4})\s*-\s*(.+?)\]/', $header, $matches)) {
                $dayName = $matches[1]; // e.g., "Tue", "Sun"
                $monthName = $matches[2]; // e.g., "Jan"
                $day = $matches[3]; // e.g., "27"
                $year = $matches[4]; // e.g., "2026"
                $modifier = trim($matches[5]); // e.g., "7:00 PM" or "AM"
                
                // Convert to date format
                $dateStr = "$monthName $day, $year";
                $date = date('Y-m-d', strtotime($dateStr));
                
                $availabilityColumns[] = [
                    'original_header' => $header,
                    'display' => "$dayName $monthName $day, $year - $modifier",
                    'date' => $date,
                    'modifier' => $modifier
                ];
            }
        }
    }
    
} catch (Exception $e) {
    $err = $e->getMessage();
    $csvHeaders = [];
    $defaultTeamColumn = '';
    $defaultDivisionColumn = '';
    $availabilityColumns = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    
    try {
        // Get column mapping
        $teamColumn = $_POST['team_column'] ?? '';
        $divisionColumn = $_POST['division_column'] ?? '';
        
        if ($teamColumn === '') {
            throw new InvalidArgumentException('Please select a column for Team Name.');
        }
        
        if ($divisionColumn === '') {
            throw new InvalidArgumentException('Please select a column for Division.');
        }
        
        // Store mapping in session
        $_SESSION['team_availability_import']['column_mapping'] = [
            'team' => $teamColumn,
            'division' => $divisionColumn
        ];
        
        $_SESSION['team_availability_import']['availability_columns'] = $availabilityColumns;
        
        // Redirect to step 3
        header('Location: /teams/import_availability_step_3.php');
        exit;
        
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}

header_html('Import Team Availability - Step 2');
?>

<h2>Import Team Availability - Step 2 of 4</h2>
<p class="small">Map CSV columns and review detected availability dates</p>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <p><strong>File:</strong> <?= h($importData['original_filename']) ?></p>
  <p><strong>Delimiter:</strong> <?= h(ucfirst($importData['delimiter'])) ?></p>
  <p><strong>Columns found:</strong> <?= count($csvHeaders) ?></p>
  <p><strong>Availability columns found:</strong> <?= count($availabilityColumns) ?></p>
</div>

<?php if (!empty($csvHeaders)): ?>
<div class="card">
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    
    <h3>Column Mapping</h3>
    <p class="small">Select which columns from your CSV file contain the team identification data.</p>
    
    <label>Team Name <span style="color:red;">*</span>
      <select name="team_column" required>
        <option value="">-- Select Column --</option>
        <?php foreach ($csvHeaders as $header): ?>
          <?php if (!preg_match('/^Please select your team.s availability/i', $header)): ?>
            <option value="<?= h($header) ?>" <?= ($header === $defaultTeamColumn) ? 'selected' : '' ?>><?= h($header) ?></option>
          <?php endif; ?>
        <?php endforeach; ?>
      </select>
      <small>Team name must match an existing team</small>
    </label>

    <label>Division <span style="color:red;">*</span>
      <select name="division_column" required>
        <option value="">-- Select Column --</option>
        <?php foreach ($csvHeaders as $header): ?>
          <?php if (!preg_match('/^Please select your team.s availability/i', $header)): ?>
            <option value="<?= h($header) ?>" <?= ($header === $defaultDivisionColumn) ? 'selected' : '' ?>><?= h($header) ?></option>
          <?php endif; ?>
        <?php endforeach; ?>
      </select>
      <small>Division must match the team's actual division</small>
    </label>

    <?php if (!empty($availabilityColumns)): ?>
      <h3>Detected Availability Dates</h3>
      <p class="small">The following game dates were found in your CSV file:</p>
      <ul style="list-style:none;padding-left:0;">
        <?php foreach ($availabilityColumns as $col): ?>
          <li style="padding:4px 0;">
            <strong><?= h($col['display']) ?></strong>
            <span class="small" style="color:#666;"> (Date: <?= h($col['date']) ?>, Modifier: <?= h($col['modifier']) ?>)</span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div style="background:#fff3e0;padding:12px;border-radius:4px;margin-top:12px;">
        <p style="margin:0;"><strong>⚠️ Warning:</strong> No availability columns were detected. Make sure your column names start with "Please select your team's availability for each possible game date" and include dates in brackets.</p>
      </div>
    <?php endif; ?>

    <div class="actions">
      <button class="primary" type="submit">Next Step →</button>
      <a class="button" href="/teams/import_availability_step_1.php">← Back</a>
      <a class="button" href="/teams/">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php footer_html(); ?>
