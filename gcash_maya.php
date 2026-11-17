<?php
// ============================================================
// gcash_maya.php — GCash / Maya Transaction Encoder
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_login();
define('PAGE_TITLE', 'GCash / Maya');

$currency = get_setting($conn, 'currency', '₱');
require_once 'includes/header.php';
?>

<style>
/* ── GCash/Maya page layout ── */
.gm-layout {
  display: grid;
  grid-template-columns: 340px 1fr;
  gap: 20px;
  align-items: start;
}
@media (max-width: 900px) { .gm-layout { grid-template-columns: 1fr; } }

/* Type selector tabs */
.gm-tabs {
  display: flex;
  gap: 0;
  border-radius: var(--radius);
  overflow: hidden;
  border: 1px solid var(--card-border);
  margin-bottom: 20px;
}
.gm-tab {
  flex: 1;
  padding: 11px 6px;
  text-align: center;
  font-size: .82rem;
  font-weight: 600;
  font-family: 'Syne', sans-serif;
  cursor: pointer;
  background: var(--bg-color);
  color: var(--text-secondary);
  border: none;
  border-right: 1px solid var(--card-border);
  transition: all .15s;
  line-height: 1.3;
}
.gm-tab:last-child { border-right: none; }
.gm-tab.active {
  background: var(--primary-color);
  color: #fff;
}
.gm-tab i { display: block; font-size: 1.1rem; margin-bottom: 4px; }

/* Transaction subtype selector */
.gm-subtype {
  display: flex;
  gap: 8px;
  margin-bottom: 16px;
}
.gm-subtype-btn {
  flex: 1;
  padding: 9px;
  border: 1px solid var(--card-border);
  border-radius: 8px;
  background: var(--bg-color);
  color: var(--text-secondary);
  font-size: .85rem;
  font-weight: 500;
  cursor: pointer;
  transition: all .12s;
  text-align: center;
}
.gm-subtype-btn.active {
  border-color: var(--accent-color);
  background: rgba(245,158,11,.1);
  color: var(--accent-color);
  font-weight: 700;
}

/* Amount input area */
.gm-amount-wrap {
  position: relative;
  margin-bottom: 14px;
}
.gm-amount-wrap .currency-prefix {
  position: absolute;
  left: 13px;
  top: 50%;
  transform: translateY(-50%);
  font-size: 1.1rem;
  font-weight: 700;
  color: var(--text-muted);
  pointer-events: none;
}
.gm-amount-input {
  width: 100%;
  padding: 14px 14px 14px 34px;
  font-size: 1.4rem;
  font-weight: 700;
  font-family: 'Syne', sans-serif;
  background: var(--input-bg);
  border: 2px solid var(--input-border);
  border-radius: 10px;
  color: var(--text-primary);
  transition: border-color .15s, box-shadow .15s;
  -moz-appearance: textfield;
}
.gm-amount-input::-webkit-inner-spin-button,
.gm-amount-input::-webkit-outer-spin-button { -webkit-appearance: none; }
.gm-amount-input:focus {
  outline: none;
  border-color: var(--accent-color);
  box-shadow: 0 0 0 3px rgba(245,158,11,.15);
}

/* Fee preview badge */
.gm-fee-preview {
  background: rgba(34,197,94,.1);
  border: 1px solid rgba(34,197,94,.3);
  border-radius: 8px;
  padding: 10px 14px;
  margin-bottom: 14px;
  display: none;
  align-items: center;
  justify-content: space-between;
}
.gm-fee-preview.show { display: flex; }
.gm-fee-arrow { font-size: 1.2rem; color: var(--success); font-weight: 700; }
.gm-fee-amount { font-family: 'Syne', sans-serif; font-size: 1.1rem; font-weight: 800; color: var(--success); }
.gm-fee-label  { font-size: .75rem; color: var(--text-muted); }

/* Validation message */
.gm-validation {
  color: var(--danger);
  font-size: .82rem;
  margin-bottom: 10px;
  min-height: 18px;
  display: flex;
  align-items: center;
  gap: 6px;
}

/* Transaction list table */
.gm-table-wrap { overflow-x: auto; }
.gm-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
.gm-table thead th {
  background: var(--primary-color);
  color: #fff;
  padding: 10px 12px;
  text-align: left;
  font-family: 'Syne', sans-serif;
  font-size: .78rem;
  text-transform: uppercase;
  letter-spacing: .05em;
}
.gm-table thead th:first-child { border-radius: 8px 0 0 0; }
.gm-table thead th:last-child  { border-radius: 0 8px 0 0; }
.gm-table tbody tr { transition: background .12s; }
.gm-table tbody tr:nth-child(even) { background: var(--table-stripe); }
.gm-table tbody tr:hover { background: rgba(245,158,11,.06); }
.gm-table tbody td { padding: 9px 12px; border-bottom: 1px solid var(--card-border); }

/* Totals row */
.gm-totals {
  background: var(--bg-color);
  border: 1px solid var(--card-border);
  border-radius: 10px;
  padding: 16px 18px;
  margin-top: 14px;
  display: flex;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
}
.gm-total-item { text-align: center; }
.gm-total-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); margin-bottom: 4px; }
.gm-total-value { font-family: 'Syne', sans-serif; font-size: 1.35rem; font-weight: 800; color: var(--text-primary); }
.gm-total-value.profit { color: var(--success); }

/* Empty state */
.gm-empty {
  text-align: center;
  padding: 36px 20px;
  color: var(--text-muted);
}
.gm-empty i { font-size: 2rem; display: block; margin-bottom: 10px; opacity: .4; }

/* Pricing reference card */
.pricing-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: .8rem; }
.pricing-row  { display: flex; justify-content: space-between; padding: 4px 0; border-bottom: 1px solid var(--card-border); }
.pricing-row:last-child { border-bottom: none; }
.pricing-fee  { font-weight: 700; color: var(--accent-color); }

/* Delete row flash */
@keyframes rowRemove {
  to { opacity: 0; transform: translateX(20px); }
}
.gm-row-exit { animation: rowRemove .25s ease forwards; }
</style>

<!-- ── Tab selector ── -->
<div class="gm-tabs">
  <button class="gm-tab active" onclick="switchTab('cashinout')" id="tab-cashinout">
    <i class="fa-solid fa-right-left"></i>Cash In / Out
  </button>
  <button class="gm-tab" onclick="switchTab('eloading')" id="tab-eloading">
    <i class="fa-solid fa-sim-card"></i>E-Loading
  </button>
  <button class="gm-tab" onclick="switchTab('bills')" id="tab-bills">
    <i class="fa-solid fa-file-invoice-dollar"></i>Bills Payment
  </button>
</div>

<div class="gm-layout">

  <!-- LEFT: Input panel -->
  <div>

    <!-- Cash In / Cash Out panel -->
    <div id="panel-cashinout" class="card gm-panel">
      <div class="card-header">
        <span class="card-title"><i class="fa-solid fa-right-left" style="color:var(--accent-color)"></i> Cash In / Cash Out</span>
      </div>

      <div class="gm-subtype" style="margin-bottom:14px">
        <button class="gm-subtype-btn active" id="sub-cashin"  onclick="setSubtype('Cash In')">
          <i class="fa-solid fa-arrow-down" style="color:var(--success)"></i> Cash In
        </button>
        <button class="gm-subtype-btn" id="sub-cashout" onclick="setSubtype('Cash Out')">
          <i class="fa-solid fa-arrow-up" style="color:var(--danger)"></i> Cash Out
        </button>
      </div>

      <label class="form-label">Amount <span style="color:var(--text-muted);font-size:.76rem">(max ₱20,000)</span></label>
      <div class="gm-amount-wrap">
        <span class="currency-prefix"><?= h($currency) ?></span>
        <input type="number" id="co-amount" class="gm-amount-input" placeholder="0"
               min="1" max="20000" step="1"
               onkeydown="handleEnter(event,'cashinout')"
               oninput="previewFee('cashinout')">
      </div>

      <div class="gm-fee-preview" id="co-preview">
        <div>
          <div class="gm-fee-label">Service Fee</div>
          <div class="gm-fee-amount" id="co-fee-display"><?= h($currency) ?>0.00</div>
        </div>
        <div class="gm-fee-arrow">→</div>
        <div style="text-align:right">
          <div class="gm-fee-label">Press ENTER to add</div>
        </div>
      </div>

      <div class="gm-validation" id="co-error"></div>

      <button class="btn btn-accent" style="width:100%;justify-content:center;padding:12px"
              onclick="addTransaction('cashinout')">
        <i class="fa-solid fa-plus"></i> Add Transaction
      </button>

      <!-- Pricing reference -->
      <details style="margin-top:16px">
        <summary style="cursor:pointer;font-size:.8rem;color:var(--text-muted);user-select:none">
          <i class="fa-solid fa-table-list"></i> View pricing table
        </summary>
        <div style="margin-top:10px">
          <div class="pricing-row"><span>₱1 – ₱200</span><span class="pricing-fee">₱10</span></div>
          <div class="pricing-row"><span>₱201 – ₱500</span><span class="pricing-fee">₱15</span></div>
          <div class="pricing-row"><span>₱501 – ₱1,000</span><span class="pricing-fee">₱20</span></div>
          <div class="pricing-row"><span>₱1,001 – ₱1,500</span><span class="pricing-fee">₱30</span></div>
          <div class="pricing-row"><span>₱1,501 – ₱2,000</span><span class="pricing-fee">₱40</span></div>
          <div class="pricing-row"><span>₱2,001 – ₱2,500</span><span class="pricing-fee">₱50</span></div>
          <div class="pricing-row"><span>+₱500 each</span><span class="pricing-fee">+₱10</span></div>
          <div class="pricing-row"><span>Maximum</span><span class="pricing-fee">₱20,000</span></div>
        </div>
      </details>
    </div>

    <!-- E-Loading panel -->
    <div id="panel-eloading" class="card gm-panel" style="display:none">
      <div class="card-header">
        <span class="card-title"><i class="fa-solid fa-sim-card" style="color:var(--info)"></i> E-Loading</span>
      </div>

      <label class="form-label">Load Amount</label>
      <div class="gm-amount-wrap">
        <span class="currency-prefix"><?= h($currency) ?></span>
        <input type="number" id="el-amount" class="gm-amount-input" placeholder="0"
               min="1" step="1"
               onkeydown="handleEnter(event,'eloading')"
               oninput="previewFee('eloading')">
      </div>

      <div class="gm-fee-preview" id="el-preview">
        <div>
          <div class="gm-fee-label">Service Fee</div>
          <div class="gm-fee-amount" id="el-fee-display"><?= h($currency) ?>0.00</div>
        </div>
        <div class="gm-fee-arrow">→</div>
        <div style="text-align:right">
          <div class="gm-fee-label">Press ENTER to add</div>
        </div>
      </div>

      <div class="gm-validation" id="el-error"></div>

      <button class="btn btn-accent" style="width:100%;justify-content:center;padding:12px"
              onclick="addTransaction('eloading')">
        <i class="fa-solid fa-plus"></i> Add Transaction
      </button>

      <details style="margin-top:16px">
        <summary style="cursor:pointer;font-size:.8rem;color:var(--text-muted);user-select:none">
          <i class="fa-solid fa-table-list"></i> View pricing table
        </summary>
        <div style="margin-top:10px">
          <div class="pricing-row"><span>₱1 – ₱95</span><span class="pricing-fee">₱5</span></div>
          <div class="pricing-row"><span>₱96 and above</span><span class="pricing-fee">₱10</span></div>
        </div>
      </details>
    </div>

    <!-- Bills Payment panel -->
    <div id="panel-bills" class="card gm-panel" style="display:none">
      <div class="card-header">
        <span class="card-title"><i class="fa-solid fa-file-invoice-dollar" style="color:var(--warning)"></i> Bills Payment</span>
      </div>

      <label class="form-label">Bill Amount <span style="color:var(--text-muted);font-size:.76rem">(max ₱10,000)</span></label>
      <div class="gm-amount-wrap">
        <span class="currency-prefix"><?= h($currency) ?></span>
        <input type="number" id="bp-amount" class="gm-amount-input" placeholder="0"
               min="1" max="10000" step="1"
               onkeydown="handleEnter(event,'bills')"
               oninput="previewFee('bills')">
      </div>

      <div class="gm-fee-preview" id="bp-preview">
        <div>
          <div class="gm-fee-label">Service Fee</div>
          <div class="gm-fee-amount" id="bp-fee-display"><?= h($currency) ?>0.00</div>
        </div>
        <div class="gm-fee-arrow">→</div>
        <div style="text-align:right">
          <div class="gm-fee-label">Press ENTER to add</div>
        </div>
      </div>

      <div class="gm-validation" id="bp-error"></div>

      <button class="btn btn-accent" style="width:100%;justify-content:center;padding:12px"
              onclick="addTransaction('bills')">
        <i class="fa-solid fa-plus"></i> Add Transaction
      </button>

      <details style="margin-top:16px">
        <summary style="cursor:pointer;font-size:.8rem;color:var(--text-muted);user-select:none">
          <i class="fa-solid fa-table-list"></i> View pricing table
        </summary>
        <div style="margin-top:10px">
          <div class="pricing-row"><span>₱1 – ₱1,000</span><span class="pricing-fee">₱20</span></div>
          <div class="pricing-row"><span>₱1,001 – ₱2,000</span><span class="pricing-fee">₱30</span></div>
          <div class="pricing-row"><span>₱2,001 – ₱3,000</span><span class="pricing-fee">₱40</span></div>
          <div class="pricing-row"><span>₱3,001 – ₱4,000</span><span class="pricing-fee">₱50</span></div>
          <div class="pricing-row"><span>+₱1,000 each</span><span class="pricing-fee">+₱10</span></div>
          <div class="pricing-row"><span>Maximum</span><span class="pricing-fee">₱10,000</span></div>
        </div>
      </details>
    </div>

  </div><!-- /left -->

  <!-- RIGHT: Transaction list + complete -->
  <div>
    <div class="card">
      <div class="card-header">
        <span class="card-title"><i class="fa-solid fa-list-check" style="color:var(--accent-color)"></i> Transaction List</span>
        <button class="btn btn-danger btn-sm" onclick="clearAll()" id="clearAllBtn" style="display:none">
          <i class="fa-solid fa-trash"></i> Clear All
        </button>
      </div>

      <div class="gm-table-wrap" id="txTableWrap">
        <div class="gm-empty" id="txEmpty">
          <i class="fa-solid fa-inbox"></i>
          <p>No transactions yet.</p>
          <small>Enter an amount and press ENTER.</small>
        </div>
        <table class="gm-table" id="txTable" style="display:none">
          <thead>
            <tr>
              <th style="width:36px">#</th>
              <th>Type</th>
              <th style="text-align:right">Amount</th>
              <th style="text-align:right">Service Fee</th>
              <th style="width:50px;text-align:center">Del</th>
            </tr>
          </thead>
          <tbody id="txBody"></tbody>
        </table>
      </div>

      <!-- Totals -->
      <div class="gm-totals" id="gmTotals" style="display:none">
        <div class="gm-total-item">
          <div class="gm-total-label">Total Amount</div>
          <div class="gm-total-value" id="totalAmount"><?= h($currency) ?>0.00</div>
        </div>
        <div class="gm-total-item">
          <div class="gm-total-label">Total Profit</div>
          <div class="gm-total-value profit" id="totalProfit"><?= h($currency) ?>0.00</div>
        </div>
        <div class="gm-total-item">
          <div class="gm-total-label">Transactions</div>
          <div class="gm-total-value" id="totalCount">0</div>
        </div>
      </div>

      <!-- Complete button -->
      <div style="margin-top:16px;display:none" id="completeWrap">
        <button class="btn btn-success" style="width:100%;justify-content:center;padding:14px;font-size:1rem;font-family:'Syne',sans-serif;font-weight:700"
                onclick="completeTransaction()">
          <i class="fa-solid fa-check-circle"></i> Complete Transaction
        </button>
      </div>

    </div>
  </div><!-- /right -->

</div><!-- /gm-layout -->

<!-- ===== COMPLETE TRANSACTION MODAL ===== -->
<div class="modal-overlay" id="completeModal">
  <div class="modal" style="max-width:440px">
    <div class="modal-header" style="border-bottom:2px solid var(--success)">
      <span class="modal-title" style="color:var(--success)">
        <i class="fa-solid fa-circle-check"></i> Transaction Complete
      </span>
    </div>
    <div class="modal-body" style="text-align:center;padding:28px">
      <div style="background:var(--bg-color);border-radius:12px;padding:20px;margin-bottom:20px">
        <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:6px">GCash / Maya Sales</div>
        <div style="font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:var(--text-primary)" id="modal-sales">₱0.00</div>
        <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--card-border)">
          <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:4px">Sales Profit</div>
          <div style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:var(--success)" id="modal-profit">₱0.00</div>
        </div>
      </div>
      <p style="color:var(--text-secondary);font-size:.875rem;margin-bottom:20px">What would you like to do?</p>
      <div style="display:flex;flex-direction:column;gap:10px">
        <button class="btn btn-primary" style="justify-content:center;padding:13px;font-size:.95rem"
                id="continuePosBtnModal" onclick="finalize('pos')">
          <i class="fa-solid fa-cash-register"></i> Continue to Point of Sale
        </button>
        <button class="btn btn-outline" style="justify-content:center;padding:13px;font-size:.95rem"
                onclick="finalize('done')">
          <i class="fa-solid fa-check"></i> Done
        </button>
      </div>
    </div>
  </div>
</div>

<script>
const CUR = '<?= h($currency) ?>';

// ── State ──────────────────────────────────────────────────
let transactions  = [];   // { id, serviceType, txType, amount, fee }
let nextId        = 1;
let currentTab    = 'cashinout';
let currentSubtype = 'Cash In';  // for cash in/out

// ── Fee calculators (must mirror PHP) ─────────────────────
function calcCashInOutFee(amount) {
  if (amount <= 0 || amount > 20000) return -1;
  if (amount <= 200)  return 10;
  if (amount <= 500)  return 15;
  if (amount <= 1000) return 20;
  if (amount <= 1500) return 30;
  if (amount <= 2000) return 40;
  if (amount <= 2500) return 50;
  const above = amount - 2500;
  const steps = Math.ceil(above / 500);
  return 50 + steps * 10;
}
function calcEloadingFee(amount) {
  if (amount <= 0)  return -1;
  if (amount <= 95) return 5;
  return 10;
}
function calcBillsFee(amount) {
  if (amount <= 0 || amount > 10000) return -1;
  if (amount <= 1000) return 20;
  if (amount <= 2000) return 30;
  if (amount <= 3000) return 40;
  if (amount <= 4000) return 50;
  const above = amount - 4000;
  const steps = Math.ceil(above / 1000);
  return 50 + steps * 10;
}
function getFee(tab, amount) {
  switch (tab) {
    case 'cashinout': return calcCashInOutFee(amount);
    case 'eloading':  return calcEloadingFee(amount);
    case 'bills':     return calcBillsFee(amount);
  }
  return -1;
}

// ── Format ─────────────────────────────────────────────────
function fmt(n) {
  return CUR + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// ── Tab switching ──────────────────────────────────────────
function switchTab(tab) {
  currentTab = tab;
  document.querySelectorAll('.gm-tab').forEach(t => t.classList.remove('active'));
  document.getElementById('tab-' + tab).classList.add('active');
  document.querySelectorAll('.gm-panel').forEach(p => p.style.display = 'none');
  document.getElementById('panel-' + tab).style.display = 'block';
  clearError(tab);
  hidePreview(tab);
  // Focus the relevant input
  const inputs = { cashinout: 'co-amount', eloading: 'el-amount', bills: 'bp-amount' };
  const el = document.getElementById(inputs[tab]);
  if (el) { el.value = ''; el.focus(); }
}

function setSubtype(type) {
  currentSubtype = type;
  document.getElementById('sub-cashin').classList.toggle('active',  type === 'Cash In');
  document.getElementById('sub-cashout').classList.toggle('active', type === 'Cash Out');
}

// ── Preview fee on input ───────────────────────────────────
function previewFee(tab) {
  const inputs  = { cashinout: 'co-amount', eloading: 'el-amount', bills: 'bp-amount' };
  const previews = { cashinout: 'co-preview', eloading: 'el-preview', bills: 'bp-preview' };
  const displays = { cashinout: 'co-fee-display', eloading: 'el-fee-display', bills: 'bp-fee-display' };

  const val    = parseFloat(document.getElementById(inputs[tab]).value);
  const fee    = isNaN(val) ? -1 : getFee(tab, val);
  const prev   = document.getElementById(previews[tab]);
  const disp   = document.getElementById(displays[tab]);

  clearError(tab);
  if (fee >= 0) {
    disp.textContent = fmt(fee);
    prev.classList.add('show');
  } else {
    prev.classList.remove('show');
  }
}

function hidePreview(tab) {
  const previews = { cashinout: 'co-preview', eloading: 'el-preview', bills: 'bp-preview' };
  document.getElementById(previews[tab])?.classList.remove('show');
}

// ── Handle Enter key ───────────────────────────────────────
function handleEnter(e, tab) {
  if (e.key === 'Enter') { e.preventDefault(); addTransaction(tab); }
}

// ── Validate & add transaction ─────────────────────────────
function addTransaction(tab) {
  const inputs   = { cashinout: 'co-amount', eloading: 'el-amount', bills: 'bp-amount' };
  const inputEl  = document.getElementById(inputs[tab]);
  const rawVal   = inputEl.value.trim();
  clearError(tab);

  if (rawVal === '') { setError(tab, 'Please enter an amount.'); return; }

  const amount = parseFloat(rawVal);
  if (isNaN(amount) || amount <= 0) { setError(tab, 'Amount must be a positive number.'); return; }
  if (!Number.isInteger(amount))    { setError(tab, 'Decimal values are not allowed.'); return; }

  const fee = getFee(tab, amount);
  if (fee < 0) {
    const maxes = { cashinout: '₱20,000', eloading: 'unlimited', bills: '₱10,000' };
    const labels = { cashinout: 'Cash In/Cash Out', eloading: 'E-Loading', bills: 'Bills Payment' };
    setError(tab, `Invalid amount. ${labels[tab]} maximum is ${maxes[tab]}.`);
    return;
  }

  // Determine display labels
  const svcMap = { cashinout: 'Cash In/Out', eloading: 'E-Loading', bills: 'Bills Payment' };
  const txMap  = {
    cashinout: currentSubtype,   // 'Cash In' or 'Cash Out'
    eloading:  'E-Loading',
    bills:     'Bills Payment',
  };

  transactions.push({
    id:          nextId++,
    serviceType: svcMap[tab],
    txType:      txMap[tab],
    amount:      amount,
    fee:         fee,
  });

  hidePreview(tab);
  inputEl.value = '';
  inputEl.focus();
  renderTable();
}

// ── Render transaction table ───────────────────────────────
function renderTable() {
  const empty      = document.getElementById('txEmpty');
  const table      = document.getElementById('txTable');
  const body       = document.getElementById('txBody');
  const totalsEl   = document.getElementById('gmTotals');
  const completeEl = document.getElementById('completeWrap');
  const clearBtn   = document.getElementById('clearAllBtn');

  if (transactions.length === 0) {
    empty.style.display = 'block';
    table.style.display = 'none';
    totalsEl.style.display   = 'none';
    completeEl.style.display = 'none';
    clearBtn.style.display   = 'none';
    return;
  }

  empty.style.display = 'none';
  table.style.display = 'table';
  totalsEl.style.display   = 'flex';
  completeEl.style.display = 'block';
  clearBtn.style.display   = '';

  body.innerHTML = transactions.map((tx, idx) => `
    <tr id="tx-row-${tx.id}">
      <td style="color:var(--text-muted)">${idx + 1}</td>
      <td>
        <span style="font-weight:600">${escH(tx.txType)}</span>
        <span class="badge badge-muted" style="margin-left:4px;font-size:.68rem">${escH(tx.serviceType)}</span>
      </td>
      <td style="text-align:right;font-weight:600">${fmt(tx.amount)}</td>
      <td style="text-align:right;color:var(--success);font-weight:700">${fmt(tx.fee)}</td>
      <td style="text-align:center">
        <button class="btn btn-danger btn-sm btn-icon" onclick="deleteTransaction(${tx.id})" title="Remove">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </td>
    </tr>`).join('');

  // Update totals
  const totalAmount = transactions.reduce((s, tx) => s + tx.amount, 0);
  const totalProfit = transactions.reduce((s, tx) => s + tx.fee, 0);
  document.getElementById('totalAmount').textContent = fmt(totalAmount);
  document.getElementById('totalProfit').textContent = fmt(totalProfit);
  document.getElementById('totalCount').textContent  = transactions.length;
}

// ── Delete a transaction ───────────────────────────────────
function deleteTransaction(id) {
  const row = document.getElementById('tx-row-' + id);
  if (row) {
    row.classList.add('gm-row-exit');
    setTimeout(() => {
      transactions = transactions.filter(tx => tx.id !== id);
      renderTable();
    }, 260);
  }
}

function clearAll() {
  if (!transactions.length) return;
  if (!confirm('Clear all transactions in the list?')) return;
  transactions = [];
  renderTable();
}

// ── Complete Transaction ───────────────────────────────────
let pendingFinalize = null; // 'pos' | 'done' — set after server saves

function completeTransaction() {
  if (!transactions.length) { showToast('No transactions to complete.', 'warning'); return; }

  const totalAmount = transactions.reduce((s, tx) => s + tx.amount, 0);
  const totalProfit = transactions.reduce((s, tx) => s + tx.fee, 0);

  document.getElementById('modal-sales').textContent  = fmt(totalAmount);
  document.getElementById('modal-profit').textContent = fmt(totalProfit);
  openModal('completeModal');
}

function finalize(destination) {
  const continueBtn = document.getElementById('continuePosBtnModal');
  continueBtn.disabled = true;
  continueBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

  const payload = {
    action:      'save_session',
    destination: destination,
    items:       transactions.map(tx => ({
      service_type:     tx.serviceType,
      transaction_type: tx.txType,
      amount:           tx.amount,
    })),
  };

  fetch('gcash_maya_save.php', {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify(payload),
  })
  .then(r => {
    // Read raw text first so we can show it on parse failure
    return r.text().then(text => {
      try {
        return JSON.parse(text);
      } catch(e) {
        throw new Error('Server returned invalid JSON. Raw response: ' + text.substring(0, 200));
      }
    });
  })
  .then(data => {
    if (!data.success) {
      closeModal('completeModal');
      showToast('Save failed: ' + data.message, 'error');
      continueBtn.disabled = false;
      continueBtn.innerHTML = '<i class="fa-solid fa-cash-register"></i> Continue to Point of Sale';
      return;
    }
    closeModal('completeModal');
    if (destination === 'pos') {
      window.location.href = 'pos.php';
    } else {
      showToast('Saved! Invoice: ' + data.invoice, 'success');
      transactions = [];
      nextId = 1;
      renderTable();
      continueBtn.disabled = false;
      continueBtn.innerHTML = '<i class="fa-solid fa-cash-register"></i> Continue to Point of Sale';
    }
  })
  .catch(err => {
    closeModal('completeModal');
    // Show the actual error so it's debuggable
    showToast('Error: ' + err.message, 'error');
    console.error('gcash_maya_save error:', err);
    continueBtn.disabled = false;
    continueBtn.innerHTML = '<i class="fa-solid fa-cash-register"></i> Continue to Point of Sale';
  });
}

function escH(str) {
  return String(str)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Error helpers ──────────────────────────────────────────
function setError(tab, msg) {
  const ids = { cashinout: 'co-error', eloading: 'el-error', bills: 'bp-error' };
  const el  = document.getElementById(ids[tab]);
  if (el) el.innerHTML = `<i class="fa-solid fa-circle-exclamation"></i> ${msg}`;
}
function clearError(tab) {
  const ids = { cashinout: 'co-error', eloading: 'el-error', bills: 'bp-error' };
  const el  = document.getElementById(ids[tab]);
  if (el) el.innerHTML = '';
}

// ── Auto-focus on load ─────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('co-amount')?.focus();
});
</script>

<?php require_once 'includes/footer.php'; ?>
