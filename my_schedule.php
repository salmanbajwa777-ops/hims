<?php
/**
 * My Schedule — the doctor's fixed weekly timetable.
 *
 * Most doctors keep the same hours all year, so this is a WEEKLY template:
 * per weekday, time-in / time-out, an optional second session, or an OFF day.
 * Saved to doctor_weekly_schedule (one row per doctor per weekday).
 *
 * This does not touch doctor_day_timings — that remains reception's per-DATE
 * confirmation sheet (delays, one-off offs). Template = the standing pattern;
 * day sheet = today's reality, and reception's sheet always wins for the day.
 */
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

// Doctors edit their own schedule; admins may open it for support and edit too
// (viewing/fixing a doctor's template via ?doctor_id=).
$baseRole = $_SESSION['base_role'] ?? '';
if ($baseRole !== 'DOCTOR' && $baseRole !== 'ADMIN') {
    http_response_code(403);
    exit('Forbidden — doctor console only.');
}
$doctorId = (int) $user['id'];
if ($baseRole === 'ADMIN' && (int) ($_GET['doctor_id'] ?? 0) > 0) {
    $doctorId = (int) $_GET['doctor_id'];
}

$weekdays = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

// ---------------- Save the whole week at once ----------------
$saved = false;
$saveError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_schedule') {
    $rows = $_POST['w'] ?? [];
    if (!is_array($rows)) { $rows = []; }

    // <input type=time> gives HH:MM; anything else (or empty) becomes NULL.
    $tParse = static fn ($v) => preg_match('/^\d{2}:\d{2}$/', trim($v ?? '')) ? trim($v) . ':00' : null;

    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare('
            INSERT INTO doctor_weekly_schedule
                (doctor_id, weekday, is_off, start_time, end_time, start_time_2, end_time_2)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                is_off = VALUES(is_off),
                start_time = VALUES(start_time), end_time = VALUES(end_time),
                start_time_2 = VALUES(start_time_2), end_time_2 = VALUES(end_time_2)
        ');
        foreach ($weekdays as $wd => $label) {
            $r = $rows[$wd] ?? [];
            $isOff  = !empty($r['off']) ? 1 : 0;
            $start  = $tParse($r['start'] ?? '');
            $end    = $tParse($r['end'] ?? '');
            $start2 = $tParse($r['start2'] ?? '');
            $end2   = $tParse($r['end2'] ?? '');
            // A session-2 window with an empty session 1 slides up to be THE window.
            if ($start === null && $end === null && ($start2 !== null || $end2 !== null)) {
                [$start, $end] = [$start2, $end2];
                $start2 = $end2 = null;
            }
            if ($isOff) { $start = $end = $start2 = $end2 = null; } // no windows on an off day

            $up->execute([$doctorId, $wd, $isOff, $start, $end, $start2, $end2]);
        }

        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([$_SESSION['user_id'], 'doctor_schedule_updated', "Weekly schedule updated for doctor #$doctorId"]);

        $pdo->commit();
        $saved = true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        $saveError = 'Could not save the schedule — please try again.';
    }
}

// ---------------- Load the week ----------------
// Friendly guard: if the migration (sql/add_doctor_weekly_schedule.sql) hasn't
// been run yet, say so instead of 500ing — deploys land before phpMyAdmin runs.
$week = [];
try {
    $q = $pdo->prepare('SELECT * FROM doctor_weekly_schedule WHERE doctor_id = ?');
    $q->execute([$doctorId]);
    foreach ($q->fetchAll() as $r) {
        $week[(int) $r['weekday']] = $r;
    }
} catch (Throwable $e) {
    exit('My Schedule is not set up yet — run sql/add_doctor_weekly_schedule.sql in phpMyAdmin first.');
}

// Waiting count for the sidebar badge (same query shape doctor.php uses).
$wq = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE doctor_id = ? AND visit_date = CURDATE() AND consult_status = 'WAITING'");
$wq->execute([(int) $user['id']]);
$dsWaitingCount = (int) $wq->fetchColumn();

$fmt = static fn ($t) => $t ? substr($t, 0, 5) : '';
$todayWd = (int) date('N');

$pageTitle = 'My Schedule';
$headExtra = <<<CSS
<style>
.content { max-width: 860px; }
.page-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 16px; }
.page-head h1 { font-size: 21px; font-weight: 700; }
.page-head .sub { font-size: 13px; color: var(--text-secondary); margin-top: 3px; }

.wk-row { display: grid; grid-template-columns: 120px 90px 1fr; gap: 14px; align-items: start; padding: 13px 0; border-top: 1px solid var(--border); }
.wk-row:first-of-type { border-top: none; }
.wk-day { font-size: 13.5px; font-weight: 600; padding-top: 8px; }
.wk-day .today-tag { display: inline-block; font-size: 10px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--primary); background: var(--primary-light); border-radius: 6px; padding: 1px 7px; margin-left: 6px; }

/* Off toggle — a small switch per row */
.off-toggle { display: inline-flex; align-items: center; gap: 8px; cursor: pointer; padding-top: 8px; user-select: none; }
.off-toggle input { position: absolute; opacity: 0; pointer-events: none; }
.off-toggle .track { width: 36px; height: 20px; border-radius: 20px; background: var(--border); position: relative; transition: background .15s ease; flex-shrink: 0; }
.off-toggle .track::after { content: ""; position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; border-radius: 50%; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.2); transition: left .15s ease; }
.off-toggle input:checked + .track { background: #B91C1C; }
.off-toggle input:checked + .track::after { left: 18px; }
.off-toggle input:focus-visible + .track { outline: 2px solid var(--primary); outline-offset: 2px; }
.off-toggle .lab { font-size: 12px; font-weight: 600; color: var(--text-secondary); }
.wk-row.is-off .off-toggle .lab { color: #B91C1C; }

.time-stack { display: flex; flex-direction: column; gap: 8px; }
.time-pair { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.time-pair .sess { font-size: 10.5px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--text-muted); width: 44px; flex-shrink: 0; }
.time-pair input[type=time] { padding: 7px 9px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 13px; background: var(--bg); color: var(--text); width: 108px; }
.time-pair input[type=time]:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); background: #fff; }
.time-pair .dash { color: var(--text-muted); }
.add-sess { background: none; border: none; padding: 0; font: 600 12px Inter, system-ui, sans-serif; color: var(--primary); cursor: pointer; text-align: left; width: fit-content; }
.add-sess:hover { text-decoration: underline; }
.rm-sess { background: none; border: none; padding: 0 2px; font-size: 15px; line-height: 1; color: var(--text-muted); cursor: pointer; }
.rm-sess:hover { color: #B91C1C; }
.wk-row.is-off .time-stack { opacity: .35; pointer-events: none; }
.wk-row.is-off .time-stack .off-note { opacity: 1; }
.off-note { font-size: 12.5px; color: var(--text-muted); padding-top: 8px; }

.sheet-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 16px; flex-wrap: wrap; }
.sheet-foot .hint { font-size: 12.5px; color: var(--text-muted); max-width: 52ch; }
.copy-link { background: none; border: none; padding: 0; font: 600 12.5px Inter, system-ui, sans-serif; color: var(--primary); cursor: pointer; }
.copy-link:hover { text-decoration: underline; }

@media (max-width: 640px) {
    .wk-row { grid-template-columns: 1fr; gap: 8px; padding: 14px 0; }
    .wk-day, .off-toggle { padding-top: 0; }
}
</style>
CSS;
require __DIR__ . '/partials/head.php';
?>
<div class="app">

    <?php
    $dsActive = 'schedule';
    $dsUserName = $user['name'];
    require __DIR__ . '/partials/doctor_sidebar.php';
    ?>

    <div class="main">
        <div class="content">

            <?php if ($saved): ?><div class="alert success">Weekly schedule saved.</div><?php endif; ?>
            <?php if ($saveError): ?><div class="alert error"><?= htmlspecialchars($saveError) ?></div><?php endif; ?>

            <div class="page-head">
                <div>
                    <h1>My Schedule</h1>
                    <div class="sub">Your fixed weekly hours — set time in / time out for each day, add a second session if you sit twice, or switch a day off.</div>
                </div>
            </div>

            <form method="POST" action="my_schedule.php<?= $baseRole === 'ADMIN' && $doctorId !== (int) $user['id'] ? '?doctor_id=' . $doctorId : '' ?>">
                <input type="hidden" name="action" value="save_schedule">
                <div class="card">
                    <?php foreach ($weekdays as $wd => $label):
                        $r = $week[$wd] ?? null;
                        $isOff = $r ? (bool) $r['is_off'] : false;
                        $s1 = $fmt($r['start_time'] ?? null);
                        $e1 = $fmt($r['end_time'] ?? null);
                        $s2 = $fmt($r['start_time_2'] ?? null);
                        $e2 = $fmt($r['end_time_2'] ?? null);
                        $hasSess2 = $s2 !== '' || $e2 !== '';
                    ?>
                    <div class="wk-row<?= $isOff ? ' is-off' : '' ?>" data-wk-row>
                        <div class="wk-day"><?= $label ?><?php if ($wd === $todayWd): ?><span class="today-tag">Today</span><?php endif; ?></div>

                        <label class="off-toggle">
                            <input type="checkbox" name="w[<?= $wd ?>][off]" value="1" <?= $isOff ? 'checked' : '' ?> onchange="wkOffChanged(this)">
                            <span class="track"></span>
                            <span class="lab"><?= $isOff ? 'Off' : 'Off?' ?></span>
                        </label>

                        <div class="time-stack">
                            <div class="time-pair">
                                <span class="sess">In / Out</span>
                                <input type="time" name="w[<?= $wd ?>][start]" value="<?= htmlspecialchars($s1) ?>">
                                <span class="dash">&ndash;</span>
                                <input type="time" name="w[<?= $wd ?>][end]" value="<?= htmlspecialchars($e1) ?>">
                            </div>
                            <div class="time-pair sess2" <?= $hasSess2 ? '' : 'hidden' ?>>
                                <span class="sess">Sess 2</span>
                                <input type="time" name="w[<?= $wd ?>][start2]" value="<?= htmlspecialchars($s2) ?>">
                                <span class="dash">&ndash;</span>
                                <input type="time" name="w[<?= $wd ?>][end2]" value="<?= htmlspecialchars($e2) ?>">
                                <button type="button" class="rm-sess" title="Remove second session" onclick="wkRemoveSess2(this)">&times;</button>
                            </div>
                            <button type="button" class="add-sess" <?= $hasSess2 ? 'hidden' : '' ?> onclick="wkAddSess2(this)">+ Add second session</button>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="sheet-foot">
                        <div class="hint">
                            This is your standing pattern for the whole year. Reception's daily
                            timings sheet can still override a single day (delay or off) without
                            changing this template.
                            <br><button type="button" class="copy-link" onclick="wkCopyMonday()">Copy Monday's times to all working days</button>
                        </div>
                        <button type="submit" class="btn">Save schedule</button>
                    </div>
                </div>
            </form>

        </div>
    </div>
</div>

<script>
// Toggling a day off greys + disables its time inputs (server also nulls the
// windows for off rows; this is just immediate feedback).
function wkOffChanged(cb) {
    var row = cb.closest('[data-wk-row]');
    if (row) {
        row.classList.toggle('is-off', cb.checked);
        var lab = cb.closest('.off-toggle').querySelector('.lab');
        if (lab) { lab.textContent = cb.checked ? 'Off' : 'Off?'; }
    }
}
function wkAddSess2(btn) {
    var stack = btn.closest('.time-stack');
    stack.querySelector('.sess2').hidden = false;
    btn.hidden = true;
}
function wkRemoveSess2(btn) {
    var pair = btn.closest('.sess2');
    pair.hidden = true;
    pair.querySelectorAll('input[type=time]').forEach(function (i) { i.value = ''; });
    var stack = pair.closest('.time-stack');
    stack.querySelector('.add-sess').hidden = false;
}
// Convenience: stamp Monday's windows onto every non-off day below it.
function wkCopyMonday() {
    var rows = document.querySelectorAll('[data-wk-row]');
    if (!rows.length) { return; }
    var src = rows[0];
    var get = function (row, part) { return row.querySelector('input[name$="[' + part + ']"]'); };
    var vals = { start: get(src, 'start').value, end: get(src, 'end').value,
                 start2: get(src, 'start2').value, end2: get(src, 'end2').value };
    rows.forEach(function (row, i) {
        if (i === 0 || row.classList.contains('is-off')) { return; }
        ['start', 'end', 'start2', 'end2'].forEach(function (p) { get(row, p).value = vals[p]; });
        var has2 = vals.start2 !== '' || vals.end2 !== '';
        row.querySelector('.sess2').hidden = !has2;
        row.querySelector('.add-sess').hidden = has2;
    });
}
</script>
</body>
</html>
