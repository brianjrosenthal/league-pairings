<?php
// download_csv.php - Handles CSV file downloads for generated schedules
require_once __DIR__ . '/../partials.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

require_csrf();

// Get CSV content from POST
$csvContent = $_POST['csv_content'] ?? '';
$filename = $_POST['filename'] ?? 'schedule.csv';

if (empty($csvContent)) {
    http_response_code(400);
    exit('No CSV content provided');
}

// Sanitize filename
$filename = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $filename);
if (!str_ends_with($filename, '.csv')) {
    $filename .= '.csv';
}

// Set headers for file download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($csvContent));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

// Output CSV content
echo $csvContent;
exit;
