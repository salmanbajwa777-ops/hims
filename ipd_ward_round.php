<?php
/**
 * IPD Consultant Ward Round Note — the ipd_doctor_visits capture form.
 *
 * Each save creates ONE immutable ipd_doctor_visits row (previous notes are
 * read-only = audit trail; a correction is a NEW note, never an update). The
 * author is the logged-in covering consultant (NOT the admitting consultant).
 *
 * Three-tier diagnosis carry-forward:
 *   - first ward round pre-fills Primary Diagnosis from ipd_admissions.provisional_diagnosis
 *   - every later round pre-fills from the MOST RECENT note
 *   (always editable — a pre-fill, never a lock)
 *
 * Minimum-to-save gate: Primary Diagnosis present AND Overall Clinical Progress
 * selected AND at least one of Clinical Assessment / Management Plan filled.
 *
 * On save: insert the note; set is_paid (first note of the calendar day -> paid,
 * else unpaid pending reception); write a DOCTOR_VISIT ipd_care_events row.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);

$baseRole = $_SESSION['base_role'] ?? '';
$uid = (int) $_SESSION['user_id'];

require_permission('IPD_VIEW_WARD_ROUNDS');
$canWrite = has_permission('IPD_WRITE_WARD_ROUND');
$hideMoney = ($baseRole === 'DOCTOR');

$admissionId = (int) ($_GET['id'] ?? 0);

function load_ipd_admission_for_round(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("
        SELECT a.*, v.token_no,
               p.id AS patient_id, p.mrn, p.name AS patient_name, p.dob, p.gender,
               COALESCE(du.name, a.admitting_consultant_manual) AS consultant_name
        FROM ipd_admissions a
        JOIN visits v ON v.id = a.visit_id
        JOIN patients p ON p.id = v.patient_id
        LEFT JOIN users du ON du.id = a.admitting_consultant_id
        WHERE a.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

$adm = load_ipd_admission_for_round($pdo, $admissionId);
if (!$adm) { http_response_code(404); exit('IPD admission not found.'); }

// The logged-in doctor's own name (this note's author / consultant of record).
$meStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
$meStmt->execute([$uid]);
$authorName = (string) ($meStmt->fetchColumn() ?: '');

// ---- The most recent note (drives carry-forward pre-fill) ----
function latest_ipd_note(PDO $pdo, int $admissionId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM ipd_doctor_visits WHERE admission_id = ? ORDER BY visited_at DESC, id DESC LIMIT 1');
    $stmt->execute([$admissionId]);
    return $stmt->fetch() ?: null;
}
$latest = latest_ipd_note($pdo, $admissionId);

// Hospital day = calendar days since admission, admit day = day 1.
$hospitalDay = (int) ((new DateTime(date('Y-m-d')))
    ->diff(new DateTime(date('Y-m-d', strtotime($adm['admitted_at']))))->days) + 1;

// The ward's consultant visit fee (snapshotted onto the note at save).
$feeStmt = $pdo->prepare('SELECT consultant_visit_fee FROM ipd_ward_rates WHERE ward = ?');
$feeStmt->execute([$adm['ward']]);
$wardFee = (float) ($feeStmt->fetchColumn() ?: 0);

$flash = '';
$err = '';
$isOpen = $adm['status'] === 'ACTIVE';

// ---- Save the ward round ----
$progressVals = ['IMPROVING','STABLE','SLOW','DETERIORATING','CRITICAL'];
$nextReviewVals = ['EVENING','TOMORROW_AM','TOMORROW_PM','AFTER_48H','PRN'];
// Keep POSTed values so a validation failure re-renders what was typed.
$form = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_round' && $canWrite && $isOpen) {
    $g = fn(string $k, int $max) => mb_substr(trim($_POST[$k] ?? ''), 0, $max);
    $form = [
        'primary_diagnosis'   => $g('primary_diagnosis', 500),
        'secondary_diagnosis' => $g('secondary_diagnosis', 500),
        'active_complaints'   => $g('active_complaints', 500),
        'progress'            => $_POST['progress'] ?? '',
        'positive_findings'   => trim($_POST['positive_findings'] ?? ''),
        'investigation_review'=> trim($_POST['investigation_review'] ?? ''),
        'clinical_assessment' => trim($_POST['clinical_assessment'] ?? ''),
        'management_plan'     => trim($_POST['management_plan'] ?? ''),
        'family_counselling'  => trim($_POST['family_counselling'] ?? ''),
        'next_review'         => $_POST['next_review'] ?? '',
    ];

    // ---- Minimum-to-save gate ----
    if ($form['primary_diagnosis'] === '') {
        $err = 'Primary Diagnosis is required.';
    } elseif (!in_array($form['progress'], $progressVals, true)) {
        $err = 'Select the Overall Clinical Progress.';
    } elseif ($form['clinical_assessment'] === '' && $form['management_plan'] === '') {
        $err = 'Fill at least one of Clinical Assessment or Management Plan.';
    } else {
        $nextReview = in_array($form['next_review'], $nextReviewVals, true) ? $form['next_review'] : null;
        $loggedRole = in_array($baseRole, ['ADMIN','DOCTOR','STAFF'], true) ? $baseRole : 'STAFF';
        try {
            $pdo->beginTransaction();

            // is_paid: 1 if this is the first note of TODAY for this admission.
            $cnt = $pdo->prepare('SELECT COUNT(*) FROM ipd_doctor_visits WHERE admission_id = ? AND DATE(visited_at) = CURDATE()');
            $cnt->execute([$admissionId]);
            $isPaid = ((int) $cnt->fetchColumn() === 0) ? 1 : 0;

            $pdo->prepare('
                INSERT INTO ipd_doctor_visits
                    (admission_id, doctor_id, visited_at, hospital_day,
                     primary_diagnosis, secondary_diagnosis, active_complaints, progress,
                     positive_findings, investigation_review, clinical_assessment, management_plan,
                     family_counselling, next_review, is_paid, visit_charge, entered_by_user_id)
                VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([
                $admissionId, $uid, $hospitalDay,
                $form['primary_diagnosis'],
                $form['secondary_diagnosis'] ?: null,
                $form['active_complaints'] ?: null,
                $form['progress'],
                $form['positive_findings'] ?: null,
                $form['investigation_review'] ?: null,
                $form['clinical_assessment'] ?: null,
                $form['management_plan'] ?: null,
                $form['family_counselling'] ?: null,
                $nextReview, $isPaid, $wardFee, $uid,
            ]);
            $noteId = (int) $pdo->lastInsertId();

            // Feed the flow sheet.
            $pdo->prepare('
                INSERT INTO ipd_care_events (admission_id, event_type, ref_table, ref_id, note, logged_by_id, logged_by_role, event_at)
                VALUES (?, \'DOCTOR_VISIT\', \'ipd_doctor_visits\', ?, ?, ?, ?, NOW())
            ')->execute([
                $admissionId, $noteId,
                'Ward round (Day ' . $hospitalDay . '): ' . $form['progress'],
                $uid, $loggedRole,
            ]);

            $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
                ->execute([$uid, 'ipd_ward_round_saved', "Ward round note #$noteId for IPD admission #$admissionId (day $hospitalDay, $form[progress])"]);

            $pdo->commit();
            header('Location: ipd_ward_round.php?id=' . $admissionId . '&saved=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $err = 'Could not save the note — please try again.';
        }
    }
}

// ---- Pre-fill (carry-forward) — used only when NOT re-rendering after an error ----
if (!$form) {
    $form = [
        'primary_diagnosis'   => $latest['primary_diagnosis'] ?? ($adm['provisional_diagnosis'] ?? ''),
        'secondary_diagnosis' => $latest['secondary_diagnosis'] ?? '',
        'active_complaints'   => $latest['active_complaints'] ?? '',
        'progress'            => '',   // never carried forward — must be chosen each round
        'positive_findings'   => '',
        'investigation_review'=> '',
        'clinical_assessment' => '',
        'management_plan'     => '',
        'family_counselling'  => '',
        'next_review'         => '',
    ];
}

// ---- Latest vitals (read-only; tolerate the table not existing pre-Phase 3) ----
$latestVitals = null;
try {
    $vs = $pdo->prepare('SELECT * FROM ipd_vitals WHERE admission_id = ? ORDER BY recorded_at DESC, id DESC LIMIT 1');
    $vs->execute([$admissionId]);
    $latestVitals = $vs->fetch() ?: null;
} catch (Throwable $e) {
    $latestVitals = null;   // ipd_vitals arrives in Phase 3
}

// ---- Read-only note timeline (newest first) ----
$notes = $pdo->prepare('
    SELECT dv.*, u.name AS author_name
    FROM ipd_doctor_visits dv JOIN users u ON u.id = dv.doctor_id
    WHERE dv.admission_id = ? ORDER BY dv.visited_at DESC, dv.id DESC
');
$notes->execute([$admissionId]);
$notes = $notes->fetchAll();

$progressMeta = [
    'IMPROVING'     => ['🟢', 'Improving',        '#16a34a'],
    'STABLE'        => ['🟡', 'Stable',           '#ca8a04'],
    'SLOW'          => ['🟠', 'Slow Improvement', '#ea580c'],
    'DETERIORATING' => ['🔴', 'Deteriorating',    '#dc2626'],
    'CRITICAL'      => ['⚫', 'Critically Ill',   '#111827'],
];
$nextReviewLabels = [
    'EVENING' => 'Evening Review', 'TOMORROW_AM' => 'Tomorrow Morning',
    'TOMORROW_PM' => 'Tomorrow Evening', 'AFTER_48H' => 'After 48 Hours', 'PRN' => 'PRN',
];
$genderLabels = ['MALE' => 'Male', 'FEMALE' => 'Female', 'OTHER' => 'Other'];

function ipd_round_age(?string $dob): string {
    if (!$dob) { return '—'; }
    $d = strtotime($dob); if ($d === false || $d > time()) { return '—'; }
    $y = (int) floor((time() - $d) / (365.25 * 86400));
    if ($y >= 2) { return $y . 'y'; }
    return (int) floor((time() - $d) / (30.44 * 86400)) . 'm';
}

$pageTitle = 'Ward Round — ' . $adm['patient_name'];
$headExtra = <<<CSS
<style>
.wr-head { border:1px solid var(--border); border-radius:12px; overflow:hidden; margin-bottom:18px; }
.wr-head .kv { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:2px; }
.wr-head .kv > div { padding:9px 13px; border-right:1px solid var(--border); border-top:1px solid var(--border); }
.wr-head .k { font-size:10px; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); font-weight:700; }
.wr-head .v { font-size:13.5px; font-weight:650; margin-top:2px; }
.wr-field { margin-bottom:16px; }
.wr-field > label { display:block; font-size:12.5px; font-weight:600; color:var(--text-secondary); margin-bottom:6px; }
.wr-field input[type=text], .wr-field textarea, .wr-field select {
    width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:10px; font:inherit; font-size:13.5px; background:var(--bg);
}
.wr-field textarea { resize:vertical; min-height:56px; }
.wr-field input:focus, .wr-field textarea:focus, .wr-field select:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(26,127,126,.15); background:#fff; }
.wr-req { color:var(--red-text,#dc2626); }
.prog-opts { display:flex; flex-wrap:wrap; gap:10px; }
.prog-opt { display:flex; align-items:center; gap:8px; border:1px solid var(--border); border-radius:12px; padding:9px 14px; cursor:pointer; font-size:13.5px; font-weight:600; }
.prog-opt input { position:absolute; opacity:0; width:0; height:0; }
.prog-opt:has(input:checked) { border-color:var(--primary); background:var(--primary-light); box-shadow:0 0 0 2px rgba(26,127,126,.2); }
.vit-strip { display:flex; flex-wrap:wrap; gap:8px; margin-top:6px; }
.vit-chip { border:1px solid var(--border); border-radius:9px; padding:6px 11px; font-size:12.5px; background:var(--bg); }
.vit-chip b { font-weight:700; }
.note-item { border:1px solid var(--border); border-radius:12px; padding:14px 16px; margin-bottom:12px; }
.note-top { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-bottom:8px; }
.note-prog { font-size:13px; font-weight:700; }
.note-meta { font-size:12px; color:var(--text-muted); }
.note-dx { font-size:13.5px; font-weight:600; }
.note-sec { margin-top:8px; font-size:13px; }
.note-sec .lbl { font-size:10.5px; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); font-weight:700; }
.paid-chip { font-size:11px; font-weight:700; padding:2px 9px; border-radius:20px; }
.paid-chip.yes { background:var(--green-bg); color:var(--green-text); }
.paid-chip.no { background:var(--amber-bg); color:var(--amber-text); }
.doc-note { font-size:11.5px; color:var(--text-muted); margin-top:4px; }
.two-col { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
@media (max-width:760px){ .two-col{ grid-template-columns:1fr; } }
CSS;
$headExtra .= "\n</style>";
require __DIR__ . '/partials/head.php';
$navActive = 'ipd';
require __DIR__ . '/partials/sidebar.php';
?>
        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Ward Round Note</div>
                    <div class="page-sub"><a href="ipd_admission.php?id=<?= $admissionId ?>" style="color:var(--primary);font-weight:600;">&larr; Back to stay</a></div>
                </div>
            </div>

            <?php if (isset($_GET['saved'])): ?><div class="alert success">Ward round note saved.</div><?php endif; ?>
            <?php if ($err): ?><div class="alert error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

            <!-- Auto-filled header (read only) -->
            <div class="wr-head">
                <div class="kv">
                    <div><div class="k">Patient</div><div class="v"><?= htmlspecialchars($adm['patient_name']) ?></div></div>
                    <div><div class="k">MR No.</div><div class="v"><?= htmlspecialchars($adm['mrn']) ?></div></div>
                    <div><div class="k">Adm No.</div><div class="v">#<?= (int) $adm['id'] ?></div></div>
                    <div><div class="k">Age / Sex</div><div class="v"><?= htmlspecialchars(ipd_round_age($adm['dob'])) ?> &middot; <?= htmlspecialchars($genderLabels[$adm['gender']] ?? $adm['gender']) ?></div></div>
                    <div><div class="k">Ward / Room</div><div class="v"><?= htmlspecialchars($adm['ward']) ?> &middot; <?= (int) $adm['room_no'] ?></div></div>
                    <div><div class="k">Consultant</div><div class="v"><?= htmlspecialchars($authorName) ?></div></div>
                    <div><div class="k">Date / Time</div><div class="v"><?= date('d/m, H:i') ?></div></div>
                    <div><div class="k">Hospital Day</div><div class="v"><?= $hospitalDay ?></div></div>
                </div>
            </div>

            <?php if (!$isOpen): ?>
            <div class="alert" style="background:#F1F5F9;color:var(--text-secondary);">This admission is <?= htmlspecialchars(strtolower(str_replace('_',' ',$adm['status']))) ?> — new ward-round notes are closed. Existing notes remain below.</div>
            <?php elseif ($canWrite): ?>
            <form method="POST" action="ipd_ward_round.php?id=<?= $admissionId ?>">
                <input type="hidden" name="action" value="save_round">

                <div class="card">
                    <div class="two-col">
                        <div class="wr-field">
                            <label>Primary Diagnosis <span class="wr-req">*</span></label>
                            <input type="text" name="primary_diagnosis" maxlength="500" value="<?= htmlspecialchars($form['primary_diagnosis']) ?>" placeholder="e.g. Community Acquired Pneumonia" required>
                        </div>
                        <div class="wr-field">
                            <label>Secondary Diagnosis / Comorbidities</label>
                            <input type="text" name="secondary_diagnosis" maxlength="500" value="<?= htmlspecialchars($form['secondary_diagnosis']) ?>" placeholder="e.g. Bronchial Asthma; Iron Deficiency Anaemia">
                        </div>
                    </div>
                    <div class="wr-field">
                        <label>Active Complaints</label>
                        <input type="text" name="active_complaints" maxlength="500" value="<?= htmlspecialchars($form['active_complaints']) ?>" placeholder="e.g. Fever; Cough; Poor Oral Intake">
                    </div>

                    <div class="wr-field">
                        <label>Overall Clinical Progress <span class="wr-req">*</span></label>
                        <div class="prog-opts">
                            <?php foreach ($progressMeta as $val => [$glyph, $label, $col]): ?>
                            <label class="prog-opt">
                                <input type="radio" name="progress" value="<?= $val ?>" <?= $form['progress'] === $val ? 'checked' : '' ?>>
                                <span><?= $glyph ?></span> <span><?= $label ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Current vitals (auto, read-only) -->
                    <div class="wr-field">
                        <label>Current Vitals <span class="muted" style="font-weight:500;">(latest nursing observation)</span></label>
                        <?php if ($latestVitals): ?>
                        <div class="vit-strip">
                            <?php if ($latestVitals['temp_f'] !== null): ?><span class="vit-chip">Temp <b><?= htmlspecialchars($latestVitals['temp_f']) ?>&deg;F</b></span><?php endif; ?>
                            <?php if ($latestVitals['pulse_bpm'] !== null): ?><span class="vit-chip">Pulse <b><?= (int) $latestVitals['pulse_bpm'] ?></b></span><?php endif; ?>
                            <?php if ($latestVitals['resp_rate'] !== null): ?><span class="vit-chip">Resp <b><?= (int) $latestVitals['resp_rate'] ?></b></span><?php endif; ?>
                            <?php if ($latestVitals['spo2_pct'] !== null): ?><span class="vit-chip">SpO&#8322; <b><?= (int) $latestVitals['spo2_pct'] ?>%</b></span><?php endif; ?>
                            <?php if ($latestVitals['systolic_bp'] !== null || $latestVitals['diastolic_bp'] !== null): ?><span class="vit-chip">BP <b><?= (int) $latestVitals['systolic_bp'] ?>/<?= (int) $latestVitals['diastolic_bp'] ?></b></span><?php endif; ?>
                            <?php if ($latestVitals['weight_kg'] !== null): ?><span class="vit-chip">Wt <b><?= htmlspecialchars($latestVitals['weight_kg']) ?>kg</b></span><?php endif; ?>
                            <span class="vit-chip" style="color:var(--text-muted);">Last: <?= date('d/m h:i A', strtotime($latestVitals['recorded_at'])) ?></span>
                        </div>
                        <?php else: ?>
                        <div class="muted" style="font-size:12.5px;">No vitals recorded yet (nursing vitals arrive with the ward's flow sheet).</div>
                        <?php endif; ?>
                    </div>

                    <div class="two-col">
                        <div class="wr-field">
                            <label>Positive Clinical Findings</label>
                            <textarea name="positive_findings" placeholder="e.g. Mild respiratory distress; bilateral basal crepitations"><?= htmlspecialchars($form['positive_findings']) ?></textarea>
                        </div>
                        <div class="wr-field">
                            <label>Investigation Review</label>
                            <textarea name="investigation_review" placeholder="e.g. CRP decreasing. CBC improving. Blood culture pending."><?= htmlspecialchars($form['investigation_review']) ?></textarea>
                        </div>
                    </div>

                    <div class="wr-field">
                        <label>Clinical Assessment</label>
                        <textarea name="clinical_assessment" placeholder="e.g. Clinically improving. Afebrile 24h. Still requires IV antibiotics."><?= htmlspecialchars($form['clinical_assessment']) ?></textarea>
                    </div>
                    <div class="wr-field">
                        <label>Management Plan</label>
                        <textarea name="management_plan" style="min-height:80px;" placeholder="e.g. Continue IV Ceftriaxone. Repeat CBC tomorrow. Continue oxygen. Review tomorrow morning."><?= htmlspecialchars($form['management_plan']) ?></textarea>
                        <div class="doc-note">Documentation only — this does not create medication or lab orders. A nurse still transcribes into the orders module.</div>
                    </div>

                    <div class="two-col">
                        <div class="wr-field">
                            <label>Family Counselling</label>
                            <textarea name="family_counselling" placeholder="e.g. Parents updated regarding progress. Warning signs explained."><?= htmlspecialchars($form['family_counselling']) ?></textarea>
                        </div>
                        <div class="wr-field">
                            <label>Next Review</label>
                            <select name="next_review">
                                <option value="">— select —</option>
                                <?php foreach ($nextReviewLabels as $val => $label): ?>
                                <option value="<?= $val ?>" <?= $form['next_review'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="margin-top:6px;"><button type="submit" class="btn">Save Ward Round</button></div>
                    <div class="doc-note" style="margin-top:8px;">Requires Primary Diagnosis, Overall Clinical Progress, and at least one of Clinical Assessment or Management Plan.</div>
                </div>
            </form>
            <?php else: ?>
            <div class="alert" style="background:#F1F5F9;color:var(--text-secondary);">You can view the ward-round history but not write notes.</div>
            <?php endif; ?>

            <!-- Read-only note timeline -->
            <div class="section-title" style="margin-top:24px;">Ward-round history</div>
            <div class="section-sub" style="margin-bottom:12px;">Each note is a permanent record. Corrections are entered as a new note.</div>
            <?php if (!$notes): ?>
            <div class="empty" style="padding:24px;"><strong>No ward-round notes yet</strong>The first note pre-fills the primary diagnosis from the admission's provisional diagnosis.</div>
            <?php else: ?>
            <?php foreach ($notes as $n): [$glyph,$plabel,$pcol] = $progressMeta[$n['progress']] ?? ['','',''];?>
            <div class="note-item">
                <div class="note-top">
                    <div class="note-prog" style="color:<?= $pcol ?>;"><?= $glyph ?> <?= htmlspecialchars($plabel) ?></div>
                    <div class="note-meta">
                        Day <?= (int) $n['hospital_day'] ?> &middot; <?= date('d/m/Y H:i', strtotime($n['visited_at'])) ?> &middot; <?= htmlspecialchars($n['author_name']) ?>
                        <?php if (!$hideMoney): ?>
                        &middot; <span class="paid-chip <?= $n['is_paid'] ? 'yes' : 'no' ?>"><?= $n['is_paid'] ? 'Paid' : 'Unpaid' ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="note-dx"><?= htmlspecialchars($n['primary_diagnosis']) ?></div>
                <?php if ($n['secondary_diagnosis']): ?><div class="note-sec"><span class="lbl">Comorbidities</span> <?= htmlspecialchars($n['secondary_diagnosis']) ?></div><?php endif; ?>
                <?php if ($n['active_complaints']): ?><div class="note-sec"><span class="lbl">Complaints</span> <?= htmlspecialchars($n['active_complaints']) ?></div><?php endif; ?>
                <?php if ($n['positive_findings']): ?><div class="note-sec"><span class="lbl">Findings</span> <?= nl2br(htmlspecialchars($n['positive_findings'])) ?></div><?php endif; ?>
                <?php if ($n['investigation_review']): ?><div class="note-sec"><span class="lbl">Investigations</span> <?= nl2br(htmlspecialchars($n['investigation_review'])) ?></div><?php endif; ?>
                <?php if ($n['clinical_assessment']): ?><div class="note-sec"><span class="lbl">Assessment</span> <?= nl2br(htmlspecialchars($n['clinical_assessment'])) ?></div><?php endif; ?>
                <?php if ($n['management_plan']): ?><div class="note-sec"><span class="lbl">Plan</span> <?= nl2br(htmlspecialchars($n['management_plan'])) ?></div><?php endif; ?>
                <?php if ($n['family_counselling']): ?><div class="note-sec"><span class="lbl">Family</span> <?= nl2br(htmlspecialchars($n['family_counselling'])) ?></div><?php endif; ?>
                <?php if ($n['next_review']): ?><div class="note-sec"><span class="lbl">Next review</span> <?= htmlspecialchars($nextReviewLabels[$n['next_review']] ?? $n['next_review']) ?></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
