<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/TeamAvailabilityManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /teams/');
    exit;
}

require_csrf();

// Get form data
$teamId = isset($_POST['team_id']) ? (int)$_POST['team_id'] : 0;
$timeslotId = isset($_POST['timeslot_id']) ? (int)$_POST['timeslot_id'] : 0;

// Validate IDs
if ($teamId <= 0 || $timeslotId <= 0) {
    header('Location: /teams/?err=' . urlencode('Invalid team or timeslot.'));
    exit;
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    TeamAvailabilityManagement::addAvailability($ctx, $teamId, $timeslotId);
    
    // Success - redirect back to availability page
    header('Location: /teams/availability.php?id=' . $teamId . '&msg=' . urlencode('Your availability has been added.'));
    exit;
    
} catch (Exception $e) {
    // Error - redirect back to add page
    header('Location: /teams/availability_add.php?team_id=' . $teamId . '&err=' . urlencode($e->getMessage()));
    exit;
}
