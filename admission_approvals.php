<?php
/**
 * Admission bill approvals — the admin / manager review queue.
 *
 * Raised by admission_discharge.php whenever a short-stay bill is finalised at
 * Rs 0, short-paid, discounted, or closed as "left before treatment". Every one
 * of those is ALLOWED at the desk; this page is where someone else looks at it.
 *
 * A DECISION MOVES NO MONEY. It is a review sign-off only. A rejected row is not
 * hidden or reversed — it stays in the list as the record that a reviewer objected.
 *
 * Who decides: anyone holding ADMISSION_APPROVE_WRITEOFF, which is exactly ADMIN
 * and MANAGER (no new permission was minted for this). A requester cannot decide
 * their own row unless they are ADMIN. See adm_decide_approval().
 *
 * Reads admission_bill_approvals, which exists only after
 * sql/2026_10_admission_bill_approvals.sql — guarded, so the page says so instead
 * of fataling before the migration.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/admission_bill.php';
require_once __DIR__ . '/config/impersonation.php';   // imp_csrf_*
refresh_session_permissions($pdo);
require_permission('ADMISSION_APPROVE_WRITEOFF');

$uid      = (int) $_SESSION['user_id'];
$baseRole = $_SESSION['base_role'] ?? '';
$ready    = adm_approvals_ready($pdo);
$notice = '';
$error  = '';

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && !imp_csrf_valid($_POST['imp_csrf'] ?? null)) {
    $error = 'Your session form expired - reload the page and try again.';
} elseif ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $r = adm_decide_approval($pdo, (int) ($_POST['approval_id'] ?? 0), (string) ($_POST['decision'] ?? ''),
                             (string) ($_POST['decision_note'] ?? ''), $uid, $baseRole);
    if ($r['ok']) { $notice = 'Decision recorded.'; } else { $error = $r['error']; }
}

$filter = in_array($_GET['status'] ?? '', ['PENDING', 'APPROVED', 'REJECTED', 'ALL'], true) ? $_GET['status'] : 'PENDING';
$rows = [];
$counts = ['PENDING' => 0, 'APPROVED' => 0, 'REJECTED' => 0];
if ($ready) {
    foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM admission_bill_approvals GROUP BY status')->fetchAll() as $c) {
        $counts[$c['status']] = (int) $c['n'];
    }
    $sql = '
        SELECT ap.*, p.name AS patient_name, p.mrn,
               ab.invoice_number, ab.grand_total, ab.paid_amount,
               ru.name AS requester_name, du.name AS decider_name
        FROM admission_bill_approvals ap
        JOIN admissions a ON a.id = ap.admission_id
        JOIN visits v ON v.id = a.visit_id
        JOIN patients p ON p.id = v.patient_id
        LEFT JOIN admission_bills ab ON ab.id = ap.bill_id
        LEFT JOIN users ru ON ru.id = ap.requested_by_id
        LEFT JOIN users du ON du.id = ap.decided_by_id';
    $params = [];
    if ($filter !== 'ALL') { $sql .= ' WHERE ap.status = ?'; $params[] = $filter; }
    $sql .= ' ORDER BY ap.id DESC LIMIT 300';
    $q = $pdo->prepare($sql);
    $q->execute($params);
    $rows = $q->fetchAll();
}

$kindLabel = [
    'LEFT_BEFORE_TREATMENT' => 'Left before treatment',
    'SHORT_PAYMENT'         => 'Short payment',
    'ZERO_OR_DISCOUNT'      => 'Zero total / discount',
];

$pageTitle = 'Bill Approvals';
$headExtra = <<<CSS
<style>
.ap-tabs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
.ap-tabs a { padding:7px 14px; border:1px solid var(--border); border-radius:var(--radius-pill); font-size:13px; font-weight:600; color:var(--text-secondary); text-decoration:none; background:#CCD0CF; }
.ap-tabs a.on { background:var(--primary); border-color:var(--primary); color:#CCD0CF; }
.ap-row { border:1px solid var(--border); border-radius:12px; padding:14px 16px; margin-bottom:12px; background:#CCD0CF; }
.ap-top { display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:flex-start; }
.ap-kind { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--text-secondary); }
.ap-reason { margin:8px 0; font-size:13.5px; }
.ap-meta { font-size:12px; color:var(--text-muted); }
.ap-act { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; align-items:center; }
.ap-act input[type=text] { flex:1; min-width:180px; padding:9px 12px; border:1px solid var(--border); border-radius:10px; font:inherit; font-size:13.5px; }
.ap-st { font-size:11px; font-weight:700; padding:3px 10px; border-radius:20px; }
.ap-st.PENDING { background:var(--amber-bg); color:var(--amber-text); }
.ap-st.APPROVED { background:var(--green-bg); color:var(--green-text); }
.ap-st.REJECTED { background:#CCD0CF; color:var(--red-text); }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'admission_approvals';
require __DIR__ . '/partials/sidebar.php';
?>
        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Bill Approvals</div>
                    <div class="page-sub">Short-stay bills closed at zero, short-paid, discounted or left before treatment. A decision is a review sign-off — it moves no money.</div>
                </div>
            </div>

            <?php if ($notice): ?><div class="alert success"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <?php if (!$ready): ?>
            <div class="card"><div class="alert error" style="margin:0;">Approvals are not set up yet — run sql/2026_10_admission_bill_approvals.sql once in phpMyAdmin.</div></div>
            <?php else: ?>
            <div class="ap-tabs">
                <?php foreach (['PENDING' => 'Pending', 'APPROVED' => 'Approved', 'REJECTED' => 'Rejected', 'ALL' => 'All'] as $k => $lbl): ?>
                <a href="admission_approvals.php?status=<?= $k ?>" class="<?= $filter === $k ? 'on' : '' ?>"><?= $lbl ?><?= $k !== 'ALL' ? ' (' . $counts[$k] . ')' : '' ?></a>
                <?php endforeach; ?>
            </div>

            <?php if (!$rows): ?>
            <div class="card muted">Nothing here.</div>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
            <div class="ap-row">
                <div class="ap-top">
                    <div>
                        <div class="ap-kind"><?= htmlspecialchars($kindLabel[$r['kind']] ?? $r['kind']) ?></div>
                        <div style="font-weight:700;"><?= htmlspecialchars(strtoupper((string) $r['patient_name'])) ?> <span class="mono muted" style="font-weight:500;"><?= htmlspecialchars((string) $r['mrn']) ?></span></div>
                    </div>
                    <span class="ap-st <?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span>
                </div>
                <div class="ap-reason"><?= htmlspecialchars((string) $r['reason']) ?></div>
                <div class="ap-meta">
                    Invoice <?= htmlspecialchars((string) ($r['invoice_number'] ?? '—')) ?>
                    &middot; total Rs <?= number_format((float) ($r['grand_total'] ?? 0)) ?>, collected Rs <?= number_format((float) ($r['paid_amount'] ?? 0)) ?>
                    &middot; raised by <?= htmlspecialchars((string) ($r['requester_name'] ?? '—')) ?>, <?= date('d/m/Y H:i', strtotime($r['requested_at'])) ?>
                    &middot; <a href="admission_discharge.php?id=<?= (int) $r['admission_id'] ?>" style="color:var(--primary);font-weight:600;">Open bill</a>
                </div>
                <?php if ($r['status'] === 'PENDING'): ?>
                <form method="POST" action="admission_approvals.php?status=<?= htmlspecialchars($filter) ?>" class="ap-act" onsubmit="if (this.dataset.busy) { return false; } this.dataset.busy = '1';">
                    <input type="hidden" name="approval_id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="imp_csrf" value="<?= htmlspecialchars(imp_csrf_token()) ?>">
                    <input type="text" name="decision_note" maxlength="255" placeholder="Note (required to reject)" autocomplete="off">
                    <button type="submit" name="decision" value="APPROVED" class="btn">Approve</button>
                    <button type="submit" name="decision" value="REJECTED" class="btn secondary">Reject</button>
                </form>
                <?php else: ?>
                <div class="ap-meta" style="margin-top:8px;">
                    <?= htmlspecialchars($r['status']) ?> by <?= htmlspecialchars((string) ($r['decider_name'] ?? '—')) ?>
                    <?= $r['decided_at'] ? ', ' . date('d/m/Y H:i', strtotime($r['decided_at'])) : '' ?>
                    <?= $r['decision_note'] ? ' &mdash; ' . htmlspecialchars((string) $r['decision_note']) : '' ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
