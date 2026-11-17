<?php
// ============================================================
// settings.php — Shop settings + theme customization
// ============================================================
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_login();
require_role('admin');
define('PAGE_TITLE', 'Settings');

$msg      = '';
$msg_type = 'success';

// Handle settings save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $keys = ['shop_name','shop_address','shop_phone','vat_percent','currency','low_stock_threshold','receipt_footer'];
    foreach ($keys as $key) {
        $val = trim($_POST[$key] ?? '');
        $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_val) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_val=?");
        $stmt->bind_param('sss', $key, $val, $val);
        $stmt->execute();
    }
    $msg = 'Settings saved successfully.';
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current  = $_POST['current_password'] ?? '';
    $new_pass = $_POST['new_password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!password_verify($current, $user['password'])) {
        $msg = 'Current password is incorrect.'; $msg_type = 'danger';
    } elseif ($new_pass !== $confirm) {
        $msg = 'New passwords do not match.'; $msg_type = 'danger';
    } elseif (strlen($new_pass) < 6) {
        $msg = 'Password must be at least 6 characters.'; $msg_type = 'danger';
    } else {
        $hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
        $stmt->bind_param('si', $hash, $_SESSION['user_id']);
        $stmt->execute();
        $msg = 'Password changed successfully.';
    }
}

// Load all settings
$all_settings = [];
$res = $conn->query("SELECT setting_key, setting_val FROM settings");
while ($r = $res->fetch_assoc()) $all_settings[$r['setting_key']] = $r['setting_val'];
function s($key, $default='') { global $all_settings; return $all_settings[$key] ?? $default; }

// Load users list
$users = $conn->query("SELECT id, username, full_name, role, created_at FROM users ORDER BY id");

require_once 'includes/header.php';
?>

<?php if ($msg): ?>
<div class="alert alert-<?= h($msg_type) ?>"><i class="fa-solid fa-circle-check"></i> <?= h($msg) ?></div>
<?php endif; ?>

<div class="settings-grid">

  <!-- Shop Settings -->
  <div class="card" style="grid-column:span 2">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-store" style="color:var(--accent-color)"></i> Shop Information</span></div>
    <form method="POST">
      <input type="hidden" name="save_settings" value="1">
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Shop Name</label>
          <input type="text" name="shop_name" class="form-control" value="<?= h(s('shop_name','QuickMart POS')) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Phone</label>
          <input type="text" name="shop_phone" class="form-control" value="<?= h(s('shop_phone')) ?>">
        </div>
        <div class="form-group" style="grid-column:span 2">
          <label class="form-label">Address</label>
          <input type="text" name="shop_address" class="form-control" value="<?= h(s('shop_address')) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Currency Symbol</label>
          <input type="text" name="currency" class="form-control" value="<?= h(s('currency','₱')) ?>" style="max-width:80px">
        </div>
        <div class="form-group">
          <label class="form-label">VAT % (default)</label>
          <input type="number" name="vat_percent" class="form-control" value="<?= h(s('vat_percent','12')) ?>" min="0" max="100" step="0.01" style="max-width:100px">
        </div>
        <div class="form-group">
          <label class="form-label">Low Stock Alert Threshold</label>
          <input type="number" name="low_stock_threshold" class="form-control" value="<?= h(s('low_stock_threshold','10')) ?>" min="1" style="max-width:100px">
        </div>
        <div class="form-group" style="grid-column:span 2">
          <label class="form-label">Receipt Footer Message</label>
          <input type="text" name="receipt_footer" class="form-control" value="<?= h(s('receipt_footer','Thank you for shopping with us!')) ?>">
        </div>
      </div>
      <div style="margin-top:6px">
        <button type="submit" class="btn btn-accent"><i class="fa-solid fa-floppy-disk"></i> Save Settings</button>
      </div>
    </form>
  </div>

  <!-- Theme Settings -->
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-palette" style="color:var(--info)"></i> Theme Customization</span></div>

    <div class="form-group">
      <label class="form-label">Dark / Light Mode</label>
      <div style="display:flex;align-items:center;gap:12px">
        <label style="position:relative;display:inline-block;width:50px;height:26px">
          <input type="checkbox" id="darkModeToggle" style="opacity:0;width:0;height:0" onchange="toggleDarkMode(this)">
          <span style="position:absolute;inset:0;background:#cbd5e1;border-radius:26px;cursor:pointer;transition:.3s" id="toggleSlider"></span>
          <span style="position:absolute;left:3px;bottom:3px;width:20px;height:20px;background:#fff;border-radius:50%;transition:.3s" id="toggleKnob"></span>
        </label>
        <span id="darkModeLabel" style="font-size:.9rem;color:var(--text-secondary)">Light Mode</span>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Background Color</label>
      <div class="color-picker-row">
        <input type="color" id="bgColorPicker" value="#f0f2f5" class="color-swatch" oninput="applyThemeColor('--bg-color', this.value)">
        <input type="text" id="bgColorText" class="form-control" value="#f0f2f5" style="max-width:120px" oninput="syncColor('bgColorPicker','bgColorText','--bg-color')">
        <button class="btn btn-outline btn-sm" onclick="resetColor('bgColorPicker','bgColorText','#f0f2f5','--bg-color')">Reset</button>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Primary Color (Sidebar)</label>
      <div class="color-picker-row">
        <input type="color" id="primaryColorPicker" value="#1a2744" class="color-swatch" oninput="applyThemeColor('--primary-color', this.value)">
        <input type="text" id="primaryColorText" class="form-control" value="#1a2744" style="max-width:120px" oninput="syncColor('primaryColorPicker','primaryColorText','--primary-color')">
        <button class="btn btn-outline btn-sm" onclick="resetColor('primaryColorPicker','primaryColorText','#1a2744','--primary-color')">Reset</button>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Accent Color</label>
      <div class="color-picker-row">
        <input type="color" id="accentColorPicker" value="#f59e0b" class="color-swatch" oninput="applyThemeColor('--accent-color', this.value)">
        <input type="text" id="accentColorText" class="form-control" value="#f59e0b" style="max-width:120px" oninput="syncColor('accentColorPicker','accentColorText','--accent-color')">
        <button class="btn btn-outline btn-sm" onclick="resetColor('accentColorPicker','accentColorText','#f59e0b','--accent-color')">Reset</button>
      </div>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:6px">
      <button class="btn btn-accent" onclick="saveTheme()"><i class="fa-solid fa-floppy-disk"></i> Save Theme</button>
      <button class="btn btn-outline" onclick="resetTheme()"><i class="fa-solid fa-rotate-left"></i> Reset All</button>
    </div>

    <div style="margin-top:16px">
      <label class="form-label">Quick Presets</label>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-outline btn-sm" onclick="applyPreset('#1a2744','#f59e0b','#f0f2f5')">🟡 Default</button>
        <button class="btn btn-outline btn-sm" onclick="applyPreset('#1e3a5f','#22c55e','#f0f7f4')">🟢 Forest</button>
        <button class="btn btn-outline btn-sm" onclick="applyPreset('#7c3aed','#f472b6','#fdf4ff')">🟣 Violet</button>
        <button class="btn btn-outline btn-sm" onclick="applyPreset('#0f172a','#38bdf8','#f0f9ff')">🔵 Ocean</button>
        <button class="btn btn-outline btn-sm" onclick="applyPreset('#7f1d1d','#fb923c','#fff7ed')">🟠 Ember</button>
      </div>
    </div>
  </div>

  <!-- Password Change -->
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-lock" style="color:var(--warning)"></i> Change Password</span></div>
    <form method="POST">
      <input type="hidden" name="change_password" value="1">
      <div class="form-group">
        <label class="form-label">Current Password</label>
        <input type="password" name="current_password" class="form-control" required>
      </div>
      <div class="form-group">
        <label class="form-label">New Password</label>
        <input type="password" name="new_password" class="form-control" required minlength="6">
      </div>
      <div class="form-group">
        <label class="form-label">Confirm New Password</label>
        <input type="password" name="confirm_password" class="form-control" required>
      </div>
      <button type="submit" class="btn btn-primary"><i class="fa-solid fa-key"></i> Change Password</button>
    </form>
  </div>

  <!-- Users List -->
  <div class="card" style="grid-column:span 2">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-users" style="color:var(--info)"></i> System Users</span></div>
    <div class="table-wrapper">
      <table>
        <thead><tr><th>#</th><th>Full Name</th><th>Username</th><th>Role</th><th>Created</th></tr></thead>
        <tbody>
        <?php while ($u = $users->fetch_assoc()): ?>
          <tr>
            <td><?= (int)$u['id'] ?></td>
            <td><?= h($u['full_name']) ?></td>
            <td><code><?= h($u['username']) ?></code></td>
            <td>
              <?php if ($u['role'] === 'admin'): ?>
                <span class="badge badge-warning">Admin</span>
              <?php else: ?>
                <span class="badge badge-info">Cashier</span>
              <?php endif; ?>
            </td>
            <td style="font-size:.8rem;color:var(--text-muted)"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<script>
// ── Dark Mode ──────────────────────────────────────────────
function toggleDarkMode(cb) {
  const next = cb.checked ? 'dark' : 'light';
  document.documentElement.setAttribute('data-theme', next);
  localStorage.setItem('pos_theme', next);
  updateToggleUI(cb.checked);
}
function updateToggleUI(isDark) {
  const knob = document.getElementById('toggleKnob');
  const slider = document.getElementById('toggleSlider');
  const label = document.getElementById('darkModeLabel');
  if (isDark) {
    knob.style.transform = 'translateX(24px)';
    slider.style.background = 'var(--accent-color)';
    label.textContent = 'Dark Mode';
  } else {
    knob.style.transform = '';
    slider.style.background = '#cbd5e1';
    label.textContent = 'Light Mode';
  }
}
(function() {
  const isDark = localStorage.getItem('pos_theme') === 'dark';
  const cb = document.getElementById('darkModeToggle');
  if (cb) { cb.checked = isDark; updateToggleUI(isDark); }
})();

// ── Color Pickers ──────────────────────────────────────────
function applyThemeColor(cssVar, value) {
  document.documentElement.style.setProperty(cssVar, value);
}
function syncColor(pickerId, textId, cssVar) {
  const val = document.getElementById(textId).value;
  if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
    document.getElementById(pickerId).value = val;
    applyThemeColor(cssVar, val);
  }
}
function resetColor(pickerId, textId, defaultVal, cssVar) {
  document.getElementById(pickerId).value = defaultVal;
  document.getElementById(textId).value = defaultVal;
  applyThemeColor(cssVar, defaultVal);
}
function saveTheme() {
  const vars = {
    '--bg-color':      document.getElementById('bgColorPicker').value,
    '--primary-color': document.getElementById('primaryColorPicker').value,
    '--accent-color':  document.getElementById('accentColorPicker').value,
  };
  Object.entries(vars).forEach(([k,v]) => {
    localStorage.setItem('cssvar_' + k, v);
    document.documentElement.style.setProperty(k, v);
  });
  showToast('Theme saved!', 'success');
}
function resetTheme() {
  ['--bg-color','--primary-color','--accent-color'].forEach(v => localStorage.removeItem('cssvar_' + v));
  document.getElementById('bgColorPicker').value = '#f0f2f5';
  document.getElementById('primaryColorPicker').value = '#1a2744';
  document.getElementById('accentColorPicker').value = '#f59e0b';
  document.getElementById('bgColorText').value = '#f0f2f5';
  document.getElementById('primaryColorText').value = '#1a2744';
  document.getElementById('accentColorText').value = '#f59e0b';
  ['--bg-color','--primary-color','--accent-color'].forEach(v =>
    document.documentElement.style.removeProperty(v)
  );
  showToast('Theme reset to defaults.', 'info');
}
function applyPreset(primary, accent, bg) {
  document.getElementById('primaryColorPicker').value = primary;
  document.getElementById('accentColorPicker').value  = accent;
  document.getElementById('bgColorPicker').value      = bg;
  document.getElementById('primaryColorText').value   = primary;
  document.getElementById('accentColorText').value    = accent;
  document.getElementById('bgColorText').value        = bg;
  applyThemeColor('--primary-color', primary);
  applyThemeColor('--accent-color', accent);
  applyThemeColor('--bg-color', bg);
}
// Restore current values
(function() {
  const stored = {
    '--bg-color':      localStorage.getItem('cssvar_--bg-color'),
    '--primary-color': localStorage.getItem('cssvar_--primary-color'),
    '--accent-color':  localStorage.getItem('cssvar_--accent-color'),
  };
  if (stored['--bg-color'])      { document.getElementById('bgColorPicker').value = stored['--bg-color']; document.getElementById('bgColorText').value = stored['--bg-color']; }
  if (stored['--primary-color']) { document.getElementById('primaryColorPicker').value = stored['--primary-color']; document.getElementById('primaryColorText').value = stored['--primary-color']; }
  if (stored['--accent-color'])  { document.getElementById('accentColorPicker').value = stored['--accent-color']; document.getElementById('accentColorText').value = stored['--accent-color']; }
})();
</script>

<?php require_once 'includes/footer.php'; ?>
