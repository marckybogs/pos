<?php
// ============================================================
// gcash_maya_save.php
// GCash/Maya is saved as a SEPARATE sales row with
// transaction_type = 'GCASH_MAYA'.
// total_amount = GCash total service fees (profit only).
// The GCash throughput amount is stored in gcash_maya_sessions
// only — it never touches the sales table.
// Reports exclude GCASH_MAYA rows from Summary Total Sales.
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_login();

header('Content-Type: application/json');
error_reporting(0);

// ── Auto-install tables + transaction_type column ──────────
$conn->query("
  CREATE TABLE IF NOT EXISTS `gcash_maya_sessions` (
    `id`           INT(11) NOT NULL AUTO_INCREMENT,
    `sale_id`      INT(11) DEFAULT NULL,
    `user_id`      INT(11) NOT NULL,
    `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'GCash throughput (NOT in reports revenue)',
    `total_profit` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'service fees = gross profit',
    `status`       ENUM('pending','done','merged_pos') NOT NULL DEFAULT 'pending',
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$conn->query("
  CREATE TABLE IF NOT EXISTS `gcash_maya_items` (
    `id`               INT(11) NOT NULL AUTO_INCREMENT,
    `session_id`       INT(11) NOT NULL,
    `service_type`     VARCHAR(50) NOT NULL,
    `transaction_type` VARCHAR(50) NOT NULL,
    `amount`           DECIMAL(10,2) NOT NULL,
    `service_fee`      DECIMAL(10,2) NOT NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `k_session` (`session_id`),
    CONSTRAINT `fk_gmi_session2`
      FOREIGN KEY (`session_id`) REFERENCES `gcash_maya_sessions` (`id`)
      ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Add transaction_type column to sales if not present
$col_check = $conn->query("SHOW COLUMNS FROM `sales` LIKE 'transaction_type'");
if ($col_check && $col_check->num_rows === 0) {
    $conn->query("
        ALTER TABLE `sales`
        ADD COLUMN `transaction_type` VARCHAR(20) NOT NULL DEFAULT 'POS'
        AFTER `payment_method`
    ");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body.']);
    exit;
}
$action = $body['action'] ?? '';

// ── Fee calculators ────────────────────────────────────────
function calculateCashInOutFee(float $a): float {
    if ($a <= 0 || $a > 20000) return -1;
    if ($a <= 200)  return 10;
    if ($a <= 500)  return 15;
    if ($a <= 1000) return 20;
    if ($a <= 1500) return 30;
    if ($a <= 2000) return 40;
    if ($a <= 2500) return 50;
    return 50 + (int)ceil(($a - 2500) / 500) * 10;
}
function calculateEloadingFee(float $a): float {
    if ($a <= 0)  return -1;
    if ($a <= 95) return 5;
    return 10;
}
function calculateBillsPaymentFee(float $a): float {
    if ($a <= 0 || $a > 10000) return -1;
    if ($a <= 1000) return 20;
    if ($a <= 2000) return 30;
    if ($a <= 3000) return 40;
    if ($a <= 4000) return 50;
    return 50 + (int)ceil(($a - 4000) / 1000) * 10;
}

// ═══════════════════════════════════════════════════════════
// ACTION: save_session
// ═══════════════════════════════════════════════════════════
if ($action === 'save_session') {
    $items       = $body['items']       ?? [];
    $destination = $body['destination'] ?? 'done'; // 'done' | 'pos'
    $user_id     = (int)$_SESSION['user_id'];

    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'No transactions to save.']);
        exit;
    }

    $total_throughput = 0.0; // sum of actual GCash amounts (NOT in reports revenue)
    $total_profit     = 0.0; // sum of service fees (IS in reports profit)
    $validated        = [];

    foreach ($items as $item) {
        $svc  = trim($item['service_type']     ?? '');
        $tx   = trim($item['transaction_type'] ?? '');
        $amt  = (float)($item['amount']        ?? 0);

        switch ($svc) {
            case 'Cash In/Out':   $fee = calculateCashInOutFee($amt);    break;
            case 'E-Loading':     $fee = calculateEloadingFee($amt);     break;
            case 'Bills Payment': $fee = calculateBillsPaymentFee($amt); break;
            default:              $fee = -1;
        }
        if ($fee < 0) {
            echo json_encode(['success' => false,
                'message' => "Invalid amount ₱{$amt} for {$svc}."]);
            exit;
        }
        $total_throughput += $amt;
        $total_profit     += $fee;
        $validated[]       = ['svc' => $svc, 'tx' => $tx, 'amt' => $amt, 'fee' => $fee];
    }

    $conn->begin_transaction();
    try {
        // 1. gcash_maya_sessions — store throughput here only
        $s1 = $conn->prepare(
            "INSERT INTO gcash_maya_sessions
               (user_id, total_amount, total_profit, status)
             VALUES (?, ?, ?, ?)"
        );
        if (!$s1) throw new Exception('Prepare sessions: ' . $conn->error);
        $status = ($destination === 'pos') ? 'merged_pos' : 'done';
        $s1->bind_param('idds', $user_id, $total_throughput, $total_profit, $status);
        if (!$s1->execute()) throw new Exception('Insert sessions: ' . $s1->error);
        $session_id = (int)$conn->insert_id;

        // 2. gcash_maya_items
        $s2 = $conn->prepare(
            "INSERT INTO gcash_maya_items
               (session_id, service_type, transaction_type, amount, service_fee)
             VALUES (?, ?, ?, ?, ?)"
        );
        if (!$s2) throw new Exception('Prepare items: ' . $conn->error);
        foreach ($validated as $v) {
            $s2->bind_param('issdd', $session_id, $v['svc'], $v['tx'], $v['amt'], $v['fee']);
            if (!$s2->execute()) throw new Exception('Insert item: ' . $s2->error);
        }

        // 3. sales row — transaction_type = 'GCASH_MAYA'
        //    total_amount = total_profit (service fees) so reports show correct revenue for GCash
        //    The GCash throughput (total_throughput) is ONLY in gcash_maya_sessions
        $invoice = 'GM-' . date('YmdHis') . '-' . sprintf('%04d', rand(0, 9999));
        $s3 = $conn->prepare(
            "INSERT INTO sales
               (invoice_number, user_id, subtotal, vat_percent, vat_amount,
                discount_amount, total_amount, amount_paid, change_amount,
                payment_method, transaction_type)
             VALUES (?, ?, ?, 0, 0, 0, ?, ?, 0, 'gcash', 'GCASH_MAYA')"
        );
        if (!$s3) throw new Exception('Prepare sales: ' . $conn->error);
        // subtotal = total_profit, total_amount = total_profit, amount_paid = total_profit
        $s3->bind_param('siddd',
            $invoice, $user_id,
            $total_profit,   // subtotal
            $total_profit,   // total_amount — only service fees, NOT throughput
            $total_profit    // amount_paid
        );
        if (!$s3->execute()) throw new Exception('Insert sales: ' . $s3->error);
        $sale_id = (int)$conn->insert_id;

        // 4. sale_items — one row per transaction, unit_price=fee, cost_price=0
        //    gross profit = SUM(line_total - cost*qty) = SUM(fee) = total_profit ✓
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");
        $s4 = $conn->prepare(
            "INSERT INTO sale_items
               (sale_id, product_id, product_name, quantity, unit_price, cost_price, line_total)
             VALUES (?, NULL, ?, 1, ?, 0.00, ?)"
        );
        if (!$s4) {
            $conn->query("SET FOREIGN_KEY_CHECKS = 1");
            throw new Exception('Prepare sale_items: ' . $conn->error);
        }
        foreach ($validated as $v) {
            $label = 'GCash/Maya – ' . $v['tx'] . ' (₱' . number_format($v['amt'], 0) . ')';
            $s4->bind_param('isdd', $sale_id, $label, $v['fee'], $v['fee']);
            if (!$s4->execute()) {
                $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                throw new Exception('Insert sale_items: ' . $s4->error);
            }
        }
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");

        // 5. Link session → sale
        $s5 = $conn->prepare("UPDATE gcash_maya_sessions SET sale_id=? WHERE id=?");
        if ($s5) { $s5->bind_param('ii', $sale_id, $session_id); $s5->execute(); }

        $conn->commit();

        // Pending profit in PHP session for "Continue to POS" only
        if ($destination === 'pos') {
            $_SESSION['gcash_pending_profit']  = $total_profit;
            $_SESSION['gcash_pending_session'] = $session_id;
        } else {
            // DONE — clear any leftover pending
            unset($_SESSION['gcash_pending_profit'], $_SESSION['gcash_pending_session']);
        }

        echo json_encode([
            'success'          => true,
            'session_id'       => $session_id,
            'invoice'          => $invoice,
            'total_throughput' => $total_throughput,
            'total_profit'     => $total_profit,
            'destination'      => $destination,
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ─── get_pending ───────────────────────────────────────────
if ($action === 'get_pending') {
    echo json_encode([
        'success'     => true,
        'has_pending' => !empty($_SESSION['gcash_pending_profit']),
        'profit'      => (float)($_SESSION['gcash_pending_profit']  ?? 0),
        'session_id'  => (int)  ($_SESSION['gcash_pending_session'] ?? 0),
    ]);
    exit;
}

// ─── clear_pending ─────────────────────────────────────────
if ($action === 'clear_pending') {
    unset($_SESSION['gcash_pending_profit'], $_SESSION['gcash_pending_session']);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
