<?php
// Day closing & cash handover — reception side (approved mock 2026-07-23).
//
// Live tally of today's collections (consultation bills + admission bills +
// cash refunds), a denomination counter for the physical drawer count, the
// declared handover amount, then submit → the day LOCKS, the A5 closing slip
// (?print=1) opens for signatures, and the handover queues for admin in
// admin_handovers.php.
//
// Requires sql/add_shift_closings.sql to be applied.

require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/billing.php';
require_once __DIR__ . '/config/notify.php';
refresh_session_permissions($pdo);
require_permission('RECEPTION_CLOSE_DAY');

$error = '';
$today = date('Y-m-d');

// PKR note faces, largest first. face_value 1 is the "Coins" line — its qty is
// entered as a rupee amount, not a piece count.
$DENOMS = [5000, 1000, 500, 100, 50, 20, 10, 1];

// ---------------- Print view (A5 closing slip) ----------------
if (isset($_GET['print']) && isset($_GET['closing_id'])) {
    $closingId = (int) $_GET['closing_id'];

    $stmt = $pdo->prepare('
        SELECT c.*, cu.name AS cashier_name, au.name AS admin_name
        FROM shift_closings c
        JOIN users cu ON cu.id = c.cashier_id
        JOIN users au ON au.id = c.handover_to_id
        WHERE c.id = ?
    ');
    $stmt->execute([$closingId]);
    $closing = $stmt->fetch();

    if (!$closing) {
        http_response_code(404);
        die('Closing slip not found.');
    }

    $denomStmt = $pdo->prepare('SELECT face_value, qty, amount FROM shift_closing_denominations WHERE closing_id = ? ORDER BY face_value DESC');
    $denomStmt->execute([$closingId]);
    $denominations = $denomStmt->fetchAll();

    $pdo->prepare('UPDATE shift_closings SET printed_at = NOW() WHERE id = ?')->execute([$closingId]);

    include __DIR__ . '/views/closing_print_partial.php';
    exit;
}

// ---------------- Submit closing ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close_day') {
    $handoverToId = (int) ($_POST['handover_to_id'] ?? 0);
    $handoverDeclared = round((float) str_replace(',', '', $_POST['handover_declared'] ?? '0'), 2);
    $varianceNote = trim($_POST['variance_note'] ?? '');

    try {
        $pdo->beginTransaction();

        if (day_closing($pdo, $today)) {
            $error = 'Today has already been closed.';
        } elseif ($handoverToId <= 0) {
            $error = 'Pick the admin you are handing the cash to.';
        } elseif ($handoverDeclared < 0) {
            $error = 'The handover amount cannot be negative.';
        } else {
            // Rebuild the count server-side from the posted quantities — the
            // client-side totals are display sugar only.
            $counted = 0.0;
            $denomRows = [];
            foreach ($DENOMS as $face) {
                $qty = max(0, (int) ($_POST['denom'][$face] ?? 0));
                $amount = $face === 1 ? (float) $qty : (float) ($face * $qty);
                if ($qty > 0) {
                    $denomRows[] = [$face, $qty, $amount];
                }
                $counted += $amount;
            }
            $counted = round($counted, 2);

            $tally = day_cash_tally($pdo, $today);
            $variance = round($counted - $tally['expected_cash'], 2);
            $floatRetained = min($tally['opening_float'], $counted);

            if (abs($variance) > 0.009 && $varianceNote === '') {
                $error = 'The count is ' . ($variance < 0 ? 'short' : 'over') . ' by Rs '
                       . number_format(abs($variance), 2) . ' — a variance note is required.';
            } elseif ($handoverDeclared > $counted) {
                $error = 'You cannot hand over more than the Rs ' . number_format($counted, 2) . ' counted.';
            } else {
                $closingNumber = generate_closing_number($pdo);

                $pdo->prepare('
                    INSERT INTO shift_closings
                        (closing_number, closing_date, cashier_id, opening_float,
                         cash_consult_total, cash_consult_count,
                         cash_admission_total, cash_admission_count,
                         online_total, online_count,
                         cash_refund_total, cash_refund_count,
                         expected_cash, counted_cash, variance, variance_note,
                         float_retained, handover_declared, handover_to_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ')->execute([
                    $closingNumber, $today, $_SESSION['user_id'], $tally['opening_float'],
                    $tally['cash_consult_total'], $tally['cash_consult_count'],
                    $tally['cash_admission_total'], $tally['cash_admission_count'],
                    $tally['online_total'], $tally['online_count'],
                    $tally['cash_refund_total'], $tally['cash_refund_count'],
                    $tally['expected_cash'], $counted, $variance, $varianceNote ?: null,
                    $floatRetained, $handoverDeclared, $handoverToId,
                ]);
                $closingId = (int) $pdo->lastInsertId();

                $denomIns = $pdo->prepare('
                    INSERT INTO shift_closing_denominations (closing_id, face_value, qty, amount)
                    VALUES (?, ?, ?, ?)
                ');
                foreach ($denomRows as [$face, $qty, $amount]) {
                    $denomIns->execute([$closingId, $face, $qty, $amount]);
                }

                $log = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)');
                $log->execute([
                    $_SESSION['user_id'],
                    'day_closed',
                    "Closing $closingNumber for $today: counted Rs " . number_format($counted, 2)
                    . ' vs expected Rs ' . number_format($tally['expected_cash'], 2)
                    . ' (variance ' . number_format($variance, 2) . '), handover declared Rs '
                    . number_format($handoverDeclared, 2),
                ]);

                $pdo->commit();

                // Summary to admin (best-effort, after commit).
                notify_day_closed($pdo, $closingId);

                header('Location: shift_closing.php?print=1&closing_id=' . $closingId);
                exit;
            }
        }

        $pdo->rollBack();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Could not close the day. Please try again.';
    }
}

// ---------------- Page data ----------------
$closing = null;
$tally = null;
try {
    $closing = day_closing($pdo, $today);
    $tally = day_cash_tally($pdo, $today);
} catch (PDOException $e) {
    http_response_code(500);
    die('Day-closing tables missing — run sql/add_shift_closings.sql first.');
}

$adminsStmt = $pdo->query("SELECT id, name FROM users WHERE base_role = 'ADMIN' ORDER BY name");
$admins = $adminsStmt->fetchAll();

$suggestedHandover = max(0, round($tally['expected_cash'] - $tally['opening_float'], 2));

$pageTitle = 'Day Closing';
require __DIR__ . '/partials/head.php';
$navActive = 'shift_closing';
require __DIR__ . '/partials/sidebar.php';
?>
<style>
.close-grid { display: grid; grid-template-columns: 1.1fr .9fr; gap: 22px; align-items: start; }
@media (max-width: 980px) { .close-grid { grid-template-columns: 1fr; } }
.close-col { display: flex; flex-direction: column; gap: 22px; }

.tiles { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
@media (max-width: 860px) { .tiles { grid-template-columns: repeat(2, 1fr); } }
.tile { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-card); padding: 16px 18px; box-shadow: var(--shadow-sm); }
.tile .lbl { font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--text-muted); }
.tile .val { font-size: 22px; font-weight: 700; letter-spacing: -.02em; margin-top: 4px; font-variant-numeric: tabular-nums; }
.tile .hint { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.tile.hero { background: var(--primary-dark); border-color: var(--primary-dark); }
.tile.hero .lbl { color: #9BD4CF; } .tile.hero .val { color: #fff; } .tile.hero .hint { color: #7FB8B3; }

.ttable { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
.ttable th { text-align: left; font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--text-muted); padding: 7px 10px; border-bottom: 1px solid var(--border); }
.ttable td { padding: 8px 10px; border-bottom: 1px solid var(--border); font-size: 13.5px; }
.ttable tr:last-child td { border-bottom: none; }
.ttable td.num, .ttable th.num { text-align: right; white-space: nowrap; }
.ttable td.neg { color: var(--red); }
.ttable tr.total td { font-weight: 700; border-top: 2px solid var(--border-strong); background: var(--bg); }
.ttable tr.grand td { font-weight: 700; background: var(--primary-light); }
.ttable tr.section td { font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--text-muted); background: var(--bg); padding: 5px 10px; }
.count-chip { display: inline-block; min-width: 24px; text-align: center; background: var(--bg); border: 1px solid var(--border); border-radius: 999px; font-size: 12px; font-weight: 600; padding: 0 8px; color: var(--text-secondary); }

.denom-row { display: grid; grid-template-columns: 88px 1fr 110px; gap: 12px; align-items: center; padding: 6px 0; border-bottom: 1px dashed var(--border); }
.denom-row:last-of-type { border-bottom: none; }
.note-face { font-weight: 700; font-size: 13.5px; font-variant-numeric: tabular-nums; background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 4px 0; text-align: center; color: var(--text-secondary); }
.denom-qty { width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: var(--radius-input); font: inherit; font-size: 13.5px; background: var(--bg); color: var(--text); text-align: center; }
.denom-qty:focus { outline: 2px solid var(--primary); outline-offset: 1px; border-color: var(--primary); }
.denom-amt { text-align: right; font-weight: 600; font-variant-numeric: tabular-nums; font-size: 13.5px; }
.denom-total { display: flex; justify-content: space-between; align-items: baseline; margin-top: 14px; padding-top: 14px; border-top: 2px solid var(--border-strong); }
.denom-total .v { font-size: 20px; font-weight: 700; font-variant-numeric: tabular-nums; }

.variance-strip { display: flex; justify-content: space-between; align-items: center; border-radius: var(--radius-input); padding: 11px 15px; margin-top: 14px; font-weight: 600; font-size: 13.5px; }
.variance-strip.ok { background: var(--green-bg); color: var(--green-text); }
.variance-strip.short { background: var(--red-bg); color: var(--red-text); }
.variance-strip.over { background: var(--amber-bg); color: var(--amber-text); }
.variance-strip .amt { font-size: 16px; font-weight: 700; font-variant-numeric: tabular-nums; }

.cfield { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
.cfield label { font-size: 12px; font-weight: 600; color: var(--text-secondary); }
.cfield input, .cfield select, .cfield textarea { padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-input); font: inherit; font-size: 13.5px; background: var(--bg); color: var(--text); }
.cfield textarea { resize: vertical; min-height: 56px; }
.cfield input:focus, .cfield select:focus, .cfield textarea:focus { outline: 2px solid var(--primary); outline-offset: 1px; border-color: var(--primary); }

.close-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: none; border-radius: var(--radius-btn); font: inherit; font-weight: 600; font-size: 14px; padding: 12px 22px; cursor: pointer; background: var(--primary-dark); color: #fff; width: 100%; }
.close-btn:hover { background: var(--primary); }
.close-btn[disabled] { opacity: .55; cursor: not-allowed; }
.lock-note { font-size: 12px; color: var(--text-muted); text-align: center; margin-top: 10px; }
.alert-error { background: var(--red-bg); color: var(--red-text); border-radius: var(--radius-input); padding: 12px 16px; font-size: 13px; font-weight: 500; }
.closed-box { background: var(--green-bg); color: var(--green-text); border-radius: var(--radius-card); padding: 20px 22px; }
.closed-box b { font-size: 15px; }
.closed-box .row { margin-top: 6px; font-size: 13.5px; }
.closed-actions { margin-top: 14px; display: flex; gap: 10px; flex-wrap: wrap; }
.closed-actions a { display: inline-flex; align-items: center; padding: 9px 16px; border-radius: var(--radius-btn); font-weight: 600; font-size: 13px; background: var(--card); color: var(--text); border: 1px solid var(--border); }
</style>

<div class="content">

    <div class="page-head">
        <h1>Day Closing — <?= date('D d M Y') ?></h1>
        <p>Count the drawer against the system tally, declare the cash handover, and lock the day.</p>
    </div>

    <?php if ($error): ?>
        <div class="alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($closing): ?>

        <div class="closed-box">
            <b>Today is closed — slip <?= htmlspecialchars($closing['closing_number']) ?></b>
            <div class="row">
                Counted Rs <?= number_format((float) $closing['counted_cash'], 2) ?>
                vs expected Rs <?= number_format((float) $closing['expected_cash'], 2) ?>
                (variance <?= number_format((float) $closing['variance'], 2) ?>)
                &middot; handover declared Rs <?= number_format((float) $closing['handover_declared'], 2) ?>
                &middot; status: <?= $closing['status'] === 'RECEIVED' ? 'received by admin' : 'awaiting admin receipt' ?>
            </div>
            <div class="closed-actions">
                <a href="shift_closing.php?print=1&closing_id=<?= (int) $closing['id'] ?>" target="_blank">Reprint A5 closing slip</a>
            </div>
        </div>

    <?php else: ?>

    <div class="tiles">
        <div class="tile">
            <div class="lbl">Total collected</div>
            <div class="val">Rs <?= number_format($tally['net_collected'], 0) ?></div>
            <div class="hint"><?= $tally['cash_count'] + $tally['online_count'] ?> payments today</div>
        </div>
        <div class="tile">
            <div class="lbl">Cash</div>
            <div class="val">Rs <?= number_format($tally['cash_total'] - $tally['cash_refund_total'], 0) ?></div>
            <div class="hint"><?= $tally['cash_count'] ?> payments<?= $tally['cash_refund_count'] ? ' − ' . $tally['cash_refund_count'] . ' refund' . ($tally['cash_refund_count'] > 1 ? 's' : '') : '' ?></div>
        </div>
        <div class="tile">
            <div class="lbl">Online</div>
            <div class="val">Rs <?= number_format($tally['online_total'], 0) ?></div>
            <div class="hint"><?= $tally['online_count'] ?> payments · bank a/c</div>
        </div>
        <div class="tile hero">
            <div class="lbl">Expected cash in drawer</div>
            <div class="val">Rs <?= number_format($tally['expected_cash'], 0) ?></div>
            <div class="hint">Float <?= number_format($tally['opening_float'], 0) ?> + cash <?= number_format($tally['cash_total'], 0) ?> − refunds <?= number_format($tally['cash_refund_total'], 0) ?></div>
        </div>
    </div>

    <form method="POST" action="shift_closing.php" id="closeForm">
    <input type="hidden" name="action" value="close_day">

    <div class="close-grid">

        <!-- ==== LEFT: system-side ledger ==== -->
        <div class="close-col">

            <div class="card">
                <h2 style="font-size:15px;font-weight:700;margin-bottom:4px;">Collections by method</h2>
                <p style="font-size:12.5px;color:var(--text-muted);margin-bottom:14px;">Consultation invoices and admission bills paid today. System figures — nothing to enter here.</p>
                <div style="overflow-x:auto;">
                <table class="ttable">
                    <tr class="section"><td colspan="3">Money in</td></tr>
                    <tr>
                        <td>Cash <span class="count-chip"><?= $tally['cash_count'] ?></span></td>
                        <td class="num" style="color:var(--text-muted)">consult <?= $tally['cash_consult_count'] ?> · admission <?= $tally['cash_admission_count'] ?></td>
                        <td class="num"><?= number_format($tally['cash_total'], 2) ?></td>
                    </tr>
                    <tr>
                        <td>Online <span class="count-chip"><?= $tally['online_count'] ?></span></td>
                        <td class="num" style="color:var(--text-muted)">consult <?= $tally['online_consult_count'] ?> · admission <?= $tally['online_admission_count'] ?></td>
                        <td class="num"><?= number_format($tally['online_total'], 2) ?></td>
                    </tr>
                    <tr class="section"><td colspan="3">Money out</td></tr>
                    <tr>
                        <td>Refunds — cash <span class="count-chip"><?= $tally['cash_refund_count'] ?></span></td>
                        <td class="num" style="color:var(--text-muted)"><?= $tally['cash_refund_count'] ? 'paid from the drawer' : 'none today' ?></td>
                        <td class="num neg"><?= $tally['cash_refund_total'] > 0 ? '− ' . number_format($tally['cash_refund_total'], 2) : '0.00' ?></td>
                    </tr>
                    <tr class="total">
                        <td colspan="2">Net collected today</td>
                        <td class="num">Rs <?= number_format($tally['net_collected'], 2) ?></td>
                    </tr>
                </table>
                </div>
            </div>

            <div class="card">
                <h2 style="font-size:15px;font-weight:700;margin-bottom:4px;">Cash drawer math</h2>
                <p style="font-size:12.5px;color:var(--text-muted);margin-bottom:14px;">What should physically be in the drawer right now.</p>
                <div style="overflow-x:auto;">
                <table class="ttable">
                    <tr><td>Opening float (carried in)</td><td class="num"><?= number_format($tally['opening_float'], 2) ?></td></tr>
                    <tr><td>+ Cash payments received</td><td class="num"><?= number_format($tally['cash_total'], 2) ?></td></tr>
                    <tr><td>− Cash refunds paid out</td><td class="num neg"><?= $tally['cash_refund_total'] > 0 ? '− ' . number_format($tally['cash_refund_total'], 2) : '0.00' ?></td></tr>
                    <tr class="grand"><td>Expected cash in drawer</td><td class="num">Rs <?= number_format($tally['expected_cash'], 2) ?></td></tr>
                </table>
                </div>
            </div>

            <div class="card">
                <h2 style="font-size:15px;font-weight:700;margin-bottom:4px;">Online — verify, don't count</h2>
                <p style="font-size:12.5px;color:var(--text-muted);margin-bottom:0;">
                    Match today's <b><?= $tally['online_count'] ?></b> online payment<?= $tally['online_count'] === 1 ? '' : 's' ?>
                    totalling <b>Rs <?= number_format($tally['online_total'], 2) ?></b> against the bank app before closing.
                    Online money never touches the drawer.
                </p>
            </div>

        </div>

        <!-- ==== RIGHT: physical count + handover ==== -->
        <div class="close-col">

            <div class="card">
                <h2 style="font-size:15px;font-weight:700;margin-bottom:4px;">Physical cash count</h2>
                <p style="font-size:12.5px;color:var(--text-muted);margin-bottom:14px;">Count the drawer by denomination — totals compute themselves. "Coins" takes a rupee amount.</p>
                <div>
                    <?php foreach ($DENOMS as $face): ?>
                    <div class="denom-row">
                        <span class="note-face"><?= $face === 1 ? 'Coins' : number_format($face) ?></span>
                        <input class="denom-qty" type="number" min="0" step="1" name="denom[<?= $face ?>]" value="0"
                               data-face="<?= $face ?>" inputmode="numeric"
                               aria-label="<?= $face === 1 ? 'Coins total in rupees' : 'Number of ' . $face . ' notes' ?>">
                        <span class="denom-amt" data-amt="<?= $face ?>">0</span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="denom-total">
                    <span style="font-weight:700;">Counted cash</span>
                    <span class="v">Rs <span id="countedTotal">0</span></span>
                </div>
                <div class="variance-strip ok" id="varianceStrip">
                    <span id="varianceLabel">Variance vs expected (<?= number_format($tally['expected_cash'], 0) ?>)</span>
                    <span class="amt" id="varianceAmt">− Rs <?= number_format($tally['expected_cash'], 0) ?></span>
                </div>
            </div>

            <div class="card">
                <h2 style="font-size:15px;font-weight:700;margin-bottom:4px;">Handover &amp; close</h2>
                <p style="font-size:12.5px;color:var(--text-muted);margin-bottom:14px;">The float (Rs <?= number_format($tally['opening_float'], 0) ?>) stays in the drawer for tomorrow; the rest goes to admin. Write the amount you are physically handing over.</p>

                <div class="cfield">
                    <label for="handover_declared">Cash handed to admin (Rs)</label>
                    <input id="handover_declared" name="handover_declared" type="number" min="0" step="1"
                           value="<?= number_format($suggestedHandover, 0, '.', '') ?>"
                           style="font-size:17px;font-weight:700;font-variant-numeric:tabular-nums;">
                </div>
                <div class="cfield">
                    <label for="handover_to_id">Handing over to (admin)</label>
                    <select id="handover_to_id" name="handover_to_id" required>
                        <?php foreach ($admins as $a): ?>
                            <option value="<?= (int) $a['id'] ?>"><?= htmlspecialchars($a['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="cfield">
                    <label for="variance_note">Variance note <span style="font-weight:400;color:var(--text-muted)">(required when short/over)</span></label>
                    <textarea id="variance_note" name="variance_note" maxlength="255" placeholder="Explain any difference between counted and expected cash"></textarea>
                </div>

                <button class="close-btn" type="submit"
                        onclick="return confirm('Close the day? Payments and refunds for today will be locked, and the A5 closing slip will open for printing.');">
                    Submit closing &amp; open A5 slip
                </button>
                <p class="lock-note">Locks today's payments, prints the closing slip (sign both lines, file the paper), and queues the handover in the admin portal.</p>
            </div>

        </div>
    </div>
    </form>

    <?php endif; ?>

</div>
</div></div><!-- .main + .app -->

<script>
// Live denomination math. Server recomputes everything from the raw
// quantities — this is display convenience only.
(function () {
    var expected = <?= json_encode(round($tally['expected_cash'], 2)) ?>;
    var floatAmt = <?= json_encode(round($tally['opening_float'], 2)) ?>;
    var inputs = document.querySelectorAll('.denom-qty');
    if (!inputs.length) return;

    var fmt = function (n) { return n.toLocaleString('en-PK', {maximumFractionDigits: 0}); };

    function recalc() {
        var total = 0;
        inputs.forEach(function (inp) {
            var face = parseInt(inp.dataset.face, 10);
            var qty = Math.max(0, parseInt(inp.value, 10) || 0);
            var amt = face === 1 ? qty : face * qty;
            total += amt;
            var cell = document.querySelector('[data-amt="' + face + '"]');
            if (cell) cell.textContent = fmt(amt);
        });

        document.getElementById('countedTotal').textContent = fmt(total);

        var variance = total - expected;
        var strip = document.getElementById('varianceStrip');
        var amtEl = document.getElementById('varianceAmt');
        strip.classList.remove('ok', 'short', 'over');
        if (Math.abs(variance) < 0.01) {
            strip.classList.add('ok');
            amtEl.textContent = 'Balanced';
        } else if (variance < 0) {
            strip.classList.add('short');
            amtEl.textContent = '− Rs ' + fmt(Math.abs(variance)) + ' short';
        } else {
            strip.classList.add('over');
            amtEl.textContent = '+ Rs ' + fmt(variance) + ' over';
        }

        // Suggested handover follows the count until the cashier edits it.
        var ho = document.getElementById('handover_declared');
        if (ho && !ho.dataset.touched) {
            ho.value = Math.max(0, total - floatAmt);
        }
    }

    var ho = document.getElementById('handover_declared');
    if (ho) ho.addEventListener('input', function () { ho.dataset.touched = '1'; });

    inputs.forEach(function (inp) { inp.addEventListener('input', recalc); });
    recalc();
})();
</script>
</body>
</html>
