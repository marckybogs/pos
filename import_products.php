<?php
// ============================================================
// import_products.php — AJAX: bulk import from parsed Excel data
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$rows = json_decode(file_get_contents('php://input'), true);
if (!is_array($rows) || empty($rows)) {
    echo json_encode(['success' => false, 'message' => 'No data received.']);
    exit;
}

$imported = 0;
$skipped  = 0;

$check = $conn->prepare("SELECT id FROM products WHERE product_name = ? LIMIT 1");
$insert = $conn->prepare("INSERT INTO products (product_name, category, cost_price, selling_price, stock_quantity) VALUES (?,?,?,?,?)");

foreach ($rows as $row) {
    $name  = trim($row['name'] ?? '');
    $cat   = trim($row['cat']  ?? 'Uncategorized');
    $cost  = (float)($row['cost'] ?? 0);
    $sell  = (float)($row['sell'] ?? 0);
    $stock = (int)  ($row['stock'] ?? 0);

    if ($name === '') { $skipped++; continue; }

    // Check duplicate
    $check->bind_param('s', $name);
    $check->execute();
    if ($check->get_result()->num_rows > 0) { $skipped++; continue; }

    $insert->bind_param('ssddi', $name, $cat, $cost, $sell, $stock);
    $insert->execute() ? $imported++ : $skipped++;
}

echo json_encode(['success' => true, 'imported' => $imported, 'skipped' => $skipped]);
