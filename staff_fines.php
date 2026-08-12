<?php
/**
 * Staff Fines — post a disciplinary/attendance fine against an employee,
 * review the fine record with a monthly filter, and bulk-clear a person's
 * outstanding fines.
 *
 * Gated on MANAGERIAL_POST_FINE — granted to ADMIN and MANAGER by default
 * (sql/add_employee_fines.sql), same as the rest of the Manager oversight
 * bundle. Anyone holding it can post and clear; there's no separate
 * approve-tier here (unlike Expenses' post/approve split) because Manager IS
 * the oversight role for this action.
 *
 * Clearing has two modes, chosen per bulk-clear batch, never silent:
 *   Forgive        — waived, no money moves. Status -> FORGIVEN.
 *   Deduct salary  — admin/manager is recording this WAS taken out of the
 *                    employee's pay (done manually outside the system — HIMS
 *                    has no per-employee payroll ledger, only the manual
 *                    "Salaries" expense category). Status -> DEDUCTED.
 * Both are terminal and stamped with who/when. A fine left PENDING never
 * auto-resolves.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);
require_permission('MANAGERIAL_POST_FINE');

$userId = (int) $_SESSION['user_id'];
$error = '';
$success = '';

// ---- Post a fine ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'post_fine') {
    $empId  = (int) ($_POST['employee_id'] ?? 0);
    $amount = round((float) ($_POST['amount'] ?? 0), 2);
    $reason = trim($_POST['reason'] ?? '');
    $fineDate = trim($_POST['fine_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fineDate)) {
        $fineDate = date('Y-m-d');
    }

    $chk = $pdo->prepare('SELECT id, name FROM users WHERE id = ? AND base_role <> "ADMIN"');
    $chk->execute([$empId]);
    $emp = $chk->fetch();

    if (!$emp) {
        $error = 'Select a valid employee.';
    } elseif ($amount <= 0) {
        $error = 'Amount must be greater than zero.';
    } elseif ($reason === '') {
        $error = 'A reason is required.';
    } else {
        $pdo->prepare('
            INSERT INTO employee_fines (employee_id, amount, reason, fine_date, posted_by_id)
            VALUES (?, ?, ?, ?, ?)
        ')->execute([$empId, $amount, $reason, $fineDate, $userId]);

        audit_log($pdo, 'employee_fine_posted', "Posted a fine of Rs. " . number_format($amount, 0) . " for \"{$emp['name']}\": $reason", $userId);
        $success = 'Fine of Rs. ' . number_format($amount, 0) . ' posted for ' . htmlspecialchars($emp['name']) . '.';
    }
}

// ---- Bulk-clear an employee's outstanding fines ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_fines') {
    $empId = (int) ($_POST['employee_id'] ?? 0);
    $mode  = $_POST['clear_mode'] ?? '';
    $fineIds = array_values(array_filter(array_map('intval', (array) ($_POST['fine_ids'] ?? []))));
    $status = $mode === 'deduct' ? 'DEDUCTED' : ($mode === 'forgive' ? 'FORGIVEN' : '');

    $chk = $pdo->prepare('SELECT id, name FROM users WHERE id = ?');
    $chk->execute([$empId]);
    $emp = $chk->fetch();

    if (!$emp) {
        $error = 'Select a valid employee.';
    } elseif ($status === '') {
        $error = 'Choose Forgive or Deduct from salary.';
    } elseif (!$fineIds) {
        $error = 'No fines were selected to clear.';
    } else {
        $ph = implode(',', array_fill(0, count($fineIds), '?'));
        $upd = $pdo->prepare("
            UPDATE employee_fines SET status = ?, cleared_at = NOW(), cleared_by_id = ?
            WHERE id IN ($ph) AND employee_id = ? AND status = 'PENDING'
        ");
        $upd->execute(array_merge([$status, $userId], $fineIds, [$empId]));
        $cleared = $upd->rowCount();

        if ($cleared > 0) {
            $verb = $status === 'DEDUCTED' ? 'deducted from salary' : 'forgiven';
            audit_log($pdo, 'employee_fines_cleared', "Marked $cleared fine(s) as $verb for \"{$emp['name']}\"", $userId);
            $success = "Cleared $cleared fine(s) for " . htmlspecialchars($emp['name']) . ' — marked ' . ($status === 'DEDUCTED' ? 'Deducted' : 'Forgiven') . '.';
        } else {
            $error = 'Nothing to clear — those fines are no longer pending.';
        }
    }
}

// ---- Data for the page ----
$employees = $pdo->query("SELECT id, name, base_role FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

$fines = $pdo->query("
    SELECT f.*, e.name AS emp_name, c.name AS cleared_by_name, p.name AS posted_by_name
    FROM employee_fines f
    JOIN users e ON e.id = f.employee_id
    LEFT JOIN users c ON c.id = f.cleared_by_id
    LEFT JOIN users p ON p.id = f.posted_by_id
    ORDER BY f.fine_date DESC, f.created_at DESC
    LIMIT 500
")->fetchAll();

$outstandingTotal = 0.0;
foreach ($fines as $f) {
    if ($f['status'] === 'PENDING') { $outstandingTotal += (float) $f['amount']; }
}

$statusTone = ['PENDING' => 'warn', 'FORGIVEN' => 'brand', 'DEDUCTED' => 'ok'];
$statusLabel = ['PENDING' => 'Pending', 'FORGIVEN' => 'Forgiven', 'DEDUCTED' => 'Deducted'];

$pageTitle = 'Staff Fines';
$headExtra = <<<CSS
<style>
.add-row { display: grid; grid-template-columns: 1.6fr 1fr 1fr 2fr auto; gap: 10px; align-items: end; }
.add-row label { font-size: 11.5px; font-weight: 600; color: var(--text-secondary); display: block; margin-bottom: 5px; }
.add-row input, .add-row select { width: 100%; padding: 9px 11px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 13.5px; background: var(--bg); }
.add-row input:focus, .add-row select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); background: #fff; }

.filter-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
.filter-row select, .filter-row input { padding: 8px 11px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 13px; background: #fff; }

.stat-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 18px; }
.stat-card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; }
.stat-card .lbl { font-size: 11.5px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .03em; margin-bottom: 6px; }
.stat-card .val { font-size: 22px; font-weight: 700; }

.chk-col { width: 36px; }
tr.fine-row.is-cleared { opacity: .55; }
.clear-bar { display: none; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; background: var(--warn-bg); border: 1px solid var(--border); border-radius: 10px; padding: 10px 14px; margin-bottom: 12px; font-size: 13px; }
.clear-bar.show { display: flex; }
.clear-bar .count { font-weight: 700; }
.clear-bar .actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.clear-bar select { padding: 7px 10px; border: 1px solid var(--border); border-radius: 8px; font: inherit; font-size: 12.5px; }
.reason-cell { max-width: 240px; white-space: normal; }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'staff_fines';
require __DIR__ . '/partials/sidebar.php';
?>
        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Staff Fines</div>
                    <div class="page-sub">Post a fine against an employee, and clear it once resolved &mdash; forgiven or deducted from salary</div>
                </div>
            </div>

            <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <div class="stat-row">
                <div class="stat-card">
                    <div class="lbl">Outstanding (pending)</div>
                    <div class="val" style="color:var(--danger)">Rs. <?= number_format($outstandingTotal, 0) ?></div>
                </div>
                <div class="stat-card">
                    <div class="lbl">Fines shown</div>
                    <div class="val"><?= count($fines) ?></div>
                </div>
            </div>

            <!-- Post a fine -->
            <div class="card" style="margin-bottom:18px;">
                <div class="section-title">Post a Fine</div>
                <div class="section-sub">Applies to one employee. Date defaults to today.</div>
                <form method="POST" action="staff_fines.php">
                    <input type="hidden" name="action" value="post_fine">
                    <div class="add-row">
                        <div>
                            <label>Employee</label>
                            <select name="employee_id" required>
                                <option value="">&mdash; Select Employee &mdash;</option>
                                <?php foreach ($employees as $e): ?>
                                <option value="<?= (int) $e['id'] ?>"><?= htmlspecialchars($e['name']) ?> <?= $e['base_role'] === 'DOCTOR' ? '(Doctor)' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Amount (Rs.)</label>
                            <input type="number" name="amount" min="1" step="0.01" placeholder="e.g. 500" required>
                        </div>
                        <div>
                            <label>Date</label>
                            <input type="date" name="fine_date" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div>
                            <label>Reason</label>
                            <input type="text" name="reason" placeholder="e.g. Late arrival, uniform violation..." maxlength="255" required>
                        </div>
                        <button type="submit" class="btn">Post Fine</button>
                    </div>
                </form>
            </div>

            <!-- Records + filter + bulk clear -->
            <div class="card">
                <div class="section-title">Fine Records</div>
                <div class="section-sub">Filter by employee and month, then select pending fines to clear in bulk.</div>

                <div class="filter-row">
                    <input type="text" id="srch" placeholder="Search employee or reason..." oninput="ffFilter()">
                    <select id="srchEmp" onchange="ffFilter()">
                        <option value="">All Employees</option>
                        <?php
                        $seenEmp = [];
                        foreach ($fines as $f) {
                            if (isset($seenEmp[$f['employee_id']])) { continue; }
                            $seenEmp[$f['employee_id']] = true;
                            echo '<option value="' . (int) $f['employee_id'] . '">' . htmlspecialchars($f['emp_name']) . '</option>';
                        }
                        ?>
                    </select>
                    <select id="srchStatus" onchange="ffFilter()">
                        <option value="">All Statuses</option>
                        <option value="PENDING">Pending</option>
                        <option value="FORGIVEN">Forgiven</option>
                        <option value="DEDUCTED">Deducted</option>
                    </select>
                    <select id="srchMonth" onchange="ffFilter()">
                        <option value="">All Months</option>
                        <?php
                        $seenMonth = [];
                        foreach ($fines as $f) {
                            $m = date('Y-m', strtotime($f['fine_date']));
                            if (isset($seenMonth[$m])) { continue; }
                            $seenMonth[$m] = true;
                            echo '<option value="' . $m . '">' . date('F Y', strtotime($f['fine_date'])) . '</option>';
                        }
                        ?>
                    </select>
                    <button type="button" class="btn" onclick="ffClear()">Clear Filters</button>
                </div>

                <!-- Bulk clear bar: appears once a row is checked. Selection must be
                     confined to ONE employee (a clear batch is always per-employee,
                     mirroring how it's posted), enforced client-side before submit. -->
                <form method="POST" action="staff_fines.php" id="clearForm">
                    <input type="hidden" name="action" value="clear_fines">
                    <input type="hidden" name="employee_id" id="clearEmpId" value="">
                    <div class="clear-bar" id="clearBar">
                        <span><span class="count" id="clearCount">0</span> fine(s) selected</span>
                        <div class="actions">
                            <select name="clear_mode" required>
                                <option value="">&mdash; Choose action &mdash;</option>
                                <option value="forgive">Forgive (waive, no salary impact)</option>
                                <option value="deduct">Deduct from salary</option>
                            </select>
                            <button type="submit" class="btn">Clear Selected</button>
                        </div>
                    </div>

                    <div style="overflow-x:auto;">
                    <table id="finesTable">
                        <thead>
                            <tr>
                                <th class="chk-col"><input type="checkbox" id="chkAll" onchange="ffToggleAll(this)"></th>
                                <th>Employee</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Posted By</th>
                                <th>Cleared</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$fines): ?>
                            <tr><td colspan="8" class="muted" style="padding:20px 10px;">No fines posted yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($fines as $f):
                                $isPending = $f['status'] === 'PENDING';
                            ?>
                            <tr class="fine-row <?= $isPending ? '' : 'is-cleared' ?>"
                                data-emp="<?= (int) $f['employee_id'] ?>"
                                data-name="<?= htmlspecialchars(strtolower($f['emp_name'])) ?>"
                                data-reason="<?= htmlspecialchars(strtolower($f['reason'])) ?>"
                                data-status="<?= htmlspecialchars($f['status']) ?>"
                                data-month="<?= date('Y-m', strtotime($f['fine_date'])) ?>">
                                <td>
                                    <?php if ($isPending): ?>
                                    <input type="checkbox" class="row-chk" data-emp="<?= (int) $f['employee_id'] ?>" data-id="<?= (int) $f['id'] ?>" onchange="ffRowToggle()">
                                    <?php endif; ?>
                                </td>
                                <td><strong><?= htmlspecialchars($f['emp_name']) ?></strong></td>
                                <td><?= date('d/m/Y', strtotime($f['fine_date'])) ?></td>
                                <td><strong style="color:var(--danger)">Rs. <?= number_format((float) $f['amount'], 0) ?></strong></td>
                                <td class="reason-cell"><span class="muted"><?= htmlspecialchars($f['reason']) ?></span></td>
                                <td><span class="pill pill--<?= $statusTone[$f['status']] ?>"><?= $statusLabel[$f['status']] ?></span></td>
                                <td class="muted"><?= htmlspecialchars($f['posted_by_name'] ?? '—') ?></td>
                                <td class="muted">
                                    <?php if (!$isPending): ?>
                                        <?= htmlspecialchars($f['cleared_by_name'] ?? '—') ?><br>
                                        <span style="font-size:11px;"><?= $f['cleared_at'] ? date('d/m/Y', strtotime($f['cleared_at'])) : '' ?></span>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
function ffFilter() {
    var q = (document.getElementById('srch').value || '').toLowerCase();
    var emp = document.getElementById('srchEmp').value;
    var status = document.getElementById('srchStatus').value;
    var month = document.getElementById('srchMonth').value;
    document.querySelectorAll('#finesTable tbody tr.fine-row').forEach(function (row) {
        var matches = true;
        if (q && row.dataset.name.indexOf(q) === -1 && row.dataset.reason.indexOf(q) === -1) { matches = false; }
        if (emp && row.dataset.emp !== emp) { matches = false; }
        if (status && row.dataset.status !== status) { matches = false; }
        if (month && row.dataset.month !== month) { matches = false; }
        row.style.display = matches ? '' : 'none';
    });
}
function ffClear() {
    document.getElementById('srch').value = '';
    document.getElementById('srchEmp').value = '';
    document.getElementById('srchStatus').value = '';
    document.getElementById('srchMonth').value = '';
    ffFilter();
}
// A clear batch must stay within one employee. Checking a row for a
// different employee than the current selection clears the prior picks —
// this keeps the "who is this batch for" question unambiguous, matching how
// posting is always one employee at a time.
function ffRowToggle() {
    var checked = Array.prototype.slice.call(document.querySelectorAll('.row-chk:checked'));
    if (checked.length) {
        var empId = checked[0].dataset.emp;
        checked.forEach(function (cb) {
            if (cb.dataset.emp !== empId) { cb.checked = false; }
        });
        checked = Array.prototype.slice.call(document.querySelectorAll('.row-chk:checked'));
    }
    var bar = document.getElementById('clearBar');
    var count = document.getElementById('clearCount');
    var empField = document.getElementById('clearEmpId');
    var form = document.getElementById('clearForm');
    document.querySelectorAll('#clearForm input[name="fine_ids[]"]').forEach(function (el) { el.remove(); });
    if (checked.length) {
        bar.classList.add('show');
        count.textContent = checked.length;
        empField.value = checked[0].dataset.emp;
        checked.forEach(function (cb) {
            var hid = document.createElement('input');
            hid.type = 'hidden';
            hid.name = 'fine_ids[]';
            hid.value = cb.dataset.id;
            form.appendChild(hid);
        });
    } else {
        bar.classList.remove('show');
        empField.value = '';
    }
}
function ffToggleAll(master) {
    document.querySelectorAll('.row-chk').forEach(function (cb) {
        if (cb.closest('tr').style.display !== 'none') { cb.checked = master.checked; }
    });
    ffRowToggle();
}
</script>
<script src="assets/js/date-picker.js?v=<?= @filemtime(__DIR__ . "/assets/js/date-picker.js") ?: 1 ?>"></script>
</body>
</html>
