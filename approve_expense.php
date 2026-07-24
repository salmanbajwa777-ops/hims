<?php
/**
 * Expense approval landing page — the target of the 60-minute magic link emailed
 * to admins/managers when a counter expense is posted.
 *
 * Two ways in:
 *   1. Magic link (?token=…): the signed, single-use token IS the authorization.
 *      No login required — the recipient taps Approve or Reject straight from
 *      their inbox. Valid for 60 minutes, then burned on first decision.
 *   2. Logged-in approver with FINANCIAL_APPROVE_EXPENSES arriving without a
 *      token (or after it expired): they act as themselves, same as the inline
 *      Approve/Reject on the Expenses page.
 *
 * This page renders standalone (no sidebar) so it works when logged out.
 */
require_once __DIR__ . '/config/auth.php';           // session + timezone (no require_login)
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/notify.php';
require_once __DIR__ . '/config/expense_approval.php';

$rawToken = trim($_GET['token'] ?? ($_POST['token'] ?? ''));
$hasToken = $rawToken !== '';

// Who, if anyone, is signed in — and may they approve?
$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
$loggedInApprover = false;
if ($sessionUserId > 0) {
    refresh_session_permissions($pdo);
    $loggedInApprover = has_permission('FINANCIAL_APPROVE_EXPENSES');
}

$expenseId   = 0;
$tokenValid  = false;   // token present, unused, unexpired
$tokenReason = '';      // '', 'not_found', 'expired', 'used'

if ($hasToken) {
    $res = resolve_expense_token($pdo, $rawToken);
    $expenseId   = (int) ($res['expense_id'] ?? 0);
    $tokenValid  = $res['ok'];
    $tokenReason = $res['reason'];
} elseif (isset($_GET['id']) && $loggedInApprover) {
    // Deep-link fallback for a signed-in approver (e.g. from the Expenses page).
    $expenseId = (int) $_GET['id'];
}

// May the current visitor decide THIS expense? Either the token is live, or a
// signed-in approver is looking at it (token expired/absent is fine for them).
$canDecide = $tokenValid || ($loggedInApprover && $expenseId > 0);

$flash = '';
$flashKind = '';   // 'ok' | 'err'

// ---- Handle a decision ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $expenseId > 0) {
    $wantApprove = ($_POST['decision'] ?? '') === 'approve';
    $wantReject  = ($_POST['decision'] ?? '') === 'reject';

    if (!$wantApprove && !$wantReject) {
        $flash = 'Choose approve or reject.'; $flashKind = 'err';
    } elseif (!$canDecide) {
        // Token dead AND not a signed-in approver → refuse.
        $flash = $tokenReason === 'expired'
            ? 'This approval link has expired. Please sign in to approve or reject from the Expenses page.'
            : ($tokenReason === 'used'
                ? 'This approval link has already been used.'
                : 'You are not authorized to decide this expense. Please sign in.');
        $flashKind = 'err';
    } else {
        // The magic-link decider is not necessarily a known user; pass null so
        // the audit line records "via email approval link".
        $deciderId = $loggedInApprover ? $sessionUserId : null;
        $reason    = trim($_POST['reject_reason'] ?? '');
        $result    = decide_expense($pdo, $expenseId, $wantApprove, $deciderId, $reason);
        if ($result['ok']) {
            notify_expense_decided($pdo, $expenseId);   // best-effort, after commit
            $flash = $result['message']; $flashKind = 'ok';
        } else {
            $flash = $result['message'];
            $flashKind = ($result['status'] === 'ALREADY') ? 'ok' : 'err';
        }
        // The token (if any) is now burned; re-render reflects the final state.
        $tokenValid = false;
    }
}

// ---- Load the expense to display (may be pending, or already decided) ----
$expense = null;
if ($expenseId > 0) {
    $stmt = $pdo->prepare('
        SELECT e.*, ec.name AS category_name, u.name AS posted_by_name,
               a.name AS approved_by_name
        FROM expenses e
        JOIN expense_categories ec ON ec.id = e.category_id
        JOIN users u ON u.id = e.posted_by_id
        LEFT JOIN users a ON a.id = e.approved_by_id
        WHERE e.id = ?
    ');
    $stmt->execute([$expenseId]);
    $expense = $stmt->fetch() ?: null;
}

$status = $expense['approval_status'] ?? null;
$isPending = ($status === 'PENDING') && ($expense['voided_at'] ?? null) === null;
// Show the decision form only when the row is still pending AND we may decide.
$showForm = $isPending && ($tokenValid || $loggedInApprover);

$base = (mail_config() ?? [])['base_url'] ?? '';

$pageTitle = 'Expense Approval';
require __DIR__ . '/partials/head.php';
?>
<style>
.appr-wrap { min-height: 100vh; display: flex; align-items: flex-start; justify-content: center; padding: 40px 16px; background: var(--bg, #f1f5f4); }
.appr-card { width: 100%; max-width: 460px; background: #fff; border: 1px solid var(--border, #dde5e4); border-radius: 16px; overflow: hidden; box-shadow: 0 8px 30px rgba(15,23,42,.06); }
.appr-head { background: var(--primary, #0E5456); color: #fff; padding: 18px 24px; font-size: 16px; font-weight: 700; }
.appr-body { padding: 22px 24px; }
.appr-kv { width: 100%; border-collapse: collapse; margin: 6px 0 18px; }
.appr-kv td { padding: 8px 10px; border: 1px solid var(--border, #e5ecec); font-size: 13.5px; }
.appr-kv td:first-child { background: #f7fafa; color: var(--text-secondary, #41504f); width: 42%; }
.appr-kv td:last-child { font-weight: 600; color: var(--text, #17211f); }
.appr-amt { font-size: 22px; font-weight: 800; color: var(--primary, #0E5456); font-variant-numeric: tabular-nums; margin: 0 0 4px; }
.appr-actions { display: flex; gap: 10px; margin-top: 6px; }
.appr-btn { flex: 1; border: none; border-radius: 10px; padding: 13px; font: inherit; font-size: 14.5px; font-weight: 700; cursor: pointer; }
.appr-approve { background: var(--primary, #0E5456); color: #fff; }
.appr-reject { background: #fff; color: var(--red-text, #b3261e); border: 1px solid rgba(225,29,72,.4); }
.appr-note { font-size: 12.5px; color: var(--text-muted, #6b7c7b); margin-top: 14px; line-height: 1.5; }
.appr-flash { border-radius: 10px; padding: 12px 14px; font-size: 13.5px; margin-bottom: 16px; }
.appr-flash.ok  { background: rgba(26,127,126,.10); border: 1px solid rgba(26,127,126,.3); color: #0E5456; }
.appr-flash.err { background: rgba(225,29,72,.08); border: 1px solid rgba(225,29,72,.28); color: var(--red-text, #b3261e); }
.appr-badge { display: inline-block; font-size: 12px; font-weight: 700; border-radius: 20px; padding: 4px 12px; }
.b-pending  { color: #92590B; background: rgba(245,158,11,.13); border: 1px solid rgba(245,158,11,.34); }
.b-approved { color: #0E5456; background: rgba(26,127,126,.11); border: 1px solid rgba(26,127,126,.28); }
.b-rejected { color: var(--red-text, #b3261e); background: rgba(225,29,72,.09); border: 1px solid rgba(225,29,72,.24); }
.appr-link { color: var(--primary, #0E5456); font-weight: 600; text-decoration: none; }
</style>

<div class="appr-wrap">
    <div class="appr-card">
        <div class="appr-head">Babymedics HMIS — Expense Approval</div>
        <div class="appr-body">

            <?php if ($flash): ?>
                <div class="appr-flash <?= $flashKind ?>"><?= htmlspecialchars($flash) ?></div>
            <?php endif; ?>

            <?php if (!$expense): ?>
                <?php if ($tokenReason === 'not_found' || !$hasToken && !$loggedInApprover): ?>
                    <p style="font-size:14px;color:#41504f;">This approval link is not valid. If you were sent it by email, it may have already been used, or the address was mistyped.</p>
                    <p class="appr-note">Signed-in admins and managers can review pending expenses from the Expenses page in the app.</p>
                <?php else: ?>
                    <p style="font-size:14px;color:#41504f;">That expense could not be found.</p>
                <?php endif; ?>

            <?php else: ?>
                <p class="appr-amt">Rs <?= number_format((float) $expense['amount'], 2) ?></p>
                <div style="margin-bottom:14px;">
                    <?php if ($status === 'APPROVED'): ?>
                        <span class="appr-badge b-approved">Approved<?= $expense['approved_by_name'] ? ' by ' . htmlspecialchars($expense['approved_by_name']) : '' ?></span>
                    <?php elseif ($status === 'REJECTED'): ?>
                        <span class="appr-badge b-rejected">Rejected<?= $expense['approved_by_name'] ? ' by ' . htmlspecialchars($expense['approved_by_name']) : '' ?></span>
                    <?php elseif (($expense['voided_at'] ?? null) !== null): ?>
                        <span class="appr-badge b-rejected">Voided</span>
                    <?php else: ?>
                        <span class="appr-badge b-pending">Awaiting approval</span>
                    <?php endif; ?>
                </div>

                <table class="appr-kv">
                    <tr><td>Voucher</td><td><?= htmlspecialchars($expense['expense_number']) ?></td></tr>
                    <tr><td>Category</td><td><?= htmlspecialchars($expense['category_name']) ?></td></tr>
                    <tr><td>For</td><td><?= htmlspecialchars($expense['description']) ?></td></tr>
                    <?php if (!empty($expense['paid_to'])): ?>
                    <tr><td>Paid to</td><td><?= htmlspecialchars($expense['paid_to']) ?></td></tr>
                    <?php endif; ?>
                    <tr><td>Posted by</td><td><?= htmlspecialchars($expense['posted_by_name']) ?></td></tr>
                    <tr><td>Date</td><td><?= htmlspecialchars(date('d/m/Y', strtotime($expense['expense_date']))) ?></td></tr>
                    <?php if ($status === 'REJECTED' && !empty($expense['rejection_reason'])): ?>
                    <tr><td>Reason</td><td><?= htmlspecialchars($expense['rejection_reason']) ?></td></tr>
                    <?php endif; ?>
                </table>

                <?php if ($showForm): ?>
                    <form method="POST" action="approve_expense.php" style="margin:0;"
                          onsubmit="if(this.decision.value==='reject'){var r=prompt('Reason for rejecting (the cash should be returned to the drawer):');if(!r){return false;}this.reject_reason.value=r;}return true;">
                        <?php if ($hasToken): ?><input type="hidden" name="token" value="<?= htmlspecialchars($rawToken) ?>"><?php endif; ?>
                        <?php if (!$hasToken): ?><input type="hidden" name="id" value="<?= (int) $expense['id'] ?>"><?php endif; ?>
                        <input type="hidden" name="decision" value="">
                        <input type="hidden" name="reject_reason" value="">
                        <div class="appr-actions">
                            <button type="submit" class="appr-btn appr-approve" onclick="this.form.decision.value='approve';">Approve</button>
                            <button type="submit" class="appr-btn appr-reject" onclick="this.form.decision.value='reject';">Reject</button>
                        </div>
                    </form>
                    <p class="appr-note">Approving clears this voucher on the shift tally. Rejecting flags it for the cash to be returned to the drawer. This decision is final and recorded.</p>

                <?php elseif ($isPending && $tokenReason === 'expired'): ?>
                    <div class="appr-flash err" style="margin:0 0 10px;">This one-click link has expired (links are valid for 60 minutes).</div>
                    <?php if ($base): ?>
                    <p class="appr-note">Sign in and open the <a class="appr-link" href="<?= htmlspecialchars($base) ?>/expenses.php">Expenses page</a> to approve or reject it there.</p>
                    <?php else: ?>
                    <p class="appr-note">Sign in and open the Expenses page to approve or reject it there.</p>
                    <?php endif; ?>

                <?php elseif ($isPending && $tokenReason === 'used'): ?>
                    <p class="appr-note">This link has already been used. The expense is still awaiting a decision — sign in to act on it from the Expenses page.</p>

                <?php elseif ($isPending): ?>
                    <p class="appr-note">This expense is awaiting approval. Sign in as an admin or manager to approve or reject it.</p>

                <?php else: ?>
                    <p class="appr-note">No further action is needed — this expense has already been decided.</p>
                <?php endif; ?>
            <?php endif; ?>

        </div>
    </div>
</div>
</body>
</html>
