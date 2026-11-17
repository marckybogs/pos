<?php
// ============================================================
// process_sale.php — Save POS sale + optionally merge GCash profit
// GCash pending profit: added as a zero-cost sale_items row only.
// It does NOT change total_amount (POS sales stays POS sales).
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body || empty($body['cart'])) {
    echo json_encode(['success' => false, 'message' => 'Empty cart.']);
    exit;
}

$cart           = $body['cart'];
$subtotal       = round((float)$body['subtotal'],    2);
$vat_percent    = round((float)$body['vat_percent'], 2);
$vat_amount     = round((float)$body['vat_amount'],  2);
$discount       = round((float)$body['discount'],    2);
$total          = round((float)$body['total'],       2);
$amount_paid    = round((float)$body['amount_paid'], 2);
$change_amount  = round((float)$body['change_amount'], 2);
$payment_method = in_array($body['payment_method'],
    ['cash','card','gcash','other']) ? $body['payment_method'] : 'cash';
$user_id        = (int)$_SESSION['user_id'];

// GCash pending profit — read from SERVER SESSION, not from client body
// Using the session value prevents any client-side tampering or double-use
$gcash_profit  = round((float)($_SESSION['gcash_pending_profit']  ?? 0), 2);
$gcash_session = (int)         ($_SESSION['gcash_pending_session'] ?? 0);

$invoice = 'INV-' . strtoupper(date('YmdHis')) . '-' . sprintf('%04d', rand(0, 9999));

$conn->begin_transaction();
try {
    // 1. Insert sale header — transaction_type = 'POS'
    //    total_amount = POS product sales only, never includes GCash throughput
    $stmt = $conn->prepare("
        INSERT INTO sales
          (invoice_number, user_id, subtotal, vat_percent, vat_amount,
           discount_amount, total_amount, amount_paid, change_amount,
           payment_method, transaction_type)
        VALUES (?,?,?,?,?,?,?,?,?,?,'POS')
    ");
    if (!$stmt) throw new Exception('Prepare sale: ' . $conn->error);
    $stmt->bind_param('siddddddds',
        $invoice, $user_id,
        $subtotal, $vat_percent, $vat_amount, $discount,
        $total, $amount_paid, $change_amount, $payment_method
    );
    if (!$stmt->execute()) throw new Exception('Insert sale: ' . $stmt->error);
    $sale_id = (int)$conn->insert_id;

    // 2. Insert product sale_items + deduct stock
    $item_stmt = $conn->prepare("
        INSERT INTO sale_items
          (sale_id, product_id, product_name, quantity, unit_price, cost_price, line_total)
        VALUES (?,?,?,?,?,?,?)
    ");
    $stock_stmt = $conn->prepare("
        UPDATE products SET stock_quantity = stock_quantity - ?
        WHERE id = ? AND stock_quantity >= ?
    ");
    if (!$item_stmt || !$stock_stmt) throw new Exception('Prepare items: ' . $conn->error);

    foreach ($cart as $item) {
        $product_id   = (int)$item['product_id'];
        $product_name = substr(trim($item['product_name']), 0, 150);
        $quantity     = (int)$item['quantity'];
        $unit_price   = (float)$item['unit_price'];
        $line_total   = $unit_price * $quantity;

        // Fetch current cost price from DB (not from client)
        $cp = $conn->prepare("SELECT cost_price FROM products WHERE id = ?");
        $cp->bind_param('i', $product_id);
        $cp->execute();
        $cp_row     = $cp->get_result()->fetch_assoc();
        $cost_price = $cp_row ? (float)$cp_row['cost_price'] : 0.0;

        $item_stmt->bind_param('iisiddd',
            $sale_id, $product_id, $product_name,
            $quantity, $unit_price, $cost_price, $line_total);
        if (!$item_stmt->execute()) throw new Exception('Insert item: ' . $item_stmt->error);

        $stock_stmt->bind_param('iii', $quantity, $product_id, $quantity);
        $stock_stmt->execute();
        if ($conn->affected_rows === 0)
            throw new Exception("Insufficient stock for: $product_name");
    }

    // 3. GCash pending profit — add as a zero-cost sale_items row
    //    unit_price = cost_price = 0, line_total = profit
    //    gross profit formula: SUM(line_total - cost*qty) = profit ✓
    //    This does NOT change total_amount (POS sales is unchanged)
    if ($gcash_profit > 0) {
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");
        $gm = $conn->prepare("
            INSERT INTO sale_items
              (sale_id, product_id, product_name, quantity, unit_price, cost_price, line_total)
            VALUES (?, NULL, 'GCash/Maya Profit (merged)', 1, ?, 0.00, ?)
        ");
        if (!$gm) {
            $conn->query("SET FOREIGN_KEY_CHECKS = 1");
            throw new Exception('Prepare GCash item: ' . $conn->error);
        }
        $gm->bind_param('idd', $sale_id, $gcash_profit, $gcash_profit);
        if (!$gm->execute()) {
            $conn->query("SET FOREIGN_KEY_CHECKS = 1");
            throw new Exception('Insert GCash item: ' . $gm->error);
        }
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");

        // Mark the gcash_maya_sessions row as merged
        if ($gcash_session > 0) {
            $mg = $conn->prepare(
                "UPDATE gcash_maya_sessions SET status='merged_pos', sale_id=? WHERE id=?"
            );
            if ($mg) { $mg->bind_param('ii', $sale_id, $gcash_session); $mg->execute(); }
        }

        // Clear from PHP session — this is the single authoritative clear
        unset($_SESSION['gcash_pending_profit'], $_SESSION['gcash_pending_session']);
    }

    $conn->commit();

    echo json_encode([
        'success'            => true,
        'invoice'            => $invoice,
        'subtotal'           => number_format($subtotal,     2),
        'vat_pct'            => $vat_percent,
        'vat_amount'         => number_format($vat_amount,   2),
        'discount'           => number_format($discount,     2),
        'total'              => number_format($total,        2),
        'paid'               => number_format($amount_paid,  2),
        'change'             => number_format($change_amount,2),
        'method'             => ucfirst($payment_method),
        'gcash_profit_merged'=> number_format($gcash_profit, 2),
    ]);

} catch (Exception $e) {
    $conn->rollback();
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
