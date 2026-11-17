<?php
// ============================================================
// reports.php — Sales, Profit, Top Products Reports
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_login();
define('PAGE_TITLE', 'Reports');

$currency = get_setting($conn, 'currency', '₱');
$today    = date('Y-m-d');

// ── Date range filter ──────────────────────────────────────
$date_from = $_GET['from'] ?? date('Y-m-01');
$date_to   = $_GET['to']   ?? $today;
$preset    = $_GET['preset'] ?? '';

if ($preset === 'today')   { $date_from = $date_to = $today; }
if ($preset === 'month')   { $date_from = date('Y-m-01'); $date_to = $today; }
if ($preset === 'lastmonth') {
    $date_from = date('Y-m-01', strtotime('first day of last month'));
    $date_to   = date('Y-m-t', strtotime('last day of last month'));
}

// ── Sales Report (ALL transactions — POS + GCash shown separately) ──
$sales_stmt = $conn->prepare("
    SELECT s.id, s.invoice_number, s.total_amount, s.discount_amount,
           s.vat_amount, s.payment_method, s.status, s.created_at,
           COALESCE(s.transaction_type, 'POS') AS transaction_type,
           u.full_name AS cashier,
           COALESCE(SUM(si.line_total - si.cost_price * si.quantity), 0) AS gross_profit
    FROM sales s
    JOIN users u ON u.id = s.user_id
    LEFT JOIN sale_items si ON si.sale_id = s.id
    WHERE DATE(s.created_at) BETWEEN ? AND ?
    GROUP BY s.id
    ORDER BY s.created_at DESC
");
$sales_stmt->bind_param('ss', $date_from, $date_to);
$sales_stmt->execute();
$sales_result = $sales_stmt->get_result();

// ── Summary Totals — POS ONLY (exclude GCASH_MAYA) ─────────
$sum_stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_tx,
        COALESCE(SUM(total_amount),0) AS total_revenue,
        COALESCE(SUM(vat_amount),0) AS total_vat,
        COALESCE(SUM(discount_amount),0) AS total_discount
    FROM sales
    WHERE DATE(created_at) BETWEEN ? AND ?
      AND status = 'completed'
      AND COALESCE(transaction_type,'POS') = 'POS'
");
$sum_stmt->bind_param('ss', $date_from, $date_to);
$sum_stmt->execute();
$totals = $sum_stmt->get_result()->fetch_assoc();

// ── COGS — POS sale_items only ─────────────────────────────
$cogs_stmt = $conn->prepare("
    SELECT COALESCE(SUM(si.cost_price * si.quantity), 0) AS cogs
    FROM sale_items si
    JOIN sales s ON s.id = si.sale_id
    WHERE DATE(s.created_at) BETWEEN ? AND ?
      AND s.status = 'completed'
      AND COALESCE(s.transaction_type,'POS') = 'POS'
");
$cogs_stmt->bind_param('ss', $date_from, $date_to);
$cogs_stmt->execute();
$cogs = $cogs_stmt->get_result()->fetch_assoc()['cogs'];

// ── GCash/Maya Profit — service fees in the selected date range ──
// Source: gcash_maya_sessions.total_profit (one row per completed batch,
// covers Cash In/Out, E-Loading and Bills Payment; both "Done" and
// "Continue to POS" batches). Uses the same $date_from/$date_to filter as
// the rest of this page. Does not touch Total Revenue or POS Gross Profit.
$gcash_profit = 0.0;
$gm_table = $conn->query("SHOW TABLES LIKE 'gcash_maya_sessions'");
if ($gm_table && $gm_table->num_rows > 0) {
    $gm_stmt = $conn->prepare("
        SELECT COALESCE(SUM(total_profit), 0) AS gm_profit
        FROM gcash_maya_sessions
        WHERE DATE(created_at) BETWEEN ? AND ?
    ");
    if ($gm_stmt) {
        $gm_stmt->bind_param('ss', $date_from, $date_to);
        $gm_stmt->execute();
        $gcash_profit = (float)($gm_stmt->get_result()->fetch_assoc()['gm_profit'] ?? 0);
    }
}

// ── Gross Profit — POS sale_items only ─────────────────────
// Includes the merged GCash profit rows (product_name contains 'GCash/Maya Profit')
// because those are stored as sale_items on POS sales (transaction_type='POS')
$profit_stmt = $conn->prepare("
    SELECT COALESCE(SUM(si.line_total - (si.cost_price * si.quantity)), 0) AS profit
    FROM sale_items si
    JOIN sales s ON s.id = si.sale_id
    WHERE DATE(s.created_at) BETWEEN ? AND ?
      AND s.status = 'completed'
      AND COALESCE(s.transaction_type,'POS') = 'POS'
");
$profit_stmt->bind_param('ss', $date_from, $date_to);
$profit_stmt->execute();
$profit = $profit_stmt->get_result()->fetch_assoc()['profit'];

// ── Top Selling Products — POS only ───────────────────────
$top_stmt = $conn->prepare("
    SELECT si.product_name,
           SUM(si.quantity) AS total_qty,
           SUM(si.line_total) AS revenue,
           SUM(si.line_total - si.cost_price * si.quantity) AS gross_profit
    FROM sale_items si
    JOIN sales s ON s.id = si.sale_id
    WHERE DATE(s.created_at) BETWEEN ? AND ?
      AND s.status = 'completed'
      AND COALESCE(s.transaction_type,'POS') = 'POS'
      AND si.product_name NOT LIKE 'GCash/Maya%'
    GROUP BY si.product_id, si.product_name
    ORDER BY total_qty DESC
    LIMIT 10
");
$top_stmt->bind_param('ss', $date_from, $date_to);
$top_stmt->execute();
$top_products = $top_stmt->get_result();

require_once 'includes/header.php';
?>

<!-- ── Filter Bar ── -->
<div class="card" style="margin-bottom:16px;overflow:hidden">
  <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
    <div class="form-group" style="margin:0">
      <label class="form-label">From</label>
      <input type="date" name="from" class="form-control" value="<?= h($date_from) ?>">
    </div>
    <div class="form-group" style="margin:0">
      <label class="form-label">To</label>
      <input type="date" name="to" class="form-control" value="<?= h($date_to) ?>">
    </div>
    <button type="submit" class="btn btn-primary" style="align-self:flex-end">
      <i class="fa-solid fa-filter"></i> Filter
    </button>
    <div style="width:1px;height:36px;background:var(--card-border);align-self:flex-end;flex-shrink:0"></div>
    <div style="display:flex;gap:6px;align-self:flex-end;flex-wrap:wrap">
      <a href="?preset=today"     class="btn btn-sm <?= $preset==='today'     ? 'btn-primary' : 'btn-outline' ?>">Today</a>
      <a href="?preset=month"     class="btn btn-sm <?= $preset==='month'     ? 'btn-primary' : 'btn-outline' ?>">This Month</a>
      <a href="?preset=lastmonth" class="btn btn-sm <?= $preset==='lastmonth' ? 'btn-primary' : 'btn-outline' ?>">Last Month</a>
    </div>
    <div style="width:1px;height:36px;background:var(--card-border);align-self:flex-end;flex-shrink:0"></div>
    <div style="display:flex;gap:6px;align-self:flex-end;flex-wrap:wrap">
      <button type="button" class="btn btn-outline btn-sm"
        onclick="exportTableToExcel('salesTable','sales_report_<?= $date_from ?>_to_<?= $date_to ?>')">
        <i class="fa-solid fa-file-excel"></i> Export Sales
      </button>
      <button type="button" class="btn btn-outline btn-sm"
        onclick="exportTableToExcel('topTable','top_products_<?= $date_from ?>')">
        <i class="fa-solid fa-file-excel"></i> Export Top Products
      </button>
    </div>
  </form>
</div>

<!-- ── Summary Stats ── -->
<div class="reports-stats-grid" style="margin-bottom:16px">
  <div class="stat-card blue">
    <div class="stat-icon blue"><i class="fa-solid fa-receipt"></i></div>
    <div style="min-width:0">
      <div class="stat-label">Transactions</div>
      <div class="stat-value"><?= (int)$totals['total_tx'] ?></div>
      <div class="stat-sub">Completed sales</div>
    </div>
  </div>
  <div class="stat-card green">
    <div class="stat-icon green"><i class="fa-solid fa-peso-sign"></i></div>
    <div style="min-width:0">
      <div class="stat-label">Total Revenue</div>
      <div class="stat-value stat-value-md"><?= h($currency) ?><?= number_format($totals['total_revenue'],2) ?></div>
      <div class="stat-sub">POS sales only</div>
    </div>
  </div>
  <div class="stat-card amber">
    <div class="stat-icon amber"><i class="fa-solid fa-chart-line"></i></div>
    <div style="min-width:0">
      <div class="stat-label">Gross Profit</div>
      <div class="stat-value stat-value-md"><?= h($currency) ?><?= number_format($profit,2) ?></div>
      <div class="stat-sub">Revenue minus COGS</div>
    </div>
  </div>
  <div class="stat-card blue">
    <div class="stat-icon blue"><i class="fa-solid fa-mobile-screen-button"></i></div>
    <div style="min-width:0">
      <div class="stat-label">GCash Profit</div>
      <div class="stat-value stat-value-md"><?= h($currency) ?><?= number_format($gcash_profit,2) ?></div>
      <div class="stat-sub">GCash/Maya service fees</div>
    </div>
  </div>
  <div class="stat-card red">
    <div class="stat-icon red"><i class="fa-solid fa-boxes-stacked"></i></div>
    <div style="min-width:0">
      <div class="stat-label">Cost of Products Sold</div>
      <div class="stat-value stat-value-md"><?= h($currency) ?><?= number_format($cogs,2) ?></div>
      <div class="stat-sub">Total COGS</div>
    </div>
  </div>
</div>

<!-- ── Main Grid ── -->
<div class="reports-main-grid">

  <!-- Sales Transactions Table -->
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-list" style="color:var(--info)"></i> Sales Transactions</span>
      <span class="badge badge-info"><?= $sales_result->num_rows ?> record<?= $sales_result->num_rows !== 1 ? 's' : '' ?></span>
    </div>
    <div class="table-wrapper" style="max-height:480px;overflow-y:auto">
      <table id="salesTable">
        <thead>
          <tr>
            <th style="min-width:130px">Invoice</th>
            <th style="white-space:nowrap">Date</th>
            <th>Cashier</th>
            <th style="text-align:right;white-space:nowrap">Total</th>
            <th style="text-align:right;white-space:nowrap">Gross Profit</th>
            <th style="text-align:center">Method</th>
            <th style="text-align:center">Status</th>
            <th style="width:40px"></th>
            <th style="width:70px;text-align:center">Copy</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($sales_result->num_rows === 0): ?>
          <tr><td colspan="9" style="text-align:center;padding:32px;color:var(--text-muted)">
            <i class="fa-solid fa-inbox" style="font-size:1.5rem;display:block;margin-bottom:8px;opacity:.4"></i>
            No transactions in this period.
          </td></tr>
        <?php else: ?>
        <?php
        // Re-calculate period totals from the result rows so the JS
        // running totals match exactly what is displayed in the table.
        // We need to re-fetch because the result pointer is consumed below.
        $period_sales_stmt = $conn->prepare("
            SELECT
              (SELECT COALESCE(SUM(total_amount), 0)
               FROM sales
               WHERE DATE(created_at) BETWEEN ? AND ?
                 AND status = 'completed'
                 AND COALESCE(transaction_type,'POS') = 'POS') AS period_sales,
              (SELECT COALESCE(SUM(si.line_total - si.cost_price * si.quantity), 0)
               FROM sale_items si
               JOIN sales s ON s.id = si.sale_id
               WHERE DATE(s.created_at) BETWEEN ? AND ?
                 AND s.status = 'completed'
                 AND COALESCE(s.transaction_type,'POS') = 'POS') AS period_profit
        ");
        $period_sales_stmt->bind_param('ssss', $date_from, $date_to, $date_from, $date_to);
        $period_sales_stmt->execute();
        $period_row   = $period_sales_stmt->get_result()->fetch_assoc();
        $period_sales  = (float)($period_row['period_sales']  ?? 0);
        $period_profit = (float)($period_row['period_profit'] ?? 0);
        ?>
        <?php while ($s = $sales_result->fetch_assoc()):
          $row_date   = date('m/d/Y', strtotime($s['created_at']));
          $row_sales  = (float)$s['total_amount'];
          $row_profit = (float)$s['gross_profit'];
          $is_gcash   = ($s['transaction_type'] === 'GCASH_MAYA');

          // For GCash rows: get the actual throughput from gcash_maya_sessions
          $gcash_throughput = null;
          if ($is_gcash) {
              $gts = $conn->prepare(
                  "SELECT total_amount FROM gcash_maya_sessions WHERE sale_id = ? LIMIT 1"
              );
              $gts->bind_param('i', $s['id']);
              $gts->execute();
              $gtr = $gts->get_result()->fetch_assoc();
              $gcash_throughput = $gtr ? (float)$gtr['total_amount'] : null;
          }

          // For POS rows: find how much of the profit came from a merged GCash row
          // so the copy text can display: POS profit + GCash profit = combined
          $gcash_merged_profit = 0.0;
          if (!$is_gcash) {
              $gmp = $conn->prepare("
                  SELECT COALESCE(SUM(line_total), 0) AS gp
                  FROM sale_items
                  WHERE sale_id = ?
                    AND product_name = 'GCash/Maya Profit (merged)'
              ");
              $gmp->bind_param('i', $s['id']);
              $gmp->execute();
              $gmp_row = $gmp->get_result()->fetch_assoc();
              $gcash_merged_profit = (float)($gmp_row['gp'] ?? 0);
          }
          // POS-only profit (excludes the merged GCash row)
          $pos_only_profit = $row_profit - $gcash_merged_profit;
        ?>
          <tr>
            <td style="font-size:.78rem;font-family:monospace;white-space:nowrap;color:var(--text-secondary)">
              <?= h($s['invoice_number']) ?>
            </td>
            <td style="font-size:.82rem;white-space:nowrap">
              <?= date('M d, Y H:i', strtotime($s['created_at'])) ?>
            </td>
            <td style="font-size:.875rem"><?= h($s['cashier']) ?></td>
            <td style="text-align:right;font-weight:700">
              <?php if ($is_gcash && $gcash_throughput !== null): ?>
                <span title="GCash throughput: <?= h($currency) ?><?= number_format($gcash_throughput,2) ?> | Service fee: <?= h($currency) ?><?= number_format($row_sales,2) ?>">
                  <?= h($currency) ?><?= number_format($gcash_throughput, 2) ?>
                </span>
                <span style="font-size:.72rem;color:var(--text-muted);display:block">
                  fee: <?= h($currency) ?><?= number_format($row_sales, 2) ?>
                </span>
              <?php else: ?>
                <?= h($currency) ?><?= number_format($row_sales, 2) ?>
              <?php endif; ?>
            </td>
            <td style="text-align:right;font-weight:600;color:var(--success)">
              <?= h($currency) ?><?= number_format($row_profit, 2) ?>
            </td>
            <td style="text-align:center">
              <?php if ($is_gcash): ?>
                <span class="badge" style="background:rgba(59,130,246,.12);color:var(--info)">GCash/Maya</span>
              <?php else: ?>
                <span class="badge badge-info"><?= h(ucfirst($s['payment_method'])) ?></span>
              <?php endif; ?>
            </td>
            <td style="text-align:center">
              <?php if ($s['status'] === 'completed'): ?>
                <span class="badge badge-success">Completed</span>
              <?php else: ?>
                <span class="badge badge-danger">Voided</span>
              <?php endif; ?>
            </td>
            <td style="text-align:center">
              <button class="btn btn-outline btn-sm btn-icon" title="View Invoice"
                onclick="viewTransaction(<?= (int)$s['id'] ?>)">
                <i class="fa-solid fa-eye"></i>
              </button>
            </td>
            <td style="text-align:center">
              <?php if (!$is_gcash): ?>
              <button
                class="btn btn-outline btn-sm copy-profit-btn"
                title="Copy sales &amp; profit summary"
                data-date="<?= h($row_date) ?>"
                data-sales="<?= $row_sales ?>"
                data-profit="<?= $row_profit ?>"
                data-pos-profit="<?= $pos_only_profit ?>"
                data-gcash-profit="<?= $gcash_merged_profit ?>"
                data-period-sales="<?= $period_sales ?>"
                data-period-profit="<?= $period_profit ?>"
                onclick="copyProfitSummary(this)"
                style="font-size:.72rem;padding:4px 8px;gap:4px">
                <i class="fa-solid fa-copy"></i> Copy
              </button>
              <?php else: ?>
                <span style="color:var(--text-muted);font-size:.72rem">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endwhile; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Top Products -->
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-star" style="color:var(--accent-color)"></i> Top Products</span>
    </div>
    <div class="table-wrapper" style="max-height:480px;overflow-y:auto">
      <table id="topTable">
        <thead>
          <tr>
            <th style="width:36px">#</th>
            <th>Product</th>
            <th style="text-align:center">Sold</th>
            <th style="text-align:right">Revenue</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($top_products->num_rows === 0): ?>
          <tr><td colspan="4" style="text-align:center;padding:32px;color:var(--text-muted)">
            <i class="fa-solid fa-inbox" style="font-size:1.5rem;display:block;margin-bottom:8px;opacity:.4"></i>
            No data.
          </td></tr>
        <?php else: ?>
        <?php $rank = 1; while ($r = $top_products->fetch_assoc()):
          $rankColors = [1=>'background:#fef3c7;color:#b45309', 2=>'background:#f1f5f9;color:#475569', 3=>'background:#fee2e2;color:#b91c1c'];
          $rankStyle = $rankColors[$rank] ?? 'background:var(--bg-color);color:var(--text-muted)';
        ?>
          <tr>
            <td>
              <span style="width:26px;height:26px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:700;font-size:.75rem;<?= $rankStyle ?>">
                <?= $rank++ ?>
              </span>
            </td>
            <td style="font-size:.83rem;font-weight:500;max-width:140px;word-break:break-word;line-height:1.3"><?= h($r['product_name']) ?></td>
            <td style="text-align:center;font-weight:600;white-space:nowrap"><?= (int)$r['total_qty'] ?></td>
            <td style="text-align:right;font-size:.83rem;font-weight:600;white-space:nowrap"><?= h($currency) ?><?= number_format($r['revenue'],2) ?></td>
          </tr>
        <?php endwhile; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<style>
/* ── Reports page layout ── */
.reports-stats-grid {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 14px;
}
@media (max-width: 1500px) { .reports-stats-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 1024px) { .reports-stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 540px)  { .reports-stats-grid { grid-template-columns: 1fr; } }

/* Prevent stat cards from blowing out */
.reports-stats-grid .stat-card {
  min-width: 0;
  padding: 16px 14px;
  gap: 10px;
}
.reports-stats-grid .stat-card > div { min-width: 0; }
.reports-stats-grid .stat-icon { width: 40px; height: 40px; flex-shrink: 0; }
.reports-stats-grid .stat-label { font-size: .7rem; }
.reports-stats-grid .stat-value {
  font-size: 1.1rem !important;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  line-height: 1.2;
}
.reports-stats-grid .stat-sub { font-size: .68rem; }

.reports-main-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 280px;
  gap: 16px;
  align-items: start;
}
@media (max-width: 900px) { .reports-main-grid { grid-template-columns: 1fr; } }

/* ── Copy profit button ── */
.copy-profit-btn {
  white-space: nowrap;
  font-size: .72rem !important;
  padding: 4px 9px !important;
  gap: 4px;
  transition: border-color .2s, color .2s;
}
.copy-profit-btn:disabled {
  opacity: 1; /* keep green feedback visible */
  cursor: default;
}

/* ── Invoice Modal Styles ── */
.invoice-modal { max-width: 680px; }

.invoice-header-block {
  background: var(--primary-color);
  color: #fff;
  border-radius: 10px;
  padding: 20px 24px;
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  flex-wrap: wrap;
  margin-bottom: 20px;
}
.invoice-shop-name  { font-family: 'Syne', sans-serif; font-size: 1.2rem; font-weight: 800; margin-bottom: 4px; }
.invoice-shop-meta  { font-size: .8rem; opacity: .7; line-height: 1.6; }
.invoice-badge-block { text-align: right; }
.invoice-number     { font-family: monospace; font-size: .95rem; font-weight: 600; background: rgba(255,255,255,.12); padding: 5px 10px; border-radius: 6px; display: inline-block; margin-bottom: 6px; letter-spacing:.04em; }
.invoice-date       { font-size: .78rem; opacity: .7; }

.invoice-meta-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px,1fr));
  gap: 10px;
  margin-bottom: 20px;
}
.invoice-meta-card {
  background: var(--bg-color);
  border: 1px solid var(--card-border);
  border-radius: 8px;
  padding: 10px 14px;
}
.invoice-meta-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); margin-bottom: 3px; }
.invoice-meta-value { font-family: 'Syne', sans-serif; font-weight: 700; font-size: .95rem; color: var(--text-primary); }

.invoice-items-table thead th { font-size: .75rem; padding: 9px 12px; }
.invoice-items-table tbody td { padding: 9px 12px; font-size: .85rem; }

.invoice-totals {
  margin-top: 14px;
  border-top: 2px solid var(--card-border);
  padding-top: 12px;
}
.invoice-total-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 4px 0;
  font-size: .875rem;
  color: var(--text-secondary);
}
.invoice-total-row.grand {
  font-family: 'Syne', sans-serif;
  font-weight: 800;
  font-size: 1.15rem;
  color: var(--text-primary);
  padding-top: 8px;
  margin-top: 4px;
  border-top: 1px solid var(--card-border);
}
.invoice-total-row.grand span:last-child { color: var(--accent-color); }
.invoice-total-row.profit span:last-child { color: var(--success); font-weight: 600; }

.invoice-footer-msg {
  text-align: center;
  margin-top: 16px;
  font-size: .8rem;
  color: var(--text-muted);
  padding-top: 12px;
  border-top: 1px dashed var(--card-border);
}

.invoice-status-banner {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  border-radius: 8px;
  margin-bottom: 16px;
  font-size: .875rem;
  font-weight: 500;
}
.invoice-status-banner.completed { background: rgba(34,197,94,.1); color: #15803d; border: 1px solid rgba(34,197,94,.25); }
.invoice-status-banner.voided    { background: rgba(239,68,68,.1); color: #dc2626; border: 1px solid rgba(239,68,68,.25); }

.invoice-loading {
  display: flex; flex-direction: column; align-items: center;
  justify-content: center; padding: 60px 20px; gap: 12px;
  color: var(--text-muted);
}
.invoice-loading i { font-size: 2rem; animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>

<!-- ===== TRANSACTION DETAIL MODAL ===== -->
<div class="modal-overlay" id="transactionModal">
  <div class="modal invoice-modal">
    <div class="modal-header">
      <span class="modal-title"><i class="fa-solid fa-file-invoice" style="color:var(--accent-color)"></i> Transaction Details</span>
      <button class="modal-close" data-close-modal="transactionModal">&times;</button>
    </div>
    <div class="modal-body" id="transactionModalBody">
      <div class="invoice-loading">
        <i class="fa-solid fa-spinner"></i>
        <span>Loading transaction…</span>
      </div>
    </div>
    <div class="modal-footer" id="transactionModalFooter" style="display:none">
      <button class="btn btn-outline" data-close-modal="transactionModal">Close</button>
      <button class="btn btn-outline" onclick="printInvoice()"><i class="fa-solid fa-print"></i> Print</button>
    </div>
  </div>
</div>

<script>
const reportCurrency = '<?= h($currency) ?>';

function viewTransaction(saleId) {
  // Reset modal to loading state
  document.getElementById('transactionModalBody').innerHTML = `
    <div class="invoice-loading">
      <i class="fa-solid fa-spinner"></i>
      <span>Loading transaction…</span>
    </div>`;
  document.getElementById('transactionModalFooter').style.display = 'none';
  openModal('transactionModal');

  fetch('get_transaction.php?id=' + saleId)
    .then(r => r.json())
    .then(data => {
      if (!data.success) {
        document.getElementById('transactionModalBody').innerHTML =
          `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> ${data.message}</div>`;
        return;
      }
      renderInvoice(data.sale, data.items, data.shop);
      document.getElementById('transactionModalFooter').style.display = 'flex';
    })
    .catch(() => {
      document.getElementById('transactionModalBody').innerHTML =
        `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> Network error. Please try again.</div>`;
    });
}

function renderInvoice(sale, items, shop) {
  const cur = shop.currency || reportCurrency;
  const fmt = n => cur + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  // Date formatting
  const dt = new Date(sale.created_at.replace(' ', 'T'));
  const dateStr = dt.toLocaleDateString('en-PH', { year:'numeric', month:'long', day:'numeric' });
  const timeStr = dt.toLocaleTimeString('en-PH', { hour:'2-digit', minute:'2-digit', second:'2-digit' });

  // Status
  const isCompleted = sale.status === 'completed';
  const statusBanner = `
    <div class="invoice-status-banner ${sale.status}">
      <i class="fa-solid ${isCompleted ? 'fa-circle-check' : 'fa-ban'}"></i>
      Transaction ${isCompleted ? 'Completed' : 'VOIDED'}
      <span style="margin-left:auto;font-size:.75rem;opacity:.7">ID #${sale.id}</span>
    </div>`;

  // Header block
  const headerBlock = `
    <div class="invoice-header-block">
      <div>
        <div class="invoice-shop-name">${escHtml(shop.name)}</div>
        <div class="invoice-shop-meta">
          ${shop.address ? escHtml(shop.address) + '<br>' : ''}
          ${shop.phone   ? escHtml(shop.phone)   + '<br>' : ''}
        </div>
      </div>
      <div class="invoice-badge-block">
        <div class="invoice-number">${escHtml(sale.invoice_number)}</div>
        <div class="invoice-date">${dateStr}<br>${timeStr}</div>
      </div>
    </div>`;

  // Meta cards
  const payMethod = sale.payment_method.charAt(0).toUpperCase() + sale.payment_method.slice(1);
  const metaGrid = `
    <div class="invoice-meta-grid">
      <div class="invoice-meta-card">
        <div class="invoice-meta-label">Cashier</div>
        <div class="invoice-meta-value">${escHtml(sale.cashier)}</div>
      </div>
      <div class="invoice-meta-card">
        <div class="invoice-meta-label">Payment</div>
        <div class="invoice-meta-value">${escHtml(payMethod)}</div>
      </div>
      <div class="invoice-meta-card">
        <div class="invoice-meta-label">Items</div>
        <div class="invoice-meta-value">${items.length} line(s)</div>
      </div>
      <div class="invoice-meta-card">
        <div class="invoice-meta-label">Total Units</div>
        <div class="invoice-meta-value">${items.reduce((s,i) => s + i.quantity, 0)}</div>
      </div>
    </div>`;

  // Items table
  const itemRows = items.map((item, idx) => `
    <tr>
      <td style="color:var(--text-muted)">${idx + 1}</td>
      <td><strong>${escHtml(item.product_name)}</strong></td>
      <td style="text-align:center">${item.quantity}</td>
      <td style="text-align:right;color:var(--text-muted);font-size:.8rem">${fmt(item.cost_price)}</td>
      <td style="text-align:right">${fmt(item.unit_price)}</td>
      <td style="text-align:right;font-weight:600">${fmt(item.line_total)}</td>
      <td style="text-align:right;color:var(--success);font-size:.8rem">${fmt(item.item_profit)}</td>
    </tr>`).join('');

  const itemsSection = `
    <div class="table-wrapper" style="margin-bottom:0">
      <table class="invoice-items-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Product</th>
            <th style="text-align:center">Qty</th>
            <th style="text-align:right">Cost</th>
            <th style="text-align:right">Unit Price</th>
            <th style="text-align:right">Total</th>
            <th style="text-align:right">Profit</th>
          </tr>
        </thead>
        <tbody>${itemRows}</tbody>
      </table>
    </div>`;

  // Totals
  const grossProfit = items.reduce((s, i) => s + i.item_profit, 0);
  const totalsSection = `
    <div class="invoice-totals">
      <div class="invoice-total-row">
        <span>Subtotal</span>
        <span>${fmt(sale.subtotal)}</span>
      </div>
      ${sale.vat_amount > 0 ? `
      <div class="invoice-total-row">
        <span>VAT (${sale.vat_percent}%)</span>
        <span>${fmt(sale.vat_amount)}</span>
      </div>` : ''}
      ${sale.discount_amount > 0 ? `
      <div class="invoice-total-row">
        <span>Discount</span>
        <span style="color:var(--danger)">− ${fmt(sale.discount_amount)}</span>
      </div>` : ''}
      <div class="invoice-total-row grand">
        <span>TOTAL</span>
        <span>${fmt(sale.total_amount)}</span>
      </div>
      <div class="invoice-total-row" style="margin-top:8px">
        <span>Amount Paid (${escHtml(payMethod)})</span>
        <span>${fmt(sale.amount_paid)}</span>
      </div>
      <div class="invoice-total-row">
        <span>Change</span>
        <span>${fmt(sale.change_amount)}</span>
      </div>
      <div class="invoice-total-row profit" style="margin-top:8px;padding-top:8px;border-top:1px solid var(--card-border)">
        <span>Gross Profit</span>
        <span>${fmt(grossProfit)}</span>
      </div>
    </div>`;

  const footerMsg = `<div class="invoice-footer-msg">${escHtml(shop.footer)}</div>`;

  document.getElementById('transactionModalBody').innerHTML =
    statusBanner + headerBlock + metaGrid + itemsSection + totalsSection + footerMsg;
}

function escHtml(str) {
  return String(str || '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function printInvoice() {
  const content = document.getElementById('transactionModalBody').innerHTML;
  const w = window.open('', '_blank', 'width=720,height=700');
  w.document.write(`
    <!DOCTYPE html><html><head>
    <title>Invoice</title>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
      :root { --primary-color:#1a2744; --accent-color:#f59e0b; --bg-color:#f0f2f5;
              --card-border:#e2e8f0; --text-primary:#1a202c; --text-secondary:#64748b;
              --text-muted:#94a3b8; --success:#22c55e; --danger:#ef4444; }
      * { box-sizing:border-box; margin:0; padding:0; }
      body { font-family:'DM Sans',sans-serif; padding:30px; font-size:13px; color:var(--text-primary); }
      .invoice-header-block { background:var(--primary-color); color:#fff; border-radius:10px; padding:18px 22px; display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:18px; }
      .invoice-shop-name { font-family:'Syne',sans-serif; font-size:1.1rem; font-weight:800; margin-bottom:4px; }
      .invoice-shop-meta { font-size:.75rem; opacity:.7; line-height:1.6; }
      .invoice-badge-block { text-align:right; }
      .invoice-number { font-family:monospace; font-size:.85rem; font-weight:600; background:rgba(255,255,255,.15); padding:4px 8px; border-radius:4px; display:inline-block; margin-bottom:5px; }
      .invoice-date { font-size:.75rem; opacity:.7; }
      .invoice-meta-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:8px; margin-bottom:16px; }
      .invoice-meta-card { background:#f8fafc; border:1px solid var(--card-border); border-radius:6px; padding:8px 10px; }
      .invoice-meta-label { font-size:.65rem; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); margin-bottom:2px; }
      .invoice-meta-value { font-family:'Syne',sans-serif; font-weight:700; font-size:.85rem; }
      .invoice-status-banner { display:flex; align-items:center; gap:8px; padding:8px 12px; border-radius:6px; margin-bottom:14px; font-size:.8rem; font-weight:500; }
      .invoice-status-banner.completed { background:rgba(34,197,94,.1); color:#15803d; border:1px solid rgba(34,197,94,.25); }
      .invoice-status-banner.voided { background:rgba(239,68,68,.1); color:#dc2626; border:1px solid rgba(239,68,68,.25); }
      table { width:100%; border-collapse:collapse; font-size:.82rem; margin-bottom:0; }
      thead th { background:var(--primary-color); color:#fff; padding:8px 10px; font-family:'Syne',sans-serif; font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; text-align:left; }
      tbody td { padding:7px 10px; border-bottom:1px solid var(--card-border); }
      .invoice-totals { margin-top:12px; border-top:2px solid var(--card-border); padding-top:10px; }
      .invoice-total-row { display:flex; justify-content:space-between; padding:3px 0; font-size:.82rem; color:#64748b; }
      .invoice-total-row.grand { font-family:'Syne',sans-serif; font-weight:800; font-size:1rem; color:var(--text-primary); padding-top:7px; margin-top:4px; border-top:1px solid var(--card-border); }
      .invoice-total-row.grand span:last-child { color:var(--accent-color); }
      .invoice-total-row.profit span:last-child { color:var(--success); font-weight:600; }
      .invoice-footer-msg { text-align:center; margin-top:14px; font-size:.75rem; color:var(--text-muted); padding-top:10px; border-top:1px dashed var(--card-border); }
    </style></head><body>${content}</body></html>`);
  w.document.close();
  setTimeout(() => { w.focus(); w.print(); }, 400);
}

// ── Copy Profit Summary ────────────────────────────────────
function copyProfitSummary(btn) {
  const cur = reportCurrency;

  const date          = btn.dataset.date;
  const sales         = parseFloat(btn.dataset.sales);
  const profit        = parseFloat(btn.dataset.profit);        // combined (POS + GCash)
  const posProfit     = parseFloat(btn.dataset.posProfit);     // POS product profit only
  const gcashProfit   = parseFloat(btn.dataset.gcashProfit);   // merged GCash profit (0 if none)
  const periodSales   = parseFloat(btn.dataset.periodSales);
  const periodProfit  = parseFloat(btn.dataset.periodProfit);

  function fmtCur(n) {
    return cur + Number(n).toLocaleString('en-PH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  let profitPart;
  if (gcashProfit > 0) {
    // Show breakdown: POS profit + GCash profit = combined
    profitPart = `PROFIT: ${fmtCur(profit)} (POS: ${fmtCur(posProfit)} + GCASH: ${fmtCur(gcashProfit)})`;
  } else {
    profitPart = `PROFIT: ${fmtCur(profit)}`;
  }

  const text =
    `${date} SALES: ${fmtCur(sales)} ${profitPart} ` +
    `TOTAL SALES: ${fmtCur(periodSales)} PROFIT: ${fmtCur(periodProfit)}`;

  function doFeedback() {
    const original = btn.innerHTML;
    btn.innerHTML  = '<i class="fa-solid fa-check"></i> Copied!';
    btn.style.borderColor = 'var(--success)';
    btn.style.color       = 'var(--success)';
    btn.disabled = true;
    showToast('Copied to clipboard!', 'success');
    setTimeout(() => {
      btn.innerHTML  = original;
      btn.style.borderColor = '';
      btn.style.color       = '';
      btn.disabled = false;
    }, 1800);
  }

  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text)
      .then(doFeedback)
      .catch(() => fallbackCopy(text, doFeedback));
  } else {
    fallbackCopy(text, doFeedback);
  }
}

function fallbackCopy(text, callback) {
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;pointer-events:none';
  document.body.appendChild(ta);
  ta.focus();
  ta.select();
  try {
    document.execCommand('copy');
    callback();
  } catch (e) {
    showToast('Could not copy — please copy manually.', 'error');
  }
  document.body.removeChild(ta);
}
</script>

<?php require_once 'includes/footer.php'; ?>
