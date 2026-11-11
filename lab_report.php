<?php
/**
 * Lab results — list, enter/edit results, attach the lab's PDF, print.
 * ADMIN ONLY for now. The patient pays the lab directly: nothing here feeds
 * clinic income. Requires sql/add_lab_module.sql (+ seed_lab_tests.sql).
 */
require_once __DIR__ . '/config/guard_admin.php';
require_once __DIR__ . '/config/lab.php';

$uid = (int) $_SESSION['user_id'];

// ---- AJAX: patient search --------------------------------------------------
if (($_GET['ajax'] ?? '') === 'patients') {
    $q = trim((string) ($_GET['q'] ?? ''));
    $rows = [];
    if (mb_strlen($q) >= 2) {
        $like = '%' . $q . '%';
        $st = $pdo->prepare('SELECT id, name, mrn, phone FROM patients WHERE name LIKE ? OR mrn LIKE ? OR phone LIKE ? ORDER BY name LIMIT 10');
        $st->execute([$like, $like, $like]);
        $rows = $st->fetchAll();
    }
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit;
}

$error = '';
$orderId = (int) ($_GET['id'] ?? 0);

// ---- Save ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    try {
        if (!lab_csrf_ok($_POST['csrf'] ?? null)) { throw new RuntimeException('Session expired — reload the page and try again.'); }
        $orderId = (int) ($_POST['order_id'] ?? 0);
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
        $dt = function ($s) { $s = trim((string) $s); $t = $s ? strtotime($s) : false; return $t ? date('Y-m-d H:i:s', $t) : null; };
        $ref = trim((string) ($_POST['referred_by'] ?? '')) ?: null;
        $note = trim((string) ($_POST['note'] ?? '')) ?: null;
        $spec = $dt($_POST['specimen_at'] ?? ''); $rep = $dt($_POST['report_at'] ?? '');

        $pdo->beginTransaction();
        if ($orderId) {
            if (!lab_load_order($pdo, $orderId)) { throw new RuntimeException('Order not found.'); }
            $pdo->prepare('UPDATE lab_orders SET patient_id=?, walkin_name=?, walkin_age=?, walkin_gender=?, walkin_phone=?, referred_by=?, specimen_at=?, report_at=?, note=?, updated_at=NOW() WHERE id=?')
                ->execute([$patientId, $wName, $wAge, $wGender, $wPhone, $ref, $spec, $rep, $note, $orderId]);
            $pdo->prepare('DELETE FROM lab_results WHERE order_id = ?')->execute([$orderId]);
        } else {
            $pdo->prepare('INSERT INTO lab_orders (patient_id, walkin_name, walkin_age, walkin_gender, walkin_phone, referred_by, specimen_at, report_at, note, created_by_id) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$patientId, $wName, $wAge, $wGender, $wPhone, $ref, $spec, $rep, $note, $uid]);
            $orderId = (int) $pdo->lastInsertId();
        }
        // Results: parallel arrays, one entry per row, already in display order.
        $ins = $pdo->prepare('INSERT INTO lab_results (order_id, test_id, test_name, section, group_label, param_name, unit, ref_range, result_value, interp, is_out, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $P = $_POST['r'] ?? [];
        $n = 0;
        foreach (($P['param'] ?? []) as $i => $pn) {
            $val = trim((string) ($P['value'][$i] ?? ''));
            $interp = trim((string) ($P['interp'][$i] ?? ''));
            if ($val === '' && $interp === '') { continue; } // untouched row: not printed
            $range = trim((string) ($P['range'][$i] ?? ''));
            $ins->execute([$orderId, (int) ($P['test_id'][$i] ?? 0) ?: null, trim((string) $P['test'][$i]), trim((string) $P['section'][$i]),
                trim((string) ($P['group'][$i] ?? '')), trim((string) $pn), trim((string) ($P['unit'][$i] ?? '')), $range, $val, $interp,
                lab_out_of_range($val, $range) ? 1 : 0, ++$n]);
        }
        // Optional uploaded lab PDF.
        $f = $_FILES['lab_pdf'] ?? null;
        if ($f && $f['error'] === UPLOAD_ERR_OK) {
            if ($f['size'] > LAB_MAX_PDF) { throw new RuntimeException('PDF is over 10 MB.'); }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
            if ($mime !== 'application/pdf') { throw new RuntimeException('Only PDF files can be attached.'); }
            $stored = bin2hex(random_bytes(16)) . '.pdf';
            if (!move_uploaded_file($f['tmp_name'], lab_upload_dir() . '/' . $stored)) { throw new RuntimeException('Could not store the PDF.'); }
            $pdo->prepare('INSERT INTO lab_result_files (order_id, original_name, stored_name, size_bytes, uploaded_by_id) VALUES (?,?,?,?,?)')
                ->execute([$orderId, mb_substr(basename((string) $f['name']), 0, 200), $stored, (int) $f['size'], $uid]);
        } elseif ($f && $f['error'] !== UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('PDF upload failed (code ' . (int) $f['error'] . ').');
        }
        if (!$n && !$pdo->query('SELECT COUNT(*) FROM lab_result_files WHERE order_id = ' . $orderId)->fetchColumn()) {
            throw new RuntimeException('Enter at least one result, or attach the lab PDF.');
        }
        $pdo->commit();
        audit_log($pdo, 'lab_order_saved', "Saved lab order #$orderId", $uid);
        header('Location: lab_report.php?id=' . $orderId . '&saved=1');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = $e->getMessage();
        $orderId = (int) ($_POST['order_id'] ?? 0);
    }
}

// ---- Remove an attached PDF ---------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'del_file' && lab_csrf_ok($_POST['csrf'] ?? null)) {
    $st = $pdo->prepare('SELECT * FROM lab_result_files WHERE id = ?'); $st->execute([(int) $_POST['file_id']]);
    if ($fr = $st->fetch()) {
        @unlink(lab_upload_dir() . '/' . $fr['stored_name']);
        $pdo->prepare('DELETE FROM lab_result_files WHERE id = ?')->execute([$fr['id']]);
        audit_log($pdo, 'lab_file_removed', "Removed lab PDF from order #{$fr['order_id']}", $uid);
        header('Location: lab_report.php?id=' . (int) $fr['order_id']); exit;
    }
}

// ---- Data for the page --------------------------------------------------------
$form = $_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['id']) || isset($_GET['new']));
$order = null; $rows = []; $files = [];
if ($orderId) {
    $order = lab_load_order($pdo, $orderId);
    if (!$order) { http_response_code(404); exit('Order not found.'); }
    $st = $pdo->prepare('SELECT * FROM lab_results WHERE order_id = ? ORDER BY sort_order'); $st->execute([$orderId]); $rows = $st->fetchAll();
    $st = $pdo->prepare('SELECT * FROM lab_result_files WHERE order_id = ? ORDER BY id'); $st->execute([$orderId]); $files = $st->fetchAll();
}
if ($error) { $form = true; }

$catalogue = [];
foreach ($pdo->query('SELECT t.id tid, t.section, t.name tname, p.group_label, p.name, p.unit, p.ref_range FROM lab_tests t JOIN lab_test_params p ON p.test_id = t.id WHERE t.is_active = 1 ORDER BY t.sort_order, t.id, p.sort_order, p.id') as $r) {
    $catalogue[$r['tid']]['name'] = $r['tname'];
    $catalogue[$r['tid']]['section'] = $r['section'];
    $catalogue[$r['tid']]['params'][] = ['g' => $r['group_label'], 'n' => $r['name'], 'u' => $r['unit'], 'r' => $r['ref_range']];
}
$doctors = $pdo->query("SELECT name FROM users WHERE base_role = 'DOCTOR' AND is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

$list = [];
if (!$form) {
    $list = $pdo->query('SELECT o.id, o.created_at, o.report_at, o.patient_id, COALESCE(p.name, o.walkin_name) pname, p.mrn,
                                (SELECT COUNT(*) FROM lab_results r WHERE r.order_id = o.id) nres,
                                (SELECT COUNT(*) FROM lab_result_files f WHERE f.order_id = o.id) nfiles,
                                (SELECT GROUP_CONCAT(DISTINCT r.test_name ORDER BY r.sort_order SEPARATOR ", ") FROM lab_results r WHERE r.order_id = o.id) tests
                           FROM lab_orders o LEFT JOIN patients p ON p.id = o.patient_id ORDER BY o.id DESC LIMIT 200')->fetchAll();
}

// Pre-fill values (edit, or re-render after a validation error).
$src = $error ? $_POST : [];
$isWalk = $error ? (($_POST['patient_mode'] ?? '') === 'wk') : ($order && !$order['patient_id']);
$pre = [
    'patient_id' => $error ? (int) ($_POST['patient_id'] ?? 0) : (int) ($order['patient_id'] ?? 0),
    'patient_label' => $error ? (string) ($_POST['patient_label'] ?? '') : ($order && $order['patient_id'] ? $order['p_name'] . ' · ' . $order['p_mrn'] : ''),
    'w_name' => $error ? ($_POST['w_name'] ?? '') : ($order['walkin_name'] ?? ''),
    'w_age' => $error ? ($_POST['w_age'] ?? '') : ($order['walkin_age'] ?? ''),
    'w_gender' => $error ? ($_POST['w_gender'] ?? '') : ($order['walkin_gender'] ?? ''),
    'w_phone' => $error ? ($_POST['w_phone'] ?? '') : ($order['walkin_phone'] ?? ''),
    'referred_by' => $error ? ($_POST['referred_by'] ?? '') : ($order['referred_by'] ?? ''),
    'specimen_at' => $error ? ($_POST['specimen_at'] ?? '') : ($order && $order['specimen_at'] ? date('Y-m-d\TH:i', strtotime($order['specimen_at'])) : date('Y-m-d\TH:i')),
    'report_at' => $error ? ($_POST['report_at'] ?? '') : ($order && $order['report_at'] ? date('Y-m-d\TH:i', strtotime($order['report_at'])) : date('Y-m-d\TH:i')),
    'note' => $error ? ($_POST['note'] ?? '') : ($order ? (string) $order['note'] : 'Please correlate with clinical data and other investigations.'),
];
$initRows = [];
if ($error && !empty($_POST['r']['param'])) {
    foreach ($_POST['r']['param'] as $i => $pn) {
        $initRows[] = ['tid' => (int) $_POST['r']['test_id'][$i], 'test' => $_POST['r']['test'][$i], 'section' => $_POST['r']['section'][$i], 'g' => $_POST['r']['group'][$i] ?? '',
            'n' => $pn, 'u' => $_POST['r']['unit'][$i] ?? '', 'r' => $_POST['r']['range'][$i] ?? '', 'v' => $_POST['r']['value'][$i] ?? '', 'i' => $_POST['r']['interp'][$i] ?? ''];
    }
} else {
    foreach ($rows as $r) {
        $initRows[] = ['tid' => (int) $r['test_id'], 'test' => $r['test_name'], 'section' => $r['section'], 'g' => $r['group_label'], 'n' => $r['param_name'],
            'u' => $r['unit'], 'r' => $r['ref_range'], 'v' => $r['result_value'], 'i' => $r['interp']];
    }
}

$pageTitle = 'Lab Report';
$headExtra = <<<CSS
<style>
.lr-card{background:var(--card,#fff);border:1px solid var(--border,#e2e8e4);border-radius:12px;padding:16px;margin-bottom:14px}
.lr-card h2{font-size:13px;margin:0 0 10px;text-transform:uppercase;letter-spacing:.04em;color:var(--text-muted,#5c6d63)}
.lr-row{display:flex;gap:10px;flex-wrap:wrap}.lr-row>div{flex:1 1 180px}
.lr-card label{display:block;font-size:12px;color:var(--text-muted,#5c6d63);margin-bottom:3px}
.lr-card input,.lr-card select,.lr-card textarea{width:100%;padding:8px 9px;border:1px solid var(--border,#e2e8e4);border-radius:7px;font:inherit;background:#fff}
.lr-seg{display:inline-flex;border:1px solid var(--border,#e2e8e4);border-radius:8px;overflow:hidden;margin-bottom:10px}
.lr-seg span{padding:7px 12px;cursor:pointer;background:#fff}.lr-seg span.on{background:#e6f2ef;color:#02624f;font-weight:600}
table.lr-res{width:100%;border-collapse:collapse}
table.lr-res th{text-align:left;font-size:12px;color:var(--text-muted,#5c6d63);padding:6px;border-bottom:1px solid var(--border,#e2e8e4)}
table.lr-res td{padding:4px 6px;border-bottom:1px dotted var(--border,#e2e8e4)}
table.lr-res td.sec{background:#f0f3f1;font-weight:700;color:#7a1f1f}
table.lr-res td.grp{text-decoration:underline}
table.lr-res input{padding:5px 7px}
.lr-btn{background:#02624f;color:#fff;border:0;border-radius:8px;padding:9px 16px;font:inherit;cursor:pointer;text-decoration:none;display:inline-block}
.lr-btn.ghost{background:#fff;color:inherit;border:1px solid var(--border,#e2e8e4)}
.lr-err{background:#fdecea;border:1px solid #f1b5ae;color:#8a1f12;border-radius:8px;padding:10px 12px;margin-bottom:12px}
.lr-ok{background:#e7f5ee;border:1px solid #b6e0c9;color:#14573a;border-radius:8px;padding:10px 12px;margin-bottom:12px}
.lr-hit{position:absolute;z-index:5;background:#fff;border:1px solid var(--border,#e2e8e4);border-radius:8px;box-shadow:0 4px 14px #0002;width:100%;max-height:220px;overflow:auto}
.lr-hit div{padding:8px 10px;cursor:pointer}.lr-hit div:hover{background:#f0f3f1}
.hidden{display:none}
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'lab_report';
require __DIR__ . '/partials/sidebar.php';
?>
<div class="content">
    <div class="page-head">
        <div>
            <div class="page-title">Generate Lab Report</div>
            <div class="page-sub">Metacare Diagnostics results printout &mdash; admin only, not linked to clinic income</div>
        </div>
        <?php if ($form): ?><a class="lr-btn ghost" href="lab_report.php">&larr; All reports</a>
        <?php else: ?><a class="lr-btn" href="lab_report.php?new=1">+ New report</a><?php endif; ?>
    </div>
    <?php if ($error): ?><div class="lr-err"><?= lab_h($error) ?></div><?php endif; ?>
    <?php if (isset($_GET['saved'])): ?><div class="lr-ok">Saved.</div><?php endif; ?>

<?php if (!$form): ?>
    <div class="lr-card" style="padding:0;overflow:auto">
    <table class="lr-res" style="min-width:640px">
        <thead><tr><th style="padding:10px">#</th><th>Patient</th><th>Tests</th><th>Report date</th><th>PDF</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($list as $o): ?>
            <tr>
                <td style="padding:10px"><?= (int) $o['id'] ?></td>
                <td><?= lab_h($o['pname']) ?><?= $o['mrn'] ? ' <span style="color:#8b9a91">' . lab_h($o['mrn']) . '</span>' : ' <span style="color:#8b9a91">walk-in</span>' ?></td>
                <td><?= lab_h($o['tests'] ?: '—') ?></td>
                <td><?= $o['report_at'] ? lab_h(date('d-M-Y g:i a', strtotime($o['report_at']))) : '—' ?></td>
                <td><?= (int) $o['nfiles'] ? 'attached' : '' ?></td>
                <td style="white-space:nowrap">
                    <a href="lab_report.php?id=<?= (int) $o['id'] ?>">Edit</a>
                    <?php if ((int) $o['nres']): ?> &middot; <a href="lab_report_print.php?id=<?= (int) $o['id'] ?>" target="_blank">Print</a><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$list): ?><tr><td colspan="6" style="padding:18px;color:#8b9a91">No lab reports yet. Click “New report”.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
<?php else: ?>
<form method="POST" enctype="multipart/form-data" id="lrForm" autocomplete="off">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="csrf" value="<?= lab_h(lab_csrf()) ?>">
    <input type="hidden" name="order_id" value="<?= (int) $orderId ?>">
    <input type="hidden" name="patient_mode" id="pmode" value="<?= $isWalk ? 'wk' : 'ex' ?>">
    <input type="hidden" name="patient_id" id="pid" value="<?= (int) $pre['patient_id'] ?>">

    <div class="lr-card"><h2>Patient</h2>
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
        <div class="lr-row" style="margin-top:10px">
            <div><label>Referred by</label><input name="referred_by" list="docs" value="<?= lab_h($pre['referred_by']) ?>">
                <datalist id="docs"><?php foreach ($doctors as $d): ?><option value="<?= lab_h($d) ?>"><?php endforeach; ?></datalist></div>
            <div><label>Specimen date &amp; time</label><input type="datetime-local" name="specimen_at" value="<?= lab_h($pre['specimen_at']) ?>"></div>
            <div><label>Report date &amp; time</label><input type="datetime-local" name="report_at" value="<?= lab_h($pre['report_at']) ?>"></div>
        </div>
    </div>

    <div class="lr-card"><h2>Results</h2>
        <div class="lr-row" style="margin-bottom:8px">
            <div style="flex:3 1 300px"><label>Add test (ranges prefill from the catalogue)</label>
                <select id="addSel"><?php foreach ($catalogue as $tid => $t): ?><option value="<?= (int) $tid ?>"><?= lab_h($t['name']) ?></option><?php endforeach; ?></select></div>
            <div style="flex:0 0 auto;align-self:end"><button type="button" class="lr-btn ghost" id="addBtn">+ Add</button></div>
        </div>
        <div style="overflow:auto"><table class="lr-res" id="rt" style="min-width:640px"><thead><tr>
            <th style="width:30%">Test</th><th style="width:15%">Result</th><th style="width:13%">Interpretation</th><th style="width:10%">Unit</th><th>Reference range</th><th style="width:40px"></th></tr></thead><tbody></tbody></table></div>
        <p style="color:#5c6d63;font-size:12px;margin:8px 0">A numeric result outside a numeric range prints bold. Free-text ranges (Negative/Positive) never flag automatically &mdash; type Positive/Negative in Interpretation. Rows left empty are not printed.</p>
        <label style="font-size:12px;color:#5c6d63">Note (printed under the results)</label>
        <textarea name="note" rows="2" style="width:100%;padding:8px;border:1px solid #e2e8e4;border-radius:7px;font:inherit"><?= lab_h($pre['note']) ?></textarea>
    </div>

    <div class="lr-card"><h2>Lab's own PDF (optional)</h2>
        <?php foreach ($files as $f): ?>
            <div style="margin-bottom:6px"><a href="lab_file.php?id=<?= (int) $f['id'] ?>" target="_blank"><?= lab_h($f['original_name']) ?></a>
                <span style="color:#8b9a91">(<?= round($f['size_bytes'] / 1024) ?> KB)</span>
                <button type="submit" name="action" value="del_file" class="lr-btn ghost" style="padding:2px 8px" formnovalidate
                        onclick="document.getElementById('delFile').value=<?= (int) $f['id'] ?>;return confirm('Remove this PDF?')">Remove</button></div>
        <?php endforeach; ?>
        <input type="hidden" name="file_id" id="delFile" value="">
        <input type="file" name="lab_pdf" accept="application/pdf">
    </div>

    <button class="lr-btn" type="submit">Save</button>
    <?php if ($orderId && $rows): ?> <a class="lr-btn ghost" href="lab_report_print.php?id=<?= (int) $orderId ?>" target="_blank">Print / Download PDF</a><?php endif; ?>
</form>
<script>
var CAT = <?= json_encode($catalogue, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var INIT = <?= json_encode($initRows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var tb = document.querySelector('#rt tbody');
function esc(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;')}
function hid(n,v){return '<input type="hidden" name="r['+n+'][]" value="'+esc(v)+'">'}
function addRow(x){
  var tr=document.createElement('tr');
  tr.innerHTML='<td>'+esc(x.n)+hid('param',x.n)+hid('test_id',x.tid)+hid('test',x.test)+hid('section',x.section)+hid('group',x.g)+'</td>'+
   '<td><input name="r[value][]" value="'+esc(x.v)+'"></td><td><input name="r[interp][]" value="'+esc(x.i)+'"></td>'+
   '<td><input name="r[unit][]" value="'+esc(x.u)+'"></td><td><input name="r[range][]" value="'+esc(x.r)+'"></td><td></td>';
  tb.appendChild(tr);
}
function addTest(tid){
  var t=CAT[tid]; if(!t) return;
  var h=document.createElement('tr'); h.className='tst'; h.dataset.tid=tid;
  h.innerHTML='<td class="sec" colspan="5">'+esc(t.section)+' &mdash; '+esc(t.name)+'</td><td class="sec"><a href="#" title="Remove test" onclick="return rmTest(this)">&times;</a></td>';
  tb.appendChild(h); var g=null;
  t.params.forEach(function(p){
    if(p.g&&p.g!==g){var gr=document.createElement('tr');gr.dataset.tid=tid;gr.innerHTML='<td class="grp" colspan="6">'+esc(p.g)+'</td>';tb.appendChild(gr)}
    g=p.g; var before=tb.lastChild; addRow({n:p.n,tid:tid,test:t.name,section:t.section,g:p.g,u:p.u,r:p.r,v:'',i:''}); tb.lastChild.dataset.tid=tid;
  });
}
function rmTest(a){var tid=a.closest('tr').dataset.tid;[].slice.call(tb.querySelectorAll('tr[data-tid="'+tid+'"]')).forEach(function(r){r.remove()});return false}
// Re-render saved rows grouped by test (header + group sub-headings as in the add flow).
(function(){var last=null,lg=null;INIT.forEach(function(x){
  if(x.tid+'|'+x.test!==last){var h=document.createElement('tr');h.dataset.tid=x.tid;
    h.innerHTML='<td class="sec" colspan="5">'+esc(x.section)+' &mdash; '+esc(x.test)+'</td><td class="sec"><a href="#" onclick="return rmTest(this)">&times;</a></td>';tb.appendChild(h);last=x.tid+'|'+x.test;lg=null}
  if(x.g&&x.g!==lg){var gr=document.createElement('tr');gr.dataset.tid=x.tid;gr.innerHTML='<td class="grp" colspan="6">'+esc(x.g)+'</td>';tb.appendChild(gr)}
  lg=x.g;addRow(x);tb.lastChild.dataset.tid=x.tid})})();
document.getElementById('addBtn').onclick=function(){addTest(document.getElementById('addSel').value)};
// Patient mode + search
document.querySelectorAll('.lr-seg span').forEach(function(s){s.onclick=function(){
  document.querySelectorAll('.lr-seg span').forEach(function(x){x.classList.remove('on')});s.classList.add('on');
  var wk=s.dataset.p==='wk';document.getElementById('pmode').value=wk?'wk':'ex';
  document.getElementById('p-ex').classList.toggle('hidden',wk);document.getElementById('p-wk').classList.toggle('hidden',!wk)}});
var ps=document.getElementById('psearch'),hit=document.getElementById('phit'),tm;
ps.oninput=function(){document.getElementById('pid').value='';clearTimeout(tm);var q=ps.value.trim();if(q.length<2){hit.classList.add('hidden');return}
  tm=setTimeout(function(){fetch('lab_report.php?ajax=patients&q='+encodeURIComponent(q)).then(function(r){return r.json()}).then(function(rows){
    hit.innerHTML='';rows.forEach(function(p){var d=document.createElement('div');d.textContent=p.name+' · '+(p.mrn||'no MRN')+' · '+p.phone;
      d.onclick=function(){document.getElementById('pid').value=p.id;ps.value=p.name+' · '+(p.mrn||'');hit.classList.add('hidden')};hit.appendChild(d)});
    hit.classList.toggle('hidden',!rows.length)})},250)};
</script>
<?php endif; ?>
</div>
    </div>
</div>
</body>
</html>
