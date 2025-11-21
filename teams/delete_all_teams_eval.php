<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/TeamManagement.php';
Application::init();
require_login();

$me = current_user();

// Only admins can delete all teams
if (!$me['is_admin']) {
    header('Location: /teams/?err=' . urlencode('Admin privileges required.'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /teams/');
    exit;
}

require_csrf();

try {
    $ctx = UserContext::getLoggedInUserContext();
    $count = TeamManagement::deleteAllTeams($ctx);
    
    header('Location: /teams/?msg=' . urlencode("Successfully deleted {$count} team(s) and all related data."));
    exit;
    
} catch (Exception $e) {
    header('Location: /teams/?err=' . urlencode($e->getMessage()));
    exit;
}
