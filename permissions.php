<?php
require_once __DIR__ . '/config/guard_admin.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';

$roles = ['ADMIN', 'DOCTOR', 'MANAGER', 'ACCOUNTANT', 'NURSE', 'RECEPTIONIST'];
$categoryLabels = [
    'clinical' => 'Clinical & Nursing',
    'financial' => 'Financial',
    'admin' => 'Admin & Reception',
];

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_role_permissions') {
    $role = $_POST['base_role'] ?? '';
    $checkedIds = array_map('intval', $_POST['permission_ids'] ?? []);

    if (!in_array($role, $roles, true)) {
        $error = 'Invalid role.';
    } else {
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

$allPermissions = $pdo->query('SELECT id, `key`, label, category FROM permissions ORDER BY category, label')->fetchAll();
$byCategory = [];
foreach ($allPermissions as $p) {
    $byCategory[$p['category']][] = $p;
}

$grantsByRole = [];
foreach ($pdo->query('SELECT base_role, permission_id FROM role_permissions')->fetchAll() as $row) {
    $grantsByRole[$row['base_role']][(int) $row['permission_id']] = true;
}

$activeRole = $_GET['role'] ?? 'ADMIN';
if (!in_array($activeRole, $roles, true)) {
    $activeRole = 'ADMIN';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HIMS — Permissions</title>
<style>
:root {
    --primary-dark: #1E3A8A;
    --primary: #2563EB;
    --primary-light: #DBEAFE;
    --green: #10B981;
    --green-bg: #ECFDF5;
    --green-text: #047857;
    --amber: #F59E0B;
    --amber-bg: #FFFBEB;
    --amber-text: #92400E;
    --red: #DC2626;
    --red-bg: #FEF2F2;
    --red-text: #B91C1C;
    --bg: #F8FAFC;
    --card: #FFFFFF;
    --text: #0F172A;
    --text-secondary: #64748B;
    --text-muted: #94A3B8;
    --border: #E2E8F0;
    --border-strong: #CBD5E1;
    --shadow-sm: 0 2px 8px rgba(15,23,42,.05);
    --shadow-md: 0 10px 25px rgba(15,23,42,.08);
    --radius-card: 20px;
    --radius-input: 12px;
    --radius-btn: 14px;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--bg); color: var(--text); font-size: 14px; line-height: 1.5; }
a { text-decoration: none; color: inherit; }
.app { display: grid; grid-template-columns: 280px 1fr; min-height: 100vh; }
.main { display: flex; flex-direction: column; min-width: 0; }
.content { padding: 28px 32px 60px; display: flex; flex-direction: column; gap: 24px; }

.sidebar { background: var(--card); border-right: 1px solid var(--border); padding: 24px 16px; position: sticky; top: 0; height: 100vh; overflow-y: auto; }
.sidebar-brand { display: flex; align-items: center; gap: 10px; padding: 0 8px 24px; font-weight: 700; font-size: 18px; }
.sidebar-brand .logo-mark { width: 34px; height: 34px; border-radius: 10px; background: linear-gradient(135deg, var(--primary-dark), var(--primary)); display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; font-size: 14px; }
.nav-group { margin-bottom: 18px; }
.nav-group-label { font-size: 11px; font-weight: 600; letter-spacing: .06em; color: var(--text-muted); padding: 0 12px 8px; text-transform: uppercase; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 12px; color: var(--text-secondary); font-weight: 500; font-size: 13.5px; transition: background .15s ease; }
.nav-item:hover { background: #F8FAFC; }
.nav-item.active { background: var(--primary-light); color: var(--primary-dark); font-weight: 600; position: relative; }
.nav-item.active::before { content: ""; position: absolute; left: -16px; top: 8px; bottom: 8px; width: 3px; background: var(--primary); border-radius: 0 3px 3px 0; }
.nav-icon { width: 28px; height: 28px; border-radius: 8px; background: #F1F5F9; display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: var(--text-secondary); }
.nav-icon svg { width: 15px; height: 15px; }
.nav-item.active .nav-icon { background: #fff; color: var(--primary-dark); }

.header { height: 72px; position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; background: rgba(255,255,255,.80); backdrop-filter: blur(18px); border-bottom: 1px solid var(--border); }
.header-right { display: flex; align-items: center; gap: 18px; margin-left: auto; }
.header-date { font-size: 13px; color: var(--text-secondary); white-space: nowrap; }
.logout-link { font-size: 13px; color: var(--text-secondary); font-weight: 500; }

.page-title { font-size: 22px; font-weight: 700; }
.page-sub { font-size: 13px; color: var(--text-muted); margin-top: 2px; }
.page-head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }

.card { background: var(--card); border-radius: var(--radius-card); border: 1px solid var(--border); box-shadow: var(--shadow-sm); padding: 22px 24px; }
.section-title { font-size: 16px; font-weight: 600; margin-bottom: 2px; }
.section-sub { font-size: 12.5px; color: var(--text-muted); margin-bottom: 16px; }

.btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: var(--radius-btn); border: none; background: linear-gradient(135deg, var(--primary-dark), var(--primary)); color: #fff; font-size: 13.5px; font-weight: 600; cursor: pointer; font-family: inherit; }
.btn:hover { opacity: .92; }
.btn.secondary { background: #fff; color: var(--text-secondary); border: 1px solid var(--border); }

.alert { border-radius: 14px; padding: 14px 18px; font-size: 13.5px; margin-bottom: 4px; }
.alert.error { background: var(--red-bg); color: var(--red-text); }
.alert.success { background: var(--green-bg); color: var(--green-text); }

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

@media (max-width: 900px) {
    .app { grid-template-columns: 1fr; }
    .sidebar { display: none; }
}
</style>
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="sidebar-brand"><div class="logo-mark">H</div>HIMS</div>
        <div class="nav-group">
            <div class="nav-group-label">Overview</div>
            <a class="nav-item" href="dashboard.php"><span class="nav-icon">▦</span> Dashboard</a>
        </div>
        <div class="nav-group">
            <div class="nav-group-label">Management</div>
            <a class="nav-item" href="staff.php"><span class="nav-icon">👥</span> Staff &amp; Doctors</a>
            <a class="nav-item active" href="permissions.php"><span class="nav-icon">🔒</span> Permissions</a>
        </div>
    </aside>

    <div class="main">
        <header class="header">
            <div class="page-title" style="font-size:16px;">Permissions</div>
            <div class="header-right">
                <span class="header-date"><?= date('D, d M Y') ?></span>
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

                    <?php foreach ($byCategory as $cat => $perms): ?>
                    <div class="perm-category">
                        <div class="perm-category-head"><?= htmlspecialchars($categoryLabels[$cat] ?? ucfirst($cat)) ?></div>
                        <?php foreach ($perms as $p): ?>
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
</body>
</html>
