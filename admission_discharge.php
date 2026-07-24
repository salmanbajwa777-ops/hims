<?php
/**
 * Admission discharge & billing.
 *
 * Raises the SEPARATE admission bill (its own "A" invoice series), seeded from
 * the stay hours + logged services. Receptionist reviews/edits the lines, takes
 * payment (full or partial), and on a shortfall routes to the admin/manager
 * write-off approval. The consultation bill is untouched.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/billing.php';
require_once __DIR__ . '/config/notify.php';
require_once __DIR__ . '/config/sheets.php';
refresh_session_permissions($pdo);

$baseRole = $_SESSION['base_role'] ?? '';
$uid = (int) $_SESSION['user_id'];
$admissionId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT a.*, v.token_no, p.id AS patient_id, p.mrn, p.name AS patient_name, p.phone, p.dob,
           COALESCE(du.name, a.admitting_doctor_manual) AS doctor_name
    FROM admissions a
    JOIN visits v ON v.id = a.visit_id
    JOIN patients p ON p.id = v.patient_id
    LEFT JOIN users du ON du.id = a.admitting_doctor_id
    WHERE a.id = ?
");
$stmt->execute([$admissionId]);
$adm = $stmt->fetch();
if (!$adm) { http_response_code(404); exit('Admission not found.'); }

// Admission settlement is its own capability now (ADMISSION_FINALIZE_BILL), split
// from the OPD till (RECEPTION_PROCESS_PAYMENTS) so a ward biller can settle
// discharges without touching front-desk payments. ADMIN/MANAGER hold both keys
// via role_permissions (see sql/rbac_overhaul_2_grants.sql), so no role hardcode.
$canFinalize = has_permission('ADMISSION_FINALIZE_BILL');
$canApproveWriteoff = has_permission('ADMISSION_APPROVE_WRITEOFF');
if (!$canFinalize && !$canApproveWriteoff) { http_response_code(403); exit('Forbidden.'); }

$err = '';

// Find or create the admission bill (draft), seeded once from stay + services.
function ensure_admission_bill(PDO $pdo, array $adm, int $uid): array {
    $b = $pdo->prepare('SELECT * FROM admission_bills WHERE admission_id = ?');
    $b->execute([$adm['id']]);
    $bill = $b->fetch();
    if ($bill) { return $bill; }

    // Patient's standing discount category: applies to SERVICE/PROCEDURE lines
    // only (each at its own rate) — the room stay is NEVER discounted. Rates are
    // snapshotted per line so later category edits don't rewrite this bill, and
    // month-end reporting can split service vs procedure discounts.
    $cat = patient_discount_category($pdo, (int) $adm['patient_id']);

    $pdo->beginTransaction();
    try {
        $inv = generate_admission_invoice_number($pdo);
        $pdo->prepare('INSERT INTO admission_bills (invoice_number, admission_id, created_by_id, discount_category_id) VALUES (?, ?, ?, ?)')
            ->execute([$inv, $adm['id'], $uid, $cat ? (int) $cat['id'] : null]);
        $billId = (int) $pdo->lastInsertId();

        // Stay line.
        $rate = $pdo->prepare('SELECT rate_amount, rate_basis FROM admission_rates WHERE admission_type = ?');
        $rate->execute([$adm['admission_type']]);
        $rate = $rate->fetch() ?: ['rate_amount' => 0, 'rate_basis' => 'HOURLY'];
        $endTs = $adm['discharged_at'] ? strtotime($adm['discharged_at']) : time();
        $mins = max(0, (int) round(($endTs - strtotime($adm['admitted_at'])) / 60));
        if ($rate['rate_basis'] === 'DAILY') {
            $units = max(1, (int) ceil($mins / 1440));
            $desc = 'Stay — ' . $adm['admission_type'] . ' (' . $units . ' day' . ($units > 1 ? 's' : '') . ')';
        } else {
            $units = admission_billed_hours($mins);
            $desc = 'Stay — ' . $adm['admission_type'] . ' (' . $units . ' hr)';
        }
        $stayAmt = round((float) $rate['rate_amount'] * $units, 2);
        $totalDiscount = 0.0;
        // The room stay now carries its own category rate (single rate across all
        // admission types). Stored net, with the discount snapshotted on the line
        // so edit/remove resync and month-end reporting stay honest.
        $stayPct = $cat ? (float) $cat['room_stay_pct'] : 0.0;
        $stayDiscount = round($stayAmt * $stayPct / 100, 2);
        $stayNet = round($stayAmt - $stayDiscount, 2);
        $totalDiscount += $stayDiscount;
        $pdo->prepare('INSERT INTO admission_bill_items (admission_bill_id, description, quantity, unit_rate, amount, item_kind, discount_pct, discount_amount) VALUES (?, ?, ?, ?, ?, \'STAY\', ?, ?)')
            ->execute([$billId, $desc, $units, $rate['rate_amount'], $stayNet, $stayPct, $stayDiscount]);

        // Service lines (billable only), net of the category discount if any.
        $svc = $pdo->prepare('SELECT * FROM admission_services WHERE admission_id = ? AND is_billable = 1 ORDER BY logged_at');
        $svc->execute([$adm['id']]);
        foreach ($svc->fetchAll() as $s) {
            $qtyLabel = $s['charge_type'] === 'HOURLY' ? ((int) $s['duration_minutes']) . ' min' : (int) $s['quantity'];
            // Procedures discount at their own rate, plain services at theirs.
            $pct = 0.0;
            if ($cat) {
                $pct = $s['service_type'] === 'PROCEDURE'
                    ? (float) $cat['procedures_pct'] : (float) $cat['er_services_pct'];
            }
            $gross = (float) $s['calculated_charge'];
            $lineDiscount = round($gross * $pct / 100, 2);
            $net = round($gross - $lineDiscount, 2);
            $totalDiscount += $lineDiscount;
            $pdo->prepare('INSERT INTO admission_bill_items (admission_bill_id, description, quantity, unit_rate, amount, item_kind, discount_pct, discount_amount, service_type) VALUES (?, ?, ?, ?, ?, \'SERVICE\', ?, ?, ?)')
                ->execute([$billId, $s['service_name'] . ' (' . $qtyLabel . ')', max(1, (int) $s['quantity']), $s['unit_charge'], $net, $pct, $lineDiscount, $s['service_type']]);
        }
        if ($totalDiscount > 0) {
            $pdo->prepare('UPDATE admission_bills SET discount_amount = ? WHERE id = ?')
                ->execute([round($totalDiscount, 2), $billId]);
        }

        recalc_admission_bill_totals($pdo, $billId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $b->execute([$adm['id']]);
    return $b->fetch();
}

$bill = ensure_admission_bill($pdo, $adm, $uid);
$locked = $bill['status'] === 'paid' || $bill['printed_at'];

// Keep the bill's discount rollup honest after any line edit/removal: it's
// simply the sum of the surviving lines' snapshots.
function resync_admission_bill_discount(PDO $pdo, int $billId): void {
    $pdo->prepare('
        UPDATE admission_bills SET discount_amount =
            (SELECT COALESCE(SUM(discount_amount), 0) FROM admission_bill_items WHERE admission_bill_id = ?)
        WHERE id = ?
    ')->execute([$billId, $billId]);
}

// ---- Edit a line item (before finalize) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_item' && !$locked && $canFinalize) {
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $desc = trim($_POST['description'] ?? '');
    $qty = (float) ($_POST['quantity'] ?? 1);
    $rate = (float) ($_POST['unit_rate'] ?? 0);
    $amount = round($qty * $rate, 2);
    if ($itemId > 0 && $desc !== '') {
        // A manual edit replaces the auto-priced line entirely — clear its
        // category-discount snapshot so reporting doesn't count a discount
        // that is no longer embedded in the amount.
        $pdo->prepare('UPDATE admission_bill_items SET description = ?, quantity = ?, unit_rate = ?, amount = ?, discount_pct = 0, discount_amount = 0 WHERE id = ? AND admission_bill_id = ?')
            ->execute([$desc, $qty, $rate, $amount, $itemId, $bill['id']]);
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$uid, 'admission_bill_item_edited', "Edited item #$itemId on admission bill {$bill['invoice_number']}"]);
        resync_admission_bill_discount($pdo, (int) $bill['id']);
        recalc_admission_bill_totals($pdo, (int) $bill['id']);
    }
    header('Location: admission_discharge.php?id=' . $admissionId); exit;
}

// ---- Remove a line item ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_item' && !$locked && $canFinalize) {
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $pdo->prepare('DELETE FROM admission_bill_items WHERE id = ? AND admission_bill_id = ?')->execute([$itemId, $bill['id']]);
    resync_admission_bill_discount($pdo, (int) $bill['id']);
    recalc_admission_bill_totals($pdo, (int) $bill['id']);
    header('Location: admission_discharge.php?id=' . $admissionId); exit;
}

// ---- Apply / update the manual discharge discount ----
// A lump-sum rupee amount OR a percentage of the current total. The % is a
// convenience: it's converted to a rupee amount here (off the current grand
// total) and stored as the authoritative amount, so later line edits don't
// silently re-scale it. This manual discount REPLACES the category discount for
// the grand total (see recalc_admission_bill_totals).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_discount' && !$locked && $canFinalize) {
    $mode = $_POST['discount_mode'] ?? 'amount';
    // Gross = current lines (net of category) + the category discount we're
    // about to replace. The discount can never exceed the gross.
    $subtotalNow = (float) $bill['subtotal'];
    $categoryDiscount = (float) ($bill['discount_amount'] ?? 0);
    $gross = round($subtotalNow + $categoryDiscount, 2);
    // % base is the "current total" the biller sees = grand_total on screen.
    $currentTotal = (float) $bill['grand_total'];

    $pct = 0.0;
    if ($mode === 'percent') {
        $pct = max(0, min(100, (float) ($_POST['discount_percent'] ?? 0)));
        $amount = round($currentTotal * $pct / 100, 2);
    } else {
        $amount = max(0, (float) ($_POST['discount_amount'] ?? 0));
    }
    // Clamp: never discount below zero.
    $amount = min($amount, $gross);

    $pdo->prepare('UPDATE admission_bills SET manual_discount_amount = ?, manual_discount_pct = ?, manual_discount_by_id = ? WHERE id = ?')
        ->execute([$amount, $pct, $uid, $bill['id']]);
    recalc_admission_bill_totals($pdo, (int) $bill['id']);
    $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
        ->execute([$uid, 'admission_bill_discount', "Manual discount Rs " . number_format($amount, 2) . ($pct > 0 ? " ({$pct}%)" : '') . " on {$bill['invoice_number']}"]);
    header('Location: admission_discharge.php?id=' . $admissionId); exit;
}

// ---- Clear the manual discharge discount ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_discount' && !$locked && $canFinalize) {
    $pdo->prepare('UPDATE admission_bills SET manual_discount_amount = 0, manual_discount_pct = 0, manual_discount_by_id = NULL WHERE id = ?')
        ->execute([$bill['id']]);
    recalc_admission_bill_totals($pdo, (int) $bill['id']);
    header('Location: admission_discharge.php?id=' . $admissionId); exit;
}

// ---- Finalize + payment ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'finalize' && !$locked && $canFinalize) {
    $method = $_POST['payment_method'] ?? 'cash';
    $paid = (float) ($_POST['paid_amount'] ?? 0);
    $total = (float) $bill['grand_total'];
    $paid = max(0, min($paid, $total));
    $short = round($total - $paid, 2);

    // A closed day accepts no more payments — the cash tally has been signed.
    $dayLock = require_day_open($pdo);
    if ($dayLock) {
        $err = $dayLock;
    } elseif ($short > 0.001) {
        // Partial → needs write-off approval before the stay closes. Record the
        // paid amount + finalize, but leave the admission DISCHARGE_IN_PROGRESS
        // and the bill 'finalized' (not yet 'paid') until approved.
        $pdo->prepare('UPDATE admission_bills SET status = \'finalized\', payment_method = ?, paid_amount = ?, finalized_by_id = ? WHERE id = ?')
            ->execute([$method, $paid, $uid, $bill['id']]);
        header('Location: admission_discharge.php?id=' . $admissionId . '&pending_writeoff=1'); exit;
    }

    // Full payment → close it.
    $pdo->beginTransaction();
    try {
        // paid_by_id fallback pattern: see checkout.php record_payment.
        try {
            $pdo->prepare('UPDATE admission_bills SET status = \'paid\', payment_method = ?, paid_amount = ?, paid_at = NOW(), finalized_by_id = ?, paid_by_id = ? WHERE id = ?')
                ->execute([$method, $paid, $uid, $uid, $bill['id']]);
        } catch (PDOException $e) {
            $pdo->prepare('UPDATE admission_bills SET status = \'paid\', payment_method = ?, paid_amount = ?, paid_at = NOW(), finalized_by_id = ? WHERE id = ?')
                ->execute([$method, $paid, $uid, $bill['id']]);
        }
        $pdo->prepare('UPDATE admissions SET status = \'DISCHARGED\', discharge_finalized_by_id = ?, discharge_finalized_at = NOW() WHERE id = ?')
            ->execute([$uid, $admissionId]);
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$uid, 'admission_bill_paid', "Admission bill {$bill['invoice_number']} paid Rs " . number_format($paid, 2) . " ($method)"]);
        $pdo->commit();

        // Alert admin: discharge complete, bill paid in full (best-effort, after commit).
        notify_patient_discharged($pdo, $admissionId);
        // Log the discharge bill to the yearly Google Sheet (best-effort, after commit).
        sheet_push($pdo, 'DISCHARGE', (int) $bill['id'], $uid);

        header('Location: admission_discharge.php?id=' . $admissionId . '&paid=1'); exit;
    } catch (Throwable $e) {
        $pdo->rollBack();
        $err = 'Could not record payment.';
    }
}

// ---- Approve the write-off (admin/manager) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve_writeoff' && $canApproveWriteoff) {
    $reason = trim($_POST['reason'] ?? '');
    $total = (float) $bill['grand_total'];
    $paid = (float) ($bill['paid_amount'] ?? 0);
    $short = round($total - $paid, 2);
    // The approval flips the bill to 'paid' with today's paid_at, landing the
    // partial cash on the COLLECTOR's shift today — so if that receptionist
    // has already closed, block until tomorrow.
    $dayLock = require_day_open($pdo, null, (int) ($bill['finalized_by_id'] ?? 0) ?: null);
    if ($dayLock) {
        $err = $dayLock;
    } elseif ($short <= 0) {
        $err = 'Nothing to write off.';
    } else {
        $pdo->beginTransaction();
        try {
            // Collector = whoever took the partial payment at discharge, not the
            // approving admin — their shift owns the cash. Fallback pattern: see
            // checkout.php record_payment.
            try {
                $pdo->prepare('UPDATE admission_bills SET status = \'paid\', write_off_amount = ?, paid_at = NOW(), paid_by_id = COALESCE(finalized_by_id, ?) WHERE id = ?')
                    ->execute([$short, $uid, $bill['id']]);
            } catch (PDOException $e) {
                $pdo->prepare('UPDATE admission_bills SET status = \'paid\', write_off_amount = ?, paid_at = NOW() WHERE id = ?')
                    ->execute([$short, $bill['id']]);
            }
            $pdo->prepare('INSERT INTO admission_writeoffs (admission_id, admission_bill_id, patient_id, amount_written_off, approved_by_id, approved_by_role, reason, shift_tally_date) VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE())')
                ->execute([$admissionId, $bill['id'], (int) $adm['patient_id'], $short, $uid, ($baseRole === 'MANAGER' ? 'MANAGER' : 'ADMIN'), $reason]);
            // Patient rollup for the alert badge.
            $pdo->prepare('UPDATE patients SET unpaid_flag = 1, unpaid_total = unpaid_total + ?, unpaid_count = unpaid_count + 1 WHERE id = ?')
                ->execute([$short, (int) $adm['patient_id']]);
            $pdo->prepare('UPDATE admissions SET status = \'DISCHARGED\', discharge_finalized_by_id = ?, discharge_finalized_at = NOW() WHERE id = ?')
                ->execute([$uid, $admissionId]);
            $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
                ->execute([$uid, 'admission_writeoff_approved', "Wrote off Rs " . number_format($short, 2) . " on {$bill['invoice_number']}: $reason"]);
            $pdo->commit();

            // Alert admin: discharge with a write-off (best-effort, after commit).
            notify_patient_discharged($pdo, $admissionId, $short);
            // Log the discharge bill to the yearly Google Sheet — the Payment Method
            // cell carries the written-off shortfall (best-effort, after commit).
            sheet_push($pdo, 'DISCHARGE', (int) $bill['id'], $uid);

            header('Location: admission_discharge.php?id=' . $admissionId . '&wroteoff=1'); exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $err = 'Could not approve write-off.';
        }
    }
}

// Reload bill + items for the view.
$b = $pdo->prepare('SELECT * FROM admission_bills WHERE admission_id = ?');
$b->execute([$admissionId]);
$bill = $b->fetch();
$items = $pdo->prepare('SELECT * FROM admission_bill_items WHERE admission_bill_id = ? ORDER BY item_kind DESC, id');
$items->execute([$bill['id']]);
$items = $items->fetchAll();
$locked = $bill['status'] === 'paid' || $bill['printed_at'];
$total = (float) $bill['grand_total'];
$paid = (float) ($bill['paid_amount'] ?? 0);
$short = round($total - $paid, 2);
$pendingWriteoff = $bill['status'] === 'finalized' && $short > 0.001;

// Admission → discharge timing for the header strip.
$dischargeTs = $adm['discharged_at'] ? strtotime($adm['discharged_at']) : time();
$stayMins = max(0, (int) round(($dischargeTs - strtotime($adm['admitted_at'])) / 60));
$stayLabel = (intdiv($stayMins, 60) ? intdiv($stayMins, 60) . 'h ' : '') . ($stayMins % 60) . 'm';

$pageTitle = 'Discharge — ' . $adm['patient_name'];
$headExtra = <<<CSS
<style>
.bill-head { display:flex; justify-content:space-between; flex-wrap:wrap; gap:12px; align-items:flex-start; }
.bill-meta { font-size:12.5px; color:var(--text-muted); }
.iedit { display:grid; grid-template-columns: 1fr 70px 90px auto auto; gap:8px; align-items:center; }
.iedit input { padding:7px 9px; border:1px solid var(--border); border-radius:8px; font:inherit; font-size:12.5px; background:#fff; width:100%; }
.iedit input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(26,127,126,.15); }
.link-btn { background:none; border:none; color:var(--red-text); font:inherit; font-size:12px; font-weight:600; cursor:pointer; }
.pay-grid { display:grid; grid-template-columns: 1fr 1fr; gap:14px; }
.pay-grid label { font-size:12px; font-weight:600; color:var(--text-secondary); display:block; margin-bottom:5px; }
.pay-grid select, .pay-grid input { width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:var(--radius-input); font:inherit; font-size:14px; background:var(--bg); }
.pay-grid select:focus, .pay-grid input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(26,127,126,.15); background:#fff; }
.totbox { display:flex; flex-direction:column; gap:8px; margin:14px 0; }
.totbox .r { display:flex; justify-content:space-between; font-size:14px; }
.totbox .r.grand { border-top:1px solid var(--border); padding-top:8px; font-weight:700; font-size:16px; }
.short-note { background:var(--amber-bg); color:var(--amber-text); border-radius:12px; padding:12px 14px; font-size:13px; font-weight:600; }
.wo-note { background:#EDE7FB; color:#6D28D9; border-radius:12px; padding:12px 14px; font-size:13px; }
.locked-tag { background:var(--green-bg); color:var(--green-text); font-size:12px; font-weight:700; padding:4px 12px; border-radius:20px; }
.time-strip { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:2px; border:1px solid var(--border); border-radius:12px; overflow:hidden; margin-top:14px; }
.time-strip > div { padding:10px 14px; border-right:1px solid var(--border); }
.time-strip > div:last-child { border-right:none; }
.time-strip .k { font-size:10.5px; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); font-weight:700; }
.time-strip .v { font-size:14px; font-weight:650; margin-top:2px; }
/* Manual discount box */
.disc-box { border:1px dashed var(--border-strong,var(--border)); border-radius:12px; padding:14px 16px; margin-top:14px; background:var(--bg); }
.disc-head { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:10px; }
.disc-head .t { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--text-secondary); }
.disc-row { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; }
.disc-seg { display:inline-flex; border:1px solid var(--border); border-radius:10px; overflow:hidden; background:#fff; }
.disc-seg button { appearance:none; border:none; background:transparent; font:inherit; font-size:12.5px; font-weight:600; color:var(--text-secondary); padding:9px 14px; cursor:pointer; }
.disc-seg button[aria-pressed="true"] { background:var(--primary); color:#fff; }
.disc-field { flex:1; min-width:140px; }
.disc-field label { font-size:11.5px; font-weight:600; color:var(--text-secondary); display:block; margin-bottom:4px; }
.disc-field .in-wrap { position:relative; }
.disc-field input { width:100%; padding:9px 12px; border:1px solid var(--border); border-radius:10px; font:inherit; font-size:14px; background:#fff; }
.disc-field input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(26,127,126,.15); }
.disc-applied { display:flex; justify-content:space-between; align-items:center; gap:10px; background:#ECFDF5; border:1px solid #A7F3D0; color:#047857; border-radius:12px; padding:11px 14px; margin-top:14px; font-size:13px; font-weight:600; }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'admissions';
require __DIR__ . '/partials/sidebar.php';
?>
        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Discharge &amp; Billing</div>
                    <div class="page-sub"><a href="admission.php?id=<?= $admissionId ?>" style="color:var(--primary);font-weight:600;">&larr; Back to stay</a></div>
                </div>
                <?php if ($bill['status'] === 'paid'): ?><span class="locked-tag">Paid &amp; closed</span><?php endif; ?>
            </div>

            <?php if ($err): ?><div class="alert error"><?= htmlspecialchars($err) ?></div><?php endif; ?>
            <?php if (isset($_GET['paid'])): ?><div class="alert success">Payment recorded — patient discharged. The invoice is opening in a new tab.</div><?php endif; ?>
            <?php if (isset($_GET['wroteoff'])): ?><div class="alert success">Write-off approved — patient discharged. The invoice is opening in a new tab.</div><?php endif; ?>
            <?php if (isset($_GET['pending_writeoff'])): ?><div class="alert error">Partial payment recorded. Admin/manager must approve the write-off below before this closes.</div><?php endif; ?>

            <div class="card">
                <div class="bill-head">
                    <div>
                        <div class="section-title"><?= htmlspecialchars($adm['patient_name']) ?></div>
                        <div class="bill-meta"><span class="mono"><?= htmlspecialchars($adm['mrn']) ?></span> &middot; <?= htmlspecialchars($adm['doctor_name'] ?: '—') ?> &middot; Admission invoice <b><?= htmlspecialchars($bill['invoice_number']) ?></b></div>
                    </div>
                    <?php if ($bill['status'] === 'paid'): ?>
                    <a class="btn secondary" href="admission_invoice.php?id=<?= $admissionId ?>" target="_blank" rel="noopener">Print invoice</a>
                    <?php else: ?>
                    <!-- The invoice PDF only exists AFTER payment: reception first reviews
                         the lines and records the payment mode; opening the print view also
                         locks the bill, so exposing it early would freeze editable bills. -->
                    <span class="bill-meta" style="align-self:center;">Invoice prints after payment is recorded</span>
                    <?php endif; ?>
                </div>

                <div class="time-strip">
                    <div><div class="k">Admitted</div><div class="v"><?= date('d/m, H:i', strtotime($adm['admitted_at'])) ?></div></div>
                    <div><div class="k">Discharged</div><div class="v"><?= $adm['discharged_at'] ? date('d/m, H:i', strtotime($adm['discharged_at'])) : '—' ?></div></div>
                    <div><div class="k">Total stay</div><div class="v"><?= $stayLabel ?></div></div>
                </div>

                <div style="overflow-x:auto;margin-top:14px;">
                <table>
                    <thead><tr><th>Description</th><th>Qty</th><th>Rate</th><th>Amount</th><?php if (!$locked && $canFinalize): ?><th></th><?php endif; ?></tr></thead>
                    <tbody>
                        <?php foreach ($items as $it): ?>
                        <tr>
                            <?php if (!$locked && $canFinalize): ?>
                            <td colspan="4" style="padding:8px 10px;">
                                <form method="POST" action="admission_discharge.php?id=<?= $admissionId ?>" class="iedit">
                                    <input type="hidden" name="action" value="edit_item">
                                    <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                                    <input type="text" name="description" value="<?= htmlspecialchars($it['description']) ?>">
                                    <input type="number" step="0.01" name="quantity" value="<?= htmlspecialchars((string) $it['quantity']) ?>">
                                    <input type="number" step="0.01" name="unit_rate" value="<?= htmlspecialchars((string) $it['unit_rate']) ?>">
                                    <span class="mono" style="white-space:nowrap;">Rs <?= number_format((float) $it['amount']) ?></span>
                                    <button type="submit" class="link-btn" style="color:var(--primary);">Save</button>
                                </form>
                            </td>
                            <td>
                                <form method="POST" action="admission_discharge.php?id=<?= $admissionId ?>" onsubmit="return confirm('Remove line?');">
                                    <input type="hidden" name="action" value="remove_item">
                                    <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                                    <button type="submit" class="link-btn">×</button>
                                </form>
                            </td>
                            <?php else: ?>
                            <td style="font-weight:600;"><?= htmlspecialchars($it['description']) ?></td>
                            <td><?= htmlspecialchars((string) (float) $it['quantity']) ?></td>
                            <td class="mono">Rs <?= number_format((float) $it['unit_rate']) ?></td>
                            <td class="mono">Rs <?= number_format((float) $it['amount']) ?></td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <?php
                $catDiscount = (float) ($bill['discount_amount'] ?? 0);
                $manualDiscount = (float) ($bill['manual_discount_amount'] ?? 0);
                $manualPct = (float) ($bill['manual_discount_pct'] ?? 0);
                // Gross = what the lines would total before ANY discount.
                $gross = round((float) $bill['subtotal'] + $catDiscount, 2);
                ?>
                <div class="totbox">
                    <?php if ($manualDiscount > 0): ?>
                    <!-- Manual discount REPLACES the category discount in the total. -->
                    <div class="r"><span>Subtotal</span><span class="mono">Rs <?= number_format($gross) ?></span></div>
                    <div class="r" style="color:var(--green-text);"><span>Discount<?= $manualPct > 0 ? ' (' . rtrim(rtrim(number_format($manualPct, 2), '0'), '.') . '%)' : '' ?></span><span class="mono">− Rs <?= number_format($manualDiscount) ?></span></div>
                    <?php elseif ($catDiscount > 0): ?>
                    <!-- Generic wording by design — the category name never shows to reception/patient here. -->
                    <div class="r"><span>Before discount</span><span class="mono">Rs <?= number_format($total + $catDiscount) ?></span></div>
                    <div class="r" style="color:var(--green-text);"><span>Discount</span><span class="mono">− Rs <?= number_format($catDiscount) ?></span></div>
                    <?php endif; ?>
                    <div class="r grand"><span>Total</span><span class="mono">Rs <?= number_format($total) ?></span></div>
                    <?php if ($bill['status'] !== 'draft'): ?>
                    <div class="r"><span>Paid</span><span class="mono">Rs <?= number_format($paid) ?></span></div>
                    <?php if ($bill['write_off_amount'] > 0): ?><div class="r"><span>Written off</span><span class="mono">Rs <?= number_format((float) $bill['write_off_amount']) ?></span></div><?php endif; ?>
                    <?php if ($short > 0.001 && $bill['status'] !== 'paid'): ?><div class="r" style="color:var(--red-text);font-weight:700;"><span>Balance</span><span class="mono">Rs <?= number_format($short) ?></span></div><?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if (!$locked && $canFinalize && !$pendingWriteoff): ?>

                <!-- Manual discount: lump-sum amount OR % of the current total, applied
                     BEFORE payment. Replaces the auto category discount in the total. -->
                <?php if ($manualDiscount > 0): ?>
                <div class="disc-applied">
                    <span>Discount applied: Rs <?= number_format($manualDiscount) ?><?= $manualPct > 0 ? ' (' . rtrim(rtrim(number_format($manualPct, 2), '0'), '.') . '% of total)' : '' ?></span>
                    <form method="POST" action="admission_discharge.php?id=<?= $admissionId ?>">
                        <input type="hidden" name="action" value="clear_discount">
                        <button type="submit" class="link-btn" style="color:var(--red-text);">Remove discount</button>
                    </form>
                </div>
                <?php else: ?>
                <div class="disc-box">
                    <div class="disc-head"><span class="t">Add discount</span></div>
                    <form method="POST" action="admission_discharge.php?id=<?= $admissionId ?>" class="disc-row" id="discForm">
                        <input type="hidden" name="action" value="apply_discount">
                        <input type="hidden" name="discount_mode" id="discMode" value="amount">
                        <div class="disc-seg" role="group" aria-label="Discount type">
                            <button type="button" data-mode="amount" aria-pressed="true" onclick="discSetMode('amount')">Amount (Rs)</button>
                            <button type="button" data-mode="percent" aria-pressed="false" onclick="discSetMode('percent')">Percent (%)</button>
                        </div>
                        <div class="disc-field" id="discAmountField">
                            <label>Lump-sum discount (Rs)</label>
                            <div class="in-wrap"><input type="number" step="0.01" min="0" max="<?= number_format($gross, 2, '.', '') ?>" name="discount_amount" placeholder="0"></div>
                        </div>
                        <div class="disc-field" id="discPercentField" style="display:none;">
                            <label>Discount (% of Rs <?= number_format($total) ?>)</label>
                            <div class="in-wrap"><input type="number" step="0.01" min="0" max="100" name="discount_percent" placeholder="0"></div>
                        </div>
                        <button type="submit" class="btn secondary" style="white-space:nowrap;">Apply discount</button>
                    </form>
                </div>
                <?php endif; ?>

                <form method="POST" action="admission_discharge.php?id=<?= $admissionId ?>">
                    <input type="hidden" name="action" value="finalize">
                    <div class="pay-grid">
                        <div>
                            <label>Payment method</label>
                            <select name="payment_method">
                                <option value="cash">Cash</option><option value="card">Card</option>
                                <option value="bank_transfer">Bank transfer</option><option value="cheque">Cheque</option>
                            </select>
                        </div>
                        <div>
                            <label>Amount collected (Rs)</label>
                            <input type="number" step="0.01" min="0" name="paid_amount" value="<?= number_format($total, 2, '.', '') ?>">
                        </div>
                    </div>
                    <div style="display:flex;justify-content:flex-end;margin-top:16px;">
                        <button type="submit" class="btn">Record payment &amp; discharge</button>
                    </div>
                    <div class="est-note" style="font-size:11.5px;color:var(--text-muted);margin-top:8px;">Collect less than the total to record a partial payment — the shortfall then needs admin/manager write-off approval.</div>
                </form>
                <?php endif; ?>

                <?php if ($pendingWriteoff): ?>
                <div class="short-note" style="margin-top:12px;">Partial payment of Rs <?= number_format($paid) ?> recorded. Balance Rs <?= number_format($short) ?> requires a write-off approval to close.</div>
                <?php if ($canApproveWriteoff): ?>
                <form method="POST" action="admission_discharge.php?id=<?= $admissionId ?>" style="margin-top:12px;" onsubmit="return confirm('Write off Rs <?= number_format($short) ?>? This is gone forever and flags the patient.');">
                    <input type="hidden" name="action" value="approve_writeoff">
                    <label style="font-size:12px;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:5px;">Reason for allowing the unpaid balance</label>
                    <input type="text" name="reason" placeholder="e.g. patient unable to pay, approved by owner" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-input);font:inherit;">
                    <div style="display:flex;justify-content:flex-end;margin-top:12px;">
                        <button type="submit" class="btn danger">Approve write-off &amp; close</button>
                    </div>
                </form>
                <?php else: ?>
                <div class="wo-note" style="margin-top:12px;">Waiting for an admin or manager to approve the write-off.</div>
                <?php endif; ?>
                <?php endif; ?>

                <?php if ($bill['status'] === 'paid'): ?>
                <div class="wo-note" style="margin-top:12px;background:var(--green-bg);color:var(--green-text);">
                    Closed. Collected Rs <?= number_format($paid) ?><?= $bill['write_off_amount'] > 0 ? ', wrote off Rs ' . number_format((float) $bill['write_off_amount']) : '' ?>.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script src="assets/js/date-picker.js"></script>
<script>
// Discount type toggle — switch the visible field and the hidden mode flag.
function discSetMode(mode) {
    var m = document.getElementById('discMode');
    if (m) m.value = mode;
    var amt = document.getElementById('discAmountField');
    var pct = document.getElementById('discPercentField');
    if (amt) amt.style.display = mode === 'amount' ? '' : 'none';
    if (pct) pct.style.display = mode === 'percent' ? '' : 'none';
    document.querySelectorAll('.disc-seg button[data-mode]').forEach(function (b) {
        b.setAttribute('aria-pressed', b.getAttribute('data-mode') === mode ? 'true' : 'false');
    });
    // Clear the now-hidden field so a stale value isn't submitted.
    var hide = mode === 'amount' ? pct : amt;
    if (hide) { var i = hide.querySelector('input'); if (i) i.value = ''; }
}
</script>
<?php if (isset($_GET['paid']) || isset($_GET['wroteoff'])): ?>
<script>
// Payment just landed: open the invoice PDF automatically, mirroring how
// registration auto-opens the consultation slip. Popup blockers fall back to
// the Print invoice button that is now visible in the header.
window.addEventListener('load', function () {
    window.open('admission_invoice.php?id=<?= (int) $admissionId ?>', '_blank', 'noopener');
});
</script>
<?php endif; ?>
</body>
</html>
