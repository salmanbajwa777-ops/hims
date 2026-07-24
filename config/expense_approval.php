<?php
/**
 * Shared expense-approval logic, used by BOTH the in-app Expenses page
 * (approver clicks Approve/Reject on a pending row) and the emailed 60-minute
 * magic-link page (approve_expense.php, no login).
 *
 * decide_expense() flips a single PENDING expense to APPROVED or REJECTED inside
 * a row-locked transaction so two approvers (or a click + a link) can't both
 * decide it. It records who + when, burns any live token for that expense, and
 * writes an audit line. It does NOT send email — the caller fires
 * notify_expense_decided() after commit.
 *
 * Returns ['ok' => bool, 'status' => 'APPROVED'|'REJECTED'|'ALREADY'|'MISSING',
 *          'message' => string]. 'ALREADY' means someone beat us to it.
 */
require_once __DIR__ . '/mailer.php';   // (for consistency; notify is fired by caller)

function decide_expense(PDO $pdo, int $expenseId, bool $approve, ?int $deciderId, string $rejectReason = ''): array {
    if ($approve === false && trim($rejectReason) === '') {
        return ['ok' => false, 'status' => 'MISSING', 'message' => 'A rejection needs a reason.'];
    }
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT id, expense_number, approval_status, voided_at FROM expenses WHERE id = ? FOR UPDATE');
        $stmt->execute([$expenseId]);
        $e = $stmt->fetch();

        if (!$e) {
            $pdo->rollBack();
            return ['ok' => false, 'status' => 'MISSING', 'message' => 'That expense could not be found.'];
        }
        if ($e['voided_at'] !== null) {
            $pdo->rollBack();
            return ['ok' => false, 'status' => 'ALREADY', 'message' => 'That expense has been voided and can no longer be approved.'];
        }
        if ($e['approval_status'] !== 'PENDING') {
            $pdo->rollBack();
            return [
                'ok' => false, 'status' => 'ALREADY',
                'message' => 'Expense ' . $e['expense_number'] . ' was already '
                           . strtolower($e['approval_status']) . '.',
            ];
        }

        if ($approve) {
            $pdo->prepare("
                UPDATE expenses
                SET approval_status = 'APPROVED', approved_by_id = ?, approved_at = NOW(),
                    rejection_reason = NULL
                WHERE id = ? AND approval_status = 'PENDING'
            ")->execute([$deciderId, $expenseId]);
            $action = 'expense_approved';
            $note   = 'Approved expense ' . $e['expense_number'];
            $status = 'APPROVED';
        } else {
            $reason = trim($rejectReason);
            $pdo->prepare("
                UPDATE expenses
                SET approval_status = 'REJECTED', approved_by_id = ?, approved_at = NOW(),
                    rejection_reason = ?
                WHERE id = ? AND approval_status = 'PENDING'
            ")->execute([$deciderId, $reason, $expenseId]);
            $action = 'expense_rejected';
            $note   = 'Rejected expense ' . $e['expense_number'] . ' — ' . $reason;
            $status = 'REJECTED';
        }

        // Burn any live token for this expense so the same link can't be reused.
        $pdo->prepare('UPDATE expense_approval_tokens SET used_at = NOW(), used_by_id = ? WHERE expense_id = ? AND used_at IS NULL')
            ->execute([$deciderId, $expenseId]);

        // audit_logs.user_id is nullable (FK ON DELETE SET NULL); a magic-link
        // decider is anonymous, so log NULL + note the channel in the details.
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([
                $deciderId,
                $action,
                $deciderId !== null ? $note : $note . ' (via email approval link)',
            ]);

        $pdo->commit();
        return [
            'ok' => true, 'status' => $status,
            'message' => 'Expense ' . $e['expense_number'] . ' ' . strtolower($status) . '.',
        ];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        return ['ok' => false, 'status' => 'MISSING', 'message' => 'Could not record the decision. Please try again.'];
    }
}

/**
 * Resolve a raw magic-link token to its expense, enforcing single-use + expiry.
 * Returns ['ok'=>bool, 'expense_id'=>?int, 'reason'=>string]. 'reason' is one of
 * '', 'not_found', 'expired', 'used'. On 'used' we still return the expense_id so
 * the landing page can show the decision that was already made.
 */
function resolve_expense_token(PDO $pdo, string $rawToken): array {
    $rawToken = trim($rawToken);
    if ($rawToken === '' || !preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
        return ['ok' => false, 'expense_id' => null, 'reason' => 'not_found'];
    }
    $stmt = $pdo->prepare('
        SELECT expense_id, expires_at, used_at
        FROM expense_approval_tokens
        WHERE token_hash = ?
        LIMIT 1
    ');
    $stmt->execute([hash('sha256', $rawToken)]);
    $t = $stmt->fetch();
    if (!$t) {
        return ['ok' => false, 'expense_id' => null, 'reason' => 'not_found'];
    }
    if ($t['used_at'] !== null) {
        return ['ok' => false, 'expense_id' => (int) $t['expense_id'], 'reason' => 'used'];
    }
    if (strtotime($t['expires_at']) < time()) {
        return ['ok' => false, 'expense_id' => (int) $t['expense_id'], 'reason' => 'expired'];
    }
    return ['ok' => true, 'expense_id' => (int) $t['expense_id'], 'reason' => ''];
}
