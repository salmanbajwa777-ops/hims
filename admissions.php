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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HIMS — Admissions</title>
<style>
:root {
    --primary-dark: #0E5456; --primary: #1A7F7E; --primary-light: #E0F2F1;
    --green: #10B981; --amber: #F59E0B; --red: #DC2626;
    --bg: #F8FAFC; --card: #FFFFFF;
    --text: #0F172A; --text-secondary: #334155; --text-muted: #64748B;
    --border: #E2E8F0;
    --shadow-sm: 0 2px 8px rgba(15,23,42,.05);
    --radius-card: 20px; --radius-input: 12px; --radius-btn: 14px;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Inter', system-ui, -apple-system, "Segoe UI", sans-serif;
    background: var(--bg); color: var(--text); font-size: 14px; line-height: 1.5;
}
a { text-decoration: none; color: inherit; }

.content { padding: 28px 32px 60px; display: flex; flex-direction: column; gap: 20px; }
.page-title { font-size: 22px; font-weight: 700; letter-spacing: -.02em; }
.page-sub { font-size: 13.5px; color: var(--text-muted); }

.card {
    background: var(--card); border: 1px solid var(--border);
    border-radius: var(--radius-card); box-shadow: var(--shadow-sm);
}
.table-scroll { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; min-width: 820px; }
th {
    text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .05em;
    color: var(--text-muted); padding: 14px 16px; border-bottom: 1px solid var(--border);
    white-space: nowrap;
}
td { padding: 13px 16px; border-top: 1px solid var(--border); font-size: 13.5px; }
tbody tr:first-child td { border-top: none; }
.mrn { font-variant-numeric: tabular-nums; color: var(--text-muted); font-size: 12.5px; }
.name { font-weight: 600; }

.status-pill {
    font-size: 11.5px; font-weight: 600; padding: 3px 9px;
    border-radius: 20px; white-space: nowrap; display: inline-block;
}
.status-pill.waiting { background: #FFFBEB; color: #92400E; }
.status-pill.in-consult { background: #ECFDF5; color: #047857; }
.status-pill.done { background: #F1F5F9; color: var(--text-secondary); }

.empty { padding: 56px 24px; text-align: center; color: var(--text-muted); }
.empty strong { display: block; font-size: 15px; color: var(--text); margin-bottom: 6px; font-weight: 600; }
</style>
</head>
<body>

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
