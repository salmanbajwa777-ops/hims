<?php
require_once __DIR__ . '/config/guard_admin.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';

// Three base roles now: ADMIN (holds everything), DOCTOR (clinical), STAFF
// (every desk/ward worker — capabilities come entirely from permissions).
$roles = ['ADMIN', 'DOCTOR', 'STAFF'];
// Category order + labels drive the grouped layout on this screen. Keys are
// re-categorized by sql/rbac_overhaul_3_categories.sql into these five buckets.
$categoryLabels = [
    'reception' => 'Front Desk & Reception',
    'nursing'   => 'Nursing & Ward',
    'clinical'  => 'Clinical',
    'financial' => 'Money & Billing',
    'admin'     => 'System Administration',
];

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_role_permissions') {
    $role = $_POST['base_role'] ?? '';
    $checkedIds = array_map('intval', $_POST['permission_ids'] ?? []);

    if (!in_array($role, $roles, true)) {
        $error = 'Invalid role.';
    } else {
        // ADMIN is the "all" role by definition. Now that the code gates no longer
        // carry an `|| in_array($role,['ADMIN',...])` fallback, ADMIN's access lives
        // entirely in role_permissions — so this screen must never be able to save
        // ADMIN with a key unchecked, or an admin could accidentally lock admins out.
        // Force the full catalog for ADMIN regardless of which boxes were posted.
        if ($role === 'ADMIN') {
            $checkedIds = array_map(
                fn($r) => (int) $r['id'],
                $pdo->query('SELECT id FROM permissions')->fetchAll()
            );
        }

        $pdo->beginTransaction();

        $del = $pdo->prepare('DELETE FROM role_permissions WHERE base_role = ?');
        $del->execute([$role]);

        if ($checkedIds) {
            $placeholders = implode(',', array_fill(0, count($checkedIds), '(?, ?)'));
            $params = [];
            foreach ($checkedIds as $id) {
                $params[] = $role;
                $params[] = $id;
            }
            $ins = $pdo->prepare("INSERT INTO role_permissions (base_role, permission_id) VALUES $placeholders");
            $ins->execute($params);
        }

        $pdo->commit();

        $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)');
        $log->execute([
            $_SESSION['user_id'],
            'role_permissions_updated',
            "Updated default permissions for role $role (" . count($checkedIds) . ' permissions)',
        ]);

        $success = "Default permissions updated for " . ucfirst(strtolower($role)) . '.';
    }
}

$allPermissions = $pdo->query('SELECT id, `key`, label, category FROM permissions ORDER BY label')->fetchAll();
$byCategory = [];
foreach ($allPermissions as $p) {
    $byCategory[$p['category']][] = $p;
}
// Render in the intended heading order, not alphabetically. Known categories
// first (in $categoryLabels order), then any legacy/stray category, appended.
$orderedCategories = array_merge(
    array_values(array_filter(array_keys($categoryLabels), fn($c) => isset($byCategory[$c]))),
    array_values(array_diff(array_keys($byCategory), array_keys($categoryLabels)))
);

$grantsByRole = [];
foreach ($pdo->query('SELECT base_role, permission_id FROM role_permissions')->fetchAll() as $row) {
    $grantsByRole[$row['base_role']][(int) $row['permission_id']] = true;
}

$activeRole = $_GET['role'] ?? 'ADMIN';
if (!in_array($activeRole, $roles, true)) {
    $activeRole = 'ADMIN';
}

$pageTitle = 'Permissions';
// Page-specific chrome + components kept local; tokens/reset/.card/.btn/.alert
// now come from app.css, and the sidebar from partials/sidebar.php.
$headExtra = <<<CSS
<style>
.header { height: 72px; position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; background: rgba(255,255,255,.80); backdrop-filter: blur(18px); border-bottom: 1px solid var(--border); }
.header-right { display: flex; align-items: center; gap: 18px; margin-left: auto; }
.header-date { font-size: 13px; color: var(--text-secondary); white-space: nowrap; }
.logout-link { font-size: 13px; color: var(--text-secondary); font-weight: 500; }

.role-tabs { display: flex; gap: 8px; flex-wrap: wrap; }
.role-tab { padding: 9px 16px; border-radius: 20px; font-size: 13px; font-weight: 600; color: var(--text-secondary); background: var(--card); border: 1px solid var(--border); }
.role-tab:hover { border-color: var(--primary); color: var(--primary); }
.role-tab.active { background: var(--primary-light); color: var(--primary-dark); border-color: var(--primary-light); }
.role-tab .count { font-size: 11px; font-weight: 700; color: var(--text-muted); margin-left: 4px; }
.role-tab.active .count { color: var(--primary-dark); }

.perm-category { margin-bottom: 4px; }
.perm-category-head { font-size: 12.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); padding: 14px 4px 8px; border-top: 1px solid var(--border); }
.perm-category:first-child .perm-category-head { border-top: none; padding-top: 0; }
.perm-row { display: flex; align-items: center; gap: 12px; padding: 10px 4px; border-radius: 10px; }
.perm-row:hover { background: var(--bg); }
.perm-row label { font-size: 13.5px; color: var(--text); cursor: pointer; flex: 1; }
.perm-row input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--primary); cursor: pointer; flex-shrink: 0; }
.perm-key { font-size: 11px; color: var(--text-muted); font-family: 'Courier New', monospace; }

.form-footer { display: flex; align-items: center; justify-content: flex-end; gap: 10px; padding-top: 16px; margin-top: 8px; border-top: 1px solid var(--border); }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'permissions';
require __DIR__ . '/partials/sidebar.php';
?>
        <header class="header">
            <div class="page-title" style="font-size:16px;">Permissions</div>
            <div class="header-right">
                <span class="header-date"><?= date('D, d/m/Y') ?></span>
                <a class="logout-link" href="logout.php">Logout</a>
            </div>
        </header>

        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Permissions</div>
                    <div class="page-sub">Default permission set granted to each base role. Individual overrides are set per-person on the Staff &amp; Doctors page.</div>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <div class="role-tabs">
                <?php foreach ($roles as $r): ?>
                <?php $count = count($grantsByRole[$r] ?? []); ?>
                <a class="role-tab <?= $r === $activeRole ? 'active' : '' ?>" href="permissions.php?role=<?= urlencode($r) ?>">
                    <?= ucfirst(strtolower($r)) ?><span class="count">(<?= $count ?>)</span>
                </a>
                <?php endforeach; ?>
            </div>

            <div class="card">
                <div class="section-title"><?= ucfirst(strtolower($activeRole)) ?> — Default Permissions</div>
                <div class="section-sub">Check everything this role should be able to do out of the box. Admin can still grant or revoke individual permissions per staff member.</div>

                <form method="POST" action="permissions.php?role=<?= urlencode($activeRole) ?>">
                    <input type="hidden" name="action" value="save_role_permissions">
                    <input type="hidden" name="base_role" value="<?= htmlspecialchars($activeRole) ?>">

                    <?php foreach ($orderedCategories as $cat): ?>
                    <div class="perm-category">
                        <div class="perm-category-head"><?= htmlspecialchars($categoryLabels[$cat] ?? ucfirst($cat)) ?></div>
                        <?php foreach ($byCategory[$cat] as $p): ?>
                        <?php $checked = isset($grantsByRole[$activeRole][(int) $p['id']]); ?>
                        <div class="perm-row">
                            <input type="checkbox" id="perm_<?= (int) $p['id'] ?>" name="permission_ids[]" value="<?= (int) $p['id'] ?>" <?= $checked ? 'checked' : '' ?>>
                            <label for="perm_<?= (int) $p['id'] ?>">
                                <?= htmlspecialchars($p['label']) ?>
                                <div class="perm-key"><?= htmlspecialchars($p['key']) ?></div>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>

                    <div class="form-footer">
                        <button type="submit" class="btn">Save Default Permissions</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script src="assets/js/date-picker.js"></script>
</body>
</html>
