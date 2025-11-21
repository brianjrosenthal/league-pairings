<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/TeamManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /teams/');
    exit;
}

require_csrf();

// Get form data
$name = trim($_POST['name'] ?? '');
$division_id = isset($_POST['division_id']) ? (int)$_POST['division_id'] : 0;
$description = trim($_POST['description'] ?? '');

// Validation
$errors = [];
if ($name === '') {
    $errors[] = 'Team name is required.';
}
if ($division_id <= 0) {
    $errors[] = 'Division is required.';
}

if (!empty($errors)) {
    // Redirect back to form with errors and form data
    $params = [
        'err' => implode(' ', $errors),
        'name' => $name,
        'division_id' => $division_id,
        'description' => $description
    ];
    $query = http_build_query($params);
    header('Location: /teams/add.php?' . $query);
    exit;
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    $teamId = TeamManagement::createTeam($ctx, $division_id, $name, $description);
    
    // Success - redirect to teams list with success message
    header('Location: /teams/?msg=' . urlencode('Your team has been added.'));
    exit;
    
} catch (Exception $e) {
    // Error creating team - redirect back to form
    $params = [
        'err' => $e->getMessage(),
        'name' => $name,
        'division_id' => $division_id,
        'description' => $description
    ];
    $query = http_build_query($params);
    header('Location: /teams/add.php?' . $query);
    exit;
}
