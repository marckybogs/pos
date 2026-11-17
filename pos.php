<?php
// ============================================================
// pos.php — Point of Sale Interface
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_login();
define('PAGE_TITLE', 'Point of Sale');

$currency    = get_setting($conn, 'currency', '₱');
$vat_percent = (float)get_setting($conn, 'vat_percent', '12');
$shop_name   = get_setting($conn, 'shop_name', 'QuickMart POS');
$receipt_footer = get_setting($conn, 'receipt_footer', 'Thank you!');

// Fetch categories for filter
$categories = $conn->query("SELECT DISTINCT category FROM products WHERE stock_quantity > 0 ORDER BY category");

// Fetch all products (we'll filter client-side for speed)
$products_result = $conn->query("SELECT id, product_name, category, selling_price, stock_quantity FROM products ORDER BY product_name");
$all_products = [];
while ($p = $products_result->fetch_assoc()) $all_products[] = $p;

require_once 'includes/header.php';
?>

<style>
/* ── POS full-viewport layout ── */
.page-content {
  padding: 0 !important;
  height: calc(100vh - var(--topbar-h));
  overflow: hidden;
}

/* Fixed cart panel — always visible on right regardless of sidebar state */
.pos-right {
  position: fixed;
  top: var(--topbar-h);
  right: 0;
  bottom: 0;
  width: 340px;
  background: var(--card-bg);
  border-left: 2px solid var(--card-border);
  display: flex;
  flex-direction: column;
  z-index: 50;
  transition: background var(--transition);
}

/* Left panel fills remaining space beside the fixed cart */
.pos-wrapper {
  display: flex;
  height: 100%;
}
.pos-left {
  flex: 1;
  padding: 0;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  margin-right: 340px; /* match .pos-right width */
}

/* Sticky header: search + category filter */
.pos-left-header {
  position: sticky;
  top: 0;
  z-index: 40;
  background: var(--bg-color);
  padding: 14px 16px 10px;
  border-bottom: 1px solid var(--card-border);
  flex-shrink: 0;
}

/* Scrollable product grid area */
.pos-left-body {
  flex: 1;
  overflow-y: auto;
  padding: 14px 16px;
}
.pos-left-body::-webkit-scrollbar { width: 4px; }
.pos-left-body::-webkit-scrollbar-thumb { background: var(--card-border); border-radius: 4px; }

.product-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
  gap: 10px;
}

/* Cart internals */
.cart-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 14px 16px;
  border-bottom: 1px solid var(--card-border);
  flex-shrink: 0;
}
.cart-items {
  flex: 1;
  overflow-y: auto;
  padding: 8px 10px;
}
.cart-items::-webkit-scrollbar { width: 4px; }
.cart-items::-webkit-scrollbar-thumb { background: var(--card-border); border-radius: 4px; }

.cart-actions {
  padding: 12px 14px;
  border-top: 2px solid var(--card-border);
  background: var(--card-bg);
  flex-shrink: 0;
  display: flex;
  gap: 8px;
}

/* Category filter pills */
.cat-filter { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
.cat-btn {
  padding: 4px 12px;
  border-radius: 20px;
  border: 1px solid var(--card-border);
  background: transparent;
  color: var(--text-secondary);
  font-size: .78rem;
  cursor: pointer;
  transition: all .12s;
  white-space: nowrap;
}
.cat-btn:hover, .cat-btn.active {
  background: var(--primary-color);
  color: #fff;
  border-color: var(--primary-color);
}

/* Editable quantity input */
.qty-input {
  width: 56px;
  height: 26px;
  text-align: center;
  font-weight: 700;
  font-size: .85rem;
  font-family: 'DM Sans', sans-serif;
  background: var(--bg-color);
  border: 1px solid var(--card-border);
  border-radius: 6px;
  color: var(--text-primary);
  padding: 0 4px;
  /* Remove browser spinner arrows */
  -moz-appearance: textfield;
}
.qty-input::-webkit-inner-spin-button,
.qty-input::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
.qty-input:focus {
  outline: none;
  border-color: var(--accent-color);
  box-shadow: 0 0 0 2px rgba(245,158,11,.2);
}

/* ── Cart item base transition ── */
.cart-item {
  transition: background-color .3s ease, border-color .3s ease, box-shadow .3s ease, opacity .3s ease, transform .3s ease;
  border: 2px solid transparent;
  border-radius: 8px;
}

/* ── Flash: added / qty increased (green) ── */
@keyframes flashAdd {
  0%   { background: rgba(34,197,94,.22); border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.15); }
  60%  { background: rgba(34,197,94,.12); border-color: #22c55e; }
  100% { background: transparent;          border-color: transparent; box-shadow: none; }
}
.flash-add {
  animation: flashAdd 1.2s ease forwards;
}

/* ── Flash: qty decreased (amber) ── */
@keyframes flashDecrease {
  0%   { background: rgba(245,158,11,.2); border-color: #f59e0b; box-shadow: 0 0 0 3px rgba(245,158,11,.12); }
  60%  { background: rgba(245,158,11,.1); border-color: #f59e0b; }
  100% { background: transparent;          border-color: transparent; box-shadow: none; }
}
.flash-decrease {
  animation: flashDecrease 1.0s ease forwards;
}

/* ── Flash: about to be removed (red) ── */
@keyframes flashRemove {
  0%   { background: rgba(239,68,68,.2); border-color: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,.12); }
  100% { background: rgba(239,68,68,.1); border-color: #ef4444; }
}
.flash-remove {
  animation: flashRemove .4s ease forwards;
}

/* ── Exit: collapse + fade out ── */
@keyframes cartItemExit {
  0%   { opacity: 1; transform: translateX(0);    max-height: 80px; margin-bottom: 4px; }
  100% { opacity: 0; transform: translateX(30px); max-height: 0;    margin-bottom: 0;  padding: 0; }
}
.cart-item-exit {
  overflow: hidden;
  animation: cartItemExit .32s ease forwards;
}

/* ── Enter: slide down from top ── */
.cart-item-enter {
  opacity: 0;
  transform: translateY(-12px);
}
.cart-item-enter-active {
  opacity: 1;
  transform: translateY(0);
  transition: opacity .35s ease, transform .35s ease;
}

/* Mobile: stack vertically */
@media (max-width: 700px) {
  .pos-right { position: static; width: 100%; border-left: none; border-top: 2px solid var(--card-border); height: 420px; }
  .pos-left { margin-right: 0; }
  .pos-wrapper { flex-direction: column; height: auto; }
  .page-content { height: auto; overflow: auto; }
}
</style>

<?php
// Check for pending GCash/Maya profit carried from gcash_maya.php
$gcash_pending_profit  = (float)($_SESSION['gcash_pending_profit']  ?? 0);
$gcash_pending_session = (int)  ($_SESSION['gcash_pending_session'] ?? 0);
?>

<div class="pos-wrapper">

  <!-- LEFT: Search header (sticky) + Product Grid (scrollable) -->
  <div class="pos-left">
    <div class="pos-left-header">
      <div class="search-bar">
        <i class="fa-solid fa-search search-icon"></i>
        <input type="text" id="posSearch" class="form-control" placeholder="Search products or scan barcode…">
      </div>
      <?php
      // Single source of truth for categories — used by pills AND the OTHERS form select
      $cats_r = $conn->query("
          SELECT DISTINCT category
          FROM products
          WHERE category != 'OTHERS'
            AND category IS NOT NULL
            AND category != ''
          ORDER BY category
      ");
      $pos_cats = [];
      while ($c = $cats_r->fetch_assoc()) $pos_cats[] = $c['category'];
      ?>
      <script>
      // POS_CATEGORIES: used by the OTHERS form <select> (built in buildOthersSelect)
      const POS_CATEGORIES = <?= json_encode($pos_cats) ?>;
      </script>

      <div class="cat-filter">
        <button class="cat-btn active" data-cat="" onclick="selectCatBtn(this, '')">All</button>
        <?php foreach ($pos_cats as $cat): ?>
        <button class="cat-btn" data-cat="<?= h($cat) ?>"
                onclick="selectCatBtn(this, '<?= h($cat) ?>')"><?= h($cat) ?></button>
        <?php endforeach; ?>
        <!-- OTHERS: permanent system button — opens quick-create modal -->
        <button class="cat-btn" id="othersBtn"
                onclick="openOthersModal(event)"
                style="border-color:var(--accent-color);color:var(--accent-color)">
          <i class="fa-solid fa-plus" style="font-size:.75rem"></i> OTHERS
        </button>
      </div>
    </div>

    <div class="pos-left-body">
      <!-- No-results OTHERS suggestion — shown when search finds nothing -->
      <div id="othersSearchSuggestion" style="display:none;flex-direction:column;
           align-items:center;justify-content:center;padding:40px 24px;text-align:center">
        <i class="fa-solid fa-magnifying-glass" style="font-size:2rem;color:var(--text-muted);opacity:.4;margin-bottom:12px"></i>
        <p style="color:var(--text-muted);margin-bottom:4px;font-size:.9rem">
          No product found for "<strong id="othersSearchTerm"></strong>"
        </p>
        <button class="btn btn-accent" style="margin-top:14px;gap:8px"
                onclick="openOthersFromSearch()">
          <i class="fa-solid fa-plus"></i>
          Add as OTHERS
        </button>
      </div>

      <div class="product-grid" id="productGrid">
        <?php foreach ($all_products as $p): ?>
        <div class="product-tile <?= $p['stock_quantity'] <= 0 ? 'out-of-stock' : '' ?>"
             data-id="<?= (int)$p['id'] ?>"
             data-name="<?= h($p['product_name']) ?>"
             data-price="<?= (float)$p['selling_price'] ?>"
             data-stock="<?= (int)$p['stock_quantity'] ?>"
             data-cat="<?= h($p['category']) ?>"
             onclick="addToCart(this)">
          <span class="tile-category"><?= h($p['category']) ?></span>
          <div class="tile-name"><?= h($p['product_name']) ?></div>
          <div class="tile-price"><?= h($currency) ?><?= number_format($p['selling_price'], 2) ?></div>
          <div class="tile-stock"><?= $p['stock_quantity'] > 0 ? 'Stock: ' . (int)$p['stock_quantity'] : 'OUT OF STOCK' ?></div>
        </div>
        <?php endforeach; ?>
      </div><!-- /productGrid -->
    </div><!-- /pos-left-body -->
  </div><!-- /pos-left -->

  <!-- RIGHT: Cart (fixed panel) -->
  <div class="pos-right">
    <div class="cart-header">
      <span class="cart-title"><i class="fa-solid fa-cart-shopping" style="color:var(--accent-color)"></i> Cart</span>
      <button class="btn btn-outline btn-sm" onclick="clearCart()"><i class="fa-solid fa-trash"></i> Clear</button>
    </div>

    <div class="cart-items" id="cartItems">
      <div class="cart-empty">
        <i class="fa-solid fa-cart-shopping"></i>
        <p>Cart is empty</p>
        <small>Click a product to add</small>
      </div>
    </div>

    <div class="cart-summary" id="cartSummary" style="display:none">
      <div class="summary-row">
        <span class="summary-label">Subtotal</span>
        <span class="summary-value" id="summarySubtotal">₱0.00</span>
      </div>
      <div class="summary-row">
        <span class="summary-label">
          VAT
          <input type="number" id="vatInput" value="<?= $vat_percent ?>" min="0" max="100"
                 style="width:50px;padding:2px 5px;border:1px solid var(--card-border);border-radius:4px;font-size:.8rem;background:var(--input-bg);color:var(--text-primary)"
                 oninput="recalcCart()">%
        </span>
        <span class="summary-value" id="summaryVat">₱0.00</span>
      </div>
      <div class="summary-row">
        <span class="summary-label">
          Discount
          <input type="number" id="discountInput" value="0" min="0"
                 style="width:60px;padding:2px 5px;border:1px solid var(--card-border);border-radius:4px;font-size:.8rem;background:var(--input-bg);color:var(--text-primary)"
                 oninput="recalcCart()">
        </span>
        <span class="summary-value" id="summaryDiscount">₱0.00</span>
      </div>
      <div class="summary-row total">
        <span class="summary-label">TOTAL</span>
        <span class="summary-value" id="summaryTotal">₱0.00</span>
      </div>
    </div>

    <div class="cart-actions">
      <button class="btn btn-outline" onclick="clearCart()" style="flex:0 0 auto">
        <i class="fa-solid fa-xmark"></i>
      </button>
      <button class="btn btn-accent" style="flex:1;justify-content:center" id="checkoutBtn" disabled onclick="openPaymentModal()">
        <i class="fa-solid fa-cash-register"></i> Checkout
      </button>
    </div>
  </div><!-- /pos-right -->

</div><!-- /pos-wrapper -->

<!-- ===== PAYMENT MODAL ===== -->
<div class="modal-overlay" id="paymentModal">
  <div class="modal payment-modal">
    <div class="modal-header">
      <span class="modal-title"><i class="fa-solid fa-money-bill-wave" style="color:var(--success)"></i> Process Payment</span>
      <button class="modal-close" data-close-modal="paymentModal">&times;</button>
    </div>
    <div class="modal-body">
      <div class="payment-display">
        <div class="pd-label">Amount Due</div>
        <div class="pd-amount" id="pdTotal">₱0.00</div>
        <div class="pd-change">Change: <span id="pdChange">₱0.00</span></div>
      </div>

      <div class="form-group">
        <label class="form-label">Amount Tendered</label>
        <input type="number" id="amountPaid" class="form-control" placeholder="0.00" step="0.01" min="0"
               style="font-size:1.3rem;font-weight:700;text-align:right" oninput="calcChange()">
      </div>

      <div class="payment-keypad">
        <?php foreach ([1,2,3,4,5,6,7,8,9,'.',0,'⌫'] as $k): ?>
        <button class="key-btn <?= $k === '⌫' ? 'key-clear' : '' ?>" onclick="keypadInput('<?= $k ?>')"><?= $k ?></button>
        <?php endforeach; ?>
      </div>

      <div class="form-group" style="margin-top:14px">
        <label class="form-label">Quick Amount</label>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn btn-sm" onclick="setExactAmount()"
            style="background:var(--accent-color);color:#1a2744;font-weight:700;border:none">
            <i class="fa-solid fa-bullseye"></i> Exact
          </button>
          <?php foreach ([20,50,100,200,500,1000] as $amt): ?>
          <button class="btn btn-outline btn-sm" onclick="setAmount(<?= $amt ?>)"><?= h($currency) ?><?= $amt ?></button>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Payment Method</label>
        <select id="paymentMethod" class="form-control">
          <option value="cash">💵 Cash</option>
          <option value="card">💳 Card</option>
          <option value="gcash">📱 GCash</option>
          <option value="other">Other</option>
        </select>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline" data-close-modal="paymentModal">Cancel</button>
      <button type="button" class="btn btn-success" id="completeSaleBtn" disabled onclick="completeSale()">
        <i class="fa-solid fa-check"></i> Complete Sale
      </button>
    </div>
  </div>
</div>

<!-- ===== RECEIPT MODAL ===== -->
<div class="modal-overlay" id="receiptModal">
  <div class="modal" style="max-width:400px">
    <div class="modal-header">
      <span class="modal-title"><i class="fa-solid fa-receipt" style="color:var(--success)"></i> Sale Complete</span>
      <button class="modal-close" data-close-modal="receiptModal" onclick="newSale()">&times;</button>
    </div>
    <div class="modal-body">
      <div id="receiptContent" class="receipt"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="printReceipt()"><i class="fa-solid fa-print"></i> Print</button>
      <button class="btn btn-accent" onclick="newSale()"><i class="fa-solid fa-plus"></i> New Sale</button>
    </div>
  </div>
</div>

<script>
// ── Cart State ─────────────────────────────────────────────
// cart: { id: { name, price, qty, stock } }
// cartOrder: array of IDs in display order (newest first)
let cart      = {};
let cartOrder = [];   // tracks insertion order, newest at index 0
const currency = '<?= $currency ?>';

// ── GCash/Maya pending profit (carried from gcash_maya.php) ──
const GCASH_PENDING_PROFIT  = <?= json_encode($gcash_pending_profit) ?>;
const GCASH_PENDING_SESSION = <?= json_encode($gcash_pending_session) ?>;

// ── Highlight types ────────────────────────────────────────
const FLASH_ADD      = 'flash-add';      // green  — new item or qty up
const FLASH_DECREASE = 'flash-decrease'; // amber  — qty down
const FLASH_REMOVE   = 'flash-remove';   // red    — about to be removed

function addToCart(tile) {
  if (tile.classList.contains('out-of-stock')) return;
  const id    = String(tile.dataset.id);
  const name  = tile.dataset.name;
  const price = parseFloat(tile.dataset.price);
  const stock = parseInt(tile.dataset.stock, 10);

  const isNew = !cart[id];

  if (!isNew) {
    if (cart[id].qty >= cart[id].stock) {
      showToast('Insufficient stock.', 'warning'); return;
    }
    cart[id].qty++;
    // Move existing item to top of order
    cartOrder = [id, ...cartOrder.filter(k => k !== id)];
    renderCart(id, FLASH_ADD);
  } else {
    cart[id] = { name, price, qty: 1, stock };
    cartOrder.unshift(id);   // new item goes to front
    renderCart(id, FLASH_ADD);
  }

  // Tile pulse
  tile.style.transform = 'scale(.95)';
  setTimeout(() => tile.style.transform = '', 100);
}

// ── renderCart ─────────────────────────────────────────────
// flashId  : id of item to animate (null = no flash)
// flashType: FLASH_ADD | FLASH_DECREASE | FLASH_REMOVE
function renderCart(flashId = null, flashType = null) {
  const container = document.getElementById('cartItems');
  const summary   = document.getElementById('cartSummary');
  const checkBtn  = document.getElementById('checkoutBtn');

  if (cartOrder.length === 0) {
    container.innerHTML = `
      <div class="cart-empty">
        <i class="fa-solid fa-cart-shopping"></i>
        <p>Cart is empty</p>
        <small>Click a product to add</small>
      </div>`;
    summary.style.display = 'none';
    checkBtn.disabled = true;
    return;
  }

  summary.style.display = 'block';
  checkBtn.disabled = false;

  container.innerHTML = cartOrder.map(id => {
    const item   = cart[id];
    if (!item) return '';
    const total  = item.price * item.qty;
    const safeId = id.replace(/[^0-9]/g, '');
    return `
      <div class="cart-item" data-cart-id="${safeId}">
        <div style="flex:1;min-width:0">
          <div class="cart-item-name">${escapeHtml(item.name)}</div>
          <div class="cart-item-price">${currency}${item.price.toFixed(2)} each</div>
        </div>
        <div class="qty-control">
          <button class="qty-btn" onclick="changeQty('${safeId}',-1)">−</button>
          <input
            type="number"
            class="qty-input"
            value="${item.qty}"
            min="1"
            max="${item.stock}"
            data-id="${safeId}"
            data-prev="${item.qty}"
            onchange="setQtyFromInput(this)"
            oninput="setQtyFromInput(this)"
            onclick="this.select()"
          >
          <button class="qty-btn" onclick="changeQty('${safeId}',1)">+</button>
        </div>
        <div class="cart-item-total">${currency}${total.toFixed(2)}</div>
        <button class="cart-item-remove" onclick="removeFromCart('${safeId}')" title="Remove">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>`;
  }).join('');

  recalcCart();

  // Apply flash animation to the target item after DOM paint
  if (flashId !== null && flashType !== null) {
    const safeId  = String(flashId).replace(/[^0-9]/g, '');
    const el      = container.querySelector(`[data-cart-id="${safeId}"]`);
    if (el) {
      // Trigger slide-in for brand new items (first in order)
      if (flashType === FLASH_ADD && cartOrder[0] === String(flashId)) {
        el.classList.add('cart-item-enter');
        requestAnimationFrame(() => {
          requestAnimationFrame(() => el.classList.add('cart-item-enter-active'));
        });
        setTimeout(() => {
          el.classList.remove('cart-item-enter', 'cart-item-enter-active');
        }, 400);
      }
      // Flash highlight
      el.classList.add(flashType);
      setTimeout(() => el.classList.remove(flashType), flashType === FLASH_REMOVE ? 500 : 1200);
    }
  }
}

function escapeHtml(str) {
  return String(str)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── changeQty ──────────────────────────────────────────────
function changeQty(id, delta) {
  id = String(id);
  if (!cart[id]) return;
  const newQty = cart[id].qty + delta;

  if (newQty <= 0) {
    // Flash red → then remove
    animateRemove(id);
    return;
  }
  if (newQty > cart[id].stock) {
    showToast('Insufficient stock.', 'warning'); return;
  }

  cart[id].qty = newQty;
  const flashType = delta > 0 ? FLASH_ADD : FLASH_DECREASE;
  renderCart(id, flashType);
}

// ── setQtyFromInput ────────────────────────────────────────
function setQtyFromInput(input) {
  const id   = String(input.dataset.id);
  const prev = parseInt(input.dataset.prev, 10) || 1;
  if (!cart[id]) return;

  let val = Math.floor(parseFloat(input.value));
  if (isNaN(val) || input.value === '') val = 1;
  if (val < 1) { showToast('Quantity must be at least 1.', 'warning'); val = 1; }
  if (val > cart[id].stock) {
    showToast(`Only ${cart[id].stock} unit(s) in stock.`, 'warning');
    val = cart[id].stock;
  }

  const flashType = val > prev ? FLASH_ADD : val < prev ? FLASH_DECREASE : null;
  cart[id].qty       = val;
  input.value        = val;
  input.dataset.prev = val;

  // Update line total in-place to preserve input focus
  const itemRow = input.closest('.cart-item');
  const totalEl = itemRow ? itemRow.querySelector('.cart-item-total') : null;
  if (totalEl) totalEl.textContent = currency + (cart[id].price * val).toFixed(2);

  // Flash the row without full re-render
  if (flashType && itemRow) {
    itemRow.classList.remove(FLASH_ADD, FLASH_DECREASE, FLASH_REMOVE);
    void itemRow.offsetWidth; // force reflow to restart animation
    itemRow.classList.add(flashType);
    setTimeout(() => itemRow.classList.remove(flashType), 1200);
  }

  recalcCart();
}

// ── animateRemove ──────────────────────────────────────────
// Flash red, then fade+collapse the row, then remove from state
function animateRemove(id) {
  id = String(id);
  const safeId = id.replace(/[^0-9]/g, '');
  const el = document.querySelector(`[data-cart-id="${safeId}"]`);

  function doRemove() {
    delete cart[id];
    cartOrder = cartOrder.filter(k => k !== id);
    renderCart();
  }

  if (!el) { doRemove(); return; }

  el.classList.add(FLASH_REMOVE);
  setTimeout(() => {
    el.classList.add('cart-item-exit');
    setTimeout(doRemove, 320);
  }, 400);
}

function removeFromCart(id) {
  animateRemove(String(id));
}

function clearCart() {
  if (!cartOrder.length) return;
  cart      = {};
  cartOrder = [];
  renderCart();
}

function recalcCart() {
  const subtotal = Object.values(cart).reduce((s, i) => s + i.price * i.qty, 0);
  const vat_pct  = parseFloat(document.getElementById('vatInput').value) || 0;
  const discount = parseFloat(document.getElementById('discountInput').value) || 0;
  const vat_amt  = subtotal * vat_pct / 100;
  const total    = Math.max(0, subtotal + vat_amt - discount);

  document.getElementById('summarySubtotal').textContent = currency + subtotal.toFixed(2);
  document.getElementById('summaryVat').textContent      = currency + vat_amt.toFixed(2);
  document.getElementById('summaryDiscount').textContent = currency + discount.toFixed(2);
  document.getElementById('summaryTotal').textContent    = currency + total.toFixed(2);
  return { subtotal, vat_pct, vat_amt, discount, total };
}

// ── Build OTHERS form <select> from POS_CATEGORIES ────────
// Must run after DOM is ready — the modal HTML comes after this script block
function buildOthersSelect() {
  const sel = document.getElementById('oth-category');
  if (!sel || sel.tagName !== 'SELECT') return;
  sel.innerHTML = '';
  POS_CATEGORIES.forEach(cat => {
    const opt = document.createElement('option');
    opt.value = cat; opt.textContent = cat;
    sel.appendChild(opt);
  });
  // OTHERS always present at the end
  if (!POS_CATEGORIES.includes('OTHERS')) {
    const o = document.createElement('option');
    o.value = 'OTHERS'; o.textContent = 'OTHERS';
    sel.appendChild(o);
  }
  sel.value = 'OTHERS';
}
document.addEventListener('DOMContentLoaded', buildOthersSelect);

// ── Category pill click ────────────────────────────────────
let activeCategory = '';
function selectCatBtn(btn, cat) {
  document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  activeCategory = cat;
  filterProducts();
}
document.getElementById('posSearch').addEventListener('input', filterProducts);

function filterProducts() {
  const q    = document.getElementById('posSearch').value.trim().toLowerCase();
  const raw  = document.getElementById('posSearch').value.trim();
  let visible = 0;

  document.querySelectorAll('.product-tile').forEach(tile => {
    const name = tile.dataset.name.toLowerCase();
    const cat  = tile.dataset.cat;
    const show = (q === '' || name.includes(q)) && (activeCategory === '' || cat === activeCategory);
    tile.style.display = show ? '' : 'none';
    if (show) visible++;
  });

  // No-results OTHERS suggestion
  const suggestion = document.getElementById('othersSearchSuggestion');
  const termEl     = document.getElementById('othersSearchTerm');
  if (suggestion) {
    if (q !== '' && visible === 0) {
      if (termEl) termEl.textContent = raw;
      suggestion.style.display = 'flex';
    } else {
      suggestion.style.display = 'none';
    }
  }
}

// ── Open OTHERS from search suggestion ─────────────────────
function openOthersFromSearch() {
  const raw = document.getElementById('posSearch').value.trim();
  openOthersModal(null, raw);
}

// ── OTHERS Quick-Create ────────────────────────────────────
function openOthersModal(e, prefillName = '') {
  if (e) e.stopPropagation();
  resetOthersForm(prefillName);
  openModal('othersModal');
  setTimeout(() => {
    const nameEl = document.getElementById('oth-name');
    if (nameEl) {
      if (!prefillName) nameEl.focus();
      else { nameEl.focus(); nameEl.select(); }
    }
  }, 80);
}

function resetOthersForm(prefillName = '') {
  document.getElementById('oth-name').value  = prefillName || '';
  document.getElementById('oth-cost').value  = '';
  document.getElementById('oth-sell').value  = '';
  document.getElementById('oth-qty').value   = '1';
  document.getElementById('oth-stock').value = '1';
  // Reset select to OTHERS
  const sel = document.getElementById('oth-category');
  if (sel) sel.value = 'OTHERS';
  const err = document.getElementById('oth-error');
  if (err) { err.innerHTML = ''; err.style.display = 'none'; }
}

function submitOthersProduct() {
  const name   = document.getElementById('oth-name').value.trim();
  const catEl  = document.getElementById('oth-category');
  const cat    = (catEl ? catEl.value : '') || 'OTHERS';
  const cost   = document.getElementById('oth-cost').value;
  const sell   = document.getElementById('oth-sell').value;
  const qty    = document.getElementById('oth-qty').value;
  const stock  = document.getElementById('oth-stock').value;
  const errEl  = document.getElementById('oth-error');
  const saveBtn = document.getElementById('oth-save-btn');

  // Client-side pre-check (server re-validates everything)
  const clientErrors = [];
  if (!name)                                          clientErrors.push('Product name is required.');
  if (cost === '' || isNaN(+cost) || +cost < 0)      clientErrors.push('Valid cost required (≥ 0).');
  if (sell === '' || isNaN(+sell) || +sell < 0)      clientErrors.push('Valid selling price required (≥ 0).');
  if (qty === ''  || !Number.isInteger(+qty)  || +qty < 1)   clientErrors.push('Quantity must be a whole number ≥ 1.');
  if (stock === '' || !Number.isInteger(+stock) || +stock < 0) clientErrors.push('Stock must be a whole number ≥ 0.');
  if (+qty > +stock)                                  clientErrors.push(`Quantity (${qty}) cannot exceed stock (${stock}).`);

  if (clientErrors.length) {
    errEl.innerHTML = clientErrors.join('<br>');
    errEl.style.display = 'flex';
    return;
  }

  saveBtn.disabled = true;
  saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Adding…';
  errEl.style.display = 'none';

  fetch('pos_quick_create.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      product_name:   name,
      category:       cat,
      cost_price:     parseFloat(cost),
      selling_price:  parseFloat(sell),
      stock_quantity: parseInt(stock, 10),
      cart_quantity:  parseInt(qty, 10),
    }),
  })
  .then(r => r.json())
  .then(data => {
    if (!data.success) {
      errEl.innerHTML = data.message;
      errEl.style.display = 'flex';
      saveBtn.disabled = false;
      saveBtn.innerHTML = '<i class="fa-solid fa-plus"></i> Add to Products';
      return;
    }

    // ── Inject the new product into the cart directly ──────
    const id    = String(data.product_id);
    const price = parseFloat(data.selling_price);
    const qty   = parseInt(data.cart_quantity, 10);
    const stk   = parseInt(data.stock_quantity, 10);

    // If already in cart, increase qty; otherwise add new entry at top
    if (cart[id]) {
      const newQty = cart[id].qty + qty;
      if (newQty > stk) {
        showToast('Adding would exceed available stock.', 'warning');
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="fa-solid fa-plus"></i> Add to Products';
        return;
      }
      cart[id].qty = newQty;
      cart[id].stock = stk; // update stock in case it changed
      cartOrder = [id, ...cartOrder.filter(k => k !== id)];
      closeModal('othersModal');
      renderCart(id, FLASH_ADD);
    } else {
      cart[id] = { name: data.product_name, price, qty, stock: stk };
      cartOrder.unshift(id);
      closeModal('othersModal');
      renderCart(id, FLASH_ADD);
    }

    // ── Also inject a product tile into the grid ───────────
    const grid = document.getElementById('productGrid');
    const tile = document.createElement('div');
    tile.className = 'product-tile';
    tile.dataset.id    = data.product_id;
    tile.dataset.name  = data.product_name;
    tile.dataset.price = data.selling_price;
    tile.dataset.stock = data.stock_quantity;
    tile.dataset.cat   = data.category;
    tile.onclick = function() { addToCart(this); };
    tile.innerHTML = `
      <span class="tile-category">${escHtmlPos(data.category)}</span>
      <div class="tile-name">${escHtmlPos(data.product_name)}</div>
      <div class="tile-price">${currency}${parseFloat(data.selling_price).toFixed(2)}</div>
      <div class="tile-stock">Stock: ${data.stock_quantity}</div>`;
    grid.prepend(tile);  // newest at top

    // Clear search so the new tile is visible
    const searchEl = document.getElementById('posSearch');
    if (searchEl) searchEl.value = '';
    const sug = document.getElementById('othersSearchSuggestion');
    if (sug) sug.style.display = 'none';
    filterProducts();

    showToast(`"${data.product_name}" added to products & cart.`, 'success');
    saveBtn.disabled = false;
    saveBtn.innerHTML = '<i class="fa-solid fa-plus"></i> Add to Products';
  })
  .catch(err => {
    errEl.innerHTML = 'Network error. Please try again.';
    errEl.style.display = 'flex';
    saveBtn.disabled = false;
    saveBtn.innerHTML = '<i class="fa-solid fa-plus"></i> Add to Products';
  });
}

function escHtmlPos(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Payment ────────────────────────────────────────────────
function openPaymentModal() {
  const t = recalcCart();
  document.getElementById('pdTotal').textContent = currency + t.total.toFixed(2);
  document.getElementById('amountPaid').value = '';
  document.getElementById('pdChange').textContent = currency + '0.00';
  document.getElementById('completeSaleBtn').disabled = true;
  openModal('paymentModal');
}

function calcChange() {
  const paid = parseFloat(document.getElementById('amountPaid').value) || 0;
  const total = parseFloat(document.getElementById('summaryTotal').textContent.replace(/[^0-9.]/g,'')) || 0;
  const change = paid - total;
  document.getElementById('pdChange').textContent = currency + Math.max(0, change).toFixed(2);
  document.getElementById('completeSaleBtn').disabled = paid < total;
}

function keypadInput(k) {
  const input = document.getElementById('amountPaid');
  if (k === '⌫') {
    input.value = input.value.slice(0, -1);
  } else if (k === '.' && input.value.includes('.')) {
    return;
  } else {
    input.value += k;
  }
  calcChange();
}

function setAmount(amount) {
  document.getElementById('amountPaid').value = amount;
  calcChange();
}

function setExactAmount() {
  const total = parseFloat(document.getElementById('summaryTotal').textContent.replace(/[^0-9.]/g,'')) || 0;
  document.getElementById('amountPaid').value = total.toFixed(2);
  calcChange();
}

// ── Complete Sale ──────────────────────────────────────────
function completeSale() {
  const btn = document.getElementById('completeSaleBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing…';

  const totals   = recalcCart();
  const paid     = parseFloat(document.getElementById('amountPaid').value) || 0;
  const method   = document.getElementById('paymentMethod').value;
  // Use cartOrder to ensure consistent item ordering in the sale record
  const cartData = cartOrder
    .filter(id => cart[id])
    .map(id => ({
      product_id:   parseInt(id),
      product_name: cart[id].name,
      quantity:     cart[id].qty,
      unit_price:   cart[id].price
    }));

  fetch('process_sale.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      cart: cartData,
      subtotal:       totals.subtotal,
      vat_percent:    totals.vat_pct,
      vat_amount:     totals.vat_amt,
      discount:       totals.discount,
      total:          totals.total,
      amount_paid:    paid,
      change_amount:  paid - totals.total,
      payment_method: method,
      // gcash_pending_profit is read from PHP session server-side
      // we pass session values only for UI receipt display
      gcash_pending_profit:  GCASH_PENDING_PROFIT,
      gcash_pending_session: GCASH_PENDING_SESSION,
    })
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      // Clear the pending GCash session from PHP session via AJAX
      if (GCASH_PENDING_PROFIT > 0) {
        fetch('gcash_maya_save.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'clear_pending' })
        });
      }
      closeModal('paymentModal');
      showReceipt(data);
    } else {
      showToast('Sale error: ' + data.message, 'error');
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-check"></i> Complete Sale';
    }
  })
  .catch(() => {
    showToast('Network error.', 'error');
    btn.disabled = false;
  });
}

function dismissGcashBanner() { /* handled via topbar pill — no-op */ }

function showReceipt(data) {
  const items = cartOrder
    .filter(id => cart[id])
    .map(id => {
      const i = cart[id];
      return `<div style="display:flex;justify-content:space-between;margin:3px 0"><span>${i.name} x${i.qty}</span><span>${currency}${(i.price*i.qty).toFixed(2)}</span></div>`;
    }).join('');
  const gcashLine = GCASH_PENDING_PROFIT > 0
    ? `<div style="display:flex;justify-content:space-between;margin:3px 0;color:#15803d">
         <span>GCash/Maya Service Profit</span>
         <span>+${currency}${GCASH_PENDING_PROFIT.toFixed(2)}</span>
       </div>`
    : '';
  const now = new Date().toLocaleString('en-PH');
  document.getElementById('receiptContent').innerHTML = `
    <div style="text-align:center;font-family:monospace">
      <div style="font-size:1.1rem;font-weight:bold;margin-bottom:4px"><?= h($shop_name) ?></div>
      <div style="font-size:.8rem;color:var(--text-muted)"><?= h(get_setting($conn,'shop_address','')) ?></div>
      <div class="receipt-divider"></div>
      <div style="font-size:.78rem;margin-bottom:8px">Invoice: <strong>${data.invoice}</strong><br>${now}</div>
      <div class="receipt-divider"></div>
      ${items}
      ${gcashLine}
      <div class="receipt-divider"></div>
      <div style="display:flex;justify-content:space-between"><span>Subtotal</span><span>${currency}${data.subtotal}</span></div>
      <div style="display:flex;justify-content:space-between"><span>VAT (${data.vat_pct}%)</span><span>${currency}${data.vat_amount}</span></div>
      ${data.discount > 0 ? `<div style="display:flex;justify-content:space-between"><span>Discount</span><span>−${currency}${data.discount}</span></div>` : ''}
      <div class="receipt-divider"></div>
      <div style="display:flex;justify-content:space-between;font-weight:bold;font-size:1.1rem"><span>TOTAL</span><span>${currency}${data.total}</span></div>
      <div style="display:flex;justify-content:space-between;margin-top:4px"><span>Paid (${data.method})</span><span>${currency}${data.paid}</span></div>
      <div style="display:flex;justify-content:space-between"><span>Change</span><span>${currency}${data.change}</span></div>
      ${GCASH_PENDING_PROFIT > 0 ? `<div class="receipt-divider"></div><div style="display:flex;justify-content:space-between;color:#15803d;font-weight:bold"><span>Total Profit (incl. GCash)</span><span>+${currency}${data.gcash_profit_total}</span></div>` : ''}
      <div class="receipt-divider"></div>
      <div style="font-size:.78rem;color:var(--text-muted)"><?= h($receipt_footer) ?></div>
    </div>`;
  openModal('receiptModal');
}

function printReceipt() {
  const w = window.open('','_blank','width=400,height=600');
  w.document.write('<html><head><title>Receipt</title><style>body{font-family:monospace;padding:20px;font-size:12px}.receipt-divider{border-top:1px dashed #000;margin:8px 0}</style></head><body>');
  w.document.write(document.getElementById('receiptContent').innerHTML);
  w.document.write('</body></html>');
  w.document.close();
  w.print();
}

function newSale() {
  closeModal('receiptModal');
  cart      = {};
  cartOrder = [];
  renderCart();
  // Refresh product tiles to show updated stock
  location.reload();
}

// ── Session Timeout Detection ──────────────────────────────
(function initSessionWatcher() {
  const POLL_INTERVAL  = 20000;  // check every 20 seconds
  const LOGIN_URL      = '<?= base_url() ?>login.php';

  let alreadyExpired   = false;  // show alert only once
  let intervalId       = null;

  function checkSession() {
    fetch('check_session.php', {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store'
    })
    .then(r => {
      // A non-200 (e.g. server error) should not trigger a false expiry
      if (!r.ok) return;
      return r.json();
    })
    .then(data => {
      if (!data) return;
      if (data.expired && !alreadyExpired) {
        alreadyExpired = true;
        clearInterval(intervalId); // stop all future polls

        // Warn if a sale is in progress (items in cart)
        const cartHasItems = cartOrder && cartOrder.length > 0;
        const warningLine  = cartHasItems
          ? '\n\n⚠ You have unsaved items in your cart. The transaction will be lost.'
          : '';

        // Show the styled modal if it exists on the page, otherwise fall back to alert()
        const modalBody = document.getElementById('sessionExpiredModal');
        if (modalBody) {
          openModal('sessionExpiredModal');
        } else {
          alert(
            'Your session has expired.' +
            '\n\nFor security reasons, you have been logged out.' +
            '\n\nPlease log in again to continue.' +
            warningLine
          );
          window.location.replace(LOGIN_URL);
        }
      }
    })
    .catch(() => {
      // Network error — do not falsely expire; silently retry next interval
    });
  }

  // Start polling after a short initial delay
  setTimeout(() => {
    checkSession();                              // first check immediately
    intervalId = setInterval(checkSession, POLL_INTERVAL);
  }, 3000);
})();
</script>

<!-- ===== OTHERS QUICK-CREATE MODAL ===== -->
<div class="modal-overlay" id="othersModal">
  <div class="modal" style="max-width:500px">
    <div class="modal-header" style="border-bottom:2px solid var(--accent-color)">
      <span class="modal-title" style="color:var(--accent-color)">
        <i class="fa-solid fa-plus-circle"></i> Add Custom Product
      </span>
      <button class="modal-close" onclick="closeModal('othersModal');resetOthersForm('')">&times;</button>
    </div>
    <div class="modal-body">
      <!-- Error box -->
      <div id="oth-error" class="alert alert-danger"
           style="display:none;margin-bottom:14px;gap:8px">
        <i class="fa-solid fa-circle-exclamation" style="flex-shrink:0"></i>
        <span></span>
      </div>

      <div class="form-grid" style="grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group" style="grid-column:span 2;margin:0">
          <label class="form-label">Product Name <span style="color:var(--danger)">*</span></label>
          <input type="text" id="oth-name" class="form-control"
                 placeholder="e.g. Custom Print A4"
                 onkeydown="if(event.key==='Enter')document.getElementById('oth-category').focus()">
        </div>
        <div class="form-group" style="margin:0">
          <label class="form-label">Category</label>
          <select id="oth-category" class="form-control">
            <!-- Options injected by buildOthersSelect() from POS_CATEGORIES -->
            <option value="OTHERS">OTHERS</option>
          </select>
        </div>
        <div class="form-group" style="margin:0">
          <label class="form-label">Cost Price <?= h($currency) ?></label>
          <input type="number" id="oth-cost" class="form-control"
                 placeholder="0.00" min="0" step="0.01">
        </div>
        <div class="form-group" style="margin:0">
          <label class="form-label">Selling Price <?= h($currency) ?> <span style="color:var(--danger)">*</span></label>
          <input type="number" id="oth-sell" class="form-control"
                 placeholder="0.00" min="0" step="0.01">
        </div>
        <div class="form-group" style="margin:0">
          <label class="form-label">Quantity <span style="font-size:.74rem;color:var(--text-muted)">(to add to cart)</span></label>
          <input type="number" id="oth-qty" class="form-control"
                 placeholder="1" min="1" step="1" value="1">
        </div>
        <div class="form-group" style="margin:0">
          <label class="form-label">Stock <span style="font-size:.74rem;color:var(--text-muted)">(inventory)</span></label>
          <input type="number" id="oth-stock" class="form-control"
                 placeholder="0" min="0" step="1" value="1">
        </div>
      </div>

      <div style="background:var(--bg-color);border-radius:8px;padding:10px 14px;margin-top:14px;font-size:.8rem;color:var(--text-muted)">
        <i class="fa-solid fa-circle-info"></i>
        <strong>Quantity</strong> = pieces to add to the current cart.
        <strong>Stock</strong> = total inventory units saved to the database.
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline"
              onclick="closeModal('othersModal');resetOthersForm('')">
        <i class="fa-solid fa-xmark"></i> Cancel
      </button>
      <button class="btn btn-accent" id="oth-save-btn" onclick="submitOthersProduct()">
        <i class="fa-solid fa-plus"></i> Add to Products
      </button>
    </div>
  </div>
</div>

<!-- ===== SESSION EXPIRED MODAL ===== -->
<div class="modal-overlay" id="sessionExpiredModal">
  <div class="modal" style="max-width:420px">
    <div class="modal-header" style="border-bottom:2px solid var(--warning)">
      <span class="modal-title" style="color:var(--warning)">
        <i class="fa-solid fa-clock-rotate-left"></i> Session Expired
      </span>
    </div>
    <div class="modal-body" style="text-align:center;padding:32px 28px">
      <div style="width:64px;height:64px;background:rgba(245,158,11,.12);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;font-size:1.8rem;color:var(--warning)">
        <i class="fa-solid fa-lock"></i>
      </div>
      <h3 style="font-family:'Syne',sans-serif;font-weight:700;font-size:1.15rem;margin-bottom:10px;color:var(--text-primary)">
        Your session has expired
      </h3>
      <p style="color:var(--text-secondary);font-size:.9rem;line-height:1.6;margin-bottom:6px">
        For security reasons, you have been logged out.
      </p>
      <p style="color:var(--text-secondary);font-size:.9rem;line-height:1.6">
        Please log in again to continue.
      </p>
      <div id="sessionCartWarning" style="display:none;margin-top:14px" class="alert alert-warning">
        <i class="fa-solid fa-triangle-exclamation"></i>
        You have unsaved items in your cart. The current transaction will be lost.
      </div>
    </div>
    <div class="modal-footer" style="justify-content:center">
      <button class="btn btn-accent" style="min-width:160px;justify-content:center"
        onclick="window.location.replace('<?= base_url() ?>login.php')">
        <i class="fa-solid fa-right-to-bracket"></i> Go to Login
      </button>
    </div>
  </div>
</div>

<script>
// Show cart warning inside modal if cart has items when session expired
(function() {
  const overlay = document.getElementById('sessionExpiredModal');
  if (!overlay) return;
  // MutationObserver watches for the modal being opened
  const observer = new MutationObserver(() => {
    if (overlay.classList.contains('open')) {
      const warn = document.getElementById('sessionCartWarning');
      if (warn && cartOrder && cartOrder.length > 0) {
        warn.style.display = 'flex';
      }
      observer.disconnect();
    }
  });
  observer.observe(overlay, { attributes: true, attributeFilter: ['class'] });
})();

<?php require_once 'includes/footer.php'; ?>
