<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/TimeslotManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /timeslots/');
    exit;
}

require_csrf();

// Get timeslot ID
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

// Validate ID
if ($id <= 0) {
    header('Location: /timeslots/?err=' . urlencode('Invalid timeslot ID.'));
    exit;
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    $success = TimeslotManagement::deleteTimeslot($ctx, $id);
    
    if ($success) {
        // Success - redirect to timeslots list with success message
        header('Location: /timeslots/?msg=' . urlencode('Timeslot has been deleted.'));
        exit;
    } else {
        // Failed to delete
        header('Location: /timeslots/?err=' . urlencode('Failed to delete timeslot.'));
        exit;
    }
    
} catch (Exception $e) {
    // Error deleting timeslot - redirect back to edit page
    header('Location: /timeslots/edit.php?id=' . $id . '&err=' . urlencode($e->getMessage()));
    exit;
}
