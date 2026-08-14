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

// Doctors edit their own schedule; admins and managers may open it for
// support and edit too (viewing/fixing a doctor's template via ?doctor_id=),
// gated on the same permission as reception's day sheet — see
// sql/grant_manager_doctor_timings.sql.
$baseRole = $_SESSION['base_role'] ?? '';
$canActOnOthers = has_permission('RECEPTION_EDIT_DOCTOR_TIMINGS') && $baseRole !== 'DOCTOR';
if ($baseRole !== 'DOCTOR' && !$canActOnOthers) {
    http_response_code(403);
    exit('Forbidden — doctor console only.');
}
$doctorId = (int) $user['id'];
$targetDoctorName = $user['name'];
$doctorPickerList = null; // populated below only when a picker must render
if ($canActOnOthers) {
    // Admin/manager have no schedule of their own — a doctor to act on behalf
    // of is required, never silently falls back to editing their own account.
    // With no ?doctor_id= yet, show a picker instead of erroring (this page
    // is reached directly from the sidebar now, not only from Staff & Doctors).
    $doctorId = (int) ($_GET['doctor_id'] ?? 0);
    if ($doctorId <= 0) {
        $doctorPickerList = $pdo->query(
            "SELECT id, name, specialty FROM users WHERE base_role = 'DOCTOR' AND is_active = 1 ORDER BY name"
        )->fetchAll();
    } else {
        $tgtStmt = $pdo->prepare('SELECT name FROM users WHERE id = ? AND base_role = "DOCTOR"');
        $tgtStmt->execute([$doctorId]);
        $targetDoctorName = $tgtStmt->fetchColumn();
        if ($targetDoctorName === false) {
            http_response_code(404);
            exit('Doctor not found.');
        }
    }
}

$weekdays = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

// ---------------- Save: one set of hours + off days, expanded to the week ----------------
// The doctor enters their hours ONCE (in/out, optional session 2), ticks which
// days are off, and the server stamps the same window onto every working day.
$saved = false;
$saveError = '';
if ($doctorId > 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_schedule') {
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

            audit_log($pdo, 'doctor_schedule_updated', "Weekly schedule updated for doctor #$doctorId", $_SESSION['user_id']);

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
if ($doctorId > 0) {
    try {
        $q = $pdo->prepare('SELECT * FROM doctor_weekly_schedule WHERE doctor_id = ?');
        $q->execute([$doctorId]);
        foreach ($q->fetchAll() as $r) {
            $week[(int) $r['weekday']] = $r;
        }
    } catch (Throwable $e) {
        exit('My Schedule is not set up yet — run sql/add_doctor_weekly_schedule.sql in phpMyAdmin first.');
    }
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

.back-link { display: inline-block; margin-top: 8px; font-size: 12.5px; font-weight: 600; color: var(--primary); text-decoration: none; }
.back-link:hover { text-decoration: underline; }

/* Doctor picker (admin/manager landing on this page with no doctor chosen) */
.picker-list { display: flex; flex-direction: column; }
.picker-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 13px 4px; border-top: 1px solid var(--border); text-decoration: none; color: inherit; }
.picker-row:first-child { border-top: none; }
.picker-row:hover { background: var(--bg); }
.picker-name { font-size: 13.5px; font-weight: 600; color: var(--text); }
.picker-specialty { font-size: 12px; color: var(--text-muted); }
.empty-state { padding: 24px 4px; text-align: center; color: var(--text-muted); font-size: 13px; }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'schedule';
// sidebar.php self-delegates to doctor_sidebar.php for the DOCTOR role (with
// the Dental group specialty-gated via $dsSpecialty), so admin/manager acting
// on a doctor's behalf still get the normal reception/admin nav instead.
$dsSpecialty = $user['specialty'] ?? '';
require __DIR__ . '/partials/sidebar.php';
?>

        <div class="content">

            <?php if ($saved): ?><div class="alert success">Weekly schedule saved.</div><?php endif; ?>
            <?php if ($saveError): ?><div class="alert error"><?= htmlspecialchars($saveError) ?></div><?php endif; ?>

            <?php if ($doctorPickerList !== null): ?>

            <div class="page-head">
                <h1>Doctor Schedules</h1>
                <div class="sub">Pick a doctor to view or set their standing weekly hours.</div>
            </div>
            <div class="card">
                <?php if (empty($doctorPickerList)): ?>
                <div class="empty-state">No doctors in the system yet.</div>
                <?php else: ?>
                <div class="picker-list">
                    <?php foreach ($doctorPickerList as $doc): ?>
                    <a class="picker-row" href="my_schedule.php?doctor_id=<?= (int) $doc['id'] ?>">
                        <span class="picker-name"><?= htmlspecialchars($doc['name']) ?></span>
                        <?php if ($doc['specialty']): ?><span class="picker-specialty"><?= htmlspecialchars($doc['specialty']) ?></span><?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php else: ?>

            <?php $editingOther = $canActOnOthers; ?>
            <div class="page-head">
                <h1><?= $editingOther ? htmlspecialchars($targetDoctorName) . "'s Schedule" : 'My Schedule' ?></h1>
                <div class="sub">
                    <?php if ($editingOther): ?>
                        Editing on the doctor's behalf — set their routine hours once, tick off days, and it applies to the whole week, all year. The doctor can still adjust this themselves from their own console.
                    <?php else: ?>
                        One-time setup — enter your daily hours once, tick your off days, and it applies to the whole week, all year.
                    <?php endif; ?>
                </div>
                <?php if ($editingOther): ?><a href="my_schedule.php" class="back-link">&larr; All doctors</a><?php endif; ?>
            </div>

            <form method="POST" action="my_schedule.php<?= $canActOnOthers ? '?doctor_id=' . $doctorId : '' ?>">
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

            <?php endif; ?>

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
