<?php
/**
 * Follow-up Pricing — admin settings for the revisit engine (config/billing.php).
 *
 * One row of rates: how long the free window is, when follow-up pricing stops
 * altogether, and the discount for each tier. Changes affect FUTURE visits only —
 * every visit snapshots the discount it was billed at.
 */
require_once __DIR__ . '/config/guard_admin.php';
require_once __DIR__ . '/config/billing.php';

$error = '';
$success = '';

$tableReady = true;
try { $pdo->query('SELECT 1 FROM revisit_policy LIMIT 1'); } catch (Throwable $e) { $tableReady = false; }

if ($tableReady && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_policy') {
    $pct = fn($v) => round(min(100, max(0, (float) $v)), 2);
    $free = (int) ($_POST['free_days'] ?? 0);
    $max  = (int) ($_POST['max_days'] ?? 0);
    if ($free < 1 || $max < $free || $max > 365) {
        $error = 'Free window must be at least 1 day, and the follow-up limit must be the same or longer (max 365).';
    } else {
        $first = $pct($_POST['first_discount_pct'] ?? 0);
        $repeat = $pct($_POST['repeat_discount_pct'] ?? 0);
        $late = $pct($_POST['late_discount_pct'] ?? 0);
        $cross = $pct($_POST['cross_discount_pct'] ?? 0);
        $pdo->prepare('UPDATE revisit_policy SET free_days = ?, max_days = ?, first_discount_pct = ?, repeat_discount_pct = ?, late_discount_pct = ?, cross_discount_pct = ?, updated_by_id = ? WHERE id = 1')
            ->execute([$free, $max, $first, $repeat, $late, $cross, $_SESSION['user_id']]);
        audit_log($pdo, 'revisit_policy_saved', "Follow-up pricing set: free {$free}d / limit {$max}d, 1st {$first}% off, repeat {$repeat}%, late {$late}%, other-doctor {$cross}%", $_SESSION['user_id']);
        $success = 'Saved. New pricing applies to future visits only.';
    }
}

$pol = revisit_policy($pdo);
$crossReady = revisit_has_cross_type($pdo);
$dis = $tableReady ? '' : 'disabled';

$pageTitle = 'Follow-up Pricing';
$headExtra = <<<CSS
<style>
.pol-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
.pol-grid label { font-size: 11.5px; font-weight: 600; color: var(--text-secondary); display: block; margin-bottom: 5px; }
.pol-grid input { width: 100%; padding: 9px 11px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 13.5px; background: var(--bg); }
.pol-grid .hint { font-size: 11.5px; color: var(--text-muted); margin-top: 4px; }
.note-box { font-size: 12.5px; color: var(--text-secondary); background: var(--primary-light); border-radius: 10px; padding: 12px 16px; margin-bottom: 18px; line-height: 1.6; }
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'revisit_policy';
require __DIR__ . '/partials/sidebar.php';
?>
        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Follow-up Pricing</div>
                    <div class="page-sub">How returning patients are charged — applies to consultation types flagged as revisit-eligible</div>
                </div>
            </div>

            <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
            <?php if (!$tableReady): ?>
                <div class="alert error">Run <b>sql/add_revisit_policy.sql</b> in phpMyAdmin first — until then the built-in defaults (5 / 15 days, free / 50% / 75%) are in force and this page is read-only.</div>
            <?php elseif (!$crossReady): ?>
                <div class="alert error">The fee-type column has not been widened yet (last step of <b>sql/add_revisit_policy.sql</b>). Other-doctor visits are saved as ordinary repeat follow-ups until it runs.</div>
            <?php endif; ?>

            <div class="note-box">
                The window is counted from the patient's last <b>full-fee</b> visit with the same doctor and consultation type.
                A patient who paid in full to one doctor and, inside the free window, sees a <b>different</b> doctor pays the
                "other-doctor" rate of that doctor's fee — no category or other discount stacks on it.
                Discounts are percentages <i>off</i> (100 = free). Past visits keep the price they were billed at.
            </div>

            <div class="card">
                <form method="POST" action="revisit_policy.php">
                    <input type="hidden" name="action" value="save_policy">
                    <div class="pol-grid">
                        <div><label>Free window (days)</label>
                            <input type="number" min="1" max="365" name="free_days" value="<?= (int) $pol['free_days'] ?>" <?= $dis ?>>
                            <div class="hint">1st / repeat / other-doctor rates apply inside this.</div></div>
                        <div><label>Follow-up limit (days)</label>
                            <input type="number" min="1" max="365" name="max_days" value="<?= (int) $pol['max_days'] ?>" <?= $dis ?>>
                            <div class="hint">After this the visit is full fee again.</div></div>
                        <div><label>1st follow-up — % off</label>
                            <input type="number" step="0.5" min="0" max="100" name="first_discount_pct" value="<?= htmlspecialchars((string) $pol['first_discount_pct']) ?>" <?= $dis ?>>
                            <div class="hint">100 = free.</div></div>
                        <div><label>Repeat follow-up — % off</label>
                            <input type="number" step="0.5" min="0" max="100" name="repeat_discount_pct" value="<?= htmlspecialchars((string) $pol['repeat_discount_pct']) ?>" <?= $dis ?>>
                            <div class="hint">2nd+ visit inside the free window.</div></div>
                        <div><label>Late follow-up — % off</label>
                            <input type="number" step="0.5" min="0" max="100" name="late_discount_pct" value="<?= htmlspecialchars((string) $pol['late_discount_pct']) ?>" <?= $dis ?>>
                            <div class="hint">After the free window, up to the limit.</div></div>
                        <div><label>Other-doctor — % off</label>
                            <input type="number" step="0.5" min="0" max="100" name="cross_discount_pct" value="<?= htmlspecialchars((string) $pol['cross_discount_pct']) ?>" <?= $dis ?>>
                            <div class="hint">Seen by a different doctor inside the free window.</div></div>
                    </div>
                    <?php if ($tableReady): ?>
                    <div style="display:flex;justify-content:flex-end;margin-top:16px;"><button type="submit" class="btn">Save pricing</button></div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>
