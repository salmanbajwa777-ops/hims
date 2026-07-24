<?php
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/billing.php';
require_once __DIR__ . '/config/notify.php';
require_once __DIR__ . '/config/sheets.php';
refresh_session_permissions($pdo);
require_permission('RECEPTION_REGISTER_PATIENTS');

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch();

// Doctors use this page as READ-ONLY patient lookup: no registration, no
// invoicing, no mutations. Front-desk work stays with reception/admin even
// though the DOCTOR role carries the page-view permission. Server-side gate —
// any POST (register/invoice/discount/delete/ajax) from a doctor is refused,
// and the register view below never renders for them.
$isDoctorReadonly = ($_SESSION['base_role'] ?? '') === 'DOCTOR';
if ($isDoctorReadonly && $_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(403);
    exit('Forbidden — doctors have read-only patient lookup.');
}

// ---------------- Admit a patient from the all-patients list ----------------
// Reception can admit any patient here (doctors admit from their own console). The
// shared handler resolves today's visit or creates a shell, so a patient with no
// visit today can still be admitted. Doctors never reach this (POST blocked above).
require_once __DIR__ . '/config/admission_actions.php';
$admitError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'admit_patient') {
    $result = handle_admit_patient($pdo);
    if ($result['ok']) {
        header('Location: admission.php?id=' . (int) $result['admission_id']);
        exit;
    }
    $admitError = $result['error'];
}

// Admit-modal data (only needed for reception; harmless if the modal isn't shown).
$canAdmitHere = !$isDoctorReadonly && has_permission('ADMISSION_ADMIT_PATIENT');
$admTypes = $admDoctors = [];
$admTypeLabels = ['ROUTINE' => 'Routine', 'PRIVATE' => 'Private Room', 'LONG_PRIVATE' => 'Long Private'];
if ($canAdmitHere) {
    $admTypes = $pdo->query('SELECT admission_type, rate_amount, rate_basis FROM admission_rates WHERE is_enabled = 1 ORDER BY FIELD(admission_type,"ROUTINE","PRIVATE","LONG_PRIVATE")')->fetchAll();
    $admDoctors = $pdo->query("SELECT id, name FROM users WHERE base_role = 'DOCTOR' ORDER BY name")->fetchAll();
}

// ---------------- AJAX: quick-add area (used from the registration panel) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quick_add_area') {
    header('Content-Type: application/json');
    $cityId = (int) ($_POST['city_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');

    if ($cityId <= 0 || $name === '') {
        echo json_encode(['error' => 'City and area name are required.']);
        exit;
    }

    $existing = $pdo->prepare('SELECT id, status FROM areas WHERE city_id = ? AND name = ?');
    $existing->execute([$cityId, $name]);
    $found = $existing->fetch();

    if ($found) {
        echo json_encode(['id' => (int) $found['id'], 'name' => $name, 'status' => $found['status']]);
        exit;
    }

    $insert = $pdo->prepare('INSERT INTO areas (city_id, name, status, added_by_id) VALUES (?, ?, ?, ?)');
    $insert->execute([$cityId, $name, 'pending', $_SESSION['user_id']]);
    $newId = (int) $pdo->lastInsertId();

    $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)');
    $log->execute([$_SESSION['user_id'], 'area_quick_added', "Added area \"$name\" (id #$newId) pending review"]);

    echo json_encode(['id' => $newId, 'name' => $name, 'status' => 'pending']);
    exit;
}

// ---------------- AJAX: consultation types for a doctor ----------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'doctor_consult_types') {
    header('Content-Type: application/json');
    $doctorId = (int) ($_GET['doctor_id'] ?? 0);
    // Default type first (so the follow-up panel can auto-select the doctor's default),
    // then alphabetical.
    $stmt = $pdo->prepare('SELECT id, label, fee, is_default, is_revisit_eligible FROM doctor_consult_types WHERE doctor_id = ? ORDER BY is_default DESC, label');
    $stmt->execute([$doctorId]);
    echo json_encode($stmt->fetchAll());
    exit;
}

// ---------------- AJAX: revisit quote for a returning patient ----------------
// Given an existing patient + doctor + consult-type, returns the proposed fee
// (with any follow-up discount) so the follow-up panel can show it live.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'revisit_quote') {
    header('Content-Type: application/json');
    $pid = (int) ($_GET['patient_id'] ?? 0);
    $did = (int) ($_GET['doctor_id'] ?? 0);
    $ctid = (int) ($_GET['consult_type_id'] ?? 0);
    $feeStmt = $pdo->prepare('SELECT fee FROM doctor_consult_types WHERE id = ? AND doctor_id = ?');
    $feeStmt->execute([$ctid, $did]);
    $feeRow = $feeStmt->fetch();
    if (!$feeRow) { echo json_encode(['error' => 'Invalid consultation type.']); exit; }
    $quote = revisit_consultation_fee($pdo, $pid, $did, $ctid, (float) $feeRow['fee']);
    $quote['full_fee'] = (float) $feeRow['fee'];

    // Patient discount category stacks ON TOP of the revisit price so the panel
    // quotes what will actually be billed. Overriding to full fee still keeps
    // the category discount (it's the patient's standing entitlement — only the
    // follow-up portion is overridable), which the JS mirrors.
    $cat = patient_discount_category($pdo, $pid);
    if ($cat) {
        $quote['category_name'] = $cat['name'];
        $quote['category_pct'] = (float) $cat['consultation_pct'];
        $quote['discount_pct'] = stack_discount_pct((float) $quote['discount_pct'], (float) $cat['consultation_pct']);
        $quote['fee'] = round($quote['full_fee'] * (1 - $quote['discount_pct'] / 100), 2);
    }
    echo json_encode($quote);
    exit;
}

// ---------------- AJAX: today's open bookings for a phone / patient ----------------
// The booking-match guard (Popup B): before an invoice is raised, check whether
// this phone (or this patient) has a live booking today, so the desk can
// consume the appointment instead of silently generating a fresh walk-in that
// would later rot into a false no-show.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'booking_check') {
    header('Content-Type: application/json');
    $phone = trim($_GET['phone'] ?? '');
    $pid = (int) ($_GET['patient_id'] ?? 0);
    $rows = [];
    try {
        if ($pid > 0) {
            // Revisit path: match by the patient link OR the patient's own phone.
            $stmt = $pdo->prepare("
                SELECT bk.id, bk.person_name, bk.preferred_time, bk.note,
                       bk.doctor_id, bk.doctor_consult_type_id,
                       du.name AS doctor_name, dct.label AS purpose
                FROM bookings bk
                JOIN users du ON du.id = bk.doctor_id
                JOIN doctor_consult_types dct ON dct.id = bk.doctor_consult_type_id
                WHERE bk.booking_date = CURDATE() AND bk.status = 'BOOKED'
                  AND (bk.patient_id = ? OR bk.phone = (SELECT phone FROM patients WHERE id = ?))
                ORDER BY bk.patient_id = ? DESC, bk.created_at
            ");
            $stmt->execute([$pid, $pid, $pid]);
            $rows = $stmt->fetchAll();
        } elseif ($phone !== '') {
            $stmt = $pdo->prepare("
                SELECT bk.id, bk.person_name, bk.preferred_time, bk.note,
                       bk.doctor_id, bk.doctor_consult_type_id,
                       du.name AS doctor_name, dct.label AS purpose
                FROM bookings bk
                JOIN users du ON du.id = bk.doctor_id
                JOIN doctor_consult_types dct ON dct.id = bk.doctor_consult_type_id
                WHERE bk.booking_date = CURDATE() AND bk.status = 'BOOKED' AND bk.phone = ?
                ORDER BY bk.created_at
            ");
            $stmt->execute([$phone]);
            $rows = $stmt->fetchAll();
        }
    } catch (Throwable $e) {
        // bookings table missing — feature silently dormant until the migration runs.
    }
    echo json_encode(['bookings' => $rows]);
    exit;
}

// ---------------- AJAX: duplicate-patient check ----------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'check_duplicate') {
    header('Content-Type: application/json');
    $name = trim($_GET['name'] ?? '');
    $father = trim($_GET['father_name'] ?? '');
    $dob = trim($_GET['dob'] ?? '');

    if (mb_strlen($name) < 3) {
        echo json_encode(['match' => null]);
        exit;
    }

    if ($father !== '' && $dob !== '') {
        $stmt = $pdo->prepare('SELECT mrn, name, father_name, dob, phone FROM patients WHERE (name = ?) OR (father_name = ? AND dob = ?) LIMIT 1');
        $stmt->execute([$name, $father, $dob]);
    } else {
        $stmt = $pdo->prepare('SELECT mrn, name, father_name, dob, phone FROM patients WHERE name = ? LIMIT 1');
        $stmt->execute([$name]);
    }
    $match = $stmt->fetch();
    echo json_encode(['match' => $match ?: null]);
    exit;
}

/**
 * Consume a booking inside the caller's open transaction: link the visit and
 * flip BOOKED → ARRIVED. Deliberately called at SAVE time, never when the
 * popup's "Yes" is clicked — abandoning the form must leave the booking live,
 * so a dangling ARRIVED-without-visit can never exist. The status guard also
 * makes double-submits harmless. Best-effort: a missing bookings table (or a
 * booking already consumed elsewhere) never fails the registration.
 */
function consume_booking(PDO $pdo, int $bookingId, int $visitId): void {
    if ($bookingId <= 0) { return; }
    try {
        $upd = $pdo->prepare("UPDATE bookings SET status = 'ARRIVED', visit_id = ? WHERE id = ? AND status = 'BOOKED'");
        $upd->execute([$visitId, $bookingId]);
        if ($upd->rowCount()) {
            $pdo->prepare('UPDATE visits SET booking_id = ? WHERE id = ?')->execute([$bookingId, $visitId]);
        }
    } catch (Throwable $e) { /* bookings migration not run yet */ }
}

/**
 * Authoritative server-side re-check for the booking-match guard: the client
 * popup can be skipped by a fast Enter, so the POST itself looks up any live
 * booking for today on this phone/patient. Returns the matches (empty when the
 * form already answered the question via booking_id / booking_dismissed).
 */
function pending_booking_guard(PDO $pdo, string $phone, int $patientId = 0): array {
    if (($_POST['booking_id'] ?? '') !== '' || ($_POST['booking_dismissed'] ?? '') === '1') {
        return []; // desk already said Yes (consume) or No (separate walk-in)
    }
    try {
        if ($patientId > 0) {
            $stmt = $pdo->prepare("
                SELECT bk.id, bk.person_name, du.name AS doctor_name, dct.label AS purpose
                FROM bookings bk
                JOIN users du ON du.id = bk.doctor_id
                JOIN doctor_consult_types dct ON dct.id = bk.doctor_consult_type_id
                WHERE bk.booking_date = CURDATE() AND bk.status = 'BOOKED'
                  AND (bk.patient_id = ? OR bk.phone = (SELECT phone FROM patients WHERE id = ?))
            ");
            $stmt->execute([$patientId, $patientId]);
        } else {
            if ($phone === '') { return []; }
            $stmt = $pdo->prepare("
                SELECT bk.id, bk.person_name, du.name AS doctor_name, dct.label AS purpose
                FROM bookings bk
                JOIN users du ON du.id = bk.doctor_id
                JOIN doctor_consult_types dct ON dct.id = bk.doctor_consult_type_id
                WHERE bk.booking_date = CURDATE() AND bk.status = 'BOOKED' AND bk.phone = ?
            ");
            $stmt->execute([$phone]);
        }
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return []; // bookings migration not run yet
    }
}

/**
 * Idempotency guard against lag-induced double registration: returns this
 * patient's most recent visit with THIS doctor today, or null if none.
 *
 * The failure this catches: reception/doctor clicks "New invoice → Save", the
 * server lags with no feedback, they click again, and a SECOND identical visit
 * (new token, new bill) is created for a patient who is already in today's
 * queue — exactly the duplicate rows the desk was seeing. The caller turns a
 * hit into a "already registered today" message with a "Register anyway"
 * override for the rare genuine second same-day consult.
 *
 * Scoped to same patient + same doctor + same day. A different doctor is a
 * legitimately separate visit and is never blocked.
 */
function same_day_visit(PDO $pdo, int $patientId, int $doctorId): ?array {
    if ($patientId <= 0 || $doctorId <= 0) { return null; }
    $stmt = $pdo->prepare("
        SELECT id, token_no, created_at
        FROM visits
        WHERE patient_id = ? AND doctor_id = ? AND visit_date = CURDATE()
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$patientId, $doctorId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ---------------- Register patient + create visit (one transaction) ----------------
$error = '';
$success = '';
$successVisit = null;
// Set when the server-side guard interrupts a submit: re-renders the form with
// the booking question asked as a banner instead of the JS popup.
$pendingBookings = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register_patient') {
    // Names are stored in ALL CAPS so every consumer (lists, invoices, slips)
    // shows them uniformly. mb_strtoupper (not strtoupper) keeps non-ASCII intact.
    $name = mb_strtoupper(trim($_POST['name'] ?? ''), 'UTF-8');
    $fatherName = mb_strtoupper(trim($_POST['father_name'] ?? ''), 'UTF-8');
    // Phone: combine country code + local number into E.164 (e.g. +923001234567).
    // Strip non-digits and any leading zero(s) from the local part before prefixing the code.
    $phoneCc = preg_replace('/[^\d+]/', '', $_POST['phone_cc'] ?? '+92');
    if ($phoneCc === '' || $phoneCc[0] !== '+') { $phoneCc = '+92'; }
    $phoneLocal = ltrim(preg_replace('/\D/', '', $_POST['phone'] ?? ''), '0');
    $phone = $phoneLocal !== '' ? $phoneCc . $phoneLocal : '';
    // A Pakistan (+92) mobile local number is exactly 10 digits and never starts with 0
    // (the leading 0 is stripped above, so a "0300…" entry lands as "300…" = 10 digits).
    // Only enforced for +92; other country codes have their own lengths.
    $phoneError = '';
    if ($phoneCc === '+92' && !preg_match('/^[1-9]\d{9}$/', $phoneLocal)) {
        $phoneError = 'Enter a valid 10-digit Pakistan mobile number (e.g. 3001234567).';
    }
    $dob = trim($_POST['dob'] ?? '') ?: null;
    $gender = $_POST['gender'] ?? '';
    // Optional — the patient record and the yearly invoice sheet both carry it. A
    // malformed address is dropped rather than blocking the registration: an email
    // is never worth turning a paying patient away at the desk.
    $email = trim($_POST['email'] ?? '');
    $email = ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null;
    // patients.email arrives with sql/add_sheet_sync.sql. Code auto-deploys on push
    // but migrations are run by hand, so the column may not exist yet — writing to
    // it unconditionally would fail EVERY registration in that window. Probed once
    // per request; when absent the field is simply dropped from the INSERT.
    $hasEmailCol = false;
    try {
        $pdo->query('SELECT email FROM patients LIMIT 0');
        $hasEmailCol = true;
    } catch (PDOException $e) { /* pre-migration — registration continues without it */ }
    $cnic = trim($_POST['cnic'] ?? '') ?: null;
    $altPhone = trim($_POST['alt_phone'] ?? '') ?: null;
    $cityId = (int) ($_POST['city_id'] ?? 0) ?: null;
    $areaId = (int) ($_POST['area_id'] ?? 0) ?: null;
    $address = trim($_POST['address'] ?? '') ?: null;

    $doctorId = (int) ($_POST['doctor_id'] ?? 0);
    $consultTypeId = (int) ($_POST['doctor_consult_type_id'] ?? 0);
    $paymentMode = $_POST['payment_mode'] ?? '';
    $discountPct = trim($_POST['discount_pct'] ?? '') !== '' ? (float) $_POST['discount_pct'] : 0;

    // Register-only (backfill): save the patient record to the registration point with
    // NO visit, NO token, NO invoice. Used to enter existing patients' demographics
    // without starting a consultation. A visit-less patient is a valid state — no bill
    // is created, so none of the doctor/consult/payment/day-lock checks apply.
    // Register-only (backfill) is a DEDICATED submit button — its name/value reaches the
    // server ONLY when that button was pressed, so a full "Register & Add to Queue"
    // submit can never be mistaken for a backfill (no stale flag). The consultation
    // fields are simply ignored on this path.
    $registerOnly = ($_POST['register_only'] ?? '') === '1';

    if ($name === '' || $phone === '' || !in_array($gender, ['MALE', 'FEMALE', 'OTHER'], true)) {
        $error = 'Name, phone, and gender are required.';
    } elseif ($phoneError !== '') {
        $error = $phoneError;
    } elseif ($registerOnly) {
        // Register-only path: minimal validation, then commit patient + MRN only.
        try {
            $pdo->beginTransaction();

            $insertPatient = $pdo->prepare('
                INSERT INTO patients (mrn, name, father_name, dob, gender, phone, ' . ($hasEmailCol ? 'email, ' : '') . 'alt_phone, cnic, city_id, area_id, address, created_by_id)
                VALUES (NULL, ?, ?, ?, ?, ?, ' . ($hasEmailCol ? '?, ' : '') . '?, ?, ?, ?, ?, ?)
            ');
            $insertPatient->execute(array_merge(
                [$name, $fatherName ?: null, $dob, $gender, $phone],
                $hasEmailCol ? [$email] : [],
                [$altPhone, $cnic, $cityId, $areaId, $address, $_SESSION['user_id']]
            ));
            $patientId = (int) $pdo->lastInsertId();

            // Same race-safe monthly MRN counter as a full registration.
            $year = (int) date('Y');
            $month = (int) date('n');
            $mrnStmt = $pdo->prepare('
                INSERT INTO mrn_counters (yr, mo, next_seq)
                VALUES (?, ?, 2)
                ON DUPLICATE KEY UPDATE next_seq = LAST_INSERT_ID(next_seq) + 1
            ');
            $mrnStmt->execute([$year, $month]);
            $mrnSeq = $mrnStmt->rowCount() === 1 ? 1 : (int) $pdo->lastInsertId();
            $mrn = substr((string) $year, 2, 2)
                . str_pad((string) $mrnSeq, 4, '0', STR_PAD_LEFT)
                . str_pad((string) $month, 2, '0', STR_PAD_LEFT);
            $pdo->prepare('UPDATE patients SET mrn = ? WHERE id = ?')->execute([$mrn, $patientId]);

            $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
                ->execute([$_SESSION['user_id'], 'patient_registered_only',
                    "Registered patient #$patientId ($name, MRN $mrn) — record only, no visit/invoice"]);

            $pdo->commit();

            $success = "Patient saved — MRN $mrn. No visit or invoice was created.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $error = 'Could not save the patient record. Please try again.';
        }
    } elseif ($doctorId <= 0 || $consultTypeId <= 0 || !in_array($paymentMode, ['CASH', 'DIGITAL'], true)) {
        $error = 'Doctor, consultation type, and payment mode are required.';
    } elseif (($pendingBookings = pending_booking_guard($pdo, $phone)) !== []) {
        // Authoritative booking-match guard: this phone has a live booking today
        // and the form answered neither Yes nor No — re-render with the question.
        $error = 'This number has a booking today — please answer the appointment question below before saving.';
    } else {
        // Fee is always looked up server-side from doctor_consult_types — never trust a
        // client-submitted fee value, even though the UI already disables that field.
        $feeStmt = $pdo->prepare('SELECT fee FROM doctor_consult_types WHERE id = ? AND doctor_id = ?');
        $feeStmt->execute([$consultTypeId, $doctorId]);
        $feeRow = $feeStmt->fetch();

        if (!$feeRow) {
            $error = 'Invalid doctor / consultation type combination.';
        } elseif ($discountPct < 0 || $discountPct > (float) $currentUser['max_discount_pct']) {
            $error = 'Discount exceeds your permitted limit of ' . $currentUser['max_discount_pct'] . '%.';
        } elseif (($dayLock = require_day_open($pdo)) !== null) {
            // Registration settles the consultation bill (create_bill_for_visit), so it
            // is a money-moving action: once this receptionist's shift is closed, no new
            // paid bill may land on the signed tally.
            $error = $dayLock;
        } else {
            $fee = (float) $feeRow['fee'];

            try {
                $pdo->beginTransaction();

                // MRN and queue token both need to be race-safe under concurrent registrations
                // (two receptionists saving at once) — a plain "SELECT MAX(...) + 1" can hand out
                // the same value to both before either commits. Both use an atomic upsert so MySQL
                // serializes concurrent increments via row locking.
                $insertPatient = $pdo->prepare('
                    INSERT INTO patients (mrn, name, father_name, dob, gender, phone, ' . ($hasEmailCol ? 'email, ' : '') . 'alt_phone, cnic, city_id, area_id, address, created_by_id)
                    VALUES (NULL, ?, ?, ?, ?, ?, ' . ($hasEmailCol ? '?, ' : '') . '?, ?, ?, ?, ?, ?)
                ');
                $insertPatient->execute(array_merge(
                    [$name, $fatherName ?: null, $dob, $gender, $phone],
                    $hasEmailCol ? [$email] : [],
                    [$altPhone, $cnic, $cityId, $areaId, $address, $_SESSION['user_id']]
                ));
                $patientId = (int) $pdo->lastInsertId();

                // MRN = YY + NNNN + MM, where NNNN resets to 0001 each month (e.g. the first
                // July-2026 patient is 26000107). The per-month sequence comes from mrn_counters,
                // incremented with the same LAST_INSERT_ID() upsert trick as the queue token below.
                // rowCount() disambiguates the two branches reliably (independent of which statement
                // ran before): MySQL reports 1 affected row for a fresh INSERT and 2 for the ODKU
                // update path, so we don't have to trust that lastInsertId() reflects THIS statement
                // on the insert branch. See sql/add_mrn_monthly_counter.sql.
                $year = (int) date('Y');
                $month = (int) date('n');
                $mrnStmt = $pdo->prepare('
                    INSERT INTO mrn_counters (yr, mo, next_seq)
                    VALUES (?, ?, 2)
                    ON DUPLICATE KEY UPDATE next_seq = LAST_INSERT_ID(next_seq) + 1
                ');
                $mrnStmt->execute([$year, $month]);
                // Fresh (year, month) row (rowCount 1): this is the month's first patient, seq = 1.
                // Otherwise (rowCount 2): LAST_INSERT_ID captured the pre-increment seq for us.
                $mrnSeq = $mrnStmt->rowCount() === 1 ? 1 : (int) $pdo->lastInsertId();
                $mrn = substr((string) $year, 2, 2)
                    . str_pad((string) $mrnSeq, 4, '0', STR_PAD_LEFT)
                    . str_pad((string) $month, 2, '0', STR_PAD_LEFT);
                $pdo->prepare('UPDATE patients SET mrn = ? WHERE id = ?')->execute([$mrn, $patientId]);

                $pdo->prepare('
                    INSERT INTO visit_queue_counters (doctor_id, visit_date, next_token)
                    VALUES (?, CURDATE(), 2)
                    ON DUPLICATE KEY UPDATE next_token = LAST_INSERT_ID(next_token) + 1
                ')->execute([$doctorId]);
                // First registration for this doctor today: fresh row inserts next_token=2
                // directly (no LAST_INSERT_ID() call on that branch, so PHP falls back to token 1
                // below). Subsequent ones: LAST_INSERT_ID(next_token) captures the PRE-increment
                // value as the issued token, then stores next_token + 1 for the following call.
                $lastId = (int) $pdo->lastInsertId();
                $tokenNo = $lastId > 0 ? $lastId : 1;

                $discountBy = $discountPct > 0 ? $_SESSION['user_id'] : null;

                // A brand-new patient's first visit at full fee is a FULL window anchor for
                // future revisit pricing; a discounted first visit is not a clean anchor.
                $feeType = $discountPct > 0 ? null : 'FULL';

                $insertVisit = $pdo->prepare('
                    INSERT INTO visits (token_no, patient_id, doctor_id, doctor_consult_type_id, fee, discount_pct, discount_applied_by_id, payment_mode, visit_date, created_by_id, consultation_fee_type)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?)
                ');
                $insertVisit->execute([
                    $tokenNo, $patientId, $doctorId, $consultTypeId, $fee, $discountPct, $discountBy, $paymentMode, $_SESSION['user_id'], $feeType,
                ]);
                $visitId = (int) $pdo->lastInsertId();

                // Booking consumed at Save, in the same transaction as the visit
                // — the popup's "Yes" only stashed the id in a hidden field.
                consume_booking($pdo, (int) ($_POST['booking_id'] ?? 0), $visitId);

                // Registration doubles as checkout: the consultation invoice is raised now so
                // the front desk can print it straight away instead of revisiting checkout.php.
                $typeStmt = $pdo->prepare('SELECT label FROM doctor_consult_types WHERE id = ?');
                $typeStmt->execute([$consultTypeId]);
                $typeLabel = $typeStmt->fetch()['label'] ?? 'Consultation';

                $billId = create_bill_for_visit(
                    $pdo,
                    $visitId,
                    $typeLabel,
                    (float) $fee,
                    (float) $discountPct,
                    (int) $_SESSION['user_id'],
                    $paymentMode
                );

                $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)');
                $log->execute([
                    $_SESSION['user_id'],
                    'patient_registered',
                    "Registered patient #$patientId ($name, MRN $mrn), visit #$visitId with doctor #$doctorId, token #$tokenNo" .
                        ($discountPct > 0 ? ", {$discountPct}% discount applied" : ''),
                ]);

                $pdo->commit();

                // Email the doctor about their new patient (best-effort, after commit).
                notify_invoice_raised($pdo, $billId);
                // Log the invoice to the yearly Google Sheet (best-effort, after commit).
                sheet_push($pdo, 'INVOICE', $billId, (int) $_SESSION['user_id']);

                $doctorStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
                $doctorStmt->execute([$doctorId]);
                $doctorName = $doctorStmt->fetch()['name'] ?? '';

                $successVisit = [
                    'bill_id' => $billId,
                    'mrn' => $mrn,
                    'patient_name' => $name,
                    'father_name' => $fatherName,
                    'gender' => $gender,
                    'doctor_name' => $doctorName,
                    'type_label' => $typeLabel,
                    'token_no' => $tokenNo,
                ];
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Could not save registration. Please try again.';
            }
        }
    }
}

// ---------------- Follow-up visit for an EXISTING patient (revisit billing) ----------------
// Raises a new consultation visit + bill for a patient already on file. The fee is
// proposed by the revisit engine (free / 50% / 75% / full), overridable by reception.
$followupVisit = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register_followup') {
    $patientId = (int) ($_POST['patient_id'] ?? 0);
    $doctorId = (int) ($_POST['doctor_id'] ?? 0);
    $consultTypeId = (int) ($_POST['doctor_consult_type_id'] ?? 0);
    $paymentMode = $_POST['payment_mode'] ?? '';
    $override = ($_POST['override_full'] ?? '') === '1';   // reception forced full fee

    $pStmt = $pdo->prepare('SELECT id, name, mrn FROM patients WHERE id = ?');
    $pStmt->execute([$patientId]);
    $patient = $pStmt->fetch();

    $feeStmt = $pdo->prepare('SELECT fee, label FROM doctor_consult_types WHERE id = ? AND doctor_id = ?');
    $feeStmt->execute([$consultTypeId, $doctorId]);
    $ctRow = $feeStmt->fetch();

    if (!$patient || !$ctRow || !in_array($paymentMode, ['CASH', 'DIGITAL'], true)) {
        $error = 'Pick a patient, doctor, consultation type and payment mode.';
    } elseif (($_POST['force_revisit'] ?? '') !== '1'
              && ($dupVisit = same_day_visit($pdo, $patientId, $doctorId)) !== null) {
        // Idempotency net for lag-induced double-submit: this patient already has
        // a visit with THIS doctor today. Rather than raise a second token + bill,
        // surface the existing one. A genuine second same-day consult is rare, and
        // the receptionist can still force it with the "Register anyway" button
        // (it re-posts with force_revisit=1).
        $duplicateVisit = $dupVisit; // consumed by the follow-up form re-render
        $error = 'Already registered today: ' . $patient['name']
            . ' (' . $patient['mrn'] . ') has visit #' . (int) $dupVisit['token_no']
            . ' with this doctor at ' . date('h:i A', strtotime($dupVisit['created_at']))
            . '. Open that visit, or choose "Register anyway" for a separate consultation.';
    } elseif (pending_booking_guard($pdo, '', $patientId) !== []) {
        // The follow-up panel asks its booking question via the JS popup before
        // submit; a bypassed popup lands here. No form re-render path for the
        // modal, so reject with instructions rather than losing the answer.
        $error = 'This patient has a booking today — reopen "New invoice" and answer the appointment prompt (it appears when you pick the patient).';
    } elseif (($dayLock = require_day_open($pdo)) !== null) {
        // A follow-up also settles its bill at registration — same day-lock guard as
        // the new-patient path. A free follow-up is settled 'waived' (no cash), but the
        // action still writes a settled bill row, so the shift must be open.
        $error = $dayLock;
    } else {
        $fullFee = (float) $ctRow['fee'];
        $quote = revisit_consultation_fee($pdo, $patientId, $doctorId, $consultTypeId, $fullFee);

        // Receptionist override: charge full instead of the proposed follow-up rate.
        if ($override) {
            $quote = ['fee' => $fullFee, 'discount_pct' => 0.0, 'fee_type' => 'FULL',
                      'reason' => 'Reception override to full fee', 'anchor_visit_id' => null];
        }

        // Patient discount category stacks ON TOP of the (possibly overridden)
        // revisit price. The override only cancels the follow-up portion — the
        // category discount is the patient's standing entitlement and survives.
        // Snapshot the rate used so later rate edits never rewrite this visit,
        // and month-end reporting can attribute the discount to its category.
        $cat = patient_discount_category($pdo, $patientId);
        $categoryPct = $cat ? (float) $cat['consultation_pct'] : 0.0;
        $categoryId = $cat ? (int) $cat['id'] : null;
        $categoryAmount = 0.0;
        if ($categoryPct > 0) {
            $beforeCategory = (float) $quote['fee'];   // price after revisit/override step
            $quote['discount_pct'] = stack_discount_pct((float) $quote['discount_pct'], $categoryPct);
            $quote['fee'] = round($fullFee * (1 - $quote['discount_pct'] / 100), 2);
            // Exact rupees the category step saved — snapshotted for month-end
            // reporting (no derivation math, exact even at 100% free).
            $categoryAmount = round($beforeCategory - $quote['fee'], 2);
            // A category-only discount is still a clean FULL anchor for the
            // revisit window (it's automatic policy, not ad-hoc pricing) — so
            // discounted regular patients keep qualifying for follow-up rates.
            // Follow-up fee types are left as the engine set them.
        }

        try {
            $pdo->beginTransaction();

            $pdo->prepare('
                INSERT INTO visit_queue_counters (doctor_id, visit_date, next_token)
                VALUES (?, CURDATE(), 2)
                ON DUPLICATE KEY UPDATE next_token = LAST_INSERT_ID(next_token) + 1
            ')->execute([$doctorId]);
            $lastId = (int) $pdo->lastInsertId();
            $tokenNo = $lastId > 0 ? $lastId : 1;

            $discountPct = (float) $quote['discount_pct'];
            $discountBy = $discountPct > 0 ? $_SESSION['user_id'] : null;

            $insertVisit = $pdo->prepare('
                INSERT INTO visits (token_no, patient_id, doctor_id, doctor_consult_type_id, fee, discount_pct, discount_applied_by_id, payment_mode, visit_date, created_by_id, consultation_fee_type, revisit_of_visit_id, fee_overridden, discount_category_id, category_discount_pct, category_discount_amount)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?)
            ');
            $insertVisit->execute([
                $tokenNo, $patientId, $doctorId, $consultTypeId, $fullFee, $discountPct, $discountBy,
                $paymentMode, $_SESSION['user_id'], $quote['fee_type'], $quote['anchor_visit_id'] ?? null, $override ? 1 : 0,
                $categoryPct > 0 ? $categoryId : null, $categoryPct, $categoryAmount,
            ]);
            $visitId = (int) $pdo->lastInsertId();

            // Booking consumed at Save, same transaction — see consume_booking().
            consume_booking($pdo, (int) ($_POST['booking_id'] ?? 0), $visitId);

            $billId = create_bill_for_visit(
                $pdo, $visitId, $ctRow['label'], $fullFee, $discountPct, (int) $_SESSION['user_id'], $paymentMode
            );

            $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
                ->execute([$_SESSION['user_id'], 'followup_registered',
                    "Follow-up visit #$visitId for patient #$patientId ({$patient['mrn']}), {$quote['fee_type']} ({$quote['reason']}), token #$tokenNo"
                    . ($categoryPct > 0 ? ", {$cat['name']} category discount {$categoryPct}% (total {$discountPct}%)" : '')]);

            $pdo->commit();

            // Email the doctor about the follow-up visit (best-effort, after commit).
            notify_invoice_raised($pdo, $billId);
            // Log the invoice to the yearly Google Sheet (best-effort, after commit).
            sheet_push($pdo, 'INVOICE', $billId, (int) $_SESSION['user_id']);

            $dStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
            $dStmt->execute([$doctorId]);
            $followupVisit = [
                'bill_id' => $billId, 'mrn' => $patient['mrn'], 'patient_name' => $patient['name'],
                'doctor_name' => $dStmt->fetch()['name'] ?? '', 'type_label' => $ctRow['label'],
                'token_no' => $tokenNo, 'fee_type' => $quote['fee_type'], 'reason' => $quote['reason'],
                'fee' => $quote['fee'],
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Could not create the follow-up visit. Please try again.';
        }
    }
}

// ---------------- Assign / clear a discount category (admin only) ----------------
// The patient's standing discount scheme (Family & Friends / Charity / Loyalty).
// Assignment is admin-only; reception just sees the badge. All FUTURE invoices
// auto-discount at the category's rates — nothing already billed changes.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_discount_category') {
    if (($_SESSION['base_role'] ?? '') !== 'ADMIN') {
        http_response_code(403);
        exit('Forbidden — admin access only.');
    }

    $targetId = (int) ($_POST['patient_id'] ?? 0);
    $categoryId = (int) ($_POST['discount_category_id'] ?? 0) ?: null;

    $pStmt = $pdo->prepare('SELECT id, name, mrn FROM patients WHERE id = ?');
    $pStmt->execute([$targetId]);
    $target = $pStmt->fetch();

    $catName = null;
    if ($categoryId !== null) {
        $cStmt = $pdo->prepare('SELECT name FROM discount_categories WHERE id = ? AND is_active = 1');
        $cStmt->execute([$categoryId]);
        $catName = $cStmt->fetchColumn() ?: null;
    }

    if (!$target || ($categoryId !== null && $catName === null)) {
        $error = 'Patient or discount category not found.';
    } else {
        $pdo->prepare('UPDATE patients SET discount_category_id = ?, discount_assigned_by_id = ?, discount_assigned_at = NOW() WHERE id = ?')
            ->execute([$categoryId, $_SESSION['user_id'], $targetId]);
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$_SESSION['user_id'], 'patient_discount_category_set',
                $categoryId !== null
                    ? "Assigned \"$catName\" discount category to patient #$targetId ({$target['name']}, MRN {$target['mrn']})"
                    : "Removed discount category from patient #$targetId ({$target['name']}, MRN {$target['mrn']})"]);
        $success = $categoryId !== null
            ? "{$target['name']} assigned to \"$catName\" — future invoices auto-discount."
            : "Discount category removed from {$target['name']}.";
    }
}

// ---------------- Delete patient (admin only) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_patient') {
    if (($_SESSION['base_role'] ?? '') !== 'ADMIN') {
        http_response_code(403);
        exit('Forbidden — admin access only.');
    }

    $deleteId = (int) ($_POST['patient_id'] ?? 0);
    $patientStmt = $pdo->prepare('SELECT id, name, mrn FROM patients WHERE id = ?');
    $patientStmt->execute([$deleteId]);
    $targetPatient = $patientStmt->fetch();

    if (!$targetPatient) {
        $error = 'Patient not found.';
    } else {
        // Cascades to that patient's visits — see sql/add_delete_cascades.sql. Visits belong
        // to this one patient and have no meaning without them, unlike deleting a staff member
        // (which only detaches, never deletes, the visits they're linked to).
        $pdo->prepare('DELETE FROM patients WHERE id = ?')->execute([$deleteId]);

        $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)');
        $log->execute([
            $_SESSION['user_id'],
            'patient_deleted',
            "Deleted patient #$deleteId ({$targetPatient['name']}, MRN {$targetPatient['mrn']})",
        ]);

        $success = "Deleted {$targetPatient['name']}.";
    }
}

// ---------------- Search / recent patients ----------------
$q = trim($_GET['q'] ?? '');
$patients = [];

// Doctor date-range filter for the default (no-search) list. Empty = "recent 10".
$docFrom = ($isDoctorReadonly && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '')) ? $_GET['from'] : '';
$docTo   = ($isDoctorReadonly && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')) ? $_GET['to'] : '';
$docRangeActive = $isDoctorReadonly && $docFrom !== '' && $docTo !== '';

if ($isDoctorReadonly) {
    // Doctors only ever see patients THEY have seen. `last_visit` is the last
    // visit UNDER THIS DOCTOR (not the patient's global last visit), so the
    // column reflects "when I saw them". Search stays scoped to those patients;
    // the default view is the last 10 they saw, or a date-range roster.
    // The doctor's own visits, aggregated to one row per patient (their last
    // visit under this doctor). Kept as a derived table so the outer SELECT can
    // pull p.*/city/category without fighting ONLY_FULL_GROUP_BY.
    $mineSql = '
        SELECT p.*, c.name AS city_name,
            dc.name AS discount_category_name, dc.is_active AS discount_category_active,
            seen.last_visit
        FROM (
            SELECT v.patient_id, MAX(v.visit_date) AS last_visit
            FROM visits v
            WHERE v.doctor_id = ? %RANGE%
            GROUP BY v.patient_id
        ) seen
        JOIN patients p ON p.id = seen.patient_id
        LEFT JOIN cities c ON c.id = p.city_id
        LEFT JOIN discount_categories dc ON dc.id = p.discount_category_id
        %WHERE%
        ORDER BY seen.last_visit DESC%NAME% LIMIT %LIMIT%';

    if ($q !== '') {
        $like = '%' . $q . '%';
        $sql = str_replace(
            ['%RANGE%', '%WHERE%', '%NAME%', '%LIMIT%'],
            ['', 'WHERE (p.name LIKE ? OR p.phone LIKE ? OR p.father_name LIKE ? OR p.mrn LIKE ?)', '', '50'],
            $mineSql
        );
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$doctorId, $like, $like, $like, $like]);
        $patients = $stmt->fetchAll();
    } elseif ($docRangeActive) {
        $sql = str_replace(
            ['%RANGE%', '%WHERE%', '%NAME%', '%LIMIT%'],
            ['AND v.visit_date BETWEEN ? AND ?', '', ', p.name ASC', '500'],
            $mineSql
        );
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$doctorId, $docFrom, $docTo]);
        $patients = $stmt->fetchAll();
    } else {
        // Default: the 10 patients this doctor most recently saw.
        $sql = str_replace(
            ['%RANGE%', '%WHERE%', '%NAME%', '%LIMIT%'],
            ['', '', '', '10'],
            $mineSql
        );
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$doctorId]);
        $patients = $stmt->fetchAll();
    }
} elseif ($q !== '') {
    $like = '%' . $q . '%';
    $stmt = $pdo->prepare('
        SELECT p.*, c.name AS city_name,
            dc.name AS discount_category_name, dc.is_active AS discount_category_active,
            (SELECT v.visit_date FROM visits v WHERE v.patient_id = p.id ORDER BY v.visit_date DESC LIMIT 1) AS last_visit,
            (SELECT v.doctor_id FROM visits v WHERE v.patient_id = p.id ORDER BY v.visit_date DESC, v.id DESC LIMIT 1) AS last_doctor_id
        FROM patients p
        LEFT JOIN cities c ON c.id = p.city_id
        LEFT JOIN discount_categories dc ON dc.id = p.discount_category_id
        WHERE p.name LIKE ? OR p.phone LIKE ? OR p.father_name LIKE ? OR p.mrn LIKE ?
        ORDER BY p.id DESC LIMIT 50
    ');
    $stmt->execute([$like, $like, $like, $like]);
    $patients = $stmt->fetchAll();
} else {
    // No search yet: pre-load the 10 most recently registered patients (newest first)
    // so the front desk can act on fresh registrations without typing a search.
    $stmt = $pdo->query('
        SELECT p.*, c.name AS city_name,
            dc.name AS discount_category_name, dc.is_active AS discount_category_active,
            (SELECT v.visit_date FROM visits v WHERE v.patient_id = p.id ORDER BY v.visit_date DESC LIMIT 1) AS last_visit,
            (SELECT v.doctor_id FROM visits v WHERE v.patient_id = p.id ORDER BY v.visit_date DESC, v.id DESC LIMIT 1) AS last_doctor_id
        FROM patients p
        LEFT JOIN cities c ON c.id = p.city_id
        LEFT JOIN discount_categories dc ON dc.id = p.discount_category_id
        ORDER BY p.id DESC LIMIT 10
    ');
    $patients = $stmt->fetchAll();
}

// Full-page registration view: ?register=1 shows only the form (no list/search).
// Also forced open when a submit failed validation so the entered data survives.
// Never for doctors — they get read-only lookup, so ?register=1 is ignored.
$showRegister = !$isDoctorReadonly && (isset($_GET['register']) || $error !== '');
$qhActive = $showRegister ? 'register' : 'patients';
$qhBrand = false; // the sidebar already carries the HIMS mark

// Arrived → register from a booking (?booking=ID): pre-fill phone/name/doctor/
// purpose from the booking and pre-answer the popup. Only a still-open booking
// pre-fills — a consumed/cancelled id silently degrades to a plain blank form.
$prefillBooking = null;
if ($showRegister && (int) ($_GET['booking'] ?? 0) > 0) {
    try {
        $pbStmt = $pdo->prepare("
            SELECT id, phone, person_name, doctor_id, doctor_consult_type_id
            FROM bookings WHERE id = ? AND status = 'BOOKED'
        ");
        $pbStmt->execute([(int) $_GET['booking']]);
        $prefillBooking = $pbStmt->fetch() ?: null;
    } catch (Throwable $e) { /* bookings migration not run yet */ }
}

$doctors = $pdo->query("SELECT id, name FROM users WHERE base_role = 'DOCTOR' ORDER BY name")->fetchAll();
// Active discount categories for the admin's assignment dropdown in the results table.
$discountCategories = ($_SESSION['base_role'] ?? '') === 'ADMIN'
    ? $pdo->query('SELECT id, name, consultation_pct FROM discount_categories WHERE is_active = 1 ORDER BY name')->fetchAll()
    : [];
$cities = $pdo->query('SELECT id, name FROM cities ORDER BY name')->fetchAll();
$areasByCity = [];
foreach ($pdo->query("SELECT id, city_id, name FROM areas WHERE status = 'active' ORDER BY name")->fetchAll() as $a) {
    $areasByCity[(int) $a['city_id']][] = ['id' => (int) $a['id'], 'name' => $a['name']];
}

function ageFromDob(?string $dob): ?int {
    if (!$dob) return null;
    return (new DateTime($dob))->diff(new DateTime())->y;
}

$pageTitle = $showRegister ? 'New Patient Registration' : 'Patients';
$headExtra = <<<CSS
<style>

.card { background: var(--card); border-radius: var(--radius-card); border: 1px solid var(--border); box-shadow: var(--shadow-sm); padding: 22px 24px; }
.section-title { font-size: 16px; font-weight: 600; margin-bottom: 2px; }
.section-sub { font-size: 12.5px; color: var(--text-muted); margin-bottom: 16px; }

.btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: var(--radius-btn); border: none; background: linear-gradient(135deg, var(--primary-dark), var(--primary)); color: #fff; font-size: 13.5px; font-weight: 600; cursor: pointer; font-family: inherit; }
.btn:hover { opacity: .92; }
.btn.secondary { background: #fff; color: var(--text-secondary); border: 1px solid var(--border); }
.btn:disabled { opacity: .5; cursor: not-allowed; }

.row-acts { display: inline-flex; gap: 6px; flex-wrap: wrap; }
.qa { border: 1px solid var(--border); background: var(--card); color: var(--text); border-radius: 8px;
      padding: 5px 11px; font: 600 12px inherit; font-family: inherit; cursor: pointer; white-space: nowrap;
      text-decoration: none; }
.qa:hover { border-color: var(--primary); color: var(--primary-dark); }
.qa[disabled] { opacity: .45; cursor: not-allowed; }
.qa[disabled]:hover { border-color: var(--border); color: var(--text); }
.search-card { display: flex; align-items: center; gap: 12px; }
.search-field { flex: 1; position: relative; }
.search-field input { width: 100%; padding: 12px 14px 12px 42px; border-radius: var(--radius-input); border: 1px solid var(--border); background: var(--bg); font-size: 14px; color: var(--text); font-family: inherit; }
.search-field input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.12); background: #fff; }
.search-field .icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-muted); display: flex; }
.search-field .icon svg { width: 17px; height: 17px; }

/* Doctor date-range roster bar */
.range-bar { display: flex; align-items: flex-end; gap: 14px; flex-wrap: wrap; padding: 16px 20px; }
.range-lead { font-size: 13.5px; font-weight: 600; color: var(--text-secondary); align-self: center; }
.range-field { display: flex; flex-direction: column; gap: 4px; }
.range-field label { font-size: 11px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--text-muted); }
.range-field input[type=date] { padding: 8px 10px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 13.5px; background: var(--bg); color: var(--text); }
.range-field input[type=date]:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.12); background: #fff; }

table { width: 100%; border-collapse: collapse; }
th { text-align: left; font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); padding: 0 10px 10px; font-weight: 600; }
td { padding: 12px 10px; border-top: 1px solid var(--border); font-size: 13.5px; }
.person { display: flex; align-items: center; gap: 10px; font-weight: 600; }
.person-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--primary-light); color: var(--primary-dark); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex-shrink: 0; }
.muted { color: var(--text-muted); font-size: 12.5px; }
.mrn { font-family: 'Courier New', monospace; font-size: 12px; color: var(--text-secondary); }
.gender-tag { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 20px; background: #F1F5F9; color: var(--text-secondary); }
.wa-link { display: inline-flex; align-items: center; gap: 6px; color: var(--text-muted); text-decoration: none; font-size: 12.5px; }
.wa-link svg { width: 15px; height: 15px; color: #25D366; flex-shrink: 0; }
.wa-link:hover { color: #128C7E; text-decoration: underline; }
.unpaid-badge { display: inline-block; margin-left: 6px; font-size: 10.5px; font-weight: 700; padding: 2px 8px; border-radius: 20px; background: var(--red-bg); color: var(--red-text); white-space: nowrap; }
.dc-badge { display: inline-block; font-size: 11px; font-weight: 700; padding: 2px 9px; border-radius: 20px; background: var(--primary-light); color: var(--primary-dark); white-space: nowrap; }
.dc-select { padding: 6px 8px; border: 1px solid var(--border); border-radius: 8px; font: inherit; font-size: 12px; background: #fff; color: var(--text-secondary); max-width: 140px; }
.dc-select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.12); }
.edit-link { font-size: 12.5px; font-weight: 600; color: var(--primary); }
.edit-link:hover { text-decoration: underline; }
.empty-state { padding: 32px 10px; text-align: center; color: var(--text-muted); font-size: 13px; }

.panel-overlay { display: none; position: fixed; inset: 0; background: rgba(15,23,42,.45); z-index: 50; overflow-y: auto; padding: 40px 20px; }
.panel-overlay.open { display: block; }
.panel { max-width: 860px; margin: 0 auto; }

.form-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; margin-bottom: 20px; flex-wrap: wrap; background: var(--card); border-radius: var(--radius-card); padding: 20px 24px; box-shadow: var(--shadow-sm); }
.form-header h1 { font-size: 20px; font-weight: 700; color: var(--text); }
.form-header .sub { font-size: 13px; color: var(--text-muted); margin-top: 4px; }
.form-header .close-btn { width: 36px; height: 36px; border-radius: 50%; background: var(--bg); border: 1px solid var(--border); color: var(--text-secondary); display: flex; align-items: center; justify-content: center; cursor: pointer; flex-shrink: 0; }
.form-header .close-btn:hover { background: var(--red-bg); color: var(--red-text); }
.form-header .close-btn svg { width: 16px; height: 16px; }

form.patient-form { display: flex; flex-direction: column; gap: 20px; }

.section { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-card); box-shadow: var(--shadow-sm); overflow: hidden; }
.section-head { display: flex; align-items: center; gap: 12px; padding: 18px 24px; border-bottom: 1px solid var(--border); }
.section-head .icon-badge { width: 34px; height: 34px; border-radius: 10px; background: var(--primary-light); color: var(--primary-dark); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.section-head .icon-badge svg { width: 17px; height: 17px; }
.section-head .titles { flex: 1; min-width: 0; }
.section-head .titles h2 { font-size: 15px; font-weight: 600; }
.section-head .titles .desc { font-size: 12.5px; color: var(--text-muted); margin-top: 1px; }
.section-head .count-chip { font-size: 11.5px; font-weight: 700; color: var(--text-secondary); background: var(--bg); border: 1px solid var(--border); border-radius: 20px; padding: 3px 10px; flex-shrink: 0; }
.section-body { padding: 22px 24px 24px; }

.field-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
.field { display: flex; flex-direction: column; gap: 6px; }
.field.full { grid-column: 1 / -1; }
.field label { font-size: 12.5px; font-weight: 600; color: var(--text-secondary); display: flex; align-items: center; gap: 5px; }
.field label .opt { font-weight: 500; color: var(--text-muted); }
.field label .req { color: var(--red); }
.field label .locked-tag { font-size: 10.5px; font-weight: 700; color: var(--text-muted); text-transform: none; letter-spacing: 0; }
.field label .auto-tag { font-size: 10.5px; font-weight: 700; color: var(--green-text); background: var(--green-bg); padding: 1px 7px; border-radius: 20px; text-transform: uppercase; letter-spacing: .02em; }
.field input, .field select { width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-input); font-size: 13.5px; font-family: inherit; background: var(--bg); color: var(--text); }
.field input:focus, .field select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); background: var(--card); }
.field input:disabled { background: var(--border); cursor: not-allowed; color: var(--text-muted); }
.field input.over-cap { border-color: var(--red); box-shadow: 0 0 0 3px rgba(220,38,38,.12); }
.field .hint { font-size: 11.5px; color: var(--text-muted); }
.field .hint.warn { color: var(--red-text); font-weight: 600; }
.field .hint.ok { color: var(--green-text); font-weight: 600; }
.seq-field.locked { opacity: .5; pointer-events: none; }
.seq-step-num { display: inline-flex; align-items: center; justify-content: center; width: 16px; height: 16px; border-radius: 50%; background: var(--border); color: var(--text-secondary); font-size: 10px; font-weight: 700; margin-right: 5px; flex-shrink: 0; }

.radio-row { display: flex; gap: 8px; }
.radio-pill { flex: 1; }
.radio-pill input { position: absolute; opacity: 0; width: 0; height: 0; }
.radio-pill label { display: flex; align-items: center; justify-content: center; padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-input); font-size: 13px; font-weight: 600; color: var(--text-secondary); background: var(--bg); cursor: pointer; transition: all .15s ease; }
.radio-pill input:checked + label { background: var(--primary-light); border-color: var(--primary); color: var(--primary-dark); }

/* ---------- Register Patient — full-page layout ---------- */
.content:has(.reg-page) { padding-top: 20px; padding-bottom: 24px; }
.reg-page { display: flex; flex-direction: column; gap: 14px; }
.reg-page .patient-form { display: flex; flex-direction: column; gap: 14px; }
.group-label .flow-close { flex: 0 0 auto; width: 28px; height: 28px; margin: -4px 0; }
.group-label .flow-close svg { width: 15px; height: 15px; }

/* ---------- Single continuous form: floating-label fields (compact) ---------- */
.form-flow { display: flex; flex-direction: column; gap: 10px; background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-card); box-shadow: var(--shadow-sm); padding: 16px 22px; }
.group-label { font-size: 10.5px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--text-muted); display: flex; align-items: center; gap: 10px; margin-top: 4px; }
.group-label:first-child { margin-top: 0; }
.group-label::after { content: ""; flex: 1; height: 1px; background: var(--border); }
.group-chip { font-size: 10px; font-weight: 700; letter-spacing: .02em; color: var(--primary-dark); background: var(--primary-light); border-radius: 20px; padding: 2px 9px; text-transform: none; }

.form-flow .field-grid { gap: 10px 14px; }
.f { position: relative; }
.f.full { grid-column: 1 / -1; }
.f > input, .f > select { width: 100%; height: 46px; padding: 18px 14px 4px; border: 1px solid var(--border-strong); border-radius: var(--radius-input); font-size: 13.5px; font-family: inherit; background: var(--card); color: var(--text); transition: border-color .15s, box-shadow .15s; }
.f > select { padding-top: 16px; padding-bottom: 2px; appearance: none; cursor: pointer; background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2394A3B8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M6 9l6 6 6-6'/></svg>"); background-repeat: no-repeat; background-position: right 14px center; }
.f > input:focus, .f > select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); }
.f > input:disabled, .f > select:disabled { background: var(--bg); color: var(--text-muted); cursor: not-allowed; }
.f > input.over-cap { border-color: var(--red); box-shadow: 0 0 0 3px rgba(220,38,38,.12); }

.f .flabel { position: absolute; left: 15px; top: 23px; transform: translateY(-50%); pointer-events: none; font-size: 13.5px; font-weight: 500; color: var(--text-muted); transition: all .15s ease; display: flex; align-items: center; gap: 5px; white-space: nowrap; }
.f > input:focus ~ .flabel,
.f > input:not(:placeholder-shown) ~ .flabel,
.f > select:focus ~ .flabel,
.f > select.filled ~ .flabel,
.f .flabel.always-float { top: 12px; transform: none; font-size: 10px; font-weight: 700; letter-spacing: .02em; color: var(--primary); }
.f > input:not(:focus):not(:placeholder-shown) ~ .flabel,
.f > select.filled:not(:focus) ~ .flabel { color: var(--text-secondary); }
.f .flabel .req { color: var(--red); }
.f .flabel .opt { color: var(--text-muted); font-weight: 500; }
.f .flabel .locked-tag { font-size: 9.5px; font-weight: 700; color: var(--text-muted); }
.f .flabel .auto-tag { font-size: 9.5px; font-weight: 700; color: var(--green-text); background: var(--green-bg); padding: 1px 6px; border-radius: 6px; }
.f > input::placeholder { color: transparent; }
.f > input:focus::placeholder { color: var(--text-muted); }
.f .hint { display: block; font-size: 10.5px; color: var(--text-muted); margin-top: 3px; padding-left: 2px; }
.f .hint.warn { color: var(--red-text); font-weight: 600; }
.f .hint.ok { color: var(--green-text); font-weight: 600; }
.f .mini-label { font-size: 11.5px; font-weight: 700; color: var(--text-secondary); margin-bottom: 6px; display: flex; gap: 5px; align-items: center; }
.f .mini-label .req { color: var(--red); }
.f .pct-suffix { position: absolute; right: 14px; top: 26px; font-size: 13px; color: var(--text-muted); font-weight: 600; pointer-events: none; }
.f .seq-step-num { margin-right: 6px; }

/* compact gender pills */
.form-flow .radio-pill label { padding: 8px 12px; }

/* phone: country code + number in one connected control */
.phone-wrap { position: relative; display: flex; align-items: stretch; border: 1px solid var(--border-strong); border-radius: var(--radius-input); background: var(--card); transition: border-color .15s, box-shadow .15s; }
.phone-wrap:focus-within { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); }
.cc-select { flex: 0 0 auto; width: 90px; border: none; background: transparent; color: var(--text); font-family: inherit; font-size: 13.5px; font-weight: 600; padding: 18px 22px 4px 14px; border-radius: var(--radius-input) 0 0 var(--radius-input); cursor: pointer; appearance: none; height: 46px; background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2394A3B8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M6 9l6 6 6-6'/></svg>"); background-repeat: no-repeat; background-position: right 10px center; }
.cc-select:focus { outline: none; }
.cc-divider { width: 1px; background: var(--border); margin: 10px 0; }
.phone-wrap input { flex: 1; height: 46px; border: none; background: transparent; box-shadow: none; padding: 18px 14px 4px 12px; border-radius: 0 var(--radius-input) var(--radius-input) 0; min-width: 0; font-size: 13.5px; font-family: inherit; color: var(--text); }
.phone-wrap input:focus { outline: none; box-shadow: none; }
.phone-wrap .flabel { left: 116px; }
.phone-wrap input:focus ~ .flabel,
.phone-wrap input:not(:placeholder-shown) ~ .flabel { top: 12px; left: 104px; }
.phone-wrap input::placeholder { color: transparent; }
.phone-wrap input:focus::placeholder { color: var(--text-muted); }

.info-banner { display: flex; gap: 12px; align-items: flex-start; background: var(--primary-light); border-radius: 14px; padding: 14px 16px; color: var(--primary-dark); font-size: 12.5px; }
.info-banner svg { width: 16px; height: 16px; flex-shrink: 0; margin-top: 1px; }

.form-footer { display: flex; align-items: center; justify-content: flex-end; gap: 12px; background: var(--card); border: 1px solid var(--border); padding: 12px 22px; border-radius: var(--radius-card); box-shadow: var(--shadow-sm); }
.form-footer .foot-note { margin-right: auto; display: flex; align-items: center; gap: 7px; font-size: 11.5px; color: var(--text-muted); max-width: 46ch; }
.form-footer .foot-note svg { width: 15px; height: 15px; flex-shrink: 0; color: var(--primary); }
@media (max-width: 720px) { .form-footer .foot-note { display: none; } }

.match-banner { display: none; background: var(--amber-bg); border: 1px solid #FDE68A; border-radius: 14px; padding: 14px 16px; gap: 12px; align-items: flex-start; color: var(--amber-text); font-size: 12.5px; }
.match-banner.show { display: flex; }
.match-banner svg { width: 16px; height: 16px; flex-shrink: 0; margin-top: 1px; }
.match-banner .match-list { margin-top: 8px; display: flex; flex-direction: column; gap: 6px; }
.match-row { display: flex; align-items: center; justify-content: space-between; background: #fff; border-radius: 10px; padding: 8px 12px; }
.match-row .name { font-weight: 600; color: var(--text); font-size: 13px; }
.match-row .meta { font-size: 11.5px; color: var(--text-muted); }

.alert { border-radius: 14px; padding: 14px 18px; font-size: 13.5px; }
.alert.error { background: var(--red-bg); color: var(--red-text); }
.alert.success { background: var(--green-bg); color: var(--green-text); }

.patient-strip { display: flex; align-items: center; gap: 16px; }
.patient-strip .person-avatar { width: 48px; height: 48px; font-size: 16px; }
.patient-strip .p-name { font-size: 17px; font-weight: 700; }
.patient-strip .p-meta { font-size: 12.5px; color: var(--text-muted); margin-top: 2px; }
.queue-token { text-align: center; padding: 28px; }
.queue-token .num { font-size: 44px; font-weight: 800; color: var(--primary-dark); line-height: 1; }
.queue-token .label { font-size: 12px; color: var(--text-muted); margin-top: 6px; text-transform: uppercase; letter-spacing: .05em; }

</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'patients';
require __DIR__ . '/partials/sidebar.php';
?>
        <?php require __DIR__ . '/partials/quick_header.php'; ?>

        <div class="content">
            <?php if (!$showRegister): ?>
            <div class="page-head">
                <div>
                    <div class="page-title"><?= $isDoctorReadonly ? 'Find Patient' : 'Patients' ?></div>
                    <div class="page-sub"><?= $isDoctorReadonly ? 'Look up a patient by name, phone or MRN' : 'Search existing patients or register someone new' ?></div>
                </div>
                <?php if (!$isDoctorReadonly): ?><a class="btn" href="patients.php?register=1">+ Register Patient</a><?php endif; ?>
            </div>

            <?php if ($success): ?>
                <div class="alert success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <form class="card search-card" method="GET" action="patients.php">
                <div class="search-field">
                    <span class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg></span>
                    <input type="text" name="q" placeholder="<?= $isDoctorReadonly ? 'Search patients you have seen…' : "Search by name, phone, father's name, or MRN..." ?>" value="<?= htmlspecialchars($q) ?>">
                </div>
                <button class="btn secondary" type="submit">Search</button>
            </form>

            <?php if ($isDoctorReadonly && $q === ''): ?>
            <!-- Doctor date-range roster: default shows the recent 10; pick a range for more. -->
            <form class="card range-bar" method="GET" action="patients.php">
                <div class="range-lead">Show patients I saw</div>
                <div class="range-field"><label for="d-from">From</label><input id="d-from" type="date" name="from" value="<?= htmlspecialchars($docFrom) ?>" max="<?= date('Y-m-d') ?>"></div>
                <div class="range-field"><label for="d-to">To</label><input id="d-to" type="date" name="to" value="<?= htmlspecialchars($docTo ?: date('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>"></div>
                <button class="btn" type="submit">Apply range</button>
                <?php if ($docRangeActive): ?><a class="btn secondary" href="patients.php">Back to recent 10</a><?php endif; ?>
            </form>
            <?php endif; ?>

            <?php if ($q !== '' || !empty($patients) || ($isDoctorReadonly && $docRangeActive)): ?>
            <div class="card">
                <?php if ($q !== ''): ?>
                <div class="section-title">Results</div>
                <div class="section-sub"><?= count($patients) ?> patient<?= count($patients) === 1 ? '' : 's' ?> matched<?= $isDoctorReadonly ? ' from your visits' : '' ?></div>
                <?php elseif ($isDoctorReadonly && $docRangeActive): ?>
                <div class="section-title">Patients I saw · <?= date('d M Y', strtotime($docFrom)) ?> – <?= date('d M Y', strtotime($docTo)) ?></div>
                <div class="section-sub"><?= count($patients) ?> patient<?= count($patients) === 1 ? '' : 's' ?><?= count($patients) >= 500 ? ' (showing first 500 — narrow the range)' : '' ?></div>
                <?php elseif ($isDoctorReadonly): ?>
                <div class="section-title">Recent 10</div>
                <div class="section-sub">The last <?= count($patients) ?> patient<?= count($patients) === 1 ? '' : 's' ?> you saw — pick a date range above for more</div>
                <?php else: ?>
                <div class="section-title">Recently Registered</div>
                <div class="section-sub">Last <?= count($patients) ?> registration<?= count($patients) === 1 ? '' : 's' ?> — newest first</div>
                <?php endif; ?>
                <?php if (empty($patients)): ?>
                    <div class="empty-state"><?= $q !== '' ? 'No patients found for "' . htmlspecialchars($q) . '".' : 'You have not seen any patients in this date range.' ?></div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr><th>Patient</th><th>Father / Guardian</th><th>Phone</th><th>DOB / Gender</th><th>MRN</th><th><?= $isDoctorReadonly ? 'Last Seen' : 'Last Visit' ?></th><th>Discount</th><?php if (!$isDoctorReadonly): ?><th>Actions</th><?php endif; ?><?php if (($_SESSION['base_role'] ?? '') === 'ADMIN'): ?><th></th><?php endif; ?></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($patients as $p): ?>
                        <tr>
                            <td><div class="person"><span class="person-avatar"><?= htmlspecialchars(strtoupper(substr($p['name'], 0, 1))) ?></span>
                                <span><?= htmlspecialchars($p['name']) ?>
                                <?php if (!empty($p['unpaid_flag'])): ?>
                                    <span class="unpaid-badge" title="Previously left with an unpaid balance">&#9888; Unpaid Rs <?= number_format((float) ($p['unpaid_total'] ?? 0)) ?><?= (int) ($p['unpaid_count'] ?? 0) > 1 ? ' (' . (int) $p['unpaid_count'] . '×)' : '' ?></span>
                                <?php endif; ?>
                                </span>
                            </div></td>
                            <td class="muted"><?= htmlspecialchars($p['father_name'] ?: '—') ?></td>
                            <td class="muted">
                                <?php if ($p['phone']): ?>
                                <!-- Phone stored as E.164 (+92300...) — wa.me wants digits only. Opens an empty
                                     WhatsApp chat; the thank-you quick-message lives on the Today queue instead. -->
                                <a class="wa-link" href="https://wa.me/<?= preg_replace('/\D/', '', $p['phone']) ?>" target="_blank" rel="noopener" title="Message on WhatsApp">
                                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.64.07-.3-.15-1.26-.46-2.4-1.47-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.6-.91-2.2-.24-.58-.49-.5-.67-.5h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48 0 1.46 1.06 2.87 1.21 3.07.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.69.62.71.23 1.36.2 1.87.12.57-.08 1.76-.72 2-1.41.25-.7.25-1.29.18-1.42-.08-.12-.28-.2-.57-.34zM12.04 21.5h-.01a9.4 9.4 0 0 1-4.79-1.31l-.34-.2-3.56.93.95-3.47-.22-.36a9.4 9.4 0 0 1-1.44-5.02c0-5.2 4.24-9.43 9.45-9.43a9.4 9.4 0 0 1 6.68 2.77 9.37 9.37 0 0 1 2.76 6.67c0 5.2-4.24 9.43-9.44 9.43zm8.03-17.46A11.3 11.3 0 0 0 12.04.66C5.8.66.72 5.73.72 11.97c0 1.99.52 3.94 1.51 5.66L.63 23.5l6-1.57a11.34 11.34 0 0 0 5.4 1.37h.01c6.24 0 11.32-5.07 11.32-11.31 0-3.02-1.18-5.87-3.29-8.01z"/></svg>
                                    <?= htmlspecialchars($p['phone']) ?>
                                </a>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td><span class="gender-tag"><?= $p['dob'] ? date('d/m/Y', strtotime($p['dob'])) . ' · ' : '' ?><?= htmlspecialchars(substr($p['gender'], 0, 1)) ?></span></td>
                            <td class="mrn"><?= htmlspecialchars($p['mrn']) ?></td>
                            <td class="muted"><?= $p['last_visit'] ? date('d/m/Y', strtotime($p['last_visit'])) : '—' ?></td>
                            <td>
                                <?php if (($_SESSION['base_role'] ?? '') === 'ADMIN'): ?>
                                <!-- Admin: assign the standing discount category; auto-saves on change. -->
                                <form method="POST" action="patients.php?q=<?= urlencode($q) ?>" style="margin:0;">
                                    <input type="hidden" name="action" value="set_discount_category">
                                    <input type="hidden" name="patient_id" value="<?= (int) $p['id'] ?>">
                                    <select name="discount_category_id" class="dc-select" onchange="this.form.submit()" title="Standing discount category — auto-applies to all future invoices">
                                        <option value="">— None —</option>
                                        <?php foreach ($discountCategories as $dc): ?>
                                        <option value="<?= (int) $dc['id'] ?>" <?= (int) ($p['discount_category_id'] ?? 0) === (int) $dc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($dc['name']) ?></option>
                                        <?php endforeach; ?>
                                        <?php if (!empty($p['discount_category_id']) && empty($p['discount_category_active'])): ?>
                                        <option value="<?= (int) $p['discount_category_id'] ?>" selected><?= htmlspecialchars($p['discount_category_name']) ?> (inactive)</option>
                                        <?php endif; ?>
                                    </select>
                                </form>
                                <?php elseif (!empty($p['discount_category_name']) && !empty($p['discount_category_active'])): ?>
                                <!-- Reception: read-only badge; assignment is admin-only. -->
                                <span class="dc-badge" title="Standing discount — auto-applied on billing"><?= htmlspecialchars($p['discount_category_name']) ?></span>
                                <?php else: ?>
                                <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <?php if (!$isDoctorReadonly): ?>
                            <td>
                                <div class="row-acts">
                                    <button type="button" class="qa" onclick="openFollowup(<?= (int) $p['id'] ?>, <?= htmlspecialchars(json_encode($p['name']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($p['mrn']), ENT_QUOTES) ?>, <?= (int) ($p['last_doctor_id'] ?? 0) ?>)">New invoice</button>
                                    <?php if (has_permission('ADMISSION_ADMIT_PATIENT')): ?>
                                    <!-- Admit from the all-patients list: the shared handler reuses today's
                                         visit or creates a shell (byPatient=true). -->
                                    <button type="button" class="qa" onclick="openAdmit(<?= (int) $p['id'] ?>, <?= htmlspecialchars(json_encode($p['name']), ENT_QUOTES) ?>, 0, '', true)">Admit</button>
                                    <?php endif; ?>
                                    <!-- Procedure billing (e.g. ear piercing) is a separate, one-time flow — placeholder for a later phase. -->
                                    <button class="qa" disabled title="Procedure billing is coming in a later phase">Procedure</button>
                                </div>
                            </td>
                            <?php endif; ?>
                            <?php if (($_SESSION['base_role'] ?? '') === 'ADMIN'): ?>
                            <td>
                                <form method="POST" action="patients.php" style="display:inline;" onsubmit="return confirm('Permanently delete <?= htmlspecialchars(addslashes($p['name'])) ?> (MRN <?= htmlspecialchars($p['mrn']) ?>)? This removes all their visit history and can\'t be undone.');">
                                    <input type="hidden" name="action" value="delete_patient">
                                    <input type="hidden" name="patient_id" value="<?= (int) $p['id'] ?>">
                                    <button type="submit" class="edit-link" style="background:none;border:none;padding:0;font:inherit;cursor:pointer;color:var(--red-text);">Delete</button>
                                </form>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; // end !$showRegister ?>

            <?php if ($showRegister): ?>
            <!-- Register Patient — full page -->
            <div class="reg-page">
                <?php if ($error && isset($duplicateVisit)): ?>
                    <?php // Duplicate same-day visit — offer "go to it" or a deliberate override. ?>
                    <div class="alert error" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;justify-content:space-between;">
                        <span><?= htmlspecialchars($error) ?></span>
                        <span style="display:inline-flex;gap:8px;flex-shrink:0;">
                            <a class="qa" href="receptionist.php">Go to today's queue</a>
                            <form method="POST" action="patients.php" style="display:inline;">
                                <input type="hidden" name="action" value="register_followup">
                                <input type="hidden" name="force_revisit" value="1">
                                <input type="hidden" name="patient_id" value="<?= (int) ($_POST['patient_id'] ?? 0) ?>">
                                <input type="hidden" name="doctor_id" value="<?= (int) ($_POST['doctor_id'] ?? 0) ?>">
                                <input type="hidden" name="doctor_consult_type_id" value="<?= (int) ($_POST['doctor_consult_type_id'] ?? 0) ?>">
                                <input type="hidden" name="payment_mode" value="<?= htmlspecialchars($_POST['payment_mode'] ?? '') ?>">
                                <input type="hidden" name="override_full" value="<?= htmlspecialchars($_POST['override_full'] ?? '') ?>">
                                <input type="hidden" name="booking_dismissed" value="1">
                                <button type="submit" class="qa warn">Register anyway</button>
                            </form>
                        </span>
                    </div>
                <?php elseif ($error): ?>
                    <div class="alert error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

        <form class="patient-form" method="POST" action="patients.php" id="patientForm">
            <input type="hidden" name="action" value="register_patient">
            <!-- Booking-match guard state: booking_id = "Yes, this visit is that
                 appointment" (consumed at save); booking_dismissed = "No, separate
                 walk-in" (booking stays live). Both empty → the POST rejects and
                 re-renders; re-entering the phone re-asks via the popup. -->
            <input type="hidden" name="booking_id" id="regBookingId" value="">
            <input type="hidden" name="booking_dismissed" id="regBookingDismissed" value="">

            <?php if ($pendingBookings): ?>
            <!-- Server-side guard tripped (client popup bypassed). The re-render
                 doesn't echo the submitted values, so no resubmit shortcut here —
                 name who's booked and let the phone field re-trigger the popup. -->
            <div class="match-banner show">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/></svg>
                <div style="flex:1;">
                    That number has a booking today:
                    <?php foreach ($pendingBookings as $i => $pb): ?><?= $i ? '; ' : ' ' ?><b><?= htmlspecialchars($pb['person_name']) ?></b> (<?= htmlspecialchars($pb['doctor_name']) ?>, <?= htmlspecialchars($pb['purpose']) ?>)<?php endforeach; ?>.
                    Re-enter the phone number below — you'll be asked whether this visit is that appointment.
                </div>
            </div>
            <?php endif; ?>

            <div class="match-banner" id="matchBanner">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg>
                <div style="flex:1;">
                    Possible match found on name, father's name &amp; date of birth — confirm this isn't the same patient before continuing.
                    <div class="match-list" id="matchList"></div>
                </div>
            </div>

            <div class="form-flow">

                <div class="group-label">
                    Patient
                    <a href="patients.php" class="close-btn flow-close" aria-label="Close">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </a>
                </div>
                <div class="field-grid">
                    <div class="f full">
                        <input type="text" id="name" name="name" class="uc" placeholder=" " required autofocus>
                        <span class="flabel" data-for="name">Full Name <span class="req">*</span></span>
                    </div>
                    <div class="f">
                        <input type="text" id="father_name" name="father_name" class="uc" placeholder=" ">
                        <span class="flabel" data-for="father_name">Father / Guardian Name <span class="opt">(optional)</span></span>
                    </div>
                    <div class="f">
                        <div class="phone-wrap">
                            <select class="cc-select" id="phone_cc" name="phone_cc">
                                <option value="+92" selected>🇵🇰 +92</option>
                                <option value="+1">🇺🇸 +1</option>
                                <option value="+44">🇬🇧 +44</option>
                                <option value="+91">🇮🇳 +91</option>
                                <option value="+971">🇦🇪 +971</option>
                                <option value="+966">🇸🇦 +966</option>
                            </select>
                            <div class="cc-divider"></div>
                            <input type="tel" id="phone" name="phone" inputmode="numeric" placeholder="3001234567"
                                   maxlength="10" title="10 digits, and it can't start with 0" required>
                            <span class="flabel" data-for="phone">Phone <span class="req">*</span></span>
                        </div>
                        <span class="hint">10-digit mobile number — don't type the leading 0.</span>
                    </div>
                    <div class="f">
                        <input type="date" id="dob" name="dob" class="always-float" placeholder=" ">
                        <span class="flabel always-float">Date of Birth <span class="opt">(optional)</span></span>
                        <span class="hint">Leave blank if unknown</span>
                    </div>
                    <div class="f">
                        <input type="email" id="email" name="email" placeholder=" " autocomplete="email">
                        <span class="flabel" data-for="email">Email <span class="opt">(optional)</span></span>
                    </div>
                    <div class="f">
                        <div class="mini-label">Gender <span class="req">*</span></div>
                        <div class="radio-row">
                            <div class="radio-pill"><input type="radio" id="gender_f" name="gender" value="FEMALE"><label for="gender_f">Female</label></div>
                            <div class="radio-pill"><input type="radio" id="gender_m" name="gender" value="MALE"><label for="gender_m">Male</label></div>
                            <div class="radio-pill"><input type="radio" id="gender_o" name="gender" value="OTHER"><label for="gender_o">Other</label></div>
                        </div>
                    </div>
                </div>

                <div class="field-grid">
                    <div class="f">
                        <select id="city" name="city_id" required>
                            <option value=""></option>
                            <?php foreach ($cities as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="flabel" data-for="city">City <span class="req">*</span></span>
                        <span class="hint">Drives branch-expansion reporting — pick the closest match</span>
                    </div>
                    <div class="f seq-field locked" id="areaField">
                        <select id="area" name="area_id" disabled required>
                            <option value=""></option>
                        </select>
                        <span class="flabel" data-for="area">Area <span class="req">*</span></span>
                    </div>
                    <div class="f full" id="newAreaField" style="display:none;">
                        <div class="mini-label">New Area Name <span class="req">*</span></div>
                        <div style="display:flex; gap:8px;">
                            <input type="text" id="new_area" placeholder="e.g. Bahria Town Phase 8" style="flex:1; height:auto;">
                            <button type="button" class="btn secondary" id="addAreaBtn" style="white-space:nowrap;">+ Add &amp; Use</button>
                        </div>
                        <span class="hint">Usable immediately for this patient — flagged for admin to review and merge duplicates later</span>
                        <div id="areaAddedNote" style="display:none; color:var(--green-text); font-size:11.5px; font-weight:600; margin-top:4px;"></div>
                    </div>
                </div>

                <div class="group-label">Consultation <span class="group-chip">Today's visit</span></div>
                <div class="field-grid">
                    <div class="f">
                        <select id="doctor" name="doctor_id" required>
                            <option value=""></option>
                            <?php foreach ($doctors as $d): ?>
                            <option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="flabel" data-for="doctor"><span class="seq-step-num">1</span>Doctor <span class="req">*</span></span>
                    </div>
                    <div class="f seq-field locked" id="typeField">
                        <select id="consult_type" name="doctor_consult_type_id" disabled required>
                            <option value=""></option>
                        </select>
                        <span class="flabel" data-for="consult_type"><span class="seq-step-num">2</span>Consultation Type <span class="req">*</span> <span class="auto-tag" id="typeAutoTag" style="display:none;">Auto</span></span>
                        <span class="hint" id="typeHint">Waiting on doctor selection</span>
                    </div>
                    <div class="f seq-field locked" id="feeField">
                        <input type="text" id="fee_display" class="always-float" readonly disabled placeholder="—">
                        <span class="flabel always-float"><span class="seq-step-num">3</span>Consultation Fee <span class="locked-tag">🔒 Locked</span></span>
                        <span class="hint">Set by doctor + consultation type — never editable by reception, at registration or checkout</span>
                    </div>
                    <div class="f seq-field locked" id="discountField">
                        <input type="number" id="discount_pct" name="discount_pct" placeholder=" " min="0" step="0.5" style="padding-right:34px;">
                        <span class="pct-suffix">%</span>
                        <span class="flabel" data-for="discount_pct"><span class="seq-step-num">4</span>Discount <span class="opt">(optional)</span></span>
                        <span class="hint" id="discountHint">Your cap: up to <?= htmlspecialchars($currentUser['max_discount_pct']) ?>% — enforced when the bill is saved</span>
                    </div>
                    <div class="f">
                        <div class="mini-label">Payment Mode <span class="req">*</span></div>
                        <div class="radio-row">
                            <div class="radio-pill"><input type="radio" id="pay_cash" name="payment_mode" value="CASH" required><label for="pay_cash">Cash</label></div>
                            <div class="radio-pill"><input type="radio" id="pay_digital" name="payment_mode" value="DIGITAL"><label for="pay_digital">Online / Card</label></div>
                        </div>
                    </div>
                </div>

            </div>

            <div class="form-footer">
                <span class="foot-note">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                    A unique MRN is generated on save and the visit goes straight to the doctor's queue.
                </span>
                <a href="patients.php" class="btn secondary">Cancel</a>
                <!-- Register-only is a distinct SUBMIT button: its name/value is sent ONLY
                     when it is the button that submits, so the server can never confuse a
                     full registration for a backfill (no stuck hidden flag possible). -->
                <button type="submit" class="btn secondary" name="register_only" value="1" id="registerOnlyBtn" formnovalidate title="Save the patient's details only — no consultation, no invoice, no queue token">Register only</button>
                <button type="submit" class="btn" id="submitBtn">Register &amp; Add to Queue</button>
            </div>
        </form>
            </div>
            <?php endif; // end $showRegister ?>
        </div>
    </div>
</div>

<!-- Follow-up "New invoice" panel (returning patient) -->
<style>
.fu-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,.45); z-index:60; align-items:center; justify-content:center; padding:20px; }
.fu-overlay.open { display:flex; }
.fu-modal { background:var(--card); border-radius:var(--radius-card); width:100%; max-width:460px; box-shadow:var(--shadow-lg); overflow:hidden; }
.fu-head { display:flex; align-items:flex-start; justify-content:space-between; padding:20px 22px 4px; }
.fu-eyebrow { font-size:11px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:var(--text-muted); }
.fu-name { font-size:18px; font-weight:700; margin-top:2px; }
.fu-x { background:none; border:none; font-size:24px; line-height:1; color:var(--text-muted); cursor:pointer; }
.fu-body { padding:10px 22px 4px; display:flex; flex-direction:column; gap:14px; }
.fu-body label { display:block; font-size:12.5px; font-weight:600; color:var(--text-secondary); margin-bottom:6px; }
.fu-body select { width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:var(--radius-input); font:inherit; font-size:13.5px; background:var(--bg); color:var(--text); }
.fu-body select:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(26,127,126,.15); background:#fff; }
.fu-quote { border:1px solid var(--border); border-radius:12px; padding:12px 14px; background:var(--bg); display:flex; justify-content:space-between; align-items:center; }
.fu-quote .lbl { font-size:12.5px; color:var(--text-secondary); }
.fu-quote .fee { font-size:18px; font-weight:700; font-variant-numeric:tabular-nums; }
.fu-quote .tag { font-size:11px; font-weight:700; padding:2px 8px; border-radius:20px; background:var(--primary-light); color:var(--primary-dark); margin-top:2px; display:inline-block; }
.fu-pay { display:flex; gap:8px; }
.fu-pay label.pill { flex:1; border:1px solid var(--border); border-radius:12px; padding:10px; text-align:center; font-size:13px; font-weight:600; cursor:pointer; }
.fu-pay label.pill:has(input:checked) { border-color:var(--primary); background:var(--primary-light); color:var(--primary-dark); }
.fu-pay input { display:none; }
.fu-override { font-size:12px; color:var(--text-secondary); display:flex; align-items:center; gap:7px; }
.fu-foot { display:flex; justify-content:flex-end; gap:10px; padding:16px 22px 22px; }
</style>
<div class="fu-overlay" id="fuOverlay" onclick="if(event.target===this)fuClose()">
    <div class="fu-modal" role="dialog" aria-modal="true">
        <form method="POST" action="patients.php" id="fuForm">
            <input type="hidden" name="action" value="register_followup">
            <input type="hidden" name="patient_id" id="fuPatientId">
            <!-- Booking-match guard state — see the registration form's twin fields. -->
            <input type="hidden" name="booking_id" id="fuBookingId" value="">
            <input type="hidden" name="booking_dismissed" id="fuBookingDismissed" value="">
            <div class="fu-head">
                <div>
                    <div class="fu-eyebrow">New consultation invoice</div>
                    <div class="fu-name" id="fuName">—</div>
                </div>
                <button type="button" class="fu-x" onclick="fuClose()" aria-label="Close">&times;</button>
            </div>
            <div class="fu-body">
                <div>
                    <label>Doctor</label>
                    <select name="doctor_id" id="fuDoctor" required onchange="fuLoadTypes()">
                        <option value="">Select doctor…</option>
                        <?php foreach ($doctors as $d): ?><option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Consultation type</label>
                    <select name="doctor_consult_type_id" id="fuType" required onchange="fuQuote()" disabled>
                        <option value="">Select doctor first…</option>
                    </select>
                </div>
                <div class="fu-quote">
                    <div>
                        <div class="lbl">Fee</div>
                        <div id="fuTag" class="tag" style="display:none;"></div>
                    </div>
                    <div class="fee" id="fuFee">—</div>
                </div>
                <label class="fu-override"><input type="checkbox" name="override_full" value="1" id="fuOverride" onchange="fuApplyOverride()"> Charge full fee instead (override follow-up discount)</label>
                <div>
                    <label>Payment mode</label>
                    <div class="fu-pay">
                        <label class="pill"><input type="radio" name="payment_mode" value="CASH" checked> Cash</label>
                        <label class="pill"><input type="radio" name="payment_mode" value="DIGITAL"> Online / Card</label>
                    </div>
                </div>
            </div>
            <div class="fu-foot">
                <button type="button" class="btn secondary" onclick="fuClose()">Cancel</button>
                <button type="submit" class="btn" id="fuSubmit" disabled>Create invoice &amp; add to queue</button>
            </div>
        </form>
    </div>
</div>
<!-- Booking-match guard (Popup B) — shared by registration and follow-up.
     "Yes" pre-fills doctor/purpose (still editable) and stashes booking_id; the
     SAVE consumes the booking, not this click. "No" leaves the booking live. -->
<style>
.bg-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,.45); z-index:80; align-items:center; justify-content:center; padding:20px; }
.bg-overlay.open { display:flex; }
.bg-modal { background:var(--card); border-radius:var(--radius-card); width:100%; max-width:460px; box-shadow:var(--shadow-lg); overflow:hidden; }
.bg-head { padding:20px 22px 6px; }
.bg-eyebrow { font-size:11px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:var(--primary); }
.bg-title { font-size:17px; font-weight:700; margin-top:2px; }
.bg-sub { font-size:12.5px; color:var(--text-muted); margin-top:3px; }
.bg-body { padding:10px 22px 6px; display:flex; flex-direction:column; gap:8px; }
.bg-row { display:flex; align-items:center; gap:12px; border:1px solid var(--border); border-radius:12px; padding:11px 14px; cursor:pointer; }
.bg-row:hover { border-color:var(--primary); background:var(--primary-light); }
.bg-row .b-name { font-size:13.5px; font-weight:600; }
.bg-row .b-meta { font-size:12px; color:var(--text-muted); margin-top:1px; }
.bg-row .b-yes { margin-left:auto; font-size:11.5px; font-weight:700; color:var(--primary-dark); white-space:nowrap; }
.bg-foot { display:flex; justify-content:flex-end; gap:10px; padding:14px 22px 20px; }
</style>
<div class="bg-overlay" id="bgOverlay">
    <div class="bg-modal" role="dialog" aria-modal="true" aria-labelledby="bgTitle">
        <div class="bg-head">
            <div class="bg-eyebrow">Booking found</div>
            <div class="bg-title" id="bgTitle">This number has a booking today</div>
            <div class="bg-sub">Is this visit that appointment? Choosing it pre-fills the doctor and purpose — you can still change them at the counter.</div>
        </div>
        <div class="bg-body" id="bgList"></div>
        <div class="bg-foot">
            <button type="button" class="btn secondary" id="bgNoBtn">No — separate walk-in</button>
        </div>
    </div>
</div>
<script>
// ---------------- Booking-match guard (Popup B) ----------------
// bgAsk(bookings, onYes, onNo): shows the popup once per lookup. The AJAX
// pre-check drives it; the POST re-checks server-side so a bypassed popup
// still gets caught (see pending_booking_guard()).
let bgOnYes = null, bgOnNo = null;
function bgEscape(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function bgAsk(bookings, onYes, onNo) {
    if (!bookings || !bookings.length) { return; }
    bgOnYes = onYes; bgOnNo = onNo;
    const list = document.getElementById('bgList');
    list.innerHTML = '';
    bookings.forEach(b => {
        const row = document.createElement('div');
        row.className = 'bg-row';
        row.innerHTML = '<span><span class="b-name">' + bgEscape(b.person_name) + '</span>'
            + '<div class="b-meta">' + bgEscape(b.doctor_name) + ' &middot; ' + bgEscape(b.purpose)
            + (b.preferred_time ? ' &middot; ' + bgEscape(b.preferred_time) : '') + '</div></span>'
            + '<span class="b-yes">Yes — use booking</span>';
        row.addEventListener('click', () => {
            document.getElementById('bgOverlay').classList.remove('open');
            if (bgOnYes) bgOnYes(b);
        });
        list.appendChild(row);
    });
    document.getElementById('bgOverlay').classList.add('open');
}
document.getElementById('bgNoBtn').addEventListener('click', () => {
    document.getElementById('bgOverlay').classList.remove('open');
    if (bgOnNo) bgOnNo();
});
// Deliberately NO backdrop-click / Escape close: the receptionist must answer
// Yes or No — silently dismissing is exactly the mistake this popup prevents.
</script>
<script>
let fuFullFee = 0, fuQuoteFee = 0, fuCatPct = 0, fuCatName = '', fuQuoteLabel = '', fuQuoteType = '';
function openFollowup(pid, name, mrn, lastDoctorId) {
    document.getElementById('fuPatientId').value = pid;
    document.getElementById('fuName').textContent = name + '  ·  ' + mrn;
    document.getElementById('fuDoctor').value = '';
    const t = document.getElementById('fuType'); t.innerHTML = '<option value="">Select doctor first…</option>'; t.disabled = true;
    document.getElementById('fuFee').textContent = '—';
    document.getElementById('fuTag').style.display = 'none';
    document.getElementById('fuOverride').checked = false;
    document.getElementById('fuSubmit').disabled = true;
    document.getElementById('fuBookingId').value = '';
    document.getElementById('fuBookingDismissed').value = '';
    document.getElementById('fuOverlay').classList.add('open');

    // Pre-fill the patient's most-recent-visit doctor (still changeable) and load that
    // doctor's default consultation type + quote. A live booking, if any, overrides this.
    const dSel = document.getElementById('fuDoctor');
    if (lastDoctorId && dSel.querySelector('option[value="' + lastDoctorId + '"]')) {
        dSel.value = String(lastDoctorId);
        fuLoadTypes();   // no explicit type -> defaults to the doctor's first type + quotes
    }

    // Booking-match guard: does this patient (or their phone) have a live
    // booking today? Ask before the desk fills the panel.
    fetch('patients.php?action=booking_check&patient_id=' + pid)
        .then(r => r.json())
        .then(res => {
            bgAsk(res.bookings, function (b) {
                // Yes: stash the id (consumed at save) and pre-fill — still editable.
                // The booked doctor/purpose wins over the last-visit auto-fill.
                document.getElementById('fuBookingId').value = b.id;
                if (dSel.querySelector('option[value="' + b.doctor_id + '"]')) {
                    dSel.value = String(b.doctor_id);
                    fuLoadTypes(b.doctor_consult_type_id);
                }
            }, function () {
                document.getElementById('fuBookingDismissed').value = '1';
            });
        });
}
function fuClose() { document.getElementById('fuOverlay').classList.remove('open'); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') fuClose(); });

function fuLoadTypes(preselectTypeId) {
    const did = document.getElementById('fuDoctor').value;
    const t = document.getElementById('fuType');
    t.innerHTML = '<option value="">Loading…</option>'; t.disabled = true;
    document.getElementById('fuFee').textContent = '—'; document.getElementById('fuTag').style.display='none';
    document.getElementById('fuSubmit').disabled = true;
    if (!did) { t.innerHTML = '<option value="">Select doctor first…</option>'; return; }
    fetch('patients.php?action=doctor_consult_types&doctor_id=' + did)
        .then(r => r.json())
        .then(types => {
            t.innerHTML = '<option value="">Select type…</option>';
            types.forEach(ct => {
                const o = document.createElement('option');
                o.value = ct.id; o.textContent = ct.label + ' (Rs ' + Math.round(ct.fee) + ')';
                t.appendChild(o);
            });
            t.disabled = false;
            // Booking pre-fill: select the booked purpose and quote it (editable).
            if (preselectTypeId && t.querySelector('option[value="' + preselectTypeId + '"]')) {
                t.value = String(preselectTypeId);
                fuQuote();
            } else if (!preselectTypeId && types.length) {
                // No explicit type: default to the doctor's default consultation type
                // (or the first one) and quote it, so the fee shows immediately.
                // Reception can still change it.
                const def = types.find(ct => Number(ct.is_default) === 1) || types[0];
                t.value = String(def.id);
                fuQuote();
            }
        });
}
function fuQuote() {
    const pid = document.getElementById('fuPatientId').value;
    const did = document.getElementById('fuDoctor').value;
    const ctid = document.getElementById('fuType').value;
    if (!ctid) { document.getElementById('fuSubmit').disabled = true; return; }
    fetch('patients.php?action=revisit_quote&patient_id=' + pid + '&doctor_id=' + did + '&consult_type_id=' + ctid)
        .then(r => r.json())
        .then(q => {
            fuFullFee = q.full_fee; fuQuoteFee = q.fee;
            fuCatPct = q.category_pct || 0; fuCatName = q.category_name || '';
            fuQuoteLabel = q.label || ''; fuQuoteType = q.fee_type || '';
            renderQuote(q.fee, q.label, q.fee_type);
            document.getElementById('fuSubmit').disabled = false;
        });
}
function renderQuote(fee, label, feeType) {
    document.getElementById('fuFee').textContent = 'Rs ' + Math.round(fee);
    const tag = document.getElementById('fuTag');
    // The patient's standing category discount always shows alongside any follow-up rate.
    const parts = [];
    if (feeType && feeType !== 'FULL' && label) { parts.push(label); }
    if (fuCatPct > 0) { parts.push(fuCatName + ' −' + fuCatPct + '%'); }
    if (parts.length) { tag.textContent = parts.join(' + '); tag.style.display = 'inline-block'; }
    else { tag.style.display = 'none'; }
}
function fuApplyOverride() {
    if (document.getElementById('fuOverride').checked) {
        // Override cancels only the follow-up rate — the category discount is
        // the patient's standing entitlement and still applies (matches server).
        const fee = fuFullFee * (1 - fuCatPct / 100);
        renderQuote(fee, 'Full fee (override)', 'FULL');
    } else { renderQuote(fuQuoteFee, fuQuoteLabel, fuQuoteType); }
}

// ---------------- Register-only (backfill, no visit/invoice) ----------------
// "Register only" is a native submit button (name="register_only" value="1",
// formnovalidate) — so which button was pressed is unambiguous server-side and the
// consultation `required` fields don't block a backfill. formnovalidate also skips the
// +92 phone check, so we re-apply just that one rule here (name/phone/gender are
// re-validated server-side regardless).
(function () {
    var btn = document.getElementById('registerOnlyBtn');
    if (!btn) { return; }
    btn.addEventListener('click', function (e) {
        var pInput = document.getElementById('phone');
        var pCc = document.getElementById('phone_cc');
        var nameOk = (document.getElementById('name').value.trim() !== '');
        var phoneOk = !((pCc ? pCc.value : '+92') === '+92') || /^[1-9]\d{9}$/.test(pInput.value);
        if (!nameOk || !phoneOk) {
            e.preventDefault();
            if (!phoneOk) {
                pInput.setCustomValidity('Enter a 10-digit mobile number that does not start with 0.');
                pInput.reportValidity();
            } else {
                document.getElementById('name').reportValidity();
            }
        }
    });
})();

// ---------------- Double-submit guard ----------------
// Registration now SETTLES the consultation bill on save (config/billing.php), so a
// double-click would create two paid bills = a phantom collection. Disable the submit
// button on the first submit; the browser still posts the (already-populated) form once.
// Guarded per-form so a validation-blocked submit doesn't lock the user out — we only
// disable when the form actually passes its own submit handlers.
['patientForm', 'fuForm'].forEach(function (id) {
    var form = document.getElementById(id);
    if (!form) { return; }
    form.addEventListener('submit', function (e) {
        // Disable the button that actually submitted (the patient form now has two:
        // Register-only and Register & Add to Queue). Fall back to the first submit
        // button if the browser doesn't report a submitter.
        var btn = e.submitter || form.querySelector('button[type="submit"]');
        // Defer so this runs after any other submit handler that might cancel it.
        setTimeout(function () {
            if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
        }, 0);
    });
});
</script>

<?php
// A follow-up visit shows the same confirmation + auto-print as a new registration.
if ($followupVisit && !$successVisit) { $successVisit = $followupVisit; }
?>
<?php if ($successVisit): ?>
<!-- Consultation / Queue confirmation -->
<div class="panel-overlay open" id="queuePage">
    <div class="panel">
        <div class="form-header">
            <div>
                <h1>Consultation Queue</h1>
                <div class="sub">
                    <?= isset($successVisit['fee_type']) ? 'Follow-up visit added' : 'Patient added' ?> — waiting to see <?= htmlspecialchars($successVisit['doctor_name']) ?>
                    <?php if (!empty($successVisit['reason']) && ($successVisit['fee_type'] ?? '') !== 'FULL'): ?>
                        &middot; <b style="color:var(--primary);"><?= htmlspecialchars($successVisit['reason']) ?> (Rs <?= number_format((float) $successVisit['fee']) ?>)</b>
                    <?php endif; ?>
                </div>
            </div>
            <a href="patients.php" class="close-btn" aria-label="Close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </a>
        </div>
        <div class="section">
            <div class="section-body">
                <div class="patient-strip">
                    <span class="person-avatar"><?= htmlspecialchars(strtoupper(substr($successVisit['patient_name'], 0, 1))) ?></span>
                    <div>
                        <div class="p-name"><?= htmlspecialchars($successVisit['patient_name']) ?> <span class="mrn"><?= htmlspecialchars($successVisit['mrn']) ?></span></div>
                        <div class="p-meta">
                            <?= $successVisit['father_name'] ? 'Father: ' . htmlspecialchars($successVisit['father_name']) . ' · ' : '' ?>
                            <?= htmlspecialchars($successVisit['type_label']) ?> · <?= htmlspecialchars($successVisit['doctor_name']) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card queue-token" style="margin-top:20px;">
            <div class="num"><?= (int) $successVisit['token_no'] ?></div>
            <div class="label">Queue Token</div>
        </div>
        <div class="form-footer" style="justify-content:center; gap:10px; box-shadow:none; border-top:none; background:transparent;">
            <a href="patients.php" class="btn secondary">Back to Patients</a>
            <a href="checkout.php?print=1&amp;bill_id=<?= (int) $successVisit['bill_id'] ?>" target="_blank" rel="noopener" class="btn" id="invoiceLink">Print Invoice</a>
        </div>
    </div>
</div>
<script>
// Pop the invoice automatically. Blocked popups still leave the button above as a fallback.
window.addEventListener('load', function () {
    window.open(document.getElementById('invoiceLink').href, '_blank');
});
</script>
<?php endif; ?>

<?php if ($showRegister): ?>
<script>
const areasByCity = <?= json_encode($areasByCity) ?>;
const cityNames = <?= json_encode(array_column($cities, 'name', 'id')) ?>;

// ---- Floating-label helper: mark a select "filled" so its label stays up ----
function setFilled(sel) { sel.classList.toggle('filled', !!sel.value); }
document.querySelectorAll('.f > select').forEach(sel => {
    setFilled(sel);
    sel.addEventListener('change', () => setFilled(sel));
});

// ---- Phone: keep digits only, auto-drop leading zero(s) ----
const phoneInput = document.getElementById('phone');
const phoneCcSel = document.getElementById('phone_cc');
phoneInput.addEventListener('input', () => {
    // Digits only, drop leading zeros. For Pakistan (+92) a mobile local number is
    // exactly 10 digits, so cap it there; other country codes keep their own lengths.
    let d = phoneInput.value.replace(/\D/g, '').replace(/^0+/, '');
    if ((phoneCcSel ? phoneCcSel.value : '+92') === '+92') { d = d.slice(0, 10); }
    if (phoneInput.value !== d) phoneInput.value = d;
});
// Re-apply the cap when the country code changes (e.g. switching back to +92).
if (phoneCcSel) {
    phoneCcSel.addEventListener('change', () => phoneInput.dispatchEvent(new Event('input')));
}
// Block submit on an invalid +92 number with a clear message (server re-checks).
const regForm = document.getElementById('patientForm');
if (regForm) {
    regForm.addEventListener('submit', (e) => {
        if ((phoneCcSel ? phoneCcSel.value : '+92') === '+92'
            && !/^[1-9]\d{9}$/.test(phoneInput.value)) {
            e.preventDefault();
            phoneInput.setCustomValidity('Enter a 10-digit mobile number that does not start with 0.');
            phoneInput.reportValidity();
        }
    });
    phoneInput.addEventListener('input', () => phoneInput.setCustomValidity(''));
}

const doctorSelect = document.getElementById('doctor');
const typeField = document.getElementById('typeField');
const typeSelect = document.getElementById('consult_type');
const typeHint = document.getElementById('typeHint');
const typeAutoTag = document.getElementById('typeAutoTag');
const feeField = document.getElementById('feeField');
const feeDisplay = document.getElementById('fee_display');

let currentDoctorTypes = [];
// Set by the booking-match guard before it triggers a doctor change: carries
// the booked purpose across the async type-list rebuild (one-shot).
let bookingPreselectTypeId = null;

function applyFee() {
    const chosen = currentDoctorTypes.find(t => String(t.id) === typeSelect.value);
    feeDisplay.value = chosen ? ('Rs ' + Number(chosen.fee).toLocaleString()) : '';
}

doctorSelect.addEventListener('change', () => {
    if (!doctorSelect.value) {
        typeField.classList.add('locked');
        feeField.classList.add('locked');
        typeSelect.disabled = true;
        typeSelect.innerHTML = '<option value=""></option>';
        setFilled(typeSelect);
        typeHint.textContent = 'Waiting on doctor selection';
        typeAutoTag.style.display = 'none';
        feeDisplay.value = '';
        currentDoctorTypes = [];
        return;
    }

    fetch('patients.php?action=doctor_consult_types&doctor_id=' + encodeURIComponent(doctorSelect.value))
        .then(r => r.json())
        .then(types => {
            currentDoctorTypes = types;
            typeField.classList.remove('locked');
            feeField.classList.remove('locked');
            typeSelect.disabled = false;

            if (!types.length) {
                typeSelect.innerHTML = '<option value="">No consultation types set up for this doctor</option>';
                setFilled(typeSelect);
                typeHint.textContent = 'Ask admin to add consultation types on this doctor\'s profile';
                feeDisplay.value = '';
                return;
            }

            // A booking pre-fill wins over the doctor's default type (one-shot).
            const booked = bookingPreselectTypeId ? types.find(t => Number(t.id) === Number(bookingPreselectTypeId)) : null;
            bookingPreselectTypeId = null;
            const defaultType = booked || types.find(t => Number(t.is_default) === 1) || types[0];
            typeSelect.innerHTML = types.map(t =>
                `<option value="${t.id}" ${t.id === defaultType.id ? 'selected' : ''}>${t.label}</option>`
            ).join('');
            setFilled(typeSelect);

            if (types.length === 1) {
                typeAutoTag.style.display = 'inline-flex';
                typeHint.textContent = 'Only one consultation type on file for this doctor — pre-filled';
            } else {
                typeAutoTag.style.display = 'none';
                typeHint.textContent = `Defaulted to "${defaultType.label}" — set as this doctor's default`;
            }

            applyFee();
        });
});

typeSelect.addEventListener('change', applyFee);

// ---- City -> Area dependency ----
const citySelect = document.getElementById('city');
const areaField = document.getElementById('areaField');
const areaSelect = document.getElementById('area');
const newAreaField = document.getElementById('newAreaField');
const newAreaInput = document.getElementById('new_area');
const addAreaBtn = document.getElementById('addAreaBtn');
const areaAddedNote = document.getElementById('areaAddedNote');

function renderAreaOptions(selectValue) {
    const areas = areasByCity[citySelect.value] || [];
    areaSelect.innerHTML = '<option value=""></option>' +
        areas.map(a => `<option value="${a.id}">${a.name}</option>`).join('') +
        '<option value="__other">+ Add new area...</option>';
    if (selectValue) areaSelect.value = selectValue;
    setFilled(areaSelect);
}

citySelect.addEventListener('change', () => {
    newAreaField.style.display = 'none';
    areaAddedNote.style.display = 'none';

    if (!citySelect.value) {
        areaField.classList.add('locked');
        areaSelect.disabled = true;
        areaSelect.innerHTML = '<option value=""></option>';
        setFilled(areaSelect);
        return;
    }

    areaField.classList.remove('locked');
    areaSelect.disabled = false;
    renderAreaOptions();
});

areaSelect.addEventListener('change', () => {
    if (areaSelect.value === '__other') {
        newAreaField.style.display = '';
        newAreaInput.focus();
    } else {
        newAreaField.style.display = 'none';
    }
});

addAreaBtn.addEventListener('click', () => {
    const name = newAreaInput.value.trim();
    if (!name) { newAreaInput.focus(); return; }

    const body = new URLSearchParams({ action: 'quick_add_area', city_id: citySelect.value, name });
    fetch('patients.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
        .then(r => r.json())
        .then(res => {
            if (res.error) { areaAddedNote.textContent = res.error; areaAddedNote.style.color = 'var(--red-text)'; areaAddedNote.style.display = ''; return; }

            if (!areasByCity[citySelect.value]) areasByCity[citySelect.value] = [];
            if (!areasByCity[citySelect.value].some(a => a.id === res.id)) {
                areasByCity[citySelect.value].push({ id: res.id, name: res.name });
            }
            renderAreaOptions(res.id);
            newAreaField.style.display = 'none';
            areaAddedNote.style.color = 'var(--green-text)';
            areaAddedNote.textContent = `"${res.name}" added to ${cityNames[citySelect.value]} and selected — pending admin review, but usable right now.`;
            areaAddedNote.style.display = '';
            newAreaInput.value = '';
        });
});

// ---- Discount cap enforcement (client-side hint; server re-validates on save) ----
const CURRENT_USER_MAX_DISCOUNT_PCT = <?= json_encode((float) $currentUser['max_discount_pct']) ?>;
const discountInput = document.getElementById('discount_pct');
const discountHint = document.getElementById('discountHint');

discountInput.addEventListener('input', () => {
    const val = parseFloat(discountInput.value);
    if (!isNaN(val) && val > CURRENT_USER_MAX_DISCOUNT_PCT) {
        discountInput.classList.add('over-cap');
        discountHint.classList.add('warn');
        discountHint.textContent = `Exceeds your ${CURRENT_USER_MAX_DISCOUNT_PCT}% cap — an admin or higher-cap staff member must apply this`;
    } else {
        discountInput.classList.remove('over-cap');
        discountHint.classList.remove('warn');
        discountHint.textContent = `Your cap: up to ${CURRENT_USER_MAX_DISCOUNT_PCT}% — enforced when the bill is saved`;
    }
});

// ---- Duplicate-match check on name / father name / DOB ----
let matchDebounce;
function checkForMatch() {
    clearTimeout(matchDebounce);
    matchDebounce = setTimeout(() => {
        const name = document.getElementById('name').value.trim();
        const father = document.getElementById('father_name').value.trim();
        const dob = document.getElementById('dob').value;
        const banner = document.getElementById('matchBanner');
        const list = document.getElementById('matchList');

        if (name.length < 3) { banner.classList.remove('show'); return; }

        const params = new URLSearchParams({ action: 'check_duplicate', name, father_name: father, dob });
        fetch('patients.php?' + params.toString())
            .then(r => r.json())
            .then(res => {
                if (res.match) {
                    list.innerHTML = `<div class="match-row"><div><div class="name">${res.match.name}</div>
                        <div class="meta">Father: ${res.match.father_name || '—'} &middot; DOB: ${res.match.dob || '—'} &middot; MRN ${res.match.mrn}</div></div></div>`;
                    banner.classList.add('show');
                } else {
                    banner.classList.remove('show');
                }
            });
    }, 350);
}

['name', 'father_name', 'dob'].forEach(id => {
    document.getElementById(id).addEventListener('input', checkForMatch);
    document.getElementById(id).addEventListener('change', checkForMatch);
});

// ---------------- Booking-match guard on the phone field ----------------
// When the typed number matches a live booking today, ask before the desk goes
// any further. Asked once per distinct number; the POST re-checks regardless.
let bkGuardAskedFor = '';
function regBookingCheck() {
    const ccSel = document.getElementById('phone_cc');
    const local = phoneInput.value.replace(/\D/g, '').replace(/^0+/, '');
    if (local.length < 9) return; // wait for a plausibly-complete number
    const e164 = (ccSel ? ccSel.value : '+92') + local;
    if (e164 === bkGuardAskedFor) return;
    bkGuardAskedFor = e164;
    // New number: previous answer no longer applies.
    document.getElementById('regBookingId').value = '';
    document.getElementById('regBookingDismissed').value = '';
    fetch('patients.php?action=booking_check&phone=' + encodeURIComponent(e164))
        .then(r => r.json())
        .then(res => {
            bgAsk(res.bookings, function (b) {
                // Yes: stash the id (consumed at save) and pre-fill — still editable.
                document.getElementById('regBookingId').value = b.id;
                if (b.person_name && !document.getElementById('name').value.trim()) {
                    document.getElementById('name').value = b.person_name;
                }
                if (doctorSelect.querySelector('option[value="' + b.doctor_id + '"]')) {
                    doctorSelect.value = String(b.doctor_id);
                    setFilled(doctorSelect);
                    bookingPreselectTypeId = b.doctor_consult_type_id;
                    doctorSelect.dispatchEvent(new Event('change'));
                }
            }, function () {
                document.getElementById('regBookingDismissed').value = '1';
            });
        })
        .catch(() => {});
}
phoneInput.addEventListener('blur', regBookingCheck);
phoneInput.addEventListener('change', regBookingCheck);

// Arrived → register from bookings.php (?booking=ID): the booking pre-answers
// the popup and pre-fills phone/name/doctor/purpose — everything stays editable.
<?php if ($prefillBooking): ?>
(function () {
    const pb = <?= json_encode([
        'id' => (int) $prefillBooking['id'],
        'phone' => $prefillBooking['phone'],
        'person_name' => $prefillBooking['person_name'],
        'doctor_id' => (int) $prefillBooking['doctor_id'],
        'consult_type_id' => (int) $prefillBooking['doctor_consult_type_id'],
    ]) ?>;
    document.getElementById('regBookingId').value = pb.id;
    bkGuardAskedFor = pb.phone; // suppress the popup — already answered

    // Split E.164 back into the cc dropdown + local digits (fall back to +92).
    const ccSel = document.getElementById('phone_cc');
    let local = pb.phone;
    for (const opt of ccSel.options) {
        if (pb.phone.indexOf(opt.value) === 0) { ccSel.value = opt.value; local = pb.phone.slice(opt.value.length); break; }
    }
    phoneInput.value = local.replace(/\D/g, '');
    document.getElementById('name').value = pb.person_name;

    if (doctorSelect.querySelector('option[value="' + pb.doctor_id + '"]')) {
        doctorSelect.value = String(pb.doctor_id);
        setFilled(doctorSelect);
        bookingPreselectTypeId = pb.consult_type_id;
        doctorSelect.dispatchEvent(new Event('change'));
    }
})();
<?php endif; ?>
</script>
<?php endif; ?>
<?php if ($canAdmitHere) { require __DIR__ . '/partials/admit_modal.php'; } ?>
<?php if ($admitError): ?>
<script>window.addEventListener('load', function () { alert(<?= json_encode($admitError) ?>); });</script>
<?php endif; ?>
<script src="assets/js/date-picker.js"></script>
</body>
</html>
