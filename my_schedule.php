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

// ---------------- Save: one set of hours + off days, expanded to the week ----------------
// The doctor enters their hours ONCE (in/out, optional session 2), ticks which
// days are off, and the server stamps the same window onto every working day.
$saved = false;
$saveError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_schedule') {
    // <input type=time> gives HH:MM; anything else (or empty) becomes NULL.
    $tParse = static fn ($v) => preg_match('/^\d{2}:\d{2}$/', trim($v ?? '')) ? trim($v) . ':00' : null;

    $start  = $tParse($_POST['start'] ?? '');
    $end    = $tParse($_POST['end'] ?? '');
    $start2 = $tParse($_POST['start2'] ?? '');
    $end2   = $tParse($_POST['end2'] ?? '');
    // A session-2 window with an empty session 1 slides up to be THE window.
    if ($start === null && $end === null && ($start2 !== null || $end2 !== null)) {
        [$start, $end] = [$start2, $end2];
        $start2 = $end2 = null;
    }

    $offDays = array_map('intval', (array) ($_POST['off'] ?? []));
    $offDays = array_values(array_intersect($offDays, array_keys($weekdays)));

    if ($start === null || $end === null) {
        $saveError = 'Please set your time in and time out.';
    } elseif (count($offDays) === 7) {
        $saveError = 'All seven days are marked off — untick at least one working day.';
    } else {
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
                $isOff = in_array($wd, $offDays, true) ? 1 : 0;
                $up->execute([
                    $doctorId, $wd, $isOff,
                    $isOff ? null : $start,  $isOff ? null : $end,
                    $isOff ? null : $start2, $isOff ? null : $end2,
                ]);
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

// The form shows ONE set of hours + off-day ticks; derive both from the stored
// rows (the first working day's window IS the hours — save writes them uniform).
$curStart = $curEnd = $curStart2 = $curEnd2 = '';
$curOff = [];
foreach ($weekdays as $wd => $label) {
    $r = $week[$wd] ?? null;
    if ($r && $r['is_off']) {
        $curOff[] = $wd;
    } elseif ($r && $curStart === '' && $r['start_time']) {
        $curStart  = $fmt($r['start_time']);
        $curEnd    = $fmt($r['end_time']);
        $curStart2 = $fmt($r['start_time_2']);
        $curEnd2   = $fmt($r['end_time_2']);
    }
}
$hasSess2 = $curStart2 !== '' || $curEnd2 !== '';
$hasSaved = !empty($week);

$pageTitle = 'My Schedule';
$headExtra = <<<CSS
<style>
.content { max-width: 640px; }
.page-head { margin-bottom: 16px; }
.page-head h1 { font-size: 21px; font-weight: 700; }
.page-head .sub { font-size: 13px; color: var(--text-secondary); margin-top: 3px; }

.blk-label { font-size: 11px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 8px; }
.blk { margin-bottom: 20px; }
.blk:last-of-type { margin-bottom: 0; }

.time-pair { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.time-pair input[type=time] { padding: 9px 11px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 14px; background: var(--bg); color: var(--text); width: 130px; }
.time-pair input[type=time]:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,127,126,.15); background: #fff; }
.time-pair .dash { color: var(--text-muted); }
.sess2-wrap[hidden], .add-sess[hidden] { display: none !important; }
.sess2-wrap { margin-top: 10px; }
.add-sess { background: none; border: none; padding: 0; margin-top: 10px; font: 600 12.5px Inter, system-ui, sans-serif; color: var(--primary); cursor: pointer; }
.add-sess:hover { text-decoration: underline; }
.rm-sess { background: none; border: none; padding: 0 4px; font-size: 16px; line-height: 1; color: var(--text-muted); cursor: pointer; }
.rm-sess:hover { color: #B91C1C; }

/* Off-day picker — one chip per weekday, tap to mark off (red) */
.day-chips { display: flex; gap: 8px; flex-wrap: wrap; }
.day-chip { position: relative; cursor: pointer; user-select: none; }
.day-chip input { position: absolute; opacity: 0; pointer-events: none; }
.day-chip span { display: flex; align-items: center; justify-content: center; min-width: 52px; padding: 9px 10px; border: 1px solid var(--border); border-radius: 10px; font-size: 12.5px; font-weight: 600; color: var(--text-secondary); background: var(--card); transition: all .12s ease; }
.day-chip input:checked + span { background: #FEF2F2; border-color: #FECACA; color: #B91C1C; text-decoration: line-through; }
.day-chip input:focus-visible + span { outline: 2px solid var(--primary); outline-offset: 2px; }
.chips-hint { font-size: 12px; color: var(--text-muted); margin-top: 8px; }

/* Live summary of what will be saved */
.wk-preview { border-top: 1px solid var(--border); margin-top: 18px; padding-top: 14px; }
.wk-line { display: flex; justify-content: space-between; gap: 12px; font-size: 12.5px; padding: 4px 0; }
.wk-line .d { color: var(--text-secondary); font-weight: 600; min-width: 90px; }
.wk-line .t { color: var(--text); }
.wk-line.off .t { color: #B91C1C; font-weight: 600; }
.wk-line.today .d::after { content: " · today"; font-size: 10.5px; color: var(--primary); font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }

.sheet-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 18px; flex-wrap: wrap; }
.sheet-foot .hint { font-size: 12px; color: var(--text-muted); max-width: 42ch; }
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
                <h1>My Schedule</h1>
                <div class="sub">One-time setup — enter your daily hours once, tick your off days, and it applies to the whole week, all year.</div>
            </div>

            <form method="POST" action="my_schedule.php<?= $baseRole === 'ADMIN' && $doctorId !== (int) $user['id'] ? '?doctor_id=' . $doctorId : '' ?>">
                <input type="hidden" name="action" value="save_schedule">
                <div class="card">

                    <div class="blk">
                        <div class="blk-label">My daily hours</div>
                        <div class="time-pair">
                            <input type="time" name="start" value="<?= htmlspecialchars($curStart) ?>" required>
                            <span class="dash">&ndash;</span>
                            <input type="time" name="end" value="<?= htmlspecialchars($curEnd) ?>" required>
                        </div>
                        <div class="sess2-wrap" id="sess2Wrap" <?= $hasSess2 ? '' : 'hidden' ?>>
                            <div class="time-pair">
                                <input type="time" name="start2" value="<?= htmlspecialchars($curStart2) ?>">
                                <span class="dash">&ndash;</span>
                                <input type="time" name="end2" value="<?= htmlspecialchars($curEnd2) ?>">
                                <button type="button" class="rm-sess" title="Remove second session" onclick="wkRemoveSess2()">&times;</button>
                            </div>
                        </div>
                        <button type="button" class="add-sess" id="addSessBtn" <?= $hasSess2 ? 'hidden' : '' ?> onclick="wkAddSess2()">+ Add a second session (evening sitting)</button>
                    </div>

                    <div class="blk">
                        <div class="blk-label">My off days</div>
                        <div class="day-chips">
                            <?php foreach ($weekdays as $wd => $label): ?>
                            <label class="day-chip">
                                <input type="checkbox" name="off[]" value="<?= $wd ?>" <?= in_array($wd, $curOff, true) ? 'checked' : '' ?> onchange="wkPreview()">
                                <span><?= substr($label, 0, 3) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="chips-hint">Tap a day to mark it off — every other day gets the hours above.</div>
                    </div>

                    <div class="wk-preview" id="wkPreview"></div>

                    <div class="sheet-foot">
                        <div class="hint">Reception's daily timings sheet can still override a single day (delay or one-off leave) without changing this.</div>
                        <button type="submit" class="btn"><?= $hasSaved ? 'Update schedule' : 'Save schedule' ?></button>
                    </div>
                </div>
            </form>

        </div>
    </div>
</div>

<script>
var WK_DAYS = <?= json_encode(array_values($weekdays)) ?>;
var WK_TODAY = <?= (int) date('N') ?>; // 1=Mon … 7=Sun

function wkAddSess2() {
    document.getElementById('sess2Wrap').hidden = false;
    document.getElementById('addSessBtn').hidden = true;
    wkPreview();
}
function wkRemoveSess2() {
    var wrap = document.getElementById('sess2Wrap');
    wrap.hidden = true;
    wrap.querySelectorAll('input[type=time]').forEach(function (i) { i.value = ''; });
    document.getElementById('addSessBtn').hidden = false;
    wkPreview();
}

// 24h "14:30" -> "2:30 PM" for the preview lines.
function wkFmt(v) {
    if (!v) { return ''; }
    var p = v.split(':'), h = parseInt(p[0], 10);
    var ap = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return h + ':' + p[1] + ' ' + ap;
}

// Live preview: exactly what each weekday will be saved as.
function wkPreview() {
    var s = document.querySelector('input[name=start]').value;
    var e = document.querySelector('input[name=end]').value;
    var s2 = document.querySelector('input[name=start2]').value;
    var e2 = document.querySelector('input[name=end2]').value;
    var win = (s && e) ? wkFmt(s) + ' – ' + wkFmt(e) : '—';
    if (s2 && e2) { win += ' &amp; ' + wkFmt(s2) + ' – ' + wkFmt(e2); }

    var offs = {};
    document.querySelectorAll('input[name="off[]"]:checked').forEach(function (c) { offs[c.value] = true; });

    var html = '';
    WK_DAYS.forEach(function (d, i) {
        var wd = i + 1, off = !!offs[wd];
        html += '<div class="wk-line' + (off ? ' off' : '') + (wd === WK_TODAY ? ' today' : '') + '">'
              + '<span class="d">' + d + '</span>'
              + '<span class="t">' + (off ? 'Off' : win) + '</span></div>';
    });
    document.getElementById('wkPreview').innerHTML = html;
}
document.querySelectorAll('input[type=time]').forEach(function (i) { i.addEventListener('input', wkPreview); });
wkPreview();
</script>
</body>
</html>
