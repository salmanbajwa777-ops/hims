<?php
// One-off timezone repair + verification tool. Visit in a browser as an admin:
//     https://hims.babymedics.com/fix_timezone.php
//
// It does three things:
//   1. Patches config/db.php on disk so the live copy carries the PKT settings
//      (that file is gitignored, so deploys never update it).
//   2. Reports what PHP and MySQL now think the time is, so you can eyeball it.
//   3. Offers to shift the DATETIME columns that are genuinely stranded in the
//      old zone -- and ONLY those.
//
// Why not shift everything: TIMESTAMP columns (bills.created_at, paid_at,
// printed_at, refunds.*, every other created_at) are stored by MySQL as UTC and
// converted on read using the session time_zone. Setting time_zone = '+05:00'
// already fixes their display. Adding 5 hours to them would double-correct and
// corrupt real data. Only DATETIME columns -- visits.started_at and
// visits.finished_at -- store a literal wall clock with no conversion, so only
// those can be stuck.
//
// DELETE THIS FILE once you're done. It is guarded to admins but it patches
// source on disk and rewrites rows; it has no business staying on a live server.

require_once __DIR__ . '/config/guard_admin.php';

$PKT = '+05:00';
$dbFile = __DIR__ . '/config/db.php';
$notices = [];

// ---------------------------------------------------------------- 1. patch db.php
$dbPatch = null;
if (!is_readable($dbFile)) {
    $dbPatch = ['ok' => false, 'msg' => 'config/db.php not found or unreadable.'];
} else {
    $src = file_get_contents($dbFile);
    $hasTz  = strpos($src, 'date_default_timezone_set') !== false;
    $hasSet = strpos($src, 'time_zone') !== false;

    if ($hasTz && $hasSet) {
        $dbPatch = ['ok' => true, 'msg' => 'Already patched - no change needed.'];
    } elseif (!is_writable($dbFile)) {
        $dbPatch = ['ok' => false, 'msg' => 'config/db.php is not writable. Patch it by hand (see the snippet below).'];
    } else {
        copy($dbFile, $dbFile . '.bak-' . date('Ymd-His'));
        $new = $src;
        if (!$hasTz) {
            $new = preg_replace(
                '/^<\?php\s*/',
                "<?php\n// All dates/times in HMIS are Pakistan Standard Time (UTC+5).\ndate_default_timezone_set('Asia/Karachi');\n\n",
                $new,
                1
            );
        }
        // Attach the SET immediately after the PDO constructor's closing "]);".
        if (!$hasSet && preg_match('/new PDO\(.*?\]\s*\);/s', $new, $m)) {
            $inject = $m[0]
                . "\n    // Keep MySQL NOW()/CURDATE()/CURRENT_TIMESTAMP on PKT too. Numeric offset,"
                . "\n    // not 'Asia/Karachi' - named zones need the mysql.time_zone tables loaded,"
                . "\n    // which shared hosting usually lacks. Pakistan has no DST, so +05:00 holds."
                . "\n    \$pdo->exec(\"SET time_zone = '{$PKT}'\");";
            $new = str_replace($m[0], $inject, $new);
        }
        if ($new !== $src && file_put_contents($dbFile, $new) !== false) {
            $dbPatch = ['ok' => true, 'msg' => 'Patched config/db.php (a .bak copy was saved alongside it).'];
        } else {
            $dbPatch = ['ok' => false, 'msg' => 'Could not rewrite config/db.php automatically - patch it by hand.'];
        }
    }
}

// ---------------------------------------------------------------- 2. diagnose
// This request may have loaded db.php before the patch above landed, so force
// the session zone here to report what a *post-patch* request will see.
$pdo->exec("SET time_zone = '{$PKT}'");

$diag = $pdo->query('SELECT @@session.time_zone AS sess, @@global.time_zone AS glob, NOW() AS mysql_now, UTC_TIMESTAMP() AS mysql_utc')->fetch();
$phpNow  = date('Y-m-d H:i:s');
$phpZone = date_default_timezone_get();
$skew    = abs(strtotime($phpNow) - strtotime($diag['mysql_now']));

// How far off are the DATETIME columns? Compare a visit's DATETIME started_at
// against its own TIMESTAMP created_at -- same event, ~seconds apart in reality,
// so any large gap is pure zone drift.
$drift = $pdo->query("
    SELECT COUNT(*) AS n,
           AVG(TIMESTAMPDIFF(MINUTE, started_at, created_at)) AS avg_min
    FROM visits
    WHERE started_at IS NOT NULL
")->fetch();
$driftHours = ($drift['n'] > 0) ? round($drift['avg_min'] / 60) : 0;

// Same probe for admissions: admitted_at is DATETIME, created_at is TIMESTAMP,
// both written in the same INSERT -- any gap between them is pure zone drift.
// Rows are judged individually (not by table average) because some may have
// been written before the db.php patch and some after. The other DATETIME
// columns on a stranded row (assigned_at, discharged_at, discharge_finalized_at)
// were written by the same misconfigured session, so they get the same offset;
// they have no TIMESTAMP twin of their own to measure against.
$admStranded = [];  // id => hours to ADD to the row's DATETIME columns
foreach ($pdo->query("SELECT id, TIMESTAMPDIFF(MINUTE, admitted_at, created_at) AS drift_min FROM admissions") as $r) {
    $h = (int) round(($r['drift_min'] ?? 0) / 60);
    if ($h !== 0) { $admStranded[(int) $r['id']] = $h; }
}

// ---------------------------------------------------------------- 3b. shift stranded admissions
// Per-row correction: each stranded row gets ITS OWN measured offset applied to
// every DATETIME column it carries. Re-running is safe -- once shifted, the
// admitted_at/created_at gap is zero and the row no longer appears stranded.
$admShiftResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'shift_admissions') {
    if (!$admStranded) {
        $admShiftResult = ['ok' => false, 'msg' => 'No stranded admission rows to shift.'];
    } else {
        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("
                UPDATE admissions SET
                    admitted_at             = admitted_at + INTERVAL ? HOUR,
                    assigned_at             = CASE WHEN assigned_at             IS NULL THEN NULL ELSE assigned_at             + INTERVAL ? HOUR END,
                    discharged_at           = CASE WHEN discharged_at           IS NULL THEN NULL ELSE discharged_at           + INTERVAL ? HOUR END,
                    discharge_finalized_at  = CASE WHEN discharge_finalized_at  IS NULL THEN NULL ELSE discharge_finalized_at  + INTERVAL ? HOUR END
                WHERE id = ?
            ");
            // visits.admitted_at mirrors the admission row; fix it with the same offset.
            $updVisit = $pdo->prepare("
                UPDATE visits v JOIN admissions a ON a.visit_id = v.id
                SET v.admitted_at = CASE WHEN v.admitted_at IS NULL THEN NULL ELSE v.admitted_at + INTERVAL ? HOUR END
                WHERE a.id = ?
            ");
            foreach ($admStranded as $id => $h) {
                $upd->execute([$h, $h, $h, $h, $id]);
                $updVisit->execute([$h, $id]);
            }
            $pdo->commit();
            $admShiftResult = ['ok' => true, 'msg' => 'Corrected ' . count($admStranded) . ' admission row(s), each by its own measured offset. Reload to re-verify.'];
            $admStranded = [];
        } catch (Throwable $e) {
            $pdo->rollBack();
            $admShiftResult = ['ok' => false, 'msg' => 'Shift failed, rolled back: ' . $e->getMessage()];
        }
    }
}

// ---------------------------------------------------------------- 3. shift DATETIMEs
$shiftResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'shift') {
    $hours = (int) ($_POST['hours'] ?? 0);
    if ($hours === 0) {
        $shiftResult = ['ok' => false, 'msg' => 'No shift applied (offset was zero).'];
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                UPDATE visits SET
                    started_at  = CASE WHEN started_at  IS NULL THEN NULL ELSE started_at  + INTERVAL ? HOUR END,
                    finished_at = CASE WHEN finished_at IS NULL THEN NULL ELSE finished_at + INTERVAL ? HOUR END
                WHERE started_at IS NOT NULL OR finished_at IS NOT NULL
            ");
            $stmt->execute([$hours, $hours]);
            $n = $stmt->rowCount();
            $pdo->commit();
            $shiftResult = ['ok' => true, 'msg' => "Shifted {$n} visit row(s) by {$hours} hour(s). Do not run this again."];
        } catch (Throwable $e) {
            $pdo->rollBack();
            $shiftResult = ['ok' => false, 'msg' => 'Shift failed, rolled back: ' . $e->getMessage()];
        }
    }
}
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>HMIS timezone repair</title>
<style>
  body { font: 15px/1.6 system-ui, sans-serif; max-width: 760px; margin: 40px auto; padding: 0 20px; color: #1a2b4a; }
  h1 { font-size: 22px; margin-bottom: 4px; }
  .sub { color: #667; margin-top: 0; }
  .card { border: 1px solid #dde3ee; border-radius: 10px; padding: 16px 18px; margin: 16px 0; }
  .ok { border-left: 4px solid #1a9d5a; }
  .bad { border-left: 4px solid #c8442c; }
  .warn { border-left: 4px solid #d99b1a; }
  table { border-collapse: collapse; width: 100%; font-size: 14px; }
  td { padding: 5px 8px; border-bottom: 1px solid #eef1f7; }
  td:first-child { color: #667; width: 45%; }
  code, .mono { font-family: ui-monospace, Menlo, Consolas, monospace; }
  pre { background: #f6f8fc; padding: 12px; border-radius: 8px; overflow-x: auto; font-size: 13px; }
  button { background: #c8442c; color: #fff; border: 0; padding: 10px 16px; border-radius: 7px; font-size: 15px; cursor: pointer; }
</style>

<h1>HMIS timezone repair</h1>
<p class="sub">Pakistan Standard Time (UTC+5) &middot; run once, then delete this file.</p>

<div class="card <?= $dbPatch['ok'] ? 'ok' : 'bad' ?>">
  <strong>1. config/db.php</strong><br><?= htmlspecialchars($dbPatch['msg']) ?>
  <?php if (!$dbPatch['ok']): ?>
    <pre>date_default_timezone_set('Asia/Karachi');   // at the top, after &lt;?php

$pdo-&gt;exec("SET time_zone = '<?= $PKT ?>'");        // right after the PDO constructor</pre>
  <?php endif; ?>
</div>

<div class="card <?= $skew <= 120 ? 'ok' : 'bad' ?>">
  <strong>2. Clocks</strong>
  <table>
    <tr><td>PHP timezone</td><td class="mono"><?= htmlspecialchars($phpZone) ?></td></tr>
    <tr><td>PHP now</td><td class="mono"><?= htmlspecialchars($phpNow) ?></td></tr>
    <tr><td>MySQL session zone</td><td class="mono"><?= htmlspecialchars($diag['sess']) ?></td></tr>
    <tr><td>MySQL global zone</td><td class="mono"><?= htmlspecialchars($diag['glob']) ?></td></tr>
    <tr><td>MySQL now</td><td class="mono"><?= htmlspecialchars($diag['mysql_now']) ?></td></tr>
    <tr><td>PHP vs MySQL skew</td><td class="mono"><?= $skew ?>s</td></tr>
  </table>
  <p style="margin-bottom:0">
    <?= $skew <= 120
        ? 'PHP and MySQL agree. Compare "PHP now" to your wall clock &mdash; it should match.'
        : 'PHP and MySQL still disagree. Confirm step 1 actually applied, then reload.' ?>
  </p>
</div>

<div class="card <?= $shiftResult ? ($shiftResult['ok'] ? 'ok' : 'bad') : ($driftHours === 0 ? 'ok' : 'warn') ?>">
  <strong>3. Historic <code>visits.started_at</code> / <code>finished_at</code></strong>
  <p>
    These two are <code>DATETIME</code>, so MySQL never zone-converts them &mdash; they are the only
    columns that can be stranded. Every other timestamp in HMIS is <code>TIMESTAMP</code>, stored
    as UTC and converted on read, so step 1 already fixed those. <em>They must not be shifted.</em>
  </p>
  <?php if ($shiftResult): ?>
    <p><strong><?= htmlspecialchars($shiftResult['msg']) ?></strong></p>
  <?php elseif ($drift['n'] == 0): ?>
    <p>No consultations have been started yet &mdash; nothing to repair.</p>
  <?php elseif ($driftHours === 0): ?>
    <p>Measured drift across <?= (int) $drift['n'] ?> row(s): <strong>none</strong>. Nothing to repair.</p>
  <?php else: ?>
    <p>
      Measured drift across <?= (int) $drift['n'] ?> row(s): <strong><?= $driftHours ?> hour(s)</strong>
      behind their own <code>created_at</code>. Shifting corrects that.
    </p>
    <form method="post" onsubmit="return confirm('Shift <?= $driftHours ?>h? Run once only - a second run doubles the error.')">
      <input type="hidden" name="action" value="shift">
      <input type="hidden" name="hours" value="<?= $driftHours ?>">
      <button type="submit">Shift <?= (int) $drift['n'] ?> row(s) by <?= $driftHours ?>h</button>
    </form>
  <?php endif; ?>
</div>

<div class="card <?= $admShiftResult ? ($admShiftResult['ok'] ? 'ok' : 'bad') : (!$admStranded ? 'ok' : 'warn') ?>">
  <strong>3b. Stranded <code>admissions</code> rows (admitted / discharged times)</strong>
  <p>
    <code>admitted_at</code>, <code>assigned_at</code>, <code>discharged_at</code> and
    <code>discharge_finalized_at</code> are <code>DATETIME</code> &mdash; a row written while MySQL
    was still on UTC is permanently ~5h behind, which is what makes a 10-minute stay
    display as 5 hours. Each row is measured against its own <code>created_at</code>
    (<code>TIMESTAMP</code>, self-correcting), so only genuinely stranded rows are touched,
    each by its own offset. <code>visits.admitted_at</code> is corrected alongside.
  </p>
  <?php if ($admShiftResult): ?>
    <p><strong><?= htmlspecialchars($admShiftResult['msg']) ?></strong></p>
  <?php elseif (!$admStranded): ?>
    <p>All admission rows agree with their own <code>created_at</code> &mdash; nothing to repair.</p>
  <?php else: ?>
    <p>
      <strong><?= count($admStranded) ?> stranded row(s):</strong>
      <?php $parts = []; foreach ($admStranded as $id => $h) { $parts[] = "#{$id} (" . ($h > 0 ? "+{$h}h" : "{$h}h") . ")"; } echo htmlspecialchars(implode(', ', $parts)); ?>
    </p>
    <form method="post" onsubmit="return confirm('Correct <?= count($admStranded) ?> admission row(s)? Safe to re-run; already-correct rows are never touched.')">
      <input type="hidden" name="action" value="shift_admissions">
      <button type="submit">Correct <?= count($admStranded) ?> admission row(s)</button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <strong>4. Finally</strong><br>
  Delete <code>fix_timezone.php</code> from the server (and any <code>config/db.php.bak-*</code> once you're happy).
</div>
