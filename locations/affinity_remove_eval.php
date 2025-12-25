<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LocationManagement.php';
Application::init();
require_login();

// Get parameters from URL
$locationId = isset($_GET['location_id']) ? (int)$_GET['location_id'] : 0;
$divisionId = isset($_GET['division_id']) ? (int)$_GET['division_id'] : 0;

// Validate IDs
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
    $ctx = UserContext::getLoggedInUserContext();
    LocationManagement::removeAffinity($ctx, $locationId, $divisionId);
    
    // Success - redirect back to edit page
    header('Location: /locations/edit.php?id=' . $locationId . '&msg=' . urlencode('Division affinity has been removed.'));
    exit;
    
} catch (Exception $e) {
    // Error - redirect back to edit page
    header('Location: /locations/edit.php?id=' . $locationId . '&err=' . urlencode($e->getMessage()));
    exit;
}
