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

$rows = $pdo->query("
    SELECT v.id AS visit_id, v.token_no, v.consult_status, v.created_at,
           p.mrn, p.name AS full_name, p.phone,
           d.name AS doctor_name
    FROM visits v
    JOIN patients p ON p.id = v.patient_id
    LEFT JOIN users d ON d.id = v.doctor_id
    WHERE v.visit_date = CURDATE() AND v.disposition = 'SHORT_STAY'
    ORDER BY v.created_at DESC
")->fetchAll();

$qhActive = 'admissions';

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
?>

<?php require __DIR__ . '/partials/quick_header.php'; ?>

<div class="content">
    <div>
        <div class="page-title">Admissions</div>
        <div class="page-sub">Short-stay patients admitted today &mdash; <?= date('l, d F Y') ?></div>
    </div>

    <div class="card">
        <?php if (!$rows): ?>
            <div class="empty">
                <strong>No admissions today</strong>
                Patients marked as a short stay at registration will appear here.
            </div>
        <?php else: ?>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Token</th>
                        <th>Patient</th>
                        <th>Phone</th>
                        <th>Doctor</th>
                        <th>Consult</th>
                        <th>Admitted</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="mrn">#<?= htmlspecialchars((string) $r['token_no']) ?></td>
                        <td>
                            <div class="name"><?= htmlspecialchars($r['full_name']) ?></div>
                            <div class="mrn"><?= htmlspecialchars($r['mrn']) ?></div>
                        </td>
                        <td><?= htmlspecialchars($r['phone'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($r['doctor_name'] ?: '—') ?></td>
                        <td>
                            <?php
                            $cs = $r['consult_status'];
                            $cls = $cs === 'WAITING' ? 'waiting' : ($cs === 'IN_CONSULT' ? 'in-consult' : 'done');
                            $lbl = $cs === 'WAITING' ? 'Waiting' : ($cs === 'IN_CONSULT' ? 'In Consult' : 'Done');
                            ?>
                            <span class="status-pill <?= $cls ?>"><?= $lbl ?></span>
                        </td>
                        <td><?= date('h:i A', strtotime($r['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
