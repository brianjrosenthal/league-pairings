<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LocationAvailabilityManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /locations/');
    exit;
}

require_csrf();

// Get form data
$locationId = isset($_POST['location_id']) ? (int)$_POST['location_id'] : 0;
$timeslotId = isset($_POST['timeslot_id']) ? (int)$_POST['timeslot_id'] : 0;

// Validate IDs
if ($locationId <= 0 || $timeslotId <= 0) {
    header('Location: /locations/?err=' . urlencode('Invalid location or timeslot.'));
    exit;
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    LocationAvailabilityManagement::addAvailability($ctx, $locationId, $timeslotId);
    
    // Success - redirect back to availability page
    header('Location: /locations/availability.php?id=' . $locationId . '&msg=' . urlencode('Availability has been added.'));
    exit;
    
} catch (Exception $e) {
    // Error - redirect back to add page
    header('Location: /locations/availability_add.php?location_id=' . $locationId . '&err=' . urlencode($e->getMessage()));
    exit;
}
