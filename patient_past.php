<?php
/**
 * Patient Past — every slip this patient has ever been issued, in one timeline.
 *
 * Reception's by-patient answer to "what has this patient had, and what did they
 * pay?". Replaces the old "Stays" row button, which linked to admissions.php
 * filtered by MRN and — carrying no tab parameter — landed on "Currently
 * admitted", so for any patient not admitted right now it showed an EMPTY table.
 *
 * Reads all FIVE revenue streams, the same set clinic_revenue() sums:
 *   bills            (OPD consultation, "INV-" series)   -> checkout.php
 *   er_bills         (walk-in ER service, "E" series)    -> er_bill.php
 *   admission_bills  (short stay,        "A" series)     -> admission_invoice.php
 *   ipd_bills        (in-door,           "I" series)     -> ipd_invoice.php
 *   procedure_bills  (procedure,         "P" series)     -> procedure_bill.php
 * Reading fewer sources than the clinic actually bills would silently under-report
 * a patient's history, which is the same class of bug as a payout that misses a
 * stream — so the stream list is one array, and every reader walks all of it.
 *
 * Each stream is wrapped independently: a database missing the IPD, ER or
 * procedure module contributes nothing instead of fataling the whole page
 * (mirrors clinic_revenue()'s per-stream try/catch).
 *
 * VOIDED bills are shown, struck through, and excluded from the totals — money
 * that was reversed still belongs in a history, but never in a sum.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
// column_exists() — used to probe admission_bills.voided_at, which older
// databases predate. Defined in billing.php, which nothing else here pulls in.
require_once __DIR__ . '/config/billing.php';
refresh_session_permissions($pdo);

// Read-only lookup of a patient's own billing history. Gated like the patients
// list it is reached from — reception registers them, nursing admits them.
if (!has_permission('RECEPTION_REGISTER_PATIENTS') && !has_permission('NURSING_RECORD_ADMISSIONS')) {
    http_response_code(403);
    die('You do not have permission to view patient history.');
}

// A patient's billing record is private — never let it sit in a shared cache.
header('Cache-Control: no-store, no-cache, must-revalidate, private');

$patientId = (int) ($_GET['id'] ?? 0);
if ($patientId <= 0) {
    header('Location: patients.php');
    exit;
}

// prepare() itself throws on a missing table (the server parses the statement
// up front), so BOTH calls have to sit inside the try — catching only around
// execute() would still fatal the page on a database without discount_categories.
try {
    $pst = $pdo->prepare('SELECT p.*, dc.name AS discount_category_name
                            FROM patients p
                            LEFT JOIN discount_categories dc ON dc.id = p.discount_category_id
                           WHERE p.id = ?');
    $pst->execute([$patientId]);
} catch (PDOException $e) {
    // discount_categories not migrated on this database — fall back to the bare row.
    $pst = $pdo->prepare('SELECT *, NULL AS discount_category_name FROM patients WHERE id = ?');
    $pst->execute([$patientId]);
}
$patient = $pst->fetch();
if (!$patient) {
    http_response_code(404);
    die('Patient not found.');
}

// ---------------------------------------------------------------------------
// Collect every slip. One row shape per stream so the timeline renders uniformly:
//   kind, kind_label, date (paid_at, else created_at), invoice_number, doctor,
//   detail, items, amount, face, payment_method, voided, href
// `amount` is what actually counts toward the totals (0 once voided); `face` is
// what the slip says, so a voided row can still show its struck-through value.
// ---------------------------------------------------------------------------
$events = [];

// Every money read filters voided_at IS NULL for the TOTALS, but this page wants
// the voided rows visible — so voided_at is SELECTed, never used to exclude.
$pull = function (string $sql, array $params, callable $map) use ($pdo, &$events) {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll() as $r) {
            $events[] = $map($r);
        }
    } catch (PDOException $e) {
        // Stream not migrated on this database — contribute nothing, never fatal.
        error_log('[patient_past] stream skipped: ' . $e->getMessage());
    }
};

// ---- 1. OPD consultations (bills -> visits) --------------------------------
// bills has no voided_at (refunds, not voids, reverse a consultation), so the
// void column is a literal NULL to keep the row shape identical across streams.
$pull(
    "SELECT b.id, b.invoice_number, b.grand_total AS face, b.paid_amount, b.status,
            b.payment_method, b.paid_at, b.created_at,
            NULL AS voided_at,
            v.visit_date, d.name AS doctor_name, ct.name AS consult_type
       FROM bills b
       JOIN visits v ON v.id = b.visit_id
       LEFT JOIN users d ON d.id = v.doctor_id
       LEFT JOIN doctor_consult_types ct ON ct.id = v.doctor_consult_type_id
      WHERE v.patient_id = ?
      ORDER BY b.id DESC",
    [$patientId],
    function ($r) {
        return [
            'kind' => 'opd', 'kind_label' => 'Consultation',
            'date' => $r['paid_at'] ?: ($r['created_at'] ?: $r['visit_date']),
            'invoice_number' => $r['invoice_number'],
            'doctor' => $r['doctor_name'] ?: '—',
            'detail' => $r['consult_type'] ?: 'Consultation',
            'items' => '',
            'face' => (float) $r['face'],
            'amount' => $r['status'] === 'paid' ? (float) $r['paid_amount'] : 0.0,
            'status' => $r['status'],
            'payment_method' => $r['payment_method'],
            'voided' => false,
            'bill_id' => (int) $r['id'],
            'href' => 'checkout.php?print=1&bill_id=' . (int) $r['id'],
        ];
    }
);

// ---- 2. ER walk-in services (er_bills, patient_id direct) ------------------
// Attending doctor mirrors the admission pair: a system user id, else free text.
$pull(
    "SELECT e.id, e.invoice_number, e.grand_total AS face, e.paid_amount, e.status,
            e.payment_method, e.paid_at, e.created_at, e.voided_at,
            COALESCE(du.name, e.doctor_manual) AS doctor_name,
            (SELECT GROUP_CONCAT(CONCAT(i.description,
                    CASE WHEN i.quantity > 1 THEN CONCAT(' x', i.quantity) ELSE '' END)
                    ORDER BY i.id SEPARATOR ' · ')
               FROM er_bill_items i WHERE i.er_bill_id = e.id) AS items
       FROM er_bills e
       LEFT JOIN users du ON du.id = e.doctor_id
      WHERE e.patient_id = ?
      ORDER BY e.id DESC",
    [$patientId],
    function ($r) {
        $void = $r['voided_at'] !== null;
        return [
            'kind' => 'er', 'kind_label' => 'ER Service',
            'date' => $r['paid_at'] ?: $r['created_at'],
            'invoice_number' => $r['invoice_number'],
            'doctor' => $r['doctor_name'] ?: '—',
            'detail' => 'Walk-in · no admission',
            'items' => $r['items'] ?: '',
            'face' => (float) $r['face'],
            'amount' => (!$void && $r['status'] === 'paid') ? (float) $r['paid_amount'] : 0.0,
            'status' => $r['status'],
            'payment_method' => $r['payment_method'],
            'voided' => $void,
            'bill_id' => 0,
            'href' => 'er_bill.php?print=1&er_bill_id=' . (int) $r['id'],
        ];
    }
);

// ---- 3. Short-stay admissions (admission_bills -> admissions -> visits) ----
// admission_bills predates the void trio, so voided_at may not exist on older
// databases; COALESCE through a subquery would still fail there. The stream
// wrapper catches that case and simply omits admissions.
$admVoid = column_exists($pdo, 'admission_bills', 'voided_at') ? 'ab.voided_at' : 'NULL';
$pull(
    "SELECT ab.id, ab.invoice_number, ab.grand_total AS face, ab.paid_amount, ab.status,
            ab.payment_method, ab.paid_at, ab.created_at,
            $admVoid AS voided_at,
            a.id AS admission_id, a.admitted_at, a.discharged_at, a.admission_type,
            COALESCE(du.name, a.admitting_doctor_manual) AS doctor_name
       FROM admission_bills ab
       JOIN admissions a ON a.id = ab.admission_id
       JOIN visits v ON v.id = a.visit_id
       LEFT JOIN users du ON du.id = a.admitting_doctor_id
      WHERE v.patient_id = ?
      ORDER BY ab.id DESC",
    [$patientId],
    function ($r) {
        $void = $r['voided_at'] !== null;
        $span = '';
        if ($r['admitted_at'] && $r['discharged_at']) {
            $mins = (int) round((strtotime($r['discharged_at']) - strtotime($r['admitted_at'])) / 60);
            $span = intdiv($mins, 60) . 'h ' . ($mins % 60) . 'm';
        }
        return [
            'kind' => 'adm', 'kind_label' => 'Admission',
            'date' => $r['paid_at'] ?: ($r['admitted_at'] ?: $r['created_at']),
            'invoice_number' => $r['invoice_number'],
            'doctor' => $r['doctor_name'] ?: '—',
            'detail' => 'Short stay' . ($span ? ' · ' . $span : '')
                        . ' · ' . ucwords(strtolower(str_replace('_', ' ', (string) $r['admission_type']))),
            'items' => '',
            'face' => (float) $r['face'],
            'amount' => (!$void && $r['status'] === 'paid') ? (float) $r['paid_amount'] : 0.0,
            'status' => $r['status'],
            'payment_method' => $r['payment_method'],
            'voided' => $void,
            'bill_id' => 0,
            'href' => 'admission_invoice.php?id=' . (int) $r['admission_id'],
        ];
    }
);

// ---- 4. In-Door / IPD (ipd_bills -> ipd_admissions -> visits) --------------
$pull(
    "SELECT ib.id, ib.invoice_number, ib.grand_total AS face, ib.paid_amount, ib.status,
            ib.payment_method, ib.paid_at, ib.created_at, ib.voided_at,
            ia.id AS admission_id, ia.admitted_at, ia.discharged_at, ia.room_no,
            COALESCE(du.name, ia.admitting_consultant_manual) AS doctor_name
       FROM ipd_bills ib
       JOIN ipd_admissions ia ON ia.id = ib.admission_id
       JOIN visits v ON v.id = ia.visit_id
       LEFT JOIN users du ON du.id = ia.admitting_consultant_id
      WHERE v.patient_id = ?
      ORDER BY ib.id DESC",
    [$patientId],
    function ($r) {
        $void = $r['voided_at'] !== null;
        $nights = '';
        if ($r['admitted_at'] && $r['discharged_at']) {
            $n = (int) floor((strtotime($r['discharged_at']) - strtotime($r['admitted_at'])) / 86400);
            $nights = max(1, $n) . ($n === 1 ? ' night' : ' nights');
        }
        return [
            'kind' => 'ipd', 'kind_label' => 'In-Door',
            'date' => $r['paid_at'] ?: ($r['admitted_at'] ?: $r['created_at']),
            'invoice_number' => $r['invoice_number'],
            'doctor' => $r['doctor_name'] ?: '—',
            'detail' => 'Room ' . (int) $r['room_no'] . ($nights ? ' · ' . $nights : ''),
            'items' => '',
            'face' => (float) $r['face'],
            'amount' => (!$void && $r['status'] === 'paid') ? (float) $r['paid_amount'] : 0.0,
            'status' => $r['status'],
            'payment_method' => $r['payment_method'],
            'voided' => $void,
            'bill_id' => 0,
            'href' => 'ipd_invoice.php?id=' . (int) $r['admission_id'],
        ];
    }
);

// ---- 5. Procedures (procedure_bills, patient_id direct) --------------------
$pull(
    "SELECT pb.id, pb.invoice_number, pb.grand_total AS face, pb.paid_amount, pb.status,
            pb.payment_method, pb.paid_at, pb.created_at, pb.voided_at,
            pb.discount_pct, pb.subtotal,
            du.name AS doctor_name,
            (SELECT GROUP_CONCAT(i.description ORDER BY i.id SEPARATOR ' · ')
               FROM procedure_bill_items i WHERE i.procedure_bill_id = pb.id) AS items
       FROM procedure_bills pb
       LEFT JOIN users du ON du.id = pb.doctor_id
      WHERE pb.patient_id = ?
      ORDER BY pb.id DESC",
    [$patientId],
    function ($r) {
        $void = $r['voided_at'] !== null;
        $disc = (float) $r['discount_pct'] > 0
            ? ' · ' . rtrim(rtrim(number_format((float) $r['discount_pct'], 2), '0'), '.') . '% discount'
            : '';
        return [
            'kind' => 'proc', 'kind_label' => 'Procedure',
            'date' => $r['paid_at'] ?: $r['created_at'],
            'invoice_number' => $r['invoice_number'],
            'doctor' => $r['doctor_name'] ?: '—',
            'detail' => 'Procedure' . $disc,
            'items' => $r['items'] ?: '',
            'face' => (float) $r['face'],
            'amount' => (!$void && $r['status'] === 'paid') ? (float) $r['paid_amount'] : 0.0,
            'status' => $r['status'],
            'payment_method' => $r['payment_method'],
            'voided' => $void,
            'bill_id' => 0,
            'href' => 'procedure_bill.php?print=1&procedure_bill_id=' . (int) $r['id'],
        ];
    }
);

// ---- Refunds, attached to their consultation bill --------------------------
// Refunds hang off `bills` only (sql/add_refunds.sql), so they annotate OPD rows
// rather than forming their own timeline entries — a refund is not a visit.
$refundsByBill = [];
$refundTotal = 0.0;
try {
    $rst = $pdo->prepare('SELECT r.bill_id, r.refund_number, r.amount, r.reason,
                                 r.refund_mode, r.created_at, u.name AS approved_by
                            FROM refunds r
                            JOIN bills b ON b.id = r.bill_id
                            JOIN visits v ON v.id = b.visit_id
                            LEFT JOIN users u ON u.id = r.approved_by_id
                           WHERE v.patient_id = ?
                           ORDER BY r.id');
    $rst->execute([$patientId]);
    foreach ($rst->fetchAll() as $r) {
        $refundsByBill[(int) $r['bill_id']][] = $r;
        $refundTotal += (float) $r['amount'];
    }
} catch (PDOException $e) {
    error_log('[patient_past] refunds skipped: ' . $e->getMessage());
}

// ---- Sort newest-first across every stream, then total --------------------
usort($events, function ($a, $b) {
    return strcmp((string) $b['date'], (string) $a['date']);
});

$streamCounts = ['opd' => 0, 'er' => 0, 'adm' => 0, 'ipd' => 0, 'proc' => 0];
$totalBilled = 0.0;
$totalPaid   = 0.0;
foreach ($events as $e) {
    if (isset($streamCounts[$e['kind']])) {
        $streamCounts[$e['kind']]++;
    }
    if (!$e['voided']) {
        $totalBilled += $e['face'];
        $totalPaid   += $e['amount'];
    }
}
// What the patient is actually out of pocket. Refunds are collected separately
// (gross per stream is what a "where did the money come from" reader wants), so
// the net has to be taken here — a "Paid" figure that ignored a refund would
// overstate what this patient handed over, on the one page meant to answer that.
$netPaid = $totalPaid - $refundTotal;
$lastVisit = $events ? $events[0]['date'] : null;

// Optional stream filter — chips are real URLs, so a filtered view is linkable.
$filter = $_GET['k'] ?? '';
if (!isset($streamCounts[$filter])) {
    $filter = '';
}
$shown = $filter === '' ? $events : array_values(array_filter($events, function ($e) use ($filter) {
    return $e['kind'] === $filter;
}));

$pageTitle = 'Patient Past';
// Page-specific only: the timeline card, the stream chips and the totals tiles.
// Tokens, .content, .btn, .empty and .page-title all come from app.css.
$headExtra = <<<CSS
<style>
.pp-head { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 18px 20px; margin-bottom: 16px; }
.pp-top { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-start; justify-content: space-between; }
.pp-name { font-size: 21px; font-weight: 700; letter-spacing: .2px; }
.pp-meta { color: var(--text-muted); font-size: 13px; margin-top: 3px; }
.mono { font-variant-numeric: tabular-nums; }
.pp-totals { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border); }
.pp-tile { background: var(--bg); border: 1px solid var(--border); border-radius: 10px; padding: 11px 13px; }
.pp-tile .k { font-size: 11px; text-transform: uppercase; letter-spacing: .6px; color: var(--text-muted); font-weight: 700; }
.pp-tile .v { font-size: 19px; font-weight: 700; margin-top: 3px; font-variant-numeric: tabular-nums; }
.pp-tile.net .v { color: var(--ok, #2F6D4F); }
.pp-tile.ref .v { color: var(--danger, #A33A32); }
/* Gross, shown under the net so a refunded patient's tile still reconciles. */
.pp-tile-note { font-size: 11px; color: var(--text-muted); margin-top: 2px; font-variant-numeric: tabular-nums; }
.pp-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
.pp-chip { padding: 6px 13px; border-radius: 999px; font-size: 12.5px; font-weight: 600; text-decoration: none; background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary); }
.pp-chip.on { background: var(--primary); border-color: var(--primary); color: #fff; }
.pp-chip .n { opacity: .65; margin-left: 5px; font-variant-numeric: tabular-nums; }
.pp-year { font-size: 12px; font-weight: 700; letter-spacing: .8px; text-transform: uppercase; color: var(--text-muted); margin: 22px 0 8px 2px; }
.pp-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; margin-bottom: 9px; overflow: hidden; }
.pp-card.void { opacity: .62; }
.pp-row { display: grid; grid-template-columns: 96px 1fr auto; gap: 14px; padding: 13px 16px; align-items: start; }
.pp-date { font-size: 12.5px; color: var(--text-muted); font-variant-numeric: tabular-nums; padding-top: 2px; }
.pp-date b { display: block; font-size: 14px; color: var(--text); font-weight: 600; }
.pp-what { min-width: 0; }
.pp-line1 { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.pp-kind { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; padding: 2.5px 8px; border-radius: 5px; white-space: nowrap; }
.k-opd { background: var(--primary-light); color: var(--primary-accent); }
.k-er { background: var(--clay-bg, #F5E8E0); color: var(--clay, #A6603F); }
.k-adm { background: var(--warn-bg, #F6EEDC); color: var(--warn, #8A6318); }
.k-ipd { background: #E2E8F5; color: #3B5488; }
.k-proc { background: var(--ok-bg, #E4F0E8); color: var(--ok, #2F6D4F); }
.pp-title { font-weight: 600; }
.pp-sub { color: var(--text-muted); font-size: 12.5px; margin-top: 3px; }
.pp-items { color: var(--text-secondary); font-size: 12.5px; margin-top: 5px; }
.pp-money { text-align: right; white-space: nowrap; padding-top: 1px; }
.pp-amt { font-size: 16px; font-weight: 700; font-variant-numeric: tabular-nums; }
.pp-inv { font-size: 12px; margin-top: 3px; }
.pp-inv a { color: var(--primary-accent); text-decoration: none; font-variant-numeric: tabular-nums; }
.pp-inv a:hover { text-decoration: underline; }
.pp-pay { font-size: 11.5px; color: var(--text-muted); margin-top: 2px; }
.pp-tag { display: inline-block; font-size: 10.5px; font-weight: 700; padding: 2px 7px; border-radius: 4px; text-transform: uppercase; letter-spacing: .4px; }
.t-void, .t-ref { background: var(--danger-bg, #F7E6E4); color: var(--danger, #A33A32); }
.t-waive { background: var(--bg); color: var(--text-muted); border: 1px solid var(--border); }
.t-unpaid { background: var(--warn-bg, #F6EEDC); color: var(--warn, #8A6318); }
.strike { text-decoration: line-through; color: var(--text-muted); }
.pp-refline { border-top: 1px dashed var(--border); padding: 8px 16px 9px 126px; font-size: 12.5px; color: var(--danger, #A33A32); background: var(--danger-bg, #F7E6E4); }
@media (max-width: 640px) {
  .pp-row { grid-template-columns: 1fr auto; }
  .pp-date { grid-column: 1 / -1; }
  .pp-refline { padding-left: 16px; }
}
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'patients';
require __DIR__ . '/partials/sidebar.php';
?>
        <?php require __DIR__ . '/partials/quick_header.php'; ?>

<div class="content">

    <div>
        <div class="page-title">Patient Past</div>
        <div class="page-sub">Every slip this patient has been issued &mdash; consultations, ER services, admissions, in-door stays and procedures.</div>
    </div>

    <div class="pp-head">
        <div class="pp-top">
            <div>
                <div class="pp-name"><?= htmlspecialchars($patient['name']) ?></div>
                <div class="pp-meta">
                    MRN <span class="mono"><?= htmlspecialchars($patient['mrn']) ?></span>
                    <?= $patient['father_name'] ? ' &middot; ' . htmlspecialchars($patient['father_name']) : '' ?>
                    <?= $patient['dob'] ? ' &middot; ' . date('d/m/Y', strtotime($patient['dob'])) : '' ?>
                    <?= !empty($patient['gender']) ? ' &middot; ' . htmlspecialchars($patient['gender']) : '' ?>
                    <?= $patient['phone'] ? ' &middot; <span class="mono">' . htmlspecialchars($patient['phone']) . '</span>' : '' ?>
                    <?= !empty($patient['discount_category_name']) ? ' &middot; ' . htmlspecialchars($patient['discount_category_name']) : '' ?>
                </div>
            </div>
            <a class="btn secondary" href="patients.php?q=<?= urlencode($patient['mrn']) ?>">&larr; Back to patients</a>
        </div>

        <div class="pp-totals">
            <div class="pp-tile"><div class="k">Total visits</div><div class="v"><?= count($events) ?></div></div>
            <div class="pp-tile"><div class="k">Billed</div><div class="v mono"><?= number_format($totalBilled) ?></div></div>
            <div class="pp-tile net">
                <div class="k">Paid<?= $refundTotal > 0 ? ' (net)' : '' ?></div>
                <div class="v mono"><?= number_format($netPaid) ?></div>
                <?php if ($refundTotal > 0): ?>
                <div class="pp-tile-note">collected <?= number_format($totalPaid) ?></div>
                <?php endif; ?>
            </div>
            <?php if ($refundTotal > 0): ?>
            <div class="pp-tile ref"><div class="k">Refunded</div><div class="v mono"><?= number_format($refundTotal) ?></div></div>
            <?php endif; ?>
            <div class="pp-tile"><div class="k">Last visit</div>
                <div class="v" style="font-size:15px"><?= $lastVisit ? date('d/m/Y', strtotime($lastVisit)) : '&mdash;' ?></div></div>
        </div>
    </div>

    <?php if ($events): ?>
    <div class="pp-chips">
        <a class="pp-chip<?= $filter === '' ? ' on' : '' ?>" href="patient_past.php?id=<?= $patientId ?>">All <span class="n"><?= count($events) ?></span></a>
        <?php foreach (['opd' => 'Consultation', 'er' => 'ER Service', 'adm' => 'Admission',
                        'ipd' => 'In-Door', 'proc' => 'Procedure'] as $k => $label): ?>
            <?php if ($streamCounts[$k] > 0): ?>
            <a class="pp-chip<?= $filter === $k ? ' on' : '' ?>" href="patient_past.php?id=<?= $patientId ?>&amp;k=<?= $k ?>"><?= $label ?> <span class="n"><?= $streamCounts[$k] ?></span></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!$shown): ?>
        <div class="empty">
            <?= $events ? 'No records in this category.' : 'No bills have been raised for this patient yet.' ?>
        </div>
    <?php else: ?>
        <?php $lastYear = null; ?>
        <?php foreach ($shown as $e): ?>
            <?php
            $ts = $e['date'] ? strtotime($e['date']) : null;
            $year = $ts ? date('Y', $ts) : '—';
            if ($year !== $lastYear) {
                echo '<div class="pp-year">' . htmlspecialchars($year) . '</div>';
                $lastYear = $year;
            }
            $refunds = $e['bill_id'] ? ($refundsByBill[$e['bill_id']] ?? []) : [];
            ?>
            <div class="pp-card<?= $e['voided'] ? ' void' : '' ?>">
                <div class="pp-row">
                    <div class="pp-date">
                        <b><?= $ts ? date('d/m', $ts) : '&mdash;' ?></b><?= $ts ? date('H:i', $ts) : '' ?>
                    </div>
                    <div class="pp-what">
                        <div class="pp-line1">
                            <span class="pp-kind k-<?= $e['kind'] ?>"><?= htmlspecialchars($e['kind_label']) ?></span>
                            <span class="pp-title<?= $e['voided'] ? ' strike' : '' ?>"><?= htmlspecialchars($e['doctor']) ?></span>
                            <?php if ($e['voided']): ?><span class="pp-tag t-void">Voided</span><?php endif; ?>
                            <?php if (!$e['voided'] && $e['status'] === 'waived'): ?><span class="pp-tag t-waive">Waived</span><?php endif; ?>
                            <?php if (!$e['voided'] && in_array($e['status'], ['draft', 'finalized'], true)): ?><span class="pp-tag t-unpaid">Unpaid</span><?php endif; ?>
                            <?php if ($refunds): ?><span class="pp-tag t-ref">Refunded</span><?php endif; ?>
                        </div>
                        <div class="pp-sub"><?= htmlspecialchars($e['detail']) ?></div>
                        <?php if ($e['items']): ?>
                        <div class="pp-items"><?= htmlspecialchars($e['items']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="pp-money">
                        <div class="pp-amt mono<?= $e['voided'] ? ' strike' : '' ?>"><?= number_format($e['face']) ?></div>
                        <div class="pp-inv"><a href="<?= htmlspecialchars($e['href']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($e['invoice_number']) ?></a></div>
                        <div class="pp-pay"><?= $e['voided'] ? '&mdash;' : htmlspecialchars(ucfirst(str_replace('_', ' ', (string) $e['payment_method']) ?: '—')) ?></div>
                    </div>
                </div>
                <?php foreach ($refunds as $rf): ?>
                <div class="pp-refline">
                    &#8627; Refund <b><?= htmlspecialchars($rf['refund_number']) ?></b>
                    &middot; <?= number_format((float) $rf['amount']) ?> <?= htmlspecialchars(str_replace('_', ' ', $rf['refund_mode'])) ?>
                    &middot; <?= date('d/m/Y H:i', strtotime($rf['created_at'])) ?>
                    &middot; <?= htmlspecialchars($rf['reason']) ?>
                    <?= $rf['approved_by'] ? ' &middot; approved ' . htmlspecialchars($rf['approved_by']) : '' ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>
        </div><!-- .main -->
    </div><!-- .app -->
</body>
</html>
