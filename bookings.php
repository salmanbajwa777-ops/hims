<?php
/**
 * Bookings — phone-call appointments taken by reception.
 *
 * Design (approved 2026-07-23):
 *   Phone-first, one workflow: type the caller's number, Enter lists every
 *   patient on that phone (families share numbers — never auto-pick), link one
 *   or continue as a new caller with the name as spoken. Doctor + purpose
 *   (the doctor's own consult type) + date. Day-level only — no slot grid;
 *   preferred time is free text. No patient row, no fee: both happen at the
 *   desk on arrival, where the booking is consumed by the visit insert
 *   (patients.php sets visits.booking_id and flips status to ARRIVED in the
 *   same transaction).
 *
 *   The booked doctor is emailed on create and on cancel (config/notify.php).
 *   Warnings, never blocks: doctor marked OFF for that date, or a duplicate
 *   phone+doctor+date (a mother booking two kids is legitimate).
 *   Stale BOOKED rows are swept to NO_SHOW by cron/mark_no_show.php after
 *   22:00 PKT; the manual button here covers early closes.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/notify.php';
refresh_session_permissions($pdo);

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: /index.php');
    exit;
}

if (!has_permission('RECEPTION_REGISTER_PATIENTS') && ($_SESSION['base_role'] ?? '') !== 'ADMIN') {
    http_response_code(403);
    exit('Forbidden — reception access only.');
}

/** Combine country code + local number into E.164, same rule as patients.php. */
function booking_normalize_phone(string $cc, string $local): string {
    $cc = preg_replace('/[^\d+]/', '', $cc);
    if ($cc === '' || $cc[0] !== '+') { $cc = '+92'; }
    $digits = ltrim(preg_replace('/\D/', '', $local), '0');
    return $digits !== '' ? $cc . $digits : '';
}

// ---------------- AJAX: patients on a phone number ----------------
// The phone-first lookup: every patient sharing the caller's number, plus
// today's doctor OFF-list so the form can warn at selection time.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'phone_lookup') {
    header('Content-Type: application/json');
    $phone = booking_normalize_phone($_GET['phone_cc'] ?? '+92', $_GET['phone'] ?? '');
    if ($phone === '') { echo json_encode(['patients' => []]); exit; }
    $stmt = $pdo->prepare('SELECT id, name, mrn, dob, gender FROM patients WHERE phone = ? ORDER BY name');
    $stmt->execute([$phone]);
    echo json_encode(['phone' => $phone, 'patients' => $stmt->fetchAll()]);
    exit;
}

// ---------------- AJAX: duplicate + doctor-off warnings for a draft ----------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'booking_warnings') {
    header('Content-Type: application/json');
    $phone = trim($_GET['phone'] ?? '');
    $doctorId = (int) ($_GET['doctor_id'] ?? 0);
    $date = trim($_GET['date'] ?? '');
    $out = ['duplicates' => [], 'doctor_off' => false];
    if ($phone !== '' && $doctorId > 0 && $date !== '') {
        $d = $pdo->prepare("
            SELECT bk.person_name, dct.label
            FROM bookings bk JOIN doctor_consult_types dct ON dct.id = bk.doctor_consult_type_id
            WHERE bk.phone = ? AND bk.doctor_id = ? AND bk.booking_date = ? AND bk.status = 'BOOKED'
        ");
        $d->execute([$phone, $doctorId, $date]);
        $out['duplicates'] = $d->fetchAll();
    }
    if ($doctorId > 0 && $date !== '') {
        try {
            $t = $pdo->prepare("SELECT 1 FROM doctor_day_timings WHERE doctor_id = ? AND timing_date = ? AND status = 'OFF'");
            $t->execute([$doctorId, $date]);
            $out['doctor_off'] = (bool) $t->fetchColumn();
        } catch (Throwable $e) { /* timings table missing — no warning */ }
    }
    echo json_encode($out);
    exit;
}

$error = '';
$success = '';

// ---------------- Create a booking ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_booking') {
    $phone = booking_normalize_phone($_POST['phone_cc'] ?? '+92', $_POST['phone'] ?? '');
    $patientId = (int) ($_POST['patient_id'] ?? 0) ?: null;
    $personName = trim($_POST['person_name'] ?? '');
    $doctorId = (int) ($_POST['doctor_id'] ?? 0);
    $consultTypeId = (int) ($_POST['doctor_consult_type_id'] ?? 0);
    $bookingDate = trim($_POST['booking_date'] ?? '');
    $preferredTime = trim($_POST['preferred_time'] ?? '') ?: null;
    $note = trim($_POST['note'] ?? '') ?: null;

    // A linked patient's name wins over whatever is in the free-text field —
    // and validates the link actually exists.
    if ($patientId) {
        $pStmt = $pdo->prepare('SELECT name, phone FROM patients WHERE id = ?');
        $pStmt->execute([$patientId]);
        $pRow = $pStmt->fetch();
        if ($pRow) {
            $personName = $pRow['name'];
        } else {
            $patientId = null;
        }
    }

    $dateOk = $bookingDate !== ''
        && ($dt = DateTime::createFromFormat('Y-m-d', $bookingDate)) !== false
        && $dt->format('Y-m-d') === $bookingDate
        && $bookingDate >= date('Y-m-d'); // same-day allowed, past dates not

    if ($phone === '' || $personName === '') {
        $error = 'Phone number and the caller/patient name are required.';
    } elseif (!$dateOk) {
        $error = 'Pick a valid booking date (today or later).';
    } else {
        // Purpose must belong to the chosen doctor — same server-side rule as
        // registration (never trust the client pairing).
        $ctStmt = $pdo->prepare('SELECT label FROM doctor_consult_types WHERE id = ? AND doctor_id = ?');
        $ctStmt->execute([$consultTypeId, $doctorId]);
        if (!$ctStmt->fetch()) {
            $error = 'Pick a doctor and one of their consultation types.';
        } else {
            try {
                $pdo->beginTransaction();
                $ins = $pdo->prepare('
                    INSERT INTO bookings (phone, patient_id, person_name, doctor_id, doctor_consult_type_id,
                                          booking_date, preferred_time, note, created_by_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $ins->execute([
                    $phone, $patientId, $personName, $doctorId, $consultTypeId,
                    $bookingDate, $preferredTime, $note, $_SESSION['user_id'],
                ]);
                $bookingId = (int) $pdo->lastInsertId();

                $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
                    ->execute([$_SESSION['user_id'], 'booking_created',
                        "Booking #$bookingId for $personName ($phone) with doctor #$doctorId on $bookingDate"]);
                $pdo->commit();

                // Email the doctor (best-effort, after commit).
                notify_booking_created($pdo, $bookingId);

                header('Location: bookings.php?created=1&date=' . urlencode($bookingDate));
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = 'Could not save the booking. Please try again.';
            }
        }
    }
}

// ---------------- Cancel a booking ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_booking') {
    $bookingId = (int) ($_POST['booking_id'] ?? 0);
    $reason = trim($_POST['cancel_reason'] ?? '');
    // Only a live booking can be cancelled — consumed/expired ones are history.
    $upd = $pdo->prepare("
        UPDATE bookings SET status = 'CANCELLED', cancelled_by_id = ?, cancelled_at = NOW(), cancel_reason = ?
        WHERE id = ? AND status = 'BOOKED'
    ");
    $upd->execute([$_SESSION['user_id'], $reason ?: null, $bookingId]);
    if ($upd->rowCount()) {
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$_SESSION['user_id'], 'booking_cancelled',
                "Cancelled booking #$bookingId" . ($reason !== '' ? " — $reason" : '')]);
        // Email the doctor it fell through (best-effort).
        notify_booking_cancelled($pdo, $bookingId);
        $success = 'Booking cancelled — the doctor has been notified.';
    } else {
        $error = 'That booking is no longer open.';
    }
}

// ---------------- Manual mark no-show (desk closing early) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_no_show') {
    $bookingId = (int) ($_POST['booking_id'] ?? 0);
    // Only past-or-today bookings can be a no-show — a future booking hasn't
    // had its chance yet.
    $upd = $pdo->prepare("
        UPDATE bookings SET status = 'NO_SHOW'
        WHERE id = ? AND status = 'BOOKED' AND booking_date <= CURDATE()
    ");
    $upd->execute([$bookingId]);
    if ($upd->rowCount()) {
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$_SESSION['user_id'], 'booking_no_show', "Marked booking #$bookingId as no-show"]);
        $success = 'Marked as no-show.';
    } else {
        $error = 'Only an open booking for today or earlier can be marked no-show.';
    }
}

// ---------------- Day list ----------------
$listDate = trim($_GET['date'] ?? '') ?: date('Y-m-d');
if (!(($ld = DateTime::createFromFormat('Y-m-d', $listDate)) && $ld->format('Y-m-d') === $listDate)) {
    $listDate = date('Y-m-d');
}
$filterDoctor = (int) ($_GET['doctor'] ?? 0);

$listSql = "
    SELECT bk.*, dct.label AS purpose, du.name AS doctor_name,
           p.name AS patient_name, p.mrn,
           cu.name AS created_by_name
    FROM bookings bk
    JOIN doctor_consult_types dct ON dct.id = bk.doctor_consult_type_id
    JOIN users du ON du.id = bk.doctor_id
    LEFT JOIN patients p ON p.id = bk.patient_id
    LEFT JOIN users cu ON cu.id = bk.created_by_id
    WHERE bk.booking_date = ?" . ($filterDoctor ? ' AND bk.doctor_id = ?' : '') . "
    ORDER BY FIELD(bk.status, 'BOOKED', 'ARRIVED', 'NO_SHOW', 'CANCELLED'), du.name, bk.created_at
";
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($filterDoctor ? [$listDate, $filterDoctor] : [$listDate]);
$bookings = $listStmt->fetchAll();

$openCount = 0;
foreach ($bookings as $b) {
    if ($b['status'] === 'BOOKED') { $openCount++; }
}

$doctors = $pdo->query("SELECT id, name FROM users WHERE base_role = 'DOCTOR' ORDER BY name")->fetchAll();

$firstName = explode(' ', trim($user['name']))[0] ?? 'there';
$qhActive = 'bookings';
$qhBrand  = false; // the sidebar already carries the HIMS mark

$pageTitle = 'Bookings';
$headExtra = <<<CSS
<style>
.card { background: var(--card); border-radius: var(--radius-card); border: 1px solid var(--border); box-shadow: var(--shadow-sm); padding: 22px 24px; }
.section-title { font-size: 16px; font-weight: 600; margin-bottom: 2px; }
.section-sub { font-size: 12.5px; color: var(--text-muted); margin-bottom: 16px; }
.btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: var(--radius-btn); border: none; background: linear-gradient(135deg, var(--primary-dark), var(--primary)); color: #fff; font-size: 13.5px; font-weight: 600; cursor: pointer; font-family: inherit; text-decoration: none; }
.btn:hover { opacity: .92; }
.btn.secondary { background: var(--card); color: var(--text-secondary); border: 1px solid var(--border); }
.btn:disabled { opacity: .5; cursor: not-allowed; }
.qa { border: 1px solid var(--border); background: var(--card); color: var(--text); border-radius: 8px;
      padding: 5px 11px; font: 600 12px inherit; font-family: inherit; cursor: pointer; white-space: nowrap; text-decoration: none; }
.qa:hover { border-color: var(--primary); color: var(--primary-dark); }
.qa.warn { color: var(--red); }
.alert { border-radius: 14px; padding: 14px 18px; font-size: 13.5px; margin-bottom: 4px; }
.alert.error { background: var(--red-bg); color: var(--red-text); }
.alert.success { background: var(--green-bg); color: var(--green-text); }

/* ---------- Layout: create form + day list ---------- */
.bk-grid { display: grid; grid-template-columns: 400px 1fr; gap: 20px; align-items: start; }
@media (max-width: 1100px) { .bk-grid { grid-template-columns: 1fr; } }

/* ---------- Create form ---------- */
.bk-form { display: flex; flex-direction: column; gap: 14px; }
.bk-form label { display: block; font-size: 12.5px; font-weight: 600; color: var(--text-secondary); margin-bottom: 6px; }
.bk-form input[type=text], .bk-form input[type=tel], .bk-form select {
    width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-input);
    font: inherit; font-size: 13.5px; background: var(--bg); color: var(--text);
}
.bk-form input:focus, .bk-form select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); background: var(--card); }
.bk-form select:disabled { opacity: .55; cursor: not-allowed; }
.bk-form .hint { font-size: 11.5px; color: var(--text-muted); margin-top: 4px; display: block; }

/* Phone-first row: number + Enter/Find */
.bk-phone-row { display: flex; gap: 8px; }
.bk-phone-row .cc { flex: 0 0 84px; }
.bk-phone-row input[type=tel] { flex: 1; min-width: 0; }
.bk-phone-row .find { flex: 0 0 auto; }

/* Patient matches on the phone */
.bk-matches { display: none; flex-direction: column; gap: 6px; }
.bk-matches.show { display: flex; }
.bk-match { display: flex; align-items: center; gap: 10px; border: 1px solid var(--border); border-radius: 12px; padding: 9px 12px; cursor: pointer; background: var(--card); }
.bk-match:hover { border-color: var(--primary); }
.bk-match.selected { border-color: var(--primary); background: var(--primary-light); }
.bk-match .m-avatar { width: 30px; height: 30px; border-radius: 50%; background: var(--primary-light); color: var(--primary-dark); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex-shrink: 0; }
.bk-match.selected .m-avatar { background: var(--card); }
.bk-match .m-name { font-size: 13px; font-weight: 600; }
.bk-match .m-meta { font-size: 11.5px; color: var(--text-muted); }
.bk-match .m-pick { margin-left: auto; font-size: 11px; font-weight: 700; color: var(--primary-dark); }
.bk-newcaller { font-size: 12px; color: var(--text-muted); padding: 2px 2px 0; }
.bk-none { font-size: 12.5px; color: var(--text-muted); padding: 4px 2px; display: none; }
.bk-none.show { display: block; }

/* Warnings (never blocks) */
.bk-warn { display: none; background: var(--amber-bg, #FFFBEB); border: 1px solid #FDE68A; border-radius: 12px; padding: 10px 14px; font-size: 12.5px; color: #92400E; }
.bk-warn.show { display: block; }

/* ---------- Day list ---------- */
.bk-filters { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
.bk-filters input[type=date], .bk-filters select { padding: 8px 10px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 13px; background: var(--bg); color: var(--text); }
.bk-filters .today-link { font-size: 12.5px; font-weight: 600; color: var(--primary); text-decoration: none; }
.bk-filters .today-link:hover { text-decoration: underline; }

.bk-scroll { overflow-x: auto; }
.bk-table { width: 100%; border-collapse: collapse; min-width: 720px; }
.bk-table th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); font-weight: 600; padding: 0 10px 10px; }
.bk-table td { padding: 11px 10px; border-top: 1px solid var(--border); vertical-align: middle; font-size: 13.5px; }
.bk-name { font-weight: 600; font-size: 13.5px; }
.bk-meta { font-size: 11.5px; color: var(--text-muted); }
.mono { font-variant-numeric: tabular-nums; }
.bk-pill { font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 20px; white-space: nowrap; display: inline-block; }
.bk-pill.booked { background: var(--primary-light); color: var(--primary-dark); }
.bk-pill.arrived { background: #EDE7FB; color: #6D28D9; }
.bk-pill.cancelled { background: #FEF2F2; color: #B91C1C; }
.bk-pill.no_show { background: #FFFBEB; color: #92400E; }
.bk-acts { display: inline-flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
.empty-state { padding: 32px 10px; text-align: center; color: var(--text-muted); font-size: 13px; }

/* ---------- Cancel dialog ---------- */
.cx-overlay { display: none; position: fixed; inset: 0; background: rgba(15,23,42,.45); z-index: 60; align-items: center; justify-content: center; padding: 20px; }
.cx-overlay.open { display: flex; }
.cx-modal { background: var(--card); border-radius: var(--radius-card); width: 100%; max-width: 420px; box-shadow: var(--shadow-lg); overflow: hidden; }
.cx-head { display: flex; align-items: flex-start; justify-content: space-between; padding: 20px 22px 6px; }
.cx-eyebrow { font-size: 11px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--text-muted); }
.cx-name { font-size: 17px; font-weight: 700; margin-top: 2px; }
.cx-x { background: none; border: none; font-size: 24px; line-height: 1; color: var(--text-muted); cursor: pointer; }
.cx-body { padding: 10px 22px 4px; }
.cx-body label { display: block; font-size: 12.5px; font-weight: 600; color: var(--text-secondary); margin-bottom: 6px; }
.cx-body select, .cx-body input[type=text] { width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-input); font: inherit; font-size: 13.5px; background: var(--bg); color: var(--text); }
.cx-foot { display: flex; justify-content: flex-end; gap: 10px; padding: 16px 22px 22px; }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'bookings';
require __DIR__ . '/partials/sidebar.php';
?>
        <?php require __DIR__ . '/partials/quick_header.php'; ?>

<div class="content">
    <div>
        <div class="page-title">Bookings</div>
        <div class="page-sub">Phone appointments — booked to a day, converted to a visit on arrival</div>
    </div>

    <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if (isset($_GET['created'])): ?><div class="alert success">Booking saved — the doctor has been emailed.</div><?php endif; ?>

    <div class="bk-grid">

        <!-- ======== Create a booking ======== -->
        <div class="card">
            <div class="section-title">New booking</div>
            <div class="section-sub">Type the caller's number first — existing patients on that phone appear below.</div>

            <form class="bk-form" method="POST" action="bookings.php" id="bkForm">
                <input type="hidden" name="action" value="create_booking">
                <input type="hidden" name="patient_id" id="bkPatientId" value="">

                <div>
                    <label>Caller's phone <span style="color:var(--red);">*</span></label>
                    <div class="bk-phone-row">
                        <select class="cc" name="phone_cc" id="bkCc">
                            <option value="+92" selected>+92</option>
                            <option value="+1">+1</option>
                            <option value="+44">+44</option>
                            <option value="+91">+91</option>
                            <option value="+971">+971</option>
                            <option value="+966">+966</option>
                        </select>
                        <input type="tel" name="phone" id="bkPhone" inputmode="numeric" placeholder="3001234567" required autofocus>
                        <button type="button" class="btn secondary find" id="bkFind">Find</button>
                    </div>
                    <span class="hint">Press Enter or Find — a leading 0 is dropped automatically.</span>
                </div>

                <div class="bk-none" id="bkNone">No patient on file with this number — continue as a new caller below.</div>
                <div class="bk-matches" id="bkMatches"></div>

                <div>
                    <label>Booking for <span style="color:var(--red);">*</span></label>
                    <input type="text" name="person_name" id="bkPerson" placeholder="Name as the caller says it" required>
                    <span class="hint" id="bkPersonHint">Picking a patient above fills and links this automatically.</span>
                </div>

                <div>
                    <label>Doctor <span style="color:var(--red);">*</span></label>
                    <select name="doctor_id" id="bkDoctor" required>
                        <option value="">Select doctor…</option>
                        <?php foreach ($doctors as $d): ?>
                        <option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Purpose <span style="color:var(--red);">*</span></label>
                    <select name="doctor_consult_type_id" id="bkType" required disabled>
                        <option value="">Select doctor first…</option>
                    </select>
                    <span class="hint">The doctor's own consultation / procedure types — the fee is decided at arrival, never on the phone.</span>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div>
                        <label>Date <span style="color:var(--red);">*</span></label>
                        <input type="date" name="booking_date" id="bkDate" value="<?= htmlspecialchars(date('Y-m-d')) ?>" min="<?= htmlspecialchars(date('Y-m-d')) ?>" required
                               style="width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:var(--radius-input); font:inherit; font-size:13.5px; background:var(--bg); color:var(--text);">
                    </div>
                    <div>
                        <label>Preferred time <span style="color:var(--text-muted); font-weight:500;">(optional)</span></label>
                        <input type="text" name="preferred_time" placeholder="e.g. after 5pm" maxlength="40">
                    </div>
                </div>

                <div class="bk-warn" id="bkWarn"></div>

                <div>
                    <label>Note <span style="color:var(--text-muted); font-weight:500;">(optional)</span></label>
                    <input type="text" name="note" placeholder="Anything the purpose list doesn't capture" maxlength="255">
                </div>

                <button type="submit" class="btn" style="justify-content:center;">Save booking &amp; email doctor</button>
            </form>
        </div>

        <!-- ======== Day list ======== -->
        <div class="card">
            <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                <div>
                    <div class="section-title"><?= $listDate === date('Y-m-d') ? 'Today' : date('l, d/m/Y', strtotime($listDate)) ?></div>
                    <div class="section-sub"><?= count($bookings) ?> booking<?= count($bookings) === 1 ? '' : 's' ?> &middot; <?= $openCount ?> still expected</div>
                </div>
                <form class="bk-filters" method="GET" action="bookings.php">
                    <input type="date" name="date" value="<?= htmlspecialchars($listDate) ?>" onchange="this.form.submit()">
                    <select name="doctor" onchange="this.form.submit()">
                        <option value="">All doctors</option>
                        <?php foreach ($doctors as $d): ?>
                        <option value="<?= (int) $d['id'] ?>" <?= $filterDoctor === (int) $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($listDate !== date('Y-m-d') || $filterDoctor): ?>
                    <a class="today-link" href="bookings.php">Today &rarr;</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (empty($bookings)): ?>
                <div class="empty-state">No bookings for this day<?= $filterDoctor ? ' and doctor' : '' ?> yet.</div>
            <?php else: ?>
            <div class="bk-scroll">
            <table class="bk-table">
                <thead>
                    <tr><th>Patient / Caller</th><th>Doctor &middot; Purpose</th><th>Preferred</th><th>Status</th><th style="text-align:right;">Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($bookings as $b): ?>
                    <tr>
                        <td>
                            <div class="bk-name"><?= htmlspecialchars($b['patient_name'] ?: $b['person_name']) ?></div>
                            <div class="bk-meta">
                                <?php if ($b['mrn']): ?><span class="mono"><?= htmlspecialchars($b['mrn']) ?></span> &middot; <?php else: ?>New caller &middot; <?php endif; ?>
                                <?= htmlspecialchars($b['phone']) ?>
                            </div>
                        </td>
                        <td>
                            <div class="bk-name" style="font-size:13px;"><?= htmlspecialchars($b['doctor_name']) ?></div>
                            <div class="bk-meta"><?= htmlspecialchars($b['purpose']) ?><?= $b['note'] ? ' — ' . htmlspecialchars($b['note']) : '' ?></div>
                        </td>
                        <td class="bk-meta"><?= htmlspecialchars($b['preferred_time'] ?: '—') ?></td>
                        <td>
                            <span class="bk-pill <?= strtolower($b['status']) ?>"><?php
                                echo ['BOOKED' => 'Booked', 'ARRIVED' => 'Arrived', 'CANCELLED' => 'Cancelled', 'NO_SHOW' => 'No-show'][$b['status']] ?? $b['status'];
                            ?></span>
                            <?php if ($b['status'] === 'CANCELLED' && $b['cancel_reason']): ?>
                                <div class="bk-meta" style="margin-top:2px;"><?= htmlspecialchars($b['cancel_reason']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <div class="bk-acts">
                                <?php if ($b['status'] === 'BOOKED'): ?>
                                    <?php if ($b['booking_date'] === date('Y-m-d')): ?>
                                        <?php // Arrive → register: jumps into the pre-filled flow; the SAVE there consumes the booking, not this link. ?>
                                        <?php if ($b['patient_id']): ?>
                                            <a class="qa" href="patients.php?q=<?= urlencode($b['mrn']) ?>">Arrived &rarr; invoice</a>
                                        <?php else: ?>
                                            <a class="qa" href="patients.php?register=1&amp;booking=<?= (int) $b['id'] ?>">Arrived &rarr; register</a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <button type="button" class="qa warn"
                                        onclick="openCancel(<?= (int) $b['id'] ?>, <?= htmlspecialchars(json_encode($b['patient_name'] ?: $b['person_name']), ENT_QUOTES) ?>)">Cancel</button>
                                    <?php if ($b['booking_date'] <= date('Y-m-d')): ?>
                                    <form method="POST" action="bookings.php" style="display:inline;" onsubmit="return confirm('Mark this booking as a no-show?');">
                                        <input type="hidden" name="action" value="mark_no_show">
                                        <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                                        <button type="submit" class="qa">No-show</button>
                                    </form>
                                    <?php endif; ?>
                                <?php elseif ($b['status'] === 'ARRIVED' && $b['visit_id']): ?>
                                    <span class="bk-meta">visit #<?= (int) $b['visit_id'] ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>
    </div>
</div>

<!-- Cancel dialog -->
<div class="cx-overlay" id="cxOverlay" onclick="if(event.target===this)closeCancel()">
    <div class="cx-modal" role="dialog" aria-modal="true" aria-labelledby="cxTitle">
        <form method="POST" action="bookings.php">
            <input type="hidden" name="action" value="cancel_booking">
            <input type="hidden" name="booking_id" id="cxBookingId">
            <div class="cx-head">
                <div>
                    <div class="cx-eyebrow">Cancel booking</div>
                    <div class="cx-name" id="cxTitle">—</div>
                </div>
                <button type="button" class="cx-x" onclick="closeCancel()" aria-label="Close">&times;</button>
            </div>
            <div class="cx-body">
                <label>Reason (goes into the doctor's email)</label>
                <select id="cxReasonSel" onchange="document.getElementById('cxReason').value = this.value === '__other' ? '' : this.value; document.getElementById('cxReason').style.display = this.value === '__other' ? '' : 'none';">
                    <option value="Patient called to cancel">Patient called to cancel</option>
                    <option value="Doctor unavailable">Doctor unavailable</option>
                    <option value="Booked by mistake">Booked by mistake</option>
                    <option value="__other">Other…</option>
                </select>
                <input type="text" name="cancel_reason" id="cxReason" value="Patient called to cancel" maxlength="255" style="margin-top:8px; display:none;" placeholder="Type the reason">
            </div>
            <div class="cx-foot">
                <button type="button" class="btn secondary" onclick="closeCancel()">Keep booking</button>
                <button type="submit" class="btn" style="background:var(--red, #B42318);">Cancel booking</button>
            </div>
        </form>
    </div>
</div>

<script>
// ---------------- Phone-first lookup ----------------
const bkPhone = document.getElementById('bkPhone');
const bkCc = document.getElementById('bkCc');
const bkMatches = document.getElementById('bkMatches');
const bkNone = document.getElementById('bkNone');
const bkPatientId = document.getElementById('bkPatientId');
const bkPerson = document.getElementById('bkPerson');
const bkPersonHint = document.getElementById('bkPersonHint');

bkPhone.addEventListener('input', () => {
    let d = bkPhone.value.replace(/\D/g, '').replace(/^0+/, '');
    if (bkPhone.value !== d) bkPhone.value = d;
    // Number changed: any previous link is stale.
    clearPatientLink();
    bkMatches.classList.remove('show');
    bkNone.classList.remove('show');
});
bkPhone.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); doLookup(); }
});
document.getElementById('bkFind').addEventListener('click', doLookup);

function clearPatientLink() {
    bkPatientId.value = '';
    bkPersonHint.textContent = 'Picking a patient above fills and links this automatically.';
    document.querySelectorAll('.bk-match.selected').forEach(el => el.classList.remove('selected'));
}

function doLookup() {
    if (!bkPhone.value) { bkPhone.focus(); return; }
    const params = new URLSearchParams({ action: 'phone_lookup', phone_cc: bkCc.value, phone: bkPhone.value });
    fetch('bookings.php?' + params.toString())
        .then(r => r.json())
        .then(res => {
            bkMatches.innerHTML = '';
            clearPatientLink();
            if (!res.patients || !res.patients.length) {
                bkMatches.classList.remove('show');
                bkNone.classList.add('show');
                bkPerson.focus();
                return;
            }
            bkNone.classList.remove('show');
            // Families share phones — list EVERY patient, never auto-pick.
            res.patients.forEach(p => {
                const row = document.createElement('div');
                row.className = 'bk-match';
                row.innerHTML = '<span class="m-avatar">' + escapeHtml((p.name || '?').charAt(0).toUpperCase()) + '</span>'
                    + '<span><span class="m-name">' + escapeHtml(p.name) + '</span>'
                    + '<div class="m-meta">MRN ' + escapeHtml(p.mrn || '—') + (p.dob ? ' · DOB ' + escapeHtml(p.dob) : '') + '</div></span>'
                    + '<span class="m-pick">Select</span>';
                row.addEventListener('click', () => {
                    const wasSelected = row.classList.contains('selected');
                    clearPatientLink();
                    if (!wasSelected) {
                        row.classList.add('selected');
                        bkPatientId.value = p.id;
                        bkPerson.value = p.name;
                        bkPersonHint.textContent = 'Linked to MRN ' + (p.mrn || '—') + ' — click again to unlink.';
                    }
                    refreshWarnings();
                });
                bkMatches.appendChild(row);
            });
            const note = document.createElement('div');
            note.className = 'bk-newcaller';
            note.textContent = 'Booking for someone else on this number? Just type their name below.';
            bkMatches.appendChild(note);
            bkMatches.classList.add('show');
        });
}

function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// ---------------- Doctor → purpose (same fetch as registration) ----------------
const bkDoctor = document.getElementById('bkDoctor');
const bkType = document.getElementById('bkType');

bkDoctor.addEventListener('change', () => {
    bkType.innerHTML = '<option value="">Loading…</option>';
    bkType.disabled = true;
    refreshWarnings();
    if (!bkDoctor.value) { bkType.innerHTML = '<option value="">Select doctor first…</option>'; return; }
    fetch('patients.php?action=doctor_consult_types&doctor_id=' + encodeURIComponent(bkDoctor.value))
        .then(r => r.json())
        .then(types => {
            if (!types.length) {
                bkType.innerHTML = '<option value="">No consultation types set up for this doctor</option>';
                return;
            }
            const def = types.find(t => Number(t.is_default) === 1) || types[0];
            bkType.innerHTML = '<option value="">Select purpose…</option>' + types.map(t =>
                '<option value="' + t.id + '"' + (t.id === def.id ? ' selected' : '') + '>' + escapeHtml(t.label) + '</option>'
            ).join('');
            bkType.disabled = false;
        });
});

// ---------------- Warnings: duplicates + doctor OFF (warn, never block) ----------------
const bkDate = document.getElementById('bkDate');
const bkWarn = document.getElementById('bkWarn');
let warnDebounce;

function refreshWarnings() {
    clearTimeout(warnDebounce);
    warnDebounce = setTimeout(() => {
        bkWarn.classList.remove('show');
        if (!bkDoctor.value || !bkDate.value) return;
        const phone = bkPhone.value
            ? bkCc.value + bkPhone.value.replace(/\D/g, '').replace(/^0+/, '')
            : '';
        const params = new URLSearchParams({ action: 'booking_warnings', phone, doctor_id: bkDoctor.value, date: bkDate.value });
        fetch('bookings.php?' + params.toString())
            .then(r => r.json())
            .then(res => {
                const msgs = [];
                if (res.doctor_off) {
                    msgs.push('This doctor is marked <b>OFF</b> for that date in the timings sheet — booking is allowed, but confirm with the caller.');
                }
                if (res.duplicates && res.duplicates.length) {
                    msgs.push('Already booked on this number for the same doctor and date: '
                        + res.duplicates.map(d => '<b>' + escapeHtml(d.person_name) + '</b> (' + escapeHtml(d.label) + ')').join(', ')
                        + ' — fine if it\'s another family member.');
                }
                if (msgs.length) { bkWarn.innerHTML = msgs.join('<br>'); bkWarn.classList.add('show'); }
            });
    }, 300);
}
bkDate.addEventListener('change', refreshWarnings);
bkPhone.addEventListener('blur', refreshWarnings);

// ---------------- Cancel dialog ----------------
function openCancel(id, name) {
    document.getElementById('cxBookingId').value = id;
    document.getElementById('cxTitle').textContent = name;
    document.getElementById('cxReasonSel').value = 'Patient called to cancel';
    const inp = document.getElementById('cxReason');
    inp.value = 'Patient called to cancel';
    inp.style.display = 'none';
    document.getElementById('cxOverlay').classList.add('open');
}
function closeCancel() { document.getElementById('cxOverlay').classList.remove('open'); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeCancel(); });
</script>
<script src="assets/js/date-picker.js"></script>
</body>
</html>
