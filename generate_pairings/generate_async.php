<?php
set_time_limit(120);

require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/SchedulingManagement.php';
Application::init();
require_login();

$me = current_user();

// Get parameters
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$algorithm = $_GET['algorithm'] ?? 'greedy';
$jobId = $_GET['job_id'] ?? '';

// Validate dates
if (empty($startDate) || empty($endDate)) {
    header('Location: /generate_pairings/step1.php?err=' . urlencode('Please provide both start and end dates.'));
    exit;
}

// If no job ID, start a new job
if (empty($jobId)) {
    try {
        $jobId = SchedulingManagement::startAsyncScheduler($startDate, $endDate, $algorithm);
        header('Location: /generate_pairings/generate_async.php?start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate) . '&algorithm=' . urlencode($algorithm) . '&job_id=' . urlencode($jobId));
        exit;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
        $isCapacityError = strpos($error, 'processing') !== false || strpos($error, 'capacity') !== false;
    }
}

header_html('Generating Schedule');
?>

<h2>Generating Game Pairings</h2>

<div style="margin-bottom: 16px;">
    <a href="/generate_pairings/step2.php?start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&algorithm=<?= urlencode($algorithm) ?>" class="button">← Back to Review</a>
</div>

<?php if (isset($error)): ?>
    <?php if (isset($isCapacityError) && $isCapacityError): ?>
        <div class="announcement" style="margin-bottom: 24px;">
            <h3 style="margin-top: 0;">⏳ System Busy</h3>
            <p><?= h($error) ?></p>
            <p class="small" style="margin-top: 12px;">
                The system can process up to 2 schedules at once. Other users are currently generating schedules.
                Please wait a moment and try again.
            </p>
        </div>
        
        <div class="card">
            <div class="actions">
                <a href="/generate_pairings/generate_async.php?start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&algorithm=<?= urlencode($algorithm) ?>" 
                   class="button primary">
                    🔄 Try Again
                </a>
                <a href="/generate_pairings/step2.php?start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&algorithm=<?= urlencode($algorithm) ?>" 
                   class="button">
                    ← Back to Review
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="error" style="margin-bottom: 24px;">
            <strong>Error:</strong> <?= h($error) ?>
        </div>
        
        <div class="card">
            <h3>Troubleshooting</h3>
            <ul>
                <li>Ensure the Python scheduling service is running: <code>cd service && python server.py</code></li>
                <li>Verify the service is accessible at <code>http://localhost:5001</code></li>
            </ul>
        </div>
    <?php endif; ?>
<?php else: ?>
    
    <div class="card" style="margin-bottom: 24px;">
        <h3>Schedule Generation in Progress</h3>
        
        <div id="progress-container" style="margin: 24px 0;">
            <div style="background: #f5f5f5; border-radius: 8px; padding: 16px; margin-bottom: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <strong id="current-step">Initializing...</strong>
                    <span id="progress-percentage">0%</span>
                </div>
                <div style="background: #e0e0e0; height: 24px; border-radius: 12px; overflow: hidden;">
                    <div id="progress-bar" style="background: linear-gradient(90deg, #4CAF50, #45a049); height: 100%; width: 0%; transition: width 0.3s ease;"></div>
                </div>
            </div>
            
            <div id="status-message" style="text-align: center; color: #666;">
                <p>Please wait while we generate your optimal schedule...</p>
                <p class="small">This may take up to a minute for complex schedules.</p>
            </div>
        </div>
        
        <div id="error-container" style="display: none;">
            <div class="error">
                <strong>Error:</strong> <span id="error-message"></span>
            </div>
        </div>
    </div>
    
    <div id="result-container" style="display: none;">
        <!-- Results will be inserted here -->
    </div>

<?php endif; ?>

<script>
const jobId = <?= json_encode($jobId) ?>;
const startDate = <?= json_encode($startDate) ?>;
const endDate = <?= json_encode($endDate) ?>;
const algorithm = <?= json_encode($algorithm) ?>;

let pollInterval;
let pollCount = 0;
const maxPolls = 120; // 2 minutes at 1 second intervals

function updateProgress(status, progress) {
    const currentStep = progress?.current_step || 'Processing...';
    const completedSteps = progress?.completed_steps || 0;
    const totalSteps = progress?.total_steps || 5;
    const percentage = Math.round((completedSteps / totalSteps) * 100);
    
    document.getElementById('current-step').textContent = currentStep;
    document.getElementById('progress-percentage').textContent = percentage + '%';
    document.getElementById('progress-bar').style.width = percentage + '%';
}

function showError(message) {
    document.getElementById('progress-container').style.display = 'none';
    document.getElementById('error-container').style.display = 'block';
    document.getElementById('error-message').textContent = message;
    clearInterval(pollInterval);
}

function displayResults(result) {
    // Redirect to the result page with job_id
    window.location.href = '/generate_pairings/generate_result.php?job_id=' + encodeURIComponent(jobId);
}

function checkStatus() {
    pollCount++;
    
    if (pollCount > maxPolls) {
        showError('Job timed out. Please try again with a smaller date range.');
        return;
    }
    
    fetch('/generate_pairings/job_status.php?job_id=' + encodeURIComponent(jobId))
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                showError(data.error);
                return;
            }
            
            const status = data.status;
            
            if (status === 'completed') {
                updateProgress(status, { current_step: 'Complete!', completed_steps: 5, total_steps: 5 });
                setTimeout(() => displayResults(data), 500);
                clearInterval(pollInterval);
            } else if (status === 'failed') {
                showError(data.error || 'Job failed. Please try again.');
                clearInterval(pollInterval);
            } else if (status === 'running' || status === 'queued') {
                updateProgress(status, data.progress);
            }
        })
        .catch(error => {
            console.error('Error checking status:', error);
            // Don't show error on network issues, keep polling
        });
}

// Start polling immediately
checkStatus();
pollInterval = setInterval(checkStatus, 1000); // Poll every second
</script>

<?php footer_html(); ?>
