<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/DivisionManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /divisions/');
    exit;
}

require_csrf();

// Get form data
$name = trim($_POST['name'] ?? '');

// Validation
$errors = [];
if ($name === '') {
    $errors[] = 'Division name is required.';
}

if (!empty($errors)) {
    // Redirect back to form with errors and form data
    $params = [
        'err' => implode(' ', $errors),
        'name' => $name
    ];
    $query = http_build_query($params);
    header('Location: /divisions/add.php?' . $query);
    exit;
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    $divisionId = DivisionManagement::createDivision($ctx, $name);
    
    // Success - redirect to divisions list with success message
    header('Location: /divisions/?msg=' . urlencode('Your division has been added.'));
    exit;
    
} catch (Exception $e) {
    // Error creating division - redirect back to form
    $params = [
        'err' => $e->getMessage(),
        'name' => $name
    ];
    $query = http_build_query($params);
    header('Location: /divisions/add.php?' . $query);
    exit;
}
