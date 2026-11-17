<?php
// ============================================================
// delete_all_products.php — AJAX: authenticate then delete all
// Admin-only. Requires valid username + password in request body.
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_login();

header('Content-Type: application/json');

// Only admins may access this endpoint at all
if (!is_admin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Admin access required.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$body     = json_decode(file_get_contents('php://input'), true);
$username = trim($body['username'] ?? '');
$password = $body['password'] ?? '';

if ($username === '' || $password === '') {
    echo json_encode(['success' => false, 'message' => 'Username and password are required.']);
    exit;
}

// ── Verify credentials against DB ─────────────────────────
$stmt = $conn->prepare("SELECT id, password, role FROM users WHERE username = ? LIMIT 1");
$stmt->bind_param('s', $username);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    // Consistent timing to prevent username enumeration
    password_verify('dummy', '$2y$10$dummyhashtopreventtimingattacks00000000000000000000000');
    echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
    exit;
}

if (!password_verify($password, $user['password'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
    exit;
}

// Must be an admin account (double-check the authenticated user's role)
if ($user['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Only admin accounts can delete all products.']);
    exit;
}

// ── Delete all products ────────────────────────────────────
// Get count first so we can report it
$count_result = $conn->query("SELECT COUNT(*) AS c FROM products");
$deleted_count = (int)$count_result->fetch_assoc()['c'];

if ($deleted_count === 0) {
    echo json_encode(['success' => false, 'message' => 'No products to delete.']);
    exit;
}

// Disable foreign key checks temporarily so products referenced
// in existing sale_items (via product_id) can be deleted without
// violating the FK constraint. sale_items rows are kept intact —
// they store product_name/price snapshots so history is preserved.
$conn->query("SET FOREIGN_KEY_CHECKS = 0");
$conn->query("DELETE FROM products");
$conn->query("SET FOREIGN_KEY_CHECKS = 1");

if ($conn->affected_rows >= 0) {
    echo json_encode([
        'success' => true,
        'deleted' => $deleted_count,
        'message' => "All $deleted_count product(s) deleted successfully."
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
}
