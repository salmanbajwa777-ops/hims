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

<div class="card">
  <strong>4. Finally</strong><br>
  Delete <code>fix_timezone.php</code> from the server (and any <code>config/db.php.bak-*</code> once you're happy).
</div>
