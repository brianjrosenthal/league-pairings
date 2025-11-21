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
$gamesWon = isset($_POST['games_won']) ? (int)$_POST['games_won'] : 0;
$gamesLost = isset($_POST['games_lost']) ? (int)$_POST['games_lost'] : 0;

// Validate ID
if ($id <= 0) {
    header('Location: /teams/?err=' . urlencode('Invalid team ID.'));
    exit;
}

// Validation
$errors = [];
if ($gamesWon < 0) {
    $errors[] = 'Games won must be a non-negative integer.';
}
if ($gamesLost < 0) {
    $errors[] = 'Games lost must be a non-negative integer.';
}

if (!empty($errors)) {
    // Redirect back to form with errors and form data
    $params = [
        'id' => $id,
        'err' => implode(' ', $errors),
        'games_won' => $gamesWon,
        'games_lost' => $gamesLost
    ];
    $query = http_build_query($params);
    header('Location: /teams/edit_record.php?' . $query);
    exit;
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    TeamManagement::updateTeamRecord($ctx, $id, $gamesWon, $gamesLost);
    
    // Success - redirect to teams list with success message
    header('Location: /teams/?msg=' . urlencode('Team record has been updated.'));
    exit;
    
} catch (Exception $e) {
    // Error updating record - redirect back to form
    $params = [
        'id' => $id,
        'err' => $e->getMessage(),
        'games_won' => $gamesWon,
        'games_lost' => $gamesLost
    ];
    $query = http_build_query($params);
    header('Location: /teams/edit_record.php?' . $query);
    exit;
}
