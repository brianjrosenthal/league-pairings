<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/CsvScheduleParser.php';
Application::init();
require_login();

// Handle file upload
$error = null;
$parser = null;
$csvContent = null;
$autoMapping = [];
$preview = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    
    // Check if file was uploaded
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Failed to upload file. Please try again.';
    } else {
        $file = $_FILES['csv_file'];
        
        // Validate file size (5MB max)
        if ($file['size'] > 5 * 1024 * 1024) {
            $error = 'File size exceeds 5MB limit.';
        } else {
            // Read CSV content
            $csvContent = file_get_contents($file['tmp_name']);
            
            if ($csvContent === false) {
                $error = 'Failed to read CSV file.';
            } else {
                // Parse CSV
                $parser = new CsvScheduleParser();
                if (!$parser->parseCSV($csvContent)) {
                    $error = 'Failed to parse CSV file. Please ensure it is a valid CSV format.';
                } else {
                    // Auto-detect column mapping
                    $autoMapping = $parser->autoDetectMapping();
                    $preview = $parser->getPreview(3);
                }
            }
        }
    }
} else {
    header('Location: /visualize_schedule.php');
    exit;
}

header_html('Map CSV Columns');
?>

<h2>Map CSV Columns</h2>

<div style="margin-bottom: 16px;">
    <a href="/visualize_schedule.php" class="button">← Back to Upload</a>
</div>

<?php if ($error): ?>
    <div class="error" style="margin-bottom: 24px;">
        <strong>Error:</strong> <?= h($error) ?>
    </div>
    
    <div style="margin-top: 16px;">
        <a href="/visualize_schedule.php" class="button">Try Again</a>
    </div>
<?php else: ?>
    
    <div class="card" style="margin-bottom: 24px;">
        <h3>CSV File Info</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-top: 12px;">
            <div>
                <div class="small" style="color: #666;">Rows</div>
                <div style="font-weight: 600; margin-top: 4px;"><?= $parser->getRowCount() ?></div>
            </div>
            <div>
                <div class="small" style="color: #666;">Columns</div>
                <div style="font-weight: 600; margin-top: 4px;"><?= count($parser->getHeaders()) ?></div>
            </div>
        </div>
    </div>
    
    <form method="POST" action="/visualize_schedule_result.php">
        <?= csrf_field() ?>
        
        <!-- Store CSV content in hidden field -->
        <input type="hidden" name="csv_content" value="<?= h($csvContent) ?>">
        
        <div class="card" style="margin-bottom: 24px;">
            <h3>Column Mapping</h3>
            <p>Map the columns from your CSV to the required fields. The system has auto-detected likely matches.</p>
            
            <div style="overflow-x: auto; margin-top: 20px;">
                <table class="list">
                    <thead>
                        <tr>
                            <th style="min-width: 150px;">Required Field</th>
                            <th style="min-width: 200px;">Map to CSV Column</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Date</strong> <span style="color: #d32f2f;">*</span></td>
                            <td>
                                <select name="mapping[date]" required style="width: 100%; padding: 6px; border: 1px solid #ddd; border-radius: 4px;">
                                    <option value="">-- Select Column --</option>
                                    <?php foreach ($parser->getHeaders() as $header): ?>
                                        <option value="<?= h($header) ?>" <?= ($autoMapping['date'] === $header) ? 'selected' : '' ?>>
                                            <?= h($header) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <?php if ($autoMapping['date']): ?>
                                    <span style="color: #388e3c;">✓ Auto-detected</span>
                                <?php else: ?>
                                    <span style="color: #f57c00;">! Select column</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        
                        <tr>
                            <td><strong>Division</strong> <span style="color: #d32f2f;">*</span></td>
                            <td>
                                <select name="mapping[division]" required style="width: 100%; padding: 6px; border: 1px solid #ddd; border-radius: 4px;">
                                    <option value="">-- Select Column --</option>
                                    <?php foreach ($parser->getHeaders() as $header): ?>
                                        <option value="<?= h($header) ?>" <?= ($autoMapping['division'] === $header) ? 'selected' : '' ?>>
                                            <?= h($header) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <?php if ($autoMapping['division']): ?>
                                    <span style="color: #388e3c;">✓ Auto-detected</span>
                                <?php else: ?>
                                    <span style="color: #f57c00;">! Select column</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        
                        <tr>
                            <td><strong>Location</strong> <span style="color: #d32f2f;">*</span></td>
                            <td>
                                <select name="mapping[location]" required style="width: 100%; padding: 6px; border: 1px solid #ddd; border-radius: 4px;">
                                    <option value="">-- Select Column --</option>
                                    <?php foreach ($parser->getHeaders() as $header): ?>
                                        <option value="<?= h($header) ?>" <?= ($autoMapping['location'] === $header) ? 'selected' : '' ?>>
                                            <?= h($header) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <?php if ($autoMapping['location']): ?>
                                    <span style="color: #388e3c;">✓ Auto-detected</span>
                                <?php else: ?>
                                    <span style="color: #f57c00;">! Select column</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        
                        <tr>
                            <td><strong>Team A</strong> <span style="color: #d32f2f;">*</span></td>
                            <td>
                                <select name="mapping[team_a]" required style="width: 100%; padding: 6px; border: 1px solid #ddd; border-radius: 4px;">
                                    <option value="">-- Select Column --</option>
                                    <?php foreach ($parser->getHeaders() as $header): ?>
                                        <option value="<?= h($header) ?>" <?= ($autoMapping['team_a'] === $header) ? 'selected' : '' ?>>
                                            <?= h($header) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <?php if ($autoMapping['team_a']): ?>
                                    <span style="color: #388e3c;">✓ Auto-detected</span>
                                <?php else: ?>
                                    <span style="color: #f57c00;">! Select column</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        
                        <tr>
                            <td><strong>Team B</strong> <span style="color: #d32f2f;">*</span></td>
                            <td>
                                <select name="mapping[team_b]" required style="width: 100%; padding: 6px; border: 1px solid #ddd; border-radius: 4px;">
                                    <option value="">-- Select Column --</option>
                                    <?php foreach ($parser->getHeaders() as $header): ?>
                                        <option value="<?= h($header) ?>" <?= ($autoMapping['team_b'] === $header) ? 'selected' : '' ?>>
                                            <?= h($header) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <?php if ($autoMapping['team_b']): ?>
                                    <span style="color: #388e3c;">✓ Auto-detected</span>
                                <?php else: ?>
                                    <span style="color: #f57c00;">! Select column</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        
                        <tr style="background: #f9f9f9;">
                            <td><strong>Day</strong> <span class="small" style="color: #666;">(optional)</span></td>
                            <td>
                                <select name="mapping[day]" style="width: 100%; padding: 6px; border: 1px solid #ddd; border-radius: 4px;">
                                    <option value="">-- Skip / Calculate from Date --</option>
                                    <?php foreach ($parser->getHeaders() as $header): ?>
                                        <option value="<?= h($header) ?>" <?= ($autoMapping['day'] === $header) ? 'selected' : '' ?>>
                                            <?= h($header) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <?php if ($autoMapping['day']): ?>
                                    <span style="color: #388e3c;">✓ Auto-detected</span>
                                <?php else: ?>
                                    <span style="color: #666;">Optional</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        
                        <tr style="background: #f9f9f9;">
                            <td><strong>Time</strong> <span class="small" style="color: #666;">(optional)</span></td>
                            <td>
                                <select name="mapping[time]" style="width: 100%; padding: 6px; border: 1px solid #ddd; border-radius: 4px;">
                                    <option value="">-- Skip --</option>
                                    <?php foreach ($parser->getHeaders() as $header): ?>
                                        <option value="<?= h($header) ?>" <?= ($autoMapping['time'] === $header) ? 'selected' : '' ?>>
                                            <?= h($header) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <?php if ($autoMapping['time']): ?>
                                    <span style="color: #388e3c;">✓ Auto-detected</span>
                                <?php else: ?>
                                    <span style="color: #666;">Optional</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <p class="small" style="margin-top: 16px; color: #666;">
                <span style="color: #d32f2f;">*</span> Required fields
            </p>
        </div>
        
        <?php if (!empty($preview)): ?>
        <div class="card" style="margin-bottom: 24px;">
            <h3>Data Preview</h3>
            <p class="small">First 3 rows from your CSV file:</p>
            
            <div style="overflow-x: auto; margin-top: 12px;">
                <table class="list">
                    <thead>
                        <tr>
                            <?php foreach ($parser->getHeaders() as $header): ?>
                                <th><?= h($header) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($preview as $row): ?>
                            <tr>
                                <?php foreach ($parser->getHeaders() as $header): ?>
                                    <td><?= h($row[$header] ?? '') ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <div style="margin-top: 20px;">
            <button type="submit" class="button primary">Visualize Schedule</button>
            <a href="/visualize_schedule.php" class="button">Cancel</a>
        </div>
    </form>
    
<?php endif; ?>

<?php footer_html(); ?>
