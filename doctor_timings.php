<?php
/**
 * Doctor Timings — today's confirmed consultation hours, per doctor.
 *
 * One of reception's first duties at shift start is confirming which doctors
 * are coming in and when. This page is the single source of truth for that:
 * every doctor gets a row for TODAY with a status (Available / Delayed / Off),
 * a time window and a note. Whatever the outgoing receptionist saved is exactly
 * what the incoming one sees — the "last updated by X at H:i" line makes the
 * handover explicit.
 *
 * receptionist.php pops these timings up automatically once per login session;
 * this page is the edit surface behind that popup (and the sidebar link).
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);

// Anyone logged in may VIEW the day's timings (doctors and admins care too);
// editing is reception work, gated on the same permission as the console.
$canEdit = has_permission('RECEPTION_REGISTER_PATIENTS');

$today = date('Y-m-d');
$saved = false;
$saveError = '';

// ---------------- Save today's timings (whole sheet at once) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_timings') {
    if (!$canEdit) {
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

            $up->execute([$docId, $today, $start, $end, $start2, $end2, $status, $note, $_SESSION['user_id']]);
        }

        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$_SESSION['user_id'], 'doctor_timings_updated', "Updated doctor timings for $today"]);

        $pdo->commit();
        $saved = true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        $saveError = 'Could not save timings — please try again.';
    }
}

// ---------------- Load the sheet: every doctor + today's row (if any) ----------------
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
    $stmt->execute([$today]);
    $doctors = $stmt->fetchAll();
} catch (Throwable $e) {
    exit('Doctor timings is not set up yet — run sql/add_doctor_day_timings.sql in phpMyAdmin first.');
}

// Unconfirmed rows prefill from the doctor's own weekly template (my_schedule.php):
// a doctor whose standing pattern says today is off shows up pre-marked OFF, and
// fixed hours land in the inputs. Reception still confirms by saving — only rows
// with no doctor_day_timings entry for today are touched, and only in-memory.
// try/catch: template table may not be migrated yet; feature silently dormant.
try {
    $tpl = $pdo->prepare('SELECT doctor_id, is_off, start_time, end_time, start_time_2, end_time_2
                          FROM doctor_weekly_schedule WHERE weekday = ?');
    $tpl->execute([(int) date('N')]);
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

$statusLabels = ['AVAILABLE' => 'Available', 'DELAYED' => 'Delayed', 'OFF' => 'Off today'];
// Read-only rendering for roles without the reception permission.
$ro = $canEdit ? '' : ' disabled';

$pageTitle = 'Doctor Timings';
$headExtra = <<<CSS
<style>
.content { max-width: 1000px; }
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

            <?php if ($saved): ?><div class="alert success">Doctor timings for today saved. The next receptionist on duty will see these.</div><?php endif; ?>
            <?php if ($saveError): ?><div class="alert error"><?= htmlspecialchars($saveError) ?></div><?php endif; ?>

            <div class="page-head">
                <div>
                    <h1>Doctor Timings — Today</h1>
                    <div class="sub"><?= date('l, d/m/Y') ?> &middot; confirm each doctor's hours for the day; this is what every reception shift sees.</div>
                </div>
                <?php if ($lastTouch): ?>
                <div class="last-touch">Last updated by <strong><?= htmlspecialchars($lastTouch['by'] ?? 'unknown') ?></strong> at <?= date('H:i', strtotime($lastTouch['at'])) ?></div>
                <?php endif; ?>
            </div>

            <form method="POST" action="doctor_timings.php">
                <input type="hidden" name="action" value="save_timings">
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
                                            <?php if ($canEdit): ?><button type="button" class="sess2-remove" title="Remove session 2" onclick="timRemoveSess2(this)">&times;</button><?php endif; ?>
                                        </div>
                                        <?php if ($canEdit): ?>
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
                        <div class="hint">Timings apply to <strong>today only</strong> and reset each day. Unconfirmed rows prefill from each doctor's own weekly schedule — saving confirms them. Most doctors need just one window; use <strong>+ Add second session</strong> for a split shift. Mark a doctor <strong>Off today</strong> to grey out their windows.</div>
                        <?php if ($canEdit): ?>
                        <button type="submit" class="btn">Save today's timings</button>
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
