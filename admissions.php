<?php
/**
 * Admissions — today's short-stay patients.
 *
 * The queue on receptionist.php already flags SHORT_STAY dispositions but only
 * ever counted them. This page is that count opened up. Admit/discharge state
 * transitions are not built yet, so the page reports rather than acts.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: /index.php');
    exit;
}

// Ward work: reception staff and nurses both need this, as do admins.
$baseRole = $_SESSION['base_role'] ?? '';
if ($baseRole !== 'NURSE' && $baseRole !== 'ADMIN' && !has_permission('RECEPTION_REGISTER_PATIENTS')) {
    http_response_code(403);
    exit('Forbidden — ward access only.');
}

$firstName = explode(' ', trim($user['name']))[0] ?? 'there';

// Currently-admitted first (not date-scoped, so a stay crossing midnight still
// shows), then today's discharged. Joined to the admissions record for status,
// nurse and the manage link.
$rows = $pdo->query("
    SELECT a.id AS admission_id, a.status, a.admitted_at, a.admission_type,
           v.token_no,
           p.mrn, p.name AS full_name, p.phone,
           COALESCE(du.name, a.admitting_doctor_manual) AS doctor_name,
           nu.name AS nurse_name
    FROM admissions a
    JOIN visits v ON v.id = a.visit_id
    JOIN patients p ON p.id = v.patient_id
    LEFT JOIN users du ON du.id = a.admitting_doctor_id
    LEFT JOIN users nu ON nu.id = a.assigned_nurse_id
    WHERE a.status <> 'DISCHARGED' OR a.discharge_finalized_at >= CURDATE()
    ORDER BY (a.status = 'DISCHARGED'), a.admitted_at DESC
")->fetchAll();

$qhActive = 'admissions';
$qhBrand  = false; // the sidebar already carries the HIMS mark

$pageTitle = 'Admissions';
// Page-specific: the wide table needs a horizontal scroll floor, and this
// page shows a card with no inner padding (the table sits flush). Everything
// else (tokens, .content, base table, .status-pill, .empty) comes from app.css.
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
.status-pill.waiting { background: var(--amber-bg); color: var(--amber-text); }
.status-pill.in-consult { background: var(--green-bg); color: var(--green-text); }
.status-pill.done { background: #F1F5F9; color: var(--text-secondary); }
.empty strong { display: block; font-size: 15px; color: var(--text); margin-bottom: 6px; font-weight: 600; }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'admissions';
require __DIR__ . '/partials/sidebar.php';
?>
        <?php require __DIR__ . '/partials/quick_header.php'; ?>

<div class="content">
    <div>
        <div class="page-title">Admissions</div>
        <div class="page-sub">Short-stay patients admitted today &mdash; <?= date('l, d F Y') ?></div>
    </div>

    <?php
    // Surface nurse-submitted discharges to whoever can bill them.
    $awaitingBilling = array_filter($rows, fn($r) => $r['status'] === 'DISCHARGE_IN_PROGRESS');
    $canBillBanner = has_permission('RECEPTION_PROCESS_PAYMENTS') || in_array($baseRole, ['ADMIN','MANAGER'], true);
    if ($awaitingBilling && $canBillBanner): ?>
    <div class="alert" style="background:var(--amber-bg);color:var(--amber-text);font-weight:600;">
        <?= count($awaitingBilling) ?> discharge<?= count($awaitingBilling) > 1 ? 's' : '' ?> awaiting billing —
        review the charges and generate the invoice<?= count($awaitingBilling) > 1 ? 's' : '' ?> below.
    </div>
    <?php endif; ?>

    <div class="card">
        <?php if (!$rows): ?>
            <div class="empty">
                <strong>No active admissions</strong>
                Admit a patient from the reception queue and the stay will appear here.
            </div>
        <?php else: ?>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Token</th>
                        <th>Patient</th>
                        <th>Type</th>
                        <th>Doctor</th>
                        <th>Nurse</th>
                        <th>Status</th>
                        <th>Admitted</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $stApill = [
                    'PENDING_ASSIGNMENT' => ['pending', 'Awaiting nurse'],
                    'ACTIVE' => ['in-consult', 'Active'],
                    'DISCHARGE_IN_PROGRESS' => ['waiting', 'Awaiting billing'],
                    'DISCHARGED' => ['done', 'Discharged'],
                ];
                // Billing-capable users get a direct "Bill now" action on stays
                // a nurse has submitted for discharge — that hand-off is the
                // whole point of the DISCHARGE_IN_PROGRESS state.
                $canBillList = has_permission('RECEPTION_PROCESS_PAYMENTS') || in_array($baseRole, ['ADMIN','MANAGER'], true);
                foreach ($rows as $r):
                    [$cls, $lbl] = $stApill[$r['status']] ?? ['done', $r['status']]; ?>
                    <tr>
                        <td class="mrn">#<?= htmlspecialchars((string) $r['token_no']) ?></td>
                        <td>
                            <div class="name"><?= htmlspecialchars($r['full_name']) ?></div>
                            <div class="mrn"><?= htmlspecialchars($r['mrn']) ?></div>
                        </td>
                        <td><?= htmlspecialchars($r['admission_type']) ?></td>
                        <td><?= htmlspecialchars($r['doctor_name'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($r['nurse_name'] ?: '—') ?></td>
                        <td><span class="status-pill <?= $cls ?>"><?= $lbl ?></span></td>
                        <td><?= date('h:i A', strtotime($r['admitted_at'])) ?></td>
                        <td>
                            <?php if ($r['status'] === 'DISCHARGE_IN_PROGRESS' && $canBillList): ?>
                            <a class="edit-link" href="admission_discharge.php?id=<?= (int) $r['admission_id'] ?>" style="color:var(--amber-text);font-weight:700;font-size:12.5px;">Bill now &rarr;</a>
                            <?php else: ?>
                            <a class="edit-link" href="admission.php?id=<?= (int) $r['admission_id'] ?>" style="color:var(--primary);font-weight:600;font-size:12.5px;">Manage &rarr;</a>
                            <?php endif; ?>
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
