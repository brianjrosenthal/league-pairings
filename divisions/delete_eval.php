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

// Get division ID
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

// Validate ID
if ($id <= 0) {
    header('Location: /divisions/?err=' . urlencode('Invalid division ID.'));
    exit;
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    $success = DivisionManagement::deleteDivision($ctx, $id);
    
    if ($success) {
        // Success - redirect to divisions list with success message
        header('Location: /divisions/?msg=' . urlencode('Division has been deleted.'));
        exit;
    } else {
        // Failed to delete
        header('Location: /divisions/?err=' . urlencode('Failed to delete division.'));
        exit;
    }
    
} catch (Exception $e) {
    // Error deleting division - redirect back to edit page
    header('Location: /divisions/edit.php?id=' . $id . '&err=' . urlencode($e->getMessage()));
    exit;
}
