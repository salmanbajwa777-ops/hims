<?php
/**
 * IPD stay report — A5 print. Every service given during the stay, in order,
 * with who gave it and when.
 *
 * The clinical companion to ipd_invoice.php: the invoice says what was CHARGED,
 * this says what was DONE. Removed items (is_billable = 0) are printed struck
 * through as "not charged" rather than hidden, so the care record and the bill
 * reconcile on one sheet — an admin taking a charge off never erases the fact
 * that a nurse gave the service.
 *
 * Reads ipd_services directly; stores nothing of its own.
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
           v.token_no, p.mrn, p.name AS patient_name, p.father_name, p.phone,
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

$svc = $pdo->prepare('
    SELECT s.*, u.name AS by_name
    FROM ipd_services s
    LEFT JOIN users u ON u.id = s.logged_by_id
    WHERE s.admission_id = ?
    ORDER BY s.logged_at, s.id
');
$svc->execute([$admissionId]);
$services = $svc->fetchAll();

// Doctors never see charges anywhere in this app.
$hideMoney = (($_SESSION['base_role'] ?? '') === 'DOCTOR');

// The session carries only user_id — no name — so look it up for the footer.
$pb = $pdo->prepare('SELECT name FROM users WHERE id = ?');
$pb->execute([(int) ($_SESSION['user_id'] ?? 0)]);
$printedBy = (string) ($pb->fetchColumn() ?: 'STAFF');

$charged = 0.0;
$nCharged = 0;
$byPerson = [];
foreach ($services as $s) {
    $who = $s['by_name'] ?: 'Unknown';
    $byPerson[$who] = ($byPerson[$who] ?? 0) + 1;
    if ((int) $s['is_billable'] === 1) { $charged += (float) $s['calculated_charge']; $nCharged++; }
}
arsort($byPerson);

// Calendar days, admit day = day 1 — same rule the bill uses.
$a = new DateTime(date('Y-m-d', strtotime($adm['admitted_at'])));
$d = new DateTime(date('Y-m-d', strtotime($adm['discharged_at'] ?: 'now')));
$days = max(1, (int) $a->diff($d)->days + 1);

// The printed name follows the treating consultant, same rule as every other
// patient-facing document: a dental admission prints SMILE RESORT, everything
// else the house brand. A manual (non-user) consultant has no specialty, so it
// falls through to the house brand — the right default.
$b = brand($adm['consultant_specialty'] ?? null);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Stay Report — <?= htmlspecialchars($adm['patient_name']) ?></title>
<style>
/* @page is written by partials/paper_size.php so the size can be switched. */
* { box-sizing: border-box; }
body { font-family: 'Lora', Georgia, serif; color: #1a1a1a; margin: 0; padding: 16px; font-size: 12.5px; }
.wrap { max-width: 148mm; margin: 0 auto; }
:root[data-paper="A4"] .wrap { max-width: 186mm; }
.hdr { border: 1.5px solid #1a1a1a; border-radius: 6px; padding: 12px 16px; margin-bottom: 14px; text-align: center; }
.hdr h1 { font-size: 18px; margin: 0 0 2px; letter-spacing: .02em; }
.hdr .sub { font-size: 11px; color: #444; }
.meta { display: flex; justify-content: space-between; gap: 16px; margin-bottom: 12px; }
.meta table { font-size: 11.5px; }
.meta td { padding: 1px 0; }
.meta td:first-child { color: #555; padding-right: 10px; }
table.items { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
table.items th, table.items td { padding: 6px 8px; border-bottom: 1px solid #ccc; text-align: left; vertical-align: top; }
table.items th { font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #555; }
table.items td.amt, table.items th.amt { text-align: right; white-space: nowrap; }
table.items td.when { white-space: nowrap; }
table.items tr.off td { color: #777; }
table.items tr.off td.svc { text-decoration: line-through; }
.note { font-size: 10.5px; color: #666; font-style: italic; }
.tot { width: 60%; margin-left: auto; }
.tot .r { display: flex; justify-content: space-between; padding: 3px 8px; font-size: 12.5px; }
.tot .r.grand { border-top: 1.5px solid #1a1a1a; font-weight: 700; font-size: 14px; margin-top: 4px; padding-top: 6px; }
.tally { margin-top: 14px; border-top: 1px solid #ccc; padding-top: 8px; font-size: 11px; color: #444; }
.tally b { color: #1a1a1a; }
.foot { margin-top: 20px; font-size: 10.5px; color: #666; text-align: center; }
.empty { padding: 20px; text-align: center; color: #666; font-size: 12px; }
.print-btn { margin: 12px 0; display: flex; align-items: center; gap: 14px; }
.print-btn .paper-pick { padding-left: 14px; border-left: 1px solid #ccc; }
@media print { .print-btn { display: none; } body { padding: 0; } }
</style>
</head>
<body>
<div class="print-btn"><button onclick="window.print()">Print / Save PDF</button><?php $paperDefault = 'A5'; $paperMargin = '10mm'; require __DIR__ . '/partials/paper_size.php'; ?></div>
<div class="wrap">
    <div class="hdr">
        <h1><?= htmlspecialchars($b['name']) ?></h1>
        <div class="sub">In-Patient Stay &middot; Service Record</div>
    </div>

    <div class="meta">
        <table>
            <tr><td>Patient</td><td><b><?= htmlspecialchars(mb_strtoupper($adm['patient_name'])) ?></b></td></tr>
            <tr><td>MR No.</td><td><?= htmlspecialchars($adm['mrn']) ?></td></tr>
            <tr><td>Consultant</td><td><?= htmlspecialchars(mb_strtoupper($adm['consultant_name'] ?: '—')) ?></td></tr>
        </table>
        <table>
            <tr><td>Room</td><td><?= htmlspecialchars($adm['room_category']) ?> &middot; <?= (int) $adm['room_no'] ?></td></tr>
            <tr><td>Admitted</td><td><?= date('d/m/Y H:i', strtotime($adm['admitted_at'])) ?></td></tr>
            <tr><td>Discharged</td><td><?= $adm['discharged_at'] ? date('d/m/Y H:i', strtotime($adm['discharged_at'])) : '—' ?></td></tr>
            <tr><td>Stay</td><td><?= $days ?> day<?= $days > 1 ? 's' : '' ?></td></tr>
        </table>
    </div>

    <?php if (!$services): ?>
    <div class="empty">No services were logged during this stay.</div>
    <?php else: ?>
    <table class="items">
        <thead>
            <tr>
                <th>Date</th><th>Service</th><th>Qty</th><th>By</th>
                <?php if (!$hideMoney): ?><th class="amt">Rs</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($services as $s): $off = (int) $s['is_billable'] === 0; ?>
            <tr<?= $off ? ' class="off"' : '' ?>>
                <td class="when"><?= date('d/m H:i', strtotime($s['logged_at'])) ?></td>
                <td class="svc">
                    <?= htmlspecialchars($s['service_name']) ?>
                    <?php if (!empty($s['clinical_note'])): ?>
                    <div class="note"><?= htmlspecialchars($s['clinical_note']) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= $s['charge_type'] === 'HOURLY' ? ((int) $s['duration_minutes']) . 'm' : (int) $s['quantity'] ?></td>
                <td><?= htmlspecialchars(mb_strtoupper($s['by_name'] ?: '—')) ?></td>
                <?php if (!$hideMoney): ?>
                <td class="amt"><?= $off ? 'not charged' : number_format((float) $s['calculated_charge']) ?></td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if (!$hideMoney): ?>
    <div class="tot">
        <div class="r grand"><span>Total services</span><span>Rs <?= number_format($charged) ?></span></div>
    </div>
    <?php endif; ?>

    <div class="tally">
        <b><?= count($services) ?></b> service<?= count($services) === 1 ? '' : 's' ?> logged<?php
            if (count($services) !== $nCharged) {
                echo ' &middot; <b>' . (count($services) - $nCharged) . '</b> not charged';
            }
        ?>
        <?php if ($byPerson): ?>
        <br>By:
        <?php $bits = []; foreach ($byPerson as $who => $n) { $bits[] = htmlspecialchars(mb_strtoupper($who)) . ' (' . $n . ')'; } echo implode(' &middot; ', $bits); ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="foot">
        <?php if ($services): ?>Struck-through entries were logged clinically but not charged.<br><?php endif; ?>
        Printed <?= date('d/m/Y H:i') ?> &middot; by <?= htmlspecialchars(mb_strtoupper($printedBy)) ?>
    </div>
</div>
</body>
</html>
