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

// Get form data
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$teamId = isset($_POST['team_id']) ? (int)$_POST['team_id'] : 0;
$date = trim($_POST['date'] ?? '');
$team1Id = isset($_POST['team_1_id']) ? (int)$_POST['team_1_id'] : 0;
$team2Id = isset($_POST['team_2_id']) ? (int)$_POST['team_2_id'] : 0;
$team1Score = isset($_POST['team_1_score']) ? (int)$_POST['team_1_score'] : 0;
$team2Score = isset($_POST['team_2_score']) ? (int)$_POST['team_2_score'] : 0;

// Validate IDs
if ($id <= 0 || $teamId <= 0) {
    header('Location: /teams/?err=' . urlencode('Invalid game or team ID.'));
    exit;
}

// Validation
$errors = [];
if ($date === '') {
    $errors[] = 'Date is required.';
}
if ($team1Id <= 0 || $team2Id <= 0) {
    $errors[] = 'Both teams are required.';
}
if ($team1Id === $team2Id) {
    $errors[] = 'A team cannot play against itself.';
}
if ($team1Score < 0 || $team2Score < 0) {
    $errors[] = 'Scores must be non-negative integers.';
}

if (!empty($errors)) {
    // Redirect back to form with errors and form data
    $params = [
        'id' => $id,
        'team_id' => $teamId,
        'err' => implode(' ', $errors),
        'date' => $date,
        'team_1_id' => $team1Id,
        'team_2_id' => $team2Id,
        'team_1_score' => $team1Score,
        'team_2_score' => $team2Score
    ];
    $query = http_build_query($params);
    header('Location: /teams/previous_game_edit.php?' . $query);
    exit;
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    PreviousGamesManagement::updateGame($ctx, $id, $date, $team1Id, $team2Id, $team1Score, $team2Score);
    
    // Success - redirect to previous games page
    header('Location: /teams/previous_games.php?id=' . $teamId . '&msg=' . urlencode('Game has been updated.'));
    exit;
    
} catch (Exception $e) {
    // Error - redirect back to form
    $params = [
        'id' => $id,
        'team_id' => $teamId,
        'err' => $e->getMessage(),
        'date' => $date,
        'team_1_id' => $team1Id,
        'team_2_id' => $team2Id,
        'team_1_score' => $team1Score,
        'team_2_score' => $team2Score
    ];
    $query = http_build_query($params);
    header('Location: /teams/previous_game_edit.php?' . $query);
    exit;
}
