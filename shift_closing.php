<?php
// Shift closing & cash handover — reception side (approved 2026-07-23;
// reworked to PER-RECEPTIONIST + post-close edits the same day).
//
// Each receptionist closes THEIR OWN shift: live tally of the payments they
// recorded, the refunds they generated, the expenses they posted. No float in
// personal tallies. Submit → their own money actions lock for the day, the
// A5 slip (?print=1) opens, and the handover queues in admin_handovers.php.
//
// EDITS: while status is PENDING_RECEIPT or EDITED, the same cashier may
// reopen the count/handover figures. Changes apply immediately, flip status
// to EDITED, log per-field in shift_closing_edits, and email admin — who
// approves them implicitly at mark-received time. Once RECEIVED: locked.
//
// Requires sql/add_shift_closings.sql + add_closing_expenses.sql +
// add_per_user_closings.sql to be applied.

require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/billing.php';
require_once __DIR__ . '/config/notify.php';
refresh_session_permissions($pdo);
require_permission('RECEPTION_CLOSE_DAY');

$error = '';
$uid = (int) $_SESSION['user_id'];
$cutoffHour = day_cutoff_hour($pdo);

// The live BUSINESS day (cutoff-hour aware): before the cutoff hour we are still
// closing the day that started yesterday, so an overnight shift stays one closing
// and its evening takings don't fall off the tally after midnight.
$liveBiz = business_day($pdo);

// LATE CLOSING: a receptionist may close any of their own unclosed business days
// within the last 5. ?date=YYYY-MM-DD targets a past day; default is the live day.
// Anything older than 5 days, or already closed, or in the future, is refused —
// an admin closes older stragglers on the reception's behalf (admin_handovers.php).
const LATE_CLOSE_WINDOW_DAYS = 5;
$today = $liveBiz;
$lateCloseError = '';
$requestedDate = $_GET['date'] ?? $_POST['closing_date'] ?? '';
if ($requestedDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedDate)) {
    $reqTs = strtotime($requestedDate);
    $ageDays = $reqTs !== false ? (int) round((strtotime($liveBiz) - $reqTs) / 86400) : -1;
    if ($reqTs === false || $ageDays < 0) {
        $lateCloseError = 'That date is not valid for closing.';
    } elseif ($ageDays > LATE_CLOSE_WINDOW_DAYS) {
        $lateCloseError = 'Days older than ' . LATE_CLOSE_WINDOW_DAYS
            . ' are closed by an admin on your behalf — ask an admin to close ' . date('d/m/Y', $reqTs) . '.';
    } else {
        $today = $requestedDate;   // valid past day inside the window
    }
}
$isPastDay = $today !== $liveBiz;
$isOvernight = !$isPastDay && $today !== date('Y-m-d');   // now past midnight but before cutoff

// Counted cash is now entered as a single total (the note-by-note breakdown was
// removed as unnecessary friction). Read the one field, floor at zero.
function sc_counted_cash(): float {
    return round(max(0.0, (float) str_replace(',', '', $_POST['counted_cash'] ?? '0')), 2);
}

// ---------------- Print view (A5 closing slip) ----------------
if (isset($_GET['print']) && isset($_GET['closing_id'])) {
    $closingId = (int) $_GET['closing_id'];

    // closed_by_admin_id only exists after add_admin_late_close.sql; join for the
    // "on behalf of" line only when the column is present, so pre-migration prints work.
    $behalfJoin = column_exists($pdo, 'shift_closings', 'closed_by_admin_id')
        ? 'LEFT JOIN users cb ON cb.id = c.closed_by_admin_id'
        : '';
    $behalfSelect = $behalfJoin ? ', cb.name AS closed_by_admin_name' : '';
    $stmt = $pdo->prepare("
        SELECT c.*, cu.name AS cashier_name, au.name AS admin_name{$behalfSelect}
        FROM shift_closings c
        JOIN users cu ON cu.id = c.cashier_id
        JOIN users au ON au.id = c.handover_to_id
        {$behalfJoin}
        WHERE c.id = ?
    ");
    $stmt->execute([$closingId]);
    $closing = $stmt->fetch();

    if (!$closing) {
        http_response_code(404);
        die('Closing slip not found.');
    }

    // Denomination breakdown was removed from the closing flow; the print
    // partial still guards on this, so an empty list simply skips the section.
    $denominations = [];

    $pdo->prepare('UPDATE shift_closings SET printed_at = NOW() WHERE id = ?')->execute([$closingId]);

    include __DIR__ . '/views/closing_print_partial.php';
    exit;
}

// A close/edit POST carrying an out-of-window date would otherwise silently fall
// back to closing the live day. Refuse it outright so nothing lands on the wrong day.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($_POST['action'] ?? '', ['close_day', 'edit_closing'], true)
    && $lateCloseError !== '') {
    $error = $lateCloseError;
}

// ---------------- Submit closing ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close_day' && $error === '') {
    $handoverToId = (int) ($_POST['handover_to_id'] ?? 0);
    $handoverDeclared = round((float) str_replace(',', '', $_POST['handover_declared'] ?? '0'), 2);
    $varianceNote = trim($_POST['variance_note'] ?? '');

    try {
        $pdo->beginTransaction();

        if (day_closing($pdo, $today, $uid)) {
            $error = 'You have already closed your shift today. Use "Edit my closing" below to correct it.';
        } elseif ($handoverToId <= 0) {
            $error = 'Pick the admin you are handing the cash to.';
        } elseif ($handoverDeclared < 0) {
            $error = 'The handover amount cannot be negative.';
        } else {
            $counted = sc_counted_cash();

            $tally = day_cash_tally($pdo, $today, $uid);
            $variance = round($counted - $tally['expected_cash'], 2);

            if (abs($variance) > 0.009 && $varianceNote === '') {
                $error = 'The count is ' . ($variance < 0 ? 'short' : 'over') . ' by Rs '
                       . number_format(abs($variance), 2) . ' — a variance note is required.';
            } elseif ($handoverDeclared > $counted) {
                $error = 'You cannot hand over more than the Rs ' . number_format($counted, 2) . ' counted.';
            } else {
                $closingNumber = generate_closing_number($pdo);

                $pdo->prepare('
                    INSERT INTO shift_closings
                        (closing_number, closing_date, cashier_id, opening_float,
                         cash_consult_total, cash_consult_count,
                         cash_admission_total, cash_admission_count,
                         online_total, online_count,
                         cash_refund_total, cash_refund_count,
                         expense_total, expense_count,
                         expected_cash, counted_cash, variance, variance_note,
                         float_retained, handover_declared, handover_to_id)
                    VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
                ')->execute([
                    $closingNumber, $today, $uid,
                    $tally['cash_consult_total'], $tally['cash_consult_count'],
                    $tally['cash_admission_total'], $tally['cash_admission_count'],
                    $tally['online_total'], $tally['online_count'],
                    $tally['cash_refund_total'], $tally['cash_refund_count'],
                    $tally['expense_total'], $tally['expense_count'],
                    $tally['expected_cash'], $counted, $variance, $varianceNote ?: null,
                    $handoverDeclared, $handoverToId,
                ]);
                $closingId = (int) $pdo->lastInsertId();

                $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)');
                $log->execute([
                    $uid,
                    'shift_closed',
                    "Closing $closingNumber for $today: counted Rs " . number_format($counted, 2)
                    . ' vs expected Rs ' . number_format($tally['expected_cash'], 2)
                    . ' (variance ' . number_format($variance, 2) . '), handover declared Rs '
                    . number_format($handoverDeclared, 2),
                ]);

                $pdo->commit();

                // Summary to admin (best-effort, after commit).
                notify_day_closed($pdo, $closingId);

                header('Location: shift_closing.php?print=1&closing_id=' . $closingId);
                exit;
            }
        }

        $pdo->rollBack();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Could not close the shift. Please try again.';
    }
}

// ---------------- Edit a closed shift (same cashier, before admin receives) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_closing' && $error === '') {
    $handoverDeclared = round((float) str_replace(',', '', $_POST['handover_declared'] ?? '0'), 2);
    $varianceNote = trim($_POST['variance_note'] ?? '');

    try {
        $pdo->beginTransaction();

        // Lock the row: only the cashier themself, only while not yet received.
        $stmt = $pdo->prepare("
            SELECT * FROM shift_closings
            WHERE closing_date = ? AND cashier_id = ? AND status IN ('PENDING_RECEIPT','EDITED')
            FOR UPDATE
        ");
        $stmt->execute([$today, $uid]);
        $closing = $stmt->fetch();

        if (!$closing) {
            $error = 'Nothing editable — either your shift is not closed yet, or admin has already received the handover (locked).';
        } elseif ($handoverDeclared < 0) {
            $error = 'The handover amount cannot be negative.';
        } else {
            $counted = sc_counted_cash();
            $expected = (float) $closing['expected_cash'];   // system side is not re-tallied — the shift's money is locked
            $variance = round($counted - $expected, 2);

            if (abs($variance) > 0.009 && $varianceNote === '') {
                $error = 'The count is ' . ($variance < 0 ? 'short' : 'over') . ' by Rs '
                       . number_format(abs($variance), 2) . ' — a variance note is required.';
            } elseif ($handoverDeclared > $counted) {
                $error = 'You cannot hand over more than the Rs ' . number_format($counted, 2) . ' counted.';
            } else {
                $round = (int) $closing['edit_count'] + 1;

                // Per-field change log — only what actually moved.
                $changes = [];
                if (abs((float) $closing['counted_cash'] - $counted) > 0.009) {
                    $changes['counted_cash'] = [number_format((float) $closing['counted_cash'], 2, '.', ''), number_format($counted, 2, '.', '')];
                }
                if (abs((float) $closing['handover_declared'] - $handoverDeclared) > 0.009) {
                    $changes['handover_declared'] = [number_format((float) $closing['handover_declared'], 2, '.', ''), number_format($handoverDeclared, 2, '.', '')];
                }
                if (($closing['variance_note'] ?? '') !== $varianceNote) {
                    $changes['variance_note'] = [$closing['variance_note'] ?? '', $varianceNote];
                }

                if (!$changes) {
                    $error = 'Nothing changed — the figures are the same as before.';
                } else {
                    $editIns = $pdo->prepare('
                        INSERT INTO shift_closing_edits (closing_id, edit_round, field_name, old_value, new_value, edited_by_id)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ');
                    foreach ($changes as $field => [$old, $new]) {
                        $editIns->execute([(int) $closing['id'], $round, $field, $old !== '' ? $old : null, $new !== '' ? $new : null, $uid]);
                    }

                    $pdo->prepare("
                        UPDATE shift_closings
                        SET counted_cash = ?, variance = ?, variance_note = ?,
                            handover_declared = ?, status = 'EDITED',
                            edited_at = NOW(), edit_count = ?
                        WHERE id = ?
                    ")->execute([$counted, $variance, $varianceNote ?: null, $handoverDeclared, $round, (int) $closing['id']]);

                    $changedList = implode(', ', array_keys($changes));
                    $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
                        ->execute([$uid, 'shift_closing_edited',
                            "Closing {$closing['closing_number']} edited (round $round): $changedList"]);

                    $pdo->commit();

                    // Alert admin: the signed figures changed (best-effort).
                    notify_closing_edited($pdo, (int) $closing['id'], $round);

                    header('Location: shift_closing.php?print=1&closing_id=' . (int) $closing['id']);
                    exit;
                }
            }
        }

        $pdo->rollBack();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Could not save the edit. Please try again.';
    }
}

// ---------------- Page data ----------------
$closing = null;
$tally = null;
try {
    $closing = day_closing($pdo, $today, $uid);
    $tally = day_cash_tally($pdo, $today, $uid);
} catch (PDOException $e) {
    http_response_code(500);
    die('Shift-closing tables missing or outdated — run sql/add_per_user_closings.sql.');
}

$adminsStmt = $pdo->query("SELECT id, name FROM users WHERE base_role = 'ADMIN' ORDER BY name");
$admins = $adminsStmt->fetchAll();

// Edit mode: the closed shift reopened for correction (?edit=1, editable states only).
$editMode = $closing && isset($_GET['edit'])
    && in_array($closing['status'], ['PENDING_RECEIPT', 'EDITED'], true);

// The form serves both modes; in edit mode expected cash comes from the
// snapshot (the shift's money is locked, so the system side cannot change).
$formExpected = $editMode ? (float) $closing['expected_cash'] : $tally['expected_cash'];
$suggestedHandover = $editMode ? (float) $closing['handover_declared'] : max(0, $tally['expected_cash']);
$showForm = !$closing || $editMode;

$meStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
$meStmt->execute([$uid]);
$myName = $meStmt->fetchColumn() ?: 'Me';

// Straggler list: my unclosed business days in the window, EXCLUDING the live day
// (that one is the normal close below). Only shown to drawer holders.
$myUnclosed = [];
if (user_holds_drawer($pdo, $uid)) {
    $myUnclosed = unclosed_business_days($pdo, $uid, LATE_CLOSE_WINDOW_DAYS, false);
}

$pageTitle = 'Day Closing';
require __DIR__ . '/partials/head.php';
$navActive = 'shift_closing';
require __DIR__ . '/partials/sidebar.php';
?>
<style>
/* One A5-proportioned sheet: the whole closing fits on a single screen, no scrolling.
   Ledger on the left half, count + handover on the right, one rule down the middle. */
.sheet { max-width: 900px; margin: 0 auto; background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-card); box-shadow: var(--shadow-sm); overflow: hidden; }
.sheet-head { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; padding: 14px 20px; border-bottom: 1px solid var(--border); }
.sheet-head h1 { font-size: 17px; font-weight: 700; letter-spacing: -.01em; margin: 0; }
.sheet-head .who { font-size: 12px; color: var(--text-muted); }
.sheet-body { display: grid; grid-template-columns: 1fr 1fr; }
.sheet-body > div { padding: 16px 20px; }
.sheet-body > div + div { border-left: 1px solid var(--border); }
@media (max-width: 820px) {
    .sheet-body { grid-template-columns: 1fr; }
    .sheet-body > div + div { border-left: none; border-top: 1px solid var(--border); }
}

.blk { font-size: 10.5px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--text-muted); margin: 0 0 6px; }
.blk + .blk, .ltab + .blk { margin-top: 16px; }

.ltab { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; font-size: 13px; }
.ltab td { padding: 5px 0; border-bottom: 1px dotted var(--border); }
.ltab td.num { text-align: right; white-space: nowrap; }
.ltab td.neg { color: var(--red-text); }
.ltab td.sub { color: var(--text-muted); font-size: 11.5px; }
.ltab tr.total td { font-weight: 700; font-size: 14px; border-top: 1.5px solid var(--border-strong); border-bottom: none; padding-top: 7px; }
.ltab tr:last-child td { border-bottom: none; }

.expect { display: flex; justify-content: space-between; align-items: baseline; background: var(--primary-dark); color: #fff; border-radius: var(--radius-input); padding: 12px 15px; margin-bottom: 14px; }
.expect .k { font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #9BD4CF; }
.expect .v { font-size: 24px; font-weight: 700; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }

.variance-strip { display: flex; justify-content: space-between; align-items: center; border-radius: var(--radius-input); padding: 9px 13px; margin: -4px 0 14px; font-weight: 600; font-size: 12.5px; }
.variance-strip.ok { background: var(--green-bg); color: var(--green-text); }
.variance-strip.short { background: var(--red-bg); color: var(--red-text); }
.variance-strip.over { background: var(--amber-bg); color: var(--amber-text); }
.variance-strip .amt { font-size: 14px; font-weight: 700; font-variant-numeric: tabular-nums; }

.cfield { display: flex; flex-direction: column; gap: 5px; margin-bottom: 12px; }
.cfield label { font-size: 11.5px; font-weight: 600; color: var(--text-secondary); }
.cfield input, .cfield select, .cfield textarea { padding: 9px 12px; border: 1px solid var(--border); border-radius: var(--radius-input); font: inherit; font-size: 13.5px; background: var(--bg); color: var(--text); }
.cfield textarea { resize: vertical; min-height: 46px; }
.cfield input:focus, .cfield select:focus, .cfield textarea:focus { outline: 2px solid var(--primary); outline-offset: 1px; border-color: var(--primary); }
.two-up { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.two-up .cfield { margin-bottom: 12px; }

.close-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: none; border-radius: var(--radius-btn); font: inherit; font-weight: 600; font-size: 14px; padding: 12px 22px; cursor: pointer; background: var(--primary-dark); color: #fff; width: 100%; }
.close-btn:hover { background: var(--primary); }
.close-btn.amber { background: var(--amber); color: #3B2E08; }
.lock-note { font-size: 11px; color: var(--text-muted); text-align: center; margin-top: 8px; }
.alert-error { background: var(--red-bg); color: var(--red-text); border-radius: var(--radius-input); padding: 12px 16px; font-size: 13px; font-weight: 500; }
.closed-box { background: var(--green-bg); color: var(--green-text); border-radius: var(--radius-card); padding: 20px 22px; }
.closed-box.amberish { background: var(--amber-bg); color: var(--amber-text); }
.closed-box b { font-size: 15px; }
.closed-box .row { margin-top: 6px; font-size: 13.5px; }
.closed-actions { margin-top: 14px; display: flex; gap: 10px; flex-wrap: wrap; }
.closed-actions a { display: inline-flex; align-items: center; padding: 9px 16px; border-radius: var(--radius-btn); font-weight: 600; font-size: 13px; background: var(--card); color: var(--text); border: 1px solid var(--border); }
.edit-banner { background: var(--amber-bg); color: var(--amber-text); border-radius: var(--radius-input); padding: 10px 14px; font-size: 12.5px; font-weight: 600; }
.notice-stack { max-width: 900px; margin: 0 auto 14px; display: flex; flex-direction: column; gap: 10px; }
</style>

<div class="content">

    <?php $hasNotices = $lateCloseError || $error || $isPastDay || $isOvernight || $myUnclosed || $editMode; ?>
    <?php if ($hasNotices): ?>
    <div class="notice-stack">
        <?php if ($lateCloseError): ?>
            <div class="alert-error"><?= htmlspecialchars($lateCloseError) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($isPastDay): ?>
            <div class="edit-banner">
                Closing a <b>past</b> day left open. Older than <?= LATE_CLOSE_WINDOW_DAYS ?> days → admin only.
                <a href="shift_closing.php" style="color:var(--amber-text);text-decoration:underline;">Back to today</a>
            </div>
        <?php endif; ?>

        <?php if ($isOvernight): ?>
            <div class="edit-banner">
                Past midnight — still closing the <b><?= date('D d/m/Y', strtotime($today)) ?></b> business day.
                Everything up to <?= sprintf('%02d:00', $cutoffHour) ?> counts here.
            </div>
        <?php endif; ?>

        <?php if ($myUnclosed): ?>
            <div class="edit-banner">
                <?= count($myUnclosed) ?> unclosed day<?= count($myUnclosed) === 1 ? '' : 's' ?>:
                <?php foreach ($myUnclosed as $u): ?>
                    <a href="shift_closing.php?date=<?= htmlspecialchars($u['date']) ?>"
                       style="color:var(--amber-text);text-decoration:underline;margin-left:6px;">
                        <?= date('D d/m', strtotime($u['date'])) ?> (Rs <?= number_format($u['expected_cash'], 0) ?>)
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($editMode): ?>
            <div class="edit-banner">
                Editing <?= htmlspecialchars($closing['closing_number']) ?> — count and handover reopen below.
                Changes are logged old&rarr;new and admin is emailed. The shift's payments stay locked.
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($closing && !$editMode): ?>

        <div class="closed-box<?= $closing['status'] === 'EDITED' ? ' amberish' : '' ?>" style="max-width:900px;margin:0 auto;">
            <b>Your shift is closed — slip <?= htmlspecialchars($closing['closing_number']) ?><?= $closing['status'] === 'EDITED' ? ' (edited ×' . (int) $closing['edit_count'] . ', awaiting admin review)' : '' ?></b>
            <div class="row">
                Counted Rs <?= number_format((float) $closing['counted_cash'], 2) ?>
                vs expected Rs <?= number_format((float) $closing['expected_cash'], 2) ?>
                (variance <?= number_format((float) $closing['variance'], 2) ?>)
                &middot; handover declared Rs <?= number_format((float) $closing['handover_declared'], 2) ?>
                &middot; status: <?= $closing['status'] === 'RECEIVED' ? 'received by admin (locked)' : 'awaiting admin receipt' ?>
            </div>
            <div class="closed-actions">
                <a href="shift_closing.php?print=1&closing_id=<?= (int) $closing['id'] ?>" target="_blank">Reprint A5 closing slip</a>
                <?php if (in_array($closing['status'], ['PENDING_RECEIPT', 'EDITED'], true)): ?>
                    <a href="shift_closing.php?edit=1<?= $isPastDay ? '&date=' . htmlspecialchars($today) : '' ?>" style="border-color:var(--amber);color:var(--amber-text);background:var(--amber-bg);">Edit my closing</a>
                <?php endif; ?>
            </div>
        </div>

    <?php endif; ?>

    <?php if ($showForm): ?>
    <?php
    // ER is folded into the admission buckets; split it out on the line so the
    // cashier can see walk-in service cash for their own reconciliation.
    $cashEr = (int) ($tally['cash_er_count'] ?? 0);
    $onlineEr = (int) ($tally['online_er_count'] ?? 0);
    ?>

    <form method="POST" action="shift_closing.php" id="closeForm">
    <input type="hidden" name="action" value="<?= $editMode ? 'edit_closing' : 'close_day' ?>">
    <input type="hidden" name="closing_date" value="<?= htmlspecialchars($today) ?>">

    <div class="sheet">

        <div class="sheet-head">
            <h1><?= $editMode ? 'Edit Closing' : 'Shift Closing' ?> — <?= date('D d/m/Y', strtotime($today)) ?><?= $isPastDay ? ' (late)' : '' ?></h1>
            <span class="who"><?= htmlspecialchars($myName) ?> · my takings only</span>
        </div>

        <div class="sheet-body">

            <!-- ==== LEFT: system-side ledger (this user only) ==== -->
            <div>
                <p class="blk">My collections — system figures</p>
                <table class="ltab">
                    <tr>
                        <td>Cash <?= $tally['cash_count'] ?></td>
                        <td class="sub">consult <?= $tally['cash_consult_count'] ?> · adm <?= $tally['cash_admission_count'] - $cashEr ?><?= $cashEr ? ' · ER ' . $cashEr : '' ?></td>
                        <td class="num"><?= number_format($tally['cash_total'], 2) ?></td>
                    </tr>
                    <tr>
                        <td>Online <?= $tally['online_count'] ?></td>
                        <td class="sub">consult <?= $tally['online_consult_count'] ?> · adm <?= $tally['online_admission_count'] - $onlineEr ?><?= $onlineEr ? ' · ER ' . $onlineEr : '' ?></td>
                        <td class="num"><?= number_format($tally['online_total'], 2) ?></td>
                    </tr>
                    <tr>
                        <td>Refunds — cash <?= $tally['cash_refund_count'] ?></td>
                        <td class="sub">issued by me</td>
                        <td class="num neg"><?= $tally['cash_refund_total'] > 0 ? '− ' . number_format($tally['cash_refund_total'], 2) : '0.00' ?></td>
                    </tr>
                    <tr>
                        <td>Expenses — EXP <?= $tally['expense_count'] ?></td>
                        <td class="sub"><?= $tally['expense_count'] ? '<a href="expenses.php" style="color:var(--primary-dark);text-decoration:underline;">vouchers</a>' : '—' ?></td>
                        <td class="num neg"><?= $tally['expense_total'] > 0 ? '− ' . number_format($tally['expense_total'], 2) : '0.00' ?></td>
                    </tr>
                    <tr class="total">
                        <td colspan="2">Net collected</td>
                        <td class="num">Rs <?= number_format($tally['net_collected'], 2) ?></td>
                    </tr>
                </table>

                <p class="blk">Cash-in-hand math</p>
                <table class="ltab">
                    <tr><td colspan="2">Cash received</td><td class="num"><?= number_format($tally['cash_total'], 2) ?></td></tr>
                    <tr><td colspan="2">− Refunds paid out</td><td class="num neg"><?= $tally['cash_refund_total'] > 0 ? '− ' . number_format($tally['cash_refund_total'], 2) : '0.00' ?></td></tr>
                    <tr><td colspan="2">− Counter expenses</td><td class="num neg"><?= $tally['expense_total'] > 0 ? '− ' . number_format($tally['expense_total'], 2) : '0.00' ?></td></tr>
                    <tr class="total"><td colspan="2">Expected in hand</td><td class="num">Rs <?= number_format($tally['expected_cash'], 2) ?></td></tr>
                </table>
                <?php if ($editMode && abs($tally['expected_cash'] - $formExpected) > 0.009): ?>
                <p style="font-size:11px;color:var(--amber-text);margin-top:8px;">Your count is checked against the frozen snapshot: Rs <?= number_format($formExpected, 2) ?>.</p>
                <?php else: ?>
                <p style="font-size:11px;color:var(--text-muted);margin-top:8px;">Drawer float is admin's — not part of your tally. Online (Rs <?= number_format($tally['online_total'], 2) ?>) is verified against the bank app, never counted.</p>
                <?php endif; ?>
            </div>

            <!-- ==== RIGHT: physical count + handover ==== -->
            <div>
                <div class="expect">
                    <span class="k">Expected cash in hand</span>
                    <span class="v">Rs <?= number_format($formExpected, 0) ?></span>
                </div>

                <div class="two-up">
                    <div class="cfield">
                        <label for="counted_cash">Counted cash (Rs)</label>
                        <input id="counted_cash" name="counted_cash" type="number" min="0" step="1"
                               inputmode="numeric" required
                               value="<?= $editMode ? number_format((float) $closing['counted_cash'], 0, '.', '') : '' ?>"
                               placeholder="0"
                               style="font-size:18px;font-weight:700;font-variant-numeric:tabular-nums;">
                    </div>
                    <div class="cfield">
                        <label for="handover_declared">Handed to admin (Rs)</label>
                        <input id="handover_declared" name="handover_declared" type="number" min="0" step="1"
                               value="<?= number_format($suggestedHandover, 0, '.', '') ?>"
                               <?= $editMode ? 'data-touched="1"' : '' ?>
                               style="font-size:18px;font-weight:700;font-variant-numeric:tabular-nums;">
                    </div>
                </div>

                <div class="variance-strip ok" id="varianceStrip">
                    <span id="varianceLabel">Variance vs expected</span>
                    <span class="amt" id="varianceAmt">&nbsp;</span>
                </div>

                <?php if (!$editMode): ?>
                <div class="cfield">
                    <label for="handover_to_id">Handing over to (admin)</label>
                    <select id="handover_to_id" name="handover_to_id" required>
                        <?php foreach ($admins as $a): ?>
                            <option value="<?= (int) $a['id'] ?>"><?= htmlspecialchars($a['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="cfield">
                    <label for="variance_note">Variance note <span style="font-weight:400;color:var(--text-muted)">(required when short/over)</span></label>
                    <textarea id="variance_note" name="variance_note" maxlength="255"
                              placeholder="Explain any difference"><?= $editMode ? htmlspecialchars($closing['variance_note'] ?? '') : '' ?></textarea>
                </div>

                <?php if ($editMode): ?>
                <button class="close-btn amber" type="submit"
                        onclick="return confirm('Save the corrected figures? Admin will be notified immediately and the changes highlighted for approval.');">
                    Save changes &amp; notify admin
                </button>
                <p class="lock-note">Reprints the slip — sign the new copy and replace the filed one.</p>
                <?php else: ?>
                <button class="close-btn" type="submit"
                        onclick="return confirm('Close your shift? Your payments and refunds for today will be locked, and the A5 closing slip will open for printing.');">
                    Submit closing &amp; print A5 slip
                </button>
                <p class="lock-note">Locks today's payments, prints the slip (sign both lines, file it), queues the handover. Editable until admin marks it received.</p>
                <?php endif; ?>
            </div>

        </div>
    </div>
    </form>

    <?php endif; ?>

</div>
</div></div><!-- .main + .app -->

<script>
// Live variance from the single counted-cash total. Server revalidates.
(function () {
    var expected = <?= json_encode(round($formExpected, 2)) ?>;
    var counted = document.getElementById('counted_cash');
    if (!counted) return;

    var fmt = function (n) { return n.toLocaleString('en-PK', {maximumFractionDigits: 0}); };

    function recalc() {
        var total = Math.max(0, parseFloat(counted.value) || 0);

        var variance = total - expected;
        var strip = document.getElementById('varianceStrip');
        var amtEl = document.getElementById('varianceAmt');
        strip.classList.remove('ok', 'short', 'over');
        if (Math.abs(variance) < 0.01) {
            strip.classList.add('ok');
            amtEl.textContent = 'Balanced';
        } else if (variance < 0) {
            strip.classList.add('short');
            amtEl.textContent = '− Rs ' + fmt(Math.abs(variance)) + ' short';
        } else {
            strip.classList.add('over');
            amtEl.textContent = '+ Rs ' + fmt(variance) + ' over';
        }

        // Suggested handover follows the count until the cashier edits it.
        var ho = document.getElementById('handover_declared');
        if (ho && !ho.dataset.touched) {
            ho.value = Math.max(0, total);
        }
    }

    var ho = document.getElementById('handover_declared');
    if (ho) ho.addEventListener('input', function () { ho.dataset.touched = '1'; });

    counted.addEventListener('input', recalc);
    recalc();
})();
</script>
</body>
</html>
