<?php
/**
 * Expense Categories — admin catalogue + spending limits.
 *
 * Two layers of control over counter cash going out as expenses:
 *   1. Per-category shift limit (expense_categories.shift_limit) — the most a
 *      single category may absorb in one shift (calendar day, all users
 *      combined). 0 = uncapped.
 *   2. Overall shift limit (clinic_settings 'expense_shift_limit_total') — the
 *      most any single posting user may pay out in one shift across all
 *      categories. 0 = uncapped.
 * Both are enforced server-side in expenses.php at posting time; admin's own
 * postings bypass them. Deactivating a category hides it from the posting form
 * without touching its history.
 *
 * Also holds the VEHICLES register (added 2026-08-11) — vehicles are part of the
 * same "what may cash be spent on" catalogue an admin comes here to manage, and
 * they feed the per-vehicle cost-per-km figures on vehicle_report.php.
 */
require_once __DIR__ . '/config/guard_admin.php';
// For VEH_MAX_PLAUSIBLE_GAP — the register shows the fleet default as the
// placeholder on the per-vehicle jump-limit field, so the two can never drift.
require_once __DIR__ . '/config/vehicle_costs.php';

$error = '';
$success = '';

// Amounts clamped to a sane non-negative range; 0 = no cap.
function ec_amt($v): float {
    return round(min(9999999, max(0, (float) $v)), 2);
}

// ---- Add a category ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_category') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        $error = 'The category needs a name.';
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO expense_categories (name, shift_limit, created_by_id)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE shift_limit = VALUES(shift_limit), is_active = 1
        ');
        $stmt->execute([$name, ec_amt($_POST['shift_limit'] ?? 0), $_SESSION['user_id']]);
        audit_log($pdo, 'expense_category_saved', "Saved expense category \"$name\"", $_SESSION['user_id']);
        $success = "Category \"$name\" saved.";
    }
}

// ---- Save all categories in one submit (name + shift limit + active) ----
// Whole table posts as id-keyed arrays; every row re-saved. The Active checkbox
// is folded into the same save (history is always kept; inactive just hides the
// category from the posting form). Delete stays a separate per-row action below.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_categories') {
    $names  = $_POST['name'] ?? [];
    $limits = $_POST['shift_limit'] ?? [];
    $active = $_POST['is_active'] ?? [];
    $upd = $pdo->prepare('UPDATE expense_categories SET name = ?, shift_limit = ?, is_active = ? WHERE id = ?');
    $saved = 0; $blank = false; $dupe = false;
    foreach ($names as $id => $rawName) {
        $id = (int) $id;
        $name = trim($rawName);
        if ($id <= 0) { continue; }
        if ($name === '') { $blank = true; continue; }
        try {
            $upd->execute([$name, ec_amt($limits[$id] ?? 0), isset($active[$id]) ? 1 : 0, $id]);
            $saved++;
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) { $dupe = true; } else { throw $e; }
        }
    }
    if ($saved > 0) {
        audit_log($pdo, 'expense_categories_saved', "Bulk-saved $saved expense categor" . ($saved === 1 ? 'y' : 'ies'), $_SESSION['user_id']);
        $success = "Saved $saved categor" . ($saved === 1 ? 'y' : 'ies') . '. New limits apply from the next posting.'
            . ($blank ? ' (Blank-named rows skipped.)' : '') . ($dupe ? ' (Some names clashed and were skipped.)' : '');
    } else {
        $error = $dupe ? 'A name clashed with another category.' : 'A category needs a name.';
    }
}

// ---- Delete a category (only if it has no expenses — otherwise deactivate) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_category') {
    $id = (int) ($_POST['category_id'] ?? 0);
    if ($id > 0) {
        $used = $pdo->prepare('SELECT COUNT(*) FROM expenses WHERE category_id = ?');
        $used->execute([$id]);
        if ((int) $used->fetchColumn() > 0) {
            $error = 'This category has expenses recorded against it — deactivate it instead so the history stays intact.';
        } else {
            $pdo->prepare('DELETE FROM expense_categories WHERE id = ?')->execute([$id]);
            audit_log($pdo, 'expense_category_deleted', "Deleted unused expense category #$id", $_SESSION['user_id']);
            $success = 'Category deleted.';
        }
    }
}

// ---- Vehicles: add / save / deactivate ----
// Lives here rather than on its own page because a vehicle is part of the same
// "what may cash be spent on" catalogue an admin already comes here to manage.
$vehiclesReady = false;
try {
    $pdo->query('SELECT 1 FROM vehicles LIMIT 1');
    $vehiclesReady = true;
} catch (PDOException $e) { /* add_vehicle_expenses.sql not run yet */ }

// The jump limit and tank size arrive with add_fuel_accountability.sql, which is
// a SEPARATE migration from the one that created the table. Probed independently
// so a half-migrated database renders the register without the two extra fields
// rather than failing on an UPDATE naming a column that is not there.
$vehTuningReady = false;
if ($vehiclesReady) {
    try {
        $pdo->query('SELECT jump_limit_km, tank_litres FROM vehicles LIMIT 1');
        $vehTuningReady = true;
    } catch (PDOException $e) { /* add_fuel_accountability.sql not run yet */ }
}

/**
 * A jump limit as the admin typed it. Blank means "use the app default", which
 * is a different statement from zero — a stored 0 would discard every reading
 * and report the vehicle as having driven nowhere, so it is refused.
 */
$vehLimitValue = function ($raw): ?int {
    $raw = trim((string) $raw);
    if ($raw === '') { return null; }
    $n = (int) $raw;
    return $n > 0 ? $n : null;
};
$vehTankValue = function ($raw): ?float {
    $raw = trim((string) $raw);
    if ($raw === '') { return null; }
    $n = round((float) $raw, 2);
    return $n > 0 ? $n : null;
};

if ($vehiclesReady && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_vehicle') {
    $vName = trim($_POST['v_name'] ?? '');
    $vReg  = strtoupper(trim($_POST['v_reg'] ?? ''));
    $vType = trim($_POST['v_type'] ?? '');
    $vJump = $vehLimitValue($_POST['v_jump'] ?? '');
    $vTank = $vehTankValue($_POST['v_tank'] ?? '');
    if ($vName === '' || $vReg === '') {
        $error = 'A vehicle needs a name and a registration number.';
    } else {
        try {
            if ($vehTuningReady) {
                $pdo->prepare('INSERT INTO vehicles (name, registration, vehicle_type, jump_limit_km, tank_litres, created_by_id)
                               VALUES (?, ?, ?, ?, ?, ?)
                               ON DUPLICATE KEY UPDATE name = VALUES(name), vehicle_type = VALUES(vehicle_type),
                                                       jump_limit_km = VALUES(jump_limit_km),
                                                       tank_litres = VALUES(tank_litres), is_active = 1')
                    ->execute([$vName, $vReg, $vType !== '' ? $vType : null, $vJump, $vTank, $_SESSION['user_id']]);
            } else {
                $pdo->prepare('INSERT INTO vehicles (name, registration, vehicle_type, created_by_id)
                               VALUES (?, ?, ?, ?)
                               ON DUPLICATE KEY UPDATE name = VALUES(name), vehicle_type = VALUES(vehicle_type), is_active = 1')
                    ->execute([$vName, $vReg, $vType !== '' ? $vType : null, $_SESSION['user_id']]);
            }
            audit_log($pdo, 'vehicle_saved', "Saved vehicle \"$vName\" ($vReg)", $_SESSION['user_id']);
            $success = "Vehicle \"$vName\" saved.";
        } catch (PDOException $e) {
            $error = 'Could not save that vehicle. The registration may already be in use.';
        }
    }
}

if ($vehiclesReady && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_vehicles') {
    $vn = $_POST['v_name_e'] ?? [];
    $vt = $_POST['v_type_e'] ?? [];
    $vj = $_POST['v_jump_e'] ?? [];
    $vk = $_POST['v_tank_e'] ?? [];
    $va = $_POST['v_active'] ?? [];
    // The jump limit changes what every historic figure for this vehicle means,
    // so a change to it is audited by name and old/new value rather than folded
    // into the row count. veh_limit_for() is the only reader, and it caches per
    // request, so the next page load picks the new value up.
    $before = [];
    if ($vehTuningReady) {
        foreach ($pdo->query('SELECT id, name, jump_limit_km FROM vehicles')->fetchAll() as $b) {
            $before[(int) $b['id']] = $b;
        }
    }
    $upd = $vehTuningReady
        ? $pdo->prepare('UPDATE vehicles SET name = ?, vehicle_type = ?, jump_limit_km = ?, tank_litres = ?, is_active = ? WHERE id = ?')
        : $pdo->prepare('UPDATE vehicles SET name = ?, vehicle_type = ?, is_active = ? WHERE id = ?');
    $saved = 0; $limitNotes = [];
    foreach ($vn as $id => $rawName) {
        $id = (int) $id; $nm = trim($rawName);
        if ($id <= 0 || $nm === '') { continue; }
        $ty = trim($vt[$id] ?? '');
        if ($vehTuningReady) {
            $jl = $vehLimitValue($vj[$id] ?? '');
            $tk = $vehTankValue($vk[$id] ?? '');
            $upd->execute([$nm, $ty !== '' ? $ty : null, $jl, $tk, isset($va[$id]) ? 1 : 0, $id]);
            $old = isset($before[$id]) && $before[$id]['jump_limit_km'] !== null
                       ? (int) $before[$id]['jump_limit_km'] : null;
            if ($old !== $jl) {
                $limitNotes[] = sprintf('%s: %s -> %s', $nm,
                    $old === null ? 'default' : $old . ' km',
                    $jl === null ? 'default' : $jl . ' km');
            }
        } else {
            $upd->execute([$nm, $ty !== '' ? $ty : null, isset($va[$id]) ? 1 : 0, $id]);
        }
        $saved++;
    }
    if ($saved > 0) {
        audit_log($pdo, 'vehicles_saved', "Bulk-saved $saved vehicle(s)", $_SESSION['user_id']);
        if ($limitNotes) {
            audit_log($pdo, 'vehicle_jump_limit_changed',
                      'Jump limit changed — ' . implode('; ', $limitNotes), $_SESSION['user_id']);
        }
        $success = "Saved $saved vehicle" . ($saved === 1 ? '' : 's') . '.';
    }
}

$vehicles = [];
if ($vehiclesReady) {
    try {
        $vehicles = $pdo->query('
            SELECT v.*,
                   (SELECT COUNT(*) FROM expenses e WHERE e.vehicle_id = v.id) AS expense_count,
                   (SELECT MAX(e.meter_reading) FROM expenses e
                     WHERE e.vehicle_id = v.id AND e.voided_at IS NULL) AS last_meter
              FROM vehicles v ORDER BY v.is_active DESC, v.name
        ')->fetchAll();
    } catch (PDOException $e) { $vehicles = []; }
}

// ---- Save the overall per-shift limit ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_shift_limit') {
    $limit = ec_amt($_POST['shift_limit_total'] ?? 0);
    $pdo->prepare('
        INSERT INTO clinic_settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ')->execute(['expense_shift_limit_total', (string) $limit]);
    audit_log($pdo, 'expense_shift_limit_saved', "Set overall per-shift expense limit to Rs $limit", $_SESSION['user_id']);
    $success = $limit > 0
        ? 'Overall per-shift limit set to Rs ' . number_format($limit) . ' per user.'
        : 'Overall per-shift limit removed — postings are only bounded by category limits now.';
}

$shiftLimitStmt = $pdo->prepare("SELECT setting_value FROM clinic_settings WHERE setting_key = 'expense_shift_limit_total'");
$shiftLimitStmt->execute();
$shiftLimitTotal = (float) ($shiftLimitStmt->fetchColumn() ?: 0);

$categories = $pdo->query('
    SELECT ec.*,
           (SELECT COUNT(*) FROM expenses e WHERE e.category_id = ec.id) AS expense_count,
           (SELECT COALESCE(SUM(e.amount), 0) FROM expenses e
             WHERE e.category_id = ec.id AND e.voided_at IS NULL AND e.expense_date = CURDATE()) AS today_total
    FROM expense_categories ec ORDER BY ec.name
')->fetchAll();

$pageTitle = 'Expense Categories';
$headExtra = <<<CSS
<style>
/* The page header styles are gone with the header itself — the shared
   app bar brings its own from assets/app.css. */

.add-row { display: grid; grid-template-columns: 2fr 1fr auto; gap: 10px; align-items: end; }
.add-row label { font-size: 11.5px; font-weight: 600; color: var(--text-secondary); display: block; margin-bottom: 5px; }
.add-row input { width: 100%; padding: 9px 11px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 13.5px; background: var(--bg); }
.add-row input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); background: #fff; }

.row-inp { padding: 7px 9px; border: 1px solid var(--border); border-radius: 8px; font: inherit; font-size: 12.5px; background: #fff; max-width: 100%; }
.row-inp:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); }
.btn.small { padding: 7px 14px; font-size: 12.5px; }
.row-inactive td { opacity: .5; }
.link-btn { background: none; border: none; color: var(--primary); font: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer; padding: 0; }
.link-btn.warn { color: var(--red-text); }
.amt-inp { width: 110px; text-align: right; }
.count-chip { font-size: 11.5px; font-weight: 700; color: var(--text-secondary); background: var(--bg); border: 1px solid var(--border); border-radius: 20px; padding: 3px 10px; white-space: nowrap; }
.count-chip.hot { color: #92400E; background: rgba(245,158,11,.12); border-color: rgba(245,158,11,.30); }
.note-box { font-size: 12.5px; color: var(--text-secondary); background: var(--primary-light); border-radius: 10px; padding: 12px 16px; margin-bottom: 18px; line-height: 1.6; }
.limit-row { display: flex; align-items: end; gap: 12px; flex-wrap: wrap; }
.limit-row label { font-size: 11.5px; font-weight: 600; color: var(--text-secondary); display: block; margin-bottom: 5px; }
.limit-row input { padding: 9px 11px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 13.5px; background: var(--bg); width: 180px; text-align: right; }
.limit-row input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); background: #fff; }
.muted-inline { font-size: 12px; color: var(--text-muted); align-self: center; padding-bottom: 10px; }
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
$navActive = 'expense_categories';
require __DIR__ . '/partials/sidebar.php';
?>
        <?php /* The page's own mini-header (title + date + Logout) is gone: the
                 shared app bar above carries date and Logout on every page,
                 and the title is repeated in .page-head just below. */ ?>

        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Expense Categories &amp; Limits</div>
                    <div class="page-sub">What counter cash may be spent on, and how much per shift</div>
                </div>
                <a class="btn" href="expenses.php" style="text-decoration:none;">View Expenses</a>
            </div>

            <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <div class="note-box">
                Reception posts expenses from <a href="expenses.php" style="font-weight:600;">Expenses</a>; the cash comes out of
                the counter drawer. A shift is one calendar day. Two caps apply at posting time:
                the <strong>per-category limit</strong> below (all users combined), and the
                <strong>overall per-shift limit</strong> (per posting user, across all categories).
                Set either to 0 to remove that cap. Your own postings as admin bypass both limits.
            </div>

            <!-- Overall per-shift limit -->
            <div class="card">
                <div class="section-title">Overall Per-Shift Limit</div>
                <div class="section-sub">The most one user may pay out of the counter in a single shift, across all categories.</div>
                <form method="POST" action="expense_categories.php">
                    <input type="hidden" name="action" value="save_shift_limit">
                    <div class="limit-row">
                        <div>
                            <label>Limit per user per shift (Rs)</label>
                            <input type="number" step="1" min="0" name="shift_limit_total" value="<?= htmlspecialchars(rtrim(rtrim(number_format($shiftLimitTotal, 2, '.', ''), '0'), '.')) ?>">
                        </div>
                        <button type="submit" class="btn">Save Limit</button>
                        <span class="muted-inline">0 = no overall cap</span>
                    </div>
                </form>
            </div>

            <!-- Add a category -->
            <div class="card">
                <div class="section-title">Add a Category</div>
                <div class="section-sub">Re-adding an existing name updates its limit and re-activates it.</div>
                <form method="POST" action="expense_categories.php">
                    <input type="hidden" name="action" value="add_category">
                    <div class="add-row">
                        <div>
                            <label>Category name</label>
                            <input type="text" name="name" placeholder="e.g. Courier Charges" required>
                        </div>
                        <div>
                            <label>Shift limit (Rs, 0 = none)</label>
                            <input type="number" step="1" min="0" name="shift_limit" value="0">
                        </div>
                        <button type="submit" class="btn">Add</button>
                    </div>
                </form>
            </div>

            <!-- Vehicles. Only rendered once add_vehicle_expenses.sql has run;
                 the fuel/maintenance/repairs sub-categories are seeded by that
                 migration and are not editable here (they drive report logic). -->
            <?php if ($vehiclesReady): ?>
            <div class="card">
                <div class="section-title">Vehicles</div>
                <div class="section-sub">
                    Any number of vehicles. These appear on the posting form for
                    <b>Transport &amp; Fuel</b>, and drive the
                    <a href="vehicle_report.php" style="font-weight:600;">Vehicle Report</a>'s
                    cost-per-km figures. Deactivate rather than delete — history stays intact.
                </div>

                <form method="POST" action="expense_categories.php" style="margin-bottom:18px;">
                    <input type="hidden" name="action" value="add_vehicle">
                    <div class="add-row" style="grid-template-columns:<?= $vehTuningReady ? '1.8fr 1.1fr 1.1fr 1fr .8fr auto' : '2fr 1.2fr 1.2fr auto' ?>;">
                        <div>
                            <label>Vehicle name</label>
                            <input type="text" name="v_name" placeholder="e.g. Suzuki Bolan" required>
                        </div>
                        <div>
                            <label>Registration</label>
                            <input type="text" name="v_reg" placeholder="e.g. LES 4471" required
                                   style="text-transform:uppercase;">
                        </div>
                        <div>
                            <label>Type (optional)</label>
                            <input type="text" name="v_type" placeholder="Ambulance / Bike">
                        </div>
                        <?php if ($vehTuningReady): ?>
                        <div>
                            <label>Jump limit (km)</label>
                            <input type="number" name="v_jump" min="1" step="1"
                                   placeholder="<?= (int) VEH_MAX_PLAUSIBLE_GAP ?>">
                        </div>
                        <div>
                            <label>Tank (L)</label>
                            <input type="number" name="v_tank" min="1" step="0.01" placeholder="—">
                        </div>
                        <?php endif; ?>
                        <button type="submit" class="btn">Add</button>
                    </div>
                    <?php if ($vehTuningReady): ?>
                    <div class="muted-note" style="margin-top:8px;">
                        <b>Jump limit</b> is the largest believable distance between two meter
                        readings for this vehicle. A bigger gap is treated as a typo or a missed
                        posting and its distance is discarded — the money still counts.
                        Leave blank for the <?= number_format(VEH_MAX_PLAUSIBLE_GAP) ?> km default.
                        <b>Tank</b> is a reference only; no figure is calculated from it.
                    </div>
                    <?php endif; ?>
                </form>

                <form method="POST" action="expense_categories.php">
                <input type="hidden" name="action" value="save_vehicles">
                <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Vehicle</th>
                            <th style="width:150px;">Registration</th>
                            <th style="width:160px;">Type</th>
                            <?php if ($vehTuningReady): ?>
                            <th style="width:118px;" title="Largest believable gap between two readings for this vehicle">Jump limit</th>
                            <th style="width:96px;" title="Reference only — no calculation uses it">Tank (L)</th>
                            <?php endif; ?>
                            <th style="width:130px;">Last meter</th>
                            <th style="width:110px;">Expenses</th>
                            <th style="width:90px;">Active</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$vehicles): ?>
                        <tr><td colspan="<?= $vehTuningReady ? 8 : 6 ?>" class="muted" style="padding:20px 10px;">
                            No vehicles yet — add one above so reception can post fuel against it.
                        </td></tr>
                        <?php endif; ?>
                        <?php foreach ($vehicles as $v): $vid = (int) $v['id']; ?>
                        <tr class="<?= $v['is_active'] ? '' : 'row-inactive' ?>">
                            <td><input type="text" name="v_name_e[<?= $vid ?>]" class="row-inp"
                                       style="font-weight:600;width:100%;" value="<?= htmlspecialchars($v['name']) ?>"></td>
                            <td><span class="count-chip"><?= htmlspecialchars($v['registration']) ?></span></td>
                            <td><input type="text" name="v_type_e[<?= $vid ?>]" class="row-inp" style="width:100%;"
                                       value="<?= htmlspecialchars($v['vehicle_type'] ?? '') ?>"></td>
                            <?php if ($vehTuningReady): ?>
                            <?php /* Blank shows the default as a placeholder rather than pre-filling
                                     5000, so saving the row does not silently freeze today's default
                                     into the vehicle and stop it following a future change. */ ?>
                            <td><input type="number" name="v_jump_e[<?= $vid ?>]" class="row-inp" min="1" step="1"
                                       style="width:100%;" placeholder="<?= (int) VEH_MAX_PLAUSIBLE_GAP ?>"
                                       value="<?= $v['jump_limit_km'] !== null ? (int) $v['jump_limit_km'] : '' ?>"></td>
                            <td><input type="number" name="v_tank_e[<?= $vid ?>]" class="row-inp" min="1" step="0.01"
                                       style="width:100%;" placeholder="—"
                                       value="<?= $v['tank_litres'] !== null ? rtrim(rtrim(number_format((float) $v['tank_litres'], 2, '.', ''), '0'), '.') : '' ?>"></td>
                            <?php endif; ?>
                            <td><span class="count-chip"><?= $v['last_meter'] !== null ? number_format((int) $v['last_meter']) . ' km' : '—' ?></span></td>
                            <td><span class="count-chip"><?= (int) $v['expense_count'] ?> posted</span></td>
                            <td><label class="active-toggle"><input type="checkbox" name="v_active[<?= $vid ?>]" value="1"
                                       <?= $v['is_active'] ? 'checked' : '' ?>><span></span></label></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php if (!$vehTuningReady): ?>
                <div class="muted-note" style="margin-top:12px;">
                    Run <b>sql/add_fuel_accountability.sql</b> to set a <b>jump limit</b> per vehicle.
                    Until then every vehicle is measured against the same
                    <?= number_format(VEH_MAX_PLAUSIBLE_GAP) ?> km default, which is too generous
                    for a motorbike.
                </div>
                <?php endif; ?>
                <?php if ($vehicles): ?>
                <div style="display:flex;justify-content:flex-end;margin-top:16px;">
                    <button type="submit" class="btn">Save vehicles</button>
                </div>
                <?php endif; ?>
                </form>
            </div>
            <?php endif; ?>

            <!-- Category list — one form, one Save all changes button. Delete
                 stays a separate per-row action (its <form>s live after the table
                 and are triggered from the row via form="del-<id>", since forms
                 can't nest inside the bulk save form). -->
            <div class="card">
                <div class="section-title">Categories</div>
                <div class="section-sub">Edit names/limits, toggle Active, then <b>Save all changes</b> once. Inactive hides a category from the posting form without touching its history. Delete is only offered while a category has no expenses.</div>
                <form method="POST" action="expense_categories.php" id="saveAll">
                <input type="hidden" name="action" value="save_categories">
                <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th style="width:140px;">Shift limit (Rs)</th>
                            <th style="width:130px;">Spent today</th>
                            <th style="width:110px;">Expenses</th>
                            <th style="width:90px;">Active</th>
                            <th style="width:90px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$categories): ?>
                        <tr><td colspan="6" class="muted" style="padding:20px 10px;">No categories yet — add one above.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($categories as $c): $cid = (int) $c['id']; ?>
                        <?php
                            $limit = (float) $c['shift_limit'];
                            $today = (float) $c['today_total'];
                            $nearCap = $limit > 0 && $today >= $limit * 0.8;
                        ?>
                        <tr class="<?= $c['is_active'] ? '' : 'row-inactive' ?>">
                            <td>
                                <input type="text" name="name[<?= $cid ?>]" class="row-inp" style="font-weight:600;width:100%;" value="<?= htmlspecialchars($c['name']) ?>">
                            </td>
                            <td><input type="number" step="1" min="0" name="shift_limit[<?= $cid ?>]" class="row-inp amt-inp" value="<?= htmlspecialchars(rtrim(rtrim(number_format($limit, 2, '.', ''), '0'), '.')) ?>"></td>
                            <td><span class="count-chip <?= $nearCap ? 'hot' : '' ?>">Rs <?= number_format($today) ?><?= $limit > 0 ? ' / ' . number_format($limit) : '' ?></span></td>
                            <td><span class="count-chip"><?= (int) $c['expense_count'] ?> posted</span></td>
                            <td><label class="active-toggle"><input type="checkbox" name="is_active[<?= $cid ?>]" value="1" <?= $c['is_active'] ? 'checked' : '' ?>><span></span></label></td>
                            <td>
                                <?php if ((int) $c['expense_count'] === 0): ?>
                                <button type="submit" form="del-<?= $cid ?>" class="link-btn warn">Delete</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php if ($categories): ?>
                <div style="display:flex;justify-content:flex-end;margin-top:16px;">
                    <button type="submit" class="btn">Save all changes</button>
                </div>
                <?php endif; ?>
                </form>

                <!-- Per-row delete forms (outside the bulk form; referenced by the
                     row's Delete button via form="del-<id>"). -->
                <?php foreach ($categories as $c): if ((int) $c['expense_count'] !== 0) continue; ?>
                <form method="POST" action="expense_categories.php" id="del-<?= (int) $c['id'] ?>" style="display:none;"
                      onsubmit="return confirm('Delete this category? It has no expenses recorded.');">
                    <input type="hidden" name="action" value="delete_category">
                    <input type="hidden" name="category_id" value="<?= (int) $c['id'] ?>">
                </form>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>
