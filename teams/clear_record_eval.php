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

// Get team ID
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

// Validate ID
if ($id <= 0) {
    header('Location: /teams/?err=' . urlencode('Invalid team ID.'));
    exit;
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    TeamManagement::clearTeamRecord($ctx, $id);
    
    // Success - redirect to teams list with success message
    header('Location: /teams/?msg=' . urlencode('Team record has been cleared.'));
    exit;
    
} catch (Exception $e) {
    // Error clearing record - redirect back to edit record page
    header('Location: /teams/edit_record.php?id=' . $id . '&err=' . urlencode($e->getMessage()));
    exit;
}
