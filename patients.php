<?php
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/billing.php';
refresh_session_permissions($pdo);
require_permission('RECEPTION_REGISTER_PATIENTS');

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch();

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
    $stmt = $pdo->prepare('SELECT id, label, fee, is_default FROM doctor_consult_types WHERE doctor_id = ? ORDER BY label');
    $stmt->execute([$doctorId]);
    echo json_encode($stmt->fetchAll());
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

// ---------------- Register patient + create visit (one transaction) ----------------
$error = '';
$success = '';
$successVisit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register_patient') {
    $name = trim($_POST['name'] ?? '');
    $fatherName = trim($_POST['father_name'] ?? '');
    // Phone: combine country code + local number into E.164 (e.g. +923001234567).
    // Strip non-digits and any leading zero(s) from the local part before prefixing the code.
    $phoneCc = preg_replace('/[^\d+]/', '', $_POST['phone_cc'] ?? '+92');
    if ($phoneCc === '' || $phoneCc[0] !== '+') { $phoneCc = '+92'; }
    $phoneLocal = ltrim(preg_replace('/\D/', '', $_POST['phone'] ?? ''), '0');
    $phone = $phoneLocal !== '' ? $phoneCc . $phoneLocal : '';
    $dob = trim($_POST['dob'] ?? '') ?: null;
    $gender = $_POST['gender'] ?? '';
    $cnic = trim($_POST['cnic'] ?? '') ?: null;
    $altPhone = trim($_POST['alt_phone'] ?? '') ?: null;
    $cityId = (int) ($_POST['city_id'] ?? 0) ?: null;
    $areaId = (int) ($_POST['area_id'] ?? 0) ?: null;
    $address = trim($_POST['address'] ?? '') ?: null;

    $doctorId = (int) ($_POST['doctor_id'] ?? 0);
    $consultTypeId = (int) ($_POST['doctor_consult_type_id'] ?? 0);
    $paymentMode = $_POST['payment_mode'] ?? '';
    $discountPct = trim($_POST['discount_pct'] ?? '') !== '' ? (float) $_POST['discount_pct'] : 0;

    if ($name === '' || $phone === '' || !in_array($gender, ['MALE', 'FEMALE', 'OTHER'], true)) {
        $error = 'Name, phone, and gender are required.';
    } elseif ($doctorId <= 0 || $consultTypeId <= 0 || !in_array($paymentMode, ['CASH', 'DIGITAL'], true)) {
        $error = 'Doctor, consultation type, and payment mode are required.';
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
        } else {
            $fee = (float) $feeRow['fee'];

            try {
                $pdo->beginTransaction();

                // MRN and queue token both need to be race-safe under concurrent registrations
                // (two receptionists saving at once) — a plain "SELECT MAX(...) + 1" can hand out
                // the same value to both before either commits. Both use an atomic upsert so MySQL
                // serializes concurrent increments via row locking.
                $insertPatient = $pdo->prepare('
                    INSERT INTO patients (mrn, name, father_name, dob, gender, phone, alt_phone, cnic, city_id, area_id, address, created_by_id)
                    VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $insertPatient->execute([
                    $name, $fatherName ?: null, $dob, $gender,
                    $phone, $altPhone, $cnic, $cityId, $areaId, $address, $_SESSION['user_id'],
                ]);
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

                $insertVisit = $pdo->prepare('
                    INSERT INTO visits (token_no, patient_id, doctor_id, doctor_consult_type_id, fee, discount_pct, discount_applied_by_id, payment_mode, visit_date, created_by_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)
                ');
                $insertVisit->execute([
                    $tokenNo, $patientId, $doctorId, $consultTypeId, $fee, $discountPct, $discountBy, $paymentMode, $_SESSION['user_id'],
                ]);
                $visitId = (int) $pdo->lastInsertId();

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

// ---------------- Search ----------------
$q = trim($_GET['q'] ?? '');
$patients = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $stmt = $pdo->prepare('
        SELECT p.*, c.name AS city_name,
            (SELECT v.visit_date FROM visits v WHERE v.patient_id = p.id ORDER BY v.visit_date DESC LIMIT 1) AS last_visit
        FROM patients p
        LEFT JOIN cities c ON c.id = p.city_id
        WHERE p.name LIKE ? OR p.phone LIKE ? OR p.father_name LIKE ? OR p.mrn LIKE ?
        ORDER BY p.name ASC LIMIT 50
    ');
    $stmt->execute([$like, $like, $like, $like]);
    $patients = $stmt->fetchAll();
}

// Full-page registration view: ?register=1 shows only the form (no list/search).
// Also forced open when a submit failed validation so the entered data survives.
$showRegister = isset($_GET['register']) || $error !== '';
$qhActive = $showRegister ? 'register' : 'patients';
$qhBrand = false; // the sidebar already carries the HIMS mark

$doctors = $pdo->query("SELECT id, name FROM users WHERE base_role = 'DOCTOR' ORDER BY name")->fetchAll();
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

table { width: 100%; border-collapse: collapse; }
th { text-align: left; font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); padding: 0 10px 10px; font-weight: 600; }
td { padding: 12px 10px; border-top: 1px solid var(--border); font-size: 13.5px; }
.person { display: flex; align-items: center; gap: 10px; font-weight: 600; }
.person-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--primary-light); color: var(--primary-dark); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex-shrink: 0; }
.muted { color: var(--text-muted); font-size: 12.5px; }
.mrn { font-family: 'Courier New', monospace; font-size: 12px; color: var(--text-secondary); }
.gender-tag { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 20px; background: #F1F5F9; color: var(--text-secondary); }
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
                    <div class="page-title">Patients</div>
                    <div class="page-sub">Search existing patients or register someone new</div>
                </div>
                <a class="btn" href="patients.php?register=1">+ Register Patient</a>
            </div>

            <?php if ($success): ?>
                <div class="alert success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <form class="card search-card" method="GET" action="patients.php">
                <div class="search-field">
                    <span class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg></span>
                    <input type="text" name="q" placeholder="Search by name, phone, father's name, or MRN..." value="<?= htmlspecialchars($q) ?>">
                </div>
                <button class="btn secondary" type="submit">Search</button>
            </form>

            <?php if ($q !== ''): ?>
            <div class="card">
                <div class="section-title">Results</div>
                <div class="section-sub"><?= count($patients) ?> patient<?= count($patients) === 1 ? '' : 's' ?> matched</div>
                <?php if (empty($patients)): ?>
                    <div class="empty-state">No patients found for "<?= htmlspecialchars($q) ?>".</div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr><th>Patient</th><th>Father / Guardian</th><th>Phone</th><th>Age / Gender</th><th>MRN</th><th>Last Visit</th><th>Actions</th><?php if (($_SESSION['base_role'] ?? '') === 'ADMIN'): ?><th></th><?php endif; ?></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($patients as $p): $age = $p['dob'] ? ageFromDob($p['dob']) : null; ?>
                        <tr>
                            <td><div class="person"><span class="person-avatar"><?= htmlspecialchars(strtoupper(substr($p['name'], 0, 1))) ?></span><?= htmlspecialchars($p['name']) ?></div></td>
                            <td class="muted"><?= htmlspecialchars($p['father_name'] ?: '—') ?></td>
                            <td class="muted"><?= htmlspecialchars($p['phone']) ?></td>
                            <td><span class="gender-tag"><?= $age !== null ? $age . ' · ' : '' ?><?= htmlspecialchars(substr($p['gender'], 0, 1)) ?></span></td>
                            <td class="mrn"><?= htmlspecialchars($p['mrn']) ?></td>
                            <td class="muted"><?= $p['last_visit'] ? date('d M Y', strtotime($p['last_visit'])) : '—' ?></td>
                            <td>
                                <div class="row-acts">
                                    <!-- Both inert for now. "New invoice" needs a revisit flow that opens
                                         the register panel prefilled from this patient — ?register=1 alone
                                         would create a duplicate patient record. "Admit" awaits the
                                         short-stay model. Shown disabled so the intent is visible. -->
                                    <button class="qa" disabled title="Billing a returning patient isn't built yet">New invoice</button>
                                    <button class="qa" disabled title="Short-stay admission isn't built yet">Admit</button>
                                </div>
                            </td>
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
                <?php if ($error): ?>
                    <div class="alert error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

        <form class="patient-form" method="POST" action="patients.php" id="patientForm">
            <input type="hidden" name="action" value="register_patient">

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
                        <input type="text" id="name" name="name" placeholder=" " required autofocus>
                        <span class="flabel" data-for="name">Full Name <span class="req">*</span></span>
                    </div>
                    <div class="f">
                        <input type="text" id="father_name" name="father_name" placeholder=" ">
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
                            <input type="tel" id="phone" name="phone" inputmode="numeric" placeholder="3001234567" required>
                            <span class="flabel" data-for="phone">Phone <span class="req">*</span></span>
                        </div>
                        <span class="hint">Type the local number — a leading 0 is dropped automatically.</span>
                    </div>
                    <div class="f">
                        <input type="date" id="dob" name="dob" class="always-float" placeholder=" ">
                        <span class="flabel always-float">Date of Birth <span class="opt">(optional)</span></span>
                        <span class="hint">Leave blank if unknown</span>
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
                <button type="submit" class="btn" id="submitBtn">Register &amp; Add to Queue</button>
            </div>
        </form>
            </div>
            <?php endif; // end $showRegister ?>
        </div>
    </div>
</div>

<?php if ($successVisit): ?>
<!-- Consultation / Queue confirmation -->
<div class="panel-overlay open" id="queuePage">
    <div class="panel">
        <div class="form-header">
            <div>
                <h1>Consultation Queue</h1>
                <div class="sub">Patient added — waiting to see <?= htmlspecialchars($successVisit['doctor_name']) ?></div>
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
phoneInput.addEventListener('input', () => {
    let d = phoneInput.value.replace(/\D/g, '').replace(/^0+/, '');
    if (phoneInput.value !== d) phoneInput.value = d;
});

const doctorSelect = document.getElementById('doctor');
const typeField = document.getElementById('typeField');
const typeSelect = document.getElementById('consult_type');
const typeHint = document.getElementById('typeHint');
const typeAutoTag = document.getElementById('typeAutoTag');
const feeField = document.getElementById('feeField');
const feeDisplay = document.getElementById('fee_display');

let currentDoctorTypes = [];

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

            const defaultType = types.find(t => Number(t.is_default) === 1) || types[0];
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
</script>
<?php endif; ?>
<script src="assets/js/date-picker.js"></script>
</body>
</html>
