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
 */
require_once __DIR__ . '/config/guard_admin.php';

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
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$_SESSION['user_id'], 'expense_category_saved', "Saved expense category \"$name\""]);
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
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$_SESSION['user_id'], 'expense_categories_saved', "Bulk-saved $saved expense categor" . ($saved === 1 ? 'y' : 'ies')]);
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
            $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
                ->execute([$_SESSION['user_id'], 'expense_category_deleted', "Deleted unused expense category #$id"]);
            $success = 'Category deleted.';
        }
    }
}

// ---- Save the overall per-shift limit ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_shift_limit') {
    $limit = ec_amt($_POST['shift_limit_total'] ?? 0);
    $pdo->prepare('
        INSERT INTO clinic_settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ')->execute(['expense_shift_limit_total', (string) $limit]);
    $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
        ->execute([$_SESSION['user_id'], 'expense_shift_limit_saved', "Set overall per-shift expense limit to Rs $limit"]);
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
.header { height: 72px; position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; background: rgba(255,255,255,.80); backdrop-filter: blur(18px); border-bottom: 1px solid var(--border); }
.header-right { display: flex; align-items: center; gap: 18px; margin-left: auto; }
.header-date { font-size: 13px; color: var(--text-secondary); white-space: nowrap; }
.logout-link { font-size: 13px; color: var(--text-secondary); font-weight: 500; }

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
        <header class="header">
            <div class="page-title" style="font-size:16px;">Expense Categories</div>
            <div class="header-right">
                <span class="header-date"><?= date('D, d/m/Y') ?></span>
                <a class="logout-link" href="logout.php">Logout</a>
            </div>
        </header>

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
