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
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$name = trim($_POST['name'] ?? '');
$division_id = isset($_POST['division_id']) ? (int)$_POST['division_id'] : 0;
$description = trim($_POST['description'] ?? '');
$previousYearRanking = isset($_POST['previous_year_ranking']) && trim($_POST['previous_year_ranking']) !== '' 
    ? (int)$_POST['previous_year_ranking'] 
    : null;

// Validate ID
if ($id <= 0) {
    header('Location: /teams/?err=' . urlencode('Invalid team ID.'));
    exit;
}

// Validation
$errors = [];
if ($name === '') {
    $errors[] = 'Team name is required.';
}
if ($division_id <= 0) {
    $errors[] = 'Division is required.';
}
if ($previousYearRanking !== null && $previousYearRanking <= 0) {
    $errors[] = 'Previous year ranking must be a positive integer.';
}

if (!empty($errors)) {
    // Redirect back to form with errors and form data
    $params = [
        'id' => $id,
        'err' => implode(' ', $errors),
        'name' => $name,
        'division_id' => $division_id,
        'description' => $description,
        'previous_year_ranking' => $previousYearRanking
    ];
    $query = http_build_query($params);
    header('Location: /teams/edit.php?' . $query);
    exit;
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    TeamManagement::updateTeam($ctx, $id, $division_id, $name, $description, $previousYearRanking);
    
    // Success - redirect to teams list with success message
    header('Location: /teams/?msg=' . urlencode('Your team has been updated.'));
    exit;
    
} catch (Exception $e) {
    // Error updating team - redirect back to form
    $params = [
        'id' => $id,
        'err' => $e->getMessage(),
        'name' => $name,
        'division_id' => $division_id,
        'description' => $description,
        'previous_year_ranking' => $previousYearRanking
    ];
    $query = http_build_query($params);
    header('Location: /teams/edit.php?' . $query);
    exit;
}
