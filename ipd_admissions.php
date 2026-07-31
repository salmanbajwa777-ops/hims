<?php
/**
 * In-Door (IPD) admitted-patient list — currently-admitted in-patients.
 *
 * The IPD counterpart to admissions.php (which is ER short-stay). Lists active
 * IPD stays (not date-scoped, so a multi-day stay still shows) plus today's
 * discharged, each linking to the per-stay page (ipd_admission.php). Also hosts
 * the "Admit to In-Door" modal so an in-patient can be admitted from here.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/notify.php';
require_once __DIR__ . '/config/ipd_actions.php';
require_once __DIR__ . '/config/tokens.php';
require_once __DIR__ . '/config/ipd_treatment.php';   // ipd_sheet_state() for the ward-list flag
refresh_session_permissions($pdo);

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
if (!$user) { session_destroy(); header('Location: /index.php'); exit; }

require_permission('IPD_VIEW_WARD');

$flash = '';
$err = '';

// ---- Admit to In-Door (shared handler) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ipd_admit_patient') {
    $res = handle_ipd_admit($pdo);
    if ($res['ok']) {
        header('Location: ipd_admission.php?id=' . (int) $res['admission_id'] . '&admitted=1');
        exit;
    }
    $err = $res['error'];
}

$firstName = explode(' ', trim($user['name']))[0] ?? 'there';

// Two tabs, mirroring admissions.php:
//   current (default) — still admitted, plus today's discharges
//   past              — discharged and finalized. Read-only; a finalized bill
//                       is never reopened here, the row just becomes reachable.
$tab = ($_GET['tab'] ?? '') === 'past' ? 'past' : 'current';
$isPast = $tab === 'past';

$isDate = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
$day    = $isDate($_GET['date'] ?? '') ? $_GET['date'] : null;
if ($day !== null && $day > date('Y-m-d')) { $day = date('Y-m-d'); }
$q = trim($_GET['q'] ?? '');

// DISCHARGE_IN_PROGRESS stays on `current` — it is unbilled work, not history.
// A finalized discharge leaves `current` IMMEDIATELY: the tab says "currently
// admitted", so a settled patient sitting there reads as still occupying a bed.
// (This used to keep same-day discharges on `current` until midnight for
// paperwork convenience, which made the count wrong.)
if ($isPast) {
    $where  = "a.status = 'DISCHARGED' AND a.discharge_finalized_at IS NOT NULL";
} else {
    $where  = "(a.status <> 'DISCHARGED' OR a.discharge_finalized_at IS NULL)";
}
$params = [];
if ($day !== null) {
    $where .= ' AND DATE(a.admitted_at) = ?';
    $params[] = $day;
}
if ($q !== '') {
    $where .= ' AND (p.name LIKE ? OR p.mrn LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
$limitSql = $isPast ? ' LIMIT 300' : '';

// Active first, then today's discharged. Joined for patient/consultant/room category.
$stmt = $pdo->prepare("
    SELECT a.id AS admission_id, a.status, a.admitted_at, a.room_category, a.room_no,
           a.discharge_finalized_at,
           v.token_no,
           p.mrn, p.name AS full_name, p.phone,
           COALESCE(du.name, a.admitting_consultant_manual) AS consultant_name,
           -- Prefix from the VISIT's doctor, not the consultant — see admissions.php.
           vd.name AS token_doctor_name, vd.token_prefix
    FROM ipd_admissions a
    JOIN visits v ON v.id = a.visit_id
    JOIN patients p ON p.id = v.patient_id
    LEFT JOIN users vd ON vd.id = v.doctor_id
    LEFT JOIN users du ON du.id = a.admitting_consultant_id
    WHERE $where
    ORDER BY (a.status = 'DISCHARGED'), a.admitted_at DESC$limitSql
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$qhActive = 'ipd';
$qhBrand  = false;

$pageTitle = 'In-Door (IPD)';
$headExtra = <<<CSS
<style>
.page-title { letter-spacing: -.02em; }
.card { padding: 0; }
.table-scroll { overflow-x: auto; }
table { min-width: 820px; }
th { padding: 14px 16px; border-bottom: 1px solid var(--border); white-space: nowrap; }
td { padding: 13px 16px; }
tbody tr:first-child td { border-top: none; }
.mrn { font-variant-numeric: tabular-nums; color: var(--text-muted); font-size: 12.5px; }
.name { font-weight: 600; }
.status-pill.active { background: var(--green-bg); color: var(--green-text); }
.status-pill.progress { background: #EDE7FB; color: #6D28D9; }
.status-pill.done { background: #F1F5F9; color: var(--text-secondary); }
.empty strong { display: block; font-size: 15px; color: var(--text); margin-bottom: 6px; font-weight: 600; }
.admit-cta { display:flex; justify-content:flex-end; margin-bottom:14px; }
.file-link { display:block; margin-top:3px; color:var(--text-secondary); font-weight:600; font-size:11.5px; }
.file-link:hover { color:var(--primary); }
/* Current / Past toggle + filter bar — kept identical to admissions.php so the
   two admission boards read as one control. Anchors, so each tab is a real URL. */
.pick-card { padding: 14px 16px; margin-bottom: 14px; }
.mode-tabs { display: inline-flex; gap: 4px; padding: 3px; background: var(--bg, #F1F5F9); border-radius: 9px; margin-bottom: 12px; }
.mode-tab { padding: 7px 14px; border-radius: 7px; font-size: 13.5px; font-weight: 600; color: var(--text-secondary); text-decoration: none; white-space: nowrap; }
.mode-tab.on { background: var(--surface, #fff); color: var(--primary); box-shadow: 0 1px 2px rgba(0,0,0,.06); }
.filter-form { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.filter-form input { padding: 9px 12px; border: 1px solid var(--border); border-radius: 8px; font: inherit; font-size: 13.5px; background: var(--surface, #fff); color: var(--text); }
.filter-form .q-field { min-width: 190px; flex: 1 1 190px; }
/* Treatment-sheet flags on the ward list — an unsigned drug chart must be
   visible from the list, not only after opening the stay. */
.ts-flag { display: inline-block; margin-top: 5px; font-size: 11.5px; font-weight: 700; padding: 2px 7px; border-radius: 4px; white-space: nowrap; }
.ts-flag-none { background: var(--danger-bg); color: var(--danger); border: 1px solid var(--danger); }
.ts-flag-pend { background: var(--warn-bg); color: var(--warn); border: 1px solid var(--warn); }
CSS;
$headExtra .= "\n</style>";
require __DIR__ . '/partials/head.php';
$navActive = 'ipd';
require __DIR__ . '/partials/sidebar.php';
?>
        <?php require __DIR__ . '/partials/quick_header.php'; ?>

<div class="content">
    <div>
        <div class="page-title">In-Door (IPD)</div>
        <div class="page-sub">
            <?php if ($isPast): ?>
                Discharged in-patients &mdash; read-only record
            <?php else: ?>
                Admitted in-patients &mdash; <?= date('l, d/m/Y') ?> &middot; <span class="muted">admit an in-patient from the <a href="patients.php" style="color:var(--primary);font-weight:600;">Patients</a> list</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Current / Past toggle — mirrors admissions.php. A discharged in-patient
         drops off the current board the next day, so Past is the only way back. -->
    <div class="card pick-card">
        <div class="mode-tabs" role="tablist">
            <a class="mode-tab<?= $isPast ? '' : ' on' ?>" href="ipd_admissions.php" role="tab" aria-selected="<?= $isPast ? 'false' : 'true' ?>">Currently admitted</a>
            <a class="mode-tab<?= $isPast ? ' on' : '' ?>" href="ipd_admissions.php?tab=past" role="tab" aria-selected="<?= $isPast ? 'true' : 'false' ?>">Past admissions</a>
        </div>
        <form class="filter-form" method="GET" action="ipd_admissions.php">
            <?php if ($isPast): ?><input type="hidden" name="tab" value="past"><?php endif; ?>
            <input type="date" name="date" value="<?= htmlspecialchars($day ?? '') ?>" max="<?= date('Y-m-d') ?>" title="Admitted on this date">
            <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Name or MRN" class="q-field">
            <button type="submit" class="btn">Filter</button>
            <?php if ($day !== null || $q !== ''): ?>
                <a class="btn secondary" href="ipd_admissions.php<?= $isPast ? '?tab=past' : '' ?>">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($flash): ?><div class="alert success"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <div class="card">
        <?php if (!$rows): ?>
            <div class="empty">
                <?php $filtered = ($day !== null || $q !== ''); ?>
                <?php if ($isPast): ?>
                    <strong>No past in-patients<?= $filtered ? ' match this filter' : '' ?></strong>
                    <?= $filtered ? 'Try clearing the date or search above.' : 'Discharged in-patients will appear here once billed out.' ?>
                <?php elseif ($filtered): ?>
                    <strong>No current in-patient matches</strong>
                    Nobody currently admitted matches this filter &mdash; check <a href="ipd_admissions.php?tab=past<?= $q !== '' ? '&amp;q=' . urlencode($q) : '' ?>">Past admissions</a>.
                <?php else: ?>
                    <strong>No in-patients admitted</strong>
                    Admit a patient to In-Door from the Patients list and the stay will appear here.
                <?php endif; ?>
            </div>
        <?php else: ?>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Token</th>
                        <th>Patient</th>
                        <th>Room</th>
                        <th>Consultant</th>
                        <th>Status</th>
                        <th>Admitted</th>
                        <?php if ($isPast): ?><th>Discharged</th><?php endif; ?>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $stPill = [
                    'ACTIVE' => ['active', 'Active'],
                    'DISCHARGE_IN_PROGRESS' => ['progress', 'Discharge in progress'],
                    'DISCHARGED' => ['done', 'Discharged'],
                ];
                foreach ($rows as $r):
                    [$cls, $lbl] = $stPill[$r['status']] ?? ['done', $r['status']]; ?>
                    <tr>
                        <td class="mrn"><?= htmlspecialchars(token_code($r['token_prefix'] ?? null, $r['token_doctor_name'] ?? '', $r['token_no'])) ?></td>
                        <td>
                            <div class="name"><?= htmlspecialchars($r['full_name']) ?></div>
                            <div class="mrn"><?= htmlspecialchars($r['mrn']) ?></div>
                        </td>
                        <td><?= htmlspecialchars($r['room_category']) ?> &middot; Room <?= (int) $r['room_no'] ?></td>
                        <td><?= htmlspecialchars($r['consultant_name'] ?: '—') ?></td>
                        <td>
                            <span class="status-pill <?= $cls ?>"><?= $lbl ?></span>
                            <?php
                            /* Treatment-sheet flag. Only for still-admitted patients —
                               a discharged stay's sheet is history, not an outstanding
                               task, and flagging it would train people to ignore the flag. */
                            if ($r['status'] !== 'DISCHARGED' && has_permission('IPD_VIEW_TREATMENT_SHEET')):
                                $ts = ipd_sheet_state($pdo, (int) $r['admission_id']);
                                // 'available' is false pre-migration — stay silent
                                // rather than flag every bed with a false alarm.
                                if (!($ts['available'] ?? true)): /* no flag */
                                elseif (!$ts['cleared']): ?>
                                    <div class="ts-flag ts-flag-none" title="No doctor-approved medication order">&#9888; No treatment sheet</div>
                                <?php elseif ($ts['pending']): ?>
                                    <div class="ts-flag ts-flag-pend" title="Written but not yet approved by a doctor">&#9203; <?= (int) $ts['pending'] ?> awaiting approval</div>
                                <?php endif;
                            endif; ?>
                        </td>
                        <td><?= date($isPast ? 'd/m/Y h:i A' : 'd/m, h:i A', strtotime($r['admitted_at'])) ?></td>
                        <?php if ($isPast): ?>
                        <td><?= $r['discharge_finalized_at']
                                ? date('d/m/Y h:i A', strtotime($r['discharge_finalized_at']))
                                : '&mdash;' ?></td>
                        <?php endif; ?>
                        <td>
                            <a class="edit-link" href="ipd_admission.php?id=<?= (int) $r['admission_id'] ?>" style="color:var(--primary);font-weight:600;font-size:12.5px;">Manage &rarr;</a>
                            <?php if ($r['status'] === 'DISCHARGED'): ?>
                            <a class="edit-link file-link" href="ipd_file.php?id=<?= (int) $r['admission_id'] ?>" target="_blank">Print file</a>
                            <?php endif; ?>
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

<!-- dd/mm/yyyy display over a hidden yyyy-mm-dd value — the house date convention.
     Any page with a date input must load this or the picker reads as mm/dd. -->
<script src="assets/js/date-picker.js?v=<?= @filemtime(__DIR__ . "/assets/js/date-picker.js") ?: 1 ?>"></script>
</body>
</html>
