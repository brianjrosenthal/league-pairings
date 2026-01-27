<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LocationManagement.php';
Application::init();
require_login();

$me = current_user();

// Check CSRF
if (!verify_csrf($_POST['csrf'] ?? '')) {
    header('Location: /locations/?err=' . urlencode('Invalid request (CSRF check failed).'));
    exit;
}

// Get POST data
$locationId = isset($_POST['location_id']) ? (int)$_POST['location_id'] : 0;
$divisionId = isset($_POST['division_id']) ? (int)$_POST['division_id'] : 0;

if ($locationId <= 0 || $divisionId <= 0) {
    header('Location: /locations/?err=' . urlencode('Invalid location or division ID.'));
    exit;
}

// Verify location exists
$location = LocationManagement::findById($locationId);
if (!$location) {
    header('Location: /locations/?err=' . urlencode('Location not found.'));
    exit;
}

try {
    // Add the holdout
    LocationManagement::addHoldout($me, $locationId, $divisionId);
    
    header('Location: /locations/edit.php?id=' . $locationId . '&msg=' . urlencode('Division holdout added successfully.'));
    exit;
} catch (Exception $e) {
    header('Location: /locations/edit.php?id=' . $locationId . '&err=' . urlencode($e->getMessage()));
    exit;
}
