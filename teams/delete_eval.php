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
    $success = TeamManagement::deleteTeam($ctx, $id);
    
    if ($success) {
        // Success - redirect to teams list with success message
        header('Location: /teams/?msg=' . urlencode('Team has been deleted.'));
        exit;
    } else {
        // Failed to delete
        header('Location: /teams/?err=' . urlencode('Failed to delete team.'));
        exit;
    }
    
} catch (Exception $e) {
    // Error deleting team - redirect back to edit page
    header('Location: /teams/edit.php?id=' . $id . '&err=' . urlencode($e->getMessage()));
    exit;
}
