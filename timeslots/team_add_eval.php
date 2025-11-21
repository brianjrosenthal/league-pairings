<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/TeamAvailabilityManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /timeslots/');
    exit;
}

require_csrf();

// Get form data
$timeslotId = isset($_POST['timeslot_id']) ? (int)$_POST['timeslot_id'] : 0;
$teamId = isset($_POST['team_id']) ? (int)$_POST['team_id'] : 0;

// Validate IDs
if ($teamId <= 0 || $timeslotId <= 0) {
    header('Location: /timeslots/?err=' . urlencode('Invalid team or timeslot.'));
    exit;
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    TeamAvailabilityManagement::addAvailability($ctx, $teamId, $timeslotId);
    
    // Success - redirect back to teams page for this timeslot
    header('Location: /timeslots/teams.php?id=' . $timeslotId . '&msg=' . urlencode('Team has been added.'));
    exit;
    
} catch (Exception $e) {
    // Error - redirect back to add page
    header('Location: /timeslots/team_add.php?timeslot_id=' . $timeslotId . '&err=' . urlencode($e->getMessage()));
    exit;
}
