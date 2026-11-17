<?php
// ============================================================
// pos_quick_create.php — AJAX: create product from POS OTHERS modal
// Returns the new product's full data so JS can add it to cart.
// Uses identical INSERT logic to products.php.
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request body.']);
    exit;
}

// ── Server-side validation ─────────────────────────────────
$name     = trim($body['product_name'] ?? '');
$category = trim($body['category']     ?? 'OTHERS');
$cost     = $body['cost_price']        ?? null;
$sell     = $body['selling_price']     ?? null;
$stock    = $body['stock_quantity']    ?? null;
$qty      = $body['cart_quantity']     ?? null;  // how many to put in cart

$errors = [];

if ($name === '') {
    $errors[] = 'Product name is required.';
}

if ($cost === null || !is_numeric($cost)) {
    $errors[] = 'Cost must be a valid number.';
} elseif ((float)$cost < 0) {
    $errors[] = 'Cost cannot be negative.';
}

if ($sell === null || !is_numeric($sell)) {
    $errors[] = 'Selling price must be a valid number.';
} elseif ((float)$sell < 0) {
    $errors[] = 'Selling price cannot be negative.';
}

if ($stock === null || !is_numeric($stock) || floor((float)$stock) != (float)$stock) {
    $errors[] = 'Stock must be a whole number.';
} elseif ((int)$stock < 0) {
    $errors[] = 'Stock cannot be negative.';
}

if ($qty === null || !is_numeric($qty) || floor((float)$qty) != (float)$qty) {
    $errors[] = 'Quantity must be a whole number.';
} elseif ((int)$qty < 1) {
    $errors[] = 'Quantity must be at least 1.';
}

if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

$cost  = (float)$cost;
$sell  = (float)$sell;
$stock = (int)$stock;
$qty   = (int)$qty;
$category = $category === '' ? 'OTHERS' : $category;
$barcode  = ''; // no barcode from quick-create

// ── Stock vs quantity check ────────────────────────────────
if ($qty > $stock) {
    echo json_encode(['success' => false,
        'message' => "Quantity ($qty) cannot exceed available stock ($stock)."]);
    exit;
}

// ── Insert product (same query as products.php) ────────────
$stmt = $conn->prepare(
    "INSERT INTO products (product_name, category, barcode, cost_price, selling_price, stock_quantity)
     VALUES (?, ?, ?, ?, ?, ?)"
);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit;
}
$stmt->bind_param('sssddi', $name, $category, $barcode, $cost, $sell, $stock);

if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Insert failed: ' . $stmt->error]);
    exit;
}

$new_id = (int)$conn->insert_id;

// ── Return product data so JS can inject it into the cart ──
echo json_encode([
    'success'        => true,
    'product_id'     => $new_id,
    'product_name'   => $name,
    'category'       => $category,
    'cost_price'     => $cost,
    'selling_price'  => $sell,
    'stock_quantity' => $stock,
    'cart_quantity'  => $qty,
]);
