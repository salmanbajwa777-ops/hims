<?php
require_once __DIR__ . '/config/guard_admin.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_city') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        $error = 'City name is required.';
    } else {
        $stmt = $pdo->prepare('INSERT INTO cities (name) VALUES (?) ON DUPLICATE KEY UPDATE name = name');
        $stmt->execute([$name]);
        $success = "City \"$name\" added.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_area') {
    $cityId = (int) ($_POST['city_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if ($cityId <= 0 || $name === '') {
        $error = 'City and area name are required.';
    } else {
        $stmt = $pdo->prepare('INSERT INTO areas (city_id, name, status) VALUES (?, ?, \'active\') ON DUPLICATE KEY UPDATE status = status');
        $stmt->execute([$cityId, $name]);
        $success = "Area \"$name\" added.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_area') {
    $areaId = (int) ($_POST['area_id'] ?? 0);
    $stmt = $pdo->prepare('DELETE FROM areas WHERE id = ?');
    $stmt->execute([$areaId]);
    $success = 'Area removed.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve_area') {
    $areaId = (int) ($_POST['area_id'] ?? 0);
    $newName = trim($_POST['rename'] ?? '');
    $mergeIntoId = (int) ($_POST['merge_into_id'] ?? 0);

    if ($mergeIntoId > 0) {
        // Repoint any patients using the pending area onto the canonical one, then drop it.
        $pdo->prepare('UPDATE patients SET area_id = ? WHERE area_id = ?')->execute([$mergeIntoId, $areaId]);
        $pdo->prepare('DELETE FROM areas WHERE id = ?')->execute([$areaId]);
        $success = 'Area merged.';
    } else {
        $stmt = $pdo->prepare('UPDATE areas SET status = \'active\'' . ($newName !== '' ? ', name = ?' : '') . ' WHERE id = ?');
        $newName !== '' ? $stmt->execute([$newName, $areaId]) : $stmt->execute([$areaId]);
        $success = 'Area approved.';
    }

    $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)');
    $log->execute([$_SESSION['user_id'], 'area_reviewed', "Reviewed pending area #$areaId" . ($mergeIntoId ? " (merged into #$mergeIntoId)" : '')]);
}

$cities = $pdo->query('SELECT id, name FROM cities ORDER BY name')->fetchAll();

$areaCounts = [];
foreach ($pdo->query('SELECT city_id, COUNT(*) AS cnt FROM areas GROUP BY city_id')->fetchAll() as $row) {
    $areaCounts[(int) $row['city_id']] = (int) $row['cnt'];
}

$patientCounts = [];
foreach ($pdo->query('SELECT area_id, COUNT(*) AS cnt FROM patients WHERE area_id IS NOT NULL GROUP BY area_id')->fetchAll() as $row) {
    $patientCounts[(int) $row['area_id']] = (int) $row['cnt'];
}

$areasByCity = [];
foreach ($pdo->query('SELECT a.*, u.name AS added_by_name FROM areas a LEFT JOIN users u ON u.id = a.added_by_id ORDER BY a.name')->fetchAll() as $a) {
    $areasByCity[(int) $a['city_id']][] = $a;
}

$pending = $pdo->query('
    SELECT a.*, c.name AS city_name, u.name AS added_by_name
    FROM areas a
    JOIN cities c ON c.id = a.city_id
    LEFT JOIN users u ON u.id = a.added_by_id
    WHERE a.status = "pending"
    ORDER BY a.created_at DESC
')->fetchAll();
?>
<?php
$pageTitle = 'Cities & Areas';
$headExtra = <<<CSS
<style>
.header { height: 72px; position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; background: rgba(255,255,255,.80); backdrop-filter: blur(18px); border-bottom: 1px solid var(--border); }
.header-right { display: flex; align-items: center; gap: 18px; margin-left: auto; }
.header-date { font-size: 13px; color: var(--text-secondary); white-space: nowrap; }
.logout-link { font-size: 13px; color: var(--text-secondary); font-weight: 500; }

.pending-alert { background: var(--amber-bg); border: 1px solid #FDE68A; border-radius: 14px; padding: 16px 18px; display: flex; align-items: flex-start; gap: 12px; }
.pending-alert svg { width: 18px; height: 18px; color: var(--amber-text); flex-shrink: 0; margin-top: 1px; }
.pending-alert .title { font-size: 13.5px; font-weight: 700; color: var(--amber-text); }
.pending-alert .sub { font-size: 12.5px; color: #92400E; opacity: .85; margin-top: 1px; }

.pending-row { display: flex; align-items: center; gap: 14px; padding: 12px 4px; border-top: 1px solid var(--border); flex-wrap: wrap; }
.pending-row .info { flex: 1; min-width: 220px; }
.pending-row .area-name { font-size: 13.5px; font-weight: 700; }
.pending-row .meta { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.pending-row form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.merge-select, .rename-input { padding: 7px 10px; border: 1px solid var(--border); border-radius: 10px; font-size: 12.5px; font-family: inherit; background: var(--bg); color: var(--text); }
.merge-select { max-width: 180px; }
.rename-input { max-width: 160px; }

.two-pane { display: grid; grid-template-columns: 320px 1fr; gap: 20px; align-items: start; }
.city-list { display: flex; flex-direction: column; gap: 4px; }
.city-row { display: flex; align-items: center; justify-content: space-between; padding: 12px 14px; border-radius: 12px; cursor: pointer; gap: 8px; }
.city-row:hover { background: var(--bg); }
.city-row.active { background: var(--primary-light); }
.city-row.active .city-name { color: var(--primary-dark); font-weight: 700; }
.city-name { font-size: 13.5px; font-weight: 600; }
.city-count { font-size: 11.5px; color: var(--text-muted); background: var(--bg); border-radius: 20px; padding: 2px 9px; flex-shrink: 0; }
.city-row.active .city-count { background: #fff; }

table { width: 100%; border-collapse: collapse; }
th { text-align: left; font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); padding: 0 10px 10px; font-weight: 600; }
td { padding: 12px 10px; border-top: 1px solid var(--border); font-size: 13.5px; }
.muted { color: var(--text-muted); font-size: 12.5px; }
.source-tag { font-size: 11px; font-weight: 600; padding: 2px 9px; border-radius: 20px; }
.source-tag.admin { background: #F1F5F9; color: var(--text-secondary); }
.source-tag.reception { background: var(--primary-light); color: var(--primary-dark); }
.status-pill { font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 20px; }
.status-pill.active { background: var(--green-bg); color: var(--green-text); }
.status-pill.pending { background: var(--amber-bg); color: var(--amber-text); }
.row-actions form { display: inline; }
.row-actions button { font-size: 12.5px; font-weight: 600; color: var(--red-text); background: none; border: none; cursor: pointer; font-family: inherit; padding: 0; }

.inline-add { display: flex; gap: 10px; margin-top: 14px; }
.inline-add input { flex: 1; padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-input); font-size: 13.5px; font-family: inherit; background: var(--bg); color: var(--text); }
.inline-add input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); background: var(--card); }
.empty-state { padding: 32px 10px; text-align: center; color: var(--text-muted); font-size: 13px; }

@media (max-width: 900px) {
    .two-pane { grid-template-columns: 1fr; }
}
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'locations';
require __DIR__ . '/partials/sidebar.php';
?>
        <header class="header">
            <div class="page-title" style="font-size:16px;">Cities &amp; Areas</div>
            <div class="header-right">
                <span class="header-date"><?= date('D, d/m/Y') ?></span>
                <a class="logout-link" href="logout.php">Logout</a>
            </div>
        </header>

        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Cities &amp; Areas</div>
                    <div class="page-sub">Reference list used across patient registration — kept clean for branch-expansion reporting</div>
                </div>
            </div>

            <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <?php if (!empty($pending)): ?>
            <div class="card">
                <div class="pending-alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg>
                    <div>
                        <div class="title"><?= count($pending) ?> area<?= count($pending) === 1 ? '' : 's' ?> added by reception, awaiting review</div>
                        <div class="sub">These are already usable on the registration form — review to fix typos or merge with an existing area.</div>
                    </div>
                </div>
                <div style="margin-top: 4px;">
                    <?php foreach ($pending as $p): ?>
                    <div class="pending-row">
                        <div class="info">
                            <div class="area-name">"<?= htmlspecialchars($p['name']) ?>" <span class="muted">— <?= htmlspecialchars($p['city_name']) ?></span></div>
                            <div class="meta">Added by <?= htmlspecialchars($p['added_by_name'] ?? 'Unknown') ?> &middot; <?= $patientCounts[(int) $p['id']] ?? 0 ?> patient(s) using it &middot; <?= date('d/m/Y', strtotime($p['created_at'])) ?></div>
                        </div>
                        <form method="POST" action="locations.php">
                            <input type="hidden" name="action" value="approve_area">
                            <input type="hidden" name="area_id" value="<?= (int) $p['id'] ?>">
                            <select name="merge_into_id" class="merge-select">
                                <option value="0">Merge into...</option>
                                <?php foreach (($areasByCity[(int) $p['city_id']] ?? []) as $a): if ($a['status'] !== 'active') continue; ?>
                                <option value="<?= (int) $a['id'] ?>"><?= htmlspecialchars($a['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="rename" class="rename-input" placeholder="Rename (optional)">
                            <button class="btn small secondary" type="submit">Approve</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="section-title">Manage Cities &amp; Areas</div>
                <div class="section-sub">Select a city to view and edit its areas</div>

                <div class="two-pane">
                    <div>
                        <div class="city-list">
                            <?php foreach ($cities as $i => $c): ?>
                            <a class="city-row<?= $i === 0 ? ' active' : '' ?>" href="#" data-city-id="<?= (int) $c['id'] ?>" onclick="selectCity(this); return false;">
                                <span class="city-name"><?= htmlspecialchars($c['name']) ?></span>
                                <span class="city-count"><?= $areaCounts[(int) $c['id']] ?? 0 ?> areas</span>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <form method="POST" action="locations.php" class="inline-add">
                            <input type="hidden" name="action" value="add_city">
                            <input type="text" name="name" placeholder="Add new city..." required>
                            <button class="btn small" type="submit">Add</button>
                        </form>
                    </div>

                    <div>
                        <?php foreach ($cities as $i => $c): ?>
                        <div class="city-panel" data-city-panel="<?= (int) $c['id'] ?>" style="<?= $i === 0 ? '' : 'display:none;' ?>">
                            <?php $areas = $areasByCity[(int) $c['id']] ?? []; ?>
                            <?php if (empty($areas)): ?>
                                <div class="empty-state">No areas added yet for <?= htmlspecialchars($c['name']) ?>.</div>
                            <?php else: ?>
                            <table>
                                <thead><tr><th>Area</th><th>Source</th><th>Status</th><th>Patients</th><th></th></tr></thead>
                                <tbody>
                                    <?php foreach ($areas as $a): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($a['name']) ?></td>
                                        <td><span class="source-tag <?= $a['added_by_id'] ? 'reception' : 'admin' ?>"><?= $a['added_by_id'] ? 'Reception' : 'Admin' ?></span></td>
                                        <td><span class="status-pill <?= $a['status'] === 'active' ? 'active' : 'pending' ?>"><?= $a['status'] === 'active' ? 'Active' : 'Pending review' ?></span></td>
                                        <td class="muted"><?= $patientCounts[(int) $a['id']] ?? 0 ?></td>
                                        <td class="row-actions">
                                            <form method="POST" action="locations.php" onsubmit="return confirm('Remove this area?');">
                                                <input type="hidden" name="action" value="remove_area">
                                                <input type="hidden" name="area_id" value="<?= (int) $a['id'] ?>">
                                                <button type="submit">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                            <form method="POST" action="locations.php" class="inline-add">
                                <input type="hidden" name="action" value="add_area">
                                <input type="hidden" name="city_id" value="<?= (int) $c['id'] ?>">
                                <input type="text" name="name" placeholder="Add new area in <?= htmlspecialchars($c['name']) ?>..." required>
                                <button class="btn small" type="submit">Add</button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function selectCity(el) {
    document.querySelectorAll('.city-row').forEach(r => r.classList.remove('active'));
    el.classList.add('active');
    const cityId = el.dataset.cityId;
    document.querySelectorAll('.city-panel').forEach(p => {
        p.style.display = p.dataset.cityPanel === cityId ? '' : 'none';
    });
}
</script>
<script src="assets/js/date-picker.js"></script>
</body>
</html>
