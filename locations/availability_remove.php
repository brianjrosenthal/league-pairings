<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LocationAvailabilityManagement.php';
Application::init();
require_login();

// Get IDs from query string
$locationId = isset($_GET['location_id']) ? (int)$_GET['location_id'] : 0;
$timeslotId = isset($_GET['timeslot_id']) ? (int)$_GET['timeslot_id'] : 0;

// Validate IDs
if ($locationId <= 0 || $timeslotId <= 0) {
    header('Location: /locations/?err=' . urlencode('Invalid location or timeslot.'));
    exit;
}

// Determine redirect location based on where we came from
$fromTimeslot = isset($_GET['from']) && $_GET['from'] === 'timeslot';

try {
    $ctx = UserContext::getLoggedInUserContext();
    LocationAvailabilityManagement::removeAvailability($ctx, $locationId, $timeslotId);
    
    // Success - redirect based on source
    if ($fromTimeslot) {
        header('Location: /timeslots/locations.php?id=' . $timeslotId . '&msg=' . urlencode('Availability has been removed.'));
    } else {
        header('Location: /locations/availability.php?id=' . $locationId . '&msg=' . urlencode('Availability has been removed.'));
    }
    exit;
    
} catch (Exception $e) {
    // Error - redirect based on source
    if ($fromTimeslot) {
        header('Location: /timeslots/locations.php?id=' . $timeslotId . '&err=' . urlencode($e->getMessage()));
    } else {
        header('Location: /locations/availability.php?id=' . $locationId . '&err=' . urlencode($e->getMessage()));
    }
    exit;
}
