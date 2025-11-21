<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/TimeslotManagement.php';
Application::init();
require_login();

$me = current_user();

// Only admins can delete all timeslots
if (!$me['is_admin']) {
    header('Location: /timeslots/?err=' . urlencode('Admin privileges required.'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /timeslots/');
    exit;
}

require_csrf();

try {
    $ctx = UserContext::getLoggedInUserContext();
    $count = TimeslotManagement::deleteAllTimeslots($ctx);
    
    header('Location: /timeslots/?msg=' . urlencode("Successfully deleted {$count} timeslot(s) and all related data."));
    exit;
    
} catch (Exception $e) {
    header('Location: /timeslots/?err=' . urlencode($e->getMessage()));
    exit;
}
