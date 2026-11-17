<?php
// ============================================================
// get_transaction.php — AJAX: fetch full invoice details
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_login();

header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid transaction ID.']);
    exit;
}

// Fetch sale header
$stmt = $conn->prepare("
    SELECT s.*, u.full_name AS cashier, u.username
    FROM sales s
    JOIN users u ON u.id = s.user_id
    WHERE s.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $id);
$stmt->execute();
$sale = $stmt->get_result()->fetch_assoc();

if (!$sale) {
    echo json_encode(['success' => false, 'message' => 'Transaction not found.']);
    exit;
}

// Fetch sale items
$items_stmt = $conn->prepare("
    SELECT si.*, (si.line_total - si.cost_price * si.quantity) AS item_profit
    FROM sale_items si
    WHERE si.sale_id = ?
    ORDER BY si.id ASC
");
$items_stmt->bind_param('i', $id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();

$items = [];
while ($row = $items_result->fetch_assoc()) {
    $items[] = [
        'product_name' => $row['product_name'],
        'quantity'     => (int)$row['quantity'],
        'unit_price'   => (float)$row['unit_price'],
        'cost_price'   => (float)$row['cost_price'],
        'line_total'   => (float)$row['line_total'],
        'item_profit'  => (float)$row['item_profit'],
    ];
}

// Fetch shop settings for receipt
$shop_name    = get_setting($conn, 'shop_name', 'QuickMart POS');
$shop_address = get_setting($conn, 'shop_address', '');
$shop_phone   = get_setting($conn, 'shop_phone', '');
$currency     = get_setting($conn, 'currency', '₱');
$footer_msg   = get_setting($conn, 'receipt_footer', 'Thank you for shopping with us!');

echo json_encode([
    'success' => true,
    'sale'    => [
        'id'             => (int)$sale['id'],
        'invoice_number' => $sale['invoice_number'],
        'cashier'        => $sale['cashier'],
        'username'       => $sale['username'],
        'subtotal'       => (float)$sale['subtotal'],
        'vat_percent'    => (float)$sale['vat_percent'],
        'vat_amount'     => (float)$sale['vat_amount'],
        'discount_amount'=> (float)$sale['discount_amount'],
        'total_amount'   => (float)$sale['total_amount'],
        'amount_paid'    => (float)$sale['amount_paid'],
        'change_amount'  => (float)$sale['change_amount'],
        'payment_method' => $sale['payment_method'],
        'status'         => $sale['status'],
        'created_at'     => $sale['created_at'],
    ],
    'items'   => $items,
    'shop'    => [
        'name'     => $shop_name,
        'address'  => $shop_address,
        'phone'    => $shop_phone,
        'currency' => $currency,
        'footer'   => $footer_msg,
    ],
]);
