<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/PreviousGamesManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /teams/');
    exit;
}

require_csrf();

// Get game ID and team ID
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$teamId = isset($_POST['team_id']) ? (int)$_POST['team_id'] : 0;

// Validate IDs
if ($id <= 0 || $teamId <= 0) {
    header('Location: /teams/?err=' . urlencode('Invalid game or team ID.'));
    exit;
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    PreviousGamesManagement::deleteGame($ctx, $id);
    
    // Success - redirect to previous games page
    header('Location: /teams/previous_games.php?id=' . $teamId . '&msg=' . urlencode('Game has been deleted.'));
    exit;
    
} catch (Exception $e) {
    // Error - redirect back to edit page
    header('Location: /teams/previous_game_edit.php?id=' . $id . '&team_id=' . $teamId . '&err=' . urlencode($e->getMessage()));
    exit;
}
