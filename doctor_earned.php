<?php
/**
 * JSON: what a doctor earned in a given month. (Accounts Phase 1b, 2026-07-26)
 *
 * Feeds the Doctor Shares posting form — pick a doctor and a month, and the
 * amount pre-fills with the share they actually earned, instead of being typed
 * from a spreadsheet. The figure stays editable: a part-payment or an agreed
 * adjustment is normal, so this suggests rather than dictates.
 *
 * Also returns what has ALREADY been disbursed for the same doctor+month, so
 * the form can warn about paying twice — the single most expensive mistake this
 * screen could make.
 *
 * GET ?doctor_id=<int>&month=YYYY-MM
 * Requires FINANCIAL_POST_EXPENSES (same gate as the page that calls it) plus
 * admin, since Doctor Shares is an admin-only category and this exposes another
 * doctor's earnings.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);
require_once __DIR__ . '/config/billing.php';

header('Content-Type: application/json');

// Compensation data — admin only, regardless of who can post ordinary expenses.
if (($_SESSION['base_role'] ?? '') !== 'ADMIN' || !has_permission('FINANCIAL_POST_EXPENSES')) {
    http_response_code(403);
    exit(json_encode(['error' => 'forbidden']));
}

$doctorId = (int) ($_GET['doctor_id'] ?? 0);
$month    = trim($_GET['month'] ?? '');

if ($doctorId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $month)) {
    http_response_code(400);
    exit(json_encode(['error' => 'bad_request']));
}

$earned = doctor_earned_for_month($pdo, $doctorId, $month);

// Already disbursed for this doctor + month, so the UI can flag a double
// payment. Counts live rows only (voided and rejected postings do not exist).
$alreadyPaid = 0.0;
try {
    $q = $pdo->prepare("
        SELECT COALESCE(SUM(e.amount), 0)
        FROM expenses e
        JOIN expense_categories ec ON ec.id = e.category_id
        WHERE e.paid_to_doctor_id = ?
          AND e.period_month = ?
          AND ec.is_disbursement = 1
          AND e.voided_at IS NULL
          AND e.approval_status <> 'REJECTED'
    ");
    $q->execute([$doctorId, $month . '-01']);
    $alreadyPaid = (float) $q->fetchColumn();
} catch (PDOException $e) {
    // add_expense_paid_to_doctor.sql not run yet — leave zero rather than fail
    // the whole lookup; the earned figure is still useful on its own.
    error_log('[doctor_earned] already-paid lookup: ' . $e->getMessage());
}

echo json_encode([
    'gross'        => round($earned['gross'], 2),
    'tax'          => round($earned['tax'], 2),
    'doctor'       => round($earned['doctor'], 2),
    'clinic'       => round($earned['clinic'], 2),
    'bills'        => $earned['bills'],
    'already_paid' => round($alreadyPaid, 2),
    // What the amount box should be set to: the balance still owed, so a
    // part-payment tops up rather than re-paying the whole month.
    'suggested'    => round(max(0.0, $earned['doctor'] - $alreadyPaid), 2),
]);
