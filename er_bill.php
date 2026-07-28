<?php
// ER Service walk-in bill — a lightweight PREPAID charge for an ultra-short ER
// touchpoint (injection / nebulization / dressing). NOT an admission: no stay
// clock, no room charge, nothing left open. Reception picks a registered patient
// and one or more services from er_services_master, then raises + settles the
// bill in ONE transaction (mirrors create_bill_for_visit). The "E" invoice series
// and er_bills/er_bill_items tables are its own (see sql/add_er_bills.sql).
//
// Requires sql/add_er_bills.sql to be applied.

require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/billing.php';
require_once __DIR__ . '/config/notify.php';
require_once __DIR__ . '/config/sheets.php';
// Attending doctor is mandatory on an ER bill — shared resolver + last-seen default.
require_once __DIR__ . '/config/doctors.php';
refresh_session_permissions($pdo);
require_permission('RECEPTION_RAISE_ER_BILL');

$error = '';
$success = '';

// ---------------- Print view (A5/A4 ER slip) ----------------
if (isset($_GET['print']) && isset($_GET['er_bill_id'])) {
    $erBillId = (int) $_GET['er_bill_id'];

    $stmt = $pdo->prepare('
        SELECT e.*, p.mrn, p.name AS patient_name, p.father_name, p.dob, p.phone,
               gen.name AS generated_by_name,
               -- Attending doctor: a system user, else the typed name.
               COALESCE(du.name, e.doctor_manual) AS doctor_name
        FROM er_bills e
        JOIN patients p ON p.id = e.patient_id
        JOIN users gen ON gen.id = e.created_by_id
        LEFT JOIN users du ON du.id = e.doctor_id
        WHERE e.id = ?
    ');
    $stmt->execute([$erBillId]);
    $bill = $stmt->fetch();

    if (!$bill) {
        http_response_code(404);
        die('ER bill not found.');
    }

    $itemsStmt = $pdo->prepare('SELECT description, quantity, unit_rate, amount FROM er_bill_items WHERE er_bill_id = ? ORDER BY id');
    $itemsStmt->execute([$erBillId]);
    $items = $itemsStmt->fetchAll();

    $pdo->prepare('UPDATE er_bills SET printed_at = NOW(), printed_by_id = COALESCE(printed_by_id, ?) WHERE id = ?')
        ->execute([(int) $_SESSION['user_id'], $erBillId]);

    include __DIR__ . '/views/er_invoice_print_partial.php';
    exit;
}

// ---------------- Void an ER bill (admin-assignable permission) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void_er_bill') {
    if (!has_permission('FINANCIAL_VOID_BILL')) {
        http_response_code(403);
        exit('You do not have permission to void an ER bill.');
    }
    $rid = (int) ($_POST['er_bill_id'] ?? 0);
    [$ok, $msg] = void_er_bill($pdo, $rid, (int) $_SESSION['user_id'], $_POST['void_reason'] ?? '');
    if ($ok) { $success = $msg; } else { $error = $msg; }
}

// ---------------- Raise + settle an ER bill (prepaid, one transaction) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'raise_er_bill') {
    $patientId = (int) ($_POST['patient_id'] ?? 0);
    $paymentMode = $_POST['payment_mode'] ?? '';       // CASH | DIGITAL
    $serviceIds = $_POST['service_id'] ?? [];          // parallel arrays
    $quantities = $_POST['quantity'] ?? [];
    // A system doctor, or a typed name when "Other" was picked. Mandatory: an ER
    // bill that can't say which doctor the service was given under is unauditable.
    [$erDocId, $erDocManual] = resolve_doctor_pick($pdo, $_POST['doctor_id'] ?? 0, $_POST['doctor_manual'] ?? '');

    // Settling a bill moves money — refuse once the cashier's shift is closed.
    $dayLock = require_day_open($pdo);

    $paymentMethod = $paymentMode === 'CASH' ? 'cash' : ($paymentMode === 'DIGITAL' ? 'card' : null);

    // Build the priced line set from the posted services, snapshotting rate + name.
    $lines = [];
    if (is_array($serviceIds)) {
        foreach ($serviceIds as $i => $sid) {
            $sid = (int) $sid;
            if ($sid <= 0) {
                continue;
            }
            $qty = max(1, (int) ($quantities[$i] ?? 1));
            $svcStmt = $pdo->prepare("SELECT id, service_name, charge_type, base_charge FROM er_services_master WHERE id = ? AND status = 'ACTIVE'");
            $svcStmt->execute([$sid]);
            $svc = $svcStmt->fetch();
            if (!$svc) {
                continue;   // inactive/removed service — skip silently
            }
            // ER walk-ins are quantity-based (FLAT/PER_UNIT); HOURLY has no duration here.
            $amount = admission_service_charge($svc['charge_type'], (float) $svc['base_charge'], $qty, null);
            $lines[] = [
                'id'    => $sid,
                'name'  => $svc['service_name'],
                'qty'   => $qty,
                'rate'  => (float) $svc['base_charge'],
                'amount'=> $amount,
            ];
        }
    }

    $patStmt = $pdo->prepare('SELECT id, name FROM patients WHERE id = ?');
    $patStmt->execute([$patientId]);
    $patient = $patStmt->fetch();

    if ($dayLock) {
        $error = $dayLock;
    } elseif (!$patient) {
        $error = 'Select a registered patient first.';
    } elseif (!$erDocId && !$erDocManual) {
        $error = 'Select the doctor for this ER service — pick "Other" to type a name.';
    } elseif (!$paymentMethod) {
        $error = 'Choose how the patient is paying (Cash or Online).';
    } elseif (!$lines) {
        $error = 'Add at least one ER service to the bill.';
    } else {
        try {
            $pdo->beginTransaction();

            $subtotal = round(array_sum(array_column($lines, 'amount')), 2);
            // Optional patient discount category (ER rate). Generic "Discount" on
            // the slip; category is internal-only (see discount categories).
            $cat = patient_discount_category($pdo, $patientId);
            $discountPct = $cat ? (float) $cat['er_services_pct'] : 0.0;
            $grandTotal = round($subtotal * (1 - $discountPct / 100), 2);
            $status = $grandTotal > 0 ? 'paid' : 'waived';

            $invoiceNumber = generate_er_invoice_number($pdo);

            $pdo->prepare('
                INSERT INTO er_bills
                    (invoice_number, patient_id, doctor_id, doctor_manual, subtotal, grand_total, status,
                     payment_method, paid_amount, paid_at, paid_by_id, created_by_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)
            ')->execute([
                $invoiceNumber, $patientId, $erDocId, $erDocManual, $subtotal, $grandTotal, $status,
                $paymentMethod, $grandTotal, (int) $_SESSION['user_id'], (int) $_SESSION['user_id'],
            ]);
            $erBillId = (int) $pdo->lastInsertId();

            $itemIns = $pdo->prepare('
                INSERT INTO er_bill_items (er_bill_id, er_service_id, description, quantity, unit_rate, amount)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            foreach ($lines as $ln) {
                // If a category discount applies, spread it proportionally onto the
                // line amounts so the item sum matches grand_total (generic label).
                $lineAmount = $discountPct > 0 ? round($ln['amount'] * (1 - $discountPct / 100), 2) : $ln['amount'];
                $itemIns->execute([$erBillId, $ln['id'], $ln['name'], $ln['qty'], $ln['rate'], $lineAmount]);
            }

            audit_log($pdo, 'er_bill_raised', "ER bill $invoiceNumber for patient #$patientId: Rs " . number_format($grandTotal, 2) . ' (' . count($lines) . ' service' . (count($lines) > 1 ? 's' : '') . ", $paymentMethod)", (int) $_SESSION['user_id']);

            $pdo->commit();

            // Best-effort Google-Sheet log row, after commit — never block reception.
            if (function_exists('sheet_push')) {
                try { sheet_push($pdo, 'ER_SERVICE', $erBillId, (int) $_SESSION['user_id']); } catch (Throwable $e) { /* ignore */ }
            }

            header('Location: er_bill.php?print=1&er_bill_id=' . $erBillId);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[er_bill] ' . $e->getMessage());
            $error = 'Could not raise the ER bill. Please try again.';
        }
    }
}

// ---------------- Page data ----------------
// Patient search (reuse the reception name/phone/father/MRN LIKE pattern).
$q = trim($_GET['q'] ?? '');
$patientResults = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $searchStmt = $pdo->prepare('
        SELECT id, mrn, name, father_name, phone, dob
        FROM patients
        WHERE name LIKE ? OR phone LIKE ? OR father_name LIKE ? OR mrn LIKE ?
        ORDER BY name LIMIT 25
    ');
    $searchStmt->execute([$like, $like, $like, $like]);
    $patientResults = $searchStmt->fetchAll();
}

// A pre-selected patient (from the search list "Select" link, or kept across a
// failed POST so the cashier doesn't lose their place).
$selectedPatient = null;
$selectedId = (int) ($_GET['patient_id'] ?? $_POST['patient_id'] ?? 0);
if ($selectedId > 0) {
    $spStmt = $pdo->prepare('SELECT id, mrn, name, father_name, phone, dob, discount_category_id FROM patients WHERE id = ?');
    $spStmt->execute([$selectedId]);
    $selectedPatient = $spStmt->fetch() ?: null;
}
$selectedCat = ($selectedPatient) ? patient_discount_category($pdo, $selectedId) : null;

// Active ER services catalogue for the picker.
$services = $pdo->query("SELECT id, service_name, service_type, charge_type, base_charge
                         FROM er_services_master WHERE status = 'ACTIVE'
                         ORDER BY service_type, service_name")->fetchAll();

// Doctor picker: mandatory, defaulting to the doctor who last saw this patient.
// On a failed POST keep what the cashier had chosen rather than resetting to the
// default, so their correction isn't silently undone.
$erDoctors = $pdo->query("SELECT id, name FROM users WHERE base_role = 'DOCTOR' AND is_active = 1 ORDER BY name")->fetchAll();
$erSelDoctorId = (int) ($_POST['doctor_id'] ?? 0);
$erSelDoctorManual = trim((string) ($_POST['doctor_manual'] ?? ''));
if (!$erSelDoctorId && $erSelDoctorManual === '' && $selectedPatient) {
    $erSelDoctorId = (int) (last_seen_doctor_id($pdo, $selectedId) ?? 0);
}

// Recent ER bills for context (voidable inline for admins).
$canVoid = has_permission('FINANCIAL_VOID_BILL');
$recent = $pdo->query("
    SELECT e.id, e.invoice_number, e.grand_total, e.payment_method, e.status,
           e.created_at, e.voided_at, p.name AS patient_name, p.mrn
    FROM er_bills e JOIN patients p ON p.id = e.patient_id
    ORDER BY e.id DESC LIMIT 12
")->fetchAll();

$pageTitle = 'ER Service';
$headExtra = <<<CSS
<style>
.er-shell { display: grid; grid-template-columns: 1.15fr .85fr; gap: 22px; align-items: start; }
@media (max-width: 980px) { .er-shell { grid-template-columns: 1fr; } }
.er-col { display: flex; flex-direction: column; gap: 20px; }
.card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-card); padding: 20px 22px; box-shadow: var(--shadow-sm); }
.card h2 { font-size: 15px; font-weight: 700; margin: 0 0 4px; }
.card .sub { font-size: 12.5px; color: var(--text-muted); margin: 0 0 14px; }
.searchbar { display: flex; gap: 10px; }
.searchbar input { flex: 1; padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-input); font: inherit; font-size: 14px; background: var(--bg); color: var(--text); }
.searchbar button { padding: 10px 16px; border: none; border-radius: var(--radius-btn); background: var(--primary-dark); color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
.plist { list-style: none; margin: 12px 0 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.plist li { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-input); }
.plist .who { font-size: 13.5px; font-weight: 600; }
.plist .meta { font-size: 12px; color: var(--text-muted); margin-top: 1px; }
.plist a.pick { font-size: 12.5px; font-weight: 600; color: var(--primary-dark); text-decoration: none; border: 1px solid var(--primary); padding: 6px 12px; border-radius: 999px; }
.selpat { display: flex; align-items: center; justify-content: space-between; gap: 12px; background: var(--primary-light); border: 1px solid transparent; border-radius: var(--radius-input); padding: 12px 14px; }
.selpat .who { font-weight: 700; font-size: 14.5px; color: var(--primary-dark); }
.selpat .meta { font-size: 12px; color: var(--primary-dark); opacity: .85; margin-top: 2px; }
.selpat a { font-size: 12px; color: var(--primary-dark); text-decoration: underline; }
.svc-add { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
.svc-add .fld { flex: 1; min-width: 160px; display: flex; flex-direction: column; gap: 5px; }
.svc-add label { font-size: 11.5px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--text-muted); }
.svc-add select, .svc-add input { padding: 9px 11px; border: 1px solid var(--border); border-radius: var(--radius-input); font: inherit; font-size: 14px; background: var(--bg); color: var(--text); }
.svc-add .addbtn { padding: 9px 14px; border: 1px dashed var(--primary); background: var(--primary-light); color: var(--primary-dark); border-radius: var(--radius-input); font: inherit; font-weight: 600; cursor: pointer; white-space: nowrap; }
.ltable { width: 100%; border-collapse: collapse; margin-top: 14px; font-variant-numeric: tabular-nums; }
.ltable th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); padding: 6px 8px; border-bottom: 1px solid var(--border); }
.ltable td { padding: 8px; border-bottom: 1px solid var(--border); font-size: 13.5px; }
.ltable td.num, .ltable th.num { text-align: right; }
.ltable .rm { color: var(--red); cursor: pointer; font-weight: 700; border: none; background: none; font-size: 15px; }
.ltable .empty td { color: var(--text-muted); text-align: center; padding: 18px; font-size: 13px; }
.totbar { display: flex; align-items: baseline; justify-content: space-between; margin-top: 14px; padding-top: 12px; border-top: 2px solid var(--border-strong); }
.totbar .lbl { font-size: 13px; font-weight: 600; color: var(--text-secondary); }
.totbar .amt { font-size: 24px; font-weight: 700; font-variant-numeric: tabular-nums; }
.paymodes { display: flex; gap: 10px; margin-top: 16px; }
.paymode { flex: 1; }
.paymode input { position: absolute; opacity: 0; }
.paymode label { display: block; text-align: center; padding: 12px; border: 1.5px solid var(--border); border-radius: var(--radius-input); font-weight: 600; font-size: 14px; cursor: pointer; }
.paymode input:checked + label { border-color: var(--primary); background: var(--primary-light); color: var(--primary-dark); }
.raisebtn { margin-top: 18px; width: 100%; padding: 13px; border: none; border-radius: var(--radius-btn); background: linear-gradient(135deg, var(--primary-dark), var(--primary)); color: #fff; font: inherit; font-weight: 600; font-size: 15px; cursor: pointer; }
.raisebtn[disabled] { opacity: .5; cursor: not-allowed; }
.disc-note { font-size: 12px; color: var(--primary-dark); margin-top: 8px; }
.rtable { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
.rtable th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); padding: 6px 8px; border-bottom: 1px solid var(--border); }
.rtable td { padding: 8px; border-bottom: 1px solid var(--border); font-size: 13px; }
.rtable td.num { text-align: right; }
.rtable a { color: var(--primary-dark); text-decoration: underline; font-weight: 600; }
.rpill { font-size: 10.5px; font-weight: 700; text-transform: uppercase; padding: 2px 8px; border-radius: 999px; }
.rpill.cash { background: var(--green-bg); color: var(--green-text); }
.rpill.online { background: var(--primary-light); color: var(--primary-dark); }
.rpill.void { background: var(--red-bg); color: var(--red-text); }
.alert { padding: 12px 16px; border-radius: var(--radius-input); font-size: 13px; }
.alert.error { background: var(--red-bg); color: var(--red-text); }
.alert.success { background: var(--green-bg); color: var(--green-text); }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'er_bill';
require __DIR__ . '/partials/sidebar.php';
?>
        <?php require __DIR__ . '/partials/quick_header.php'; ?>

        <div class="content">

            <div class="page-head">
                <h1>ER Service &mdash; walk-in bill</h1>
                <p>A quick injection, nebulization or dressing. Charge the service and collect payment — no admission, no room, no stay clock.</p>
            </div>

            <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <div class="er-shell">

                <!-- ==== LEFT: patient + services + pay ==== -->
                <div class="er-col">

                    <?php if (!$selectedPatient): ?>
                    <div class="card">
                        <h2>1 &middot; Find the patient</h2>
                        <p class="sub">ER bills attach to a registered patient. Search by name, phone, father's name, or MRN.</p>
                        <form method="GET" action="er_bill.php" class="searchbar">
                            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search patients…" autofocus>
                            <button type="submit">Search</button>
                        </form>
                        <?php if ($q !== '' && !$patientResults): ?>
                            <p class="sub" style="margin-top:12px;">No patient matches “<?= htmlspecialchars($q) ?>”. <a href="patients.php?register=1" style="color:var(--primary-dark);text-decoration:underline;">Register a new patient</a> first.</p>
                        <?php endif; ?>
                        <?php if ($patientResults): ?>
                        <ul class="plist">
                            <?php foreach ($patientResults as $p): ?>
                            <li>
                                <div>
                                    <div class="who"><?= htmlspecialchars($p['name']) ?></div>
                                    <div class="meta">MR <?= htmlspecialchars($p['mrn']) ?><?= $p['father_name'] ? ' · ' . htmlspecialchars($p['father_name']) : '' ?><?= $p['phone'] ? ' · ' . htmlspecialchars($p['phone']) : '' ?></div>
                                </div>
                                <a class="pick" href="er_bill.php?patient_id=<?= (int) $p['id'] ?>">Select</a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>

                    <div class="card">
                        <h2>1 &middot; Patient</h2>
                        <div class="selpat">
                            <div>
                                <div class="who"><?= htmlspecialchars($selectedPatient['name']) ?></div>
                                <div class="meta">MR <?= htmlspecialchars($selectedPatient['mrn']) ?>
                                    <?= $selectedPatient['father_name'] ? ' · ' . htmlspecialchars($selectedPatient['father_name']) : '' ?>
                                    <?= $selectedPatient['dob'] ? ' · ' . date('d/m/Y', strtotime($selectedPatient['dob'])) : '' ?>
                                    <?= $selectedPatient['phone'] ? ' · ' . htmlspecialchars($selectedPatient['phone']) : '' ?>
                                </div>
                            </div>
                            <a href="er_bill.php">Change</a>
                        </div>
                        <?php if ($selectedCat): ?>
                        <p class="disc-note"><?= htmlspecialchars($selectedCat['name']) ?> discount (<?= rtrim(rtrim(number_format((float) $selectedCat['er_services_pct'], 2), '0'), '.') ?>%) will apply to ER services.</p>
                        <?php endif; ?>
                    </div>

                    <form method="POST" action="er_bill.php" id="erForm">
                    <input type="hidden" name="action" value="raise_er_bill">
                    <input type="hidden" name="patient_id" value="<?= (int) $selectedPatient['id'] ?>">

                    <!-- Attending doctor — mandatory, defaulted to the doctor who
                         last saw this patient. "Other" reveals the free-text box
                         for a visiting/locum doctor who isn't a system user. -->
                    <div class="card">
                        <h2>2 &middot; Doctor</h2>
                        <p class="sub">Who is this ER service being given under? Defaults to the patient's last-seen doctor.</p>
                        <div class="fld">
                            <label for="erDoctor">Doctor <span style="color:var(--red,#dc2626);">*</span></label>
                            <select id="erDoctor" name="doctor_id" required>
                                <option value="">Select doctor&hellip;</option>
                                <?php foreach ($erDoctors as $d): ?>
                                <option value="<?= (int) $d['id'] ?>" <?= $erSelDoctorId === (int) $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                                <?php endforeach; ?>
                                <option value="__OTHER__" <?= ($erSelDoctorManual !== '' && !$erSelDoctorId) ? 'selected' : '' ?>>Other (type a name)&hellip;</option>
                            </select>
                            <input type="text" id="erDoctorManual" name="doctor_manual" class="uc" placeholder="Doctor's name"
                                   value="<?= htmlspecialchars($erSelDoctorManual) ?>" style="margin-top:8px;"
                                   <?= ($erSelDoctorManual !== '' && !$erSelDoctorId) ? '' : 'hidden' ?>>
                        </div>
                    </div>

                    <div class="card">
                        <h2>3 &middot; Add services</h2>
                        <p class="sub">Pick a service and quantity, then Add. Repeat for more than one.</p>
                        <div class="svc-add">
                            <div class="fld">
                                <label for="svcPick">Service</label>
                                <select id="svcPick">
                                    <option value="">Select a service…</option>
                                    <?php foreach ($services as $s): ?>
                                        <option value="<?= (int) $s['id'] ?>" data-rate="<?= htmlspecialchars($s['base_charge']) ?>" data-name="<?= htmlspecialchars($s['service_name']) ?>">
                                            <?= htmlspecialchars($s['service_name']) ?> — Rs <?= number_format((float) $s['base_charge'], 0) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="fld" style="max-width:110px;">
                                <label for="svcQty">Qty</label>
                                <input id="svcQty" type="number" min="1" step="1" value="1">
                            </div>
                            <button type="button" class="addbtn" id="addSvc">+ Add</button>
                        </div>

                        <table class="ltable" id="lineTable">
                            <thead><tr><th>Service</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th><th></th></tr></thead>
                            <tbody id="lineBody">
                                <tr class="empty" id="emptyRow"><td colspan="5">No services added yet.</td></tr>
                            </tbody>
                        </table>

                        <div class="totbar">
                            <span class="lbl">Total to collect</span>
                            <span class="amt" id="totalAmt">Rs 0</span>
                        </div>
                        <?php if ($selectedCat): ?>
                        <p class="disc-note">Total shown is before the <?= htmlspecialchars($selectedCat['name']) ?> discount, which is applied when the bill is raised.</p>
                        <?php endif; ?>
                    </div>

                    <div class="card">
                        <h2>4 &middot; Payment</h2>
                        <p class="sub">Collected now, before treatment.</p>
                        <div class="paymodes">
                            <div class="paymode">
                                <input type="radio" name="payment_mode" id="payCash" value="CASH">
                                <label for="payCash">Cash</label>
                            </div>
                            <div class="paymode">
                                <input type="radio" name="payment_mode" id="payDigital" value="DIGITAL">
                                <label for="payDigital">Online / Card</label>
                            </div>
                        </div>
                        <button type="submit" class="raisebtn" id="raiseBtn" data-busy="Raising…" disabled>Raise bill &amp; print slip</button>
                    </div>
                    </form>

                    <?php endif; ?>

                </div>

                <!-- ==== RIGHT: recent ER bills ==== -->
                <div class="er-col">
                    <div class="card">
                        <h2>Recent ER bills</h2>
                        <p class="sub">Newest first. Click an invoice to reprint.</p>
                        <?php if (!$recent): ?>
                            <p class="sub">No ER bills yet.</p>
                        <?php else: ?>
                        <table class="rtable">
                            <thead><tr><th>Invoice</th><th>Patient</th><th class="num">Amount</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($recent as $r): $isVoid = !empty($r['voided_at']); ?>
                                <tr<?= $isVoid ? ' style="opacity:.55;"' : '' ?>>
                                    <td>
                                        <a href="er_bill.php?print=1&er_bill_id=<?= (int) $r['id'] ?>" target="_blank"><?= htmlspecialchars($r['invoice_number']) ?></a>
                                        <div style="font-size:11px;color:var(--text-muted);"><?= date('d/m H:i', strtotime($r['created_at'])) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($r['patient_name']) ?><div style="font-size:11px;color:var(--text-muted);">MR <?= htmlspecialchars($r['mrn']) ?></div></td>
                                    <td class="num">
                                        Rs <?= number_format((float) $r['grand_total'], 0) ?><br>
                                        <?php if ($isVoid): ?>
                                            <span class="rpill void">Void</span>
                                        <?php else: ?>
                                            <span class="rpill <?= $r['payment_method'] === 'cash' ? 'cash' : 'online' ?>"><?= $r['payment_method'] === 'cash' ? 'Cash' : 'Online' ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($canVoid && !$isVoid): ?>
                                        <form method="POST" action="er_bill.php" data-no-once
                                              onsubmit="var r=prompt('Void ER bill <?= htmlspecialchars($r['invoice_number'], ENT_QUOTES) ?>? This removes it from all totals.\n\nReason:'); if(!r){return false;} this.void_reason.value=r; return true;">
                                            <input type="hidden" name="action" value="void_er_bill">
                                            <input type="hidden" name="er_bill_id" value="<?= (int) $r['id'] ?>">
                                            <input type="hidden" name="void_reason" value="">
                                            <button type="submit" style="border:none;background:none;color:var(--red);font-size:12px;font-weight:600;cursor:pointer;">Void</button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

        </div>
</div></div><!-- .main + .app -->

<script>
// Service line builder — client-side list, posted as parallel service_id[]/quantity[] arrays.
(function () {
    var pick = document.getElementById('svcPick');
    if (!pick) return;   // no patient selected yet
    var qtyIn = document.getElementById('svcQty');
    var addBtn = document.getElementById('addSvc');
    var body = document.getElementById('lineBody');
    var emptyRow = document.getElementById('emptyRow');
    var totalEl = document.getElementById('totalAmt');
    var raiseBtn = document.getElementById('raiseBtn');
    var lines = [];

    function fmt(n) { return 'Rs ' + n.toLocaleString('en-PK', {maximumFractionDigits: 0}); }

    function render() {
        // clear line rows (keep the empty placeholder element)
        body.querySelectorAll('tr.line').forEach(function (tr) { tr.remove(); });
        var total = 0;
        lines.forEach(function (ln, idx) {
            total += ln.amount;
            var tr = document.createElement('tr');
            tr.className = 'line';
            tr.innerHTML =
                '<td>' + ln.name +
                    '<input type="hidden" name="service_id[]" value="' + ln.id + '">' +
                    '<input type="hidden" name="quantity[]" value="' + ln.qty + '"></td>' +
                '<td class="num">' + ln.qty + '</td>' +
                '<td class="num">' + fmt(ln.rate) + '</td>' +
                '<td class="num">' + fmt(ln.amount) + '</td>' +
                '<td><button type="button" class="rm" data-i="' + idx + '" aria-label="Remove">&times;</button></td>';
            body.appendChild(tr);
        });
        emptyRow.style.display = lines.length ? 'none' : '';
        totalEl.textContent = fmt(total);
        raiseBtn.disabled = lines.length === 0;
    }

    addBtn.addEventListener('click', function () {
        var opt = pick.options[pick.selectedIndex];
        if (!opt || !opt.value) { pick.focus(); return; }
        var qty = Math.max(1, parseInt(qtyIn.value, 10) || 1);
        var rate = parseFloat(opt.dataset.rate) || 0;
        lines.push({ id: opt.value, name: opt.dataset.name, qty: qty, rate: rate, amount: rate * qty });
        pick.selectedIndex = 0; qtyIn.value = 1;
        render();
    });

    body.addEventListener('click', function (e) {
        var btn = e.target.closest('.rm');
        if (!btn) return;
        lines.splice(parseInt(btn.dataset.i, 10), 1);
        render();
    });

    render();
})();

/* ---------------------------------------------------------------------
   Attending doctor: "Other" reveals the free-text box. The sentinel value
   is stripped on submit so the handler sees an empty id and stores the
   typed name instead (mirrors the admit modal).
   ------------------------------------------------------------------- */
(function () {
    var sel = document.getElementById('erDoctor');
    var manual = document.getElementById('erDoctorManual');
    if (!sel || !manual) { return; }

    function sync() {
        var isOther = sel.value === '__OTHER__';
        manual.hidden = !isOther;
        manual.required = isOther;
        if (!isOther) { manual.value = ''; }
    }
    sel.addEventListener('change', sync);
    sync();

    if (sel.form) {
        sel.form.addEventListener('submit', function () {
            if (sel.value === '__OTHER__') { sel.disabled = true; }
        });
    }
})();
</script>
</body>
</html>
