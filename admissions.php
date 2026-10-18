<?php
/**
 * Admissions — today's short-stay patients.
 *
 * The queue on receptionist.php already flags SHORT_STAY dispositions but only
 * ever counted them. This page is that count opened up. Admit/discharge state
 * transitions are not built yet, so the page reports rather than acts.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/tokens.php';
refresh_session_permissions($pdo);

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: /index.php');
    exit;
}

// In-Door work: reception staff and nurses both need this, as do admins/managers.
// NURSE/ADMIN/MANAGER hold NURSING_RECORD_ADMISSIONS via role_permissions
// (sql/rbac_overhaul_2_grants.sql); reception holds RECEPTION_REGISTER_PATIENTS.
$baseRole = $_SESSION['base_role'] ?? '';
if (!has_permission('RECEPTION_REGISTER_PATIENTS') && !has_permission('NURSING_RECORD_ADMISSIONS')) {
    http_response_code(403);
    exit('Forbidden — in-door access only.');
}

$firstName = explode(' ', trim($user['name']))[0] ?? 'there';

// Reception hand-off of open admissions (Phase 4). Pass/accept are receptionist
// actions (the same drawer-holders who close a shift). PRG so a refresh can't replay.
require_once __DIR__ . '/config/billing.php';   // receptionists_with_drawer()
require_once __DIR__ . '/config/handover_helpers.php';
$uid = (int) $_SESSION['user_id'];
$canHandOver = has_permission('RECEPTION_CLOSE_DAY');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canHandOver) {
    $hoResult = ho_handle_post($pdo, $uid);
    if ($hoResult !== null) {
        $_SESSION['ho_flash'] = $hoResult;
        header('Location: admissions.php');
        exit;
    }
}
$hoFlash = $_SESSION['ho_flash'] ?? null;
unset($_SESSION['ho_flash']);
// All three are empty until sql/add_admission_reception_handovers.sql is run.
$myOpen = $canHandOver ? open_admissions_for_user($pdo, $uid) : [];
$myOpenIds = array_flip(array_map(fn($r) => (int) $r['admission_id'], $myOpen));
$passedToMe = $canHandOver ? handovers_passed_to_user($pdo, $uid) : [];
$unaccepted = has_permission('ADMIN_RECEIVE_HANDOVER') ? pending_handovers_unaccepted($pdo) : [];
$hoReceivers = ($myOpen && $canHandOver) ? receptionists_with_drawer($pdo) : [];

// Two tabs on one page:
//   current (default) — the live admissions board: everyone still admitted, plus
//                       today's discharges. Not date-scoped, so a stay
//                       crossing midnight still shows.
//   past              — stays already discharged. Read-only: a finalized bill
//                       is never reopened here, the row just becomes
//                       reachable again. Before this, a stay discharged
//                       before today was unreachable from this page at all.
$tab = ($_GET['tab'] ?? '') === 'past' ? 'past' : 'current';
$isPast = $tab === 'past';

// Optional date + name/MRN narrowing, both meaningful on either tab.
$isDate  = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
$day     = $isDate($_GET['date'] ?? '') ? $_GET['date'] : null;
// A future date would always be empty — clamp it so the picker can't strand
// the user on a blank page.
if ($day !== null && $day > date('Y-m-d')) { $day = date('Y-m-d'); }
// Trimmed so a stray space from a copy-paste does not silently match nothing.
$q = trim($_GET['q'] ?? '');

// Past = discharged AND finalized. A row still mid-discharge belongs on the
// current board (it is unbilled work), so DISCHARGE_IN_PROGRESS is excluded
// from past and left on current — matching how the live filter already treats it.
if ($isPast) {
    $where  = "a.status = 'DISCHARGED' AND a.discharge_finalized_at IS NOT NULL";
    $params = [];
} else {
    $where  = "(a.status <> 'DISCHARGED' OR a.discharge_finalized_at >= CURDATE())";
    $params = [];
}
if ($day !== null) {
    $where .= ' AND DATE(a.admitted_at) = ?';
    $params[] = $day;
}
if ($q !== '') {
    $where .= ' AND (p.name LIKE ? OR p.mrn LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
// Past spans all time, so it needs a cap; the live board is never this large.
$limitSql = $isPast ? ' LIMIT 300' : '';

// Joined to the admissions record for status, nurse and the manage link.
$stmt = $pdo->prepare("
    SELECT a.id AS admission_id, a.status, a.admitted_at, a.admission_type,
           a.discharge_finalized_at,
           v.token_no,
           p.mrn, p.name AS full_name, p.phone,
           COALESCE(du.name, a.admitting_doctor_manual) AS doctor_name,
           -- Prefix comes from the VISIT's doctor (vd), not the admitting consultant
           -- (du) — the token was issued against that doctor's queue and the two are
           -- often different people on an admission.
           vd.name AS token_doctor_name, vd.token_prefix,
           nu.name AS nurse_name
    FROM admissions a
    JOIN visits v ON v.id = a.visit_id
    JOIN patients p ON p.id = v.patient_id
    LEFT JOIN users vd ON vd.id = v.doctor_id
    LEFT JOIN users du ON du.id = a.admitting_doctor_id
    LEFT JOIN users nu ON nu.id = a.assigned_nurse_id
    WHERE $where
    ORDER BY (a.status = 'DISCHARGED'), a.admitted_at DESC$limitSql
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$qhActive = 'admissions';
$qhBrand  = false; // the sidebar already carries the HIMS mark

$pageTitle = 'Admissions';
// Page-specific: the wide table needs a horizontal scroll floor, and this
// page shows a card with no inner padding (the table sits flush). Everything
// else (tokens, .content, base table, .status-pill, .empty) comes from app.css.
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
.status-pill.waiting { background: var(--amber-bg); color: var(--amber-text); }
.status-pill.in-consult { background: var(--green-bg); color: var(--green-text); }
.status-pill.done { background: #F1F5F9; color: var(--text-secondary); }
.empty strong { display: block; font-size: 15px; color: var(--text); margin-bottom: 6px; font-weight: 600; }
/* Filter bar sits in its own card above the table, matching the expense
   report's period picker. Wraps on narrow/mobile instead of overflowing. */
.pick-card { padding: 14px 16px; margin-bottom: 14px; }
/* Current / Past toggle. Anchors, not buttons — each tab is a real URL, so it
   is linkable, bookmarkable and works with the browser's back button. */
.mode-tabs { display: inline-flex; gap: 4px; padding: 3px; background: var(--bg, #F1F5F9); border-radius: 9px; margin-bottom: 12px; }
.mode-tab { padding: 7px 14px; border-radius: 7px; font-size: 13.5px; font-weight: 600; color: var(--text-secondary); text-decoration: none; white-space: nowrap; }
.mode-tab.on { background: var(--surface, #fff); color: var(--primary); box-shadow: 0 1px 2px rgba(0,0,0,.06); }
.filter-form { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.filter-form input { padding: 9px 12px; border: 1px solid var(--border); border-radius: 8px; font: inherit; font-size: 13.5px; background: var(--surface, #fff); color: var(--text); }
.filter-form .q-field { min-width: 190px; flex: 1 1 190px; }
/* Hand-off cards */
.ho-card { padding: 14px 18px; margin-bottom: 14px; border: 1px solid var(--amber); }
.ho-card h2 { font-size: 14px; margin: 0 0 8px; }
.ho-row { display: flex; flex-wrap: wrap; gap: 6px 12px; align-items: center; padding: 8px 0; border-top: 1px dotted var(--border); font-size: 13px; }
.ho-row .pt { flex: 1 1 220px; font-weight: 600; }
.ho-row .pt small { display: block; font-weight: 400; color: var(--text-muted); font-size: 11.5px; }
.ho-row form { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin: 0; }
.ho-row input[type=text], .ho-row select { padding: 7px 10px; border: 1px solid var(--border); border-radius: 8px; font: inherit; font-size: 12.5px; background: var(--surface, #fff); color: var(--text); }
.ho-btn { border: 1px solid var(--border); background: var(--surface, #fff); color: var(--text); border-radius: 8px; font: inherit; font-weight: 600; font-size: 12.5px; padding: 6px 12px; cursor: pointer; }
.ho-btn.go { background: var(--primary-dark); color: #fff; border-color: var(--primary-dark); }
details.pass-on summary { cursor: pointer; color: var(--text-secondary); font-size: 12px; font-weight: 600; margin-top: 4px; }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'admissions';
require __DIR__ . '/partials/sidebar.php';
?>
        <?php require __DIR__ . '/partials/quick_header.php'; ?>

<div class="content">
    <div>
        <div class="page-title">Admissions</div>
        <div class="page-sub">
            <?php if ($isPast): ?>
                Discharged stays &mdash; read-only record
            <?php else: ?>
                Current stays &amp; today's discharges &mdash; <?= date('l, d/m/Y') ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Current / Past toggle. A discharged stay drops off the current board the
         next day, so without the Past tab it cannot be reached from here at all. -->
    <div class="card pick-card">
        <div class="mode-tabs" role="tablist">
            <a class="mode-tab<?= $isPast ? '' : ' on' ?>" href="admissions.php" role="tab" aria-selected="<?= $isPast ? 'false' : 'true' ?>">Currently admitted</a>
            <a class="mode-tab<?= $isPast ? ' on' : '' ?>" href="admissions.php?tab=past" role="tab" aria-selected="<?= $isPast ? 'true' : 'false' ?>">Past admissions</a>
        </div>
        <form class="filter-form" method="GET" action="admissions.php">
            <!-- Keeps the active tab across a filter submit. -->
            <?php if ($isPast): ?><input type="hidden" name="tab" value="past"><?php endif; ?>
            <input type="date" name="date" value="<?= htmlspecialchars($day ?? '') ?>" max="<?= date('Y-m-d') ?>" title="Admitted on this date">
            <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Name or MRN" class="q-field">
            <button type="submit" class="btn">Filter</button>
            <?php if ($day !== null || $q !== ''): ?>
                <a class="btn secondary" href="admissions.php<?= $isPast ? '?tab=past' : '' ?>">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($hoFlash): ?>
    <div class="alert" style="<?= !empty($hoFlash['ok']) ? 'background:var(--green-bg);color:var(--green-text);' : 'background:var(--red-bg);color:var(--red-text);' ?>font-weight:600;"><?= htmlspecialchars($hoFlash['msg']) ?></div>
    <?php endif; ?>

    <?php if (!$isPast && $passedToMe): ?>
    <div class="card ho-card">
        <h2>Passed to you (<?= count($passedToMe) ?>) &mdash; accept to take them over</h2>
        <?php foreach ($passedToMe as $h): ?>
        <div class="ho-row">
            <div class="pt"><?= htmlspecialchars(strtoupper($h['patient_name'])) ?>
                <small>from <?= htmlspecialchars($h['from_name']) ?> &middot; <?= date('d/m/Y h:i A', strtotime($h['passed_at'])) ?> &middot; &ldquo;<?= htmlspecialchars($h['note']) ?>&rdquo;</small></div>
            <form method="POST" action="admissions.php" onsubmit="this.querySelector('button').disabled=true;">
                <input type="hidden" name="action" value="accept_handover">
                <input type="hidden" name="handover_id" value="<?= (int) $h['id'] ?>">
                <input type="hidden" name="imp_csrf" value="<?= htmlspecialchars(imp_csrf_token()) ?>">
                <?= imp_confirm_field('Accept this admission') ?>
                <button class="ho-btn go" type="submit">Accept</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!$isPast && $unaccepted): ?>
    <div class="card ho-card">
        <h2>Passed on, not yet accepted (<?= count($unaccepted) ?>)</h2>
        <?php foreach ($unaccepted as $u): ?>
        <div class="ho-row">
            <div class="pt"><?= htmlspecialchars(strtoupper($u['patient_name'])) ?><?= $u['stale'] ? ' &mdash; <span style="color:var(--red-text)">waiting ' . (int) $u['age_hours'] . 'h</span>' : '' ?>
                <small>from <?= htmlspecialchars($u['from_name']) ?> to <?= htmlspecialchars($u['to_name'] ?: 'next receptionist') ?> &middot; <?= date('d/m/Y h:i A', strtotime($u['passed_at'])) ?> &middot; &ldquo;<?= htmlspecialchars($u['note']) ?>&rdquo;</small></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php
    // Surface nurse-submitted discharges to whoever can bill them.
    $awaitingBilling = array_filter($rows, fn($r) => $r['status'] === 'DISCHARGE_IN_PROGRESS');
    $canBillBanner = has_permission('ADMISSION_FINALIZE_BILL');
    if ($awaitingBilling && $canBillBanner): ?>
    <div class="alert" style="background:var(--amber-bg);color:var(--amber-text);font-weight:600;">
        <?= count($awaitingBilling) ?> discharge<?= count($awaitingBilling) > 1 ? 's' : '' ?> awaiting billing —
        review the charges and generate the invoice<?= count($awaitingBilling) > 1 ? 's' : '' ?> below.
    </div>
    <?php endif; ?>

    <div class="card">
        <?php if (!$rows): ?>
            <div class="empty">
                <?php $filtered = ($day !== null || $q !== ''); ?>
                <?php if ($isPast): ?>
                    <strong>No past admissions<?= $filtered ? ' match this filter' : '' ?></strong>
                    <?= $filtered ? 'Try clearing the date or search above.' : 'Discharged stays will appear here once a patient is billed out.' ?>
                <?php elseif ($filtered): ?>
                    <strong>No current stay matches</strong>
                    Nobody currently admitted matches this filter &mdash; check <a href="admissions.php?tab=past<?= $q !== '' ? '&amp;q=' . urlencode($q) : '' ?>">Past admissions</a>.
                <?php else: ?>
                    <strong>No active admissions</strong>
                    Admit a patient from the reception queue and the stay will appear here.
                <?php endif; ?>
            </div>
        <?php else: ?>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Token</th>
                        <th>Patient</th>
                        <th>Type</th>
                        <th>Doctor</th>
                        <th>Nurse</th>
                        <th>Status</th>
                        <th>Admitted</th>
                        <?php if ($isPast): ?><th>Discharged</th><?php endif; ?>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $stApill = [
                    'PENDING_ASSIGNMENT' => ['pending', 'Awaiting nurse'],
                    'ACTIVE' => ['in-consult', 'Active'],
                    'DISCHARGE_IN_PROGRESS' => ['waiting', 'Awaiting billing'],
                    'DISCHARGED' => ['done', 'Discharged'],
                ];
                // Billing-capable users get a direct "Bill now" action on stays
                // a nurse has submitted for discharge — that hand-off is the
                // whole point of the DISCHARGE_IN_PROGRESS state.
                $canBillList = has_permission('ADMISSION_FINALIZE_BILL');
                foreach ($rows as $r):
                    [$cls, $lbl] = $stApill[$r['status']] ?? ['done', $r['status']]; ?>
                    <tr>
                        <td class="mrn"><?= htmlspecialchars(token_code($r['token_prefix'] ?? null, $r['token_doctor_name'] ?? '', $r['token_no'])) ?></td>
                        <td>
                            <div class="name"><?= htmlspecialchars($r['full_name']) ?></div>
                            <div class="mrn"><?= htmlspecialchars($r['mrn']) ?></div>
                        </td>
                        <td><?= htmlspecialchars($r['admission_type']) ?></td>
                        <td><?= htmlspecialchars($r['doctor_name'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($r['nurse_name'] ?: '—') ?></td>
                        <td><span class="status-pill <?= $cls ?>"><?= $lbl ?></span></td>
                        <?php // Past spans many days, so the date is needed there; the current
                              // board is today-ish and unambiguous on time alone. ?>
                        <td><?= date($isPast ? 'd/m/Y h:i A' : 'h:i A', strtotime($r['admitted_at'])) ?></td>
                        <?php if ($isPast): ?>
                        <td><?= $r['discharge_finalized_at']
                                ? date('d/m/Y h:i A', strtotime($r['discharge_finalized_at']))
                                : '&mdash;' ?></td>
                        <?php endif; ?>
                        <td>
                            <?php if ($r['status'] === 'DISCHARGE_IN_PROGRESS' && $canBillList): ?>
                            <a class="edit-link" href="admission_discharge.php?id=<?= (int) $r['admission_id'] ?>" style="color:var(--amber-text);font-weight:700;font-size:12.5px;">Bill now &rarr;</a>
                            <?php else: ?>
                            <a class="edit-link" href="admission.php?id=<?= (int) $r['admission_id'] ?>" style="color:var(--primary);font-weight:600;font-size:12.5px;">Manage &rarr;</a>
                            <?php endif; ?>
                            <?php if (isset($myOpenIds[(int) $r['admission_id']])): ?>
                            <details class="pass-on ho-row" style="border:none;padding:0;">
                                <summary>Pass on&hellip;</summary>
                                <form method="POST" action="admissions.php" onsubmit="this.querySelector('button').disabled=true;">
                                    <input type="hidden" name="action" value="pass_admission">
                                    <input type="hidden" name="admission_id" value="<?= (int) $r['admission_id'] ?>">
                                    <input type="hidden" name="imp_csrf" value="<?= htmlspecialchars(imp_csrf_token()) ?>">
                                    <input type="text" name="note" maxlength="500" required placeholder="Note for next receptionist" size="22">
                                    <select name="to_user_id">
                                        <option value="0">Anyone</option>
                                        <?php foreach ($hoReceivers as $rc): if ((int) $rc['id'] === $uid) { continue; } ?>
                                            <option value="<?= (int) $rc['id'] ?>"><?= htmlspecialchars(strtoupper($rc['name'])) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?= imp_confirm_field('Pass this admission on') ?>
                                    <button class="ho-btn" type="submit">Pass</button>
                                </form>
                            </details>
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
