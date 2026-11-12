<?php
/**
 * Generate Lab Bill — list, create, edit, delete a Metacare Diagnostics invoice.
 * ADMIN ONLY. The clinic collects on behalf of the lab: nothing here feeds clinic
 * income, Day Closing, doctor share or analytics. Requires sql/add_lab_module.sql
 * and sql/add_lab_billing.sql. Total/Net/Balance are computed, never stored.
 */
require_once __DIR__ . '/config/guard_admin.php';
require_once __DIR__ . '/config/lab.php';

$uid = (int) $_SESSION['user_id'];
$error = '';
$invId = (int) ($_GET['id'] ?? 0);
$orderParam = (int) ($_GET['order'] ?? 0);

/** Parse a typed money value ("1,650") to a non-negative float, or null if invalid. */
function lb_money($s): ?float
{
    $s = str_replace(',', '', trim((string) $s));
    if ($s === '') { return 0.0; }
    return is_numeric($s) && (float) $s >= 0 ? round((float) $s, 2) : null;
}
function lb_n($v): string { return number_format((float) $v, 0); }

// ---- Delete ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (lab_csrf_ok($_POST['csrf'] ?? null)) {
        $did = (int) ($_POST['invoice_id'] ?? 0);
        $st = $pdo->prepare('SELECT invoice_no FROM lab_invoices WHERE id = ?'); $st->execute([$did]);
        if ($no = $st->fetchColumn()) {
            $pdo->prepare('DELETE FROM lab_invoices WHERE id = ?')->execute([$did]); // lines cascade
            audit_log($pdo, 'lab_invoice_deleted', "Deleted lab invoice $no (id $did)", $uid);
        }
    }
    header('Location: lab_bill.php'); exit;
}

// ---- Save (create or edit) ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    try {
        if (!lab_csrf_ok($_POST['csrf'] ?? null)) { throw new RuntimeException('Session expired — reload the page and try again.'); }
        $invId = (int) ($_POST['invoice_id'] ?? 0);
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $ref = trim((string) ($_POST['referred_by'] ?? '')) ?: null;

        // Lines: parallel arrays. Rows with no title are ignored.
        $L = $_POST['l'] ?? [];
        $lines = []; $total = 0.0;
        $catByName = [];
        foreach ($pdo->query('SELECT id, name FROM lab_tests WHERE is_active = 1') as $t) { $catByName[mb_strtolower($t['name'])] = (int) $t['id']; }
        foreach (($L['title'] ?? []) as $i => $title) {
            $title = trim((string) $title);
            if ($title === '') { continue; }
            $amt = lb_money($L['amount'][$i] ?? '');
            if ($amt === null) { throw new RuntimeException('Amount for "' . $title . '" is not a valid number.'); }
            $ts = trim((string) ($L['reporting_at'][$i] ?? '')); $tt = $ts ? strtotime($ts) : false;
            $lines[] = [
                'test_id' => $catByName[mb_strtolower($title)] ?? null,
                'title' => mb_substr($title, 0, 150),
                'process' => ($L['process'][$i] ?? '') === 'Urgent' ? 'Urgent' : 'Normal',
                'reporting_at' => $tt ? date('Y-m-d H:i:s', $tt) : null,
                'sample_no' => mb_substr(trim((string) ($L['sample'][$i] ?? '')), 0, 30) ?: null,
                'amount' => $amt,
            ];
            $total += $amt;
        }
        if (!$lines) { throw new RuntimeException('Add at least one test.'); }
        $adj = lb_money($_POST['adjustment'] ?? '');
        $rec = lb_money($_POST['received'] ?? '');
        if ($adj === null || $rec === null) { throw new RuntimeException('Adjustment and Received must be valid numbers.'); }
        if ($adj > $total) { throw new RuntimeException('Adjustment cannot be more than the total.'); }
        $net = $total - $adj;
        if ($rec > $net) { throw new RuntimeException('Received cannot be more than the net amount.'); }
        // Invoice date: staff may back-date (charged earlier, documented at end of shift). Never future.
        $nowTs = strtotime((string) $pdo->query('SELECT NOW()')->fetchColumn());
        $bs = trim((string) ($_POST['billed_at'] ?? ''));
        $billedTs = $bs !== '' ? strtotime($bs) : $nowTs;
        if (!$billedTs) { throw new RuntimeException('Invoice date is not valid.'); }
        if ($billedTs > $nowTs + 300) { throw new RuntimeException('Invoice date cannot be in the future.'); }
        $billedAt = date('Y-m-d H:i:s', $billedTs);
        $backNote = $nowTs - $billedTs > 3600 ? " [back-dated to $billedAt, entered " . date('Y-m-d H:i', $nowTs) . ']' : '';
        $tooOld = fn($ts) => $nowTs - $ts > LAB_BACKDATE_DAYS * 86400;

        $pdo->beginTransaction();
        if ($invId) {
            $st = $pdo->prepare('SELECT * FROM lab_invoices WHERE id = ? FOR UPDATE'); $st->execute([$invId]);
            $inv = $st->fetch();
            if (!$inv) { throw new RuntimeException('Invoice not found.'); }
            $orderId = (int) $inv['order_id'];
            $oldB = strtotime((string) ($inv['billed_at'] ?: $inv['created_at']));
            if (date('Y-m-d H:i', $billedTs) !== date('Y-m-d H:i', $oldB) && $tooOld($billedTs)) {
                throw new RuntimeException('Invoice date can be at most ' . LAB_BACKDATE_DAYS . ' days back.');
            }
            $old = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM lab_invoice_lines WHERE invoice_id = ?'); $old->execute([$invId]);
            $oldTotal = (float) $old->fetchColumn();
            $pdo->prepare('UPDATE lab_invoices SET adjustment=?, received=?, billed_at=?, updated_at=NOW() WHERE id=?')->execute([$adj, $rec, $billedAt, $invId]);
            $pdo->prepare('DELETE FROM lab_invoice_lines WHERE invoice_id = ?')->execute([$invId]);
            $audit = ['lab_invoice_edited', "Edited lab invoice {$inv['invoice_no']}: total " . lb_n($oldTotal) . ' -> ' . lb_n($total)
                . ', adjustment ' . lb_n($inv['adjustment']) . ' -> ' . lb_n($adj) . ', received ' . lb_n($inv['received']) . ' -> ' . lb_n($rec) . $backNote];
            if ($orderId) { $pdo->prepare('UPDATE lab_orders SET referred_by=?, updated_at=NOW() WHERE id=?')->execute([$ref, $orderId]); }
        } else {
            if ($tooOld($billedTs)) { throw new RuntimeException('Invoice date can be at most ' . LAB_BACKDATE_DAYS . ' days back.'); }
            if ($orderId) {
                if (!lab_load_order($pdo, $orderId)) { throw new RuntimeException('Order not found.'); }
                $dup = $pdo->prepare('SELECT id FROM lab_invoices WHERE order_id = ?'); $dup->execute([$orderId]);
                if ($existing = $dup->fetchColumn()) { $pdo->rollBack(); header('Location: lab_bill.php?id=' . (int) $existing); exit; }
                $pdo->prepare('UPDATE lab_orders SET referred_by=?, updated_at=NOW() WHERE id=?')->execute([$ref, $orderId]);
            } else {
                $mode = ($_POST['patient_mode'] ?? 'ex') === 'wk' ? 'wk' : 'ex';
                $patientId = null; $wName = $wAge = $wGender = $wPhone = null;
                if ($mode === 'ex') {
                    $patientId = (int) ($_POST['patient_id'] ?? 0);
                    $chk = $pdo->prepare('SELECT 1 FROM patients WHERE id = ?'); $chk->execute([$patientId]);
                    if (!$patientId || !$chk->fetchColumn()) { throw new RuntimeException('Pick a patient from the search results, or switch to Walk-in.'); }
                } else {
                    $wName = trim((string) ($_POST['w_name'] ?? ''));
                    if ($wName === '') { throw new RuntimeException('Walk-in needs a name.'); }
                    $wAge = trim((string) ($_POST['w_age'] ?? '')) ?: null;
                    $wGender = in_array($_POST['w_gender'] ?? '', ['Male', 'Female', 'Other'], true) ? $_POST['w_gender'] : null;
                    $wPhone = trim((string) ($_POST['w_phone'] ?? '')) ?: null;
                }
                $pdo->prepare('INSERT INTO lab_orders (patient_id, walkin_name, walkin_age, walkin_gender, walkin_phone, referred_by, created_by_id) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$patientId, $wName, $wAge, $wGender, $wPhone, $ref, $uid]);
                $orderId = (int) $pdo->lastInsertId();
            }
            // Next number for this month: the upsert takes the row lock, the SELECT reads the new value.
            $ym = date('ym', $billedTs); // number follows the invoice date's month
            $pdo->prepare('INSERT INTO lab_invoice_counters (period_ym, last_seq) VALUES (?, 1) ON DUPLICATE KEY UPDATE last_seq = last_seq + 1')->execute([$ym]);
            $q = $pdo->prepare('SELECT last_seq FROM lab_invoice_counters WHERE period_ym = ?'); $q->execute([$ym]);
            $seq = (int) $q->fetchColumn();
            $no = 'MCD-' . substr($ym, 2, 2) . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            $pdo->prepare('INSERT INTO lab_invoices (order_id, period_ym, seq, invoice_no, adjustment, received, billed_at, created_by_id) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$orderId, $ym, $seq, $no, $adj, $rec, $billedAt, $uid]);
            $invId = (int) $pdo->lastInsertId();
            $audit = ['lab_invoice_created', "Created lab invoice $no (order #$orderId): total " . lb_n($total) . ', received ' . lb_n($rec) . $backNote];
        }
        $ins = $pdo->prepare('INSERT INTO lab_invoice_lines (invoice_id, test_id, title, process, reporting_at, sample_no, amount, sort_order) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($lines as $n => $ln) {
            $ins->execute([$invId, $ln['test_id'], $ln['title'], $ln['process'], $ln['reporting_at'], $ln['sample_no'], $ln['amount'], $n + 1]);
        }
        $pdo->commit();
        audit_log($pdo, $audit[0], $audit[1], $uid);
        header('Location: lab_bill.php?id=' . $invId . '&saved=1');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = $e->getMessage();
        $invId = (int) ($_POST['invoice_id'] ?? 0);
        $orderParam = (int) ($_POST['order_id'] ?? 0);
    }
}

// ---- Data for the page ---------------------------------------------------------
$form = $error !== '' || isset($_GET['new']) || $invId || $orderParam;
$inv = null; $order = null; $lines = [];
if ($invId) {
    $st = $pdo->prepare('SELECT * FROM lab_invoices WHERE id = ?'); $st->execute([$invId]); $inv = $st->fetch();
    if (!$inv) { http_response_code(404); exit('Invoice not found.'); }
    $orderParam = (int) $inv['order_id'];
    $st = $pdo->prepare('SELECT * FROM lab_invoice_lines WHERE invoice_id = ? ORDER BY sort_order'); $st->execute([$invId]); $lines = $st->fetchAll();
}
if ($orderParam) {
    $order = lab_load_order($pdo, $orderParam);
    if (!$order) { http_response_code(404); exit('Order not found.'); }
    if (!$inv) { // billing an existing order that may already have an invoice
        $st = $pdo->prepare('SELECT id FROM lab_invoices WHERE order_id = ?'); $st->execute([$orderParam]);
        if ($ex = $st->fetchColumn()) { header('Location: lab_bill.php?id=' . (int) $ex); exit; }
    }
}
$catNames = $pdo->query('SELECT name FROM lab_tests WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll(PDO::FETCH_COLUMN);
$doctors = $pdo->query("SELECT name FROM users WHERE base_role = 'DOCTOR' AND is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

$list = []; $unbilled = [];
if (!$form) {
    $list = $pdo->query('SELECT i.id, i.invoice_no, i.created_at, i.billed_at, i.adjustment, i.received, COALESCE(p.name, o.walkin_name) pname, p.mrn,
                                (SELECT COALESCE(SUM(l.amount),0) FROM lab_invoice_lines l WHERE l.invoice_id = i.id) total
                           FROM lab_invoices i JOIN lab_orders o ON o.id = i.order_id LEFT JOIN patients p ON p.id = o.patient_id
                          ORDER BY i.id DESC LIMIT 200')->fetchAll();
    $unbilled = $pdo->query('SELECT o.id, o.created_at, COALESCE(p.name, o.walkin_name) pname
                               FROM lab_orders o LEFT JOIN patients p ON p.id = o.patient_id
                              WHERE NOT EXISTS (SELECT 1 FROM lab_invoices i WHERE i.order_id = o.id) ORDER BY o.id DESC LIMIT 30')->fetchAll();
}

// Form pre-fill (edit, validation error re-render, or blank).
$P = $error ? $_POST : [];
$isWalk = $error ? (($_POST['patient_mode'] ?? '') === 'wk') : false;
$pre = [
    'referred_by' => $error ? ($_POST['referred_by'] ?? '') : ($order['referred_by'] ?? ''),
    'billed_at' => $error ? ($_POST['billed_at'] ?? '') : date('Y-m-d\TH:i', strtotime($inv ? ((string) ($inv['billed_at'] ?: $inv['created_at'])) : 'now')),
    'adjustment' => $error ? ($_POST['adjustment'] ?? '0') : ($inv ? (float) $inv['adjustment'] : '0'),
    'received' => $error ? ($_POST['received'] ?? '0') : ($inv ? (float) $inv['received'] : '0'),
    'patient_id' => (int) ($_POST['patient_id'] ?? 0),
    'patient_label' => (string) ($_POST['patient_label'] ?? ''),
    'w_name' => $_POST['w_name'] ?? '', 'w_age' => $_POST['w_age'] ?? '', 'w_gender' => $_POST['w_gender'] ?? '', 'w_phone' => $_POST['w_phone'] ?? '',
];
$initLines = [];
if ($error && !empty($_POST['l']['title'])) {
    foreach ($_POST['l']['title'] as $i => $t) {
        $initLines[] = ['t' => $t, 'p' => $_POST['l']['process'][$i] ?? 'Normal', 'd' => $_POST['l']['reporting_at'][$i] ?? '', 's' => $_POST['l']['sample'][$i] ?? '', 'a' => $_POST['l']['amount'][$i] ?? ''];
    }
} else {
    foreach ($lines as $l) {
        $initLines[] = ['t' => $l['title'], 'p' => $l['process'], 'd' => $l['reporting_at'] ? date('Y-m-d\TH:i', strtotime($l['reporting_at'])) : '', 's' => (string) $l['sample_no'], 'a' => (float) $l['amount']];
    }
}
if (!$initLines) { $initLines[] = ['t' => '', 'p' => 'Normal', 'd' => '', 's' => '', 'a' => '']; }
$pt = $order ? lab_patient_block($order) : null;

$pageTitle = 'Generate Lab Bill';
$headExtra = <<<CSS
<style>
.lr-card{background:var(--card,#fff);border:1px solid var(--border,#e2e8e4);border-radius:12px;padding:16px;margin-bottom:14px}
.lr-card h2{font-size:13px;margin:0 0 10px;text-transform:uppercase;letter-spacing:.04em;color:var(--text-muted,#5c6d63)}
.lr-row{display:flex;gap:10px;flex-wrap:wrap}.lr-row>div{flex:1 1 170px}
.lr-card label{display:block;font-size:12px;color:var(--text-muted,#5c6d63);margin-bottom:3px}
.lr-card input,.lr-card select{width:100%;padding:8px 9px;border:1px solid var(--border,#e2e8e4);border-radius:7px;font:inherit;background:#fff}
.lr-seg{display:inline-flex;border:1px solid var(--border,#e2e8e4);border-radius:8px;overflow:hidden;margin-bottom:10px}
.lr-seg span{padding:7px 12px;cursor:pointer;background:#fff}.lr-seg span.on{background:#e6f2ef;color:#02624f;font-weight:600}
table.lr-res{width:100%;border-collapse:collapse}
table.lr-res th{text-align:left;font-size:12px;color:var(--text-muted,#5c6d63);padding:6px;border-bottom:1px solid var(--border,#e2e8e4)}
table.lr-res td{padding:4px 4px;border-bottom:1px dotted var(--border,#e2e8e4)}
.lr-btn{background:#02624f;color:#fff;border:0;border-radius:8px;padding:9px 16px;font:inherit;cursor:pointer;text-decoration:none;display:inline-block}
.lr-btn.ghost{background:#fff;color:inherit;border:1px solid var(--border,#e2e8e4)}
.lr-btn.danger{background:#fff;color:#b42318;border:1px solid #f1b5ae}
.lr-err{background:#fdecea;border:1px solid #f1b5ae;color:#8a1f12;border-radius:8px;padding:10px 12px;margin-bottom:12px}
.lr-ok{background:#e7f5ee;border:1px solid #b6e0c9;color:#14573a;border-radius:8px;padding:10px 12px;margin-bottom:12px}
.lr-hit{position:absolute;z-index:5;background:#fff;border:1px solid var(--border,#e2e8e4);border-radius:8px;box-shadow:0 4px 14px #0002;width:100%;max-height:220px;overflow:auto}
.lr-hit div{padding:8px 10px;cursor:pointer}.lr-hit div:hover{background:#f0f3f1}
.lb-tot{margin-left:auto;width:300px;margin-top:12px}.lb-tot div{display:flex;justify-content:space-between;padding:3px 0}
.lb-tot .bal{color:#b42318;font-weight:700}
.hidden{display:none}
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'lab_bill';
require __DIR__ . '/partials/sidebar.php';
?>
<div class="content">
    <div class="page-head">
        <div>
            <div class="page-title">Generate Lab Bill</div>
            <div class="page-sub">Metacare Diagnostics invoice &mdash; admin only. Collected on behalf of the lab; not clinic income.</div>
        </div>
        <?php if ($form): ?><a class="lr-btn ghost" href="lab_bill.php">&larr; All bills</a>
        <?php else: ?><a class="lr-btn" href="lab_bill.php?new=1">+ New bill</a><?php endif; ?>
    </div>
    <?php if ($error): ?><div class="lr-err"><?= lab_h($error) ?></div><?php endif; ?>
    <?php if (isset($_GET['saved'])): ?><div class="lr-ok">Saved.</div><?php endif; ?>

<?php if (!$form): ?>
    <div class="lr-card" style="padding:0;overflow:auto">
    <table class="lr-res" style="min-width:680px">
        <thead><tr><th style="padding:10px">Invoice</th><th>Patient</th><th>Date</th><th style="text-align:right">Net</th><th style="text-align:right">Received</th><th style="text-align:right">Balance</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($list as $r): $net = (float) $r['total'] - (float) $r['adjustment']; $bal = $net - (float) $r['received']; ?>
            <tr>
                <td style="padding:10px"><?= lab_h($r['invoice_no']) ?></td>
                <td><?= lab_h($r['pname']) ?><?= $r['mrn'] ? ' <span style="color:#8b9a91">' . lab_h($r['mrn']) . '</span>' : ' <span style="color:#8b9a91">walk-in</span>' ?></td>
                <td><?= lab_h(date('d-M-Y g:i a', strtotime($r['billed_at'] ?: $r['created_at']))) ?>
                    <?php if ($r['billed_at'] && strtotime($r['created_at']) - strtotime($r['billed_at']) > 3600): ?><span style="font-size:11px;background:#fff3cd;color:#7a5b00;border-radius:5px;padding:1px 6px" title="Entered <?= lab_h(date('d-M-Y g:i a', strtotime($r['created_at']))) ?>">Back-dated</span><?php endif; ?></td>
                <td style="text-align:right"><?= lb_n($net) ?></td>
                <td style="text-align:right"><?= lb_n($r['received']) ?></td>
                <td style="text-align:right;<?= $bal > 0 ? 'color:#b42318;font-weight:700' : '' ?>"><?= $bal > 0 ? lb_n($bal) : '—' ?></td>
                <td style="white-space:nowrap"><a href="lab_bill.php?id=<?= (int) $r['id'] ?>">Edit</a> &middot; <a href="lab_bill_print.php?id=<?= (int) $r['id'] ?>" target="_blank">Print</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$list): ?><tr><td colspan="7" style="padding:18px;color:#8b9a91">No lab bills yet. Click “New bill”.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
    <?php if ($unbilled): ?>
    <div class="lr-card"><h2>Lab report orders with no bill yet</h2>
        <?php foreach ($unbilled as $u): ?>
            <div style="padding:3px 0">#<?= (int) $u['id'] ?> &middot; <?= lab_h($u['pname']) ?> <span style="color:#8b9a91"><?= lab_h(date('d-M-Y', strtotime($u['created_at']))) ?></span>
                &middot; <a href="lab_bill.php?order=<?= (int) $u['id'] ?>">Bill this</a></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
<?php else: ?>
<form method="POST" id="lbForm" autocomplete="off">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="csrf" value="<?= lab_h(lab_csrf()) ?>">
    <input type="hidden" name="invoice_id" value="<?= (int) $invId ?>">
    <input type="hidden" name="order_id" value="<?= (int) $orderParam ?>">

    <div class="lr-card"><h2>Patient<?= $inv ? ' &mdash; ' . lab_h($inv['invoice_no']) : '' ?></h2>
    <?php if ($order): ?>
        <div style="margin-bottom:10px"><strong><?= lab_h($pt['name']) ?></strong>
            <?= $pt['mrn'] ? ' &middot; ' . lab_h($pt['mrn']) : ' &middot; walk-in' ?>
            <?= $pt['agegender'] ? ' &middot; ' . lab_h($pt['agegender']) : '' ?>
            <?= $pt['phone'] ? ' &middot; ' . lab_h($pt['phone']) : '' ?>
            <div style="color:#8b9a91;font-size:12px">Patient details come from lab order #<?= (int) $orderParam ?>; change them on Generate Report.</div></div>
    <?php else: ?>
        <input type="hidden" name="patient_mode" id="pmode" value="<?= $isWalk ? 'wk' : 'ex' ?>">
        <input type="hidden" name="patient_id" id="pid" value="<?= (int) $pre['patient_id'] ?>">
        <div class="lr-seg"><span data-p="ex" class="<?= $isWalk ? '' : 'on' ?>">HIMS patient</span><span data-p="wk" class="<?= $isWalk ? 'on' : '' ?>">Walk-in (type details)</span></div>
        <div id="p-ex" class="<?= $isWalk ? 'hidden' : '' ?>" style="position:relative">
            <label>Search patient (name / MR no / phone)</label>
            <input type="text" id="psearch" name="patient_label" value="<?= lab_h($pre['patient_label']) ?>">
            <div class="lr-hit hidden" id="phit"></div>
        </div>
        <div id="p-wk" class="lr-row <?= $isWalk ? '' : 'hidden' ?>">
            <div><label>Name</label><input name="w_name" value="<?= lab_h($pre['w_name']) ?>"></div>
            <div><label>Age</label><input name="w_age" placeholder="e.g. 7 yr(s)" value="<?= lab_h($pre['w_age']) ?>"></div>
            <div><label>Gender</label><select name="w_gender"><?php foreach (['Male', 'Female', 'Other'] as $g): ?><option <?= $pre['w_gender'] === $g ? 'selected' : '' ?>><?= $g ?></option><?php endforeach; ?></select></div>
            <div><label>Phone</label><input name="w_phone" value="<?= lab_h($pre['w_phone']) ?>"></div>
        </div>
    <?php endif; ?>
        <div class="lr-row" style="margin-top:10px">
            <div><label>Referred by</label><input name="referred_by" list="docs" value="<?= lab_h($pre['referred_by']) ?>">
                <datalist id="docs"><?php foreach ($doctors as $d): ?><option value="<?= lab_h($d) ?>"><?php endforeach; ?></datalist></div>
            <div><label>Invoice date &amp; time (back-date up to <?= LAB_BACKDATE_DAYS ?> days)</label>
                <input type="datetime-local" name="billed_at" value="<?= lab_h($pre['billed_at']) ?>"></div>
        </div>
    </div>

    <div class="lr-card"><h2>Tests</h2>
        <datalist id="tests"><?php foreach ($catNames as $n): ?><option value="<?= lab_h($n) ?>"><?php endforeach; ?></datalist>
        <div style="overflow:auto"><table class="lr-res" style="min-width:720px"><thead><tr>
            <th style="width:32%">Test (pick or type)</th><th>Process</th><th>Reporting date / time</th><th>Sample no</th><th style="text-align:right">Amount</th><th></th></tr></thead>
            <tbody id="rows"></tbody></table></div>
        <p><button type="button" class="lr-btn ghost" id="addLine">+ Add test</button></p>
        <div class="lr-row" style="margin-top:8px">
            <div><label>Adjustment (discount)</label><input name="adjustment" id="adj" inputmode="decimal" value="<?= lab_h($pre['adjustment']) ?>"></div>
            <div><label>Received</label><input name="received" id="rec" inputmode="decimal" value="<?= lab_h($pre['received']) ?>"></div>
        </div>
        <div class="lb-tot"><div><span>Total</span><b id="t1"></b></div><div><span>Net amount</span><b id="t2"></b></div>
            <div><span>Received</span><b id="t3"></b></div><div class="bal"><span>Balance due</span><b id="t4"></b></div></div>
    </div>

    <button class="lr-btn" type="submit">Save</button>
    <?php if ($inv): ?>
        <a class="lr-btn ghost" href="lab_bill_print.php?id=<?= (int) $invId ?>" target="_blank">Print Patient Copy</a>
        <a class="lr-btn ghost" href="lab_bill_print.php?id=<?= (int) $invId ?>&amp;copy=lab" target="_blank">Print Lab Copy</a>
        <button type="submit" name="action" value="delete" class="lr-btn danger" style="float:right" formnovalidate
                onclick="return confirm('Delete invoice <?= lab_h($inv['invoice_no']) ?>? The number will not be reused.')">Delete invoice</button>
    <?php endif; ?>
</form>
<script>
var INIT = <?= json_encode($initLines, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var rows = document.getElementById('rows');
function esc(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;')}
function num(s){var v=parseFloat(String(s).replace(/,/g,''));return isNaN(v)?0:v}
function fmt(n){return n?Math.round(n).toLocaleString('en-US'):'-'}
function addLine(x){
  x=x||{t:'',p:'Normal',d:'',s:'',a:''};
  var tr=document.createElement('tr');
  tr.innerHTML='<td><input name="l[title][]" list="tests" value="'+esc(x.t)+'"></td>'+
   '<td><select name="l[process][]"><option>Normal</option><option'+(x.p==='Urgent'?' selected':'')+'>Urgent</option></select></td>'+
   '<td><input type="datetime-local" name="l[reporting_at][]" value="'+esc(x.d)+'"></td>'+
   '<td><input name="l[sample][]" value="'+esc(x.s)+'"></td>'+
   '<td><input class="amt" name="l[amount][]" inputmode="decimal" style="text-align:right" value="'+esc(x.a)+'"></td>'+
   '<td><button type="button" class="lr-btn ghost rm" title="Remove">&times;</button></td>';
  rows.appendChild(tr);
}
function calc(){
  var t=0;rows.querySelectorAll('.amt').forEach(function(i){t+=num(i.value)});
  var a=num(document.getElementById('adj').value),r=num(document.getElementById('rec').value),net=t-a;
  document.getElementById('t1').textContent=fmt(t);document.getElementById('t2').textContent=fmt(net);
  document.getElementById('t3').textContent=fmt(r);document.getElementById('t4').textContent=fmt(net-r);
}
INIT.forEach(addLine);calc();
document.getElementById('addLine').onclick=function(){addLine();calc()};
rows.onclick=function(e){if(e.target.classList.contains('rm')&&rows.children.length>1){e.target.closest('tr').remove();calc()}};
document.getElementById('lbForm').addEventListener('input',calc);
var seg=document.querySelectorAll('.lr-seg span');
seg.forEach(function(s){s.onclick=function(){
  seg.forEach(function(x){x.classList.remove('on')});s.classList.add('on');
  var wk=s.dataset.p==='wk';document.getElementById('pmode').value=wk?'wk':'ex';
  document.getElementById('p-ex').classList.toggle('hidden',wk);document.getElementById('p-wk').classList.toggle('hidden',!wk)}});
var ps=document.getElementById('psearch');
if(ps){var hit=document.getElementById('phit'),tm;
ps.oninput=function(){document.getElementById('pid').value='';clearTimeout(tm);var q=ps.value.trim();if(q.length<2){hit.classList.add('hidden');return}
  tm=setTimeout(function(){fetch('lab_report.php?ajax=patients&q='+encodeURIComponent(q)).then(function(r){return r.json()}).then(function(list){
    hit.innerHTML='';list.forEach(function(p){var d=document.createElement('div');d.textContent=p.name+' · '+(p.mrn||'no MRN')+' · '+p.phone;
      d.onclick=function(){document.getElementById('pid').value=p.id;ps.value=p.name+' · '+(p.mrn||'');hit.classList.add('hidden')};hit.appendChild(d)});
    hit.classList.toggle('hidden',!list.length)})},250)};}
</script>
<?php endif; ?>
</div>
    </div>
</div>
</body>
</html>
