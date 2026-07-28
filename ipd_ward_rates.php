<?php
/**
 * In-Door (IPD) Ward Rates — admin catalogue.
 *
 * Set each ward's per-day bed charge and its daily consultant visit fee, add
 * new wards, and enable/disable them. Stored, not hardcoded — consumed by the
 * IPD admit modal and (Phase 4) the IPD billing engine.
 *
 * Gated on IPD_MANAGE_WARD_RATES (admin by default; permission-driven so a
 * per-user grant works too).
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);

require_permission('IPD_MANAGE_WARD_RATES');

$error = '';
$success = '';
$uid = (int) $_SESSION['user_id'];

// ---- Add a new ward ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_ward') {
    $name = trim($_POST['ward'] ?? '');
    $rate = (float) ($_POST['per_day_rate'] ?? 0);
    $fee  = (float) ($_POST['consultant_visit_fee'] ?? 0);
    if ($name === '') {
        $error = 'Enter a ward name.';
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO ipd_ward_rates (ward, per_day_rate, consultant_visit_fee, is_enabled, updated_by_id)
            VALUES (?, ?, ?, 1, ?)
            ON DUPLICATE KEY UPDATE per_day_rate = VALUES(per_day_rate), consultant_visit_fee = VALUES(consultant_visit_fee), is_enabled = 1, updated_by_id = VALUES(updated_by_id)
        ');
        $stmt->execute([$name, $rate, $fee, $uid]);
        $success = "Ward \"$name\" saved.";
    }
}

// ---- Save all wards in one submit ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_wards') {
    $rates    = $_POST['per_day_rate'] ?? [];          // [id => amount]
    $fees     = $_POST['consultant_visit_fee'] ?? [];  // [id => amount]
    $enabled  = $_POST['enabled'] ?? [];               // [id => '1']
    $upd = $pdo->prepare('UPDATE ipd_ward_rates SET per_day_rate = ?, consultant_visit_fee = ?, is_enabled = ?, updated_by_id = ? WHERE id = ?');
    foreach ($rates as $id => $amt) {
        $id = (int) $id;
        $upd->execute([(float) $amt, (float) ($fees[$id] ?? 0), isset($enabled[$id]) ? 1 : 0, $uid, $id]);
    }
    audit_log($pdo, 'ipd_ward_rates_updated', 'Updated IPD ward rates', $uid);
    $success = 'Ward rates saved.';
}

$wards = $pdo->query('SELECT * FROM ipd_ward_rates ORDER BY ward')->fetchAll();

$pageTitle = 'In-Door Ward Rates';
$headExtra = <<<CSS
<style>
.page-title { letter-spacing: -.02em; }
.card { padding: 20px; }
.ward-table { width: 100%; border-collapse: collapse; }
.ward-table th { text-align: left; font-size: 10.5px; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); font-weight: 700; padding: 8px 10px; border-bottom: 1px solid var(--border); }
.ward-table td { padding: 10px; border-bottom: 1px solid var(--border); }
.row-inp { padding: 8px 10px; border: 1px solid var(--border); border-radius: 9px; font: inherit; font-size: 13px; background: #fff; width: 130px; }
.row-inp:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); }
.add-row { display: grid; grid-template-columns: 1.4fr 1fr 1fr auto; gap: 10px; align-items: end; margin-bottom: 20px; }
.add-row label { font-size: 11.5px; font-weight: 600; color: var(--text-secondary); display: block; margin-bottom: 5px; }
.add-row input { width: 100%; padding: 9px 11px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 13.5px; background: var(--bg); }
.add-row input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); background: #fff; }
.active-toggle { display: inline-flex; align-items: center; cursor: pointer; }
.active-toggle input { position: absolute; opacity: 0; width: 0; height: 0; }
.active-toggle span { width: 40px; height: 22px; border-radius: 20px; background: var(--border); position: relative; transition: background .15s; display: inline-block; }
.active-toggle span::after { content: ''; position: absolute; top: 2px; left: 2px; width: 18px; height: 18px; border-radius: 50%; background: #fff; transition: transform .15s; box-shadow: 0 1px 2px rgba(0,0,0,.2); }
.active-toggle input:checked + span { background: var(--primary); }
.active-toggle input:checked + span::after { transform: translateX(18px); }
.cur { color: var(--text-muted); font-weight: 600; font-size: 12px; }
CSS;
$headExtra .= "\n</style>";
require __DIR__ . '/partials/head.php';
$navActive = 'ipd_ward_rates';
require __DIR__ . '/partials/sidebar.php';
?>
        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">In-Door Ward Rates</div>
                    <div class="page-sub">Per-day bed charge and daily consultant visit fee for each ward</div>
                </div>
            </div>

            <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <div class="card">
                <form method="POST" action="ipd_ward_rates.php">
                    <input type="hidden" name="action" value="add_ward">
                    <div class="add-row">
                        <div><label>New ward</label><input type="text" name="ward" placeholder="e.g. Semi-Private" required></div>
                        <div><label>Per-day rate (Rs)</label><input type="number" step="0.01" min="0" name="per_day_rate" value="0"></div>
                        <div><label>Consultant visit fee (Rs)</label><input type="number" step="0.01" min="0" name="consultant_visit_fee" value="0"></div>
                        <button type="submit" class="btn">Add ward</button>
                    </div>
                </form>

                <form method="POST" action="ipd_ward_rates.php">
                    <input type="hidden" name="action" value="save_wards">
                    <table class="ward-table">
                        <thead>
                            <tr>
                                <th>Ward</th>
                                <th>Per-day rate</th>
                                <th>Consultant visit fee</th>
                                <th>Active</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$wards): ?>
                            <tr><td colspan="4" class="muted" style="padding:18px 10px;">No wards yet — add one above.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($wards as $w): ?>
                            <tr>
                                <td style="font-weight:600;"><?= htmlspecialchars($w['ward']) ?></td>
                                <td><span class="cur">Rs</span> <input class="row-inp" type="number" step="0.01" min="0" name="per_day_rate[<?= (int) $w['id'] ?>]" value="<?= htmlspecialchars((string) $w['per_day_rate']) ?>"></td>
                                <td><span class="cur">Rs</span> <input class="row-inp" type="number" step="0.01" min="0" name="consultant_visit_fee[<?= (int) $w['id'] ?>]" value="<?= htmlspecialchars((string) $w['consultant_visit_fee']) ?>"></td>
                                <td>
                                    <label class="active-toggle">
                                        <input type="checkbox" name="enabled[<?= (int) $w['id'] ?>]" value="1" <?= $w['is_enabled'] ? 'checked' : '' ?>>
                                        <span></span>
                                    </label>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($wards): ?>
                    <div style="margin-top:16px;"><button type="submit" class="btn">Save all</button></div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>
