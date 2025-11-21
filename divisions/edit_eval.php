<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/DivisionManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /divisions.php');
    exit;
}

require_csrf();

// Get form data
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$name = trim($_POST['name'] ?? '');

// Validate ID
if ($id <= 0) {
    header('Location: /divisions.php?err=' . urlencode('Invalid division ID.'));
    exit;
}

// Validation
$errors = [];
if ($name === '') {
    $errors[] = 'Division name is required.';
}

if (!empty($errors)) {
    // Redirect back to form with errors and form data
    $params = [
        'id' => $id,
        'err' => implode(' ', $errors),
        'name' => $name
    ];
    $query = http_build_query($params);
    header('Location: /divisions/edit.php?' . $query);
    exit;
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    DivisionManagement::updateDivision($ctx, $id, $name);
    
    // Success - redirect to divisions list with success message
    header('Location: /divisions.php?msg=' . urlencode('Your division has been updated.'));
    exit;
    
} catch (Exception $e) {
    // Error updating division - redirect back to form
    $params = [
        'id' => $id,
        'err' => $e->getMessage(),
        'name' => $name
    ];
    $query = http_build_query($params);
    header('Location: /divisions/edit.php?' . $query);
    exit;
}
