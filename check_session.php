<?php
// ============================================================
// check_session.php — AJAX session validity endpoint
// Called periodically by pos.php to detect expiry.
// Uses the same session variables as auth.php (user_id,
// last_active, SESSION_TIMEOUT) so no new auth logic is added.
// ============================================================
require_once 'includes/auth.php'; // starts session, defines SESSION_TIMEOUT

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Not logged in at all
if (empty($_SESSION['user_id'])) {
    echo json_encode(['expired' => true, 'reason' => 'not_authenticated']);
    exit;
}

// Session idle timeout check (mirrors require_login() in auth.php)
if (!empty($_SESSION['last_active']) &&
    (time() - $_SESSION['last_active']) > SESSION_TIMEOUT) {
    // Destroy the session so subsequent page actions also redirect
    session_unset();
    session_destroy();
    echo json_encode(['expired' => true, 'reason' => 'timeout']);
    exit;
}

// Session is valid — refresh last_active timestamp
$_SESSION['last_active'] = time();

echo json_encode(['expired' => false]);
