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
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$date = trim($_POST['date'] ?? '');
$modifier = trim($_POST['modifier'] ?? '');

// Validate ID
if ($id <= 0) {
    header('Location: /timeslots/?err=' . urlencode('Invalid timeslot ID.'));
    exit;
}

// Validation
$errors = [];
if ($date === '') {
    $errors[] = 'Date is required.';
}

if (!empty($errors)) {
    // Redirect back to form with errors and form data
    $params = [
        'id' => $id,
        'err' => implode(' ', $errors),
        'date' => $date,
        'modifier' => $modifier
    ];
    $query = http_build_query($params);
    header('Location: /timeslots/edit.php?' . $query);
    exit;
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    TimeslotManagement::updateTimeslot($ctx, $id, $date, $modifier);
    
    // Success - redirect to timeslots list with success message
    header('Location: /timeslots/?msg=' . urlencode('Your timeslot has been updated.'));
    exit;
    
} catch (Exception $e) {
    // Error updating timeslot - redirect back to form
    $params = [
        'id' => $id,
        'err' => $e->getMessage(),
        'date' => $date,
        'modifier' => $modifier
    ];
    $query = http_build_query($params);
    header('Location: /timeslots/edit.php?' . $query);
    exit;
}
