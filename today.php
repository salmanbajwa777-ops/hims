<?php
/**
 * Today — one live screen of everything happening in the clinic on the
 * current business day: registrations, consultations, ER direct, procedures,
 * dental, admissions, discharges, discounts, refunds and write-offs.
 *
 * Admin + Manager (OVERSIGHT_VIEW_TODAY, see sql/add_today_screen.sql).
 *
 * Sources, each read as it already exists (no new write sites):
 *   - shift_events      invoices, discounts, refunds, discharges, write-offs
 *   - patients          registrations (created_at)
 *   - ipd_admissions    In-Door admissions (admitted_at)
 *   - admissions        the older "A"-series admissions (admitted_at)
 * A source that fails to read is reported on the page instead of silently
 * dropping its rows, so a missing table never looks like a quiet day.
 *
 * `today.php?feed=1` returns the same payload as JSON; the page polls it.
 * Money shown is what was BILLED (voided rows excluded). Cash/online collected
 * stays on Day Closing and Cash Handovers, which tally actual payments.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);
require_once __DIR__ . '/config/billing.php';
require_permission('OVERSIGHT_VIEW_TODAY');

function today_payload(PDO $pdo): array {
    $date = business_day($pdo);
    [$start, $end] = business_day_window($pdo, $date);
    $events = [];
    $problems = [];

    // Run one source; on failure note it and carry on with the others.
    $source = function (string $label, callable $fn) use (&$problems) {
        try { return $fn(); } catch (Throwable $e) { $problems[] = $label; return []; }
    };

    foreach ($source('registrations', function () use ($pdo, $start, $end) {
        $q = $pdo->prepare('
            SELECT p.id, p.name, p.mrn, p.created_at, u.name AS staff
            FROM patients p LEFT JOIN users u ON u.id = p.created_by_id
            WHERE p.created_at >= ? AND p.created_at < ?');
        $q->execute([$start, $end]);
        return $q->fetchAll();
    }) as $r) {
        $events[] = ['t' => $r['created_at'], 'kind' => 'reg', 'name' => $r['name'], 'mrn' => $r['mrn'],
                     'detail' => 'New patient' . ($r['staff'] ? ' · by ' . $r['staff'] : ''),
                     'amount' => null, 'status' => '', 'ref' => 'patient_history.php?patient_id=' . (int) $r['id']];
    }

    $label = ['INVOICE' => ['OPD' => 'con', 'ER' => 'er', 'IPD' => 'ipdbill', 'PROCEDURE' => 'proc', 'DENTAL' => 'den']];
    foreach ($source('invoices, discounts and refunds', function () use ($pdo, $date) {
        $q = $pdo->prepare('
            SELECT se.id, se.event_type, se.stream, se.occurred_at, se.amount, se.invoice_number, se.status, se.note,
                   p.id AS pid, p.name, p.mrn, du.name AS doctor, su.name AS staff
            FROM shift_events se
            JOIN patients p ON p.id = se.patient_id
            LEFT JOIN users du ON du.id = se.doctor_id
            LEFT JOIN users su ON su.id = se.actor_id
            WHERE se.business_date = ?');
        $q->execute([$date]);
        return $q->fetchAll();
    }) as $r) {
        $kind = match ($r['event_type']) {
            'INVOICE'   => $label['INVOICE'][$r['stream']] ?? 'con',
            'DISCOUNT'  => 'disc',
            'REFUND'    => 'ref',
            'DISCHARGE' => 'dis',
            default     => 'wo',
        };
        $bits = [];
        if ($r['invoice_number']) { $bits[] = $r['invoice_number']; }
        if ($r['doctor'])         { $bits[] = $r['doctor']; }
        if ($r['staff'])          { $bits[] = 'by ' . $r['staff']; }
        if ($r['note'])           { $bits[] = $r['note']; }
        $events[] = ['t' => $r['occurred_at'], 'kind' => $kind, 'name' => $r['name'], 'mrn' => $r['mrn'],
                     'detail' => implode(' · ', $bits), 'amount' => (float) $r['amount'],
                     'status' => $r['status'], 'ref' => 'patient_history.php?patient_id=' . (int) $r['pid']];
    }

    foreach ($source('In-Door admissions', function () use ($pdo, $start, $end) {
        $q = $pdo->prepare('
            SELECT a.admitted_at, a.room_no, p.id AS pid, p.name, p.mrn, du.name AS doctor, bu.name AS staff
            FROM ipd_admissions a
            JOIN visits v ON v.id = a.visit_id
            JOIN patients p ON p.id = v.patient_id
            LEFT JOIN users du ON du.id = a.admitting_consultant_id
            LEFT JOIN users bu ON bu.id = a.admitted_by_id
            WHERE a.admitted_at >= ? AND a.admitted_at < ?');
        $q->execute([$start, $end]);
        return $q->fetchAll();
    }) as $r) {
        $bits = ['Room ' . (int) $r['room_no']];
        if ($r['doctor']) { $bits[] = $r['doctor']; }
        if ($r['staff'])  { $bits[] = 'by ' . $r['staff']; }
        $events[] = ['t' => $r['admitted_at'], 'kind' => 'adm', 'name' => $r['name'], 'mrn' => $r['mrn'],
                     'detail' => implode(' · ', $bits), 'amount' => null, 'status' => '',
                     'ref' => 'patient_history.php?patient_id=' . (int) $r['pid']];
    }

    foreach ($source('admissions', function () use ($pdo, $start, $end) {
        $q = $pdo->prepare('
            SELECT a.admitted_at, a.admission_type, p.id AS pid, p.name, p.mrn, du.name AS doctor, bu.name AS staff
            FROM admissions a
            JOIN visits v ON v.id = a.visit_id
            JOIN patients p ON p.id = v.patient_id
            LEFT JOIN users du ON du.id = a.admitting_doctor_id
            LEFT JOIN users bu ON bu.id = a.admitted_by_id
            WHERE a.admitted_at >= ? AND a.admitted_at < ?');
        $q->execute([$start, $end]);
        return $q->fetchAll();
    }) as $r) {
        $bits = [ucfirst(strtolower(str_replace('_', ' ', $r['admission_type'])))];
        if ($r['doctor']) { $bits[] = $r['doctor']; }
        if ($r['staff'])  { $bits[] = 'by ' . $r['staff']; }
        $events[] = ['t' => $r['admitted_at'], 'kind' => 'adm', 'name' => $r['name'], 'mrn' => $r['mrn'],
                     'detail' => implode(' · ', $bits), 'amount' => null, 'status' => '',
                     'ref' => 'patient_history.php?patient_id=' . (int) $r['pid']];
    }

    usort($events, fn($a, $b) => strcmp($b['t'], $a['t']));

    // Counts and billed total. Voided rows stay in the feed (struck through)
    // but never count toward a number.
    $k = array_fill_keys(['reg', 'con', 'er', 'ipdbill', 'proc', 'den', 'adm', 'dis', 'disc', 'ref', 'wo'], 0);
    $billed = 0.0; $discounted = 0.0; $refunded = 0.0;
    foreach ($events as $e) {
        if ($e['status'] === 'voided') { continue; }
        $k[$e['kind']]++;
        if (in_array($e['kind'], ['con', 'er', 'ipdbill', 'proc', 'den'], true)) { $billed += $e['amount']; }
        if ($e['kind'] === 'disc') { $discounted += -$e['amount']; }
        if ($e['kind'] === 'ref')  { $refunded += -$e['amount']; }
    }

    // Right now.
    $scalar = function (string $label, string $sql) use ($pdo, &$problems) {
        try { return (int) $pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { $problems[] = $label; return null; }
    };
    $inHouse = null;
    $a = $scalar('in-house count', "SELECT COUNT(*) FROM ipd_admissions WHERE status <> 'DISCHARGED'");
    $b = $scalar('in-house count', "SELECT COUNT(*) FROM admissions WHERE status <> 'DISCHARGED'");
    if ($a !== null || $b !== null) { $inHouse = (int) $a + (int) $b; }
    $billApprovals = $scalar('bill approvals', "SELECT COUNT(*) FROM admission_bill_approvals WHERE status = 'PENDING'");
    $procDiscounts = $scalar('procedure discounts', "SELECT COUNT(*) FROM procedure_bills WHERE discount_approval = 'PENDING'");

    return [
        'date' => $date, 'now' => date('H:i:s'), 'events' => $events, 'counts' => $k,
        'billed' => $billed, 'discounted' => $discounted, 'refunded' => $refunded,
        'inHouse' => $inHouse, 'billApprovals' => $billApprovals, 'procDiscounts' => $procDiscounts,
        'problems' => array_values(array_unique($problems)),
    ];
}

$payload = today_payload($pdo);

if (isset($_GET['feed'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

$pageTitle = 'Today';
$headExtra = <<<CSS
<style>
.td-head { display:flex; flex-wrap:wrap; gap:12px; justify-content:space-between; align-items:flex-end; }
.td-live { display:inline-flex; gap:7px; align-items:center; font-size:12.5px; font-weight:700; padding:6px 12px; border-radius:99px; background:var(--success-bg, #D3EBDB); color:var(--success, #1E6B3A); }
.td-live i { width:7px; height:7px; border-radius:50%; background:currentColor; }
.td-live.stale { background:var(--danger-bg); color:var(--danger); }
.td-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(118px,1fr)); gap:10px; }
.td-kpi { border:1px solid var(--border); border-radius:12px; padding:11px 13px; background:var(--card); min-width:0; }
.td-kpi .k { font-size:10.5px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--text-muted); }
.td-kpi .v { font-size:22px; font-weight:800; font-variant-numeric:tabular-nums; margin-top:2px; }
.td-kpi .d { font-size:11.5px; color:var(--text-muted); }
.td-kpi.neg .v { color:var(--danger); }
.td-cols { display:grid; grid-template-columns:minmax(0,1fr) 290px; gap:16px; align-items:start; }
.td-panel { border:1px solid var(--border); border-radius:14px; background:var(--card); min-width:0; }
.td-ph { padding:12px 14px; border-bottom:1px solid var(--border); display:flex; flex-wrap:wrap; gap:10px; justify-content:space-between; align-items:center; }
.td-chips { display:flex; flex-wrap:wrap; gap:6px; }
.td-chip { border:1px solid var(--border); background:transparent; color:var(--text); border-radius:99px; padding:4px 11px; font:inherit; font-size:12px; font-weight:600; cursor:pointer; }
.td-chip[aria-pressed="true"] { background:var(--primary); color:#fff; border-color:var(--primary); }
.td-feed { list-style:none; margin:0; padding:0; }
.td-ev { display:grid; grid-template-columns:52px 112px minmax(0,1fr) auto; gap:12px; align-items:center; padding:10px 14px; border-bottom:1px solid var(--border); }
.td-ev:last-child { border-bottom:0; }
.td-ev time { color:var(--text-muted); font-size:12px; font-variant-numeric:tabular-nums; }
.td-tag { font-size:11px; font-weight:700; padding:3px 9px; border-radius:7px; text-align:center; white-space:nowrap; }
.td-k-reg,.td-k-con { background:var(--primary-light); color:var(--primary-dark); }
.td-k-er,.td-k-ref,.td-k-wo { background:var(--danger-bg); color:var(--danger); }
.td-k-adm,.td-k-dis,.td-k-ipdbill { background:#D6E5FB; color:#0A47C2; }
.td-k-proc { background:#FAE3BC; color:#933B00; }
.td-k-den { background:#E8DCF6; color:#6B3A9E; }
.td-k-disc { background:#F6E4B8; color:#8A5A00; }
.td-who { min-width:0; }
.td-who a { color:inherit; text-decoration:none; font-weight:700; display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.td-who a:hover { text-decoration:underline; }
.td-who span { display:block; color:var(--text-muted); font-size:12px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.td-amt { text-align:right; font-weight:700; font-variant-numeric:tabular-nums; white-space:nowrap; }
.td-amt small { display:block; font-weight:500; font-size:11px; color:var(--text-muted); }
.td-amt.neg { color:var(--danger); }
.td-ev.voided .td-who, .td-ev.voided .td-amt { text-decoration:line-through; opacity:.6; }
.td-empty { padding:28px 14px; text-align:center; color:var(--text-muted); }
.td-rail { display:flex; flex-direction:column; gap:16px; min-width:0; }
.td-rl { list-style:none; margin:0; padding:6px 14px 12px; }
.td-rl li { display:flex; justify-content:space-between; gap:10px; padding:7px 0; border-bottom:1px dashed var(--border); }
.td-rl li:last-child { border:0; }
.td-rl a { color:inherit; }
.td-rl b { font-variant-numeric:tabular-nums; }
.td-warn { margin:0; padding:10px 14px; border-radius:12px; background:var(--danger-bg); color:var(--danger); font-size:12.5px; font-weight:600; }
.td-note { font-size:12px; color:var(--text-muted); }
@media (max-width:1000px){ .td-cols { grid-template-columns:minmax(0,1fr); } }
@media (max-width:760px){
  .td-ev { grid-template-columns:44px minmax(0,1fr) auto; row-gap:2px; }
  .td-ev .td-tag { grid-column:2; grid-row:1; justify-self:start; }
  .td-ev .td-who { grid-column:1/-1; grid-row:2; }
  .td-ev .td-amt { grid-column:3; grid-row:1; }
}
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'today';
require __DIR__ . '/partials/sidebar.php';
?>
<div class="content" style="display:flex;flex-direction:column;gap:16px;">
    <div class="td-head">
        <div>
            <div class="page-title">Today</div>
            <div class="page-sub" id="tdSub">Everything happening in the clinic, newest first</div>
        </div>
        <span class="td-live" id="tdLive"><i></i><span id="tdLiveTxt">Live</span></span>
    </div>

    <div id="tdProblems"></div>
    <section class="td-kpis" id="tdKpis" aria-label="Today in numbers"></section>

    <div class="td-cols">
        <section class="td-panel" aria-label="Activity">
            <div class="td-ph">
                <b>Activity</b>
                <div class="td-chips" id="tdChips" role="group" aria-label="Filter activity">
                    <button class="td-chip" type="button" data-f="all" aria-pressed="true">All</button>
                    <button class="td-chip" type="button" data-f="reg" aria-pressed="false">Registration</button>
                    <button class="td-chip" type="button" data-f="con" aria-pressed="false">Consultation</button>
                    <button class="td-chip" type="button" data-f="er" aria-pressed="false">ER direct</button>
                    <button class="td-chip" type="button" data-f="adm" aria-pressed="false">Admit / Discharge</button>
                    <button class="td-chip" type="button" data-f="proc" aria-pressed="false">Procedure / Dental</button>
                    <button class="td-chip" type="button" data-f="money" aria-pressed="false">Discount / Refund</button>
                </div>
            </div>
            <ul class="td-feed" id="tdFeed"></ul>
        </section>

        <div class="td-rail">
            <section class="td-panel">
                <div class="td-ph"><b>Right now</b></div>
                <ul class="td-rl" id="tdNow"></ul>
            </section>
            <section class="td-panel">
                <div class="td-ph"><b>Needs a decision</b></div>
                <ul class="td-rl" id="tdAttn"></ul>
            </section>
        </div>
    </div>
    <div class="td-note">Amounts are what was billed, voided invoices excluded. Cash and online collected is on Day Closing and Cash Handovers. The business day starts at the clinic's cutoff hour.</div>
</div>

<script>
(function () {
    var KIND = {
        reg: 'Registered', con: 'Consultation', er: 'ER direct', ipdbill: 'In-Door bill', proc: 'Procedure',
        den: 'Dental', adm: 'Admitted', dis: 'Discharged', disc: 'Discount', ref: 'Refund', wo: 'Written off'
    };
    var GROUP = { reg: 'reg', con: 'con', er: 'er', adm: 'adm', dis: 'adm', ipdbill: 'adm', proc: 'proc', den: 'proc', disc: 'money', ref: 'money', wo: 'money' };
    var STATUS = { paid: 'Paid', pending: 'Unpaid', partial: 'Part paid', voided: 'Voided' };
    var filter = 'all', data = null, lastOk = 0;

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function rs(n) { return 'Rs ' + Math.round(Math.abs(n)).toLocaleString('en-US'); }
    function el(id) { return document.getElementById(id); }

    function renderKpis(d) {
        var c = d.counts;
        var tiles = [
            ['Registered', c.reg, 'new patients', ''],
            ['Consultations', c.con, 'OPD invoices', ''],
            ['ER direct', c.er, 'walk-in bills', ''],
            ['Procedures', c.proc, c.den + ' dental', ''],
            ['Admitted', c.adm, d.inHouse == null ? '' : d.inHouse + ' in-house now', ''],
            ['Discharged', c.dis, '', ''],
            ['Billed', rs(d.billed), 'excl. voided', ''],
            ['Discounts', c.disc, rs(d.discounted) + ' given', 'neg'],
            ['Refunds', c.ref, rs(d.refunded) + ' paid out', 'neg']
        ];
        el('tdKpis').innerHTML = tiles.map(function (t) {
            return '<div class="td-kpi ' + t[3] + '"><div class="k">' + t[0] + '</div><div class="v">' + esc(t[1]) + '</div><div class="d">' + esc(t[2]) + '&nbsp;</div></div>';
        }).join('');
    }

    function renderFeed(d) {
        var rows = d.events.filter(function (e) { return filter === 'all' || GROUP[e.kind] === filter; });
        if (!rows.length) {
            el('tdFeed').innerHTML = '<li class="td-empty">Nothing here yet today.</li>';
            return;
        }
        el('tdFeed').innerHTML = rows.map(function (e) {
            var neg = e.amount != null && e.amount < 0;
            var amt = e.amount == null ? '' : (neg ? '−' : '') + rs(e.amount);
            var st = e.amount != null && STATUS[e.status] && e.status !== 'paid' ? '<small>' + STATUS[e.status] + '</small>' : '';
            return '<li class="td-ev' + (e.status === 'voided' ? ' voided' : '') + '"><time>' + esc(e.t.slice(11, 16)) + '</time>' +
                '<span class="td-tag td-k-' + e.kind + '">' + KIND[e.kind] + '</span>' +
                '<div class="td-who"><a href="' + esc(e.ref) + '">' + esc(e.name) + '</a><span>' + esc((e.mrn ? 'MRN ' + e.mrn + ' · ' : '') + e.detail) + '</span></div>' +
                '<div class="td-amt' + (neg ? ' neg' : '') + '">' + esc(amt) + st + '</div></li>';
        }).join('');
    }

    function renderRail(d) {
        var n = function (v) { return v == null ? 'n/a' : v; };
        el('tdNow').innerHTML = '<li><span>In-house now</span><b>' + n(d.inHouse) + '</b></li>' +
            '<li><span>Events today</span><b>' + d.events.length + '</b></li>';
        el('tdAttn').innerHTML =
            '<li><a href="admission_approvals.php">Admission bill approvals</a><b>' + n(d.billApprovals) + '</b></li>' +
            '<li><a href="procedure_discount_approvals.php">Procedure discounts to sign</a><b>' + n(d.procDiscounts) + '</b></li>';
    }

    function render(d) {
        data = d;
        var p = d.date.split('-');
        el('tdSub').textContent = 'Business day ' + p[2] + '/' + p[1] + '/' + p[0] + ' · everything happening in the clinic, newest first';
        el('tdProblems').innerHTML = d.problems.length
            ? '<p class="td-warn">Could not read: ' + esc(d.problems.join(', ')) + '. Numbers for these are missing, not zero.</p>' : '';
        renderKpis(d); renderFeed(d); renderRail(d);
    }

    function setLive(ok) {
        var b = el('tdLive');
        b.classList.toggle('stale', !ok);
        el('tdLiveTxt').textContent = ok ? 'Live · updated ' + data.now.slice(0, 5) : 'Connection lost, retrying';
    }

    function poll() {
        fetch('today.php?feed=1', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
            .then(function (d) { render(d); setLive(true); })
            .catch(function () { if (data) { setLive(false); } });
    }

    el('tdChips').addEventListener('click', function (ev) {
        var b = ev.target.closest('.td-chip'); if (!b) { return; }
        filter = b.dataset.f;
        [].forEach.call(this.children, function (c) { c.setAttribute('aria-pressed', c === b ? 'true' : 'false'); });
        renderFeed(data);
    });

    render(<?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_PARTIAL_OUTPUT_ON_ERROR) ?>);
    setLive(true);
    setInterval(function () { if (!document.hidden) { poll(); } }, 15000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); } });
})();
</script>
    </div>
</div>
</body>
</html>
