<?php
// ============================================================
// dashboard.php — Main overview page
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_login();
define('PAGE_TITLE', 'Dashboard');

// ── Stats ──────────────────────────────────────────────────
$today = date('Y-m-d');

// Today's sales
$r = $conn->query("SELECT COALESCE(SUM(total_amount),0) AS total, COUNT(*) AS cnt
                   FROM sales WHERE DATE(created_at)='$today' AND status='completed'");
$today_sales = $r->fetch_assoc();

// Total products
$total_products = $conn->query("SELECT COUNT(*) AS c FROM products")->fetch_assoc()['c'];

// Low stock
$low_threshold = (int)get_setting($conn, 'low_stock_threshold', '10');
$r = $conn->prepare("SELECT COUNT(*) AS c FROM products WHERE stock_quantity <= ? AND stock_quantity > 0");
$r->bind_param('i', $low_threshold);
$r->execute();
$low_stock_count = $r->get_result()->fetch_assoc()['c'];

// Out of stock
$out_of_stock = $conn->query("SELECT COUNT(*) AS c FROM products WHERE stock_quantity = 0")->fetch_assoc()['c'];

// Weekly sales chart data (last 7 days)
$chart_data = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $label = date('D', strtotime("-$i days"));
    $res = $conn->prepare("SELECT COALESCE(SUM(total_amount),0) AS t FROM sales WHERE DATE(created_at)=? AND status='completed'");
    $res->bind_param('s', $date);
    $res->execute();
    $chart_data[] = ['label' => $label, 'value' => (float)$res->get_result()->fetch_assoc()['t']];
}

// Top products today
$top_products = $conn->query("
    SELECT si.product_name, SUM(si.quantity) AS qty, SUM(si.line_total) AS revenue
    FROM sale_items si
    JOIN sales s ON s.id = si.sale_id
    WHERE DATE(s.created_at)='$today' AND s.status='completed'
    GROUP BY si.product_id, si.product_name
    ORDER BY qty DESC
    LIMIT 5
");

// Low stock items
$low_items = $conn->prepare("
    SELECT product_name, category, stock_quantity
    FROM products
    WHERE stock_quantity <= ?
    ORDER BY stock_quantity ASC
    LIMIT 10
");
$low_items->bind_param('i', $low_threshold);
$low_items->execute();
$low_items_result = $low_items->get_result();

$currency = get_setting($conn, 'currency', '₱');
require_once 'includes/header.php';
?>

<div class="stats-grid">
  <div class="stat-card blue">
    <div class="stat-icon blue"><i class="fa-solid fa-peso-sign"></i></div>
    <div>
      <div class="stat-label">Today's Sales</div>
      <div class="stat-value"><?= h($currency) ?><?= number_format($today_sales['total'], 2) ?></div>
      <div class="stat-sub"><?= (int)$today_sales['cnt'] ?> transaction(s)</div>
    </div>
  </div>
  <div class="stat-card green">
    <div class="stat-icon green"><i class="fa-solid fa-boxes-stacked"></i></div>
    <div>
      <div class="stat-label">Total Products</div>
      <div class="stat-value"><?= (int)$total_products ?></div>
      <div class="stat-sub">In catalogue</div>
    </div>
  </div>
  <div class="stat-card amber">
    <div class="stat-icon amber"><i class="fa-solid fa-triangle-exclamation"></i></div>
    <div>
      <div class="stat-label">Low Stock</div>
      <div class="stat-value"><?= (int)$low_stock_count ?></div>
      <div class="stat-sub">≤ <?= $low_threshold ?> units</div>
    </div>
  </div>
  <div class="stat-card red">
    <div class="stat-icon red"><i class="fa-solid fa-ban"></i></div>
    <div>
      <div class="stat-label">Out of Stock</div>
      <div class="stat-value"><?= (int)$out_of_stock ?></div>
      <div class="stat-sub">Needs restocking</div>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;" class="responsive-2col">

  <!-- Sales Chart -->
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-chart-area" style="color:var(--info)"></i> 7-Day Sales</span>
    </div>
    <canvas id="salesChart" height="180"></canvas>
  </div>

  <!-- Top Products -->
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-star" style="color:var(--accent-color)"></i> Top Products Today</span>
    </div>
    <?php if ($top_products->num_rows === 0): ?>
      <p style="color:var(--text-muted);text-align:center;padding:30px 0">No sales recorded today.</p>
    <?php else: ?>
      <div class="table-wrapper">
        <table>
          <thead><tr><th>#</th><th>Product</th><th>Qty Sold</th><th>Revenue</th></tr></thead>
          <tbody>
          <?php $rank = 1; while ($r = $top_products->fetch_assoc()): ?>
            <tr>
              <td><span class="badge badge-info"><?= $rank++ ?></span></td>
              <td><?= h($r['product_name']) ?></td>
              <td><?= (int)$r['qty'] ?></td>
              <td><?= h($currency) ?><?= number_format($r['revenue'], 2) ?></td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Low Stock Alert -->
<?php if ($low_items_result->num_rows > 0): ?>
<div class="card">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-bell" style="color:var(--danger)"></i> Low Stock Alerts</span>
    <a href="products.php" class="btn btn-outline btn-sm">View All Products</a>
  </div>
  <div class="table-wrapper">
    <table>
      <thead><tr><th>Product</th><th>Category</th><th>Stock</th><th>Status</th></tr></thead>
      <tbody>
      <?php while ($row = $low_items_result->fetch_assoc()): ?>
        <tr>
          <td><?= h($row['product_name']) ?></td>
          <td><?= h($row['category']) ?></td>
          <td><strong><?= (int)$row['stock_quantity'] ?></strong></td>
          <td>
            <?php if ($row['stock_quantity'] == 0): ?>
              <span class="badge badge-danger low-stock-badge">Out of Stock</span>
            <?php else: ?>
              <span class="badge badge-warning low-stock-badge">Low Stock</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script>
// ── Mini Bar Chart ─────────────────────────────────────────
(function() {
  const data = <?= json_encode($chart_data) ?>;
  const canvas = document.getElementById('salesChart');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  const W = canvas.offsetWidth; canvas.width = W; canvas.height = 180;
  const max = Math.max(...data.map(d => d.value), 1);
  const pad = { top: 20, right: 14, bottom: 40, left: 50 };
  const bw = (W - pad.left - pad.right) / data.length;
  const style = getComputedStyle(document.documentElement);
  const accent = style.getPropertyValue('--accent-color').trim() || '#f59e0b';
  const primary = style.getPropertyValue('--primary-color').trim() || '#1a2744';
  const textMuted = style.getPropertyValue('--text-muted').trim() || '#94a3b8';

  data.forEach((d, i) => {
    const barH = ((d.value / max) * (180 - pad.top - pad.bottom));
    const x = pad.left + i * bw + bw * .15;
    const y = 180 - pad.bottom - barH;
    ctx.fillStyle = accent + 'dd';
    const r = 5;
    ctx.beginPath();
    ctx.moveTo(x + r, y); ctx.lineTo(x + bw * .7 - r, y);
    ctx.quadraticCurveTo(x + bw * .7, y, x + bw * .7, y + r);
    ctx.lineTo(x + bw * .7, 180 - pad.bottom);
    ctx.lineTo(x, 180 - pad.bottom);
    ctx.lineTo(x, y + r);
    ctx.quadraticCurveTo(x, y, x + r, y);
    ctx.closePath(); ctx.fill();

    ctx.fillStyle = textMuted;
    ctx.font = '11px DM Sans, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText(d.label, x + bw * .35, 180 - pad.bottom + 16);
    if (d.value > 0) {
      ctx.fillStyle = primary;
      ctx.font = 'bold 10px DM Sans';
      ctx.fillText('₱' + (d.value >= 1000 ? (d.value/1000).toFixed(1)+'k' : d.value.toFixed(0)), x + bw*.35, y - 5);
    }
  });
})();
</script>

<style>
@media (max-width: 768px) { .responsive-2col { grid-template-columns: 1fr !important; } }
</style>

<?php require_once 'includes/footer.php'; ?>
