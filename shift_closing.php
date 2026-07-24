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
// The shift's BUSINESS day (cutoff-hour aware): before the cutoff hour we are
// still closing the day that started yesterday, so an overnight shift stays one
// closing and its evening takings don't fall off the tally after midnight.
$today = business_day($pdo);
$cutoffHour = day_cutoff_hour($pdo);
$isOvernight = $today !== date('Y-m-d');   // now past midnight but before cutoff
$uid = (int) $_SESSION['user_id'];

// PKR note faces, largest first. face_value 1 is the "Coins" line — its qty is
// entered as a rupee amount, not a piece count.
$DENOMS = [5000, 1000, 500, 100, 50, 20, 10, 1];

// Rebuild the physical count server-side from posted quantities; the
// client-side totals are display sugar only.
function sc_parse_denoms(array $denomFaces): array {
    $counted = 0.0;
    $rows = [];
    foreach ($denomFaces as $face) {
        $qty = max(0, (int) ($_POST['denom'][$face] ?? 0));
        $amount = $face === 1 ? (float) $qty : (float) ($face * $qty);
        if ($qty > 0) {
            $rows[] = [$face, $qty, $amount];
        }
        $counted += $amount;
    }
    return [round($counted, 2), $rows];
}

// ---------------- Print view (A5 closing slip) ----------------
if (isset($_GET['print']) && isset($_GET['closing_id'])) {
    $closingId = (int) $_GET['closing_id'];

    $stmt = $pdo->prepare('
        SELECT c.*, cu.name AS cashier_name, au.name AS admin_name
        FROM shift_closings c
        JOIN users cu ON cu.id = c.cashier_id
        JOIN users au ON au.id = c.handover_to_id
        WHERE c.id = ?
    ');
    $stmt->execute([$closingId]);
    $closing = $stmt->fetch();

    if (!$closing) {
        http_response_code(404);
        die('Closing slip not found.');
    }

    $denomStmt = $pdo->prepare('SELECT face_value, qty, amount FROM shift_closing_denominations WHERE closing_id = ? ORDER BY face_value DESC');
    $denomStmt->execute([$closingId]);
    $denominations = $denomStmt->fetchAll();

    $pdo->prepare('UPDATE shift_closings SET printed_at = NOW() WHERE id = ?')->execute([$closingId]);

    include __DIR__ . '/views/closing_print_partial.php';
    exit;
}

// ---------------- Submit closing ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close_day') {
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
            [$counted, $denomRows] = sc_parse_denoms($DENOMS);

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

                $denomIns = $pdo->prepare('
                    INSERT INTO shift_closing_denominations (closing_id, face_value, qty, amount)
                    VALUES (?, ?, ?, ?)
                ');
                foreach ($denomRows as [$face, $qty, $amount]) {
                    $denomIns->execute([$closingId, $face, $qty, $amount]);
                }

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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_closing') {
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
            [$counted, $denomRows] = sc_parse_denoms($DENOMS);
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

                // Denomination diff, summarised as one loggable line each way.
                $oldDenoms = [];
                $dStmt = $pdo->prepare('SELECT face_value, qty FROM shift_closing_denominations WHERE closing_id = ? ORDER BY face_value DESC');
                $dStmt->execute([(int) $closing['id']]);
                foreach ($dStmt->fetchAll() as $d) {
                    $oldDenoms[] = ((int) $d['face_value'] === 1 ? 'Coins Rs' : $d['face_value'] . '×') . $d['qty'];
                }
                $newDenoms = [];
                foreach ($denomRows as [$face, $qty, $amount]) {
                    $newDenoms[] = ($face === 1 ? 'Coins Rs' : $face . '×') . $qty;
                }
                $oldDenomStr = implode(', ', $oldDenoms);
                $newDenomStr = implode(', ', $newDenoms);
                if ($oldDenomStr !== $newDenomStr) {
                    $changes['denominations'] = [$oldDenomStr, $newDenomStr];
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

                    // Replace the denomination rows with the new count.
                    $pdo->prepare('DELETE FROM shift_closing_denominations WHERE closing_id = ?')->execute([(int) $closing['id']]);
                    $denomIns = $pdo->prepare('
                        INSERT INTO shift_closing_denominations (closing_id, face_value, qty, amount)
                        VALUES (?, ?, ?, ?)
                    ');
                    foreach ($denomRows as [$face, $qty, $amount]) {
                        $denomIns->execute([(int) $closing['id'], $face, $qty, $amount]);
                    }

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

$savedDenoms = [];
if ($editMode) {
    $dStmt = $pdo->prepare('SELECT face_value, qty FROM shift_closing_denominations WHERE closing_id = ?');
    $dStmt->execute([(int) $closing['id']]);
    foreach ($dStmt->fetchAll() as $d) {
        $savedDenoms[(int) $d['face_value']] = (int) $d['qty'];
    }
}

// The form serves both modes; in edit mode expected cash comes from the
// snapshot (the shift's money is locked, so the system side cannot change).
$formExpected = $editMode ? (float) $closing['expected_cash'] : $tally['expected_cash'];
$suggestedHandover = $editMode ? (float) $closing['handover_declared'] : max(0, $tally['expected_cash']);
$showForm = !$closing || $editMode;

$meStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
$meStmt->execute([$uid]);
$myName = $meStmt->fetchColumn() ?: 'Me';

$pageTitle = 'Day Closing';
require __DIR__ . '/partials/head.php';
$navActive = 'shift_closing';
require __DIR__ . '/partials/sidebar.php';
?>
<style>
.close-grid { display: grid; grid-template-columns: 1.1fr .9fr; gap: 22px; align-items: start; }
@media (max-width: 980px) { .close-grid { grid-template-columns: 1fr; } }
.close-col { display: flex; flex-direction: column; gap: 22px; }

.tiles { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
@media (max-width: 860px) { .tiles { grid-template-columns: repeat(2, 1fr); } }
.tile { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-card); padding: 16px 18px; box-shadow: var(--shadow-sm); }
.tile .lbl { font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--text-muted); }
.tile .val { font-size: 22px; font-weight: 700; letter-spacing: -.02em; margin-top: 4px; font-variant-numeric: tabular-nums; }
.tile .hint { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.tile.hero { background: var(--primary-dark); border-color: var(--primary-dark); }
.tile.hero .lbl { color: #9BD4CF; } .tile.hero .val { color: #fff; } .tile.hero .hint { color: #7FB8B3; }

.ttable { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
.ttable th { text-align: left; font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--text-muted); padding: 7px 10px; border-bottom: 1px solid var(--border); }
.ttable td { padding: 8px 10px; border-bottom: 1px solid var(--border); font-size: 13.5px; }
.ttable tr:last-child td { border-bottom: none; }
.ttable td.num, .ttable th.num { text-align: right; white-space: nowrap; }
.ttable td.neg { color: var(--red); }
.ttable tr.total td { font-weight: 700; border-top: 2px solid var(--border-strong); background: var(--bg); }
.ttable tr.grand td { font-weight: 700; background: var(--primary-light); }
.ttable tr.section td { font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--text-muted); background: var(--bg); padding: 5px 10px; }
.count-chip { display: inline-block; min-width: 24px; text-align: center; background: var(--bg); border: 1px solid var(--border); border-radius: 999px; font-size: 12px; font-weight: 600; padding: 0 8px; color: var(--text-secondary); }

.denom-row { display: grid; grid-template-columns: 88px 1fr 110px; gap: 12px; align-items: center; padding: 6px 0; border-bottom: 1px dashed var(--border); }
.denom-row:last-of-type { border-bottom: none; }
.note-face { font-weight: 700; font-size: 13.5px; font-variant-numeric: tabular-nums; background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 4px 0; text-align: center; color: var(--text-secondary); }
.denom-qty { width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: var(--radius-input); font: inherit; font-size: 13.5px; background: var(--bg); color: var(--text); text-align: center; }
.denom-qty:focus { outline: 2px solid var(--primary); outline-offset: 1px; border-color: var(--primary); }
.denom-amt { text-align: right; font-weight: 600; font-variant-numeric: tabular-nums; font-size: 13.5px; }
.denom-total { display: flex; justify-content: space-between; align-items: baseline; margin-top: 14px; padding-top: 14px; border-top: 2px solid var(--border-strong); }
.denom-total .v { font-size: 20px; font-weight: 700; font-variant-numeric: tabular-nums; }

.variance-strip { display: flex; justify-content: space-between; align-items: center; border-radius: var(--radius-input); padding: 11px 15px; margin-top: 14px; font-weight: 600; font-size: 13.5px; }
.variance-strip.ok { background: var(--green-bg); color: var(--green-text); }
.variance-strip.short { background: var(--red-bg); color: var(--red-text); }
.variance-strip.over { background: var(--amber-bg); color: var(--amber-text); }
.variance-strip .amt { font-size: 16px; font-weight: 700; font-variant-numeric: tabular-nums; }

.cfield { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
.cfield label { font-size: 12px; font-weight: 600; color: var(--text-secondary); }
.cfield input, .cfield select, .cfield textarea { padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-input); font: inherit; font-size: 13.5px; background: var(--bg); color: var(--text); }
.cfield textarea { resize: vertical; min-height: 56px; }
.cfield input:focus, .cfield select:focus, .cfield textarea:focus { outline: 2px solid var(--primary); outline-offset: 1px; border-color: var(--primary); }

.close-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: none; border-radius: var(--radius-btn); font: inherit; font-weight: 600; font-size: 14px; padding: 12px 22px; cursor: pointer; background: var(--primary-dark); color: #fff; width: 100%; }
.close-btn:hover { background: var(--primary); }
.close-btn.amber { background: var(--amber); color: #3B2E08; }
.lock-note { font-size: 12px; color: var(--text-muted); text-align: center; margin-top: 10px; }
.alert-error { background: var(--red-bg); color: var(--red-text); border-radius: var(--radius-input); padding: 12px 16px; font-size: 13px; font-weight: 500; }
.closed-box { background: var(--green-bg); color: var(--green-text); border-radius: var(--radius-card); padding: 20px 22px; }
.closed-box.amberish { background: var(--amber-bg); color: var(--amber-text); }
.closed-box b { font-size: 15px; }
.closed-box .row { margin-top: 6px; font-size: 13.5px; }
.closed-actions { margin-top: 14px; display: flex; gap: 10px; flex-wrap: wrap; }
.closed-actions a { display: inline-flex; align-items: center; padding: 9px 16px; border-radius: var(--radius-btn); font-weight: 600; font-size: 13px; background: var(--card); color: var(--text); border: 1px solid var(--border); }
.edit-banner { background: var(--amber-bg); color: var(--amber-text); border-radius: var(--radius-input); padding: 12px 16px; font-size: 13px; font-weight: 600; }
</style>

<div class="content">

    <div class="page-head">
        <h1>My Shift Closing — <?= date('D d/m/Y', strtotime($today)) ?></h1>
        <p><?= htmlspecialchars($myName) ?>'s own takings only: payments you recorded, refunds you issued, expenses you posted. Colleagues close their shifts separately.</p>
    </div>

    <?php if ($isOvernight): ?>
        <div class="edit-banner" style="margin-bottom:18px;">
            It's past midnight — you're still closing the <b><?= date('D d/m/Y', strtotime($today)) ?></b> business day
            (the shift that opened yesterday). All cash you took this evening AND after midnight, up to <?= sprintf('%02d:00', $cutoffHour) ?>,
            counts on this one closing. The new day begins at <?= sprintf('%02d:00', $cutoffHour) ?>.
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($closing && !$editMode): ?>

        <div class="closed-box<?= $closing['status'] === 'EDITED' ? ' amberish' : '' ?>">
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
                    <a href="shift_closing.php?edit=1" style="border-color:var(--amber);color:var(--amber-text);background:var(--amber-bg);">Edit my closing</a>
                <?php endif; ?>
            </div>
        </div>

    <?php endif; ?>

    <?php if ($showForm): ?>

    <?php if ($editMode): ?>
        <div class="edit-banner">
            Editing closing <?= htmlspecialchars($closing['closing_number']) ?> — your count and handover figures reopen below.
            Every change is logged old&rarr;new, highlighted for admin, and admin is emailed immediately.
            The shift's payments stay locked; only the physical-count side can change.
        </div>
    <?php endif; ?>

    <div class="tiles">
        <div class="tile">
            <div class="lbl">My collections</div>
            <div class="val">Rs <?= number_format($tally['net_collected'], 0) ?></div>
            <div class="hint"><?= $tally['cash_count'] + $tally['online_count'] ?> payments recorded by me</div>
        </div>
        <div class="tile">
            <div class="lbl">My cash</div>
            <div class="val">Rs <?= number_format($tally['cash_total'] - $tally['cash_refund_total'], 0) ?></div>
            <div class="hint"><?= $tally['cash_count'] ?> payments<?= $tally['cash_refund_count'] ? ' − ' . $tally['cash_refund_count'] . ' refund' . ($tally['cash_refund_count'] > 1 ? 's' : '') : '' ?></div>
        </div>
        <div class="tile">
            <div class="lbl">My online</div>
            <div class="val">Rs <?= number_format($tally['online_total'], 0) ?></div>
            <div class="hint"><?= $tally['online_count'] ?> payments · bank a/c</div>
        </div>
        <div class="tile hero">
            <div class="lbl">Expected cash in hand</div>
            <div class="val">Rs <?= number_format($formExpected, 0) ?></div>
            <div class="hint">Cash <?= number_format($tally['cash_total'], 0) ?> − refunds <?= number_format($tally['cash_refund_total'], 0) ?> − expenses <?= number_format($tally['expense_total'], 0) ?></div>
        </div>
    </div>

    <form method="POST" action="shift_closing.php" id="closeForm">
    <input type="hidden" name="action" value="<?= $editMode ? 'edit_closing' : 'close_day' ?>">

    <div class="close-grid">

        <!-- ==== LEFT: system-side ledger (this user only) ==== -->
        <div class="close-col">

            <div class="card">
                <h2 style="font-size:15px;font-weight:700;margin-bottom:4px;">My collections by method</h2>
                <p style="font-size:12.5px;color:var(--text-muted);margin-bottom:14px;">Consultation invoices and admission bills where <b>you</b> recorded the payment. System figures — nothing to enter here.</p>
                <div style="overflow-x:auto;">
                <table class="ttable">
                    <tr class="section"><td colspan="3">Money in</td></tr>
                    <tr>
                        <td>Cash <span class="count-chip"><?= $tally['cash_count'] ?></span></td>
                        <td class="num" style="color:var(--text-muted)">consult <?= $tally['cash_consult_count'] ?> · admission <?= $tally['cash_admission_count'] ?></td>
                        <td class="num"><?= number_format($tally['cash_total'], 2) ?></td>
                    </tr>
                    <tr>
                        <td>Online <span class="count-chip"><?= $tally['online_count'] ?></span></td>
                        <td class="num" style="color:var(--text-muted)">consult <?= $tally['online_consult_count'] ?> · admission <?= $tally['online_admission_count'] ?></td>
                        <td class="num"><?= number_format($tally['online_total'], 2) ?></td>
                    </tr>
                    <tr class="section"><td colspan="3">Money out</td></tr>
                    <tr>
                        <td>Refunds — cash <span class="count-chip"><?= $tally['cash_refund_count'] ?></span></td>
                        <td class="num" style="color:var(--text-muted)"><?= $tally['cash_refund_count'] ? 'issued by me' : 'none today' ?></td>
                        <td class="num neg"><?= $tally['cash_refund_total'] > 0 ? '− ' . number_format($tally['cash_refund_total'], 2) : '0.00' ?></td>
                    </tr>
                    <tr>
                        <td>Expenses — counter <span class="count-chip"><?= $tally['expense_count'] ?></span></td>
                        <td class="num" style="color:var(--text-muted)"><?= $tally['expense_count'] ? '<a href="expenses.php" style="color:var(--primary-dark);text-decoration:underline;">my EXP vouchers</a>' : 'none today' ?></td>
                        <td class="num neg"><?= $tally['expense_total'] > 0 ? '− ' . number_format($tally['expense_total'], 2) : '0.00' ?></td>
                    </tr>
                    <tr class="total">
                        <td colspan="2">My net collected today</td>
                        <td class="num">Rs <?= number_format($tally['net_collected'], 2) ?></td>
                    </tr>
                </table>
                </div>
            </div>

            <div class="card">
                <h2 style="font-size:15px;font-weight:700;margin-bottom:4px;">Cash-in-hand math</h2>
                <p style="font-size:12.5px;color:var(--text-muted);margin-bottom:14px;">What you should physically be holding right now. (The drawer float is admin's, not part of your tally.)</p>
                <div style="overflow-x:auto;">
                <table class="ttable">
                    <tr><td>My cash payments received</td><td class="num"><?= number_format($tally['cash_total'], 2) ?></td></tr>
                    <tr><td>− My cash refunds paid out</td><td class="num neg"><?= $tally['cash_refund_total'] > 0 ? '− ' . number_format($tally['cash_refund_total'], 2) : '0.00' ?></td></tr>
                    <tr><td>− My counter expenses (EXP)</td><td class="num neg"><?= $tally['expense_total'] > 0 ? '− ' . number_format($tally['expense_total'], 2) : '0.00' ?></td></tr>
                    <tr class="grand"><td>Expected cash in hand</td><td class="num">Rs <?= number_format($tally['expected_cash'], 2) ?></td></tr>
                </table>
                </div>
                <?php if ($editMode && abs($tally['expected_cash'] - $formExpected) > 0.009): ?>
                <p style="font-size:12px;color:var(--amber-text);margin-top:10px;">Note: the closing snapshot (Rs <?= number_format($formExpected, 2) ?>) is the figure your count is checked against — it was frozen when you closed.</p>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2 style="font-size:15px;font-weight:700;margin-bottom:4px;">Online — verify, don't count</h2>
                <p style="font-size:12.5px;color:var(--text-muted);margin-bottom:0;">
                    Match your <b><?= $tally['online_count'] ?></b> online payment<?= $tally['online_count'] === 1 ? '' : 's' ?>
                    totalling <b>Rs <?= number_format($tally['online_total'], 2) ?></b> against the bank app before closing.
                    Online money never passes through your hands.
                </p>
            </div>

        </div>

        <!-- ==== RIGHT: physical count + handover ==== -->
        <div class="close-col">

            <div class="card">
                <h2 style="font-size:15px;font-weight:700;margin-bottom:4px;">Physical cash count</h2>
                <p style="font-size:12.5px;color:var(--text-muted);margin-bottom:14px;">Count your cash by denomination — totals compute themselves. "Coins" takes a rupee amount.</p>
                <div>
                    <?php foreach ($DENOMS as $face): ?>
                    <div class="denom-row">
                        <span class="note-face"><?= $face === 1 ? 'Coins' : number_format($face) ?></span>
                        <input class="denom-qty" type="number" min="0" step="1" name="denom[<?= $face ?>]"
                               value="<?= (int) ($savedDenoms[$face] ?? 0) ?>"
                               data-face="<?= $face ?>" inputmode="numeric"
                               aria-label="<?= $face === 1 ? 'Coins total in rupees' : 'Number of ' . $face . ' notes' ?>">
                        <span class="denom-amt" data-amt="<?= $face ?>">0</span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="denom-total">
                    <span style="font-weight:700;">Counted cash</span>
                    <span class="v">Rs <span id="countedTotal">0</span></span>
                </div>
                <div class="variance-strip ok" id="varianceStrip">
                    <span id="varianceLabel">Variance vs expected (<?= number_format($formExpected, 0) ?>)</span>
                    <span class="amt" id="varianceAmt">&nbsp;</span>
                </div>
            </div>

            <div class="card">
                <h2 style="font-size:15px;font-weight:700;margin-bottom:4px;"><?= $editMode ? 'Corrected handover' : 'Handover & close' ?></h2>
                <p style="font-size:12.5px;color:var(--text-muted);margin-bottom:14px;">All your counted cash goes to admin. Write the amount you are physically handing over.</p>

                <div class="cfield">
                    <label for="handover_declared">Cash handed to admin (Rs)</label>
                    <input id="handover_declared" name="handover_declared" type="number" min="0" step="1"
                           value="<?= number_format($suggestedHandover, 0, '.', '') ?>"
                           <?= $editMode ? 'data-touched="1"' : '' ?>
                           style="font-size:17px;font-weight:700;font-variant-numeric:tabular-nums;">
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
                              placeholder="Explain any difference between counted and expected cash"><?= $editMode ? htmlspecialchars($closing['variance_note'] ?? '') : '' ?></textarea>
                </div>

                <?php if ($editMode): ?>
                <button class="close-btn amber" type="submit"
                        onclick="return confirm('Save the corrected figures? Admin will be notified immediately and the changes highlighted for approval.');">
                    Save changes &amp; notify admin
                </button>
                <p class="lock-note">Reprints the slip with the corrected figures — sign the new copy and replace the filed one.</p>
                <?php else: ?>
                <button class="close-btn" type="submit"
                        onclick="return confirm('Close your shift? Your payments and refunds for today will be locked, and the A5 closing slip will open for printing.');">
                    Submit closing &amp; open A5 slip
                </button>
                <p class="lock-note">Locks your payments for today, prints the closing slip (sign both lines, file the paper), and queues the handover in the admin portal. You can still edit the count until admin marks it received.</p>
                <?php endif; ?>
            </div>

        </div>
    </div>
    </form>

    <?php endif; ?>

</div>
</div></div><!-- .main + .app -->

<script>
// Live denomination math. Server recomputes everything from the raw
// quantities — this is display convenience only.
(function () {
    var expected = <?= json_encode(round($formExpected, 2)) ?>;
    var inputs = document.querySelectorAll('.denom-qty');
    if (!inputs.length) return;

    var fmt = function (n) { return n.toLocaleString('en-PK', {maximumFractionDigits: 0}); };

    function recalc() {
        var total = 0;
        inputs.forEach(function (inp) {
            var face = parseInt(inp.dataset.face, 10);
            var qty = Math.max(0, parseInt(inp.value, 10) || 0);
            var amt = face === 1 ? qty : face * qty;
            total += amt;
            var cell = document.querySelector('[data-amt="' + face + '"]');
            if (cell) cell.textContent = fmt(amt);
        });

        document.getElementById('countedTotal').textContent = fmt(total);

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

    inputs.forEach(function (inp) { inp.addEventListener('input', recalc); });
    recalc();
})();
</script>
</body>
</html>
