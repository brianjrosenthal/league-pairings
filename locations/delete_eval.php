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

// Get location ID
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

// Validate ID
if ($id <= 0) {
    header('Location: /locations/?err=' . urlencode('Invalid location ID.'));
    exit;
}

try {
    $ctx = UserContext::getLoggedInUserContext();
    $success = LocationManagement::deleteLocation($ctx, $id);
    
    if ($success) {
        // Success - redirect to locations list with success message
        header('Location: /locations/?msg=' . urlencode('Location has been deleted.'));
        exit;
    } else {
        // Failed to delete
        header('Location: /locations/?err=' . urlencode('Failed to delete location.'));
        exit;
    }
    
} catch (Exception $e) {
    // Error deleting location - redirect back to edit page
    header('Location: /locations/edit.php?id=' . $id . '&err=' . urlencode($e->getMessage()));
    exit;
}
