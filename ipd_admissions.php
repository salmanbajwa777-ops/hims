<?php
/**
 * In-Door (IPD) ward list — currently-admitted in-patients.
 *
 * The IPD counterpart to admissions.php (which is ER short-stay). Lists active
 * IPD stays (not date-scoped, so a multi-day stay still shows) plus today's
 * discharged, each linking to the per-stay page (ipd_admission.php). Also hosts
 * the "Admit to In-Door" modal so an in-patient can be admitted from here.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/notify.php';
require_once __DIR__ . '/config/ipd_actions.php';
require_once __DIR__ . '/config/tokens.php';
refresh_session_permissions($pdo);

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
if (!$user) { session_destroy(); header('Location: /index.php'); exit; }

require_permission('IPD_VIEW_WARD');

$flash = '';
$err = '';

// ---- Admit to In-Door (shared handler) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ipd_admit_patient') {
    $res = handle_ipd_admit($pdo);
    if ($res['ok']) {
        header('Location: ipd_admission.php?id=' . (int) $res['admission_id'] . '&admitted=1');
        exit;
    }
    $err = $res['error'];
}

$firstName = explode(' ', trim($user['name']))[0] ?? 'there';

// Active first, then today's discharged. Joined for patient/consultant/ward.
$rows = $pdo->query("
    SELECT a.id AS admission_id, a.status, a.admitted_at, a.ward, a.room_no,
           v.token_no,
           p.mrn, p.name AS full_name, p.phone,
           COALESCE(du.name, a.admitting_consultant_manual) AS consultant_name,
           -- Prefix from the VISIT's doctor, not the consultant — see admissions.php.
           vd.name AS token_doctor_name, vd.token_prefix
    FROM ipd_admissions a
    JOIN visits v ON v.id = a.visit_id
    JOIN patients p ON p.id = v.patient_id
    LEFT JOIN users vd ON vd.id = v.doctor_id
    LEFT JOIN users du ON du.id = a.admitting_consultant_id
    WHERE a.status <> 'DISCHARGED' OR a.discharge_finalized_at >= CURDATE()
    ORDER BY (a.status = 'DISCHARGED'), a.admitted_at DESC
")->fetchAll();

$qhActive = 'ipd';
$qhBrand  = false;

$pageTitle = 'In-Door (IPD)';
$headExtra = <<<CSS
<style>
.page-title { letter-spacing: -.02em; }
.card { padding: 0; }
.table-scroll { overflow-x: auto; }
table { min-width: 820px; }
th { padding: 14px 16px; border-bottom: 1px solid var(--border); white-space: nowrap; }
td { padding: 13px 16px; }
tbody tr:first-child td { border-top: none; }
.mrn { font-variant-numeric: tabular-nums; color: var(--text-muted); font-size: 12.5px; }
.name { font-weight: 600; }
.status-pill.active { background: var(--green-bg); color: var(--green-text); }
.status-pill.progress { background: #EDE7FB; color: #6D28D9; }
.status-pill.done { background: #F1F5F9; color: var(--text-secondary); }
.empty strong { display: block; font-size: 15px; color: var(--text); margin-bottom: 6px; font-weight: 600; }
.admit-cta { display:flex; justify-content:flex-end; margin-bottom:14px; }
CSS;
$headExtra .= "\n</style>";
require __DIR__ . '/partials/head.php';
$navActive = 'ipd';
require __DIR__ . '/partials/sidebar.php';
?>
        <?php require __DIR__ . '/partials/quick_header.php'; ?>

<div class="content">
    <div>
        <div class="page-title">In-Door (IPD)</div>
        <div class="page-sub">Admitted in-patients &mdash; <?= date('l, d/m/Y') ?> &middot; <span class="muted">admit an in-patient from the <a href="patients.php" style="color:var(--primary);font-weight:600;">Patients</a> list</span></div>
    </div>

    <?php if ($flash): ?><div class="alert success"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <div class="card">
        <?php if (!$rows): ?>
            <div class="empty">
                <strong>No in-patients admitted</strong>
                Admit a patient to In-Door from the Patients list and the stay will appear here.
            </div>
        <?php else: ?>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Token</th>
                        <th>Patient</th>
                        <th>Ward / Room</th>
                        <th>Consultant</th>
                        <th>Status</th>
                        <th>Admitted</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $stPill = [
                    'ACTIVE' => ['active', 'Active'],
                    'DISCHARGE_IN_PROGRESS' => ['progress', 'Discharge in progress'],
                    'DISCHARGED' => ['done', 'Discharged'],
                ];
                foreach ($rows as $r):
                    [$cls, $lbl] = $stPill[$r['status']] ?? ['done', $r['status']]; ?>
                    <tr>
                        <td class="mrn"><?= htmlspecialchars(token_code($r['token_prefix'] ?? null, $r['token_doctor_name'] ?? '', $r['token_no'])) ?></td>
                        <td>
                            <div class="name"><?= htmlspecialchars($r['full_name']) ?></div>
                            <div class="mrn"><?= htmlspecialchars($r['mrn']) ?></div>
                        </td>
                        <td><?= htmlspecialchars($r['ward']) ?> &middot; Room <?= (int) $r['room_no'] ?></td>
                        <td><?= htmlspecialchars($r['consultant_name'] ?: '—') ?></td>
                        <td><span class="status-pill <?= $cls ?>"><?= $lbl ?></span></td>
                        <td><?= date('d/m, h:i A', strtotime($r['admitted_at'])) ?></td>
                        <td>
                            <a class="edit-link" href="ipd_admission.php?id=<?= (int) $r['admission_id'] ?>" style="color:var(--primary);font-weight:600;font-size:12.5px;">Manage &rarr;</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
    </div>
</div>
</body>
</html>
