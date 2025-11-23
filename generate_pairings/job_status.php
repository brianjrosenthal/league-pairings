<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/SchedulingManagement.php';
Application::init();
require_login();

header('Content-Type: application/json');

$jobId = $_GET['job_id'] ?? '';

if (empty($jobId)) {
    echo json_encode(['error' => 'Job ID is required']);
    exit;
}

try {
    $status = SchedulingManagement::getJobStatus($jobId);
    echo json_encode($status);
} catch (RuntimeException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
