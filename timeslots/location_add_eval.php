<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LocationAvailabilityManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /timeslots/');
    exit;
}

require_csrf();

// Get form data
$timeslotId = isset($_POST['timeslot_id']) ? (int)$_POST['timeslot_id'] : 0;
$locationId = isset($_POST['location_id']) ? (int)$_POST['location_id'] : 0;

// Validate IDs
if ($locationId <= 0 || $timeslotId <= 0) {
    header('Location: /timeslots/?err=' . urlencode('Invalid location or timeslot.'));
    exit;
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    LocationAvailabilityManagement::addAvailability($ctx, $locationId, $timeslotId);
    
    // Success - redirect back to locations page for this timeslot
    header('Location: /timeslots/locations.php?id=' . $timeslotId . '&msg=' . urlencode('Location has been added.'));
    exit;
    
} catch (Exception $e) {
    // Error - redirect back to add page
    header('Location: /timeslots/location_add.php?timeslot_id=' . $timeslotId . '&err=' . urlencode($e->getMessage()));
    exit;
}
