<?php
/**
 * Procedure bill — a walk-in, prepaid charge for a one-off procedure
 * (ear piercing, dressing, minor suturing …).
 *
 * NOT an admission and NOT a consultation: no stay clock, no room, nothing left
 * open. Reception picks the patient, picks the performing doctor, adds one or
 * more procedures from THAT doctor's assigned list, takes payment, and the slip
 * prints. The whole thing settles in ONE transaction (mirrors er_bill.php).
 *
 * The catalogue lives in procedure_master + doctor_procedures (admin edits it on
 * procedure_master.php). Each line SNAPSHOTS the fee, the doctor's share %, and
 * the tax % at billing time, so re-pricing a procedure later never rewrites what
 * a doctor already earned — that snapshot is what doctor_share_statement.php
 * reads via doctor_procedure_earnings().
 *
 * Requires sql/add_procedure_bills.sql to be applied.
 */

require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/billing.php';
require_once __DIR__ . '/config/notify.php';
require_once __DIR__ . '/config/sheets.php';
// For dental_consent_satisfied() — the mandatory_consent gate below. Loading it
// here is what makes the gate active; without it the function_exists() guard
// falls through and this page warns rather than blocks, exactly as before.
require_once __DIR__ . '/config/dental.php';
// Procedure consent: the per-procedure template, the frozen consent row, and
// the sheets appended to the printed slip.
require_once __DIR__ . '/config/consent.php';
refresh_session_permissions($pdo);
require_permission('RECEPTION_RAISE_PROCEDURE_BILL');

$error = '';
$success = '';

// Has add_procedure_disposables.sql been run? The supplies-cost field, its
// column and its INSERT are all conditional on it, so an un-migrated database
// bills exactly as it did before instead of fataling on a missing column.
// BOTH halves are required here: the flag decides which procedures show the
// field, the cost column is where the entered amount is stored — offering the
// field without somewhere to put it would silently discard what was typed.
$procHasDisposables = procedure_disposables_column($pdo) && procedure_disposables_flag($pdo);

// ---------------- Print view (A5 procedure slip) ----------------
if (isset($_GET['print']) && isset($_GET['procedure_bill_id'])) {
    $procBillId = (int) $_GET['procedure_bill_id'];

    $stmt = $pdo->prepare('
        SELECT pb.*, p.mrn, p.name AS patient_name, p.father_name, p.dob, p.phone,
               doc.name AS doctor_name, doc.specialty AS doctor_specialty,
               gen.name AS generated_by_name
        FROM procedure_bills pb
        JOIN patients p ON p.id = pb.patient_id
        JOIN users doc  ON doc.id = pb.doctor_id
        JOIN users gen  ON gen.id = pb.created_by_id
        WHERE pb.id = ?
    ');
    $stmt->execute([$procBillId]);
    $bill = $stmt->fetch();

    if (!$bill) {
        http_response_code(404);
        die('Procedure bill not found.');
    }

    $itemsStmt = $pdo->prepare('SELECT description, quantity, unit_rate, amount FROM procedure_bill_items WHERE procedure_bill_id = ? ORDER BY id');
    $itemsStmt->execute([$procBillId]);
    $items = $itemsStmt->fetchAll();

    // Consent sheets to append after the receipt. Empty when no procedure on
    // the bill carries a template, in which case the slip prints exactly as it
    // always did. A REPRINT reprints the consent too — that is deliberate: the
    // signed copy gets lost, and the patient copy walks out of the building.
    $consents = consent_for_bill($pdo, $procBillId);

    // First print only — printed_at is COALESCE'd like printed_by_id beside it, so
    // a reprint keeps the original's timestamp instead of advancing it to today
    // (config/reprint.php). $bill is read above, so this first print still renders
    // without the DUPLICATE mark. The appended consent sheets are unaffected: they
    // reprint in full, deliberately, as noted above.
    $pdo->prepare('UPDATE procedure_bills
                      SET printed_at    = COALESCE(printed_at, NOW()),
                          printed_by_id = COALESCE(printed_by_id, ?)
                    WHERE id = ?')
        ->execute([(int) $_SESSION['user_id'], $procBillId]);

    include __DIR__ . '/views/procedure_invoice_print_partial.php';
    exit;
}

// ---------------- Void a procedure bill (admin-assignable permission) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void_procedure_bill') {
    if (!has_permission('FINANCIAL_VOID_BILL')) {
        http_response_code(403);
        exit('You do not have permission to void a procedure bill.');
    }
    $rid = (int) ($_POST['procedure_bill_id'] ?? 0);
    [$ok, $msg] = void_procedure_bill($pdo, $rid, (int) $_SESSION['user_id'], $_POST['void_reason'] ?? '');
    if ($ok) { $success = $msg; } else { $error = $msg; }
}

// ---------------- Raise + settle a procedure bill (prepaid, one transaction) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'raise_procedure_bill') {
    $patientId   = (int) ($_POST['patient_id'] ?? 0);
    $doctorId    = (int) ($_POST['doctor_id'] ?? 0);
    $paymentMode = $_POST['payment_mode'] ?? '';        // CASH | DIGITAL
    $assignIds   = $_POST['assignment_id'] ?? [];       // parallel arrays
    $quantities  = $_POST['quantity'] ?? [];
    $disposables = $_POST['disposables'] ?? [];

    // Who is signing the consent. All optional: left empty the printed sheet
    // carries a blank rule for the parent to fill in by hand, which is how the
    // paper form has always worked.
    //
    // RELATION IS FREE TEXT, deliberately. It used to be validated against
    // consent_relations() and silently blanked when it did not match — which
    // meant a real answer the list did not anticipate ("UNCLE", "GRANDFATHER")
    // vanished without a word and the sheet printed an empty rule instead. The
    // clinic asked to type it. The cost is that the column now accepts
    // "father"/"Father"/"walid" as separate spellings; consent_create_for_bill()
    // applies mb_strtoupper() on the way in, which absorbs case but not
    // synonyms. Truncated to the column width so a paste cannot be cut off
    // mid-word by MySQL in a way nobody sees until the sheet prints.
    $signerName     = trim((string) ($_POST['consent_signer_name'] ?? ''));
    $signerRelation = mb_substr(trim((string) ($_POST['consent_signer_relation'] ?? '')), 0, 60);
    // CNIC is normalised to 00000-0000000-0 so the column holds one spelling.
    // A partial entry is kept as typed rather than discarded — see
    // consent_format_cnic(). Optional like the two above.
    $signerCnic = consent_format_cnic((string) ($_POST['consent_signer_cnic'] ?? ''));

    // Settling a bill moves money — refuse once the cashier's shift is closed.
    $dayLock = require_day_open($pdo);

    $paymentMethod = $paymentMode === 'CASH' ? 'cash' : ($paymentMode === 'DIGITAL' ? 'card' : null);

    // Build the priced line set. Every rate and share is re-read from the DB
    // here — never trusted from the POST — and snapshotted onto the line.
    $lines = [];
    if (is_array($assignIds)) {
        foreach ($assignIds as $i => $aid) {
            $aid = (int) $aid;
            if ($aid <= 0) {
                continue;
            }
            $qty = max(1, (int) ($quantities[$i] ?? 1));
            // Join through the assignment so a procedure can only be billed for
            // the doctor it is actually assigned to. COALESCE: a NULL override
            // means "charge the master's current rate".
            $rowStmt = $pdo->prepare("
                SELECT dp.id, dp.procedure_master_id, pm.name, pm.mandatory_consent,
                       COALESCE(dp.fee, pm.fee) AS fee,
                       dp.doctor_share_pct, dp.has_tax, dp.tax_percent" . ($procHasDisposables ? ",
                       pm.has_disposables" : '') . "
                FROM doctor_procedures dp
                JOIN procedure_master pm ON pm.id = dp.procedure_master_id
                WHERE dp.id = ? AND dp.doctor_id = ? AND dp.is_active = 1 AND pm.is_active = 1
            ");
            $rowStmt->execute([$aid, $doctorId]);
            $row = $rowStmt->fetch();
            if (!$row) {
                continue;   // unassigned/inactive/retired — skip silently
            }
            $rate = (float) $row['fee'];
            $amount = round($rate * $qty, 2);
            // Supplies cost is accepted ONLY for procedures the admin flagged,
            // and never more than the line is worth — a mis-keyed figure must
            // not drive the divisible amount negative. The flag is re-read from
            // the DB here, so a hand-crafted POST can't smuggle a cost onto an
            // unflagged procedure.
            $dispCost = 0.0;
            if ($procHasDisposables && (int) ($row['has_disposables'] ?? 0) === 1) {
                $dispCost = min($amount, max(0.0, (float) ($disposables[$i] ?? 0)));
            }
            $lines[] = [
                'master_id'  => (int) $row['procedure_master_id'],
                'name'       => $row['name'],
                'qty'        => $qty,
                'rate'       => $rate,
                'amount'     => $amount,
                'disp'       => $dispCost,
                'consent'    => (int) ($row['mandatory_consent'] ?? 0),
                'share_pct'  => (float) $row['doctor_share_pct'],
                'has_tax'    => (int) $row['has_tax'],
                'tax_pct'    => (float) $row['tax_percent'],
            ];
        }
    }

    // CONSENT GATE.
    //
    // The gate used to demand a consent that was already SIGNED, and a consent
    // only becomes SIGNED when the scanned paper is uploaded. That works for
    // dentistry, where the consent is captured at treatment planning — days
    // before anything is billed. It CANNOT work for a walk-in procedure: the
    // patient is at the desk, the consent sheet prints with the receipt, and it
    // is signed on the spot. Requiring a signed scan first made any procedure
    // with a consent template permanently unbillable.
    //
    // So the gate now splits on where the consent comes from:
    //
    //   - Procedure HAS a template  -> this page prints the consent itself, so
    //     there is nothing to wait for. Billing creates the PENDING consent and
    //     prints it; the signed scan is filed afterwards on the register.
    //
    //   - Procedure is flagged mandatory_consent but has NO template -> the
    //     consent lives elsewhere (the dental module's own capture page), and
    //     the original rule stands: a signed consent must already exist.
    //
    // Net effect: no procedure that could be billed yesterday is blocked today,
    // and no flagged procedure is ever billed with no consent recorded at all.
    if (!$error && function_exists('dental_consent_satisfied')) {
        foreach ($lines as $ln) {
            if (empty($ln['consent'])) {
                continue;
            }
            if (consent_template_for($pdo, (int) $ln['master_id']) !== null) {
                continue;   // printed here, with the receipt
            }
            if (!dental_consent_satisfied($pdo, $patientId, $ln['master_id'])) {
                $error = '“' . $ln['name'] . '” requires a signed consent before it can be billed. '
                       . 'Capture it on the Consent page, then bill this again.';
                break;
            }
        }
    }

    // mrn + father_name are here for the consent sheet, which names the patient
    // as "<name> S/O <father_name>" and prints the MR number in its header.
    $patStmt = $pdo->prepare('SELECT id, name, mrn, father_name FROM patients WHERE id = ?');
    $patStmt->execute([$patientId]);
    $patient = $patStmt->fetch();

    $docStmt = $pdo->prepare("SELECT id, name FROM users WHERE id = ? AND base_role = 'DOCTOR'");
    $docStmt->execute([$doctorId]);
    $doctor = $docStmt->fetch();

    // $error may already be set by the consent gate above — this chain must not
    // overwrite it, or a consent-blocked bill would fall through and be raised.
    if ($error) {
        // keep it
    } elseif ($dayLock) {
        $error = $dayLock;
    } elseif (!$patient) {
        $error = 'Select a registered patient first.';
    } elseif (!$doctor) {
        $error = 'Select the doctor performing the procedure.';
    } elseif (!$paymentMethod) {
        $error = 'Choose how the patient is paying (Cash or Online).';
    } elseif (!$lines) {
        $error = 'Add at least one procedure to the bill.';
    } else {
        try {
            $pdo->beginTransaction();

            $subtotal = round(array_sum(array_column($lines, 'amount')), 2);
            // Optional patient discount category (procedures rate). The slip
            // shows a generic "Discount"; the category name stays internal.
            $cat = patient_discount_category($pdo, $patientId);
            $discountPct = $cat ? (float) $cat['procedures_pct'] : 0.0;
            $grandTotal = round($subtotal * (1 - $discountPct / 100), 2);
            $discountAmount = round($subtotal - $grandTotal, 2);
            $status = $grandTotal > 0 ? 'paid' : 'waived';

            $invoiceNumber = generate_procedure_invoice_number($pdo);

            $pdo->prepare('
                INSERT INTO procedure_bills
                    (invoice_number, patient_id, doctor_id, subtotal, discount_pct, discount_amount,
                     grand_total, status, payment_method, paid_amount, paid_at, paid_by_id, created_by_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)
            ')->execute([
                $invoiceNumber, $patientId, $doctorId, $subtotal, $discountPct, $discountAmount,
                $grandTotal, $status, $paymentMethod, $grandTotal,
                (int) $_SESSION['user_id'], (int) $_SESSION['user_id'],
            ]);
            $procBillId = (int) $pdo->lastInsertId();

            $itemIns = $procHasDisposables
                ? $pdo->prepare('
                    INSERT INTO procedure_bill_items
                        (procedure_bill_id, procedure_master_id, description, quantity, unit_rate, amount,
                         disposables_cost, doctor_share_pct, has_tax, tax_percent)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                  ')
                : $pdo->prepare('
                    INSERT INTO procedure_bill_items
                        (procedure_bill_id, procedure_master_id, description, quantity, unit_rate, amount,
                         doctor_share_pct, has_tax, tax_percent)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                  ');
            foreach ($lines as $ln) {
                // Spread the category discount proportionally across the lines so
                // SUM(amount) always re-sums to grand_total — the doctor's share
                // is then computed on what was ACTUALLY collected, not list price.
                $lineAmount = $discountPct > 0 ? round($ln['amount'] * (1 - $discountPct / 100), 2) : $ln['amount'];
                // The supplies cost is NOT discounted — the clinic paid the same
                // for them whoever the patient is. But a discount can drop the
                // line below the cost, so re-clamp: the deduction can never
                // exceed what was actually collected on this line.
                $lineDisp = min($lineAmount, (float) ($ln['disp'] ?? 0));
                $params = [$procBillId, $ln['master_id'], $ln['name'], $ln['qty'], $ln['rate'], $lineAmount];
                if ($procHasDisposables) { $params[] = $lineDisp; }
                array_push($params, $ln['share_pct'], $ln['has_tax'], $ln['tax_pct']);
                $itemIns->execute($params);
            }

            // Consent rows for any line that carries a template, created INSIDE
            // the transaction: a consent must never survive a bill that rolled
            // back, and a bill must never commit having failed to record the
            // consent it is about to print. The wording is frozen onto the row
            // here — see consent_create_for_bill().
            $consentsCreated = consent_create_for_bill(
                $pdo, $procBillId, $patientId, $doctorId, $lines,
                $patient, (string) $doctor['name'], (int) $_SESSION['user_id'],
                $signerName, $signerRelation, $signerCnic
            );

            audit_log($pdo, 'procedure_bill_raised', "Procedure bill $invoiceNumber for patient #$patientId by {$doctor['name']}: Rs " . number_format($grandTotal, 2) . ' (' . count($lines) . ' procedure' . (count($lines) > 1 ? 's' : '') . ", $paymentMethod)", (int) $_SESSION['user_id']);

            if ($consentsCreated > 0) {
                // The CNIC itself is deliberately NOT written to the audit trail:
                // it already lives on the consent row, and the audit log is read
                // far more widely than the consent register. Recording only
                // whether one was captured keeps the trail useful without
                // copying an identifying number into a second place.
                audit_log($pdo, 'procedure_consent_created', "$consentsCreated consent form(s) generated for procedure bill $invoiceNumber (patient #$patientId)" . ($signerName !== '' ? ", signer: $signerName" . ($signerRelation !== '' ? " ($signerRelation)" : '') . ($signerCnic !== '' ? ', CNIC on file' : '') : ', signer to be filled by hand'), (int) $_SESSION['user_id']);
            }

            $pdo->commit();

            // Best-effort Sheet log after commit — never block reception.
            if (function_exists('sheet_push')) {
                try { sheet_push($pdo, 'PROCEDURE', $procBillId, (int) $_SESSION['user_id']); } catch (Throwable $e) { /* ignore */ }
            }

            header('Location: procedure_bill.php?print=1&procedure_bill_id=' . $procBillId);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[procedure_bill] ' . $e->getMessage());
            $error = 'Could not raise the procedure bill. Please try again.';
        }
    }
}

// ---------------- Page data ----------------
// Patient search (same name/phone/father/MRN LIKE pattern as reception).
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

// Pre-selected patient (from the search list, the patients.php Procedure button,
// or kept across a failed POST so the cashier doesn't lose their place).
$selectedPatient = null;
$selectedId = (int) ($_GET['patient_id'] ?? $_POST['patient_id'] ?? 0);
if ($selectedId > 0) {
    $spStmt = $pdo->prepare('SELECT id, mrn, name, father_name, phone, dob, discount_category_id FROM patients WHERE id = ?');
    $spStmt->execute([$selectedId]);
    $selectedPatient = $spStmt->fetch() ?: null;
}
$selectedCat = ($selectedPatient) ? patient_discount_category($pdo, $selectedId) : null;

// Doctors who actually have at least one active procedure assigned — a doctor
// with an empty list would be a dead end in the picker.
$procDoctors = $pdo->query("
    SELECT DISTINCT u.id, u.name
    FROM users u
    JOIN doctor_procedures dp ON dp.doctor_id = u.id AND dp.is_active = 1
    JOIN procedure_master pm ON pm.id = dp.procedure_master_id AND pm.is_active = 1
    WHERE u.base_role = 'DOCTOR' AND u.is_active = 1
    ORDER BY u.name
")->fetchAll();

// Every active assignment, grouped by doctor for the client-side picker.
// COALESCE(dp.fee, pm.fee): NULL override = the master's current rate.
// Does this database have consent templates yet? Everything consent-related in
// the form below is conditional on it.
$procHasConsentTpl = consent_column_live($pdo);
$assignRows = $pdo->query("
    SELECT dp.id, dp.doctor_id, pm.name, COALESCE(dp.fee, pm.fee) AS fee, pm.mandatory_consent"
    . ($procHasDisposables ? ", pm.has_disposables" : '')
    // TRIM + <> '': a template of whitespace is not a template. This mirrors
    // consent_template_for(), so the button the cashier sees and the sheet the
    // server prints agree on which procedures have a consent form.
    . ($procHasConsentTpl ? ", (pm.consent_template IS NOT NULL AND TRIM(pm.consent_template) <> '') AS has_tpl" : '') . "
    FROM doctor_procedures dp
    JOIN procedure_master pm ON pm.id = dp.procedure_master_id
    WHERE dp.is_active = 1 AND pm.is_active = 1
    ORDER BY pm.name
")->fetchAll();
$assignByDoctor = [];
foreach ($assignRows as $r) {
    $assignByDoctor[(int) $r['doctor_id']][] = [
        'id'      => (int) $r['id'],
        'name'    => $r['name'],
        'fee'     => (float) $r['fee'],
        'consent' => (int) $r['mandatory_consent'],
        // Drives whether the line gets a supplies-cost box. Server re-checks it
        // on submit, so this is a UI hint, not the authority.
        'disp'    => (int) ($r['has_disposables'] ?? 0),
        // Has a consent form that THIS page prints, as opposed to one captured
        // elsewhere. Decides which of the two consent messages the cashier sees.
        'tpl'     => (int) ($r['has_tpl'] ?? 0),
    ];
}

// A doctor pre-selected from the patient row (last treating doctor), if they
// happen to have procedures assigned.
$preDoctorId = (int) ($_GET['doctor_id'] ?? $_POST['doctor_id'] ?? 0);
if ($preDoctorId > 0 && !isset($assignByDoctor[$preDoctorId])) {
    $preDoctorId = 0;
}

// Recent procedure bills for context (voidable inline for admins).
$canVoid = has_permission('FINANCIAL_VOID_BILL');
$recent = $pdo->query("
    SELECT pb.id, pb.invoice_number, pb.grand_total, pb.payment_method, pb.status,
           pb.created_at, pb.voided_at, p.name AS patient_name, p.mrn, doc.name AS doctor_name
    FROM procedure_bills pb
    JOIN patients p ON p.id = pb.patient_id
    JOIN users doc  ON doc.id = pb.doctor_id
    ORDER BY pb.id DESC LIMIT 12
")->fetchAll();

$pageTitle = 'Procedure';
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
.docpick { width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-input); font: inherit; font-size: 14px; background: var(--bg); color: var(--text); }
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
.ltable .disp-in { width: 84px; padding: 5px 7px; border: 1px solid var(--border); border-radius: 8px; font: inherit; font-size: 13px; text-align: right; background: var(--bg); color: var(--text); }
.ltable .empty td { color: var(--text-muted); text-align: center; padding: 18px; font-size: 13px; }
.totbar { display: flex; align-items: baseline; justify-content: space-between; margin-top: 14px; padding-top: 12px; border-top: 2px solid var(--border-strong); }
.totbar .lbl { font-size: 13px; font-weight: 600; color: var(--text-secondary); }
.totbar .amt { font-size: 24px; font-weight: 700; font-variant-numeric: tabular-nums; }
.subline { display: flex; align-items: baseline; justify-content: space-between; margin-top: 10px; font-size: 13px; color: var(--text-muted); }
.paymodes { display: flex; gap: 10px; margin-top: 16px; }
.paymode { flex: 1; }
.paymode input { position: absolute; opacity: 0; }
.paymode label { display: block; text-align: center; padding: 12px; border: 1.5px solid var(--border); border-radius: var(--radius-input); font-weight: 600; font-size: 14px; cursor: pointer; }
.paymode input:checked + label { border-color: var(--primary); background: var(--primary-light); color: var(--primary-dark); }
.raisebtn { margin-top: 18px; width: 100%; padding: 13px; border: none; border-radius: var(--radius-btn); background: linear-gradient(135deg, var(--primary-dark), var(--primary)); color: #fff; font: inherit; font-weight: 600; font-size: 15px; cursor: pointer; }
.raisebtn[disabled] { opacity: .5; cursor: not-allowed; }
.disc-note { font-size: 12px; color: var(--primary-dark); margin-top: 8px; }
.consent-note { font-size: 12px; color: var(--amber-text, #92400e); background: var(--amber-bg, #fef3c7); border-radius: var(--radius-input); padding: 8px 11px; margin-top: 10px; }
/* Signer capture. Primary/sage rather than the amber above on purpose: this is
   not a warning. Nothing is blocked, the consent prints either way, and the
   fields only decide whether it prints pre-filled or with a rule to write on. */
.signer-box { margin-top: 10px; padding: 12px 13px; border: 1px solid var(--primary); background: var(--primary-light); border-radius: var(--radius-input); }
.signer-head { font-size: 12.5px; font-weight: 600; color: var(--primary-dark); margin-bottom: 10px; }
.signer-row { display: flex; gap: 12px; flex-wrap: wrap; }
.signer-row .fld { flex: 1 1 190px; min-width: 160px; }
.signer-box label .opt { font-weight: 400; text-transform: none; letter-spacing: 0; color: var(--text-muted); }
.signer-hint { font-size: 11.5px; color: var(--text-muted); margin: 8px 0 0; }
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
$navActive = 'procedure_bill';
require __DIR__ . '/partials/sidebar.php';
?>
        <?php require __DIR__ . '/partials/quick_header.php'; ?>

        <div class="content">

            <div class="page-head">
                <h1>Procedure &mdash; walk-in bill</h1>
                <p>A one-off procedure such as ear piercing or a dressing. Charge it and collect payment &mdash; no admission, no room, no stay clock.</p>
            </div>

            <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

            <div class="er-shell">

                <!-- ==== LEFT: patient + doctor + procedures + pay ==== -->
                <div class="er-col">

                    <?php if (!$selectedPatient): ?>
                    <div class="card">
                        <h2>1 &middot; Find the patient</h2>
                        <p class="sub">Procedure bills attach to a registered patient. Search by name, phone, father's name, or MRN.</p>
                        <form method="GET" action="procedure_bill.php" class="searchbar">
                            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search patients&hellip;" autofocus>
                            <button type="submit">Search</button>
                        </form>
                        <?php if ($q !== '' && !$patientResults): ?>
                            <p class="sub" style="margin-top:12px;">No patient matches &ldquo;<?= htmlspecialchars($q) ?>&rdquo;. <a href="patients.php?register=1" style="color:var(--primary-dark);text-decoration:underline;">Register a new patient</a> first.</p>
                        <?php endif; ?>
                        <?php if ($patientResults): ?>
                        <ul class="plist">
                            <?php foreach ($patientResults as $p): ?>
                            <li>
                                <div>
                                    <div class="who"><?= htmlspecialchars($p['name']) ?></div>
                                    <div class="meta">MR <?= htmlspecialchars($p['mrn']) ?><?= $p['father_name'] ? ' &middot; ' . htmlspecialchars($p['father_name']) : '' ?><?= $p['phone'] ? ' &middot; ' . htmlspecialchars($p['phone']) : '' ?></div>
                                </div>
                                <a class="pick" href="procedure_bill.php?patient_id=<?= (int) $p['id'] ?>">Select</a>
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
                                <div class="meta">MR <?= htmlspecialchars($selectedPatient['mrn']) ?><?= $selectedPatient['father_name'] ? ' &middot; ' . htmlspecialchars($selectedPatient['father_name']) : '' ?><?= $selectedPatient['phone'] ? ' &middot; ' . htmlspecialchars($selectedPatient['phone']) : '' ?></div>
                            </div>
                            <a href="procedure_bill.php">Change</a>
                        </div>
                    </div>

                    <form method="POST" action="procedure_bill.php" id="procForm">
                        <input type="hidden" name="action" value="raise_procedure_bill">
                        <input type="hidden" name="patient_id" value="<?= (int) $selectedPatient['id'] ?>">

                        <div class="card">
                            <h2>2 &middot; Who is performing it?</h2>
                            <p class="sub">The procedure list below is that doctor's assigned set, and the share is credited to them.</p>
                            <?php if (!$procDoctors): ?>
                            <p class="sub" style="margin:0;">No doctor has any procedure assigned yet. Set this up under <a href="procedure_master.php" style="color:var(--primary-dark);text-decoration:underline;">Procedures</a>.</p>
                            <?php else: ?>
                            <select name="doctor_id" id="docPick" class="docpick" required>
                                <option value="">Select the performing doctor&hellip;</option>
                                <?php foreach ($procDoctors as $d): ?>
                                <option value="<?= (int) $d['id'] ?>" <?= $preDoctorId === (int) $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>
                        </div>

                        <?php if ($procDoctors): ?>
                        <div class="card" style="margin-top:20px;">
                            <h2>3 &middot; Procedures</h2>
                            <p class="sub">Add each procedure performed. Rates come from the doctor's assigned list.</p>

                            <div class="svc-add">
                                <div class="fld">
                                    <label for="procPick">Procedure</label>
                                    <select id="procPick" disabled>
                                        <option value="">Select a doctor first&hellip;</option>
                                    </select>
                                </div>
                                <div class="fld" style="flex:0 0 90px;min-width:90px;">
                                    <label for="procQty">Qty</label>
                                    <input type="number" id="procQty" value="1" min="1" max="99">
                                </div>
                                <button type="button" class="addbtn" id="addProc">+ Add</button>
                            </div>

                            <div id="consentNote" class="consent-note" style="display:none;"></div>

                            <?php if ($procHasConsentTpl): ?>
                            <!-- Shown only when a procedure on the bill prints its own consent.
                                 Both fields are optional: left empty the sheet prints a blank
                                 rule for the parent to complete by hand, which is how the paper
                                 form has always worked. -->
                            <div id="signerBox" class="signer-box" style="display:none;">
                                <div class="signer-head">Consent form will print with the slip &mdash; sign it and keep it in the file</div>
                                <div class="signer-row">
                                    <div class="fld">
                                        <label for="signerName">Consent signed by <span class="opt">(optional)</span></label>
                                        <input type="text" name="consent_signer_name" id="signerName"
                                               maxlength="150" placeholder="e.g. HAMZA TARIQ" class="uc">
                                    </div>
                                    <div class="fld">
                                        <label for="signerRelation">Relation to patient <span class="opt">(optional)</span></label>
                                        <!-- Free text, not a dropdown: the fixed list could not cover
                                             every real answer and silently discarded the ones it missed.
                                             maxlength matches dental_consents.signed_relation VARCHAR(60). -->
                                        <input type="text" name="consent_signer_relation" id="signerRelation"
                                               maxlength="60" placeholder="e.g. FATHER" class="uc">
                                    </div>
                                    <div class="fld">
                                        <label for="signerCnic">CNIC <span class="opt">(optional)</span></label>
                                        <!-- inputmode numeric, not type=number: a CNIC is punctuated and
                                             a spinner on an identity number is nonsense. The dashes are
                                             inserted as the cashier types; the server re-normalises. -->
                                        <input type="text" name="consent_signer_cnic" id="signerCnic"
                                               maxlength="15" inputmode="numeric" autocomplete="off"
                                               placeholder="00000-0000000-0">
                                    </div>
                                </div>
                                <p class="signer-hint">Leave any of these empty to print a blank line for the parent to fill in by hand.</p>
                            </div>
                            <?php endif; ?>

                            <table class="ltable">
                                <thead>
                                    <tr>
                                        <th>Procedure</th>
                                        <th class="num">Qty</th>
                                        <th class="num">Rate</th>
                                        <?php if ($procHasDisposables): ?>
                                        <th class="num" title="Cost of disposables used. Deducted before tax and before the doctor/clinic split. Does not change what the patient pays.">Supplies</th>
                                        <?php endif; ?>
                                        <th class="num">Amount</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="lineBody">
                                    <tr class="empty" id="emptyRow"><td colspan="<?= $procHasDisposables ? 6 : 5 ?>">No procedures added yet.</td></tr>
                                </tbody>
                            </table>

                            <?php if ($selectedCat && (float) $selectedCat['procedures_pct'] > 0): ?>
                            <div class="subline">
                                <span>Subtotal</span>
                                <span id="subAmt">Rs 0</span>
                            </div>
                            <div class="subline">
                                <span>Discount (<?= rtrim(rtrim(number_format((float) $selectedCat['procedures_pct'], 2), '0'), '.') ?>%)</span>
                                <span id="discAmt">&minus; Rs 0</span>
                            </div>
                            <?php endif; ?>

                            <div class="totbar">
                                <span class="lbl">Total payable</span>
                                <span class="amt" id="totalAmt">Rs 0</span>
                            </div>

                            <?php if ($selectedCat && (float) $selectedCat['procedures_pct'] > 0): ?>
                            <p class="disc-note">A <?= rtrim(rtrim(number_format((float) $selectedCat['procedures_pct'], 2), '0'), '.') ?>% discount applies to this patient and is already included.</p>
                            <?php endif; ?>

                            <div class="paymodes">
                                <div class="paymode">
                                    <input type="radio" name="payment_mode" id="payCash" value="CASH">
                                    <label for="payCash">Cash</label>
                                </div>
                                <div class="paymode">
                                    <input type="radio" name="payment_mode" id="payOnline" value="DIGITAL">
                                    <label for="payOnline">Online / Card</label>
                                </div>
                            </div>

                            <button type="submit" class="raisebtn" id="raiseBtn" disabled>Take payment &amp; print slip</button>
                        </div>
                        <?php endif; ?>
                    </form>
                    <?php endif; ?>
                </div>

                <!-- ==== RIGHT: recent bills ==== -->
                <div class="er-col">
                    <div class="card">
                        <h2>Recent procedure bills</h2>
                        <p class="sub">The last 12 raised at this clinic.</p>
                        <?php if (!$recent): ?>
                        <p class="sub" style="margin:0;">Nothing yet.</p>
                        <?php else: ?>
                        <table class="rtable">
                            <thead>
                                <tr>
                                    <th>Invoice</th>
                                    <th>Patient</th>
                                    <th class="num">Amount</th>
                                    <th></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recent as $r): $isVoid = $r['voided_at'] !== null; ?>
                                <tr>
                                    <td>
                                        <a href="procedure_bill.php?print=1&procedure_bill_id=<?= (int) $r['id'] ?>" target="_blank"><?= htmlspecialchars($r['invoice_number']) ?></a>
                                        <div class="meta" style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($r['doctor_name']) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($r['patient_name']) ?><div style="font-size:11px;color:var(--text-muted);">MR <?= htmlspecialchars($r['mrn']) ?></div></td>
                                    <td class="num"><?= $isVoid ? '<s>' : '' ?>Rs <?= number_format((float) $r['grand_total']) ?><?= $isVoid ? '</s>' : '' ?></td>
                                    <td>
                                        <?php if ($isVoid): ?>
                                            <span class="rpill void">Void</span>
                                        <?php else: ?>
                                            <span class="rpill <?= $r['payment_method'] === 'cash' ? 'cash' : 'online' ?>"><?= $r['payment_method'] === 'cash' ? 'Cash' : 'Online' ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($canVoid && !$isVoid): ?>
                                        <form method="POST" action="procedure_bill.php" data-no-once
                                              onsubmit="var r=prompt('Void procedure bill <?= htmlspecialchars($r['invoice_number'], ENT_QUOTES) ?>? This removes it from all totals.\n\nReason:'); if(!r){return false;} this.void_reason.value=r; return true;">
                                            <input type="hidden" name="action" value="void_procedure_bill">
                                            <input type="hidden" name="procedure_bill_id" value="<?= (int) $r['id'] ?>">
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
// Procedure line builder. The picker is driven by the chosen doctor: only that
// doctor's assigned procedures are offered, so a procedure can never be billed
// against a doctor who doesn't perform it. Lines post as parallel
// assignment_id[]/quantity[] arrays; the server re-reads every rate.
(function () {
    var docPick = document.getElementById('docPick');
    if (!docPick) return;   // no patient selected, or no assignments configured

    var byDoctor = <?= json_encode($assignByDoctor, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var discountPct = <?= $selectedCat ? (float) $selectedCat['procedures_pct'] : 0 ?>;
    var dispOn = <?= $procHasDisposables ? 'true' : 'false' ?>;

    var pick = document.getElementById('procPick');
    var qtyIn = document.getElementById('procQty');
    var addBtn = document.getElementById('addProc');
    var body = document.getElementById('lineBody');
    var emptyRow = document.getElementById('emptyRow');
    var totalEl = document.getElementById('totalAmt');
    var subEl = document.getElementById('subAmt');
    var discEl = document.getElementById('discAmt');
    var raiseBtn = document.getElementById('raiseBtn');
    var consentNote = document.getElementById('consentNote');
    var signerBox = document.getElementById('signerBox');
    // Carries the selected patient through to the consent page's deep link.
    var consentPatientId = <?= (int) $selectedId ?>;
    var lines = [];

    function fmt(n) { return 'Rs ' + n.toLocaleString('en-PK', {maximumFractionDigits: 0}); }

    // Repopulate the procedure picker for the selected doctor.
    function loadProcedures() {
        var list = byDoctor[docPick.value] || [];
        pick.innerHTML = '';
        var ph = document.createElement('option');
        ph.value = '';
        ph.textContent = list.length ? 'Select a procedure…' : 'No procedures assigned to this doctor';
        pick.appendChild(ph);
        list.forEach(function (p) {
            var o = document.createElement('option');
            o.value = p.id;
            o.textContent = p.name + ' — ' + fmt(p.fee);
            o.dataset.name = p.name;
            o.dataset.fee = p.fee;
            o.dataset.consent = p.consent;
            o.dataset.disp = p.disp;
            o.dataset.tpl = p.tpl || 0;
            pick.appendChild(o);
        });
        pick.disabled = list.length === 0;
        // Switching doctor invalidates any lines already added — they were priced
        // off the previous doctor's list.
        if (lines.length) { lines = []; }
        render();
    }

    function render() {
        body.querySelectorAll('tr.line').forEach(function (tr) { tr.remove(); });
        var subtotal = 0;
        lines.forEach(function (ln, idx) {
            subtotal += ln.amount;
            var tr = document.createElement('tr');
            tr.className = 'line';
            // Supplies cell: an editable box only for procedures the admin
            // flagged. Unflagged lines still post a disposables[] slot (empty)
            // so the parallel arrays stay index-aligned with assignment_id[].
            var dispCell = '';
            if (dispOn) {
                dispCell = ln.disp
                    ? '<td class="num"><input type="number" class="disp-in" name="disposables[]" min="0" step="0.01"' +
                          ' value="' + (ln.dispCost || '') + '" data-i="' + idx + '" placeholder="0"></td>'
                    : '<td class="num"><input type="hidden" name="disposables[]" value="0">' +
                          '<span class="muted">&mdash;</span></td>';
            }
            tr.innerHTML =
                '<td>' + ln.name +
                    '<input type="hidden" name="assignment_id[]" value="' + ln.id + '">' +
                    '<input type="hidden" name="quantity[]" value="' + ln.qty + '"></td>' +
                '<td class="num">' + ln.qty + '</td>' +
                '<td class="num">' + fmt(ln.rate) + '</td>' +
                dispCell +
                '<td class="num">' + fmt(ln.amount) + '</td>' +
                '<td><button type="button" class="rm" data-i="' + idx + '" aria-label="Remove">&times;</button></td>';
            body.appendChild(tr);
        });
        emptyRow.style.display = lines.length ? 'none' : '';

        // Mirror the server's arithmetic so the screen total matches the slip.
        var total = discountPct > 0 ? Math.round(subtotal * (1 - discountPct / 100)) : subtotal;
        if (subEl) { subEl.textContent = fmt(subtotal); }
        if (discEl) { discEl.textContent = '− ' + fmt(subtotal - total); }
        totalEl.textContent = fmt(total);
        raiseBtn.disabled = lines.length === 0;

        // Surface the consent state. Two different situations, and conflating
        // them is what made this confusing before:
        //
        //   printsHere — the procedure has its own consent template, so the
        //     sheet comes out with the slip. Nothing is blocked. Show the
        //     signer fields so the cashier CAN pre-fill them if the parent is
        //     standing there, and say plainly that it will print.
        //
        //   needsCapture — flagged, but the consent lives on another page. The
        //     server WILL refuse the bill, so this stays a warning with a deep
        //     link. Kept as a warning rather than a disabled button because a
        //     consent may already exist from an earlier visit — only the server
        //     can know that.
        var printsHere = lines.filter(function (l) { return l.consent && l.tpl; });
        var needsCapture = lines.filter(function (l) { return l.consent && !l.tpl; });

        if (needsCapture.length) {
            consentNote.style.display = '';
            consentNote.innerHTML = 'Consent required for: <b>'
                + needsCapture.map(function (l) { return l.name; }).join(', ')
                + '</b>. This bill will be refused unless a signed consent is on file. '
                + '<a href="dental_consent.php' + (consentPatientId ? '?patient_id=' + consentPatientId : '')
                + '" target="_blank" style="text-decoration:underline;">Capture consent</a>.';
        } else {
            consentNote.style.display = 'none';
        }

        if (signerBox) {
            signerBox.style.display = printsHere.length ? '' : 'none';
        }
        // Tell the cashier what is about to come out of the printer, so three
        // sheets appearing instead of one is expected rather than alarming.
        raiseBtn.textContent = printsHere.length
            ? 'Take payment & print slip + consent'
            : 'Take payment & print slip';
    }

    docPick.addEventListener('change', loadProcedures);

    // CNIC dashes as the cashier types: 00000-0000000-0. Digits are the only
    // input kept, so pasting an already-punctuated number re-formats rather
    // than doubling its dashes. Purely a typing aid — consent_format_cnic()
    // normalises again on the server, which is the authority.
    var cnicIn = document.getElementById('signerCnic');
    if (cnicIn) {
        cnicIn.addEventListener('input', function () {
            var d = cnicIn.value.replace(/\D/g, '').slice(0, 13);
            var out = d.slice(0, 5);
            if (d.length > 5)  { out += '-' + d.slice(5, 12); }
            if (d.length > 12) { out += '-' + d.slice(12); }
            cnicIn.value = out;
        });
    }

    addBtn.addEventListener('click', function () {
        var opt = pick.options[pick.selectedIndex];
        if (!opt || !opt.value) { pick.focus(); return; }
        var qty = Math.max(1, parseInt(qtyIn.value, 10) || 1);
        var fee = parseFloat(opt.dataset.fee) || 0;
        lines.push({
            id: opt.value, name: opt.dataset.name, qty: qty, rate: fee,
            amount: fee * qty, consent: opt.dataset.consent === '1',
            tpl: opt.dataset.tpl === '1',
            disp: opt.dataset.disp === '1', dispCost: ''
        });
        pick.selectedIndex = 0; qtyIn.value = 1;
        render();
    });

    body.addEventListener('click', function (e) {
        var btn = e.target.closest('.rm');
        if (!btn) return;
        lines.splice(parseInt(btn.dataset.i, 10), 1);
        render();
    });

    // Keep a typed supplies cost on the model, so removing another line (which
    // re-renders the whole table) doesn't wipe what the cashier already entered.
    body.addEventListener('input', function (e) {
        var inp = e.target.closest('.disp-in');
        if (!inp) return;
        var ln = lines[parseInt(inp.dataset.i, 10)];
        if (ln) { ln.dispCost = inp.value; }
    });

    // A payment mode must be chosen — the server refuses otherwise, so catch it
    // here rather than losing the cashier's line items to a round trip.
    document.getElementById('procForm').addEventListener('submit', function (e) {
        if (!document.querySelector('input[name="payment_mode"]:checked')) {
            e.preventDefault();
            alert('Choose how the patient is paying (Cash or Online).');
        }
    });

    if (docPick.value) { loadProcedures(); }
    render();
})();
</script>
</body>
</html>
