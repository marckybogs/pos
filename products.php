<?php
// ============================================================
// products.php — CRUD + Import/Export
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_login();
define('PAGE_TITLE', 'Products');

$currency = get_setting($conn, 'currency', '₱');
$msg      = '';
$msg_type = 'success';

// ── Handle DELETE ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'delete' && is_admin()) {
        $id   = (int)$_POST['product_id'];
        $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $msg = 'Product deleted successfully.';

    } elseif ($action === 'save') {
        // Add or edit product
        $id       = (int)($_POST['product_id'] ?? 0);
        $name     = trim($_POST['product_name'] ?? '');
        $category = trim($_POST['category'] ?? 'Uncategorized');
        $barcode  = trim($_POST['barcode'] ?? '');
        $cost     = (float)($_POST['cost_price'] ?? 0);
        $sell     = (float)($_POST['selling_price'] ?? 0);
        $stock    = (int)($_POST['stock_quantity'] ?? 0);

        if ($name === '') {
            $msg = 'Product name is required.'; $msg_type = 'danger';
        } else {
            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE products SET product_name=?, category=?, barcode=?, cost_price=?, selling_price=?, stock_quantity=? WHERE id=?");
                $stmt->bind_param('sssddii', $name, $category, $barcode, $cost, $sell, $stock, $id);
            } else {
                $stmt = $conn->prepare("INSERT INTO products (product_name, category, barcode, cost_price, selling_price, stock_quantity) VALUES (?,?,?,?,?,?)");
                $stmt->bind_param('sssddi', $name, $category, $barcode, $cost, $sell, $stock);
            }
            if ($stmt->execute()) {
                $msg = $id > 0 ? 'Product updated.' : 'Product added.';
            } else {
                $msg = 'Error: ' . $conn->error; $msg_type = 'danger';
            }
        }
    }
}

// ── Filters ────────────────────────────────────────────────
$search   = trim($_GET['search'] ?? '');
$cat_filter = trim($_GET['category'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset   = ($page - 1) * $per_page;

$where = '1=1';
$params = [];
$types  = '';
if ($search !== '') {
    $like = "%$search%";
    $where .= " AND (product_name LIKE ? OR barcode LIKE ? OR category LIKE ?)";
    $params = array_merge($params, [$like, $like, $like]);
    $types .= 'sss';
}
if ($cat_filter !== '') {
    $where .= " AND category = ?";
    $params[] = $cat_filter;
    $types .= 's';
}

$count_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM products WHERE $where");
if ($types !== '') $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_rows  = $count_stmt->get_result()->fetch_assoc()['c'];
$total_pages = max(1, (int)ceil($total_rows / $per_page));

$stmt = $conn->prepare("SELECT * FROM products WHERE $where ORDER BY created_at DESC LIMIT ? OFFSET ?");
$all_params = array_merge($params, [$per_page, $offset]);
$all_types  = $types . 'ii';
$stmt->bind_param($all_types, ...$all_params);
$stmt->execute();
$products = $stmt->get_result();

// Categories for dropdown
$categories = $conn->query("SELECT DISTINCT category FROM products ORDER BY category");

$low_threshold = (int)get_setting($conn, 'low_stock_threshold', '10');

require_once 'includes/header.php';
?>

<?php if ($msg !== ''): ?>
<div class="alert alert-<?= h($msg_type) ?>"><i class="fa-solid fa-circle-info"></i> <?= h($msg) ?></div>
<?php endif; ?>

<!-- Top toolbar -->
<div class="card" style="margin-bottom:18px">
  <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
    <form method="GET" style="display:flex;gap:10px;flex:1;flex-wrap:wrap;align-items:center">
      <div class="search-bar" style="flex:1;min-width:200px">
        <i class="fa-solid fa-search search-icon"></i>
        <input type="text" name="search" class="form-control" placeholder="Search products…" value="<?= h($search) ?>">
      </div>
      <select name="category" class="form-control" style="width:170px">
        <option value="">All Categories</option>
        <?php while ($c = $categories->fetch_assoc()): ?>
          <option value="<?= h($c['category']) ?>" <?= $cat_filter === $c['category'] ? 'selected' : '' ?>><?= h($c['category']) ?></option>
        <?php endwhile; ?>
      </select>
      <button type="submit" class="btn btn-primary"><i class="fa-solid fa-search"></i> Filter</button>
      <?php if ($search || $cat_filter): ?>
      <a href="products.php" class="btn btn-outline"><i class="fa-solid fa-xmark"></i> Clear</a>
      <?php endif; ?>
    </form>

    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn btn-accent" onclick="openModal('productModal');resetForm()">
        <i class="fa-solid fa-plus"></i> Add Product
      </button>
      <button class="btn btn-outline" onclick="openModal('importModal')">
        <i class="fa-solid fa-file-import"></i> Import
      </button>
      <button class="btn btn-outline" onclick="exportAllProducts()">
        <i class="fa-solid fa-file-excel"></i> Export
      </button>
      <?php if (is_admin()): ?>
      <button class="btn btn-danger" onclick="openModal('deleteAllModal')">
        <i class="fa-solid fa-trash-can"></i> Delete All
      </button>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Products Table -->
<div class="card">
  <div class="card-header">
    <span class="card-title">Products <span class="badge badge-info" style="font-family:DM Sans"><?= $total_rows ?> total</span></span>
  </div>
  <div class="table-wrapper">
    <table id="productsTable">
      <thead>
        <tr>
          <th>#</th><th>Product Name</th><th>Category</th><th>Barcode</th>
          <th>Cost Price</th><th>Selling Price</th><th>Stock</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($products->num_rows === 0): ?>
        <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--text-muted)">No products found.</td></tr>
      <?php else: ?>
      <?php $counter = 1; while ($p = $products->fetch_assoc()): ?>
        <tr>
          <td><?= $counter++ ?></td>
          <td><strong><?= h($p['product_name']) ?></strong></td>
          <td><span class="badge badge-muted"><?= h($p['category']) ?></span></td>
          <td style="font-size:.8rem;color:var(--text-muted)"><?= h($p['barcode'] ?: '—') ?></td>
          <td><?= h($currency) ?><?= number_format($p['cost_price'], 2) ?></td>
          <td><?= h($currency) ?><?= number_format($p['selling_price'], 2) ?></td>
          <td>
            <?php if ($p['stock_quantity'] == 0): ?>
              <span class="badge badge-danger"><?= (int)$p['stock_quantity'] ?></span>
            <?php elseif ($p['stock_quantity'] <= $low_threshold): ?>
              <span class="badge badge-warning"><?= (int)$p['stock_quantity'] ?></span>
            <?php else: ?>
              <span class="badge badge-success"><?= (int)$p['stock_quantity'] ?></span>
            <?php endif; ?>
          </td>
          <td>
            <button class="btn btn-outline btn-sm btn-icon" title="Edit"
              onclick='editProduct(<?= json_encode($p) ?>)'>
              <i class="fa-solid fa-pen"></i>
            </button>
            <?php if (is_admin()): ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this product?')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm btn-icon" title="Delete">
                <i class="fa-solid fa-trash"></i>
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endwhile; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($total_pages > 1): ?>
  <div style="display:flex;justify-content:center;gap:6px;padding:16px">
    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
      <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&category=<?= urlencode($cat_filter) ?>"
         class="btn <?= $i === $page ? 'btn-primary' : 'btn-outline' ?> btn-sm"><?= $i ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ===== ADD/EDIT MODAL ===== -->
<div class="modal-overlay" id="productModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title" id="modalTitle">Add Product</span>
      <button class="modal-close" data-close-modal="productModal">&times;</button>
    </div>
    <form method="POST" action="">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="product_id" id="productId" value="0">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group" style="grid-column:span 2">
            <label class="form-label">Product Name *</label>
            <input type="text" name="product_name" id="fieldName" class="form-control" required placeholder="e.g. Coca-Cola 1.5L">
          </div>
          <div class="form-group">
            <label class="form-label">Category</label>
            <input type="text" name="category" id="fieldCategory" class="form-control" placeholder="e.g. Beverages" list="categoryList">
            <datalist id="categoryList">
              <?php
              $conn->query("SELECT DISTINCT category FROM products ORDER BY category")->data_seek(0);
              $cats2 = $conn->query("SELECT DISTINCT category FROM products ORDER BY category");
              while ($c = $cats2->fetch_assoc()) echo '<option value="' . h($c['category']) . '">';
              ?>
            </datalist>
          </div>
          <div class="form-group">
            <label class="form-label">Barcode (optional)</label>
            <input type="text" name="barcode" id="fieldBarcode" class="form-control" placeholder="Scan or type">
          </div>
          <div class="form-group">
            <label class="form-label">Cost Price</label>
            <input type="number" name="cost_price" id="fieldCost" class="form-control" step="0.01" min="0" value="0">
          </div>
          <div class="form-group">
            <label class="form-label">Selling Price *</label>
            <input type="number" name="selling_price" id="fieldSell" class="form-control" step="0.01" min="0" value="0" required>
          </div>
          <div class="form-group">
            <label class="form-label">Stock Quantity</label>
            <input type="number" name="stock_quantity" id="fieldStock" class="form-control" min="0" value="0">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" data-close-modal="productModal">Cancel</button>
        <button type="submit" class="btn btn-accent"><i class="fa-solid fa-floppy-disk"></i> Save Product</button>
      </div>
    </form>
  </div>
</div>

<!-- ===== IMPORT MODAL ===== -->
<div class="modal-overlay" id="importModal">
  <div class="modal" style="max-width:700px">
    <div class="modal-header">
      <span class="modal-title">Import Products from Excel / CSV</span>
      <button class="modal-close" data-close-modal="importModal">&times;</button>
    </div>
    <div class="modal-body">
      <div class="alert alert-info">
        <i class="fa-solid fa-circle-info"></i>
        Required columns: <strong>Product Name, Category, Cost Price, Selling Price, Stock Quantity</strong>
        &nbsp;<a href="<?= base_url() ?>assets/js/template_download.js" onclick="downloadTemplate(event)" style="text-decoration:underline">Download Template</a>
      </div>
      <div class="form-group">
        <label class="form-label">Select .xlsx or .csv file</label>
        <input type="file" id="importFile" class="form-control" accept=".xlsx,.csv">
      </div>
      <div id="importPreview" style="display:none">
        <h4 style="font-family:Syne;font-weight:700;margin-bottom:10px">Preview</h4>
        <div class="import-preview-table table-wrapper">
          <table id="previewTable">
            <thead><tr id="previewHead"></tr></thead>
            <tbody id="previewBody"></tbody>
          </table>
        </div>
        <p id="importSummary" style="margin-top:10px;font-size:.85rem;color:var(--text-secondary)"></p>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline" data-close-modal="importModal">Cancel</button>
      <button type="button" class="btn btn-outline" onclick="previewImport()"><i class="fa-solid fa-eye"></i> Preview</button>
      <button type="button" class="btn btn-accent" id="confirmImportBtn" disabled onclick="submitImport()">
        <i class="fa-solid fa-file-import"></i> Confirm Import
      </button>
    </div>
  </div>
</div>

<script>
let importData = [];

function resetForm() {
  document.getElementById('modalTitle').textContent = 'Add Product';
  document.getElementById('productId').value = '0';
  ['fieldName','fieldCategory','fieldBarcode','fieldCost','fieldSell','fieldStock'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = ['fieldCost','fieldSell','fieldStock'].includes(id) ? '0' : '';
  });
}

function editProduct(p) {
  document.getElementById('modalTitle').textContent = 'Edit Product';
  document.getElementById('productId').value  = p.id;
  document.getElementById('fieldName').value  = p.product_name;
  document.getElementById('fieldCategory').value = p.category;
  document.getElementById('fieldBarcode').value  = p.barcode || '';
  document.getElementById('fieldCost').value  = p.cost_price;
  document.getElementById('fieldSell').value  = p.selling_price;
  document.getElementById('fieldStock').value = p.stock_quantity;
  openModal('productModal');
}

// Import preview
function previewImport() {
  const file = document.getElementById('importFile').files[0];
  if (!file) { showToast('Please select a file.', 'warning'); return; }
  const ext = file.name.split('.').pop().toLowerCase();

  const reader = new FileReader();
  reader.onload = function(e) {
    let rows = [];
    if (ext === 'csv') {
      const text = e.target.result;
      rows = text.split('\n').map(r => r.split(',').map(c => c.replace(/^"|"$/g,'').trim()));
    } else {
      const wb = XLSX.read(e.target.result, { type: 'array' });
      const ws = wb.Sheets[wb.SheetNames[0]];
      rows = XLSX.utils.sheet_to_json(ws, { header: 1 });
    }

    rows = rows.filter(r => r.some(c => c !== ''));
    if (rows.length < 2) { showToast('File seems empty.', 'error'); return; }

    const header = rows[0].map(h => String(h).trim());
    const requiredCols = ['Product Name','Category','Cost Price','Selling Price','Stock Quantity'];
    const missing = requiredCols.filter(c => !header.includes(c));
    if (missing.length) {
      showToast('Missing columns: ' + missing.join(', '), 'error'); return;
    }

    const idx = {};
    requiredCols.forEach(c => idx[c] = header.indexOf(c));

    importData = [];
    const errors = [];
    for (let i = 1; i < rows.length; i++) {
      const row = rows[i];
      const name  = String(row[idx['Product Name']] || '').trim();
      const cat   = String(row[idx['Category']] || 'Uncategorized').trim();
      const cost  = parseFloat(row[idx['Cost Price']]);
      const sell  = parseFloat(row[idx['Selling Price']]);
      const stock = parseInt(row[idx['Stock Quantity']]);
      if (!name) continue;
      if (isNaN(cost) || isNaN(sell) || isNaN(stock)) {
        errors.push(`Row ${i+1}: Numeric values required for prices/stock.`); continue;
      }
      importData.push({ name, cat, cost, sell, stock });
    }

    // Render preview
    const head = document.getElementById('previewHead');
    head.innerHTML = requiredCols.map(c => `<th>${c}</th>`).join('');
    const tbody = document.getElementById('previewBody');
    tbody.innerHTML = importData.slice(0, 10).map(d =>
      `<tr><td>${d.name}</td><td>${d.cat}</td><td>${d.cost}</td><td>${d.sell}</td><td>${d.stock}</td></tr>`
    ).join('');

    const summary = document.getElementById('importSummary');
    summary.innerHTML = `✅ ${importData.length} valid rows found${errors.length ? ` | ⚠️ ${errors.length} skipped` : ''}.`;
    document.getElementById('importPreview').style.display = 'block';
    document.getElementById('confirmImportBtn').disabled = importData.length === 0;
    if (errors.length) showToast(errors[0], 'warning');
  };
  ext === 'csv' ? reader.readAsText(file) : reader.readAsArrayBuffer(file);
}

function submitImport() {
  if (!importData.length) return;
  const btn = document.getElementById('confirmImportBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Importing…';

  fetch('import_products.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(importData)
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      showToast(`Imported ${data.imported} product(s). ${data.skipped} duplicate(s) skipped.`, 'success');
      setTimeout(() => location.reload(), 1500);
    } else {
      showToast('Import error: ' + data.message, 'error');
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-file-import"></i> Confirm Import';
    }
  })
  .catch(() => {
    showToast('Network error during import.', 'error');
    btn.disabled = false;
  });
}

// ── Export ALL products (ignores filters & pagination) ─────
function exportAllProducts() {
  fetch('export_products.php')
    .then(r => r.json())
    .then(data => {
      if (!data.success) { showToast('Export failed: ' + data.message, 'error'); return; }

      const wb = XLSX.utils.book_new();
      // Build rows: header + data
      const header = ['Product No.', 'Product Name', 'Category', 'Barcode', 'Cost Price', 'Selling Price', 'Stock Quantity'];
      const rows = data.products.map((p, i) => [
        i + 1,
        p.product_name,
        p.category,
        p.barcode || '',
        parseFloat(p.cost_price),
        parseFloat(p.selling_price),
        parseInt(p.stock_quantity)
      ]);

      const ws = XLSX.utils.aoa_to_sheet([header, ...rows]);

      // Style header row: bold, dark background, white text
      const range = XLSX.utils.decode_range(ws['!ref']);
      for (let C = range.s.c; C <= range.e.c; C++) {
        const cell = ws[XLSX.utils.encode_cell({ r: 0, c: C })];
        if (cell) cell.s = {
          font: { bold: true, color: { rgb: 'FFFFFF' } },
          fill: { fgColor: { rgb: '1A2744' } }
        };
      }

      // Column widths
      ws['!cols'] = [
        { wch: 12 }, { wch: 30 }, { wch: 18 }, { wch: 16 },
        { wch: 13 }, { wch: 15 }, { wch: 15 }
      ];

      XLSX.utils.book_append_sheet(wb, ws, 'Products');
      XLSX.writeFile(wb, 'products_export_' + new Date().toISOString().slice(0,10) + '.xlsx');
      showToast(`Exported ${data.products.length} product(s).`, 'success');
    })
    .catch(() => showToast('Network error during export.', 'error'));
}

function downloadTemplate(e) {
  e.preventDefault();
  const wb = XLSX.utils.book_new();
  const ws = XLSX.utils.aoa_to_sheet([
    ['Product Name','Category','Cost Price','Selling Price','Stock Quantity'],
    ['Sample Product','Beverages', 10.00, 15.00, 50]
  ]);
  ws['!cols'] = [{wch:25},{wch:20},{wch:12},{wch:14},{wch:14}];
  XLSX.utils.book_append_sheet(wb, ws, 'Products');
  XLSX.writeFile(wb, 'products_import_template.xlsx');
}
</script>

<!-- ===== DELETE ALL MODAL ===== -->
<?php if (is_admin()): ?>
<div class="modal-overlay" id="deleteAllModal">
  <div class="modal" style="max-width:460px">
    <div class="modal-header" style="border-bottom:2px solid var(--danger)">
      <span class="modal-title" style="color:var(--danger)">
        <i class="fa-solid fa-triangle-exclamation"></i> Delete All Products
      </span>
      <button class="modal-close" data-close-modal="deleteAllModal" onclick="resetDeleteAllModal()">&times;</button>
    </div>
    <div class="modal-body">

      <!-- Step 1: Warning -->
      <div id="deleteStep1">
        <div class="alert alert-danger" style="margin-bottom:16px">
          <i class="fa-solid fa-circle-exclamation"></i>
          <div>
            <strong>This action cannot be undone.</strong><br>
            All <strong><?= number_format($total_rows) ?> product(s)</strong> will be permanently deleted from the database and will no longer appear in the POS.
          </div>
        </div>
        <p style="font-size:.9rem;color:var(--text-secondary);margin-bottom:16px">
          This will also clear product references in sales history (invoice line items will retain snapshots, but the product catalogue will be empty).
        </p>
        <p style="font-size:.875rem;font-weight:600;color:var(--text-primary)">To continue, you must verify your identity.</p>
      </div>

      <!-- Step 2: Auth form -->
      <div id="deleteStep2" style="margin-top:14px">
        <div class="form-group">
          <label class="form-label">Your Username</label>
          <input type="text" id="deleteAuthUser" class="form-control" placeholder="Enter your username" autocomplete="off">
        </div>
        <div class="form-group">
          <label class="form-label">Your Password</label>
          <div class="search-bar">
            <input type="password" id="deleteAuthPass" class="form-control" placeholder="Enter your password" autocomplete="new-password">
            <button type="button" onclick="toggleDeletePassVis()" class="search-icon" style="pointer-events:auto;background:none;border:none;cursor:pointer;color:var(--text-muted)" title="Show/hide password">
              <i class="fa-solid fa-eye" id="deletePassEyeIcon"></i>
            </button>
          </div>
        </div>
        <div id="deleteAuthError" class="alert alert-danger" style="display:none;margin-top:8px">
          <i class="fa-solid fa-circle-xmark"></i> <span id="deleteAuthErrorMsg">Invalid credentials.</span>
        </div>
      </div>

    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" data-close-modal="deleteAllModal" onclick="resetDeleteAllModal()">Cancel</button>
      <button class="btn btn-danger" id="confirmDeleteAllBtn" onclick="confirmDeleteAll()">
        <i class="fa-solid fa-trash-can"></i> Verify &amp; Delete All
      </button>
    </div>
  </div>
</div>

<script>
function resetDeleteAllModal() {
  document.getElementById('deleteAuthUser').value = '';
  document.getElementById('deleteAuthPass').value = '';
  document.getElementById('deleteAuthError').style.display = 'none';
  const btn = document.getElementById('confirmDeleteAllBtn');
  btn.disabled = false;
  btn.innerHTML = '<i class="fa-solid fa-trash-can"></i> Verify &amp; Delete All';
}

function toggleDeletePassVis() {
  const input = document.getElementById('deleteAuthPass');
  const icon  = document.getElementById('deletePassEyeIcon');
  if (input.type === 'password') {
    input.type = 'text';
    icon.className = 'fa-solid fa-eye-slash';
  } else {
    input.type = 'password';
    icon.className = 'fa-solid fa-eye';
  }
}

function confirmDeleteAll() {
  const username = document.getElementById('deleteAuthUser').value.trim();
  const password = document.getElementById('deleteAuthPass').value;
  const errBox   = document.getElementById('deleteAuthError');
  const errMsg   = document.getElementById('deleteAuthErrorMsg');
  const btn      = document.getElementById('confirmDeleteAllBtn');

  if (!username || !password) {
    errMsg.textContent = 'Please enter your username and password.';
    errBox.style.display = 'flex';
    return;
  }

  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Verifying…';
  errBox.style.display = 'none';

  fetch('delete_all_products.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username, password })
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      closeModal('deleteAllModal');
      showToast(`All ${data.deleted} product(s) deleted successfully.`, 'success');
      setTimeout(() => location.reload(), 1400);
    } else {
      errMsg.textContent = data.message || 'Authentication failed.';
      errBox.style.display = 'flex';
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-trash-can"></i> Verify &amp; Delete All';
    }
  })
  .catch(() => {
    errMsg.textContent = 'Network error. Please try again.';
    errBox.style.display = 'flex';
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-trash-can"></i> Verify &amp; Delete All';
  });
}
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
