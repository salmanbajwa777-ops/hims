<?php
/**
 * Doctor Timings & Schedules — one nav entry, two tabs.
 *
 * Tab 1 ("Today's Sheet", this file's original content): confirmed
 * consultation hours per doctor per day — reception's shift-start duty.
 * Tab 2 ("Weekly Schedule"): the doctor's standing weekly template, delegated
 * straight to my_schedule.php's own admin/manager code path (?tab=schedule
 * here maps to that file's $canActOnOthers picker+editor). Kept as one link
 * in the sidebar instead of two, per request — the two data models
 * (doctor_day_timings vs doctor_weekly_schedule) stay separate underneath.
 *
 * Defaults to TODAY (the sheet reception confirms each shift). ?date=YYYY-MM-DD
 * opens any other date — e.g. pre-marking a doctor off for a known future
 * leave day — without touching today's sheet or the doctor's standing weekly
 * schedule (my_schedule.php). A date-specific save always wins for that one
 * date; the weekly schedule remains the fallback for any date with no row.
 *
 * receptionist.php pops today's timings up automatically once per login
 * session; this page is the edit surface behind that popup (and the sidebar
 * link), plus the only entry point for a different date.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);

// The Weekly Schedule tab is my_schedule.php's own admin/manager render path
// (picker + editor for RECEPTION_EDIT_DOCTOR_TIMINGS holders), reused as-is so
// the two data models never get tangled into one form. It sets $navActive and
// requires sidebar.php itself, so hand off before this file does either.
if (($_GET['tab'] ?? '') === 'schedule') {
    $dtTabBarHtml = doctor_timings_tab_bar('schedule');
    require __DIR__ . '/my_schedule.php';
    exit;
}

/** Renders the two-tab strip shared by both tabs of this merged page. */
function doctor_timings_tab_bar(string $active): string
{
    $sheetCls = $active === 'sheet' ? ' active' : '';
    $schedCls = $active === 'schedule' ? ' active' : '';
    return '<div class="dt-tabs">'
        . '<a href="doctor_timings.php" class="dt-tab' . $sheetCls . '">Today\'s Sheet</a>'
        . '<a href="doctor_timings.php?tab=schedule" class="dt-tab' . $schedCls . '">Weekly Schedule</a>'
        . '</div>';
}

// Anyone logged in may VIEW the day's timings (doctors and admins care too);
// editing is its own capability now (RECEPTION_EDIT_DOCTOR_TIMINGS), split out of
// RECEPTION_REGISTER_PATIENTS so a scheduler can edit timings without full
// registration rights. Current registration holders were back-granted this key
// (sql/rbac_overhaul_2_grants.sql).
$canEdit = has_permission('RECEPTION_EDIT_DOCTOR_TIMINGS');

$today = date('Y-m-d');

// The date this sheet is for — today unless a valid ?date= is given. Past
// dates are allowed to view (audit-style) but not to edit; there is no value
// in rewriting a day that already happened.
$reqDate = (string) ($_GET['date'] ?? '');
$sheetDate = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $reqDate) && strtotime($reqDate) !== false) ? $reqDate : $today;
$isToday = $sheetDate === $today;
$isPast = $sheetDate < $today;
$canEditSheet = $canEdit && !$isPast;

$saved = false;
$saveError = '';

// ---------------- Save this date's timings (whole sheet at once) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_timings') {
    $postDate = (string) ($_POST['sheet_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $postDate) || strtotime($postDate) === false) {
        $postDate = $today;
    }
    if (!$canEdit || $postDate < $today) {
        http_response_code(403);
        exit('Forbidden — reception access only.');
    }
    $rows = $_POST['t'] ?? [];
    if (!is_array($rows)) { $rows = []; }

    // Only real doctors can be written against.
    $validIds = array_column(
        $pdo->query("SELECT id FROM users WHERE base_role = 'DOCTOR'")->fetchAll(),
        'id'
    );
    $validIds = array_map('intval', $validIds);

    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare('
            INSERT INTO doctor_day_timings
                (doctor_id, timing_date, start_time, end_time, start_time_2, end_time_2, status, note, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                start_time = VALUES(start_time), end_time = VALUES(end_time),
                start_time_2 = VALUES(start_time_2), end_time_2 = VALUES(end_time_2),
                status = VALUES(status), note = VALUES(note), updated_by = VALUES(updated_by)
        ');
        // <input type=time> gives HH:MM; anything else (or empty) becomes NULL.
        $tParse = static fn ($v) => preg_match('/^\d{2}:\d{2}$/', trim($v ?? '')) ? trim($v) . ':00' : null;
        foreach ($rows as $docId => $r) {
            $docId = (int) $docId;
            if (!in_array($docId, $validIds, true)) { continue; }

            $status = in_array($r['status'] ?? '', ['AVAILABLE', 'DELAYED', 'OFF'], true)
                ? $r['status'] : 'AVAILABLE';
            $start  = $tParse($r['start'] ?? '');
            $end    = $tParse($r['end'] ?? '');
            $start2 = $tParse($r['start2'] ?? '');
            $end2   = $tParse($r['end2'] ?? '');
            // A session-2 window with an empty session 1 slides up to be THE window.
            if ($start === null && $end === null && ($start2 !== null || $end2 !== null)) {
                [$start, $end] = [$start2, $end2];
                $start2 = $end2 = null;
            }
            if ($status === 'OFF') { $start = $end = $start2 = $end2 = null; } // no windows on an off day
            $note = trim($r['note'] ?? '');
            $note = $note === '' ? null : mb_substr($note, 0, 255);

            $up->execute([$docId, $postDate, $start, $end, $start2, $end2, $status, $note, $_SESSION['user_id']]);
        }

        audit_log($pdo, 'doctor_timings_updated', "Updated doctor timings for $postDate", $_SESSION['user_id']);

        $pdo->commit();
        $saved = true;
        $sheetDate = $postDate;
        $isToday = $sheetDate === $today;
        $isPast = false;
    } catch (Throwable $e) {
        $pdo->rollBack();
        $saveError = 'Could not save timings — please try again.';
    }
}

// ---------------- Load the sheet: every doctor + the selected date's row (if any) ----------------
// Friendly guard: if the migration (sql/add_doctor_day_timings.sql) hasn't been
// run yet, say so instead of 500ing — deploys land before phpMyAdmin runs.
try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.name,
               t.start_time, t.end_time, t.start_time_2, t.end_time_2,
               t.status, t.note, t.updated_at,
               ub.name AS updated_by_name
        FROM users u
        LEFT JOIN doctor_day_timings t ON t.doctor_id = u.id AND t.timing_date = ?
        LEFT JOIN users ub ON ub.id = t.updated_by
        WHERE u.base_role = 'DOCTOR'
        ORDER BY u.name
    ");
    $stmt->execute([$sheetDate]);
    $doctors = $stmt->fetchAll();
} catch (Throwable $e) {
    exit('Doctor timings is not set up yet — run sql/add_doctor_day_timings.sql in phpMyAdmin first.');
}

// Unconfirmed rows prefill from the doctor's own weekly template (my_schedule.php):
// a doctor whose standing pattern says this weekday is off shows up pre-marked
// OFF, and fixed hours land in the inputs. Reception still confirms by saving —
// only rows with no doctor_day_timings entry for this date are touched, and
// only in-memory. try/catch: template table may not be migrated yet; feature
// silently dormant.
try {
    $tpl = $pdo->prepare('SELECT doctor_id, is_off, start_time, end_time, start_time_2, end_time_2
                          FROM doctor_weekly_schedule WHERE weekday = ?');
    $tpl->execute([(int) date('N', strtotime($sheetDate))]);
    $tplByDoc = [];
    foreach ($tpl->fetchAll() as $t) {
        $tplByDoc[(int) $t['doctor_id']] = $t;
    }
    foreach ($doctors as &$d) {
        if ($d['status'] !== null || !isset($tplByDoc[(int) $d['id']])) { continue; }
        $t = $tplByDoc[(int) $d['id']];
        $d['status']       = $t['is_off'] ? 'OFF' : 'AVAILABLE';
        $d['start_time']   = $t['start_time'];
        $d['end_time']     = $t['end_time'];
        $d['start_time_2'] = $t['start_time_2'];
        $d['end_time_2']   = $t['end_time_2'];
    }
    unset($d);
} catch (Throwable $e) {
    // doctor_weekly_schedule not migrated yet — sheet starts blank as before.
}

// Sheet-level "last updated" line: the most recent touch across all rows.
$lastTouch = null;
foreach ($doctors as $d) {
    if ($d['updated_at'] && (!$lastTouch || $d['updated_at'] > $lastTouch['at'])) {
        $lastTouch = ['at' => $d['updated_at'], 'by' => $d['updated_by_name']];
    }
}

$statusLabels = ['AVAILABLE' => 'Available', 'DELAYED' => 'Delayed', 'OFF' => 'Off'];
// Read-only rendering for roles without the reception permission, and for
// any date already in the past (nothing to confirm about a day that happened).
$ro = $canEditSheet ? '' : ' disabled';

$pageTitle = 'Doctor Timings';
$headExtra = <<<CSS
<style>
.content { max-width: 1000px; }
.dt-tabs { display: flex; gap: 4px; margin-bottom: 18px; border-bottom: 1px solid var(--border); }
.dt-tab { padding: 10px 14px; font-size: 13px; font-weight: 600; color: var(--text-secondary); text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -1px; }
.dt-tab:hover { color: var(--text); }
.dt-tab.active { color: var(--primary); border-bottom-color: var(--primary); }
.page-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
.page-head h1 { font-size: 22px; font-weight: 700; }
.page-head .sub { font-size: 13px; color: var(--text-secondary); margin-top: 4px; }
.last-touch { font-size: 12.5px; color: var(--text-muted); }

.card { background: var(--card); border-radius: var(--radius-card); border: 1px solid var(--border); box-shadow: var(--shadow-sm); padding: 22px 24px; }

.tim-table { width: 100%; border-collapse: collapse; }
.tim-table th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); font-weight: 600; padding: 0 10px 10px; }
.tim-table td { padding: 12px 10px; border-top: 1px solid var(--border); vertical-align: middle; }

.doc-cell { display: flex; align-items: center; gap: 10px; min-width: 180px; }
.doc-avatar { width: 34px; height: 34px; border-radius: 50%; background: var(--primary-light); color: var(--primary-dark); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex-shrink: 0; }
.doc-name { font-size: 13.5px; font-weight: 600; }
.doc-updated { font-size: 11px; color: var(--text-muted); margin-top: 1px; }

.status-seg { display: inline-flex; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
.status-seg label { position: relative; cursor: pointer; }
.status-seg input { position: absolute; opacity: 0; pointer-events: none; }
.status-seg span { display: block; padding: 7px 12px; font-size: 12px; font-weight: 600; color: var(--text-secondary); border-left: 1px solid var(--border); white-space: nowrap; }
.status-seg label:first-child span { border-left: none; }
.status-seg input:checked + span.s-avail { background: #ECFDF5; color: #047857; }
.status-seg input:checked + span.s-delay { background: #FFFBEB; color: #92400E; }
.status-seg input:checked + span.s-off { background: #FEF2F2; color: #B91C1C; }

.time-stack { display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }
.time-pair { display: inline-flex; align-items: center; gap: 6px; }
.time-pair .sess { font-size: 10.5px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--text-muted); width: 44px; flex-shrink: 0; }
.time-pair input[type=time] { padding: 7px 9px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 13px; background: var(--bg); color: var(--text); width: 110px; }
.time-pair input[type=time]:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); background: #fff; }
.time-pair .dash { color: var(--text-muted); }
tr.is-off .time-stack, tr.is-off .note-input { opacity: .4; pointer-events: none; }

/* Session 2 is hidden until reception explicitly adds it for a doctor. */
.sess2-row { display: none; }
tr.has-sess2 .sess2-row { display: inline-flex; }
.sess2-add { display: inline-flex; align-items: center; gap: 5px; background: none; border: none; padding: 2px 0; margin-left: 50px; font: inherit; font-size: 12px; font-weight: 600; color: var(--primary); cursor: pointer; }
.sess2-add:hover { text-decoration: underline; }
.sess2-add:disabled { display: none; }
tr.has-sess2 .sess2-add { display: none; }
.sess2-remove { background: none; border: none; padding: 0 4px; font-size: 15px; line-height: 1; color: var(--text-muted); cursor: pointer; }
.sess2-remove:hover { color: #B91C1C; }

.note-input { width: 100%; min-width: 160px; padding: 7px 10px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 13px; background: var(--bg); color: var(--text); }
.note-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); background: #fff; }

.sheet-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 18px; flex-wrap: wrap; }
.sheet-foot .hint { font-size: 12.5px; color: var(--text-muted); }
.empty-state { padding: 32px 10px; text-align: center; color: var(--text-muted); font-size: 13px; }

.date-jump { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
.date-jump label { font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: .04em; }
.date-jump input[type=date] { padding: 7px 10px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 13px; background: var(--card); color: var(--text); }
.back-link { font-size: 12.5px; font-weight: 600; color: var(--primary); text-decoration: none; }
.back-link:hover { text-decoration: underline; }

@media (max-width: 760px) {
    .tim-scroll { overflow-x: auto; }
    .tim-table { min-width: 720px; }
}
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'doctor_timings';
// sidebar.php self-delegates to the doctor sidebar for the DOCTOR role, so a
// doctor who opens this reception page still sees their own clinical nav.
require __DIR__ . '/partials/sidebar.php';
?>
        <div class="content">

            <?= doctor_timings_tab_bar('sheet') ?>

            <?php if ($saved): ?><div class="alert success">Doctor timings for <?= $isToday ? 'today' : date('d/m/Y', strtotime($sheetDate)) ?> saved.<?= $isToday ? ' The next receptionist on duty will see these.' : '' ?></div><?php endif; ?>
            <?php if ($saveError): ?><div class="alert error"><?= htmlspecialchars($saveError) ?></div><?php endif; ?>

            <div class="page-head">
                <div>
                    <h1>Doctor Timings <?= $isToday ? '— Today' : '' ?></h1>
                    <div class="sub">
                        <?= date('l, d/m/Y', strtotime($sheetDate)) ?>
                        <?php if ($isToday): ?>&middot; confirm each doctor's hours for the day; this is what every reception shift sees.
                        <?php elseif ($isPast): ?>&middot; a past date — read-only.
                        <?php else: ?>&middot; setting this ahead of time overrides just this one date; the standing weekly schedule is untouched.
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($lastTouch): ?>
                <div class="last-touch">Last updated by <strong><?= htmlspecialchars($lastTouch['by'] ?? 'unknown') ?></strong> at <?= date('H:i', strtotime($lastTouch['at'])) ?></div>
                <?php endif; ?>
            </div>

            <form method="GET" action="doctor_timings.php" class="date-jump">
                <label for="dateJump">Date</label>
                <input type="date" id="dateJump" name="date" value="<?= htmlspecialchars($sheetDate) ?>" onchange="this.form.submit()">
                <?php if (!$isToday): ?><a href="doctor_timings.php" class="back-link">&larr; Today</a><?php endif; ?>
            </form>

            <form method="POST" action="doctor_timings.php<?= $isToday ? '' : '?date=' . urlencode($sheetDate) ?>">
                <input type="hidden" name="action" value="save_timings">
                <input type="hidden" name="sheet_date" value="<?= htmlspecialchars($sheetDate) ?>">
                <div class="card">
                    <?php if (empty($doctors)): ?>
                        <div class="empty-state">No doctors in the system yet. Add them under Staff &amp; Doctors.</div>
                    <?php else: ?>
                    <div class="tim-scroll">
                    <table class="tim-table">
                        <thead>
                            <tr><th>Doctor</th><th>Status</th><th>Timings</th><th>Note</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($doctors as $d): ?>
                            <?php
                                $st = $d['status'] ?? 'AVAILABLE';
                                $startVal  = $d['start_time'] ? substr($d['start_time'], 0, 5) : '';
                                $endVal    = $d['end_time'] ? substr($d['end_time'], 0, 5) : '';
                                $start2Val = $d['start_time_2'] ? substr($d['start_time_2'], 0, 5) : '';
                                $end2Val   = $d['end_time_2'] ? substr($d['end_time_2'], 0, 5) : '';
                            ?>
                            <?php $hasSess2 = $start2Val !== '' || $end2Val !== ''; ?>
                            <tr class="<?= trim(($st === 'OFF' ? 'is-off ' : '') . ($hasSess2 ? 'has-sess2' : '')) ?>" data-doc-row>
                                <td>
                                    <div class="doc-cell">
                                        <div class="doc-avatar"><?= strtoupper(mb_substr($d['name'], 0, 1)) ?></div>
                                        <div>
                                            <div class="doc-name"><?= htmlspecialchars($d['name']) ?></div>
                                            <?php if ($d['updated_at']): ?>
                                            <div class="doc-updated">by <?= htmlspecialchars($d['updated_by_name'] ?? '—') ?> · <?= date('H:i', strtotime($d['updated_at'])) ?></div>
                                            <?php else: ?>
                                            <div class="doc-updated">not confirmed yet</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="status-seg">
                                        <?php foreach ($statusLabels as $val => $label): ?>
                                        <label>
                                            <input type="radio" name="t[<?= (int) $d['id'] ?>][status]" value="<?= $val ?>" <?= $st === $val ? 'checked' : '' ?> onchange="timStatusChanged(this)"<?= $ro ?>>
                                            <span class="s-<?= $val === 'AVAILABLE' ? 'avail' : ($val === 'DELAYED' ? 'delay' : 'off') ?>"><?= $label ?></span>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="time-stack">
                                        <div class="time-pair">
                                            <span class="sess">Sess 1</span>
                                            <input type="time" name="t[<?= (int) $d['id'] ?>][start]" value="<?= htmlspecialchars($startVal) ?>"<?= $ro ?>>
                                            <span class="dash">&ndash;</span>
                                            <input type="time" name="t[<?= (int) $d['id'] ?>][end]" value="<?= htmlspecialchars($endVal) ?>"<?= $ro ?>>
                                        </div>
                                        <div class="time-pair sess2-row">
                                            <span class="sess">Sess 2</span>
                                            <input type="time" name="t[<?= (int) $d['id'] ?>][start2]" value="<?= htmlspecialchars($start2Val) ?>"<?= $ro ?>>
                                            <span class="dash">&ndash;</span>
                                            <input type="time" name="t[<?= (int) $d['id'] ?>][end2]" value="<?= htmlspecialchars($end2Val) ?>"<?= $ro ?>>
                                            <?php if ($canEditSheet): ?><button type="button" class="sess2-remove" title="Remove session 2" onclick="timRemoveSess2(this)">&times;</button><?php endif; ?>
                                        </div>
                                        <?php if ($canEditSheet): ?>
                                        <button type="button" class="sess2-add" onclick="timAddSess2(this)">+ Add second session</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <input type="text" class="note-input" name="t[<?= (int) $d['id'] ?>][note]" maxlength="255"
                                           value="<?= htmlspecialchars($d['note'] ?? '') ?>" placeholder="e.g. arriving 30 min late, OT till 2pm"<?= $ro ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>

                    <div class="sheet-foot">
                        <div class="hint">Timings apply to <strong><?= $isToday ? 'today only' : 'this date only' ?></strong>. Unconfirmed rows prefill from each doctor's own weekly schedule — saving confirms them. Most doctors need just one window; use <strong>+ Add second session</strong> for a split shift. Mark a doctor <strong>Off</strong> to grey out their windows.</div>
                        <?php if ($canEditSheet): ?>
                        <button type="submit" class="btn">Save <?= $isToday ? "today's" : 'these' ?> timings</button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </form>

        </div>
    </div>
</div>

<script>
// Marking a doctor OFF greys + disables their time/note inputs (server also
// nulls the window for OFF rows, this is just immediate feedback).
function timStatusChanged(radio) {
    var tr = radio.closest('[data-doc-row]');
    if (tr) { tr.classList.toggle('is-off', radio.value === 'OFF'); }
}

// Reveal the (already-present but hidden) session-2 window for one doctor.
function timAddSess2(btn) {
    var tr = btn.closest('[data-doc-row]');
    if (tr) { tr.classList.add('has-sess2'); }
}

// Hide session 2 again and clear its values so nothing is saved for it.
function timRemoveSess2(btn) {
    var tr = btn.closest('[data-doc-row]');
    if (!tr) { return; }
    tr.classList.remove('has-sess2');
    tr.querySelectorAll('.sess2-row input[type=time]').forEach(function (i) { i.value = ''; });
}
</script>
</body>
</html>
