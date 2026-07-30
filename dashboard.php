<?php
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: /index.php');
    exit;
}

$mustChangePassword = (bool) $user['must_change_password'];
$firstName = explode(' ', trim($user['name']))[0] ?? 'there';
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');

// Today's real counter-cash expenses (voided excluded). Guarded so the dashboard
// keeps rendering if sql/add_expenses.sql hasn't been run yet.
// source = 'CASH_COUNTER' keeps monthly salary/rent postings (BANK/OWNER, added
// in add_accounts_phase1.sql) out of a figure that is meant to read "cash that
// left the drawer today" — otherwise payroll day shows as a Rs 400,000 expense.
// Falls back to the unfiltered sum if that migration has not run, where every
// row is CASH_COUNTER anyway.
$todayExpenses = 0.0;
try {
    $todayExpenses = (float) $pdo->query("
        SELECT COALESCE(SUM(amount), 0) FROM expenses
        WHERE expense_date = CURDATE() AND voided_at IS NULL
          AND source = 'CASH_COUNTER'
    ")->fetchColumn();
} catch (Throwable $e) {
    try {
        $todayExpenses = (float) $pdo->query('
            SELECT COALESCE(SUM(amount), 0) FROM expenses
            WHERE expense_date = CURDATE() AND voided_at IS NULL
        ')->fetchColumn();
    } catch (Throwable $e2) {
        // Migration not applied yet — show zero rather than fatal.
    }
}

// ===========================================================================
//  REAL DASHBOARD DATA
//  Everything below reads live rows. Each block is wrapped so a not-yet-run
//  migration degrades to zero/empty instead of a fatal — same guard the
//  $todayExpenses block above already uses. Financial windows use the app's
//  business-day cutoff (config/billing.php) so the dashboard agrees with the
//  shift-closing / handover figures rather than a naive CURDATE().
// ===========================================================================
require_once __DIR__ . '/config/billing.php';

$today = date('Y-m-d');          // calendar day, for visit_date (registration date)
try { $bizToday = business_day($pdo); } catch (Throwable $e) { $bizToday = $today; }
try { [$winStart, $winEnd] = business_day_window($pdo, $bizToday); }
catch (Throwable $e) { $winStart = $today . ' 00:00:00'; $winEnd = date('Y-m-d', strtotime($today . ' +1 day')) . ' 00:00:00'; }

// ---- Visit funnel for today (drives Snapshot + Patient Flow) --------------
$visitStats = ['total' => 0, 'waiting' => 0, 'in_consult' => 0, 'done' => 0];
try {
    $vs = $pdo->prepare("
        SELECT COUNT(*) AS total,
               SUM(consult_status = 'WAITING')    AS waiting,
               SUM(consult_status = 'IN_CONSULT') AS in_consult,
               SUM(consult_status = 'DONE')       AS done
        FROM visits WHERE visit_date = ?
    ");
    $vs->execute([$today]);
    $r = $vs->fetch() ?: [];
    $visitStats = [
        'total'      => (int) ($r['total'] ?? 0),
        'waiting'    => (int) ($r['waiting'] ?? 0),
        'in_consult' => (int) ($r['in_consult'] ?? 0),
        'done'       => (int) ($r['done'] ?? 0),
    ];
} catch (Throwable $e) { /* visits table missing — leave zeros */ }

// ---- Today's collections & outstanding (business-day window) --------------
$todayCollections = 0.0;
$todayOutstanding = 0.0;
try {
    $c = $pdo->prepare("
        SELECT COALESCE(SUM(paid_amount), 0)
        FROM bills WHERE status = 'paid' AND voided_at IS NULL AND paid_at >= ? AND paid_at < ?
    ");
    $c->execute([$winStart, $winEnd]);
    $todayCollections = (float) $c->fetchColumn();
    // Refunds taken back today reduce net cash collected.
    try {
        $rf = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE voided_at IS NULL AND created_at >= ? AND created_at < ?");
        $rf->execute([$winStart, $winEnd]);
        $todayCollections -= (float) $rf->fetchColumn();
    } catch (Throwable $e) { /* refunds table missing */ }
} catch (Throwable $e) { /* bills table missing */ }
try {
    // Bills raised for today's visits that aren't fully paid yet.
    $o = $pdo->prepare("
        SELECT COALESCE(SUM(b.grand_total - COALESCE(b.paid_amount, 0)), 0)
        FROM bills b JOIN visits v ON v.id = b.visit_id
        WHERE v.visit_date = ? AND b.status <> 'paid'
    ");
    $o->execute([$today]);
    $todayOutstanding = (float) $o->fetchColumn();
} catch (Throwable $e) { /* leave zero */ }

// ---- Revenue bucket helper: net collected + doctor share for a window ------
// Doctor share uses the established consult formula (tax off full fee first,
// then split) and degrades to 0 if the consult_share_pct columns aren't live.
// Returns ['val'=>net total, 'doc'=>doctor share, 'clinic'=>clinic share].
$revBucket = function (string $start, string $end) use ($pdo) {
    $q = $pdo->prepare("SELECT COALESCE(SUM(paid_amount),0) FROM bills WHERE status='paid' AND voided_at IS NULL AND paid_at >= ? AND paid_at < ?");
    $q->execute([$start, $end]);
    $total = (float) $q->fetchColumn();
    try {
        $rq = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM refunds WHERE voided_at IS NULL AND created_at >= ? AND created_at < ?");
        $rq->execute([$start, $end]);
        $total -= (float) $rq->fetchColumn();
    } catch (Throwable $e) { /* no refunds table */ }
    $total = max(0.0, $total);

    $doc = 0.0;
    try {
        $ds = $pdo->prepare("
            SELECT COALESCE(SUM(
                CASE WHEN dr.consult_has_tax = 1
                     THEN (b.paid_amount - b.paid_amount * dr.consult_tax_pct / 100) * dr.consult_share_pct / 100
                     ELSE b.paid_amount * dr.consult_share_pct / 100 END
            ), 0)
            FROM bills b
            JOIN visits v ON v.id = b.visit_id
            JOIN users dr ON dr.id = v.doctor_id
            WHERE b.status = 'paid' AND b.voided_at IS NULL AND b.paid_at >= ? AND b.paid_at < ?
        ");
        $ds->execute([$start, $end]);
        $doc = (float) $ds->fetchColumn();
    } catch (Throwable $e) { /* consult share columns missing — leave zero */ }
    $doc = max(0.0, min($doc, $total));            // never exceed net collected
    return ['val' => $total, 'doc' => $doc, 'clinic' => max(0.0, $total - $doc)];
};

// ---- Weekly revenue: net collections per business day, last 7 days --------
$weekBars = [];   // [ ['day'=>'Mon', 'val'=>, 'doc'=>, 'clinic'=>], ... ]
$weekTotal = 0.0; $weekDocTotal = 0.0; $weekPeakDay = ''; $weekPeakVal = 0.0;
try {
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime($bizToday . " -$i day"));
        [$ws, $we] = business_day_window($pdo, $d);
        $bk = $revBucket($ws, $we);
        $bk['day'] = date('D', strtotime($d));
        $weekBars[] = $bk;
        $weekTotal += $bk['val']; $weekDocTotal += $bk['doc'];
        if ($bk['val'] >= $weekPeakVal) { $weekPeakVal = $bk['val']; $weekPeakDay = date('l', strtotime($d)); }
    }
} catch (Throwable $e) { $weekBars = []; }
$weekAvg = $weekBars ? $weekTotal / count($weekBars) : 0.0;

// ---- Monthly revenue: last 4 calendar weeks (Mon–Sun buckets) --------------
$monthBars = []; $monthTotal = 0.0; $monthDocTotal = 0.0; $monthPeakLabel = ''; $monthPeakVal = 0.0;
try {
    $dow = (int) date('N', strtotime($bizToday));           // 1=Mon..7=Sun
    $thisMon = date('Y-m-d', strtotime($bizToday . ' -' . ($dow - 1) . ' day'));
    for ($w = 3; $w >= 0; $w--) {
        $wkStart = date('Y-m-d', strtotime($thisMon . " -$w week"));
        $wkEnd   = date('Y-m-d', strtotime($wkStart . ' +7 day'));
        $bk = $revBucket($wkStart . ' 00:00:00', $wkEnd . ' 00:00:00');
        $bk['day'] = date('j M', strtotime($wkStart));
        $monthBars[] = $bk;
        $monthTotal += $bk['val']; $monthDocTotal += $bk['doc'];
        if ($bk['val'] >= $monthPeakVal) { $monthPeakVal = $bk['val']; $monthPeakLabel = 'Wk of ' . $bk['day']; }
    }
} catch (Throwable $e) { $monthBars = []; }
$monthAvg = $monthBars ? $monthTotal / count($monthBars) : 0.0;

// ---- Yearly revenue: last 12 calendar months -------------------------------
$yearBars = []; $yearTotal = 0.0; $yearDocTotal = 0.0; $yearPeakLabel = ''; $yearPeakVal = 0.0;
try {
    $firstOfThisMonth = date('Y-m-01', strtotime($bizToday));
    for ($m = 11; $m >= 0; $m--) {
        $mStart = date('Y-m-01', strtotime($firstOfThisMonth . " -$m month"));
        $mEnd   = date('Y-m-01', strtotime($mStart . ' +1 month'));
        $bk = $revBucket($mStart . ' 00:00:00', $mEnd . ' 00:00:00');
        $bk['day'] = date('M', strtotime($mStart));
        $yearBars[] = $bk;
        $yearTotal += $bk['val']; $yearDocTotal += $bk['doc'];
        if ($bk['val'] >= $yearPeakVal) { $yearPeakVal = $bk['val']; $yearPeakLabel = date('F', strtotime($mStart)); }
    }
} catch (Throwable $e) { $yearBars = []; }
$yearAvg = $yearBars ? $yearTotal / count($yearBars) : 0.0;

// ---- Doctor performance today (real; no fake ratings) ---------------------
$doctorPerf = [];
try {
    $dp = $pdo->prepare("
        SELECT dr.id, dr.name,
               COUNT(v.id) AS patients,
               COALESCE(SUM(b.paid_amount), 0) AS revenue,
               AVG(CASE WHEN v.finished_at IS NOT NULL AND v.started_at IS NOT NULL
                        THEN TIMESTAMPDIFF(MINUTE, v.started_at, v.finished_at) END) AS avg_min,
               MAX(t.status) AS timing_status
        FROM users dr
        JOIN visits v ON v.doctor_id = dr.id AND v.visit_date = ?
        LEFT JOIN bills b ON b.visit_id = v.id AND b.status = 'paid' AND b.voided_at IS NULL
        LEFT JOIN doctor_day_timings t ON t.doctor_id = dr.id AND t.timing_date = ?
        WHERE dr.base_role = 'DOCTOR'
        GROUP BY dr.id, dr.name
        ORDER BY revenue DESC, patients DESC
    ");
    $dp->execute([$today, $today]);
    $doctorPerf = $dp->fetchAll();
} catch (Throwable $e) { $doctorPerf = []; }

// ---- Doctor share of today's paid consults (for Financial Summary) --------
$todayDoctorShare = 0.0;
try {
    $ds = $pdo->prepare("
        SELECT COALESCE(SUM(
            CASE WHEN dr.consult_has_tax = 1
                 THEN (b.paid_amount - b.paid_amount * dr.consult_tax_pct / 100) * dr.consult_share_pct / 100
                 ELSE b.paid_amount * dr.consult_share_pct / 100 END
        ), 0)
        FROM bills b
        JOIN visits v ON v.id = b.visit_id
        JOIN users dr ON dr.id = v.doctor_id
        WHERE b.status = 'paid' AND b.voided_at IS NULL AND b.paid_at >= ? AND b.paid_at < ?
    ");
    $ds->execute([$winStart, $winEnd]);
    $todayDoctorShare = (float) $ds->fetchColumn();
} catch (Throwable $e) { /* consult share columns missing — leave zero */ }
// Clinic share = what the clinic keeps of collections after the doctors' cut.
// This is the clinic's actual gross income; Net then nets off running expenses.
$todayClinicShare = max(0.0, $todayCollections - $todayDoctorShare);
$todayNet = $todayClinicShare - $todayExpenses;

// ---- Admissions snapshot (replaces the fake "bed occupancy" rings) --------
// There is no bed-inventory table, so we show the real census: how many
// patients are currently admitted, mid-discharge, and admitted today.
$adm = ['active' => 0, 'discharging' => 0, 'today' => 0, 'has_table' => false];
try {
    $a = $pdo->query("
        SELECT
            SUM(status = 'ACTIVE')                 AS active,
            SUM(status = 'DISCHARGE_IN_PROGRESS')  AS discharging,
            SUM(DATE(admitted_at) = CURDATE())     AS today
        FROM admissions
    ")->fetch();
    $adm = [
        'active'      => (int) ($a['active'] ?? 0),
        'discharging' => (int) ($a['discharging'] ?? 0),
        'today'       => (int) ($a['today'] ?? 0),
        'has_table'   => true,
    ];
} catch (Throwable $e) { /* admissions migration not run */ }

// ---- Recent activity timeline (real events) -------------------------------
$timeline = [];
try {
    // Paid bills
    foreach ($pdo->query("
        SELECT b.paid_at AS ts, CONCAT('Payment received — Rs ', FORMAT(b.paid_amount, 0)) AS text, 'primary' AS kind
        FROM bills b WHERE b.status = 'paid' AND b.voided_at IS NULL AND b.paid_at IS NOT NULL
        ORDER BY b.paid_at DESC LIMIT 5
    ")->fetchAll() as $row) { $timeline[] = $row; }
} catch (Throwable $e) {}
try {
    foreach ($pdo->query("
        SELECT v.finished_at AS ts, CONCAT('Consultation completed — ', dr.name) AS text, 'green' AS kind
        FROM visits v JOIN users dr ON dr.id = v.doctor_id
        WHERE v.consult_status = 'DONE' AND v.finished_at IS NOT NULL
        ORDER BY v.finished_at DESC LIMIT 5
    ")->fetchAll() as $row) { $timeline[] = $row; }
} catch (Throwable $e) {}
try {
    foreach ($pdo->query("
        SELECT a.admitted_at AS ts, CONCAT('Patient admitted — ', p.name) AS text, 'red' AS kind
        FROM admissions a JOIN patients p ON p.id = a.patient_id
        WHERE a.admitted_at IS NOT NULL
        ORDER BY a.admitted_at DESC LIMIT 5
    ")->fetchAll() as $row) { $timeline[] = $row; }
} catch (Throwable $e) {}
// Newest first, keep the most recent 6.
usort($timeline, fn ($x, $y) => strcmp($y['ts'] ?? '', $x['ts'] ?? ''));
$timeline = array_slice($timeline, 0, 6);

// ---- Notifications (real, admin-relevant) ---------------------------------
$notifs = [];
try {
    $pending = (int) $pdo->query("SELECT COUNT(*) FROM user_permission_overrides WHERE granted = 0")->fetchColumn();
    // (informational — overrides table doesn't model a request queue, but a
    //  non-zero explicit-deny count is still worth surfacing to an admin.)
} catch (Throwable $e) { $pending = 0; }
if (($adm['discharging'] ?? 0) > 0) {
    $notifs[] = ['icon' => 'bed', 'bg' => '#FFFBEB', 'fg' => '#92400E', 'text' => $adm['discharging'] . ' discharge' . ($adm['discharging'] > 1 ? 's' : '') . ' awaiting payment', 'href' => 'admissions.php', 'action' => 'Open'];
}
if ($todayOutstanding > 0) {
    $notifs[] = ['icon' => 'wallet', 'bg' => '#FEF2F2', 'fg' => '#B91C1C', 'text' => 'Rs ' . number_format($todayOutstanding, 0) . ' outstanding today', 'href' => 'checkout.php', 'action' => 'View'];
}
if (($visitStats['waiting'] ?? 0) > 0) {
    $notifs[] = ['icon' => 'user-group', 'bg' => '#ECFDF5', 'fg' => '#047857', 'text' => $visitStats['waiting'] . ' patient' . ($visitStats['waiting'] > 1 ? 's' : '') . ' waiting in queue', 'href' => 'receptionist.php', 'action' => 'Open'];
}

// ---- Today's queue by doctor (replaces the fake inventory card) -----------
$queueByDoctor = [];
try {
    $qd = $pdo->prepare("
        SELECT dr.name,
               SUM(v.consult_status <> 'DONE') AS pending,
               COUNT(*) AS total
        FROM visits v JOIN users dr ON dr.id = v.doctor_id
        WHERE v.visit_date = ?
        GROUP BY dr.id, dr.name
        ORDER BY pending DESC, dr.name
    ");
    $qd->execute([$today]);
    $queueByDoctor = $qd->fetchAll();
} catch (Throwable $e) { $queueByDoctor = []; }

// ---- Calendar event days: real bookings this month ------------------------
$eventDays = [];
try {
    $bk = $pdo->query("
        SELECT DISTINCT DAY(booking_date) AS d
        FROM bookings
        WHERE YEAR(booking_date) = YEAR(CURDATE()) AND MONTH(booking_date) = MONTH(CURDATE())
    ")->fetchAll(PDO::FETCH_COLUMN);
    $eventDays = array_map('intval', $bk);
} catch (Throwable $e) { $eventDays = []; }

function icon(string $name, int $size = 18): string {
    $paths = [
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'stethoscope' => '<path d="M4.8 2.3A.3.3 0 1 0 5 2H4a2 2 0 0 0-2 2v5a6 6 0 0 0 6 6v0a6 6 0 0 0 6-6V4a2 2 0 0 0-2-2h-1a.2.2 0 1 0 .3.3"/><path d="M8 15v1a6 6 0 0 0 6 6v0a6 6 0 0 0 6-6v-4"/><circle cx="20" cy="10" r="2"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'bed' => '<path d="M2 4v16M2 8h18a2 2 0 0 1 2 2v10M2 17h20"/><path d="M6 8V6a2 2 0 0 1 2-2h3"/>',
        'clipboard' => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M9 4H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-3"/>',
        'card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        'wallet' => '<path d="M20 12V8a2 2 0 0 0-2-2H5a2 2 0 0 1 0-4h13a2 2 0 0 1 2 2v3"/><path d="M3 5v14a2 2 0 0 0 2 2h15a2 2 0 0 0 2-2v-4"/><circle cx="17" cy="14" r="1.5"/>',
        'bar-chart' => '<path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="5" width="3" height="13"/>',
        'user-group' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'lock' => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'box' => '<path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3.3 7.6L12 12l8.7-4.4M12 12v9"/>',
        'trending-up' => '<path d="M23 6l-9.5 9.5-5-5L1 18"/><path d="M17 6h6v6"/>',
        'file-text' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 7l10 6 10-6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5M12 15V3"/>',
        'alert-triangle' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/>',
        'check-circle' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/>',
        'dollar-sign' => '<path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'droplet' => '<path d="M12 2.69s5.66 5.86 5.66 10.31A5.66 5.66 0 1 1 6.34 13C6.34 8.55 12 2.69 12 2.69z"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
}

$pageTitle = 'Dashboard';
$headExtra = <<<CSS
<style>
.content {
    padding: 28px 32px 60px;
    display: flex;
    flex-direction: column;
    gap: 24px;
}

/* The page header is gone: partials/quick_header.php (emitted by the sidebar)
   is the app bar now, and it brings its own styles from app.css. These 56
   lines styled a header this page no longer renders. */

/* .welcome-line now ships in assets/app.css — the doctor console shows the
   same greeting, and one definition beats two. */

/* ---------- Section shell ---------- */
.section-title { font-size: 18px; font-weight: 600; margin-bottom: 2px; }
.section-sub { font-size: 12.5px; color: var(--text-muted); margin-bottom: 16px; }
.card {
    background: var(--card);
    border-radius: var(--radius-card);
    border: 1px solid var(--border);
    box-shadow: var(--shadow-sm);
    padding: 22px 24px;
}
.row-2 { display: grid; grid-template-columns: 1.4fr 1fr; gap: 20px; align-items: start; }

/* ---------- Revenue chart ---------- */
.chart-tabs { display: flex; gap: 6px; background: #F1F5F9; padding: 4px; border-radius: 10px; width: fit-content; }
.chart-tab { padding: 6px 14px; border-radius: 8px; font-size: 12.5px; font-weight: 600; color: var(--text-secondary); cursor: pointer; }
.chart-tab.active { background: #fff; color: var(--primary-dark); box-shadow: var(--shadow-sm); }
.chart-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }
.rev-panel { display: none; }
.rev-panel.active { display: block; }
.bars { display: flex; align-items: flex-end; gap: 14px; height: 160px; margin-top: 8px; }
.bar-col { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 8px; height: 100%; justify-content: flex-end; }
.bar-fill {
    position: relative;
    width: 100%; max-width: 24px; border-radius: 4px 4px 0 0;
    background: linear-gradient(180deg, var(--primary), var(--primary-dark)); /* clinic share (base) */
    overflow: hidden;
}
/* Doctor share sits on TOP of the stack (visually above clinic). */
.bar-doctor { position: absolute; top: 0; left: 0; right: 0; background: var(--amber, #F59E0B); }
.bar-day { font-size: 11px; color: var(--text-muted); }
/* Legend */
.chart-legend { display: flex; gap: 16px; margin: 4px 0 2px; }
.lg-item { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-secondary); }
.lg-swatch { width: 12px; height: 12px; border-radius: 3px; display: inline-block; }
.lg-clinic { background: linear-gradient(180deg, var(--primary), var(--primary-dark)); }
.lg-doctor { background: var(--amber, #F59E0B); }
/* Ledger */
.ledger-toggle {
    margin-top: 16px; background: none; border: none; padding: 0; cursor: pointer;
    font-size: 12.5px; font-weight: 600; color: var(--primary-dark);
}
.ledger-toggle:hover { text-decoration: underline; }
.ledger-wrap { margin-top: 12px; overflow-x: auto; }
.ledger-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.ledger-table th, .ledger-table td { padding: 7px 8px; text-align: left; border-bottom: 1px solid var(--border); }
.ledger-table th { color: var(--text-secondary); font-weight: 600; }
.ledger-table td.num, .ledger-table th.num { text-align: right; white-space: nowrap; }
.ledger-table tfoot td { font-weight: 700; border-bottom: none; border-top: 2px solid var(--border); }
.revenue-summary { display: flex; flex-direction: column; gap: 16px; margin-top: 20px; padding-top: 18px; border-top: 1px solid var(--border); }
.summary-row { display: flex; justify-content: space-between; align-items: baseline; }
.summary-row .label { font-size: 12.5px; color: var(--text-secondary); }
.summary-row .value { font-size: 15px; font-weight: 700; }
.summary-row .value.good { color: #047857; }

/* ---------- Snapshot ---------- */
.snapshot-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.snapshot-item { display: flex; align-items: center; gap: 10px; }
.snapshot-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.snapshot-item .value { font-size: 20px; font-weight: 700; display: block; }
.snapshot-item .label { font-size: 12px; color: var(--text-muted); }

/* ---------- Patient flow ---------- */
.flow { display: flex; gap: 16px; }
.flow-stage { flex: 1; text-align: center; }
.flow-stage .num { font-size: 24px; font-weight: 700; }
.flow-stage .name { font-size: 12px; color: var(--text-secondary); margin: 4px 0 8px; }
.flow-bar { height: 6px; border-radius: 4px; background: #F1F5F9; overflow: hidden; }
.flow-bar-fill { height: 100%; background: var(--primary); border-radius: 4px; }
.flow-pct { font-size: 11px; color: var(--text-muted); margin-top: 6px; }
.flow-arrow { display: flex; align-items: center; color: var(--text-muted); font-size: 14px; padding-top: 30px; }

/* ---------- Admissions census ---------- */
.census-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-top: 8px; }
.census-item {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 6px; padding: 22px 10px; border-radius: 14px; background: #F8FAFC; border: 1px solid var(--border);
    text-align: center; transition: background .15s ease;
}
.census-item:hover { background: var(--primary-light); }
.census-num { font-size: 30px; font-weight: 700; line-height: 1; }
.census-label { font-size: 12px; color: var(--text-secondary); }

/* ---------- Doctor table ---------- */
table { width: 100%; border-collapse: collapse; }
th { text-align: left; font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); padding: 0 10px 10px; font-weight: 600; }
td { padding: 12px 10px; border-top: 1px solid var(--border); font-size: 13.5px; }
tr.top-performer td:first-child { position: relative; }
tr.top-performer td:first-child::before { content: "★"; color: var(--amber); margin-right: 6px; }
.doc-name { display: flex; align-items: center; gap: 10px; font-weight: 600; }
.doc-avatar { width: 30px; height: 30px; border-radius: 50%; background: var(--primary-light); color: var(--primary-dark); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; }
.status-pill { font-size: 11.5px; font-weight: 600; padding: 3px 9px; border-radius: 20px; }
.status-pill.in-clinic { background: #ECFDF5; color: #047857; }
.status-pill.on-leave { background: #FEF2F2; color: #B91C1C; }
tabular { font-variant-numeric: tabular-nums; }

/* ---------- Financial summary ---------- */
.fin-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; }
.fin-card { padding: 4px 2px; }
.fin-card .label { font-size: 12.5px; color: var(--text-secondary); margin-bottom: 6px; }
.fin-card .value { font-size: 24px; font-weight: 700; margin-bottom: 4px; }
.fin-card .trend { font-size: 12px; font-weight: 600; }
.fin-card .trend.up { color: #047857; }
.fin-card .trend.down { color: #B91C1C; }

/* ---------- Timeline ---------- */
.timeline { display: flex; flex-direction: column; max-height: 320px; overflow-y: auto; }
.tl-item { display: flex; gap: 14px; padding-bottom: 20px; position: relative; }
.tl-item:not(:last-child)::before {
    content: ""; position: absolute; left: 5px; top: 16px; bottom: 0; width: 1px; background: var(--border);
}
.tl-dot { width: 11px; height: 11px; border-radius: 50%; margin-top: 3px; flex-shrink: 0; z-index: 1; }
.tl-time { font-size: 11.5px; color: var(--text-muted); margin-bottom: 2px; }
.tl-text { font-size: 13.5px; font-weight: 500; }

/* ---------- Notifications ---------- */
.notif-list { display: flex; flex-direction: column; gap: 4px; }
.notif-item { display: flex; align-items: center; gap: 12px; padding: 12px 4px; border-bottom: 1px solid var(--border); }
.notif-item:last-child { border-bottom: none; }
.notif-icon {
    width: 34px; height: 34px; border-radius: 10px; display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.notif-icon svg { width: 16px; height: 16px; }
.notif-text { flex: 1; font-size: 13px; font-weight: 500; }
.notif-action { font-size: 12px; font-weight: 600; color: var(--primary); }

/* ---------- Inventory ---------- */
.inv-list { display: flex; flex-direction: column; gap: 10px; }
.inv-item {
    display: flex; justify-content: space-between; align-items: center;
    padding: 10px 14px; border-radius: 10px; background: #FEF2F2; border-left: 3px solid var(--red);
}
.inv-name { font-size: 13px; font-weight: 600; }
.inv-qty { font-size: 12px; color: var(--text-secondary); }
.inv-order { font-size: 12px; font-weight: 600; color: var(--primary); }

/* ---------- Calendar ---------- */
.cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; text-align: center; }
.cal-dow { font-size: 11px; color: var(--text-muted); font-weight: 600; padding-bottom: 6px; }
.cal-day { font-size: 13px; padding: 8px 0; border-radius: 8px; position: relative; color: var(--text-secondary); }
.cal-day.today { background: var(--primary); color: #fff; font-weight: 700; }
.cal-day.has-event::after { content: ""; position: absolute; bottom: 3px; left: 50%; transform: translateX(-50%); width: 4px; height: 4px; border-radius: 50%; background: var(--amber); }
.cal-day.muted { color: var(--text-muted); opacity: .4; }

/* ---------- Password nag ---------- */
.nag-banner {
    background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 14px;
    padding: 14px 18px; display: flex; align-items: center; justify-content: space-between; gap: 12px;
    font-size: 13.5px; color: #92400E;
}
.nag-banner a { font-weight: 700; text-decoration: underline; }

@media (max-width: 1500px) {
    .fin-grid { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 1200px) {
    .fin-grid { grid-template-columns: repeat(2, 1fr); }
    .row-2 { grid-template-columns: 1fr; }
}
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'dashboard';
/* partials/sidebar.php now emits the shared application bar itself, so the
   72px translucent <header class="header"> this page used to carry by hand is
   gone. It was the third of four separate top-bar implementations; its search
   box wasn't even wired to a form action. */
require __DIR__ . '/partials/sidebar.php';
?>

        <div class="content">

            <?php if ($mustChangePassword): ?>
            <div class="nag-banner">
                <span>You're signed in with a temporary password. Please set a new one to secure your account.</span>
                <a href="change-password.php">Change password now &rarr;</a>
            </div>
            <?php endif; ?>

            <!-- Greeting -->
            <div class="welcome-line">
                <h1><?= $greeting ?>, <?= htmlspecialchars($user['name']) ?></h1>
                <span class="welcome-date"><?= date('l, d/m/Y') ?></span>
            </div>

            <!-- Revenue + Snapshot -->
            <div class="row-2">
                <div class="card">
                    <div class="chart-head">
                        <div>
                            <div class="section-title" style="margin-bottom:0;">Revenue Analytics</div>
                            <div class="section-sub" style="margin-bottom:0;" id="revSub">Weekly performance overview</div>
                        </div>
                        <div class="chart-tabs" id="revTabs">
                            <div class="chart-tab active" data-range="week" data-sub="Weekly performance overview">Week</div>
                            <div class="chart-tab" data-range="month" data-sub="Last 4 weeks">Month</div>
                            <div class="chart-tab" data-range="year" data-sub="Last 12 months">Year</div>
                        </div>
                    </div>
                    <div class="chart-legend">
                        <span class="lg-item"><span class="lg-swatch lg-clinic"></span>Clinic share</span>
                        <span class="lg-item"><span class="lg-swatch lg-doctor"></span>Doctor share</span>
                    </div>
                    <?php
                    // range key => [bars, total, docTotal, avg, peakLabel, peakVal, totalLbl, avgLbl, peakRowLbl, periodCol]
                    $revPanels = [
                        'week'  => [$weekBars,  $weekTotal,  $weekDocTotal,  $weekAvg,  $weekPeakDay,    $weekPeakVal,  '7-day Revenue',   'Daily Average',  'Peak Day',   'Day'],
                        'month' => [$monthBars, $monthTotal, $monthDocTotal, $monthAvg, $monthPeakLabel, $monthPeakVal, '4-week Revenue',  'Weekly Average', 'Peak Week',  'Week of'],
                        'year'  => [$yearBars,  $yearTotal,  $yearDocTotal,  $yearAvg,  $yearPeakLabel,  $yearPeakVal,  '12-month Revenue','Monthly Average','Peak Month', 'Month'],
                    ];
                    foreach ($revPanels as $rk => $p):
                        [$bars, $total, $docTotal, $avg, $peakLabel, $peakVal, $totalLbl, $avgLbl, $peakRowLbl, $periodCol] = $p;
                        $clinicTotal = max(0.0, $total - $docTotal);
                        $barMax = 0;
                        foreach ($bars as $b) { $barMax = max($barMax, $b['val']); }
                    ?>
                    <div class="rev-panel<?= $rk === 'week' ? ' active' : '' ?>" data-range="<?= $rk ?>">
                        <div class="bars">
                            <?php if (!$bars): ?>
                                <div style="width:100%;text-align:center;color:var(--text-muted);font-size:13px;align-self:center;">No revenue data yet.</div>
                            <?php else:
                            foreach ($bars as $b):
                                $h = $barMax > 0 ? max(2, round(($b['val'] / $barMax) * 100)) : 2;
                                // Split the stack: doctor portion sits on top of clinic portion.
                                $docPct = $b['val'] > 0 ? round(($b['doc'] / $b['val']) * 100) : 0;
                                $ttl = htmlspecialchars($b['day']) . ': Rs ' . number_format($b['val'])
                                     . ' (Clinic Rs ' . number_format($b['clinic']) . ' · Doctor Rs ' . number_format($b['doc']) . ')';
                            ?>
                            <div class="bar-col">
                                <div class="bar-fill" style="height: <?= $h ?>%;" title="<?= $ttl ?>">
                                    <div class="bar-doctor" style="height: <?= $docPct ?>%;"></div>
                                </div>
                                <div class="bar-day"><?= htmlspecialchars($b['day']) ?></div>
                            </div>
                            <?php endforeach; endif; ?>
                        </div>
                        <div class="revenue-summary">
                            <div class="summary-row"><span class="label"><?= $totalLbl ?></span><span class="value">Rs <?= number_format($total) ?></span></div>
                            <div class="summary-row"><span class="label">Clinic Share</span><span class="value">Rs <?= number_format($clinicTotal) ?></span></div>
                            <div class="summary-row"><span class="label">Doctor Share</span><span class="value">Rs <?= number_format($docTotal) ?></span></div>
                            <div class="summary-row"><span class="label"><?= $avgLbl ?></span><span class="value">Rs <?= number_format($avg) ?></span></div>
                            <div class="summary-row"><span class="label"><?= $peakRowLbl ?></span><span class="value"><?= $peakVal > 0 ? htmlspecialchars($peakLabel) : '—' ?></span></div>
                        </div>
                        <?php if ($bars): ?>
                        <button type="button" class="ledger-toggle" aria-expanded="false">View ledger details</button>
                        <div class="ledger-wrap" hidden>
                            <table class="ledger-table">
                                <thead>
                                    <tr><th><?= htmlspecialchars($periodCol) ?></th><th class="num">Total</th><th class="num">Clinic</th><th class="num">Doctor</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bars as $b): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($b['day']) ?></td>
                                        <td class="num">Rs <?= number_format($b['val']) ?></td>
                                        <td class="num">Rs <?= number_format($b['clinic']) ?></td>
                                        <td class="num">Rs <?= number_format($b['doc']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td>Total</td>
                                        <td class="num">Rs <?= number_format($total) ?></td>
                                        <td class="num">Rs <?= number_format($clinicTotal) ?></td>
                                        <td class="num">Rs <?= number_format($docTotal) ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="card">
                    <div class="section-title">Today's Snapshot</div>
                    <div class="section-sub">Executive summary</div>
                    <div class="snapshot-grid">
                        <div class="snapshot-item"><span class="snapshot-dot" style="background:var(--primary);"></span><div><span class="value"><?= number_format($visitStats['total']) ?></span><span class="label">Registered</span></div></div>
                        <div class="snapshot-item"><span class="snapshot-dot" style="background:var(--green);"></span><div><span class="value"><?= number_format($visitStats['done']) ?></span><span class="label">Completed</span></div></div>
                        <div class="snapshot-item"><span class="snapshot-dot" style="background:var(--amber);"></span><div><span class="value"><?= number_format($visitStats['waiting']) ?></span><span class="label">Waiting</span></div></div>
                        <div class="snapshot-item"><span class="snapshot-dot" style="background:var(--primary);"></span><div><span class="value"><?= number_format($visitStats['in_consult']) ?></span><span class="label">In Consult</span></div></div>
                        <div class="snapshot-item"><span class="snapshot-dot" style="background:var(--green);"></span><div><span class="value"><?= number_format($todayCollections) ?></span><span class="label">Collections (Rs)</span></div></div>
                        <div class="snapshot-item"><span class="snapshot-dot" style="background:var(--amber);"></span><div><span class="value"><?= number_format($todayOutstanding) ?></span><span class="label">Outstanding (Rs)</span></div></div>
                    </div>
                </div>
            </div>

            <!-- Patient Flow + Bed Occupancy -->
            <div class="row-2">
                <div class="card">
                    <div class="section-title">Patient Flow</div>
                    <div class="section-sub">Today's journey across stages</div>
                    <div class="flow">
                        <?php
                        $flowTotal = max(1, $visitStats['total']);
                        $stages = [
                            'Registered'   => $visitStats['total'],
                            'Waiting'      => $visitStats['waiting'],
                            'In Consult'   => $visitStats['in_consult'],
                            'Completed'    => $visitStats['done'],
                        ];
                        $i = 0;
                        foreach ($stages as $name => $num):
                            $pct = (int) round(($num / $flowTotal) * 100);
                            if ($i > 0): ?><div class="flow-arrow">&rarr;</div><?php endif;
                        ?>
                        <div class="flow-stage">
                            <div class="num"><?= number_format($num) ?></div>
                            <div class="name"><?= $name ?></div>
                            <div class="flow-bar"><div class="flow-bar-fill" style="width:<?= $pct ?>%;"></div></div>
                            <div class="flow-pct"><?= $pct ?>%</div>
                        </div>
                        <?php $i++; endforeach; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="section-title">Admissions Census</div>
                    <div class="section-sub">Live room status</div>
                    <?php if (!$adm['has_table']): ?>
                        <div style="color:var(--text-muted);font-size:13px;padding:20px 0;">Admissions module not enabled.</div>
                    <?php else: ?>
                    <div class="census-grid">
                        <a class="census-item" href="admissions.php" style="text-decoration:none;color:inherit;">
                            <span class="census-num" style="color:var(--primary-dark);"><?= number_format($adm['active']) ?></span>
                            <span class="census-label">Currently Admitted</span>
                        </a>
                        <a class="census-item" href="admissions.php" style="text-decoration:none;color:inherit;">
                            <span class="census-num" style="color:#B45309;"><?= number_format($adm['discharging']) ?></span>
                            <span class="census-label">Awaiting Discharge</span>
                        </a>
                        <a class="census-item" href="admissions.php" style="text-decoration:none;color:inherit;">
                            <span class="census-num" style="color:#047857;"><?= number_format($adm['today']) ?></span>
                            <span class="census-label">Admitted Today</span>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Doctor Performance + Financial Summary -->
            <div class="row-2">
                <div class="card">
                    <div class="section-title">Doctor Performance</div>
                    <div class="section-sub">Today's activity by doctor</div>
                    <table>
                        <thead>
                            <tr><th>Doctor</th><th>Patients</th><th>Revenue</th><th>Avg. Time</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php if (!$doctorPerf): ?>
                            <tr><td colspan="5" style="color:var(--text-muted);text-align:center;padding:20px 0;">No consultations recorded today.</td></tr>
                            <?php else:
                            foreach ($doctorPerf as $idx => $d):
                                $nm = trim(preg_replace('/^dr\.?\s*/i', '', $d['name']));
                                $initials = strtoupper(substr($nm, 0, 1));
                                $onLeave = ($d['timing_status'] ?? '') === 'OFF';
                                $avgMin = $d['avg_min'] !== null ? round($d['avg_min']) . 'm' : '—';
                                $rev = (float) $d['revenue'];
                                $revFmt = $rev >= 1000 ? number_format($rev / 1000, 1) . 'k' : number_format($rev);
                            ?>
                            <tr class="<?= $idx === 0 && $rev > 0 ? 'top-performer' : '' ?>">
                                <td><div class="doc-name"><span class="doc-avatar"><?= htmlspecialchars($initials) ?></span><?= htmlspecialchars($d['name']) ?></div></td>
                                <td class="tabular"><?= (int) $d['patients'] ?></td>
                                <td class="tabular"><?= $revFmt ?></td>
                                <td class="tabular"><?= $avgMin ?></td>
                                <td><span class="status-pill <?= $onLeave ? 'on-leave' : 'in-clinic' ?>"><?= $onLeave ? 'On Leave' : 'In Clinic' ?></span></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card">
                    <div class="section-title">Financial Summary</div>
                    <div class="section-sub">Today at a glance</div>
                    <div class="fin-grid">
                        <?php $kfmt = fn ($v) => abs($v) >= 1000 ? number_format($v / 1000, 1) . 'k' : number_format($v); ?>
                        <div class="fin-card"><div class="label">Collections</div><div class="value"><?= $kfmt($todayCollections) ?></div><div class="trend">today</div></div>
                        <div class="fin-card"><div class="label">Doctor Share</div><div class="value"><?= $kfmt($todayDoctorShare) ?></div><div class="trend">today</div></div>
                        <div class="fin-card"><div class="label">Clinic Share</div><div class="value"><?= $kfmt($todayClinicShare) ?></div><div class="trend">clinic income</div></div>
                        <a class="fin-card" href="expenses.php" style="text-decoration:none;color:inherit;"><div class="label">Expenses</div><div class="value"><?= $kfmt($todayExpenses) ?></div><div class="trend">today</div></a>
                        <div class="fin-card"><div class="label">Net</div><div class="value"><?= $kfmt($todayNet) ?></div><div class="trend <?= $todayNet >= 0 ? 'up' : 'down' ?>"><?= $todayNet >= 0 ? 'positive' : 'negative' ?></div></div>
                    </div>
                </div>
            </div>

            <!-- Timeline + Notifications -->
            <div class="row-2">
                <div class="card">
                    <div class="section-title">Timeline</div>
                    <div class="section-sub">Recent activity</div>
                    <div class="timeline">
                        <?php if (!$timeline): ?>
                            <div style="color:var(--text-muted);font-size:13px;padding:12px 0;">No recent activity.</div>
                        <?php else:
                        $kindColor = ['green' => 'var(--green)', 'red' => 'var(--red)', 'primary' => 'var(--primary)', 'amber' => 'var(--amber)'];
                        foreach ($timeline as $e):
                            $color = $kindColor[$e['kind']] ?? 'var(--primary)';
                        ?>
                        <div class="tl-item">
                            <div class="tl-dot" style="background:<?= $color ?>;"></div>
                            <div>
                                <div class="tl-time"><?= $e['ts'] ? date('d/m H:i', strtotime($e['ts'])) : '' ?></div>
                                <div class="tl-text"><?= htmlspecialchars($e['text']) ?></div>
                            </div>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="section-title">Notifications</div>
                    <div class="section-sub">Needs your attention</div>
                    <div class="notif-list">
                        <?php if (!$notifs): ?>
                            <div class="notif-item"><span class="notif-icon" style="background:#ECFDF5;color:#047857;"><?= icon('check-circle') ?></span><span class="notif-text">All clear — nothing needs your attention.</span></div>
                        <?php else: foreach ($notifs as $n): ?>
                            <a class="notif-item" href="<?= htmlspecialchars($n['href']) ?>" style="text-decoration:none;color:inherit;">
                                <span class="notif-icon" style="background:<?= $n['bg'] ?>;color:<?= $n['fg'] ?>;"><?= icon($n['icon']) ?></span>
                                <span class="notif-text"><?= htmlspecialchars($n['text']) ?></span>
                                <span class="notif-action"><?= htmlspecialchars($n['action']) ?></span>
                            </a>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>

            <!-- Today by Doctor + Calendar -->
            <div class="row-2">
                <div class="card">
                    <div class="section-title">Today by Doctor</div>
                    <div class="section-sub">Patients seen vs. still waiting</div>
                    <div class="inv-list">
                        <?php if (!$queueByDoctor): ?>
                            <div style="color:var(--text-muted);font-size:13px;padding:12px 0;">No patients registered today.</div>
                        <?php else: foreach ($queueByDoctor as $q):
                            $pending = (int) $q['pending']; $total = (int) $q['total']; $seen = $total - $pending;
                        ?>
                            <div class="inv-item" style="background:#F8FAFC;border-left-color:var(--primary);">
                                <div>
                                    <div class="inv-name"><?= htmlspecialchars($q['name']) ?></div>
                                    <div class="inv-qty"><?= $seen ?> seen &middot; <?= $pending ?> waiting</div>
                                </div>
                                <span class="inv-order" style="color:var(--text-secondary);"><?= $total ?> total</span>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="section-title"><?= date('F Y') ?></div>
                    <div class="section-sub">Monthly overview</div>
                    <div class="cal-grid">
                        <?php
                        foreach (['S','M','T','W','T','F','S'] as $d) echo "<div class='cal-dow'>$d</div>";
                        $firstDay = (int) date('N', strtotime(date('Y-m-01'))) % 7;
                        $daysInMonth = (int) date('t');
                        $todayDom = (int) date('j');
                        // $eventDays comes from real bookings this month (computed above).
                        for ($i = 0; $i < $firstDay; $i++) echo "<div class='cal-day muted'></div>";
                        for ($d = 1; $d <= $daysInMonth; $d++) {
                            $classes = 'cal-day';
                            if ($d === $todayDom) $classes .= ' today';
                            if (in_array($d, $eventDays, true)) $classes .= ' has-event';
                            echo "<div class='$classes'>$d</div>";
                        }
                        ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
<script src="assets/js/date-picker.js?v=<?= @filemtime(__DIR__ . "/assets/js/date-picker.js") ?: 1 ?>"></script>
<script>
(function () {
    var tabs = document.getElementById('revTabs');
    if (!tabs) return;
    var sub = document.getElementById('revSub');
    tabs.addEventListener('click', function (e) {
        var tab = e.target.closest('.chart-tab');
        if (!tab) return;
        var range = tab.getAttribute('data-range');
        tabs.querySelectorAll('.chart-tab').forEach(function (t) { t.classList.toggle('active', t === tab); });
        document.querySelectorAll('.rev-panel').forEach(function (p) {
            p.classList.toggle('active', p.getAttribute('data-range') === range);
        });
        if (sub) sub.textContent = tab.getAttribute('data-sub') || sub.textContent;
    });

    // Ledger expand/collapse (per panel)
    document.querySelectorAll('.ledger-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var wrap = btn.nextElementSibling;
            if (!wrap) return;
            var open = wrap.hasAttribute('hidden');
            if (open) { wrap.removeAttribute('hidden'); } else { wrap.setAttribute('hidden', ''); }
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            btn.textContent = open ? 'Hide ledger details' : 'View ledger details';
        });
    });
})();
</script>
</body>
</html>
