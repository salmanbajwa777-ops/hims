<?php
// Cash handovers — admin side (approved mock 2026-07-23).
//
// Pending closings from reception land here. Admin recounts the cash, ticks
// "signed A5 slip collected & filed", enters the actual amount if the recount
// differs (the difference logs as a HANDOVER DISCREPANCY, separate from the
// drawer variance), and marks received — which stamps who/when on the closing
// and completes the day's audit trail.
//
// AUDIT vs RECEIVE (2026-08-12): a MANAGER holding only ADMIN_AUDIT_HANDOVER
// sees this same page — pending list, received history, transaction
// drill-down — but the mark-received and close-on-behalf actions stay locked
// to ADMIN_RECEIVE_HANDOVER, so an auditor can monitor without being able to
// touch the money. Not gated via guard_admin.php any more: this page is
// permission-driven like the rest of the app, not ADMIN-only.

require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);
require_once __DIR__ . '/config/billing.php';
require_once __DIR__ . '/config/payment_methods.php';

// Either permission opens the page; the POST actions below re-check the
// stricter one so a URL-crafted POST from an audit-only session still 403s.
if (!has_permission('ADMIN_RECEIVE_HANDOVER') && !has_permission('ADMIN_AUDIT_HANDOVER')) {
    require_permission('ADMIN_RECEIVE_HANDOVER'); // renders the standard 403
}
$canReceive = has_permission('ADMIN_RECEIVE_HANDOVER');

$error = '';
$success = '';

// ---------------- Mark received ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_received' && $canReceive) {
    $closingId = (int) ($_POST['closing_id'] ?? 0);
    $received = round((float) str_replace(',', '', $_POST['handover_received'] ?? '0'), 2);
    $cashOk = !empty($_POST['cash_ok']);
    $slipFiled = !empty($_POST['slip_filed']);

    try {
        $pdo->beginTransaction();

        // Lock the row so two admins can't both acknowledge the same handover.
        // EDITED closings are receivable too — marking received IS the approval
        // of the cashier's post-close changes.
        $stmt = $pdo->prepare("SELECT * FROM shift_closings WHERE id = ? AND status IN ('PENDING_RECEIPT','EDITED') FOR UPDATE");
        $stmt->execute([$closingId]);
        $closing = $stmt->fetch();

        if (!$closing) {
            $error = 'Handover not found or already received.';
        } elseif (!$cashOk) {
            $error = 'Confirm you have recounted the cash before marking received.';
        } elseif (!$slipFiled) {
            $error = 'Confirm the signed A5 slip is collected and filed before marking received.';
        } elseif ($received < 0) {
            $error = 'The received amount cannot be negative.';
        } else {
            $pdo->prepare("
                UPDATE shift_closings
                SET status = 'RECEIVED', handover_received = ?, received_by_id = ?,
                    received_at = NOW(), slip_filed = 1
                WHERE id = ?
            ")->execute([$received, $_SESSION['user_id'], $closingId]);

            $declared = (float) $closing['handover_declared'];
            $discrepancy = round($received - $declared, 2);
            $wasEdited = $closing['status'] === 'EDITED';
            $detail = "Handover {$closing['closing_number']} received: Rs " . number_format($received, 2)
                    . ' (declared Rs ' . number_format($declared, 2) . ')'
                    . (abs($discrepancy) > 0.009
                        ? ' — DISCREPANCY Rs ' . number_format($discrepancy, 2)
                        : ' — matches declared')
                    . ($wasEdited ? '; cashier edits (×' . (int) $closing['edit_count'] . ') APPROVED' : '')
                    . '; signed slip filed.';
            audit_log($pdo, 'handover_received', $detail, $_SESSION['user_id']);

            $pdo->commit();

            $success = 'Handover ' . $closing['closing_number'] . ' marked received'
                     . (abs($discrepancy) > 0.009
                        ? ' with a Rs ' . number_format(abs($discrepancy), 2)
                          . ($discrepancy < 0 ? ' shortfall' : ' excess') . ' logged.'
                        : '.');
        }

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Could not mark the handover received. Please try again.';
    }
}

// ---------------- Admin closes a stranded shift on the receptionist's behalf ----
// Only for days older than the receptionist self-close window (5 days). Uses the
// receptionist's own system tally; the closing row still belongs to them
// (cashier_id) so attribution is unchanged, but closed_by_admin_id records who
// actually did it. Admin is both the closer and the handover recipient.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'admin_close_behalf' && $canReceive) {
    $cashierId = (int) ($_POST['cashier_id'] ?? 0);
    $closeDate = $_POST['close_date'] ?? '';
    $counted = round(max(0.0, (float) str_replace(',', '', $_POST['counted_cash'] ?? '0')), 2);
    $handover = round((float) str_replace(',', '', $_POST['handover_received'] ?? '0'), 2);
    $note = trim($_POST['behalf_note'] ?? '');

    $validDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $closeDate) ? $closeDate : '';
    $ageDays = $validDate ? (int) round((strtotime(business_day($pdo)) - strtotime($validDate)) / 86400) : -1;

    try {
        $pdo->beginTransaction();

        if ($cashierId <= 0 || $validDate === '') {
            $error = 'Pick a valid receptionist and date to close.';
        } elseif ($ageDays < 0) {
            // A future business day has no takings to count yet.
            $error = 'That day has not happened yet.';
        } elseif (!user_holds_drawer($pdo, $cashierId)) {
            $error = 'That user does not run a cash drawer.';
        } elseif (day_closing($pdo, $validDate, $cashierId)) {
            $error = 'That shift is already closed.';
        } elseif ($note === '') {
            $error = 'A note is required when closing on a receptionist\'s behalf.';
        } else {
            $tally = day_cash_tally($pdo, $validDate, $cashierId);
            $variance = round($counted - $tally['expected_cash'], 2);
            $closingNumber = generate_closing_number($pdo);

            // closed_by_admin_id only exists after add_admin_late_close.sql. Without
            // it the closing still records correctly under the cashier; we just lose
            // the "closed by X on behalf of Y" attribution until the migration runs.
            $hasBehalfCol = column_exists($pdo, 'shift_closings', 'closed_by_admin_id');
            $behalfCol = $hasBehalfCol ? 'closed_by_admin_id,' : '';
            $behalfVal = $hasBehalfCol ? '?,' : '';

            $params = [$closingNumber, $validDate, $cashierId];
            if ($hasBehalfCol) {
                $params[] = $_SESSION['user_id'];
            }

            $pdo->prepare("
                INSERT INTO shift_closings
                    (closing_number, closing_date, cashier_id, $behalfCol opening_float,
                     cash_consult_total, cash_consult_count,
                     cash_admission_total, cash_admission_count,
                     online_total, online_count,
                     cash_refund_total, cash_refund_count,
                     expense_total, expense_count,
                     expected_cash, counted_cash, variance, variance_note,
                     float_retained, handover_declared, handover_to_id,
                     status, handover_received, received_by_id, received_at, slip_filed)
                VALUES (?, ?, ?, $behalfVal 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?,
                        'RECEIVED', ?, ?, NOW(), 1)
            ")->execute([
                ...$params,
                $tally['cash_consult_total'], $tally['cash_consult_count'],
                $tally['cash_admission_total'], $tally['cash_admission_count'],
                $tally['online_total'], $tally['online_count'],
                $tally['cash_refund_total'], $tally['cash_refund_count'],
                $tally['expense_total'], $tally['expense_count'],
                $tally['expected_cash'], $counted, $variance, $note,
                $handover, $_SESSION['user_id'],
                $handover, $_SESSION['user_id'],
            ]);
            $closingId = (int) $pdo->lastInsertId();

            audit_log($pdo, 'shift_closed_on_behalf', "Admin closed stranded shift $closingNumber for cashier #$cashierId on $validDate: " . 'counted Rs ' . number_format($counted, 2) . ' vs expected Rs ' . number_format($tally['expected_cash'], 2) . ' (variance ' . number_format($variance, 2) . "); note: $note", $_SESSION['user_id']);

            $pdo->commit();

            $success = "Closed $closingNumber on behalf of the receptionist for " . date('d/m/Y', strtotime($validDate)) . '.';
        }

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[admin_close_behalf] ' . $e->getMessage());
        $error = 'Could not close the shift on their behalf. Please try again.';
    }
}

// Stranded shifts: any drawer receptionist's unclosed business days OLDER than the
// self-close window (age > 5), with money movement, going back a reasonable span.
// Receive-only — an audit-only viewer can't act on these, so skip computing
// the list rather than show a "close on behalf" card with no working button.
$strandedShifts = [];
try {
    foreach ($canReceive ? receptionists_with_drawer($pdo) : [] as $recp) {
        $days = unclosed_business_days($pdo, (int) $recp['id'], 30, true);
        foreach ($days as $d) {
            // EVERY unclosed day is listed, not only those past the receptionist's
            // own 5-day window. A receptionist who goes home sick at 4pm strands
            // TODAY's drawer, and that is exactly when an admin needs to be able to
            // close it — waiting five days to reconcile cash is the opposite of
            // what the situation calls for.
            //
            // 'stranded' distinguishes the two cases for the UI: past the window is
            // overdue and shown in red, inside it is a normal stand-in and shown in
            // amber, so opening this up does not make five ordinary days look like
            // five emergencies.
            $strandedShifts[] = [
                'cashier_id'    => (int) $recp['id'],
                'cashier_name'  => $recp['name'],
                'date'          => $d['date'],
                'expected_cash' => $d['expected_cash'],
                'age_days'      => $d['age_days'],
                'stranded'      => $d['age_days'] > 5,
            ];
        }
    }
    // Oldest first — the most urgent to reconcile.
    usort($strandedShifts, fn($a, $b) => $b['age_days'] <=> $a['age_days']);
} catch (Throwable $e) {
    $strandedShifts = [];
}

// ---------------- Page data ----------------
try {
    $pendingStmt = $pdo->query("
        SELECT c.*, cu.name AS cashier_name
        FROM shift_closings c
        JOIN users cu ON cu.id = c.cashier_id
        WHERE c.status IN ('PENDING_RECEIPT','EDITED')
        ORDER BY c.closing_date DESC, c.id
    ");
    $pending = $pendingStmt->fetchAll();
} catch (PDOException $e) {
    http_response_code(500);
    die('Shift-closing tables missing or outdated — run sql/add_per_user_closings.sql.');
}

// Per-field change history for the pending EDITED closings, newest round first.
$editLogs = [];
if ($pending) {
    $ids = array_column($pending, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $elStmt = $pdo->prepare("
            SELECT e.*, u.name AS edited_by_name
            FROM shift_closing_edits e
            JOIN users u ON u.id = e.edited_by_id
            WHERE e.closing_id IN ($ph)
            ORDER BY e.closing_id, e.edit_round DESC, e.id
        ");
        $elStmt->execute($ids);
        foreach ($elStmt->fetchAll() as $row) {
            $editLogs[(int) $row['closing_id']][] = $row;
        }
    } catch (PDOException $e) {
        // edits table not migrated yet — show closings without change detail
    }
}

$FIELD_LABELS = [
    'counted_cash'      => 'Counted cash',
    'handover_declared' => 'Handover declared',
    'variance_note'     => 'Variance note',
    'denominations'     => 'Denominations',
];

// edit_count only exists after add_per_user_closings.sql. The $pending query
// above survives without it (it selects c.*), so an unmigrated DB would fail
// here instead — uncaught, as a 500. Select it only when it is really there.
$editCountSelect = column_exists($pdo, 'shift_closings', 'edit_count')
    ? 'c.edit_count,'
    : '0 AS edit_count,';
// ---------------- Received history: filters ----------------
//
// This list used to be a hard "last 30", which meant a handover older than a
// month was simply unreachable — there was no way to pull up a past date at
// all. Filters make the whole archive addressable. Unlike the transaction
// drill-down (deliberately locked to one closing so its total always ties to
// the slip), a date RANGE is correct here: each row is a self-contained
// closing, so widening the range adds rows without making any figure wrong.
$isDate = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
$hFrom = $isDate($_GET['from'] ?? '') ? $_GET['from'] : '';
$hTo   = $isDate($_GET['to'] ?? '')   ? $_GET['to']   : '';
// Swap rather than error on a reversed range — the house behaviour (see
// income_report.php / doctor_share_statement.php).
if ($hFrom !== '' && $hTo !== '' && $hTo < $hFrom) {
    [$hFrom, $hTo] = [$hTo, $hFrom];
}
$hCashier = (int) ($_GET['cashier'] ?? 0);
$hQ       = trim($_GET['hq'] ?? '');
$hFiltered = ($hFrom !== '' || $hTo !== '' || $hCashier > 0 || $hQ !== '');

$hWhere  = ["c.status = 'RECEIVED'"];
$hParams = [];
if ($hFrom !== '')   { $hWhere[] = 'c.closing_date >= ?'; $hParams[] = $hFrom; }
if ($hTo !== '')     { $hWhere[] = 'c.closing_date <= ?'; $hParams[] = $hTo; }
if ($hCashier > 0)   { $hWhere[] = 'c.cashier_id = ?';    $hParams[] = $hCashier; }
if ($hQ !== '') {
    $hWhere[] = '(c.closing_number LIKE ? OR cu.name LIKE ?)';
    $hParams[] = '%' . $hQ . '%';
    $hParams[] = '%' . $hQ . '%';
}
// Unfiltered keeps the familiar recent-30 view; a filtered search reaches the
// whole archive under a sane cap, matching the LIMIT 300 used app-wide.
$hLimit = $hFiltered ? 300 : 30;

try {
    $historyStmt = $pdo->prepare("
        SELECT c.closing_number, c.closing_date, c.handover_declared, c.handover_received,
               c.variance, c.received_at, c.id, $editCountSelect
               cu.name AS cashier_name, ru.name AS received_by_name
        FROM shift_closings c
        JOIN users cu ON cu.id = c.cashier_id
        LEFT JOIN users ru ON ru.id = c.received_by_id
        WHERE " . implode(' AND ', $hWhere) . "
        ORDER BY c.closing_date DESC, c.id DESC
        LIMIT $hLimit
    ");
    $historyStmt->execute($hParams);
    $history = $historyStmt->fetchAll();
} catch (PDOException $e) {
    error_log('[admin_handovers history] ' . $e->getMessage());
    $history = [];
}

// The active history filters as a URL suffix, appended to each drill-down link
// so the return trip restores the same search.
$histQs = http_build_query(array_filter([
    'from' => $hFrom, 'to' => $hTo,
    'cashier' => $hCashier > 0 ? $hCashier : '', 'hq' => $hQ,
], fn($v) => $v !== '' && $v !== null));
$histQs = $histQs ? '&amp;' . htmlspecialchars($histQs) : '';

// Cashiers who actually appear in the archive — a dropdown of every user would
// list people who have never run a drawer.
try {
    $hCashiers = $pdo->query("
        SELECT DISTINCT u.id, u.name
        FROM shift_closings c JOIN users u ON u.id = c.cashier_id
        WHERE c.status = 'RECEIVED'
        ORDER BY u.name
    ")->fetchAll();
} catch (PDOException $e) {
    $hCashiers = [];
}

// ---------------- Transaction drill-down (?closing=N) ----------------
//
// The per-transaction cross-check. Scope is taken from the CLOSING ROW, never
// from user input: one date, one cashier. That is deliberate and load-bearing —
// a date-range filter here would produce a list that no longer ties to the
// closing being checked, and a drill-down whose total doesn't equal the figure
// it explains is worse than no drill-down at all.
$detail = null;
if (isset($_GET['closing'])) {
    $stmt = $pdo->prepare("
        SELECT c.*, cu.name AS cashier_name
        FROM shift_closings c
        JOIN users cu ON cu.id = c.cashier_id
        WHERE c.id = ?
    ");
    $stmt->execute([(int) $_GET['closing']]);
    $dc = $stmt->fetch();

    if ($dc) {
        $dType = trim($_GET['type'] ?? '');
        $dMode = trim($_GET['mode'] ?? '');
        $dQ    = trim($_GET['q'] ?? '');
        $dVoid = !empty($_GET['voided']);

        $txn = day_transaction_rows($pdo, $dc['closing_date'], (int) $dc['cashier_id'], [
            'type' => $dType, 'mode' => $dMode, 'q' => $dQ, 'voided' => $dVoid,
        ]);

        // Counts per type for the filter tabs, computed UNFILTERED so each tab
        // shows what it will open rather than what the current filter left.
        $all = day_transaction_rows($pdo, $dc['closing_date'], (int) $dc['cashier_id'],
                                    ['voided' => $dVoid]);
        $typeCounts = [];
        foreach ($all['rows'] as $r) {
            $typeCounts[$r['type']] = ($typeCounts[$r['type']] ?? 0) + 1;
        }

        // The tie-out. Live rows are compared against the LIVE tally, which is
        // what proves the drill-down is faithful. The stored snapshot is shown
        // beside it but cannot be broken down per stream — shift_closings keeps
        // only the pre-fold bucket set — so a long-closed day that was edited
        // after signing can legitimately differ. The banner names which side is
        // which rather than just crying "mismatch".
        // Refunds are checked on their own line because the tally keeps them
        // out of cash_total too — money in and money out are separate figures.
        $liveTally = day_cash_tally($pdo, $dc['closing_date'], (int) $dc['cashier_id']);
        $tieCash   = abs($all['cash'] - $liveTally['cash_total']) < 0.01
                  && abs($all['refund'] - $liveTally['cash_refund_total']) < 0.01;
        $tieOnline = abs($all['online'] - $liveTally['online_total']) < 0.01;

        $detail = [
            'c' => $dc, 'txn' => $txn, 'counts' => $typeCounts,
            'tally' => $liveTally, 'tieCash' => $tieCash, 'tieOnline' => $tieOnline,
            'allCash' => $all['cash'], 'allOnline' => $all['online'],
            'allRefund' => $all['refund'],
            'type' => $dType, 'mode' => $dMode, 'q' => $dQ, 'voided' => $dVoid,
            'uncounted' => day_uncounted_rows($pdo, $dc['closing_date'], (int) $dc['cashier_id']),
        ];
    } else {
        $error = 'That closing no longer exists.';
    }
}

$pageTitle = $detail ? 'Handover Transactions' : 'Cash Handovers';
require __DIR__ . '/partials/head.php';
$navActive = 'handovers';
require __DIR__ . '/partials/sidebar.php';
?>
<style>
.ho-grid { display: grid; grid-template-columns: 1.05fr .95fr; gap: 22px; align-items: start; }
@media (max-width: 980px) { .ho-grid { grid-template-columns: 1fr; } }
.ho-col { display: flex; flex-direction: column; gap: 22px; }

.pend { display: grid; grid-template-columns: 1fr auto; gap: 16px; align-items: center; border: 1px solid var(--border); border-radius: var(--radius-input); padding: 14px 16px; background: var(--bg); }
.pend .who { font-weight: 700; font-size: 14px; }
.pend .det { font-size: 12.5px; color: var(--text-muted); margin-top: 2px; }
.pend .amt { font-size: 20px; font-weight: 700; font-variant-numeric: tabular-nums; text-align: right; }

.check-line { display: flex; align-items: flex-start; gap: 10px; padding: 9px 0; }
.check-line input[type=checkbox] { width: 18px; height: 18px; margin-top: 2px; accent-color: var(--primary-dark); flex-shrink: 0; }
.check-line label { font-size: 13.5px; cursor: pointer; }
.check-line .d { font-size: 12px; color: var(--text-muted); font-weight: 400; }

.hfield { display: flex; flex-direction: column; gap: 6px; margin: 8px 0 14px; }
.hfield label { font-size: 12px; font-weight: 600; color: var(--text-secondary); }
.hfield input { padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-input); font: inherit; font-size: 15px; font-weight: 700; font-variant-numeric: tabular-nums; background: var(--bg); color: var(--text); }
.hfield input:focus { outline: 2px solid var(--primary); outline-offset: 1px; border-color: var(--primary); }

.rcv-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: none; border-radius: var(--radius-btn); font: inherit; font-weight: 600; font-size: 14px; padding: 12px 22px; cursor: pointer; background: var(--primary-dark); color: #fff; width: 100%; }
.rcv-btn:hover { background: var(--primary); }
.hint-note { font-size: 12px; color: var(--text-muted); text-align: center; margin-top: 10px; }

.htable { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
.htable th { text-align: left; font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--text-muted); padding: 7px 10px; border-bottom: 1px solid var(--border); }
.htable td { padding: 9px 10px; border-bottom: 1px solid var(--border); font-size: 13px; }
.htable tr:last-child td { border-bottom: none; }
.htable td.num { text-align: right; white-space: nowrap; }
/* Slip numbers and dates are identifiers — breaking "DC-2026-0044" across three
   lines makes the column unscannable, which is the one thing this table is for. */
.htable td.nowrap, .htable td.nowrap a { white-space: nowrap; }

.ho-pill { display: inline-block; border-radius: 999px; padding: 2px 10px; font-size: 11.5px; font-weight: 600; white-space: nowrap; }
.ho-pill.green { background: var(--green-bg); color: var(--green-text); }
.ho-pill.amber { background: var(--amber-bg); color: var(--amber-text); }
.ho-pill.red { background: var(--red-bg); color: var(--red-text); }

.edit-log { margin-top: 12px; border: 1px solid var(--amber); border-radius: var(--radius-input); background: var(--amber-bg); padding: 12px 14px; overflow-x: auto; }
.edit-log-title { font-size: 12px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--amber-text); margin-bottom: 8px; }
.edit-log .htable td, .edit-log .htable th { border-color: rgba(146,64,14,.25); }
.chg-old { color: var(--red-text); text-decoration: line-through; }
.chg-new { color: var(--green-text); font-weight: 700; }
.edit-log-note { font-size: 11.5px; color: var(--amber-text); margin-top: 8px; }
.alert-error { background: var(--red-bg); color: var(--red-text); border-radius: var(--radius-input); padding: 12px 16px; font-size: 13px; font-weight: 500; }
.alert-ok { background: var(--green-bg); color: var(--green-text); border-radius: var(--radius-input); padding: 12px 16px; font-size: 13px; font-weight: 500; }
.empty { text-align: center; color: var(--text-muted); padding: 26px 0; font-size: 13.5px; }
.slip-link { font-weight: 600; color: var(--primary-dark); text-decoration: underline; }

/* Negative-expected-cash explainer on a stranded shift. Amber, not red: the
   figure is correct, it just needs explaining — red would read as data damage. */
.neg-explain { background: var(--warn-bg); color: var(--warn); border-radius: var(--radius-input); padding: 11px 14px; font-size: 12.5px; line-height: 1.55; margin: 12px 0 4px; }
/* Live variance under the counted-cash box. Hidden while balanced so it only
   ever appears when there is something to acknowledge. */
.var-live { display: none; margin-top: 7px; font-size: 12.5px; font-weight: 600;
    border-radius: var(--radius-input); padding: 9px 12px; line-height: 1.5; }
.var-live.on { display: block; }
.var-live.short { background: var(--red-bg); color: var(--red-text); border: 1px solid rgba(225,29,72,.28); }
.var-live.over  { background: var(--warn-bg); color: var(--warn); border: 1px solid rgba(245,158,11,.34); }
.var-live b { font-variant-numeric: tabular-nums; }

/* ---- Received-history filters ----
   Two stacked rows rather than one wrapping toolbar: this card sits in the
   narrow right column, so a single flex row would wrap unpredictably between
   widths. */
.hist-filters { display: flex; flex-direction: column; gap: 8px; margin-bottom: 14px; padding: 12px; background: var(--card-alt); border: 1px solid var(--border); border-radius: var(--radius-input); }
.hf-row { display: flex; gap: 8px; flex-wrap: wrap; }
.hf { display: flex; flex-direction: column; gap: 4px; flex: 1 1 130px; min-width: 0; }
.hf.grow { flex: 1 1 150px; }
.hf > span { font-size: 11px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--text-muted); }
.hf input, .hf select { width: 100%; min-width: 0; padding: 7px 10px; border: 1px solid var(--border); border-radius: var(--radius-input); font: inherit; font-size: 13px; background: var(--card); color: var(--text); }
.hf input:focus, .hf select:focus { outline: 2px solid var(--primary); outline-offset: 1px; border-color: var(--primary); }
.hf-actions { display: flex; gap: 8px; }
.hf-actions .btn { padding: 8px 18px; font-size: 13px; }

/* ---- Transaction drill-down ---- */
.txn-link { font-size: 12.5px; font-weight: 600; color: var(--primary-dark); text-decoration: none; border: 1px solid var(--border); border-radius: var(--radius-pill); padding: 3px 11px; white-space: nowrap; }
.txn-link:hover { background: var(--primary-light, #eef2f0); border-color: var(--primary); }

.txn-head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 12px; margin-bottom: 4px; }
.txn-scope { font-size: 12.5px; color: var(--text-muted); }
.txn-scope b { color: var(--text); }

/* Tabs are anchors, not buttons — each filter state is a real, bookmarkable URL
   that works with the back button. Same rule as the admission boards. */
.tabs { display: flex; gap: 6px; flex-wrap: wrap; margin: 14px 0; }
.tabs a { padding: 6px 12px; border-radius: var(--radius-pill); background: var(--bg); border: 1px solid var(--border); font-size: 12.5px; font-weight: 600; color: var(--text-secondary); text-decoration: none; }
.tabs a:hover { border-color: var(--primary); }
.tabs a.on { background: var(--primary-dark); border-color: var(--primary-dark); color: #fff; }
.tabs a .n { opacity: .72; margin-left: 5px; }

.txn-filters { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
.txn-filters input[type=search] { padding: 8px 12px; border: 1px solid var(--border); border-radius: var(--radius-input); font: inherit; font-size: 13px; background: var(--bg); color: var(--text); min-width: 210px; }
.txn-filters label { font-size: 12.5px; color: var(--text-secondary); display: inline-flex; align-items: center; gap: 6px; }

.txn-tab-amt { font-variant-numeric: tabular-nums; }
.txn-row.void td { opacity: .5; text-decoration: line-through; }
.txn-note { font-size: 11.5px; color: var(--text-muted); display: block; margin-top: 2px; }
.txn-neg { color: var(--red-text, #b42318); }
.txn-total td { font-weight: 700; border-top: 2px solid var(--border) !important; }

.tie { border-radius: var(--radius-input); padding: 11px 14px; font-size: 12.5px; margin-bottom: 14px; }
.tie.ok { background: var(--green-bg); color: var(--green-text); }
.tie.bad { background: var(--amber-bg); color: var(--amber-text); }
.tie b { font-variant-numeric: tabular-nums; }

.unc { margin-top: 22px; border: 1px solid var(--amber); border-radius: var(--radius-input); background: var(--amber-bg); padding: 12px 14px; }
.unc summary { font-size: 12.5px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; color: var(--amber-text); cursor: pointer; }
.unc .htable td, .unc .htable th { border-color: rgba(146,64,14,.25); }
.unc-why { font-size: 11.5px; color: var(--amber-text); }
.unc-intro { font-size: 12px; color: var(--amber-text); margin: 10px 0 4px; }

@media (max-width: 700px) {
    .txn-filters input[type=search] { min-width: 0; flex: 1 1 100%; }
}
@media print {
    .sidebar, .mobile-bar, .appbar, .tabs, .txn-filters, .txn-link, .print-btn { display: none !important; }
    .main { margin: 0 !important; }
    .card { box-shadow: none !important; border: 1px solid #ccc; break-inside: avoid; }
}
</style>

<div class="content">
<?php if ($detail):
    $dc = $detail['c'];
    $rows = $detail['txn']['rows'];
    // Every filter link must preserve the other filters, or clicking a type tab
    // would silently throw away the search the admin just typed.
    $dUrl = function (array $over = []) use ($detail, $dc) {
        $p = array_filter([
            'closing' => (int) $dc['id'],
            'type'    => $detail['type'],
            'mode'    => $detail['mode'],
            'q'       => $detail['q'],
            'voided'  => $detail['voided'] ? 1 : '',
        ], fn($v) => $v !== '' && $v !== null);
        foreach ($over as $k => $v) {
            if ($v === '' || $v === null) { unset($p[$k]); } else { $p[$k] = $v; }
        }
        return 'admin_handovers.php?' . http_build_query($p);
    };
    $money = fn($v) => number_format((float) $v, 2);
?>
    <div class="page-head">
        <h1>Handover transactions</h1>
        <p>Every money row behind this closing, straight from the live tables. The figures below are what this drawer actually took — not invoice face value — so they tie to the slip.</p>
    </div>

    <?php if ($error): ?><div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card">
        <div class="txn-head">
            <div>
                <h2 style="font-size:15px;font-weight:700;margin-bottom:3px;"><?= htmlspecialchars($dc['cashier_name']) ?></h2>
                <div class="txn-scope">
                    <b><?= date('d/m/Y', strtotime($dc['closing_date'])) ?></b>
                    · slip <?= htmlspecialchars($dc['closing_number']) ?>
                    · declared Rs <?= $money($dc['handover_declared']) ?>
                </div>
            </div>
            <?php
            // Carry the history filters back, so returning from a drill-down
            // lands on the same search the admin left rather than resetting to
            // the recent 30 and making them re-enter the date range.
            $backQ = http_build_query(array_filter([
                'from' => $hFrom, 'to' => $hTo,
                'cashier' => $hCashier > 0 ? $hCashier : '', 'hq' => $hQ,
            ], fn($v) => $v !== '' && $v !== null));
            ?>
            <a class="txn-link" href="admin_handovers.php<?= $backQ ? '?' . htmlspecialchars($backQ) : '' ?>">&larr; Back to handovers</a>
        </div>

        <?php
        // The tie-out. This is the guard that makes the drill-down trustworthy:
        // the rows are proven against the live tally rather than merely assumed
        // to match it.
        $tied = $detail['tieCash'] && $detail['tieOnline'];
        ?>
        <div class="tie <?= $tied ? 'ok' : 'bad' ?>" style="margin-top:14px;">
            <?php if ($tied): ?>
                These rows tie exactly to the day's tally — cash in <b>Rs <?= $money($detail['allCash']) ?></b>,
                online <b>Rs <?= $money($detail['allOnline']) ?></b><?php if ($detail['allRefund'] > 0): ?>,
                cash refunded <b>Rs <?= $money($detail['allRefund']) ?></b><?php endif; ?>.
                Expected in hand Rs <?= $money($detail['tally']['expected_cash']) ?> after refunds and counter expenses.
            <?php else: ?>
                <strong>These rows do not tie to the day's tally.</strong>
                Rows total cash <b>Rs <?= $money($detail['allCash']) ?></b> / online <b>Rs <?= $money($detail['allOnline']) ?></b>
                / refunded <b>Rs <?= $money($detail['allRefund']) ?></b>;
                the tally says cash <b>Rs <?= $money($detail['tally']['cash_total']) ?></b> / online <b>Rs <?= $money($detail['tally']['online_total']) ?></b>
                / refunded <b>Rs <?= $money($detail['tally']['cash_refund_total']) ?></b>.
                Treat the tally as authoritative and check the panel below before acting on this list.
            <?php endif; ?>
            <?php if ($dc['status'] === 'RECEIVED'): ?>
                <br>Signed slip recorded expected Rs <?= $money($dc['expected_cash']) ?>, counted Rs <?= $money($dc['counted_cash']) ?>.
                This list is recomputed live, so a row edited after signing will differ from the slip.
            <?php endif; ?>
        </div>

        <?php // Type tabs. Counts come from the UNFILTERED set so each tab shows what it opens. ?>
        <div class="tabs">
            <a class="<?= $detail['type'] === '' ? 'on' : '' ?>" href="<?= htmlspecialchars($dUrl(['type' => ''])) ?>">All<span class="n"><?= array_sum($detail['counts']) ?></span></a>
            <?php foreach ($detail['counts'] as $t => $n): ?>
                <a class="<?= $detail['type'] === $t ? 'on' : '' ?>" href="<?= htmlspecialchars($dUrl(['type' => $t])) ?>"><?= htmlspecialchars($t) ?><span class="n"><?= $n ?></span></a>
            <?php endforeach; ?>
        </div>

        <form class="txn-filters" method="GET" action="admin_handovers.php">
            <input type="hidden" name="closing" value="<?= (int) $dc['id'] ?>">
            <?php if ($detail['type'] !== ''): ?><input type="hidden" name="type" value="<?= htmlspecialchars($detail['type']) ?>"><?php endif; ?>
            <input type="search" name="q" value="<?= htmlspecialchars($detail['q']) ?>" placeholder="Patient name or document no.">
            <select name="mode" style="padding:8px 10px;border:1px solid var(--border);border-radius:var(--radius-input);font:inherit;font-size:13px;background:var(--bg);color:var(--text);">
                <option value="">Cash &amp; online</option>
                <option value="cash" <?= $detail['mode'] === 'cash' ? 'selected' : '' ?>>Cash only</option>
                <option value="online" <?= $detail['mode'] === 'online' ? 'selected' : '' ?>>Online only</option>
            </select>
            <label><input type="checkbox" name="voided" value="1" <?= $detail['voided'] ? 'checked' : '' ?>> Show voided</label>
            <button type="submit" class="btn">Filter</button>
            <?php if ($detail['q'] !== '' || $detail['mode'] !== '' || $detail['type'] !== '' || $detail['voided']): ?>
                <a class="btn secondary" href="admin_handovers.php?closing=<?= (int) $dc['id'] ?>">Clear</a>
            <?php endif; ?>
            <button type="button" class="btn secondary print-btn" onclick="window.print()">Print</button>
        </form>

        <?php if ($detail['txn']['capped']): ?>
            <p class="unc-intro">Showing the first 300 rows of at least one stream — this list is truncated.</p>
        <?php endif; ?>

        <?php if (!$rows): ?>
            <div class="empty">
                <?php if ($detail['q'] !== '' || $detail['type'] !== '' || $detail['mode'] !== ''): ?>
                    No transactions match this filter. <a class="slip-link" href="admin_handovers.php?closing=<?= (int) $dc['id'] ?>">Clear it</a> to see the whole day.
                <?php else: ?>
                    No money was recorded against this drawer on this day.
                <?php endif; ?>
            </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="htable">
            <thead>
                <tr>
                    <th>Document</th><th>Type</th><th>Patient</th>
                    <th>Time</th><th>Method</th><th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $sumShown = 0.0;
            foreach ($rows as $r):
                $isVoid = $r['voided_at'] !== null;
                if (!$isVoid) { $sumShown += (float) $r['amount']; }
                $amt = (float) $r['amount'];
            ?>
                <tr class="txn-row<?= $isVoid ? ' void' : '' ?>">
                    <td><?= htmlspecialchars((string) $r['doc_no']) ?></td>
                    <td><?= htmlspecialchars((string) $r['type']) ?><?= $isVoid ? ' <span class="ho-pill red">VOID</span>' : '' ?></td>
                    <td>
                        <?= htmlspecialchars((string) ($r['patient'] ?? '—')) ?>
                        <?php if (!empty($r['note'])): ?><span class="txn-note"><?= htmlspecialchars((string) $r['note']) ?></span><?php endif; ?>
                    </td>
                    <td><?= $r['paid_at'] ? date('h:i A', strtotime((string) $r['paid_at'])) : '—' ?></td>
                    <td><?= htmlspecialchars(pay_method_label($r['payment_method'])) ?></td>
                    <td class="num<?= $amt < 0 ? ' txn-neg' : '' ?>">
                        <?= $amt < 0 ? '− ' . $money(abs($amt)) : $money($amt) ?>
                        <?php
                        // Face value is shown only where it genuinely differs —
                        // i.e. the drawer received LESS than the document's total
                        // (a partial payment, or an IPD bill part-settled by an
                        // earlier advance). For an outgoing row the face is the
                        // same number with the sign stripped, so printing it adds
                        // nothing but noise.
                        if ($amt > 0 && (float) $r['face'] - $amt > 0.009): ?>
                            <span class="txn-note">of <?= $money($r['face']) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="txn-total">
                    <td colspan="5">Net of the rows shown<?= $detail['voided'] ? ' (voided excluded)' : '' ?></td>
                    <td class="num">Rs <?= $money($sumShown) ?></td>
                </tr>
            </tfoot>
        </table>
        </div>
        <?php endif; ?>

        <?php
        // "What is missing" — the question an admin actually has when a slip
        // looks wrong. Silent on a clean day so it never becomes noise.
        if ($detail['uncounted']): ?>
        <details class="unc" <?= $tied ? '' : 'open' ?>>
            <summary><?= count($detail['uncounted']) ?> row<?= count($detail['uncounted']) === 1 ? '' : 's' ?> needing attention</summary>
            <p class="unc-intro">Money in or around this window that the tally treats specially — a bill with no drawer attribution belongs to nobody's shift, and a payment outside the business day lands on a neighbouring closing.</p>
            <div style="overflow-x:auto;">
            <table class="htable">
                <thead><tr><th>Document</th><th>Stream</th><th>Patient</th><th class="num">Amount</th><th>Why</th></tr></thead>
                <tbody>
                <?php foreach ($detail['uncounted'] as $u): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $u['doc_no']) ?></td>
                        <td><?= htmlspecialchars((string) $u['stream']) ?></td>
                        <td><?= htmlspecialchars((string) ($u['patient'] ?? '—')) ?></td>
                        <td class="num"><?= $money($u['amount']) ?></td>
                        <td class="unc-why"><?= htmlspecialchars((string) $u['why']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </details>
        <?php endif; ?>
    </div>

<?php else: ?>
    <div class="page-head">
        <h1>Cash Handovers</h1>
        <?php if ($canReceive): ?>
        <p>Each receptionist's shift closing lands here separately. Recount their cash, review any highlighted post-close edits, confirm the signed slip is filed, then mark received — that locks the shift for good.</p>
        <?php else: ?>
        <p>Read-only audit view of every receptionist's shift closing — pending and received. Cross-check transactions with "View transactions"; receiving a handover requires the Receive Cash Handovers permission.</p>
        <?php endif; ?>
    </div>

    <?php if ($error): ?><div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert-ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <div class="ho-grid">
        <div class="ho-col">

            <div class="card">
                <h2 style="font-size:15px;font-weight:700;margin-bottom:4px;">Pending handover<?= count($pending) === 1 ? '' : 's' ?>
                    <?php if ($pending): ?><span class="ho-pill amber" style="margin-left:8px;"><?= count($pending) ?> pending</span><?php endif; ?>
                </h2>
                <p style="font-size:12.5px;color:var(--text-muted);margin-bottom:14px;"><?= $canReceive ? 'Both confirmations are required — this is the audit gate.' : 'Awaiting receipt by whoever holds the Receive Cash Handovers permission.' ?></p>

                <?php if (!$pending): ?>
                    <div class="empty">No handovers awaiting receipt. Reception hasn't closed a day yet, or everything is already received.</div>
                <?php endif; ?>

                <?php foreach ($pending as $p): ?>
                <?php // Audit-only viewers get a read-only <div> here (nothing to
                      // submit); a receiver gets the real <form>. Same inner markup
                      // either way, so the two roles see an identical layout. ?>
                <?php $pStyle = $p !== $pending[0] ? 'margin-top:22px;padding-top:22px;border-top:1px solid var(--border);' : ''; ?>
                <?php if ($canReceive): ?>
                <form method="POST" action="admin_handovers.php" style="<?= $pStyle ?>">
                    <input type="hidden" name="action" value="mark_received">
                    <input type="hidden" name="closing_id" value="<?= (int) $p['id'] ?>">
                <?php else: ?>
                <div style="<?= $pStyle ?>">
                <?php endif; ?>

                    <?php $pEdited = $p['status'] === 'EDITED'; ?>
                    <div class="pend"<?= $pEdited ? ' style="border-color:var(--amber);background:var(--amber-bg);"' : '' ?>>
                        <div>
                            <div class="who"><?= htmlspecialchars($p['cashier_name']) ?> — <?= date('D d/m/Y', strtotime($p['closing_date'])) ?>
                                <?php if ($pEdited): ?><span class="ho-pill amber" style="margin-left:6px;">EDITED ×<?= (int) $p['edit_count'] ?> — review changes</span><?php endif; ?>
                            </div>
                            <div class="det">
                                Slip <a class="slip-link" href="shift_closing.php?print=1&closing_id=<?= (int) $p['id'] ?>" target="_blank"><?= htmlspecialchars($p['closing_number']) ?></a>
                                · closed <?= date('H:i', strtotime($p['created_at'])) ?>
                                <?= $pEdited && $p['edited_at'] ? ' · last edit ' . date('H:i', strtotime($p['edited_at'])) : '' ?>
                                · variance <?= number_format((float) $p['variance'], 2) ?><?= $p['variance_note'] ? ' (note attached)' : '' ?>
                            </div>
                            <?php // Cross-check BEFORE signing — this is the gate the list exists for. ?>
                            <div style="margin-top:8px;"><a class="txn-link" href="admin_handovers.php?closing=<?= (int) $p['id'] ?>">View transactions</a></div>
                        </div>
                        <div>
                            <div class="amt">Rs <?= number_format((float) $p['handover_declared'], 0) ?></div>
                            <div class="det" style="text-align:right;">declared handover</div>
                        </div>
                    </div>

                    <?php if (!empty($editLogs[(int) $p['id']])): ?>
                    <div class="edit-log">
                        <div class="edit-log-title">What the cashier changed after closing</div>
                        <table class="htable">
                            <tr><th>Round</th><th>Field</th><th>Was</th><th>Now</th><th>When</th></tr>
                            <?php foreach ($editLogs[(int) $p['id']] as $e): ?>
                            <tr>
                                <td>#<?= (int) $e['edit_round'] ?></td>
                                <td><?= htmlspecialchars($FIELD_LABELS[$e['field_name']] ?? $e['field_name']) ?></td>
                                <td class="chg-old"><?= htmlspecialchars($e['old_value'] ?? '—') ?></td>
                                <td class="chg-new"><?= htmlspecialchars($e['new_value'] ?? '—') ?></td>
                                <td style="white-space:nowrap;"><?= date('H:i', strtotime($e['created_at'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                        <p class="edit-log-note">Marking received approves these changes and locks the closing permanently.</p>
                    </div>
                    <?php endif; ?>

                    <?php if ($p['variance_note']): ?>
                        <p style="font-size:12.5px;color:var(--text-secondary);margin-top:10px;"><b>Variance note:</b> <?= htmlspecialchars($p['variance_note']) ?></p>
                    <?php endif; ?>

                    <?php if ($canReceive): ?>
                    <div style="margin-top:12px;">
                        <div class="check-line">
                            <input type="checkbox" id="cash_ok_<?= (int) $p['id'] ?>" name="cash_ok" value="1" required>
                            <label for="cash_ok_<?= (int) $p['id'] ?>"><b>Cash received &amp; recounted</b>
                                <div class="d">If your recount differs from the declared Rs <?= number_format((float) $p['handover_declared'], 0) ?>, correct the amount below — the difference logs as a handover discrepancy.</div>
                            </label>
                        </div>
                        <div class="check-line">
                            <input type="checkbox" id="slip_ok_<?= (int) $p['id'] ?>" name="slip_filed" value="1" required>
                            <label for="slip_ok_<?= (int) $p['id'] ?>"><b>Signed A5 slip collected &amp; filed</b>
                                <div class="d">Paper copy of <?= htmlspecialchars($p['closing_number']) ?> signed by both parties and placed in the audit file.</div>
                            </label>
                        </div>
                    </div>

                    <div class="hfield">
                        <label for="rcvd_<?= (int) $p['id'] ?>">Actual amount received (Rs) <span style="font-weight:400;color:var(--text-muted)">— edit only if different</span></label>
                        <input id="rcvd_<?= (int) $p['id'] ?>" name="handover_received" type="number" min="0" step="1"
                               value="<?= number_format((float) $p['handover_declared'], 0, '.', '') ?>">
                    </div>

                    <button class="rcv-btn" type="submit"
                            onclick="return confirm('<?= $pEdited ? 'Mark received AND approve the cashier\'s edits? The closing locks permanently.' : 'Mark this handover received? Your name and the time will be stamped on ' . htmlspecialchars($p['closing_number']) . '.' ?>');">
                        <?= $pEdited ? 'Approve changes &amp; mark received' : 'Mark received' ?>
                    </button>
                    <p class="hint-note">Stamps your name + time on <?= htmlspecialchars($p['closing_number']) ?><?= $pEdited ? ', approves the highlighted changes,' : '' ?> and locks the shift's audit trail.</p>
                    <?php else: ?>
                    <p class="hint-note">Audit view — receiving this handover requires the Receive Cash Handovers permission.</p>
                    <?php endif; ?>
                <?php if ($canReceive): ?>
                </form>
                <?php else: ?>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <?php if ($canReceive && $strandedShifts): ?>
            <div class="card">
                <?php
                // Two populations in one card. Overdue days are a problem; a day
                // the receptionist simply could not close is routine cover, and
                // presenting both in alarm red would train the admin to ignore red.
                $overdueCount = count(array_filter($strandedShifts, fn($s) => !empty($s['stranded'])));
                $standInCount = count($strandedShifts) - $overdueCount;
                ?>
                <h2 style="font-size:15px;font-weight:700;margin-bottom:4px;">Close a shift on someone's behalf
                    <?php if ($overdueCount): ?>
                    <span class="ho-pill red" style="margin-left:8px;" title="Older than 5 days"><?= $overdueCount ?> overdue</span>
                    <?php endif; ?>
                    <?php if ($standInCount): ?>
                    <span class="ho-pill" style="margin-left:6px;" title="Within the receptionist's own window"><?= $standInCount ?> to cover</span>
                    <?php endif; ?>
                </h2>
                <p style="font-size:12.5px;color:var(--text-muted);margin-bottom:14px;">
                    Any day a receptionist has not closed — including today. If someone is off sick or
                    cannot finish their closing, count the cash you are holding for that day, enter what
                    you actually received, and add a note. This closes and receives it in one step, under
                    their name, stamped as closed by you.
                    <strong>A mismatch between counted and expected is allowed</strong> — it is recorded
                    as the variance and printed on the slip.
                </p>
                <?php /* The slip can only name WHO closed it once the attribution column
                         exists. Everything else works without it, so this informs rather
                         than blocks. */ ?>
                <?php if (!column_exists($pdo, 'shift_closings', 'closed_by_admin_id')): ?>
                <p class="neg-explain" style="margin-top:0;margin-bottom:14px;">
                    <strong>Run <code>sql/add_admin_close_behalf.sql</code></strong> to record who closed
                    a shift on whose behalf. Closing works without it and the cash reconciles correctly,
                    but the slip cannot print &ldquo;closed by you on their behalf&rdquo; until it is applied.
                </p>
                <?php endif; ?>

                <?php foreach ($strandedShifts as $i => $s): ?>
                <form method="POST" action="admin_handovers.php" style="<?= $i > 0 ? 'margin-top:20px;padding-top:20px;border-top:1px solid var(--border);' : '' ?>">
                    <input type="hidden" name="action" value="admin_close_behalf">
                    <input type="hidden" name="cashier_id" value="<?= (int) $s['cashier_id'] ?>">
                    <input type="hidden" name="close_date" value="<?= htmlspecialchars($s['date']) ?>">

                    <?php
                    // expected_cash is cash_in − refunds − counter expenses, so it goes
                    // NEGATIVE on a day where a drawer paid money out without taking any
                    // in (a refund against an older bill, or a petty-cash voucher). That
                    // is a real, correct figure — but you cannot count negative notes, so
                    // the count fields are min="0" and must not be prefilled with it.
                    // Prefilling −2,000 into a min="0" input made the shift IMPOSSIBLE to
                    // close: the browser blocked every submit and the day stayed stranded.
                    $expCash    = (float) $s['expected_cash'];
                    $isNegative = $expCash < 0;
                    $prefill    = number_format(max(0, $expCash), 0, '.', '');
                    ?>
                    <?php
                    $isOverdue = !empty($s['stranded']);
                    $age = (int) $s['age_days'];
                    $ageTxt = $age === 0 ? 'today' : ($age === 1 ? 'yesterday' : $age . ' days ago');
                    $rowStyle = $isOverdue
                        ? 'border-color:var(--red);background:var(--red-bg);'
                        : 'border-color:rgba(245,158,11,.45);background:var(--amber-bg);';
                    $fid = 'beh' . (int) $s['cashier_id'] . '_' . preg_replace('/\D/', '', $s['date']);
                    ?>
                    <div class="pend" style="<?= $rowStyle ?>">
                        <div>
                            <div class="who"><?= htmlspecialchars($s['cashier_name']) ?> — <?= date('D d/m/Y', strtotime($s['date'])) ?>
                                <?php if ($isOverdue): ?>
                                <span class="ho-pill red" style="margin-left:6px;">overdue</span>
                                <?php endif; ?>
                            </div>
                            <div class="det"><?= $ageTxt ?> · expected cash Rs <?= number_format($expCash, 0) ?> (their system tally)</div>
                        </div>
                        <div>
                            <div class="amt">Rs <?= number_format($expCash, 0) ?></div>
                            <div class="det" style="text-align:right;">expected</div>
                        </div>
                    </div>

                    <?php if ($isNegative): ?>
                    <p class="neg-explain">
                        <strong>Expected cash is negative.</strong> On this day this drawer paid out more
                        than it took in — a refund against an earlier bill, or a counter expense, with no
                        cash collected to offset it. Nothing is wrong with the data: the clinic owed the
                        drawer Rs <?= number_format(abs($expCash), 0) ?>, it did not hold it.
                        Enter <strong>0</strong> below if no cash was physically handed over, and say so
                        in the note.
                    </p>
                    <?php endif; ?>

                    <div class="hfield">
                        <label>Counted cash for that day (Rs)</label>
                        <input name="counted_cash" type="number" min="0" step="1" required
                               id="<?= $fid ?>_cnt" data-expected="<?= htmlspecialchars((string) $expCash) ?>"
                               value="<?= $prefill ?>">
                        <?php /* Live variance. The admin is allowed to record a mismatch, but
                                 must SEE it before committing — a short drawer discovered on
                                 the printed slip a week later is a different problem from one
                                 acknowledged at the moment of closing. */ ?>
                        <div class="var-live" id="<?= $fid ?>_var" aria-live="polite"></div>
                    </div>
                    <div class="hfield">
                        <label>Actual amount you received (Rs)</label>
                        <input name="handover_received" type="number" min="0" step="1" required
                               value="<?= $prefill ?>">
                    </div>
                    <div class="hfield">
                        <label>Note (required) — why you closed this for them</label>
                        <input name="behalf_note" type="text" maxlength="255" required
                               placeholder="e.g. receptionist off sick; cash counted from safe with witness">
                    </div>

                    <?php /* The confirm restates the variance in words, computed from
                             what is in the box at click time — so the last thing seen
                             before an irreversible close is the size of the mismatch,
                             not a generic "are you sure". */ ?>
                    <button class="rcv-btn" type="submit"
                            onclick="return confirmBehalf(this, '<?= htmlspecialchars(date('d/m/Y', strtotime($s['date'])), ENT_QUOTES) ?>', '<?= htmlspecialchars($s['cashier_name'], ENT_QUOTES) ?>', '<?= $fid ?>_cnt');">
                        Close &amp; receive on their behalf
                    </button>
                    <p class="hint-note">Records the closing under <?= htmlspecialchars($s['cashier_name']) ?>, stamped "closed by you on their behalf". Once received it cannot be reopened.</p>
                </form>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        </div>

        <div class="ho-col">
            <div class="card">
                <h2 style="font-size:15px;font-weight:700;margin-bottom:4px;">Received handovers</h2>
                <p style="font-size:12.5px;color:var(--text-muted);margin-bottom:14px;">
                    <?php if ($hFiltered): ?>
                        <?= count($history) ?> matching handover<?= count($history) === 1 ? '' : 's' ?>, newest first. Click a slip to reprint, or View transactions to cross-check it.
                    <?php else: ?>
                        The 30 most recent, newest first. Filter by date or cashier to reach older ones.
                    <?php endif; ?>
                </p>

                <?php // Reaches the whole archive — without this a handover older than 30 closings was unreachable. ?>
                <form class="hist-filters" method="GET" action="admin_handovers.php">
                    <div class="hf-row">
                        <label class="hf">
                            <span>From</span>
                            <input type="date" name="from" value="<?= htmlspecialchars($hFrom) ?>" max="<?= date('Y-m-d') ?>">
                        </label>
                        <label class="hf">
                            <span>To</span>
                            <input type="date" name="to" value="<?= htmlspecialchars($hTo) ?>" max="<?= date('Y-m-d') ?>">
                        </label>
                    </div>
                    <div class="hf-row">
                        <label class="hf grow">
                            <span>Cashier</span>
                            <select name="cashier">
                                <option value="0">All cashiers</option>
                                <?php foreach ($hCashiers as $hc): ?>
                                    <option value="<?= (int) $hc['id'] ?>" <?= $hCashier === (int) $hc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($hc['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="hf grow">
                            <span>Search</span>
                            <input type="search" name="hq" value="<?= htmlspecialchars($hQ) ?>" placeholder="Slip no. or cashier">
                        </label>
                    </div>
                    <div class="hf-actions">
                        <button type="submit" class="btn">Search</button>
                        <?php if ($hFiltered): ?>
                            <a class="btn secondary" href="admin_handovers.php">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>

                <div style="overflow-x:auto;">
                <table class="htable">
                    <tr><th>Date</th><th>Slip</th><th>Cashier</th><th class="num">Declared</th><th class="num">Received</th><th>Status</th></tr>
                    <?php if (!$history): ?>
                        <tr><td colspan="6" class="empty">
                            <?php if ($hFiltered): ?>
                                No received handover matches these filters. <a class="slip-link" href="admin_handovers.php">Clear them</a> to see the most recent.
                            <?php else: ?>
                                Nothing received yet.
                            <?php endif; ?>
                        </td></tr>
                    <?php endif; ?>
                    <?php foreach ($history as $h): ?>
                    <?php $disc = round((float) $h['handover_received'] - (float) $h['handover_declared'], 2); ?>
                    <tr>
                        <?php // Show the year too: the list now reaches back months, so "Fri 24/07" alone is ambiguous. ?>
                        <td class="nowrap"><?= date('d/m/y', strtotime($h['closing_date'])) ?></td>
                        <td class="nowrap"><a class="slip-link" href="shift_closing.php?print=1&closing_id=<?= (int) $h['id'] ?>" target="_blank"><?= htmlspecialchars($h['closing_number']) ?></a></td>
                        <td><?= htmlspecialchars($h['cashier_name']) ?></td>
                        <td class="num"><?= number_format((float) $h['handover_declared'], 0) ?></td>
                        <td class="num"><?= number_format((float) $h['handover_received'], 0) ?></td>
                        <td>
                            <?php if (abs($disc) > 0.009): ?>
                                <span class="ho-pill red"><?= $disc < 0 ? '− ' : '+ ' ?><?= number_format(abs($disc), 0) ?> discrepancy</span>
                            <?php else: ?>
                                <span class="ho-pill green">Received · filed</span>
                            <?php endif; ?>
                            <?php if ((int) ($h['edit_count'] ?? 0) > 0): ?>
                                <span class="ho-pill amber" title="Cashier edited before receipt; approved at mark-received">edited ×<?= (int) $h['edit_count'] ?></span>
                            <?php endif; ?>
                            <?php // Filters ride along so "Back to handovers" returns to this same search. ?>
                            <a class="txn-link" href="admin_handovers.php?closing=<?= (int) $h['id'] ?><?= $histQs ?>">Transactions</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>
</div></div><!-- .main + .app -->
<!-- dd/mm/yyyy display over a hidden yyyy-mm-dd value — the house convention.
     This page has date inputs on the history filter, so without this the picker
     reads as mm/dd on a US-English machine. -->
<script src="assets/js/date-picker.js?v=<?= @filemtime(__DIR__ . '/assets/js/date-picker.js') ?: 1 ?>"></script>
<script>
// Live variance on the close-on-behalf forms.
//
// A mismatch is ALLOWED — an admin counting a drawer for someone who is not
// there will sometimes find less or more than the system expects, and refusing
// the close would leave the day open forever. But it must be acknowledged at
// the moment of closing, not discovered on a printed slip a week later, so the
// difference is spelled out in words as soon as it appears.
function confirmBehalf(btn, dateTxt, who, cntId) {
    var input = document.getElementById(cntId);
    var msg = 'Close ' + dateTxt + ' on behalf of ' + who + '?\n\nThis is recorded under your name and cannot be reopened once received.';
    if (input) {
        var expected = parseFloat(input.getAttribute('data-expected'));
        var counted = parseFloat(input.value);
        if (!isNaN(expected) && !isNaN(counted)) {
            var diff = Math.round((counted - expected) * 100) / 100;
            if (Math.abs(diff) >= 0.01) {
                var amt = Math.abs(diff).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                msg = 'CASH MISMATCH — ' + (diff < 0 ? 'SHORT' : 'OVER') + ' by Rs ' + amt
                    + '\n\nExpected Rs ' + expected.toLocaleString('en-PK', {maximumFractionDigits: 2})
                    + ', counted Rs ' + counted.toLocaleString('en-PK', {maximumFractionDigits: 2})
                    + '.\n\nClosing anyway is allowed. The difference is recorded as the variance '
                    + 'and printed on the slip under ' + who + "'s name.\n\n" + msg;
            }
        }
    }
    return window.confirm(msg);
}

(function () {
    var boxes = document.querySelectorAll('.var-live[id]');
    Array.prototype.forEach.call(boxes, function (out) {
        var input = document.getElementById(out.id.replace(/_var$/, '_cnt'));
        if (!input) { return; }
        var expected = parseFloat(input.getAttribute('data-expected'));
        if (isNaN(expected)) { return; }

        function render() {
            var counted = parseFloat(input.value);
            if (input.value === '' || isNaN(counted)) {
                out.className = 'var-live';
                out.textContent = '';
                return;
            }
            var diff = Math.round((counted - expected) * 100) / 100;
            if (Math.abs(diff) < 0.01) {
                out.className = 'var-live';
                out.textContent = '';
                return;
            }
            var short = diff < 0;
            var amt = Math.abs(diff).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            out.className = 'var-live on ' + (short ? 'short' : 'over');
            out.innerHTML = (short ? 'SHORT by Rs <b>' : 'OVER by Rs <b>') + amt + '</b>'
                + ' — expected Rs ' + expected.toLocaleString('en-PK', {maximumFractionDigits: 2})
                + '. This is allowed; it will be recorded as the variance and printed on the slip. '
                + 'Say why in the note.';
        }
        input.addEventListener('input', render);
        render();
    });
})();
</script>
</body>
</html>
