<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LocationManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /locations/');
    exit;
}

require_csrf();

// Get form data
$name = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');

// Validation
$errors = [];
if ($name === '') {
    $errors[] = 'Location name is required.';
}

if (!empty($errors)) {
    // Redirect back to form with errors and form data
    $params = [
        'err' => implode(' ', $errors),
        'name' => $name,
        'description' => $description
    ];
    $query = http_build_query($params);
    header('Location: /locations/add.php?' . $query);
    exit;
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    $locationId = LocationManagement::createLocation($ctx, $name, $description);
    
    // Success - redirect to locations list with success message
    header('Location: /locations/?msg=' . urlencode('Your location has been added.'));
    exit;
    
} catch (Exception $e) {
    // Error creating location - redirect back to form
    $params = [
        'err' => $e->getMessage(),
        'name' => $name,
        'description' => $description
    ];
    $query = http_build_query($params);
    header('Location: /locations/add.php?' . $query);
    exit;
}
