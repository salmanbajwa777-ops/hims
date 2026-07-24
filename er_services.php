<?php
/**
 * ER Services & Admission Rates — admin catalogue.
 *
 * Admin sets the per-hour/day admission rates and manages the ER service
 * template (add services, set each rate, toggle active). Rates are stored, not
 * hardcoded, so the clinic can change them anytime. Consumed by the admission
 * service-logging + discharge billing screens.
 */
require_once __DIR__ . '/config/guard_admin.php';

$error = '';
$success = '';

$serviceTypes = ['SERVICE', 'PROCEDURE'];
$chargeTypes  = ['FLAT', 'HOURLY', 'PER_UNIT'];

// ---- Save admission-type rates ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_rates') {
    $rates = $_POST['rate'] ?? [];       // [ROUTINE => amount, ...]
    $enabled = $_POST['enabled'] ?? [];  // [ROUTINE => '1', ...]
    $upd = $pdo->prepare('UPDATE admission_rates SET rate_amount = ?, is_enabled = ?, updated_by_id = ? WHERE admission_type = ?');
    foreach (['ROUTINE', 'PRIVATE', 'LONG_PRIVATE'] as $type) {
        $amt = (float) ($rates[$type] ?? 0);
        $en  = isset($enabled[$type]) ? 1 : 0;
        $upd->execute([$amt, $en, $_SESSION['user_id'], $type]);
    }
    $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
        ->execute([$_SESSION['user_id'], 'admission_rates_updated', 'Updated admission-type rates']);
    $success = 'Admission rates saved.';
}

// ---- Add a new ER service ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_service') {
    $type = $_POST['service_type'] ?? '';
    $name = trim($_POST['service_name'] ?? '');
    $charge = $_POST['charge_type'] ?? 'FLAT';
    $base = (float) ($_POST['base_charge'] ?? 0);

    if (!in_array($type, $serviceTypes, true) || $name === '' || !in_array($charge, $chargeTypes, true)) {
        $error = 'Pick a service type, a name, and a charge type.';
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO er_services_master (service_type, service_name, charge_type, base_charge, created_by_id)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE charge_type = VALUES(charge_type), base_charge = VALUES(base_charge), status = \'ACTIVE\'
        ');
        $stmt->execute([$type, $name, $charge, $base, $_SESSION['user_id']]);
        $success = "Service \"$name\" saved.";
    }
}

// ---- Save all services in one submit (type / name / charge / rate / active) ----
// Whole catalogue posts as id-keyed arrays; every row re-saved. The Active
// checkbox is folded into the same save (unchecked → INACTIVE).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_services') {
    $names   = $_POST['service_name'] ?? [];
    $types   = $_POST['service_type'] ?? [];
    $charges = $_POST['charge_type'] ?? [];
    $bases   = $_POST['base_charge'] ?? [];
    $active  = $_POST['is_active'] ?? [];
    $upd = $pdo->prepare("UPDATE er_services_master SET service_type = ?, service_name = ?, charge_type = ?, base_charge = ?, status = ? WHERE id = ?");
    $saved = 0; $skipped = false;
    foreach ($names as $id => $rawName) {
        $id = (int) $id;
        $name = trim($rawName);
        $type = $types[$id] ?? '';
        $charge = $charges[$id] ?? 'FLAT';
        if ($id <= 0) { continue; }
        if ($name === '' || !in_array($type, $serviceTypes, true) || !in_array($charge, $chargeTypes, true)) { $skipped = true; continue; }
        $upd->execute([$type, $name, $charge, (float) ($bases[$id] ?? 0), isset($active[$id]) ? 'ACTIVE' : 'INACTIVE', $id]);
        $saved++;
    }
    if ($saved > 0) {
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$_SESSION['user_id'], 'er_services_saved', "Bulk-saved $saved ER service(s)"]);
        $success = "Saved $saved service(s)." . ($skipped ? ' (Rows missing a name/type/charge were skipped.)' : '');
    } else {
        $error = $skipped ? 'A service needs a name, a type, and a charge type.' : 'Nothing to save.';
    }
}

$rateRows = $pdo->query('SELECT * FROM admission_rates ORDER BY FIELD(admission_type, "ROUTINE","PRIVATE","LONG_PRIVATE")')->fetchAll();
$rateByType = [];
foreach ($rateRows as $r) { $rateByType[$r['admission_type']] = $r; }

$services = $pdo->query('SELECT * FROM er_services_master ORDER BY service_type, service_name')->fetchAll();

$typeLabels = [
    'SERVICE' => 'Service', 'PROCEDURE' => 'Procedure',
];
$chargeLabels = ['FLAT' => 'Flat', 'HOURLY' => 'Per hour', 'PER_UNIT' => 'Per unit'];

$pageTitle = 'ER Services & Rates';
$headExtra = <<<CSS
<style>
.header { height: 72px; position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; background: rgba(255,255,255,.80); backdrop-filter: blur(18px); border-bottom: 1px solid var(--border); }
.header-right { display: flex; align-items: center; gap: 18px; margin-left: auto; }
.header-date { font-size: 13px; color: var(--text-secondary); white-space: nowrap; }
.logout-link { font-size: 13px; color: var(--text-secondary); font-weight: 500; }

.rate-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
.rate-card { border: 1px solid var(--border); border-radius: 14px; padding: 16px; background: var(--bg); }
.rate-card h3 { font-size: 14px; font-weight: 700; margin-bottom: 2px; }
.rate-card .basis { font-size: 12px; color: var(--text-muted); margin-bottom: 12px; }
.rate-card .amt-row { display: flex; align-items: center; gap: 8px; }
.rate-card .amt-row .cur { font-weight: 700; color: var(--text-muted); }
.rate-card input[type=number] { width: 100%; padding: 9px 11px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 14px; background: #fff; }
.rate-card input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); }
.rate-card .en { display: flex; align-items: center; gap: 7px; margin-top: 10px; font-size: 12.5px; color: var(--text-secondary); }

.add-row { display: grid; grid-template-columns: 1.1fr 1.4fr 1fr .9fr auto; gap: 10px; align-items: end; }
.add-row label { font-size: 11.5px; font-weight: 600; color: var(--text-secondary); display: block; margin-bottom: 5px; }
.add-row input, .add-row select { width: 100%; padding: 9px 11px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 13.5px; background: var(--bg); }
.add-row input:focus, .add-row select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); background: #fff; }

.svc-type-tag { font-size: 11px; font-weight: 700; padding: 2px 9px; border-radius: 20px; background: var(--primary-light); color: var(--primary-dark); }
.inline-edit { display: flex; gap: 6px; align-items: center; }
.inline-edit select, .inline-edit input { padding: 6px 9px; border: 1px solid var(--border); border-radius: 8px; font: inherit; font-size: 12.5px; background: #fff; }
.inline-edit input { width: 90px; }
.row-inp { padding: 7px 9px; border: 1px solid var(--border); border-radius: 8px; font: inherit; font-size: 12.5px; background: #fff; max-width: 100%; }
.row-inp:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); }
.btn.small { padding: 7px 14px; font-size: 12.5px; }
.row-inactive td { opacity: .5; }
.link-btn { background: none; border: none; color: var(--primary); font: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer; padding: 0; }
.link-btn.warn { color: var(--red-text); }
/* Active toggle — checkbox styled as a small pill switch */
.active-toggle { display: inline-flex; align-items: center; cursor: pointer; }
.active-toggle input { position: absolute; opacity: 0; width: 0; height: 0; }
.active-toggle span { width: 40px; height: 22px; border-radius: 20px; background: var(--border); position: relative; transition: background .15s; display: inline-block; }
.active-toggle span::after { content: ''; position: absolute; top: 2px; left: 2px; width: 18px; height: 18px; border-radius: 50%; background: #fff; transition: transform .15s; box-shadow: 0 1px 2px rgba(0,0,0,.2); }
.active-toggle input:checked + span { background: var(--primary); }
.active-toggle input:checked + span::after { transform: translateX(18px); }
.active-toggle input:focus-visible + span { box-shadow: 0 0 0 3px rgba(26,127,126,.25); }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'er_services';
require __DIR__ . '/partials/sidebar.php';
?>
        <header class="header">
            <div class="page-title" style="font-size:16px;">ER Services &amp; Rates</div>
            <div class="header-right">
                <span class="header-date"><?= date('D, d/m/Y') ?></span>
                <a class="logout-link" href="logout.php">Logout</a>
            </div>
        </header>

        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">ER Services &amp; Admission Rates</div>
                    <div class="page-sub">Set the room rates and the chargeable-service catalogue used during admissions</div>
                </div>
            </div>

            <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <!-- Admission-type rates -->
            <div class="card">
                <div class="section-title">Admission Room Rates</div>
                <div class="section-sub">The hourly/daily rate charged for the stay, by admission type.</div>
                <form method="POST" action="er_services.php">
                    <input type="hidden" name="action" value="save_rates">
                    <div class="rate-grid">
                        <?php foreach (['ROUTINE' => 'Routine', 'PRIVATE' => 'Private Room', 'LONG_PRIVATE' => 'Long Private'] as $type => $label):
                            $row = $rateByType[$type] ?? ['rate_amount' => 0, 'rate_basis' => 'HOURLY', 'is_enabled' => 0]; ?>
                        <div class="rate-card">
                            <h3><?= $label ?></h3>
                            <div class="basis">Charged <?= $row['rate_basis'] === 'DAILY' ? 'per day' : 'per hour' ?></div>
                            <div class="amt-row">
                                <span class="cur">Rs</span>
                                <input type="number" step="0.01" min="0" name="rate[<?= $type ?>]" value="<?= htmlspecialchars((string) $row['rate_amount']) ?>">
                            </div>
                            <label class="en">
                                <input type="checkbox" name="enabled[<?= $type ?>]" value="1" <?= $row['is_enabled'] ? 'checked' : '' ?>>
                                Enabled (selectable at admission)
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="display:flex;justify-content:flex-end;margin-top:16px;">
                        <button type="submit" class="btn">Save Rates</button>
                    </div>
                </form>
            </div>

            <!-- Add a service -->
            <div class="card">
                <div class="section-title">Add a Service</div>
                <div class="section-sub">Add to the ER service catalogue. Re-adding an existing name updates its rate.</div>
                <form method="POST" action="er_services.php">
                    <input type="hidden" name="action" value="add_service">
                    <div class="add-row">
                        <div>
                            <label>Type</label>
                            <select name="service_type">
                                <?php foreach ($serviceTypes as $t): ?>
                                <option value="<?= $t ?>"><?= htmlspecialchars($typeLabels[$t]) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Service name</label>
                            <input type="text" name="service_name" placeholder="e.g. Paracetamol IV" required>
                        </div>
                        <div>
                            <label>Charge type</label>
                            <select name="charge_type">
                                <?php foreach ($chargeTypes as $c): ?>
                                <option value="<?= $c ?>"><?= htmlspecialchars($chargeLabels[$c]) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Rate (Rs)</label>
                            <input type="number" step="0.01" min="0" name="base_charge" value="0">
                        </div>
                        <button type="submit" class="btn">Add</button>
                    </div>
                </form>
            </div>

            <!-- Service list — one form, one Save all changes button. -->
            <div class="card">
                <div class="section-title">Service Catalogue</div>
                <div class="section-sub">Set the rate for each service, then <b>Save all changes</b> once. Uncheck Active for anything not offered.</div>
                <form method="POST" action="er_services.php" id="saveAll">
                <input type="hidden" name="action" value="save_services">
                <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr><th style="width:130px;">Type</th><th>Service</th><th style="width:130px;">Charge</th><th style="width:120px;">Rate (Rs)</th><th style="width:90px;">Active</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!$services): ?>
                        <tr><td colspan="5" class="muted" style="padding:20px 10px;">No services yet — add one above.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($services as $s): $sid = (int) $s['id']; ?>
                        <tr class="<?= $s['status'] === 'INACTIVE' ? 'row-inactive' : '' ?>">
                            <td>
                                <select name="service_type[<?= $sid ?>]" class="row-inp">
                                    <?php foreach ($serviceTypes as $t): ?>
                                    <option value="<?= $t ?>" <?= $s['service_type'] === $t ? 'selected' : '' ?>><?= htmlspecialchars($typeLabels[$t]) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="text" name="service_name[<?= $sid ?>]" class="row-inp" style="font-weight:600;width:100%;" value="<?= htmlspecialchars($s['service_name']) ?>">
                            </td>
                            <td>
                                <select name="charge_type[<?= $sid ?>]" class="row-inp">
                                    <?php foreach ($chargeTypes as $c): ?>
                                    <option value="<?= $c ?>" <?= $s['charge_type'] === $c ? 'selected' : '' ?>><?= htmlspecialchars($chargeLabels[$c]) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="number" step="0.01" min="0" name="base_charge[<?= $sid ?>]" class="row-inp" style="width:100px;" value="<?= htmlspecialchars((string) $s['base_charge']) ?>">
                            </td>
                            <td><label class="active-toggle"><input type="checkbox" name="is_active[<?= $sid ?>]" value="1" <?= $s['status'] === 'ACTIVE' ? 'checked' : '' ?>><span></span></label></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php if ($services): ?>
                <div style="display:flex;justify-content:flex-end;margin-top:16px;">
                    <button type="submit" class="btn">Save all changes</button>
                </div>
                <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>
<script src="assets/js/date-picker.js"></script>
</body>
</html>
