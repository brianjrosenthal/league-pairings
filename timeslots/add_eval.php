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

// Get form data
$date = trim($_POST['date'] ?? '');
$modifier = trim($_POST['modifier'] ?? '');

// Validation
$errors = [];
if ($date === '') {
    $errors[] = 'Date is required.';
}

if (!empty($errors)) {
    // Redirect back to form with errors and form data
    $params = [
        'err' => implode(' ', $errors),
        'date' => $date,
        'modifier' => $modifier
    ];
    $query = http_build_query($params);
    header('Location: /timeslots/add.php?' . $query);
    exit;
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    $timeslotId = TimeslotManagement::createTimeslot($ctx, $date, $modifier);
    
    // Success - redirect to timeslots list with success message
    header('Location: /timeslots/?msg=' . urlencode('Your timeslot has been added.'));
    exit;
    
} catch (Exception $e) {
    // Error creating timeslot - redirect back to form
    $params = [
        'err' => $e->getMessage(),
        'date' => $date,
        'modifier' => $modifier
    ];
    $query = http_build_query($params);
    header('Location: /timeslots/add.php?' . $query);
    exit;
}
