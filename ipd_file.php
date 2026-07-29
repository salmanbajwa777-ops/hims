<?php
/**
 * IPD patient file — the whole stay as one printable document.
 *
 * Reception picks which sheets to include; they print as a single A4 file with
 * a page break between each, so a discharged patient's paperwork is one click
 * and one PDF rather than four separate views.
 *
 * Sheets (all optional, chosen via ?sheets=):
 *   summary  — the doctor's discharge summary (final diagnosis + narrative)
 *   notes    — every daily-round note in order
 *   vitals   — the observation chart
 *   services — chargeable services logged during the stay
 *
 * Everything is read straight from the existing tables; this page stores
 * nothing and computes no money beyond the services total.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/brand.php';
refresh_session_permissions($pdo);
require_permission('IPD_VIEW_WARD');

$admissionId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT a.room_category, a.room_no, a.admitted_at, a.discharged_at, a.status,
           a.provisional_diagnosis,
           v.token_no, p.mrn, p.name AS patient_name, p.father_name, p.phone, p.dob, p.gender,
           COALESCE(du.name, a.admitting_consultant_manual) AS consultant_name,
           du.specialty AS consultant_specialty
    FROM ipd_admissions a
    JOIN visits v ON v.id = a.visit_id
    JOIN patients p ON p.id = v.patient_id
    LEFT JOIN users du ON du.id = a.admitting_consultant_id
    WHERE a.id = ?
");
$stmt->execute([$admissionId]);
$adm = $stmt->fetch();
if (!$adm) { http_response_code(404); exit('IPD admission not found.'); }

// Which sheets? Default to all four so a bare link still prints a full file.
$all = ['summary', 'notes', 'vitals', 'services'];
$want = isset($_GET['sheets']) ? array_intersect(explode(',', (string) $_GET['sheets']), $all) : $all;
if (!$want) { $want = $all; }
$want = array_values($want);
$has = fn(string $s) => in_array($s, $want, true);

// Doctors never see charges anywhere in this app.
$hideMoney = (($_SESSION['base_role'] ?? '') === 'DOCTOR');

$q = function (string $sql, array $args) use ($pdo) {
    // A missing table (migration not run) degrades to an empty sheet rather
    // than 500-ing the whole file.
    try { $s = $pdo->prepare($sql); $s->execute($args); return $s->fetchAll(); }
    catch (Throwable $e) { return []; }
};

$summary = [];
if ($has('summary')) {
    $rows = $q('SELECT s.*, u.name AS author_name FROM ipd_discharge_summaries s LEFT JOIN users u ON u.id = s.written_by_id WHERE s.admission_id = ? ORDER BY s.id DESC', [$admissionId]);
    $summary = $rows[0] ?? [];
}
$notes = $has('notes')
    ? $q('SELECT n.*, u.name AS author_name FROM ipd_doctor_visits n LEFT JOIN users u ON u.id = n.doctor_id WHERE n.admission_id = ? ORDER BY n.visited_at, n.id', [$admissionId])
    : [];
$vitals = $has('vitals')
    ? $q('SELECT v.*, u.name AS by_name FROM ipd_vitals v LEFT JOIN users u ON u.id = v.recorded_by_id WHERE v.admission_id = ? ORDER BY v.recorded_at, v.id', [$admissionId])
    : [];
$services = $has('services')
    ? $q('SELECT s.*, u.name AS by_name FROM ipd_services s LEFT JOIN users u ON u.id = s.logged_by_id WHERE s.admission_id = ? ORDER BY s.logged_at, s.id', [$admissionId])
    : [];

$svcTotal = 0.0;
foreach ($services as $s) { if ((int) $s['is_billable'] === 1) { $svcTotal += (float) $s['calculated_charge']; } }

$days = max(1, (int) (new DateTime(date('Y-m-d', strtotime($adm['admitted_at']))))
    ->diff(new DateTime(date('Y-m-d', strtotime($adm['discharged_at'] ?: 'now'))))->days + 1);

$pb = $pdo->prepare('SELECT name FROM users WHERE id = ?');
$pb->execute([(int) ($_SESSION['user_id'] ?? 0)]);
$printedBy = (string) ($pb->fetchColumn() ?: 'STAFF');

$b = brand($adm['consultant_specialty'] ?? null);

$progressLabels = ['IMPROVING' => 'Improving', 'STABLE' => 'Stable', 'SLOW' => 'Slow progress',
                   'DETERIORATING' => 'Deteriorating', 'CRITICAL' => 'Critical'];
$sheetTitles = ['summary' => 'Discharge Summary', 'notes' => 'Daily Round Notes',
                'vitals' => 'Vitals Chart', 'services' => 'Services Record'];

// The page header repeats on every sheet so a loose page is still identifiable.
$patientHeader = function (string $sheetName) use ($adm, $b, $days) { ?>
    <div class="hdr">
        <div class="brand"><?= htmlspecialchars($b['name']) ?></div>
        <div class="sheet"><?= htmlspecialchars($sheetName) ?></div>
    </div>
    <div class="meta">
        <div><span>Patient</span><b><?= htmlspecialchars(mb_strtoupper($adm['patient_name'])) ?></b></div>
        <div><span>MR No.</span><?= htmlspecialchars($adm['mrn']) ?></div>
        <div><span>Room</span><?= htmlspecialchars($adm['room_category']) ?> &middot; <?= (int) $adm['room_no'] ?></div>
        <div><span>Consultant</span><?= htmlspecialchars(mb_strtoupper($adm['consultant_name'] ?: '—')) ?></div>
        <div><span>Admitted</span><?= date('d/m/Y H:i', strtotime($adm['admitted_at'])) ?></div>
        <div><span>Discharged</span><?= $adm['discharged_at'] ? date('d/m/Y H:i', strtotime($adm['discharged_at'])) : '—' ?></div>
        <div><span>Stay</span><?= $days ?> day<?= $days > 1 ? 's' : '' ?></div>
    </div>
<?php };
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Patient File — <?= htmlspecialchars($adm['patient_name']) ?></title>
<style>
/* @page is written by partials/paper_size.php so the size can be switched. */
* { box-sizing: border-box; }
body { font-family: 'Lora', Georgia, serif; color: #1a1a1a; margin: 0; padding: 18px; font-size: 12.5px; }
.wrap { max-width: 186mm; margin: 0 auto; }
:root[data-paper="A5"] .wrap { max-width: 128mm; }
:root[data-paper="A5"] .meta { grid-template-columns: repeat(2, 1fr); }
.sheet-page { page-break-after: always; }
.sheet-page:last-child { page-break-after: auto; }
.hdr { border-bottom: 2px solid #1a1a1a; padding-bottom: 8px; margin-bottom: 12px;
       display: flex; justify-content: space-between; align-items: baseline; }
.hdr .brand { font-size: 17px; font-weight: 700; letter-spacing: .02em; }
.hdr .sheet { font-size: 12px; color: #444; text-transform: uppercase; letter-spacing: .08em; }
.meta { display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px 14px; margin-bottom: 16px;
        font-size: 11px; border-bottom: 1px solid #ccc; padding-bottom: 10px; }
.meta span { color: #666; display: block; font-size: 9.5px; text-transform: uppercase; letter-spacing: .04em; }
table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
th, td { padding: 5px 7px; border-bottom: 1px solid #ccc; text-align: left; vertical-align: top; }
th { font-size: 9.5px; text-transform: uppercase; letter-spacing: .04em; color: #555; background: #f4f4f0; }
td.n, th.n { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
td.w { white-space: nowrap; }
tr.off td { color: #777; }
tr.off td.svc { text-decoration: line-through; }
.note-block { border: 1px solid #ccc; border-radius: 4px; padding: 10px 12px; margin-bottom: 10px; page-break-inside: avoid; }
.note-head { display: flex; justify-content: space-between; border-bottom: 1px solid #ddd;
             padding-bottom: 5px; margin-bottom: 6px; font-size: 11px; }
.note-head b { font-size: 12.5px; }
.f { margin-bottom: 5px; font-size: 11.5px; }
.f span { color: #666; font-size: 9.5px; text-transform: uppercase; letter-spacing: .04em; display: block; }
.pill { display: inline-block; padding: 1px 8px; border: 1px solid #999; border-radius: 20px; font-size: 10px; }
.narr { white-space: pre-wrap; font-size: 12px; line-height: 1.65; }
.sig { margin-top: 34px; display: flex; gap: 40px; }
.sig div { flex: 1; border-top: 1px solid #1a1a1a; padding-top: 5px; font-size: 10.5px; color: #555; }
.foot { margin-top: 16px; font-size: 9.5px; color: #666; text-align: center; }
.empty { padding: 24px; text-align: center; color: #777; font-size: 11.5px; font-style: italic; }
.tot { width: 50%; margin-left: auto; border-top: 1.5px solid #1a1a1a; padding-top: 5px;
       display: flex; justify-content: space-between; font-weight: 700; font-size: 13px; }
.bar { background: #223A31; color: #fff; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px;
       font-family: system-ui, -apple-system, sans-serif; }
.bar h1 { font-size: 15px; margin: 0 0 8px; font-weight: 600; }
.bar label { display: inline-flex; align-items: center; gap: 5px; margin-right: 14px; font-size: 12.5px; cursor: pointer; }
.bar .btns { margin-top: 10px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.bar .paper-pick { margin-left: 6px; padding-left: 12px; border-left: 1px solid rgba(255,255,255,.28); }
.bar button { font: inherit; font-size: 12.5px; padding: 6px 14px; border-radius: 7px; cursor: pointer;
              border: 1px solid rgba(255,255,255,.35); background: transparent; color: #fff; }
.bar button.go { background: #fff; color: #223A31; border-color: #fff; font-weight: 600; }
@media print { .bar { display: none; } body { padding: 0; } }
</style>
</head>
<body>

<div class="bar">
    <h1>Patient file &mdash; choose the sheets to print</h1>
    <form id="pick" method="GET" action="ipd_file.php">
        <input type="hidden" name="id" value="<?= $admissionId ?>">
        <?php foreach ($sheetTitles as $key => $label): ?>
        <label><input type="checkbox" class="sh" value="<?= $key ?>" <?= $has($key) ? 'checked' : '' ?>> <?= htmlspecialchars($label) ?></label>
        <?php endforeach; ?>
        <input type="hidden" name="sheets" id="sheetsField" value="<?= htmlspecialchars(implode(',', $want)) ?>">
        <div class="btns">
            <button type="button" id="selAll">Select all</button>
            <button type="button" id="selNone">Clear</button>
            <button type="submit">Apply</button>
            <?php $paperDefault = 'A4'; $paperMargin = '12mm'; require __DIR__ . '/partials/paper_size.php'; ?>
            <button type="button" class="go" onclick="window.print()">Print / Save PDF</button>
        </div>
    </form>
</div>

<div class="wrap">

<?php if ($has('summary')): ?>
<div class="sheet-page">
    <?php $patientHeader('Discharge Summary'); ?>
    <?php if (!$summary): ?>
        <div class="empty">No discharge summary has been written for this admission.</div>
    <?php else: ?>
        <div class="f"><span>Final diagnosis</span><?= htmlspecialchars($summary['final_diagnosis'] ?: '—') ?></div>
        <?php if (!empty($adm['provisional_diagnosis'])): ?>
        <div class="f"><span>Provisional diagnosis at admission</span><?= htmlspecialchars($adm['provisional_diagnosis']) ?></div>
        <?php endif; ?>
        <div class="f" style="margin-top:10px;"><span>Summary</span></div>
        <div class="narr"><?= htmlspecialchars($summary['summary_text'] ?: '—') ?></div>
        <div class="sig">
            <div>Consultant &mdash; <?= htmlspecialchars(mb_strtoupper($summary['author_name'] ?: ($adm['consultant_name'] ?: ''))) ?></div>
            <div>Patient / attendant signature</div>
        </div>
    <?php endif; ?>
    <div class="foot">Printed <?= date('d/m/Y H:i') ?> &middot; by <?= htmlspecialchars(mb_strtoupper($printedBy)) ?></div>
</div>
<?php endif; ?>

<?php if ($has('notes')): ?>
<div class="sheet-page">
    <?php $patientHeader('Daily Round Notes'); ?>
    <?php if (!$notes): ?>
        <div class="empty">No daily-round notes were recorded for this admission.</div>
    <?php else: ?>
        <?php foreach ($notes as $n): ?>
        <div class="note-block">
            <div class="note-head">
                <b>Day <?= (int) $n['hospital_day'] ?> &middot; <?= date('d/m/Y H:i', strtotime($n['visited_at'])) ?></b>
                <span><span class="pill"><?= htmlspecialchars($progressLabels[$n['progress']] ?? $n['progress']) ?></span>
                &nbsp;<?= htmlspecialchars(mb_strtoupper($n['author_name'] ?: '—')) ?></span>
            </div>
            <div class="f"><span>Primary diagnosis</span><?= htmlspecialchars($n['primary_diagnosis']) ?></div>
            <?php foreach ([
                'secondary_diagnosis' => 'Secondary diagnosis',
                'active_complaints'   => 'Active complaints',
                'positive_findings'   => 'Positive findings',
                'investigation_review'=> 'Investigation review',
                'clinical_assessment' => 'Clinical assessment',
                'management_plan'     => 'Management plan',
                'family_counselling'  => 'Family counselling',
            ] as $col => $label): ?>
                <?php if (!empty($n[$col])): ?>
                <div class="f"><span><?= $label ?></span><?= nl2br(htmlspecialchars($n[$col])) ?></div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
    <div class="foot">Printed <?= date('d/m/Y H:i') ?> &middot; by <?= htmlspecialchars(mb_strtoupper($printedBy)) ?></div>
</div>
<?php endif; ?>

<?php if ($has('vitals')): ?>
<div class="sheet-page">
    <?php $patientHeader('Vitals Chart'); ?>
    <?php if (!$vitals): ?>
        <div class="empty">No vitals were recorded for this admission.</div>
    <?php else: ?>
        <table>
            <thead><tr>
                <th>Date / time</th><th class="n">Temp &deg;F</th><th class="n">Pulse</th><th class="n">Resp</th>
                <th class="n">BP</th><th class="n">SpO&#8322;</th><th class="n">Glucose</th><th class="n">Pain</th><th>By</th>
            </tr></thead>
            <tbody>
                <?php foreach ($vitals as $v): ?>
                <tr>
                    <td class="w"><?= date('d/m H:i', strtotime($v['recorded_at'])) ?></td>
                    <td class="n"><?= $v['temp_f'] !== null ? htmlspecialchars((string) $v['temp_f']) : '—' ?></td>
                    <td class="n"><?= $v['pulse_bpm'] !== null ? (int) $v['pulse_bpm'] : '—' ?></td>
                    <td class="n"><?= $v['resp_rate'] !== null ? (int) $v['resp_rate'] : '—' ?></td>
                    <td class="n"><?= ($v['systolic_bp'] !== null && $v['diastolic_bp'] !== null) ? ((int) $v['systolic_bp'] . '/' . (int) $v['diastolic_bp']) : '—' ?></td>
                    <td class="n"><?= $v['spo2_pct'] !== null ? (int) $v['spo2_pct'] . '%' : '—' ?></td>
                    <td class="n"><?= $v['blood_glucose'] !== null ? htmlspecialchars((string) $v['blood_glucose']) : '—' ?></td>
                    <td class="n"><?= $v['pain_score'] !== null ? (int) $v['pain_score'] : '—' ?></td>
                    <td><?= htmlspecialchars(mb_strtoupper($v['by_name'] ?: '—')) ?></td>
                </tr>
                <?php if (!empty($v['notes'])): ?>
                <tr><td></td><td colspan="8" style="font-size:10.5px;color:#666;padding-top:0;">&#8627; <?= htmlspecialchars($v['notes']) ?></td></tr>
                <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <div class="foot">Printed <?= date('d/m/Y H:i') ?> &middot; by <?= htmlspecialchars(mb_strtoupper($printedBy)) ?></div>
</div>
<?php endif; ?>

<?php if ($has('services')): ?>
<div class="sheet-page">
    <?php $patientHeader('Services Record'); ?>
    <?php if (!$services): ?>
        <div class="empty">No services were logged during this stay.</div>
    <?php else: ?>
        <table>
            <thead><tr>
                <th>Date / time</th><th>Service</th><th class="n">Qty</th><th>Given by</th>
                <?php if (!$hideMoney): ?><th class="n">Rs</th><?php endif; ?>
            </tr></thead>
            <tbody>
                <?php foreach ($services as $s): $off = (int) $s['is_billable'] === 0; ?>
                <tr<?= $off ? ' class="off"' : '' ?>>
                    <td class="w"><?= date('d/m H:i', strtotime($s['logged_at'])) ?></td>
                    <td class="svc">
                        <?= htmlspecialchars($s['service_name']) ?>
                        <?php if (!empty($s['clinical_note'])): ?>
                        <div style="font-size:10.5px;color:#666;font-style:italic;"><?= htmlspecialchars($s['clinical_note']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="n"><?= $s['charge_type'] === 'HOURLY' ? ((int) $s['duration_minutes']) . 'm' : (int) $s['quantity'] ?></td>
                    <td><?= htmlspecialchars(mb_strtoupper($s['by_name'] ?: '—')) ?></td>
                    <?php if (!$hideMoney): ?>
                    <td class="n"><?= $off ? 'not charged' : number_format((float) $s['calculated_charge']) ?></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$hideMoney): ?>
        <div class="tot"><span>Total services</span><span>Rs <?= number_format($svcTotal) ?></span></div>
        <?php endif; ?>
        <div class="foot" style="text-align:left;font-style:italic;">Struck-through entries were logged clinically but not charged.</div>
    <?php endif; ?>
    <div class="foot">Printed <?= date('d/m/Y H:i') ?> &middot; by <?= htmlspecialchars(mb_strtoupper($printedBy)) ?></div>
</div>
<?php endif; ?>

</div>

<script>
(function () {
    var boxes = Array.prototype.slice.call(document.querySelectorAll('.sh'));
    var field = document.getElementById('sheetsField');
    function sync() {
        field.value = boxes.filter(function (b) { return b.checked; })
                           .map(function (b) { return b.value; }).join(',');
    }
    boxes.forEach(function (b) { b.addEventListener('change', sync); });
    document.getElementById('selAll').addEventListener('click', function () {
        boxes.forEach(function (b) { b.checked = true; }); sync();
    });
    document.getElementById('selNone').addEventListener('click', function () {
        boxes.forEach(function (b) { b.checked = false; }); sync();
    });
    sync();
})();
</script>
</body>
</html>
