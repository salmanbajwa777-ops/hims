<?php
/**
 * IPD discharge & billing.
 *
 * Raises the SEPARATE IPD bill ("I" invoice series), seeded from stay-days +
 * paid consultant visits + logged services. Reception reviews/edits lines,
 * applies an optional manual discount, takes payment (full or partial), and on
 * a shortfall an admin approves the write-off. Consultation + ER bills untouched.
 *
 * Also the place to LOG chargeable IPD services (before discharge) and to submit
 * the discharge itself.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/billing.php';
require_once __DIR__ . '/config/ipd_billing.php';
require_once __DIR__ . '/config/ipd_advances.php';
refresh_session_permissions($pdo);

$baseRole = $_SESSION['base_role'] ?? '';
$uid = (int) $_SESSION['user_id'];
$admissionId = (int) ($_GET['id'] ?? 0);

function load_ipd_adm_bill(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("
        SELECT a.*, v.token_no, p.id AS patient_id, p.mrn, p.name AS patient_name, p.phone,
               COALESCE(du.name, a.admitting_consultant_manual) AS consultant_name
        FROM ipd_admissions a
        JOIN visits v ON v.id = a.visit_id
        JOIN patients p ON p.id = v.patient_id
        LEFT JOIN users du ON du.id = a.admitting_consultant_id
        WHERE a.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

$adm = load_ipd_adm_bill($pdo, $admissionId);
if (!$adm) { http_response_code(404); exit('IPD admission not found.'); }

$canFinalize = has_permission('IPD_FINALIZE_BILL');
$canApproveWriteoff = has_permission('IPD_APPROVE_WRITEOFF');
$canDischarge = has_permission('IPD_DISCHARGE_PATIENT');
$canLogServices = has_permission('IPD_LOG_SERVICES');
if (!$canFinalize && !$canApproveWriteoff && !$canDischarge && !$canLogServices) {
    http_response_code(403); exit('Forbidden.');
}

$flash = '';
$err = '';

// ---------------- Submit discharge (marks ready for billing) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_discharge' && $canDischarge && $adm['status'] === 'ACTIVE') {
    $pdo->prepare("UPDATE ipd_admissions SET status = 'DISCHARGE_IN_PROGRESS', discharged_at = COALESCE(discharged_at, NOW()) WHERE id = ?")
        ->execute([$admissionId]);
    $pdo->prepare('UPDATE visits SET discharged_at = NOW() WHERE id = ?')->execute([(int) $adm['visit_id']]);
    audit_log($pdo, 'ipd_discharge_submitted', "IPD discharge submitted for admission #$admissionId", $uid);
    $flash = 'Discharge submitted — review the bill below.';
    $adm = load_ipd_adm_bill($pdo, $admissionId);
}

// ---------------- Log a chargeable service ----------------
// Services are logged BEFORE discharge is submitted — once the draft bill is
// seeded (at discharge submit) it reads the service list, so logging is only
// open while the stay is ACTIVE. This keeps the bill and the service log honest.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_service' && $canLogServices && $adm['status'] === 'ACTIVE') {
    $erId = (int) ($_POST['er_service_id'] ?? 0) ?: null;
    $qty = max(1, (int) ($_POST['quantity'] ?? 1));
    $dur = ($_POST['duration_minutes'] ?? '') !== '' ? (int) $_POST['duration_minutes'] : null;
    if (!$erId) {
        $err = 'Pick a service.';
    } else {
        $s = $pdo->prepare("SELECT * FROM er_services_master WHERE id = ? AND status = 'ACTIVE'");
        $s->execute([$erId]);
        $svc = $s->fetch();
        if (!$svc) {
            $err = 'That service is not available.';
        } else {
            $note = trim($_POST['clinical_note'] ?? '');
            $charge = ipd_service_charge($svc['charge_type'], (float) $svc['base_charge'], $qty, $dur);
            $loggedRole = in_array($baseRole, ['ADMIN','DOCTOR','STAFF'], true) ? $baseRole : 'STAFF';
            $pdo->prepare('
                INSERT INTO ipd_services (admission_id, er_service_id, service_type, service_name, charge_type, quantity, duration_minutes, unit_charge, calculated_charge, clinical_note, logged_by_id, logged_by_role)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([
                $admissionId, $svc['id'], $svc['service_type'], $svc['service_name'], $svc['charge_type'],
                $qty, $dur, $svc['base_charge'], $charge, $note !== '' ? mb_substr($note, 0, 200) : null, $uid, $loggedRole,
            ]);
            // Mirror into the nursing flow-sheet: a given service is also a care
            // action, so the clinical record shows it (without the price).
            log_ipd_service_care_event($pdo, $admissionId, [
                'service_name' => $svc['service_name'], 'charge_type' => $svc['charge_type'],
                'quantity' => $qty, 'duration_minutes' => $dur, 'clinical_note' => $note,
            ], (int) $pdo->lastInsertId(), $uid, $loggedRole);
            audit_log($pdo, 'ipd_service_logged', $svc['service_name'] . " logged for IPD admission #$admissionId", $uid);
            $flash = 'Service logged.';
        }
    }
}

// ---------------- Override one charge line's rate ----------------
// Room / nursing / MO / consultant lines are computed from the room category's
// rates, but a bill sometimes needs a one-off figure (a negotiated room rate, a
// short first day). SERVICE lines are excluded — those carry their own frozen
// per-line charge and are adjusted by removing the service instead.
// Every override is audited: a hand-edited charge must be answerable later.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'override_line' && $canFinalize) {
    $lineId = (int) ($_POST['line_id'] ?? 0);
    $newRate = max(0, (float) ($_POST['unit_rate'] ?? 0));
    try {
        $li = $pdo->prepare("
            SELECT i.* FROM ipd_bill_items i
            JOIN ipd_bills b ON b.id = i.ipd_bill_id
            WHERE i.id = ? AND b.admission_id = ? AND b.status = 'draft' AND i.item_kind <> 'SERVICE'
        ");
        $li->execute([$lineId, $admissionId]);
        $line = $li->fetch();
        if (!$line) {
            $err = 'That line cannot be edited — the bill may already be settled.';
        } else {
            $qty = max(1, (int) $line['quantity']);
            $amount = round($newRate * $qty, 2);
            $pdo->prepare('UPDATE ipd_bill_items SET unit_rate = ?, amount = ? WHERE id = ?')
                ->execute([$newRate, $amount, $lineId]);
            recalc_ipd_bill_totals($pdo, (int) $line['ipd_bill_id']);
            audit_log($pdo, 'ipd_bill_line_override',
                "Line #$lineId ({$line['item_kind']}) on IPD admission #$admissionId: rate {$line['unit_rate']} -> $newRate", $uid);
            $flash = 'Charge updated.';
        }
    } catch (Throwable $e) {
        $err = 'Could not update that charge.';
    }
}

// ---------------- Remove / restore a logged service ----------------
// Admin only. is_billable flips — the ipd_services row is NEVER deleted, so the
// clinical record of what was done to the patient survives the charge coming off.
$canRemoveService = has_permission('IPD_REMOVE_SERVICE');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['remove_service','restore_service'], true) && $canRemoveService) {
    $svcId = (int) ($_POST['service_id'] ?? 0);
    $makeBillable = ($_POST['action'] === 'restore_service');
    try {
        if (set_ipd_service_billable($pdo, $admissionId, $svcId, $makeBillable)) {
            audit_log($pdo, $makeBillable ? 'ipd_service_restored' : 'ipd_service_removed',
                "Service #$svcId " . ($makeBillable ? 'restored to' : 'removed from') . " the bill for IPD admission #$admissionId", $uid);
            $flash = $makeBillable ? 'Service restored to the bill.' : 'Service removed from the bill.';
        } else {
            $err = 'That service could not be changed — the bill may already be settled.';
        }
    } catch (Throwable $e) {
        $err = 'Could not update the service.';
    }
    $adm = load_ipd_adm_bill($pdo, $admissionId);
}

// A draft bill only exists once discharge is submitted (or someone opens billing).
$bill = null;
if ($adm['status'] !== 'ACTIVE' && $canFinalize) {
    $bill = ensure_ipd_bill($pdo, $adm, $uid);
}
$locked = $bill && ($bill['status'] === 'paid' || $bill['printed_at']);

// ---------------- Apply manual discount ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_discount' && $bill && !$locked && $canFinalize) {
    $mode = $_POST['discount_mode'] ?? 'amount';
    $val = max(0, (float) ($_POST['discount_value'] ?? 0));
    if ($mode === 'pct') {
        $pdo->prepare('UPDATE ipd_bills SET manual_discount_pct = ?, manual_discount_amount = 0 WHERE id = ?')->execute([min(100, $val), $bill['id']]);
    } else {
        $pdo->prepare('UPDATE ipd_bills SET manual_discount_amount = ?, manual_discount_pct = 0 WHERE id = ?')->execute([$val, $bill['id']]);
    }
    recalc_ipd_bill_totals($pdo, (int) $bill['id']);
    audit_log($pdo, 'ipd_bill_discount', "Discount on IPD bill #{$bill['id']} ($mode $val)", $uid);
    $flash = 'Discount applied.';
    $bill = ensure_ipd_bill($pdo, $adm, $uid);
    $locked = ($bill['status'] === 'paid' || $bill['printed_at']);
}

// ---------------- Settle (take payment) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'settle' && $bill && !$locked && $canFinalize) {
    $method = in_array($_POST['payment_method'] ?? '', ['cash','card','bank_transfer','cheque'], true) ? $_POST['payment_method'] : 'cash';
    $paid = max(0, (float) ($_POST['paid_amount'] ?? 0));
    $grand = (float) $bill['grand_total'];

    // Advances already held settle the bill first; only the remainder is
    // collected now. Re-read the balance here rather than trusting a POSTed
    // figure — the ledger is the authority, and it may have changed since the
    // page rendered.
    $advHeld = ipd_advance_balance($pdo, $admissionId);
    [$advApplied, $advRefundDue] = ipd_settle_advance($grand, $advHeld);
    $balanceDue = round($grand - $advApplied, 2);

    // The shortfall is measured against what is still OWED after advances, not
    // the grand total — otherwise a bill fully covered by an advance would look
    // like a 100% write-off.
    $shortfall = round($balanceDue - $paid, 2);

    // How much of the excess to hand back. Only meaningful when advances cover
    // the whole bill; clamped to what is actually held.
    $returnAmt = 0.0;
    if ($advRefundDue > 0.009) {
        $returnAmt = round(min((float) ($_POST['return_amount'] ?? $advRefundDue), $advRefundDue), 2);
        $returnMethod = in_array($_POST['return_method'] ?? '', ['cash','card','bank_transfer','cheque'], true)
            ? $_POST['return_method'] : 'cash';
    }

    if ($shortfall > 0.009 && !$canApproveWriteoff) {
        $err = 'Short payment needs an admin to approve the write-off.';
    } else {
        $writeOff = $shortfall > 0.009 ? $shortfall : 0;
        $pdo->beginTransaction();
        try {
            // paid_amount is the TOTAL settled against the bill — cash taken now
            // plus advance applied — so the invoice and every revenue read see
            // the full amount the patient paid for this stay.
            $totalSettled = round($paid + $advApplied, 2);
            $pdo->prepare("
                UPDATE ipd_bills
                SET status = 'paid', payment_method = ?, paid_amount = ?, write_off_amount = ?, paid_at = NOW(), paid_by_id = ?, finalized_by_id = COALESCE(finalized_by_id, ?)
                WHERE id = ?
            ")->execute([$method, $totalSettled, $writeOff, $uid, $uid, $bill['id']]);

            // Snapshot what the advance covered, so the invoice can show it
            // without re-deriving a sum that later voids would change.
            try {
                $pdo->prepare('UPDATE ipd_bills SET advance_applied = ?, advance_refunded = ? WHERE id = ?')
                    ->execute([$advApplied, $returnAmt, $bill['id']]);
            } catch (PDOException $e) { /* columns not migrated yet */ }

            $pdo->prepare("UPDATE ipd_admissions SET status = 'DISCHARGED', discharge_finalized_by_id = ?, discharge_finalized_at = NOW(), discharged_at = COALESCE(discharged_at, NOW()) WHERE id = ?")
                ->execute([$uid, $admissionId]);

            audit_log($pdo, 'ipd_bill_settled', "IPD bill {$bill['invoice_number']} settled: collected $paid, advance applied $advApplied, write-off $writeOff", $uid);
            $pdo->commit();

            // Return the excess AFTER the bill commits: the refund is its own
            // ledger row, and a failure here must not roll back a settled
            // discharge. Reported if it fails rather than silently swallowed.
            if ($returnAmt > 0.009) {
                [$rOk, $rRes] = ipd_refund_excess_advance($pdo, $admissionId, $returnAmt, $returnMethod, $uid);
                if (!$rOk) {
                    header('Location: ipd_discharge.php?id=' . $admissionId . '&settled=1&return_failed=' . urlencode($rRes));
                    exit;
                }
            }
            header('Location: ipd_discharge.php?id=' . $admissionId . '&settled=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $err = 'Could not settle the bill — please try again.';
        }
    }
    if ($bill) { $bill = ensure_ipd_bill($pdo, $adm, $uid); }
}

// ---- Data for the view ----
$items = [];
if ($bill) {
    // FIELD() returns 0 for a value not in its list, which would sort the newer
    // NURSING/MO kinds ABOVE everything else — they must be named here.
    $it = $pdo->prepare('SELECT * FROM ipd_bill_items WHERE ipd_bill_id = ? ORDER BY FIELD(item_kind,\'STAY\',\'NURSING\',\'MO\',\'CONSULT_VISIT\',\'SERVICE\'), id');
    $it->execute([(int) $bill['id']]);
    $items = $it->fetchAll();
    $locked = ($bill['status'] === 'paid' || $bill['printed_at']);
}
// Joined to users so reception can see WHO logged each line — nurse-logged
// services land here automatically, so the name is what makes a surprise charge
// answerable.
$services = $pdo->prepare('
    SELECT s.*, u.name AS by_name
    FROM ipd_services s
    LEFT JOIN users u ON u.id = s.logged_by_id
    WHERE s.admission_id = ?
    ORDER BY s.logged_at
');
$services->execute([$admissionId]);
$services = $services->fetchAll();
$erServices = $pdo->query("SELECT id, service_type, service_name, charge_type, base_charge FROM er_services_master WHERE status = 'ACTIVE' ORDER BY service_type, service_name")->fetchAll();

$days = ipd_stay_days($adm['admitted_at'], $adm['discharged_at'] ?? null);
$statusLabels = ['ACTIVE' => 'Active', 'DISCHARGE_IN_PROGRESS' => 'Discharge in progress', 'DISCHARGED' => 'Discharged'];

$pageTitle = 'IPD Discharge — ' . $adm['patient_name'];
$headExtra = <<<CSS
<style>
.bill-table { width:100%; border-collapse:collapse; font-size:13px; }
.bill-table th, .bill-table td { text-align:left; padding:9px 11px; border-bottom:1px solid var(--border); }
.bill-table th { font-size:10.5px; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); font-weight:700; }
.bill-table td.amt, .bill-table th.amt { text-align:right; font-variant-numeric:tabular-nums; }
.kind-tag { font-size:10px; font-weight:700; padding:2px 7px; border-radius:20px; background:#F1F5F9; color:var(--text-secondary); }
.two-col { display:grid; grid-template-columns:1.5fr 1fr; gap:20px; align-items:start; }
@media (max-width:960px){ .two-col{ grid-template-columns:1fr; } }
.svc-add { display:grid; grid-template-columns:2fr .7fr .9fr auto; gap:10px; align-items:end; }
.svc-add label, .mini label { font-size:11.5px; font-weight:600; color:var(--text-secondary); display:block; margin-bottom:5px; }
.svc-add select, .svc-add input, .mini input, .mini select { width:100%; padding:9px 11px; border:1px solid var(--border); border-radius:10px; font:inherit; font-size:13.5px; background:var(--bg); }
.tot-row { display:flex; justify-content:space-between; font-size:13.5px; padding:4px 0; }
.tot-row.grand { border-top:1px solid var(--border); margin-top:6px; padding-top:10px; font-weight:700; font-size:16px; }
.tot-row.credit span:last-child { color:var(--primary-dark); font-weight:600; }
.tot-row.due { font-weight:700; font-size:15.5px; background:var(--bg); margin:6px -8px 0; padding:9px 8px; border-radius:8px; }
.tot-row.due.ret span { color:var(--red-text); }
.adv-chip { display:inline-block; font-size:10px; font-weight:700; padding:1px 6px; border-radius:20px; background:var(--primary-light); color:var(--primary-dark); margin-left:4px; }
.adv-banner { background:#FFF7E8; border:1px solid #FBCFB0; color:#8a5a00; border-radius:8px; padding:11px 13px; font-size:12.5px; margin-bottom:14px; }
.adv-banner b { display:block; margin-bottom:2px; font-size:13px; }
.svc-table { width:100%; border-collapse:collapse; font-size:12.5px; }
.svc-table th, .svc-table td { text-align:left; padding:7px 9px; border-bottom:1px solid var(--border); vertical-align:top; }
.svc-table th { font-size:10.5px; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); font-weight:700; white-space:nowrap; }
.svc-table td.mono { white-space:nowrap; font-variant-numeric:tabular-nums; }
.svc-table .svc-total td { border-bottom:none; border-top:2px solid var(--border); font-weight:700; font-size:13.5px; background:var(--bg); }
.svc-tag.rm { display:inline-block; font-size:10px; font-weight:700; padding:1px 7px; border-radius:20px; background:var(--red-bg); color:var(--red-text); margin-left:4px; }
.svc-off td:not(:last-child) { opacity:.6; }
.svc-off td:nth-child(2) { text-decoration:line-through; }
.svc-off td:nth-child(2) .svc-tag, .svc-off td:nth-child(2) .muted { text-decoration:none; }
.svc-act { background:none; border:none; padding:2px 4px; font:inherit; font-size:12px; font-weight:600; color:var(--primary); cursor:pointer; text-decoration:underline; }
.svc-act.danger { color:var(--red-text); }
.rate-inp { width:92px; padding:5px 8px; border:1px solid var(--border); border-radius:8px;
    font:inherit; font-size:12.5px; text-align:right; background:#fff; font-variant-numeric:tabular-nums; }
.rate-inp:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(26,127,126,.15); }
.rate-go { border:1px solid var(--border); background:var(--bg); color:var(--primary);
    border-radius:8px; padding:4px 8px; font-size:12px; cursor:pointer; line-height:1; }
.rate-go:hover { background:var(--primary-light); }
.kind-tag.NURSING { background:var(--green-bg); color:var(--green-text); }
.kind-tag.MO { background:var(--amber-bg); color:var(--amber-text); }
CSS;
$headExtra .= "\n</style>";
require __DIR__ . '/partials/head.php';
$navActive = 'ipd';
require __DIR__ . '/partials/sidebar.php';
?>
        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">IPD Discharge &amp; Billing</div>
                    <div class="page-sub"><a href="ipd_admission.php?id=<?= $admissionId ?>" style="color:var(--primary);font-weight:600;">&larr; Back to stay</a> &middot; <?= htmlspecialchars($adm['patient_name']) ?> &middot; <span class="mono"><?= htmlspecialchars($adm['mrn']) ?></span> &middot; <?= htmlspecialchars($statusLabels[$adm['status']] ?? $adm['status']) ?></div>
                </div>
            </div>

            <?php if (isset($_GET['settled'])): ?><div class="alert success">Bill settled and patient discharged.</div><?php endif; ?>
            <?php if (($rf = trim($_GET['return_failed'] ?? '')) !== ''): ?>
            <div class="alert error">The bill was settled and the patient discharged, but the advance return could NOT be recorded: <?= htmlspecialchars($rf) ?> Hand the money back and record it manually, or ask an admin.</div>
            <?php endif; ?>
            <?php if ($flash): ?><div class="alert success"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

            <?php if ($adm['status'] === 'ACTIVE'): ?>
            <div class="card">
                <div class="section-title">Discharge</div>
                <div class="section-sub">Submitting discharge stops the stay clock and opens billing.</div>
                <?php if ($canDischarge): ?>
                <form method="POST" action="ipd_discharge.php?id=<?= $admissionId ?>" style="margin-top:12px;" onsubmit="return confirm('Submit discharge for this patient?');">
                    <input type="hidden" name="action" value="submit_discharge">
                    <button type="submit" class="btn">Submit discharge</button>
                </form>
                <?php else: ?>
                <div class="muted" style="margin-top:8px;">You don't have permission to submit the discharge.</div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="two-col" style="margin-top:20px;">
                <!-- Bill -->
                <div class="card">
                    <div class="section-title">IPD Bill<?= $bill ? ' — ' . htmlspecialchars($bill['invoice_number']) : '' ?></div>
                    <?php if (!$bill): ?>
                        <div class="muted" style="margin-top:8px;"><?= $adm['status'] === 'ACTIVE' ? 'The bill is raised once discharge is submitted.' : 'You do not have billing permission for IPD.' ?></div>
                    <?php else: ?>
                    <?php
                    $kindLabels = ['STAY' => 'ROOM', 'NURSING' => 'NURSING', 'MO' => 'MO',
                                   'CONSULT_VISIT' => 'CONSULTANT', 'SERVICE' => 'SERVICE'];
                    // A rate is only editable while the bill is open, and never on
                    // a SERVICE line — those are changed by removing the service.
                    $canOverride = $canFinalize && !$locked;
                    ?>
                    <table class="bill-table" style="margin-top:12px;">
                        <thead><tr><th>Item</th><th>Qty</th><th class="amt">Rate</th><th class="amt">Amount</th></tr></thead>
                        <tbody>
                            <?php foreach ($items as $li): $editable = $canOverride && $li['item_kind'] !== 'SERVICE'; ?>
                            <tr>
                                <td><span class="kind-tag <?= htmlspecialchars($li['item_kind']) ?>"><?= htmlspecialchars($kindLabels[$li['item_kind']] ?? $li['item_kind']) ?></span> <?= htmlspecialchars($li['description']) ?></td>
                                <td><?= rtrim(rtrim(number_format((float) $li['quantity'], 2), '0'), '.') ?></td>
                                <td class="amt">
                                    <?php if ($editable): ?>
                                    <form method="POST" action="ipd_discharge.php?id=<?= $admissionId ?>" style="display:inline-flex;gap:5px;align-items:center;">
                                        <input type="hidden" name="action" value="override_line">
                                        <input type="hidden" name="line_id" value="<?= (int) $li['id'] ?>">
                                        <input class="rate-inp" type="number" step="0.01" min="0" name="unit_rate" value="<?= htmlspecialchars((string) $li['unit_rate']) ?>">
                                        <button type="submit" class="rate-go" title="Apply this rate">&#10003;</button>
                                    </form>
                                    <?php else: ?>
                                    <span class="muted"><?= number_format((float) $li['unit_rate']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="amt">Rs <?= number_format((float) $li['amount']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php
                    $discountShown = (float) $bill['grand_total'] < (float) $bill['subtotal'];
                    // Advances held against this stay. On an already-settled bill
                    // read the SNAPSHOT, not the live ledger — the ledger has the
                    // return row subtracted and would understate what was applied.
                    $advSettled = $bill['status'] === 'paid';
                    $advApplied = $advSettled
                        ? (float) ($bill['advance_applied'] ?? 0)
                        : 0.0;
                    $advRefunded = (float) ($bill['advance_refunded'] ?? 0);
                    if (!$advSettled) {
                        $advHeldNow = ipd_advance_balance($pdo, $admissionId);
                        [$advApplied, $advReturnDue] = ipd_settle_advance((float) $bill['grand_total'], $advHeldNow);
                        $advBalanceDue = round((float) $bill['grand_total'] - $advApplied, 2);
                    }
                    $advCount = count(array_filter(ipd_advance_rows($pdo, $admissionId),
                        fn($r) => empty($r['voided_at']) && $r['direction'] === 'PAYMENT'));
                    ?>
                    <div style="margin-top:12px;">
                        <div class="tot-row"><span>Subtotal</span><span>Rs <?= number_format((float) $bill['subtotal']) ?></span></div>
                        <?php if ($discountShown): ?>
                        <div class="tot-row"><span>Discount</span><span>&minus; Rs <?= number_format((float) $bill['subtotal'] - (float) $bill['grand_total']) ?></span></div>
                        <?php endif; ?>
                        <div class="tot-row grand"><span>Grand total</span><span>Rs <?= number_format((float) $bill['grand_total']) ?></span></div>

                        <?php if ($advApplied > 0.009): ?>
                        <div class="tot-row credit">
                            <span>Less advances paid<?= $advCount ? ' <span class="adv-chip">' . $advCount . ' receipt' . ($advCount > 1 ? 's' : '') . '</span>' : '' ?></span>
                            <span>&minus; Rs <?= number_format($advApplied) ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!$advSettled && $advApplied > 0.009): ?>
                            <?php if ($advReturnDue > 0.009): ?>
                            <div class="tot-row due ret"><span>To return to patient</span><span>Rs <?= number_format($advReturnDue) ?></span></div>
                            <?php else: ?>
                            <div class="tot-row due"><span>Balance payable now</span><span>Rs <?= number_format($advBalanceDue) ?></span></div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($advSettled): ?>
                        <div class="tot-row"><span>Paid (<?= htmlspecialchars($bill['payment_method']) ?>)</span><span>Rs <?= number_format((float) $bill['paid_amount']) ?></span></div>
                        <?php if ($advRefunded > 0.009): ?><div class="tot-row"><span>Advance returned</span><span>Rs <?= number_format($advRefunded) ?></span></div><?php endif; ?>
                        <?php if ((float) $bill['write_off_amount'] > 0): ?><div class="tot-row"><span>Written off</span><span>Rs <?= number_format((float) $bill['write_off_amount']) ?></span></div><?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
                        <a class="btn secondary" href="ipd_invoice.php?id=<?= $admissionId ?>" target="_blank">Print invoice</a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Actions: services, discount, settle -->
                <div>
                    <?php if ($canLogServices && $adm['status'] === 'ACTIVE'): ?>
                    <div class="card">
                        <div class="section-title">Log a service</div>
                        <div class="section-sub">Log all chargeable services before submitting discharge — the bill reads them at discharge.</div>
                        <form method="POST" action="ipd_discharge.php?id=<?= $admissionId ?>" style="margin-top:12px;">
                            <input type="hidden" name="action" value="add_service">
                            <div class="svc-add">
                                <div>
                                    <label>Service</label>
                                    <select name="er_service_id" required>
                                        <option value="">Select…</option>
                                        <?php foreach ($erServices as $e): ?>
                                        <option value="<?= (int) $e['id'] ?>"><?= htmlspecialchars($e['service_name']) ?> (Rs <?= number_format((float) $e['base_charge']) ?><?= $e['charge_type'] === 'HOURLY' ? '/hr' : '' ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div><label>Qty</label><input type="number" name="quantity" min="1" value="1"></div>
                                <div><label>Min (hrly)</label><input type="number" name="duration_minutes" min="0" placeholder="—"></div>
                                <button type="submit" class="btn secondary">Add</button>
                            </div>
                            <div style="margin-top:8px;"><input type="text" name="clinical_note" maxlength="200" placeholder="Detail (optional)" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;font:inherit;font-size:13px;background:var(--bg);"></div>
                        </form>
                    </div>
                    <?php endif; ?>

                    <?php if ($services): ?>
                    <div class="card" style="margin-top:20px;">
                        <div class="section-title">Services logged during stay</div>
                        <div class="section-sub">
                            Nurse-logged items are already on the bill.
                            <?= $canRemoveService ? 'Remove anything that should not be charged — the record of it stays.' : '' ?>
                        </div>
                        <div style="overflow-x:auto;margin-top:6px;">
                        <table class="svc-table">
                            <thead>
                                <tr>
                                    <th>When</th><th>Service</th><th>Logged by</th>
                                    <th style="text-align:right;">Qty</th><th style="text-align:right;">Amount</th>
                                    <?php if ($canRemoveService): ?><th></th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $svcTotal = 0; foreach ($services as $s): $off = (int) $s['is_billable'] === 0; if (!$off) { $svcTotal += (float) $s['calculated_charge']; } ?>
                                <tr<?= $off ? ' class="svc-off"' : '' ?>>
                                    <td class="mono"><?= date('d/m H:i', strtotime($s['logged_at'])) ?></td>
                                    <td>
                                        <?= htmlspecialchars($s['service_name']) ?>
                                        <?php if ($off): ?><span class="svc-tag rm">Removed</span><?php endif; ?>
                                        <?php if (!empty($s['clinical_note'])): ?><div class="muted" style="font-size:11.5px;">&#8627; <?= htmlspecialchars($s['clinical_note']) ?></div><?php endif; ?>
                                    </td>
                                    <td class="muted"><?= htmlspecialchars($s['by_name'] ?: '—') ?></td>
                                    <td class="mono" style="text-align:right;"><?= $s['charge_type'] === 'HOURLY' ? ((int) $s['duration_minutes']) . ' min' : (int) $s['quantity'] ?></td>
                                    <td class="mono" style="text-align:right;"><?= $off ? '—' : number_format((float) $s['calculated_charge'], 2) ?></td>
                                    <?php if ($canRemoveService): ?>
                                    <td style="text-align:right;">
                                        <?php if (!$locked): ?>
                                        <form method="POST" action="ipd_discharge.php?id=<?= $admissionId ?>" style="display:inline;">
                                            <input type="hidden" name="action" value="<?= $off ? 'restore_service' : 'remove_service' ?>">
                                            <input type="hidden" name="service_id" value="<?= (int) $s['id'] ?>">
                                            <button type="submit" class="svc-act<?= $off ? '' : ' danger' ?>"><?= $off ? 'Restore' : 'Remove' ?></button>
                                        </form>
                                        <?php else: ?><span class="muted">&mdash;</span><?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="svc-total">
                                    <td colspan="4">Services total</td>
                                    <td class="mono" style="text-align:right;"><?= number_format($svcTotal, 2) ?></td>
                                    <?php if ($canRemoveService): ?><td></td><?php endif; ?>
                                </tr>
                            </tbody>
                        </table>
                        </div>
                        <a class="btn secondary" style="margin-top:12px;display:inline-block;" href="ipd_stay_report.php?id=<?= $admissionId ?>" target="_blank">Print stay report</a>
                    </div>
                    <?php endif; ?>

                    <?php if ($bill && !$locked && $canFinalize): ?>
                    <div class="card" style="margin-top:20px;">
                        <div class="section-title">Discount</div>
                        <form method="POST" action="ipd_discharge.php?id=<?= $admissionId ?>" class="mini" style="margin-top:10px;display:flex;gap:8px;align-items:end;">
                            <input type="hidden" name="action" value="apply_discount">
                            <div style="width:110px;"><label>Mode</label><select name="discount_mode"><option value="amount">Rs</option><option value="pct">%</option></select></div>
                            <div style="flex:1;"><label>Value</label><input type="number" step="0.01" min="0" name="discount_value" value="0"></div>
                            <button type="submit" class="btn secondary">Apply</button>
                        </form>
                    </div>

                    <?php
                    // Recomputed here (this block renders after the totals above,
                    // but the two must never disagree about what is owed).
                    $sHeld = ipd_advance_balance($pdo, $admissionId);
                    [$sApplied, $sReturnDue] = ipd_settle_advance((float) $bill['grand_total'], $sHeld);
                    $sBalance = round((float) $bill['grand_total'] - $sApplied, 2);
                    $sIsReturn = $sReturnDue > 0.009;
                    ?>
                    <div class="card" style="margin-top:20px;">
                        <?php if ($sIsReturn): ?>
                        <div class="adv-banner">
                            <b>Advance exceeds the bill by Rs <?= number_format($sReturnDue) ?></b>
                            Return this to the patient from the drawer. It is recorded as an advance
                            return and appears on your shift closing under Paid out.
                        </div>
                        <div class="section-title">Return excess &amp; discharge</div>
                        <div class="section-sub">The bill settles fully from the advance held; nothing further is collected.</div>
                        <form method="POST" action="ipd_discharge.php?id=<?= $admissionId ?>" class="mini" style="margin-top:10px;display:flex;flex-direction:column;gap:10px;" onsubmit="return confirm('Return Rs <?= number_format($sReturnDue) ?> and discharge the patient?');" data-lock-submit>
                            <input type="hidden" name="action" value="settle">
                            <input type="hidden" name="payment_method" value="cash">
                            <input type="hidden" name="paid_amount" value="0">
                            <div><label>Returned as</label>
                                <select name="return_method"><option value="cash">Cash</option><option value="card">Card</option><option value="bank_transfer">Bank transfer</option><option value="cheque">Cheque</option></select>
                            </div>
                            <div><label>Amount returned</label>
                                <input type="number" step="0.01" min="0" max="<?= number_format($sReturnDue, 2, '.', '') ?>"
                                       name="return_amount" value="<?= number_format($sReturnDue, 2, '.', '') ?>"
                                       style="font-weight:700;font-variant-numeric:tabular-nums;">
                            </div>
                            <button type="submit" class="btn">Return Rs <?= number_format($sReturnDue) ?> &amp; discharge</button>
                            <div class="muted" style="font-size:11.5px;">Prints an ADV return receipt alongside the discharge invoice.</div>
                        </form>
                        <?php else: ?>
                        <div class="section-title">Settle &amp; discharge</div>
                        <?php if ($sApplied > 0.009): ?>
                        <div class="section-sub">Rs <?= number_format($sApplied) ?> already held as advance — collect the balance below.</div>
                        <?php endif; ?>
                        <form method="POST" action="ipd_discharge.php?id=<?= $admissionId ?>" class="mini" style="margin-top:10px;display:flex;flex-direction:column;gap:10px;" onsubmit="return confirm('Settle this bill and discharge the patient?');" data-lock-submit>
                            <input type="hidden" name="action" value="settle">
                            <div><label>Payment method</label>
                                <select name="payment_method"><option value="cash">Cash</option><option value="card">Card</option><option value="bank_transfer">Bank transfer</option><option value="cheque">Cheque</option></select>
                            </div>
                            <div><label>Amount received</label>
                                <input type="number" step="0.01" min="0" name="paid_amount"
                                       value="<?= number_format($sBalance, 2, '.', '') ?>">
                            </div>
                            <button type="submit" class="btn">Settle &amp; discharge</button>
                            <?php if ($sApplied > 0.009): ?><div class="muted" style="font-size:11.5px;">Pre-filled with the balance after advances.</div><?php endif; ?>
                            <?php if (!$canApproveWriteoff): ?><div class="muted" style="font-size:11.5px;">A short payment needs an admin to approve the write-off.</div><?php endif; ?>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php elseif ($bill && $bill['status'] === 'paid'): ?>
                    <div class="card" style="margin-top:20px;">
                        <div class="muted">Bill settled <?= $bill['paid_at'] ? date('d/m/Y H:i', strtotime($bill['paid_at'])) : '' ?>. Patient discharged.</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
