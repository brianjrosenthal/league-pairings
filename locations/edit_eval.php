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
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$name = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');

// Validate ID
if ($id <= 0) {
    header('Location: /locations/?err=' . urlencode('Invalid location ID.'));
    exit;
}

// Validation
$errors = [];
if ($name === '') {
    $errors[] = 'Location name is required.';
}

if (!empty($errors)) {
    // Redirect back to form with errors and form data
    $params = [
        'id' => $id,
        'err' => implode(' ', $errors),
        'name' => $name,
        'description' => $description
    ];
    $query = http_build_query($params);
    header('Location: /locations/edit.php?' . $query);
    exit;
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    LocationManagement::updateLocation($ctx, $id, $name, $description);
    
    // Success - redirect back to edit page with success message
    header('Location: /locations/edit.php?id=' . $id . '&msg=' . urlencode('Your location has been updated.'));
    exit;
    
} catch (Exception $e) {
    // Error updating location - redirect back to form
    $params = [
        'id' => $id,
        'err' => $e->getMessage(),
        'name' => $name,
        'description' => $description
    ];
    $query = http_build_query($params);
    header('Location: /locations/edit.php?' . $query);
    exit;
}
