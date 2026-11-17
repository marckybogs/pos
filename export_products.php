<?php
// ============================================================
// export_products.php — AJAX: return ALL products as JSON
// Ignores filters, pagination, search — full database dump
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_login();

header('Content-Type: application/json');

$result = $conn->query("
    SELECT product_name, category, barcode, cost_price, selling_price, stock_quantity
    FROM products
    ORDER BY product_name ASC
");

if (!$result) {
    echo json_encode(['success' => false, 'message' => $conn->error]);
    exit;
}

$products = [];
while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}

echo json_encode(['success' => true, 'products' => $products]);
