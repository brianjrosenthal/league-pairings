<?php
require_once __DIR__ . '/partials.php';
Application::init();
require_login();

header_html('Visualize Schedule from CSV');
?>

<h2>Visualize Schedule from CSV</h2>

<div style="margin-bottom: 16px;">
    <a href="/index.php" class="button">← Back to Home</a>
</div>

<div class="card" style="max-width: 800px;">
    <h3>Upload CSV File</h3>
    <p>Upload a CSV file containing your schedule data to visualize it.</p>
    
    <form method="POST" action="/visualize_schedule_step2.php" enctype="multipart/form-data" style="margin-top: 20px;">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        
        <div class="form-group">
            <label for="csv_file">CSV File *</label>
            <input type="file" 
                   id="csv_file" 
                   name="csv_file" 
                   accept=".csv,text/csv" 
                   required 
                   style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
            <p class="small" style="margin-top: 4px; color: #666;">
                Maximum file size: 5MB
            </p>
        </div>
        
        <div style="margin-top: 20px;">
            <button type="submit" class="button primary">Upload and Map Columns</button>
        </div>
    </form>
</div>

<div class="card" style="max-width: 800px; margin-top: 24px;">
    <h3>CSV Format</h3>
    <p>Your CSV file should contain the following information:</p>
    
    <h4 style="margin-top: 16px;">Required Columns:</h4>
    <ul>
        <li><strong>Date</strong> - Game date (various formats supported: MM/DD/YYYY, YYYY-MM-DD, etc.)</li>
        <li><strong>Division</strong> - Division or league name</li>
        <li><strong>Location</strong> - Venue, field, or court name</li>
        <li><strong>Team A</strong> - First team (may be labeled Home, Team 1, etc.)</li>
        <li><strong>Team B</strong> - Second team (may be labeled Away, Team 2, etc.)</li>
    </ul>
    
    <h4 style="margin-top: 16px;">Optional Columns:</h4>
    <ul>
        <li><strong>Day</strong> - Day of week (will be calculated from date if not provided)</li>
        <li><strong>Time</strong> - Time of day or time modifier (e.g., "7:00 PM")</li>
    </ul>
    
    <h4 style="margin-top: 16px;">Example CSV:</h4>
    <pre style="background: #f5f5f5; padding: 12px; border-radius: 4px; overflow-x: auto; font-size: 0.9em;">Date,Day,Time,Division,Location,Team A,Team B
12/02/2025,Tuesday,7:00 PM,Division A,Field 1,Eagles,Hawks
12/02/2025,Tuesday,8:30 PM,Division B,Field 2,Lions,Tigers
12/03/2025,Wednesday,7:00 PM,Division A,Field 1,Bears,Wolves</pre>
    
    <p class="small" style="margin-top: 12px; color: #666;">
        <strong>Note:</strong> Column names don't need to match exactly - you'll be able to map them in the next step.
    </p>
</div>

<?php footer_html(); ?>
