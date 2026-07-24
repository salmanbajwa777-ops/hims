<?php
/**
 * Doctor Analytics — "My Reports" for the doctor console.
 *
 * Three sections on ?view= :
 *   revenue    — stacked bar chart of the DOCTOR'S EARNED share (Full / Revisits;
 *                (paid − tax) × share%, consultation money only — ER admission
 *                bills are clinic revenue and excluded), month-year granularity,
 *                previous-period comparison, summary cards
 *   patients   — "Consultations & Revisits": filterable consultation table
 *                (fee-type column covers Full + every revisit tier) + a revisit
 *                summary strip (rate + per-tier counts) + CSV export
 *   procedures — placeholder; procedure billing lands in a later phase
 *
 * (The old standalone 'revisits' tab was folded into 'patients'; 'admissions'
 * was dropped from doctor analytics — that block is kept but unreachable via
 * the tabs, redirected to revenue, since admission money isn't doctor revenue.)
 *
 * A DOCTOR always sees their own numbers. ADMIN opens the same page with a
 * doctor picker (?doctor_id=). No schema changes — everything reads visits +
 * bills.
 *
 * The Revenue view shows the doctor's EARNED share of paid consultations
 * (using consult_share_pct / consult_tax_pct on the users row), NOT gross
 * billed and NOT admission money (ER admission bills are clinic revenue). The
 * Consultations table still lists each invoice's billed total — it's a
 * transaction ledger for reconciliation, distinct from the earnings headline.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);
require_once __DIR__ . '/config/billing.php';

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
if (!$user) {
    session_destroy();
    header('Location: /index.php');
    exit;
}

$baseRole = $_SESSION['base_role'] ?? '';
if ($baseRole !== 'DOCTOR' && $baseRole !== 'ADMIN') {
    http_response_code(403);
    exit('Forbidden — doctor console only.');
}

// A doctor is locked to their own id; an admin may pick any doctor.
$isAdmin = ($baseRole === 'ADMIN');
$doctorId = (int) $user['id'];
$doctorList = [];
if ($isAdmin) {
    $doctorList = $pdo->query("SELECT id, name FROM users WHERE base_role = 'DOCTOR' ORDER BY name")->fetchAll();
    $requested = (int) ($_GET['doctor_id'] ?? 0);
    if ($requested > 0) {
        foreach ($doctorList as $d) {
            if ((int) $d['id'] === $requested) { $doctorId = $requested; break; }
        }
    } elseif ($doctorList) {
        $doctorId = (int) $doctorList[0]['id'];
    }
}
$docStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
$docStmt->execute([$doctorId]);
$doctorName = (string) ($docStmt->fetchColumn() ?: $user['name']);

$view = $_GET['view'] ?? 'revenue';
// Three sections now: revenue | patients (Consultations & Revisits) | procedures.
// The old standalone 'revisits' tab folded INTO the patients view (its summary
// strip lives there now); 'admissions' was dropped from doctor analytics
// entirely (still on the console cards). Legacy links fall back gracefully.
if ($view === 'revisits') { $view = 'patients'; }
if ($view === 'admissions') { $view = 'revenue'; }
if (!in_array($view, ['revenue', 'patients', 'procedures'], true)) {
    $view = 'revenue';
}

// Keeps the doctor picker sticky across view links (admin only).
function qs_view(string $view, array $extra = []): string {
    global $isAdmin, $doctorId;
    $q = ['view' => $view] + $extra;
    if ($isAdmin) { $q['doctor_id'] = $doctorId; }
    return 'doctor_analytics.php?' . http_build_query($q);
}

$FEE_TYPE_LABELS = [
    'FULL' => 'Full',
    'FREE_FOLLOWUP' => 'Revisit FREE',
    'HALF_FOLLOWUP' => 'Revisit 50%',
    'THREE_QUARTER_FOLLOWUP' => 'Revisit 75%',
];

// Payment badge for a consultation bill row. paid → Paid; paid_amount short of
// grand_total → Partial; anything else (draft/finalized/no bill) → Unpaid.
function pay_badge(?string $status, $paidAmount, $grandTotal): array {
    if ($status === 'paid') {
        if ($paidAmount !== null && (float) $paidAmount + 0.005 < (float) $grandTotal) {
            return ['Partial', 'amber'];
        }
        return ['Paid', 'green'];
    }
    return ['Unpaid', 'red'];
}

// ============================================================================
// VIEW: patients — table data (+ CSV export takes over the response)
// ============================================================================
if ($view === 'patients') {
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
    $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
    $feeType = $_GET['fee_type'] ?? '';
    if (!isset($FEE_TYPE_LABELS[$feeType])) { $feeType = ''; }
    $payFilter = in_array($_GET['pay'] ?? '', ['paid', 'partial', 'unpaid'], true) ? $_GET['pay'] : '';

    $sql = "
        SELECT v.id AS visit_id, v.visit_date, v.created_at, v.fee, v.discount_pct,
               v.category_discount_amount, v.consultation_fee_type, v.fee_overridden,
               p.name AS patient_name, p.mrn,
               t.label AS type_label,
               b.invoice_number, b.status AS bill_status, b.grand_total, b.paid_amount
        FROM visits v
        JOIN patients p ON p.id = v.patient_id
        LEFT JOIN doctor_consult_types t ON t.id = v.doctor_consult_type_id
        LEFT JOIN bills b ON b.visit_id = v.id AND b.voided_at IS NULL
        WHERE v.doctor_id = ? AND v.visit_date BETWEEN ? AND ?
    ";
    $params = [$doctorId, $from, $to];
    if ($feeType !== '') {
        $sql .= " AND v.consultation_fee_type = ?";
        $params[] = $feeType;
    }
    $sql .= " ORDER BY v.visit_date DESC, v.id DESC";
    $q = $pdo->prepare($sql);
    $q->execute($params);
    $patientRows = $q->fetchAll();

    // Payment filter applies to the derived badge, so it's done in PHP.
    if ($payFilter !== '') {
        $patientRows = array_values(array_filter($patientRows, function ($r) use ($payFilter) {
            [$label] = pay_badge($r['bill_status'], $r['paid_amount'], $r['grand_total']);
            return strtolower($label) === $payFilter;
        }));
    }

    // Revisit summary for the SAME date range (the old Revisits tab, folded in).
    // Counted over the full matched set BEFORE the pay filter narrows the view,
    // so the rate reflects the range, not the current payment lens. Uses the
    // unfiltered $patientRows only when no fee-type filter is active; with a
    // fee-type filter the summary is naturally scoped to what's shown.
    $rvTierCounts = ['FREE_FOLLOWUP' => 0, 'HALF_FOLLOWUP' => 0, 'THREE_QUARTER_FOLLOWUP' => 0];
    $rvFullCount = 0;
    foreach ($patientRows as $r) {
        $ft = $r['consultation_fee_type'];
        if ($ft === 'FULL') { $rvFullCount++; }
        elseif (isset($rvTierCounts[$ft])) { $rvTierCounts[$ft]++; }
    }
    $rvCount = array_sum($rvTierCounts);
    $rvTotal = $rvCount + $rvFullCount;
    $rvRate = $rvTotal > 0 ? $rvCount / $rvTotal * 100 : 0.0;

    if (($_GET['export'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="consultations_' . $from . '_' . $to . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Date', 'Invoice', 'Patient', 'MRN', 'Consult type', 'Fee type', 'Billed (PKR)', 'Payment', 'Fee overridden']);
        foreach ($patientRows as $r) {
            [$payLabel] = pay_badge($r['bill_status'], $r['paid_amount'], $r['grand_total']);
            fputcsv($out, [
                $r['visit_date'],
                $r['invoice_number'] ?? '',
                $r['patient_name'],
                $r['mrn'],
                $r['type_label'] ?? '',
                $FEE_TYPE_LABELS[$r['consultation_fee_type']] ?? $r['consultation_fee_type'],
                number_format((float) ($r['grand_total'] ?? $r['fee']), 2, '.', ''),
                $payLabel,
                $r['fee_overridden'] ? 'yes' : '',
            ]);
        }
        fclose($out);
        exit;
    }
}

// ============================================================================
// VIEW: revenue — build series for the chart + summary
// ============================================================================
if ($view === 'revenue') {
    // Day granularity was cut (2026-07-23, user request): Month and Year only.
    $gran = in_array($_GET['gran'] ?? '', ['month', 'year'], true) ? $_GET['gran'] : 'month';
    $compare = ($_GET['compare'] ?? '1') !== '0';

    // Anchor period. month → a year of monthly bars; year → the last 6 years.
    if ($gran === 'month') {
        $period = preg_match('/^\d{4}$/', $_GET['period'] ?? '') ? $_GET['period'] : date('Y');
        $curStart = $period . '-01-01';
        $curEnd = $period . '-12-31';
        $prevStart = ($period - 1) . '-01-01';
        $prevEnd = ($period - 1) . '-12-31';
        $bucketExpr = 'MONTH(%s)';
        $bucketCount = 12;
        $periodLabel = $period;
        $prevLabel = (string) ($period - 1);
        $navPrev = (string) ($period - 1);
        $navNext = (string) ($period + 1);
    } else { // year — one fixed window, no prev/next nav, no comparison pair
        $yearNow = (int) date('Y');
        $firstYear = $yearNow - 5;
        $period = '';
        $curStart = $firstYear . '-01-01';
        $curEnd = $yearNow . '-12-31';
        $prevStart = $prevEnd = null;
        $bucketExpr = 'YEAR(%s)';
        $bucketCount = 6;
        $periodLabel = $firstYear . ' – ' . $yearNow;
        $prevLabel = '';
        $navPrev = $navNext = null;
        $compare = false;
    }

    if (!isset($firstYear)) { $firstYear = null; }

    // One period's stacked series: [bucket => [full, revisit]].
    // Amounts are the DOCTOR'S EARNED share — (paid − tax) × share% — not gross
    // billed (the clinic keeps the rest). Same formula as dashboard.php.
    // Consultations count when their bill is PAID, attributed to visit_date.
    // ER admission bills are deliberately EXCLUDED (2026-07-23): admission money
    // is clinic revenue, not the doctor's — it does not belong on this chart.
    $earnedExpr = "(CASE WHEN dr.consult_has_tax = 1
                        THEN (b2.paid_amount - b2.paid_amount * dr.consult_tax_pct / 100) * dr.consult_share_pct / 100
                        ELSE b2.paid_amount * dr.consult_share_pct / 100 END)";
    $seriesFor = function (string $start, string $end) use ($pdo, $doctorId, $bucketExpr, $earnedExpr): array {
        $buckets = [];

        $cSql = "
            SELECT " . sprintf($bucketExpr, 'v.visit_date') . " AS b,
                   SUM(CASE WHEN v.consultation_fee_type = 'FULL' THEN $earnedExpr ELSE 0 END) AS full_amt,
                   SUM(CASE WHEN v.consultation_fee_type <> 'FULL' THEN $earnedExpr ELSE 0 END) AS revisit_amt,
                   SUM(CASE WHEN v.consultation_fee_type = 'FULL' THEN 1 ELSE 0 END) AS full_n,
                   SUM(CASE WHEN v.consultation_fee_type <> 'FULL' THEN 1 ELSE 0 END) AS revisit_n
            FROM visits v
            JOIN bills b2 ON b2.visit_id = v.id AND b2.status = 'paid' AND b2.voided_at IS NULL
            JOIN users dr ON dr.id = v.doctor_id
            WHERE v.doctor_id = ? AND v.visit_date BETWEEN ? AND ?
            GROUP BY b
        ";
        try {
            $c = $pdo->prepare($cSql);
            $c->execute([$doctorId, $start, $end]);
            $rows = $c->fetchAll();
        } catch (PDOException $e) {
            // consult_share columns not migrated yet — earnings read zero, but the
            // counts still matter, so re-run the same buckets without the amounts.
            $cSql0 = "
                SELECT " . sprintf($bucketExpr, 'v.visit_date') . " AS b,
                       0 AS full_amt, 0 AS revisit_amt,
                       SUM(CASE WHEN v.consultation_fee_type = 'FULL' THEN 1 ELSE 0 END) AS full_n,
                       SUM(CASE WHEN v.consultation_fee_type <> 'FULL' THEN 1 ELSE 0 END) AS revisit_n
                FROM visits v
                JOIN bills b2 ON b2.visit_id = v.id AND b2.status = 'paid' AND b2.voided_at IS NULL
                WHERE v.doctor_id = ? AND v.visit_date BETWEEN ? AND ?
                GROUP BY b
            ";
            $c = $pdo->prepare($cSql0);
            $c->execute([$doctorId, $start, $end]);
            $rows = $c->fetchAll();
        }
        foreach ($rows as $r) {
            $buckets[(int) $r['b']]['full'] = (float) $r['full_amt'];
            $buckets[(int) $r['b']]['revisit'] = (float) $r['revisit_amt'];
            $buckets[(int) $r['b']]['full_n'] = (int) $r['full_n'];
            $buckets[(int) $r['b']]['revisit_n'] = (int) $r['revisit_n'];
        }
        return $buckets;
    };

    $curSeries = $seriesFor($curStart, $curEnd);
    $prevSeries = ($compare && $prevStart) ? $seriesFor($prevStart, $prevEnd) : [];

    // Bucket key list in display order (year view keys are actual years).
    $bucketKeys = [];
    for ($i = 1; $i <= $bucketCount; $i++) {
        $bucketKeys[] = ($gran === 'year') ? ($firstYear + $i - 1) : $i;
    }
    // Comparison pairs the SAME bucket index of the previous period; for the
    // month view that's the same month number last year, for days the same
    // day-of-month last month (days 29–31 may have no counterpart — fine).

    $sum = fn(array $series, string $k) => array_sum(array_map(fn($b) => (float) ($b[$k] ?? 0), $series));
    $tot = [
        'full' => $sum($curSeries, 'full'),   'full_n' => (int) $sum($curSeries, 'full_n'),
        'revisit' => $sum($curSeries, 'revisit'), 'revisit_n' => (int) $sum($curSeries, 'revisit_n'),
    ];
    $totAll = $tot['full'] + $tot['revisit'];
    $prevAll = $sum($prevSeries, 'full') + $sum($prevSeries, 'revisit');

    // Revisit mix inside the period (counts by tier) for the summary card.
    $mixQ = $pdo->prepare("
        SELECT v.consultation_fee_type AS ft, COUNT(*) AS n
        FROM visits v
        JOIN bills b ON b.visit_id = v.id AND b.status = 'paid' AND b.voided_at IS NULL
        WHERE v.doctor_id = ? AND v.visit_date BETWEEN ? AND ? AND v.consultation_fee_type <> 'FULL'
        GROUP BY v.consultation_fee_type
    ");
    $mixQ->execute([$doctorId, $curStart, $curEnd]);
    $revisitMix = array_column($mixQ->fetchAll(), 'n', 'ft');
}

// ============================================================================
// VIEW: admissions — active cards + discharged history (no longer a doctor tab
// as of 2026-07-23, but kept reachable for admin deep-links / future reuse)
// ============================================================================
if ($view === 'admissions') {
    $admMonth = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
    $admStart = $admMonth . '-01';
    $admEnd = date('Y-m-t', strtotime($admStart));

    // Active: this doctor's current stays, with nurse + latest handover status
    // + running total (stay-so-far at the type rate + billable logged services).
    $actQ = $pdo->prepare("
        SELECT a.id, a.admission_type, a.admitted_at, a.status,
               p.name AS patient_name, p.mrn,
               nu.name AS nurse_name,
               ar.rate_amount, ar.rate_basis,
               (SELECT h.status_at_handover FROM admission_handovers h
                WHERE h.admission_id = a.id ORDER BY h.handover_time DESC, h.id DESC LIMIT 1) AS last_status,
               (SELECT COALESCE(SUM(s.calculated_charge), 0) FROM admission_services s
                WHERE s.admission_id = a.id AND s.is_billable = 1) AS services_total,
               (SELECT COUNT(*) FROM admission_services s
                WHERE s.admission_id = a.id AND s.is_billable = 1) AS services_n
        FROM admissions a
        JOIN visits v ON v.id = a.visit_id
        JOIN patients p ON p.id = v.patient_id
        LEFT JOIN users nu ON nu.id = a.assigned_nurse_id
        LEFT JOIN admission_rates ar ON ar.admission_type = a.admission_type
        WHERE COALESCE(a.admitting_doctor_id, v.doctor_id) = ?
          AND a.status IN ('PENDING_ASSIGNMENT','ACTIVE','DISCHARGE_IN_PROGRESS')
        ORDER BY a.admitted_at DESC
    ");
    $actQ->execute([$doctorId]);
    $activeAdms = $actQ->fetchAll();

    foreach ($activeAdms as &$a) {
        $mins = max(0, (int) floor((time() - strtotime($a['admitted_at'])) / 60));
        $a['elapsed_min'] = $mins;
        if (($a['rate_basis'] ?? 'HOURLY') === 'DAILY') {
            $a['stay_charge'] = (float) $a['rate_amount'] * max(1, (int) ceil($mins / 1440));
            $a['billed_units'] = max(1, (int) ceil($mins / 1440)) . ' day(s)';
        } else {
            $hrs = admission_billed_hours($mins);
            $a['stay_charge'] = (float) $a['rate_amount'] * $hrs;
            $a['billed_units'] = rtrim(rtrim(number_format($hrs, 2), '0'), '.') . ' h';
        }
        $a['running_total'] = $a['stay_charge'] + (float) $a['services_total'];
    }
    unset($a);

    // Discharged in the picked month, with the bill outcome.
    $disQ = $pdo->prepare("
        SELECT a.admitted_at, a.discharged_at, a.admission_type,
               p.name AS patient_name, p.mrn,
               ab.invoice_number, ab.grand_total, ab.paid_amount, ab.write_off_amount, ab.status AS bill_status
        FROM admissions a
        JOIN visits v ON v.id = a.visit_id
        JOIN patients p ON p.id = v.patient_id
        LEFT JOIN admission_bills ab ON ab.admission_id = a.id AND ab.voided_at IS NULL
        WHERE COALESCE(a.admitting_doctor_id, v.doctor_id) = ?
          AND a.status = 'DISCHARGED'
          AND DATE(a.discharged_at) BETWEEN ? AND ?
        ORDER BY a.discharged_at DESC
    ");
    $disQ->execute([$doctorId, $admStart, $admEnd]);
    $dischargedAdms = $disQ->fetchAll();
}

// ---------------------------------------------------------------------------
// Shared header data (sidebar queue badge = today's waiting count)
// ---------------------------------------------------------------------------
$wq = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE doctor_id = ? AND visit_date = CURDATE() AND consult_status = 'WAITING'");
$wq->execute([(int) $user['id']]);
$waitingCount = (int) $wq->fetchColumn();

function fmt_amt($n): string { return number_format((float) $n); }
function elapsed_label(int $mins): string {
    $h = intdiv($mins, 60);
    $m = $mins % 60;
    return $h > 0 ? "{$h}h {$m}m" : "{$m}m";
}

$ADM_STATUS_PILL = ['STABLE' => 'green', 'CRITICAL' => 'red', 'ACTIVE' => 'teal'];
$ADM_TYPE_LABEL = ['ROUTINE' => 'ER Routine', 'PRIVATE' => 'ER Private', 'LONG_PRIVATE' => 'Long Private'];

$pageTitle = 'My Reports';
$headExtra = <<<CSS
<style>
.tnum { font-variant-numeric: tabular-nums; }

/* Pill tones this page uses (app.css only ships active/pending/on-leave). */
.status-pill.green { background: var(--green-bg); color: var(--green-text); }
.status-pill.amber { background: var(--amber-bg); color: var(--amber-text); }
.status-pill.red   { background: var(--red-bg);   color: var(--red-text); }
.status-pill.teal  { background: var(--primary-light); color: var(--primary-dark); }
.status-pill.grey  { background: var(--bg); color: var(--text-muted); border: 1px solid var(--border); }

/* Header (same look as doctor.php) */
.header { height: 72px; position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; background: rgba(255,255,255,.82); backdrop-filter: blur(18px); border-bottom: 1px solid var(--border); }
.header-title { font-size: 16px; font-weight: 700; }
.header-right { display: flex; align-items: center; gap: 16px; }
.header-date { font-size: 13px; color: var(--text-secondary); white-space: nowrap; }
.avatar { width: 38px; height: 38px; border-radius: 50%; background: linear-gradient(135deg, var(--primary-dark), var(--primary)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 13px; }
.logout-link { font-size: 13px; color: var(--text-secondary); font-weight: 500; }

/* View tabs */
.view-tabs { display: flex; gap: 8px; flex-wrap: wrap; }
.view-tab { padding: 8px 16px; border-radius: 12px; font-size: 13px; font-weight: 600; color: var(--text-secondary); border: 1px solid var(--border); background: var(--card); }
.view-tab.active { background: var(--primary-light); border-color: transparent; color: var(--primary-dark); }

/* Controls */
.ctrl-bar { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.seg { display: inline-flex; border: 1px solid var(--border); border-radius: 12px; overflow: hidden; background: var(--card); }
.seg a { padding: 8px 14px; font-size: 12.5px; font-weight: 600; color: var(--text-muted); }
.seg a.on { background: var(--primary-light); color: var(--primary-dark); }
.datepick { display: inline-flex; align-items: center; gap: 6px; }
.datepick .arrow { width: 30px; height: 30px; border-radius: 9px; border: 1px solid var(--border); background: var(--card); color: var(--text-secondary); font-size: 14px; display: inline-flex; align-items: center; justify-content: center; }
.datepick .cur { font-size: 13px; font-weight: 600; padding: 6px 12px; border: 1px solid var(--border); border-radius: 10px; background: var(--card); }
.chart-legend { display: flex; gap: 16px; flex-wrap: wrap; font-size: 12px; color: var(--text-secondary); margin-left: auto; }
.chart-legend span { display: inline-flex; align-items: center; gap: 6px; }
.dotk { width: 10px; height: 10px; border-radius: 3px; display: inline-block; flex-shrink: 0; }

/* Chart */
.chart-wrap { overflow-x: auto; }
.chart-wrap svg { display: block; min-width: 640px; width: 100%; height: auto; }
.axis-lab { font-size: 10px; fill: var(--text-muted); font-family: inherit; }
.bar-g rect { transition: opacity .12s ease; }
.bar-g:hover rect { opacity: .85; }

/* Summary cards */
.sum-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; }
.sum { background: var(--bg); border: 1px solid var(--border); border-radius: 14px; padding: 14px 16px; }
.sum .lab { font-size: 11.5px; color: var(--text-muted); display: flex; align-items: center; gap: 6px; }
.sum .val { font-size: 18px; font-weight: 700; margin-top: 4px; }
.sum .cnt { font-size: 11.5px; color: var(--text-muted); }
.rev-total { display: flex; justify-content: space-between; border-top: 1px solid var(--border); margin-top: 16px; padding-top: 14px; font-weight: 700; font-size: 14px; }

/* Filters + table */
.filters { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
.f-field { display: flex; flex-direction: column; gap: 4px; }
.f-field label { font-size: 11px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--text-muted); }
.f-field input, .f-field select { border: 1px solid var(--border); border-radius: var(--radius-input); background: var(--card); padding: 8px 12px; font-size: 13px; font-family: inherit; color: var(--text); }
.tbl-wrap { overflow-x: auto; }
table.rep { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 680px; }
.rep th { text-align: left; font-size: 11.5px; font-weight: 600; letter-spacing: .03em; text-transform: uppercase; color: var(--text-muted); padding: 10px 12px; border-bottom: 1px solid var(--border-strong); white-space: nowrap; }
.rep td { padding: 11px 12px; border-bottom: 1px solid var(--border); white-space: nowrap; }
.rep tr:nth-child(even) td { background: var(--bg); }
.rep .plink { color: var(--primary); font-weight: 600; }
.rep th.r, .rep td.r { text-align: right; }
.rep td.amt { font-weight: 600; text-align: right; }
.mrn { font-family: ui-monospace, 'Courier New', monospace; font-size: 11.5px; color: var(--text-secondary); }

/* Revisit tiers */
.tier { border: 1px solid var(--border); border-radius: 16px; overflow: hidden; background: var(--card); }
.tier-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 13px 18px; background: var(--bg); font-weight: 600; font-size: 13.5px; flex-wrap: wrap; }
.tier-body { padding: 6px 18px 12px; }
.tl { display: grid; grid-template-columns: 1fr auto; gap: 10px; align-items: center; padding: 10px 0; border-top: 1px solid var(--border); font-size: 13px; }
.tl:first-child { border-top: none; }
.tl .path { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.tl .path b { color: var(--text-secondary); font-weight: 600; }
.arrowc { color: var(--primary); }
.badge-count { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 3px 12px; font-weight: 700; font-size: 13px; }

/* Admission cards */
.adm { border-left: 3px solid var(--primary); border-radius: 14px; background: var(--card); border-top: 1px solid var(--border); border-right: 1px solid var(--border); border-bottom: 1px solid var(--border); padding: 16px 18px; box-shadow: var(--shadow-sm); }
.adm.stable { border-left-color: var(--green); }
.adm.critical { border-left-color: var(--red); }
.adm-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.adm-name { font-size: 14px; font-weight: 700; }
.adm-meta { font-size: 12px; color: var(--text-muted); margin-top: 3px; }
.adm-foot { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; border-top: 1px solid var(--border); margin-top: 12px; padding-top: 10px; font-size: 12px; color: var(--text-muted); }
.adm-foot b { color: var(--text-secondary); font-weight: 600; }

.empty-state { padding: 32px 10px; text-align: center; color: var(--text-muted); font-size: 13px; }
.doc-picker select { border: 1px solid var(--border); border-radius: 10px; padding: 7px 10px; font-size: 13px; font-family: inherit; background: var(--card); color: var(--text); }

/* Revisit summary strip on the Consultations & Revisits view */
.rv-strip { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; background: var(--bg); border: 1px solid var(--border); border-radius: 12px; padding: 12px 18px; margin-bottom: 18px; }
.rv-stat { display: flex; align-items: center; gap: 12px; padding-right: 20px; border-right: 1px solid var(--border); }
.rv-stat .rv-num { font-size: 24px; font-weight: 700; color: var(--primary-dark); }
.rv-stat .rv-lab { font-size: 12.5px; font-weight: 600; color: var(--text-secondary); }
.rv-stat .rv-sub { font-size: 11px; color: var(--text-muted); font-weight: 500; margin-top: 1px; }
.rv-tiers { display: flex; gap: 22px; flex-wrap: wrap; }
.rv-tier { display: flex; align-items: center; gap: 7px; font-size: 12.5px; color: var(--text-secondary); }
.rv-tier b { font-size: 15px; color: var(--text); }
@media (max-width: 620px) { .rv-stat { border-right: none; padding-right: 0; } }

/* Procedures placeholder */
.proc-placeholder { text-align: center; padding: 44px 20px; }
.proc-icon { width: 56px; height: 56px; margin: 0 auto 14px; border-radius: 16px; background: var(--primary-light); color: var(--primary); display: flex; align-items: center; justify-content: center; }
.proc-icon svg { width: 26px; height: 26px; }
.proc-placeholder .section-sub { max-width: 46ch; margin-left: auto; margin-right: auto; }

@media (max-width: 900px) { .sum-grid { grid-template-columns: 1fr; } }
</style>
CSS;
require __DIR__ . '/partials/head.php';
?>
<div class="app">

    <?php
    $dsActive = 'analytics';
    $dsUserName = $user['name'];
    $dsWaitingCount = $waitingCount;
    require __DIR__ . '/partials/doctor_sidebar.php';
    ?>

    <div class="main">
        <header class="header">
            <div class="header-title">My Reports<?= $isAdmin ? ' — ' . htmlspecialchars($doctorName) : '' ?></div>
            <div class="header-right">
                <?php if ($isAdmin && $doctorList): ?>
                <form class="doc-picker" method="GET" action="doctor_analytics.php">
                    <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
                    <select name="doctor_id" onchange="this.form.submit()" aria-label="Choose doctor">
                        <?php foreach ($doctorList as $d): ?>
                        <option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === $doctorId ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php endif; ?>
                <span class="header-date tnum"><?= date('D, d/m/Y') ?></span>
                <a class="avatar" href="profile.php" title="My Profile" style="text-decoration:none;"><?= htmlspecialchars(strtoupper(substr($user['name'], 0, 1))) ?></a>
                <a class="logout-link" href="logout.php">Logout</a>
            </div>
        </header>

        <div class="content">

            <div class="view-tabs">
                <a class="view-tab <?= $view === 'revenue' ? 'active' : '' ?>" href="<?= qs_view('revenue') ?>">Revenue</a>
                <a class="view-tab <?= $view === 'patients' ? 'active' : '' ?>" href="<?= qs_view('patients') ?>">Consultations &amp; Revisits</a>
                <a class="view-tab <?= $view === 'procedures' ? 'active' : '' ?>" href="<?= qs_view('procedures') ?>">Procedures</a>
            </div>

<?php if ($view === 'revenue'): ?>
            <div class="card">
                <div class="ctrl-bar" style="margin-bottom:18px">
                    <div class="seg" role="group" aria-label="Granularity">
                        <a class="<?= $gran === 'month' ? 'on' : '' ?>" href="<?= qs_view('revenue', ['gran' => 'month']) ?>">Month</a>
                        <a class="<?= $gran === 'year' ? 'on' : '' ?>" href="<?= qs_view('revenue', ['gran' => 'year']) ?>">Year</a>
                    </div>
                    <?php if ($navPrev !== null): ?>
                    <div class="datepick">
                        <a class="arrow" aria-label="Previous period" href="<?= qs_view('revenue', ['gran' => $gran, 'period' => $navPrev, 'compare' => $compare ? '1' : '0']) ?>">&lsaquo;</a>
                        <span class="cur tnum"><?= htmlspecialchars($periodLabel) ?></span>
                        <a class="arrow" aria-label="Next period" href="<?= qs_view('revenue', ['gran' => $gran, 'period' => $navNext, 'compare' => $compare ? '1' : '0']) ?>">&rsaquo;</a>
                    </div>
                    <a class="btn secondary small" href="<?= qs_view('revenue', ['gran' => $gran, 'period' => $period, 'compare' => $compare ? '0' : '1']) ?>">
                        Compare vs <?= htmlspecialchars($prevLabel) ?>: <b><?= $compare ? 'ON' : 'OFF' ?></b>
                    </a>
                    <?php else: ?>
                    <span class="cur tnum" style="font-size:13px;font-weight:600"><?= htmlspecialchars($periodLabel) ?></span>
                    <?php endif; ?>
                    <div class="chart-legend">
                        <span><span class="dotk" style="background:var(--primary)"></span>Full</span>
                        <span><span class="dotk" style="background:#0891B2"></span>Revisits</span>
                        <?php if ($compare): ?><span style="color:var(--text-muted)"><span class="dotk" style="background:#A7D8D7"></span><?= htmlspecialchars($prevLabel) ?> (faded)</span><?php endif; ?>
                    </div>
                </div>

                <?php
                // ---- Inline SVG stacked bar chart ----
                $W = 760; $H = 280; $padL = 52; $padR = 10; $padT = 14; $padB = 34;
                $plotW = $W - $padL - $padR; $plotH = $H - $padT - $padB;

                $maxVal = 0;
                foreach ($bucketKeys as $i => $bk) {
                    $key = ($gran === 'year') ? $bk : ($i + 1);
                    $c = $curSeries[$key] ?? [];
                    $p = $prevSeries[$key] ?? [];
                    $maxVal = max($maxVal,
                        (float)($c['full'] ?? 0) + (float)($c['revisit'] ?? 0),
                        (float)($p['full'] ?? 0) + (float)($p['revisit'] ?? 0));
                }
                // Round the axis top to a friendly step.
                $step = $maxVal > 0 ? pow(10, floor(log10($maxVal))) : 1;
                $axisMax = $maxVal > 0 ? ceil($maxVal / $step) * $step : 100;
                if ($axisMax / $step <= 2) { $axisMax = ceil($maxVal / ($step / 2)) * ($step / 2); }

                $slotW = $plotW / max(1, count($bucketKeys));
                $barW = $compare ? max(4, min(16, ($slotW - 8) / 2)) : max(6, min(22, $slotW - 8));
                $yFor = fn(float $v) => $padT + $plotH - ($axisMax > 0 ? ($v / $axisMax) * $plotH : 0);

                $gridLines = 4;
                ?>
                <div class="chart-wrap">
                <svg viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="Stacked revenue by period: full consultations and revisits">
                    <g stroke="var(--border)" stroke-width="1">
                        <?php for ($g = 0; $g <= $gridLines; $g++):
                            $gy = $padT + $plotH * $g / $gridLines; ?>
                        <line x1="<?= $padL ?>" y1="<?= $gy ?>" x2="<?= $W - $padR ?>" y2="<?= $gy ?>" <?= $g === $gridLines ? 'stroke="var(--border-strong)"' : '' ?>/>
                        <?php endfor; ?>
                    </g>
                    <g class="axis-lab" text-anchor="end">
                        <?php for ($g = 0; $g <= $gridLines; $g++):
                            $gy = $padT + $plotH * $g / $gridLines;
                            $gv = $axisMax * (1 - $g / $gridLines); ?>
                        <text x="<?= $padL - 6 ?>" y="<?= $gy + 3 ?>"><?= $gv >= 1000 ? round($gv / 1000, 1) . 'k' : (int) $gv ?></text>
                        <?php endfor; ?>
                    </g>
                    <?php foreach ($bucketKeys as $i => $bk):
                        $key = ($gran === 'year') ? $bk : ($i + 1);
                        $slotX = $padL + $slotW * $i;
                        $c = $curSeries[$key] ?? [];
                        $cf = (float)($c['full'] ?? 0); $cr = (float)($c['revisit'] ?? 0);
                        $ctot = $cf + $cr;
                        $x = $compare ? $slotX + ($slotW - 2 * $barW - 3) / 2 : $slotX + ($slotW - $barW) / 2;
                        $lab = ($gran === 'month') ? date('M', mktime(0, 0, 0, $bk, 1)) : (string) $bk;
                        $tip = htmlspecialchars("$lab — Full: " . fmt_amt($cf) . " · Revisits: " . fmt_amt($cr) . " · Total: " . fmt_amt($ctot) . " PKR");
                    ?>
                    <g class="bar-g">
                        <title><?= $tip ?></title>
                        <?php if ($ctot > 0):
                            $y0 = $yFor(0); $y1 = $yFor($cf); $y2 = $yFor($ctot); ?>
                        <?php if ($cf > 0): ?><rect x="<?= round($x,1) ?>" y="<?= round($y1,1) ?>" width="<?= round($barW,1) ?>" height="<?= round($y0 - $y1,1) ?>" fill="var(--primary)" rx="1.5"/><?php endif; ?>
                        <?php if ($cr > 0): ?><rect x="<?= round($x,1) ?>" y="<?= round($y2,1) ?>" width="<?= round($barW,1) ?>" height="<?= round($y1 - $y2,1) ?>" fill="#0891B2" rx="1.5"/><?php endif; ?>
                        <?php endif; ?>
                        <?php if ($compare):
                            $p = $prevSeries[$key] ?? [];
                            $pf = (float)($p['full'] ?? 0); $pr = (float)($p['revisit'] ?? 0);
                            $ptot = $pf + $pr;
                            $px = $x + $barW + 3;
                            if ($ptot > 0):
                                $y0 = $yFor(0); $y1 = $yFor($pf); $y2 = $yFor($ptot); ?>
                        <?php if ($pf > 0): ?><rect x="<?= round($px,1) ?>" y="<?= round($y1,1) ?>" width="<?= round($barW,1) ?>" height="<?= round($y0 - $y1,1) ?>" fill="#A7D8D7" rx="1.5"/><?php endif; ?>
                        <?php if ($pr > 0): ?><rect x="<?= round($px,1) ?>" y="<?= round($y2,1) ?>" width="<?= round($barW,1) ?>" height="<?= round($y1 - $y2,1) ?>" fill="#A5E5F0" rx="1.5"/><?php endif; ?>
                        <?php endif; endif; ?>
                        <text class="axis-lab" text-anchor="middle" x="<?= round($slotX + $slotW / 2, 1) ?>" y="<?= $H - $padB + 16 ?>"><?= htmlspecialchars($lab) ?></text>
                    </g>
                    <?php endforeach; ?>
                </svg>
                </div>

                <div class="sum-grid" style="margin-top:20px">
                    <div class="sum">
                        <div class="lab"><span class="dotk" style="background:var(--primary)"></span>Full consultations</div>
                        <div class="val tnum"><?= fmt_amt($tot['full']) ?> PKR</div>
                        <div class="cnt tnum"><?= $tot['full_n'] ?> paid consultation<?= $tot['full_n'] === 1 ? '' : 's' ?></div>
                    </div>
                    <div class="sum">
                        <div class="lab"><span class="dotk" style="background:#0891B2"></span>Revisits</div>
                        <div class="val tnum"><?= fmt_amt($tot['revisit']) ?> PKR</div>
                        <div class="cnt tnum"><?= $tot['revisit_n'] ?> revisit<?= $tot['revisit_n'] === 1 ? '' : 's' ?>
                            (<?= (int) ($revisitMix['FREE_FOLLOWUP'] ?? 0) ?> free · <?= (int) ($revisitMix['HALF_FOLLOWUP'] ?? 0) ?> half · <?= (int) ($revisitMix['THREE_QUARTER_FOLLOWUP'] ?? 0) ?> at 75%)</div>
                    </div>
                </div>
                <div class="rev-total">
                    <span>Total earnings — <?= htmlspecialchars($periodLabel) ?></span>
                    <span class="tnum"><?= fmt_amt($totAll) ?> PKR<?php if ($compare && $prevAll > 0):
                        $delta = ($totAll - $prevAll) / $prevAll * 100; ?>
                        &nbsp;(<?= htmlspecialchars($prevLabel) ?>: <?= fmt_amt($prevAll) ?> · <?= $delta >= 0 ? '+' : '' ?><?= number_format($delta, 1) ?>%)
                    <?php endif; ?></span>
                </div>
                <div class="section-sub" style="margin-top:12px;margin-bottom:0">
                    Your earned share of paid consultations — (fee paid, less any withheld tax) × your revenue-share %. The clinic keeps the remainder; ER admission bills are clinic revenue and aren't counted here.
                </div>
            </div>

<?php elseif ($view === 'patients'): ?>
            <div class="card">
                <form class="filters" method="GET" action="doctor_analytics.php" style="margin-bottom:18px">
                    <input type="hidden" name="view" value="patients">
                    <?php if ($isAdmin): ?><input type="hidden" name="doctor_id" value="<?= $doctorId ?>"><?php endif; ?>
                    <div class="f-field"><label for="f-from">From</label><input id="f-from" type="date" name="from" value="<?= htmlspecialchars($from) ?>"></div>
                    <div class="f-field"><label for="f-to">To</label><input id="f-to" type="date" name="to" value="<?= htmlspecialchars($to) ?>"></div>
                    <div class="f-field"><label for="f-ft">Fee type</label>
                        <select id="f-ft" name="fee_type">
                            <option value="">All</option>
                            <?php foreach ($FEE_TYPE_LABELS as $k => $lab): ?>
                            <option value="<?= $k ?>" <?= $feeType === $k ? 'selected' : '' ?>><?= $lab ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="f-field"><label for="f-pay">Payment</label>
                        <select id="f-pay" name="pay">
                            <option value="">All</option>
                            <option value="paid" <?= $payFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
                            <option value="partial" <?= $payFilter === 'partial' ? 'selected' : '' ?>>Partial</option>
                            <option value="unpaid" <?= $payFilter === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                        </select>
                    </div>
                    <button class="btn small" type="submit">Apply</button>
                    <a class="btn secondary small" href="<?= qs_view('patients') ?>">Reset</a>
                    <a class="btn secondary small" href="<?= qs_view('patients', ['from' => $from, 'to' => $to, 'fee_type' => $feeType, 'pay' => $payFilter, 'export' => 'csv']) ?>">Export CSV</a>
                </form>

                <!-- Revisit summary strip (the old Revisits tab, folded in). Scoped to the
                     same range as the table below; when a Fee-type filter is on, it reflects
                     that narrowed set. -->
                <div class="rv-strip">
                    <div class="rv-stat">
                        <div class="rv-num tnum"><?= number_format($rvRate, 1) ?>%</div>
                        <div class="rv-lab">Revisit rate<div class="rv-sub tnum"><?= $rvCount ?> of <?= $rvTotal ?> consultations</div></div>
                    </div>
                    <div class="rv-tiers">
                        <div class="rv-tier"><span class="dotk" style="background:var(--green)"></span>Free<b class="tnum"><?= $rvTierCounts['FREE_FOLLOWUP'] ?></b></div>
                        <div class="rv-tier"><span class="dotk" style="background:#0891B2"></span>50%<b class="tnum"><?= $rvTierCounts['HALF_FOLLOWUP'] ?></b></div>
                        <div class="rv-tier"><span class="dotk" style="background:#D97706"></span>75%<b class="tnum"><?= $rvTierCounts['THREE_QUARTER_FOLLOWUP'] ?></b></div>
                        <div class="rv-tier"><span class="dotk" style="background:var(--primary)"></span>Full<b class="tnum"><?= $rvFullCount ?></b></div>
                    </div>
                </div>

                <div class="tbl-wrap">
                <table class="rep">
                    <thead><tr><th>Date</th><th>Patient</th><th>Consult type</th><th>Fee type</th><th class="r">Billed</th><th>Payment</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$patientRows): ?>
                        <tr><td colspan="7"><div class="empty-state">No consultations in this range.</div></td></tr>
                    <?php else: foreach ($patientRows as $r):
                        [$payLabel, $payTone] = pay_badge($r['bill_status'], $r['paid_amount'], $r['grand_total']);
                        $ftLabel = $FEE_TYPE_LABELS[$r['consultation_fee_type']] ?? $r['consultation_fee_type'];
                        $ftTone = $r['consultation_fee_type'] === 'FULL' ? 'teal' : ($r['consultation_fee_type'] === 'FREE_FOLLOWUP' ? 'green' : 'amber');
                        $billed = $r['grand_total'] !== null ? $r['grand_total'] : $r['fee'];
                    ?>
                        <tr>
                            <td class="tnum"><?= date('d/m', strtotime($r['visit_date'])) ?>, <?= date('g:i A', strtotime($r['created_at'])) ?></td>
                            <td><a class="plink" href="patients.php?q=<?= urlencode($r['mrn']) ?>"><?= htmlspecialchars($r['patient_name']) ?></a> <span class="mrn"><?= htmlspecialchars($r['mrn']) ?></span></td>
                            <td><?= htmlspecialchars($r['type_label'] ?? '—') ?></td>
                            <td><span class="status-pill <?= $ftTone ?>"><?= $ftLabel ?></span><?php if ($r['fee_overridden']): ?> <span class="status-pill grey">Fee overridden</span><?php endif; ?></td>
                            <td class="amt tnum"><?= fmt_amt($billed) ?></td>
                            <td><span class="status-pill <?= $payTone ?>"><?= $payLabel ?></span></td>
                            <td><a class="plink" href="patients.php?q=<?= urlencode($r['mrn']) ?>">View</a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
                </div>
                <div class="section-sub" style="margin-top:12px;margin-bottom:0">
                    "Billed" is the invoice total after revisit and category discounts. "Fee overridden" marks a manual price change at registration.
                </div>
            </div>

<?php else: /* procedures */ ?>
            <div class="card">
                <div class="proc-placeholder">
                    <div class="proc-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 2v20l3-2 3 2 3-2 3 2 3-2V2l-3 2-3-2-3 2-3-2Z"/><path d="M8 7h8M8 11h8M8 15h5"/></svg>
                    </div>
                    <div class="section-title" style="font-size:17px">Procedures — coming soon</div>
                    <div class="section-sub" style="margin:6px 0 0">
                        One-time procedure billing (e.g. ear piercing, minor OPD procedures) isn't
                        built yet. When it lands, your performed procedures and their billed amounts
                        will show here alongside consultations.
                    </div>
                </div>
            </div>
<?php endif; ?>

        </div>
    </div>
</div>
<script src="assets/js/date-picker.js"></script>
</body>
</html>
