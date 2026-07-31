<?php
/**
 * Procedure discounts — the doctor's queue.
 *
 * A receptionist can give a flat rupee discount when raising a procedure bill.
 * The patient pays the discounted amount and leaves immediately; nothing is
 * held for an answer. This page is where the performing doctor records whether
 * they agree, within 24 hours.
 *
 * A DECISION MOVES NO MONEY. The bill is settled and the patient has gone. That
 * is not a shortcut, it is the only workable design: a procedure bill cannot be
 * refunded (refunds.bill_id is FK'd to the OPD bills table) and a void is
 * refused once the cashier's day is signed, which inside a 24-hour window is
 * the normal case rather than an edge one. So the doctor's answer goes on the
 * record and onto the admin report, and that visibility IS the control.
 *
 * The reject button therefore says "Object", not "Reject" — a button must not
 * promise a reversal the system will never perform.
 *
 * WHY EVERY ROW LEADS WITH THE DOCTOR'S OWN RUPEES.
 * procedure_bill.php spreads a discount across the bill's lines, and doctor
 * share is computed per line on the discounted amount ("computed on what was
 * ACTUALLY collected, not list price"). So a discount comes out of the doctor's
 * earnings at their own share percentage — they are not rubber-stamping a
 * clinic decision, they are being asked about their own money. Showing only the
 * headline discount would hide the number that matters.
 *
 * ADMIN opens the same page and sees every doctor's queue, because the admin
 * report is where objections actually get acted on.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/billing.php';      // doctor_split()
require_once __DIR__ . '/config/notifications.php';
refresh_session_permissions($pdo);
require_permission('DOCTOR_APPROVE_PROCEDURE_DISCOUNT');

$uid     = (int) $_SESSION['user_id'];
$isAdmin = ($_SESSION['base_role'] ?? '') === 'ADMIN';
$notice  = '';
$error   = '';

// ---------------------------------------------------------------- decide ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $billId   = (int) ($_POST['bill_id'] ?? 0);
    $decision = (string) ($_POST['decision'] ?? '');
    $reason   = mb_substr(trim((string) ($_POST['reject_reason'] ?? '')), 0, 255);

    if (!in_array($decision, ['APPROVED', 'REJECTED'], true)) {
        $error = 'Choose approve or object.';
    } elseif ($decision === 'REJECTED' && $reason === '') {
        // An objection with no reason is unactionable by the admin who reads it.
        $error = 'Say why you are objecting — the reason is the whole point of the record.';
    } else {
        // Only the assigned doctor may decide their own; an admin may decide any.
        // The status guard in the WHERE is what makes a double-submit safe: the
        // second one matches zero rows rather than overwriting the first answer.
        $sql = 'UPDATE procedure_bills
                   SET discount_approval = ?, discount_decided_by_id = ?, discount_decided_at = NOW(),
                       discount_reject_reason = ?
                 WHERE id = ? AND discount_approval = ' . "'PENDING'";
        $args = [$decision, $uid, $decision === 'REJECTED' ? $reason : null, $billId];
        if (!$isAdmin) {
            $sql .= ' AND discount_doctor_id = ?';
            $args[] = $uid;
        }
        $st = $pdo->prepare($sql);
        $st->execute($args);

        if ($st->rowCount() > 0) {
            audit_log($pdo, 'procedure_discount_' . strtolower($decision),
                'Procedure bill #' . $billId . ($reason ? ' — ' . $reason : ''), $uid);
            $notice = $decision === 'APPROVED'
                ? 'Approved. Nothing further to do.'
                : 'Objection recorded. It now appears on the admin report.';
        } else {
            // Already decided, timed out to AUTO_APPROVED, or not this doctor's.
            $error = 'That one is no longer pending — it may have been decided already, or passed its 24 hours.';
        }
    }
}

// ------------------------------------------------------------------ read ----
// PENDING first (they have a deadline), then recently decided for context.
$where = $isAdmin ? '1=1' : 'pb.discount_doctor_id = ' . $uid;
$rows = $pdo->query("
    SELECT pb.id, pb.invoice_number, pb.paid_at, pb.grand_total, pb.subtotal,
           pb.manual_discount_amount, pb.manual_discount_reason,
           pb.discount_approval, pb.discount_notified_at, pb.discount_decided_at,
           pb.discount_reject_reason,
           p.name AS patient_name,
           d.name AS doctor_name,
           cu.name AS raised_by_name,
           du.name AS decided_by_name,
           TIMESTAMPDIFF(MINUTE, pb.discount_notified_at, NOW()) AS mins_elapsed
      FROM procedure_bills pb
      JOIN patients p  ON p.id = pb.patient_id
      LEFT JOIN users d  ON d.id  = pb.discount_doctor_id
      LEFT JOIN users cu ON cu.id = pb.created_by_id
      LEFT JOIN users du ON du.id = pb.discount_decided_by_id
     WHERE {$where}
       AND pb.manual_discount_amount > 0
       AND pb.voided_at IS NULL
     ORDER BY (pb.discount_approval = 'PENDING') DESC, pb.paid_at DESC
     LIMIT 100
")->fetchAll();

// The doctor's own exposure per bill: what they would have earned at list price
// against what they will now earn. Summed from the per-line share snapshots,
// which is the same arithmetic the share statement uses.
$lineStmt = $pdo->prepare('
    SELECT amount, unit_rate, quantity, doctor_share_pct, has_tax, tax_percent,
           COALESCE(disposables_cost, 0) AS disposables_cost
      FROM procedure_bill_items WHERE procedure_bill_id = ?
');
$exposure = [];
foreach ($rows as $r) {
    $loss = 0.0;
    $lineStmt->execute([(int) $r['id']]);
    foreach ($lineStmt->fetchAll() as $ln) {
        $disp   = (float) $ln['disposables_cost'];
        $actual = doctor_split((float) $ln['amount'], (float) $ln['doctor_share_pct'],
                               (bool) $ln['has_tax'], (float) $ln['tax_percent'], $disp);
        $list   = doctor_split((float) $ln['unit_rate'] * (float) $ln['quantity'],
                               (float) $ln['doctor_share_pct'],
                               (bool) $ln['has_tax'], (float) $ln['tax_percent'], $disp);
        $loss  += ($list['doctor'] - $actual['doctor']);
    }
    $exposure[(int) $r['id']] = max(0.0, round($loss, 0));
}

$pendingCount = 0;
foreach ($rows as $r) { if ($r['discount_approval'] === 'PENDING') { $pendingCount++; } }

$pageTitle = 'Procedure discounts';
require __DIR__ . '/partials/head.php';
$navActive = 'proc_discounts';
require __DIR__ . '/partials/sidebar.php';
?>
<style>
.pd-wrap { display: flex; flex-direction: column; gap: 14px; }
.pd-lede { margin: 0; font-size: 13.5px; color: var(--text-secondary); max-width: 70ch; line-height: 1.6; }
.pd-card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-card); padding: 16px 18px; }
.pd-card.pending { border-left: 3px solid var(--warn); }

.pd-top { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.pd-who { font-size: 15px; font-weight: 700; }
.pd-inv { font-size: 12px; color: var(--text-muted); font-variant-numeric: tabular-nums; }

/* The doctor's own money is the headline -- bigger than the discount itself,
   because it is the number they are actually being asked about. */
.pd-figs { display: flex; gap: 22px; flex-wrap: wrap; margin: 12px 0 10px; }
.pd-fig { display: flex; flex-direction: column; gap: 2px; }
.pd-fig .k { font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--text-muted); }
.pd-fig .v { font-size: 15px; font-weight: 700; font-variant-numeric: tabular-nums; }
.pd-fig.mine .v { font-size: 20px; color: var(--danger); }

.pd-meta { font-size: 12.5px; color: var(--text-secondary); line-height: 1.6; }
.pd-meta b { color: var(--text); }
.pd-left { font-size: 12px; font-weight: 700; }
.pd-left.soon { color: var(--danger); }

.pd-act { display: flex; gap: 10px; align-items: flex-start; margin-top: 12px; flex-wrap: wrap; }
.pd-act input[type="text"] { flex: 1; min-width: 200px; padding: 9px 11px; border: 1px solid var(--border); border-radius: var(--radius-input); font: inherit; font-size: 13px; background: var(--bg); }
.pd-act input[type="text"]:focus { outline: none; border-color: var(--primary); background: #fff; }
.pd-empty { color: var(--text-muted); font-size: 13.5px; }
/* Stacked, the reason box must stretch like the buttons do — min-width alone
   leaves it short of the card edge and it reads as a broken half-field. */
@media (max-width: 620px) {
    .pd-act { flex-direction: column; }
    .pd-act .btn, .pd-act input[type="text"] { width: 100%; min-width: 0; }
}
html[data-view="mobile"] .pd-act { flex-direction: column; }
html[data-view="mobile"] .pd-act .btn,
html[data-view="mobile"] .pd-act input[type="text"] { width: 100%; min-width: 0; }
</style>

<div class="content">
    <div class="page-head">
        <div>
            <div class="page-title">Procedure discounts</div>
            <div class="page-sub">
                <?= $pendingCount ?> awaiting an answer<?= $isAdmin ? ' (all doctors)' : '' ?>
                &middot; your decision is recorded, it does not move money
            </div>
        </div>
    </div>

<div class="pd-wrap">
    <?php if ($notice): ?><div class="alert success"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <p class="pd-lede">
        Reception gave these discounts at the counter. The patient has already paid and gone —
        your answer does not move any money. It goes on the record and onto the clinic's
        discount report, which is what the admin acts on.
    </p>

    <?php if (!$rows): ?>
    <div class="pd-card"><p class="pd-empty">No procedure discounts to show.</p></div>
    <?php endif; ?>

    <?php foreach ($rows as $r):
        $isPending = $r['discount_approval'] === 'PENDING';
        $mine = $exposure[(int) $r['id']] ?? 0;
        $minsLeft = 1440 - (int) $r['mins_elapsed'];
    ?>
    <div class="pd-card <?= $isPending ? 'pending' : '' ?>">
        <div class="pd-top">
            <div>
                <div class="pd-who"><?= htmlspecialchars(mb_strtoupper($r['patient_name'])) ?></div>
                <div class="pd-inv">
                    <?= htmlspecialchars($r['invoice_number']) ?>
                    &middot; <?= $r['paid_at'] ? date('d/m/Y, h:i A', strtotime($r['paid_at'])) : '—' ?>
                    <?php if ($isAdmin && $r['doctor_name']): ?>&middot; <?= htmlspecialchars($r['doctor_name']) ?><?php endif; ?>
                </div>
            </div>
            <?php if ($isPending): ?>
                <span class="pd-left <?= $minsLeft <= 240 ? 'soon' : '' ?>">
                    <?php if ($minsLeft <= 0): ?>past 24h — closing shortly
                    <?php elseif ($minsLeft < 60): ?><?= $minsLeft ?> min left
                    <?php else: ?><?= floor($minsLeft / 60) ?>h left<?php endif; ?>
                </span>
            <?php elseif ($r['discount_approval'] === 'APPROVED'): ?>
                <span class="pill pill--ok">Approved</span>
            <?php elseif ($r['discount_approval'] === 'REJECTED'): ?>
                <span class="pill pill--danger">Objected</span>
            <?php elseif ($r['discount_approval'] === 'AUTO_APPROVED'): ?>
                <span class="pill pill--quiet">Closed — no answer in 24h</span>
            <?php endif; ?>
        </div>

        <div class="pd-figs">
            <div class="pd-fig mine">
                <span class="k">Costs you</span>
                <span class="v">Rs <?= number_format($mine) ?></span>
            </div>
            <div class="pd-fig">
                <span class="k">Discount given</span>
                <span class="v">Rs <?= number_format((float) $r['manual_discount_amount']) ?></span>
            </div>
            <div class="pd-fig">
                <span class="k">Patient paid</span>
                <span class="v">Rs <?= number_format((float) $r['grand_total']) ?></span>
            </div>
            <div class="pd-fig">
                <span class="k">Before discount</span>
                <span class="v">Rs <?= number_format((float) $r['subtotal']) ?></span>
            </div>
        </div>

        <div class="pd-meta">
            Given by <b><?= htmlspecialchars($r['raised_by_name'] ?? 'reception') ?></b><?php
                if ($r['manual_discount_reason']): ?> — &ldquo;<?= htmlspecialchars($r['manual_discount_reason']) ?>&rdquo;<?php
                else: ?> <span style="color:var(--text-muted);">— no reason given</span><?php endif; ?>
            <?php if (!$isPending && $r['discount_decided_at']): ?>
                <br><?= $r['discount_approval'] === 'REJECTED' ? 'Objected' : 'Answered' ?>
                by <b><?= htmlspecialchars($r['decided_by_name'] ?? '—') ?></b>
                on <?= date('d/m/Y, h:i A', strtotime($r['discount_decided_at'])) ?>
                <?php if ($r['discount_reject_reason']): ?><br>Reason: &ldquo;<?= htmlspecialchars($r['discount_reject_reason']) ?>&rdquo;<?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($isPending): ?>
        <form method="POST" action="procedure_discount_approvals.php" class="pd-act" data-lock-submit>
            <input type="hidden" name="bill_id" value="<?= (int) $r['id'] ?>">
            <input type="text" name="reject_reason" maxlength="255"
                   placeholder="Reason — required only if you object" autocomplete="off">
            <button type="submit" name="decision" value="APPROVED" class="btn">Approve</button>
            <button type="submit" name="decision" value="REJECTED" class="btn secondary">Object</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
    </div>
</div>
</body>
</html>
