<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/TeamAvailabilityManagement.php';
Application::init();
require_login();

// Get IDs from query string
$teamId = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
$timeslotId = isset($_GET['timeslot_id']) ? (int)$_GET['timeslot_id'] : 0;

// Validate IDs
if ($teamId <= 0 || $timeslotId <= 0) {
    header('Location: /teams/?err=' . urlencode('Invalid team or timeslot.'));
    exit;
}

// Determine redirect location based on where we came from
$fromTimeslot = isset($_GET['from']) && $_GET['from'] === 'timeslot';

try {
    $ctx = UserContext::getLoggedInUserContext();
    TeamAvailabilityManagement::removeAvailability($ctx, $teamId, $timeslotId);
    
    // Success - redirect based on source
    if ($fromTimeslot) {
        header('Location: /timeslots/teams.php?id=' . $timeslotId . '&msg=' . urlencode('Availability has been removed.'));
    } else {
        header('Location: /teams/availability.php?id=' . $teamId . '&msg=' . urlencode('Availability has been removed.'));
    }
    exit;
    
} catch (Exception $e) {
    // Error - redirect based on source
    if ($fromTimeslot) {
        header('Location: /timeslots/teams.php?id=' . $timeslotId . '&err=' . urlencode($e->getMessage()));
    } else {
        header('Location: /teams/availability.php?id=' . $teamId . '&err=' . urlencode($e->getMessage()));
    }
    exit;
}
