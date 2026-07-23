<?php
/**
 * Discount Categories — admin catalogue.
 *
 * The clinic's standing discount schemes (Family & Friends / Charity /
 * Loyalty, extendable). Each category carries three separate rates —
 * consultation, ER services, procedures — and admin assigns a category to a
 * patient from the patient list; all future invoices then auto-discount.
 * Rates are snapshotted onto each bill at billing time, so editing a rate
 * here never changes past invoices. The printed slip stays generic
 * ("Discount") — category names are internal, for month-end reporting.
 */
require_once __DIR__ . '/config/guard_admin.php';

$error = '';
$success = '';

// Percentages clamped 0–100; 100 = fully free (a 0-payable invoice is still raised).
function dc_pct($v): float {
    return round(min(100, max(0, (float) $v)), 2);
}

// ---- Add a category ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_category') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        $error = 'The category needs a name.';
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO discount_categories (name, consultation_pct, er_services_pct, procedures_pct, created_by_id)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE consultation_pct = VALUES(consultation_pct),
                er_services_pct = VALUES(er_services_pct), procedures_pct = VALUES(procedures_pct), is_active = 1
        ');
        $stmt->execute([
            $name, dc_pct($_POST['consultation_pct'] ?? 0),
            dc_pct($_POST['er_services_pct'] ?? 0), dc_pct($_POST['procedures_pct'] ?? 0),
            $_SESSION['user_id'],
        ]);
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$_SESSION['user_id'], 'discount_category_saved', "Saved discount category \"$name\""]);
        $success = "Category \"$name\" saved.";
    }
}

// ---- Edit a category (name + the three rates) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_category') {
    $id = (int) ($_POST['category_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if ($id > 0 && $name !== '') {
        $pdo->prepare('UPDATE discount_categories SET name = ?, consultation_pct = ?, er_services_pct = ?, procedures_pct = ? WHERE id = ?')
            ->execute([
                $name, dc_pct($_POST['consultation_pct'] ?? 0),
                dc_pct($_POST['er_services_pct'] ?? 0), dc_pct($_POST['procedures_pct'] ?? 0), $id,
            ]);
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$_SESSION['user_id'], 'discount_category_updated', "Updated discount category #$id (\"$name\")"]);
        $success = 'Category updated. New rates apply to future invoices only.';
    } else {
        $error = 'A category needs a name.';
    }
}

// ---- Toggle active/inactive (assigned patients keep the link; an inactive
//      category simply stops discounting until re-activated) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_category') {
    $id = (int) ($_POST['category_id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare('UPDATE discount_categories SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?')->execute([$id]);
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$_SESSION['user_id'], 'discount_category_toggled', "Toggled discount category #$id active/inactive"]);
        $success = 'Category status changed.';
    }
}

$categories = $pdo->query('
    SELECT dc.*, (SELECT COUNT(*) FROM patients p WHERE p.discount_category_id = dc.id) AS patient_count
    FROM discount_categories dc ORDER BY dc.name
')->fetchAll();

$pageTitle = 'Discount Categories';
$headExtra = <<<CSS
<style>
.header { height: 72px; position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; background: rgba(255,255,255,.80); backdrop-filter: blur(18px); border-bottom: 1px solid var(--border); }
.header-right { display: flex; align-items: center; gap: 18px; margin-left: auto; }
.header-date { font-size: 13px; color: var(--text-secondary); white-space: nowrap; }
.logout-link { font-size: 13px; color: var(--text-secondary); font-weight: 500; }

.add-row { display: grid; grid-template-columns: 1.6fr 1fr 1fr 1fr auto; gap: 10px; align-items: end; }
.add-row label { font-size: 11.5px; font-weight: 600; color: var(--text-secondary); display: block; margin-bottom: 5px; }
.add-row input { width: 100%; padding: 9px 11px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 13.5px; background: var(--bg); }
.add-row input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); background: #fff; }

.row-inp { padding: 7px 9px; border: 1px solid var(--border); border-radius: 8px; font: inherit; font-size: 12.5px; background: #fff; max-width: 100%; }
.row-inp:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); }
.btn.small { padding: 7px 14px; font-size: 12.5px; }
.row-inactive td { opacity: .5; }
.link-btn { background: none; border: none; color: var(--primary); font: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer; padding: 0; }
.link-btn.warn { color: var(--red-text); }
.pct-inp { width: 82px; text-align: right; }
.count-chip { font-size: 11.5px; font-weight: 700; color: var(--text-secondary); background: var(--bg); border: 1px solid var(--border); border-radius: 20px; padding: 3px 10px; white-space: nowrap; }
.note-box { font-size: 12.5px; color: var(--text-secondary); background: var(--primary-light); border-radius: 10px; padding: 12px 16px; margin-bottom: 18px; line-height: 1.6; }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'discount_categories';
require __DIR__ . '/partials/sidebar.php';
?>
        <header class="header">
            <div class="page-title" style="font-size:16px;">Discount Categories</div>
            <div class="header-right">
                <span class="header-date"><?= date('D, d/m/Y') ?></span>
                <a class="logout-link" href="logout.php">Logout</a>
            </div>
        </header>

        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Discount Categories</div>
                    <div class="page-sub">Standing discount schemes assignable to patients — auto-applied to every future invoice</div>
                </div>
            </div>

            <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <div class="note-box">
                Assign a category to a patient from the <a href="patients.php" style="font-weight:600;">Patients</a> list (admin only).
                Rates are locked onto each invoice when it's raised — editing them here affects future bills only.
                Consultation discounts stack on top of follow-up pricing; on admission bills the discount applies to
                service/procedure lines only, never the room stay. 100% = fully free (a zero-payable invoice is still raised).
                The printed slip shows a generic "Discount" line — category names never appear on paper.
            </div>

            <!-- Add a category -->
            <div class="card">
                <div class="section-title">Add a Category</div>
                <div class="section-sub">Re-adding an existing name updates its rates and re-activates it.</div>
                <form method="POST" action="discount_categories.php">
                    <input type="hidden" name="action" value="add_category">
                    <div class="add-row">
                        <div>
                            <label>Category name</label>
                            <input type="text" name="name" placeholder="e.g. Staff Family" required>
                        </div>
                        <div>
                            <label>Consultation (%)</label>
                            <input type="number" step="0.5" min="0" max="100" name="consultation_pct" value="0">
                        </div>
                        <div>
                            <label>ER Services (%)</label>
                            <input type="number" step="0.5" min="0" max="100" name="er_services_pct" value="0">
                        </div>
                        <div>
                            <label>Procedures (%)</label>
                            <input type="number" step="0.5" min="0" max="100" name="procedures_pct" value="0">
                        </div>
                        <button type="submit" class="btn">Add</button>
                    </div>
                </form>
            </div>

            <!-- Category list -->
            <div class="card">
                <div class="section-title">Categories</div>
                <div class="section-sub">Set each rate per billing area. Deactivating pauses the discount without unassigning patients.</div>
                <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th style="width:120px;">Consultation</th>
                            <th style="width:120px;">ER Services</th>
                            <th style="width:120px;">Procedures</th>
                            <th style="width:110px;">Patients</th>
                            <th style="width:150px;">Update</th>
                            <th style="width:90px;">Status</th>
                            <th style="width:110px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$categories): ?>
                        <tr><td colspan="8" class="muted" style="padding:20px 10px;">No categories yet — add one above.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($categories as $c): ?>
                        <tr class="<?= $c['is_active'] ? '' : 'row-inactive' ?>">
                            <td>
                                <input type="text" name="name" form="edit-<?= (int) $c['id'] ?>" class="row-inp" style="font-weight:600;width:100%;" value="<?= htmlspecialchars($c['name']) ?>">
                            </td>
                            <td><input type="number" step="0.5" min="0" max="100" name="consultation_pct" form="edit-<?= (int) $c['id'] ?>" class="row-inp pct-inp" value="<?= htmlspecialchars((string) $c['consultation_pct']) ?>"></td>
                            <td><input type="number" step="0.5" min="0" max="100" name="er_services_pct" form="edit-<?= (int) $c['id'] ?>" class="row-inp pct-inp" value="<?= htmlspecialchars((string) $c['er_services_pct']) ?>"></td>
                            <td><input type="number" step="0.5" min="0" max="100" name="procedures_pct" form="edit-<?= (int) $c['id'] ?>" class="row-inp pct-inp" value="<?= htmlspecialchars((string) $c['procedures_pct']) ?>"></td>
                            <td><span class="count-chip"><?= (int) $c['patient_count'] ?> assigned</span></td>
                            <td>
                                <form method="POST" action="discount_categories.php" id="edit-<?= (int) $c['id'] ?>" style="margin:0;">
                                    <input type="hidden" name="action" value="edit_category">
                                    <input type="hidden" name="category_id" value="<?= (int) $c['id'] ?>">
                                    <button type="submit" class="btn small">Save changes</button>
                                </form>
                            </td>
                            <td><?= $c['is_active'] ? '<span class="status-pill active">Active</span>' : '<span class="status-pill on-leave">Inactive</span>' ?></td>
                            <td>
                                <form method="POST" action="discount_categories.php" style="display:inline;margin:0;">
                                    <input type="hidden" name="action" value="toggle_category">
                                    <input type="hidden" name="category_id" value="<?= (int) $c['id'] ?>">
                                    <button type="submit" class="link-btn <?= $c['is_active'] ? 'warn' : '' ?>"><?= $c['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
