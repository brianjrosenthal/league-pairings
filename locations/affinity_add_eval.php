<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LocationManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /locations/');
    exit;
}

require_csrf();

// Get form data
$locationId = isset($_POST['location_id']) ? (int)$_POST['location_id'] : 0;
$divisionId = isset($_POST['division_id']) ? (int)$_POST['division_id'] : 0;

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
    LocationManagement::addAffinity($ctx, $locationId, $divisionId);
    
    // Success - redirect back to edit page
    header('Location: /locations/edit.php?id=' . $locationId . '&msg=' . urlencode('Division affinity has been added.'));
    exit;
    
} catch (Exception $e) {
    // Error - redirect back to edit page
    header('Location: /locations/edit.php?id=' . $locationId . '&err=' . urlencode($e->getMessage()));
    exit;
}
