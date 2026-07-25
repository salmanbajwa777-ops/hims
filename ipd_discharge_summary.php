<?php
/**
 * IPD Discharge Summary — MANUAL (author-written, no AI).
 *
 * The doctor (or a staffer the admin has granted IPD_WRITE_SUMMARY per case)
 * writes the summary by hand. Final Diagnosis pre-fills from the latest
 * ward-round note's Primary Diagnosis; the ward-round notes are shown alongside
 * for reference. Save draft -> Finalize (locks).
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
refresh_session_permissions($pdo);

$uid = (int) $_SESSION['user_id'];
require_permission('IPD_WRITE_SUMMARY');

$admissionId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("
    SELECT a.*, v.token_no, p.mrn, p.name AS patient_name, p.dob, p.gender
    FROM ipd_admissions a
    JOIN visits v ON v.id = a.visit_id
    JOIN patients p ON p.id = v.patient_id
    WHERE a.id = ?
");
$stmt->execute([$admissionId]);
$adm = $stmt->fetch();
if (!$adm) { http_response_code(404); exit('IPD admission not found.'); }

// Latest ward-round note (seeds Final Diagnosis).
$ln = $pdo->prepare('SELECT * FROM ipd_doctor_visits WHERE admission_id = ? ORDER BY visited_at DESC, id DESC LIMIT 1');
$ln->execute([$admissionId]);
$latest = $ln->fetch() ?: null;

// Existing summary row (if any).
$sq = $pdo->prepare('SELECT * FROM ipd_discharge_summaries WHERE admission_id = ?');
$sq->execute([$admissionId]);
$summary = $sq->fetch() ?: null;

$flash = '';
$err = '';
$locked = $summary && $summary['status'] === 'finalized';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked) {
    $act = $_POST['action'] ?? '';
    $finalDx = mb_substr(trim($_POST['final_diagnosis'] ?? ''), 0, 500);
    $text = trim($_POST['summary_text'] ?? '');

    if ($act === 'save_summary' || $act === 'finalize_summary') {
        $status = $act === 'finalize_summary' ? 'finalized' : 'draft';
        if ($act === 'finalize_summary' && ($finalDx === '' || $text === '')) {
            $err = 'Final Diagnosis and the summary text are both required to finalize.';
        } else {
            if ($summary) {
                $pdo->prepare('UPDATE ipd_discharge_summaries SET final_diagnosis = ?, summary_text = ?, status = ?, written_by_id = ?, finalized_by_id = ?, finalized_at = ? WHERE admission_id = ?')
                    ->execute([
                        $finalDx ?: null, $text ?: null, $status, $uid,
                        $status === 'finalized' ? $uid : null,
                        $status === 'finalized' ? date('Y-m-d H:i:s') : null,
                        $admissionId,
                    ]);
            } else {
                $pdo->prepare('INSERT INTO ipd_discharge_summaries (admission_id, final_diagnosis, summary_text, status, written_by_id, finalized_by_id, generated_at, finalized_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)')
                    ->execute([
                        $admissionId, $finalDx ?: null, $text ?: null, $status, $uid,
                        $status === 'finalized' ? $uid : null,
                        $status === 'finalized' ? date('Y-m-d H:i:s') : null,
                    ]);
            }
            $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')
                ->execute([$uid, 'ipd_discharge_summary_' . $status, "IPD summary $status for admission #$admissionId"]);
            header('Location: ipd_discharge_summary.php?id=' . $admissionId . '&saved=1');
            exit;
        }
    }
}

// Pre-fill values.
$vFinalDx = $summary['final_diagnosis'] ?? ($latest['primary_diagnosis'] ?? ($adm['provisional_diagnosis'] ?? ''));
$vText    = $summary['summary_text'] ?? '';

// All ward-round notes for the reference panel.
$notes = $pdo->prepare('SELECT dv.*, u.name AS author_name FROM ipd_doctor_visits dv JOIN users u ON u.id = dv.doctor_id WHERE dv.admission_id = ? ORDER BY dv.visited_at ASC, dv.id ASC');
$notes->execute([$admissionId]);
$notes = $notes->fetchAll();

$progressLabels = ['IMPROVING' => '🟢 Improving', 'STABLE' => '🟡 Stable', 'SLOW' => '🟠 Slow Improvement', 'DETERIORATING' => '🔴 Deteriorating', 'CRITICAL' => '⚫ Critically Ill'];

$pageTitle = 'Discharge Summary — ' . $adm['patient_name'];
$headExtra = <<<CSS
<style>
.two-col { display:grid; grid-template-columns:1.3fr 1fr; gap:20px; align-items:start; }
@media (max-width:900px){ .two-col{ grid-template-columns:1fr; } }
.ds-field { margin-bottom:16px; }
.ds-field label { display:block; font-size:12.5px; font-weight:600; color:var(--text-secondary); margin-bottom:6px; }
.ds-field input, .ds-field textarea { width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:10px; font:inherit; font-size:13.5px; background:var(--bg); }
.ds-field textarea { resize:vertical; min-height:280px; line-height:1.6; }
.ds-field input:focus, .ds-field textarea:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(26,127,126,.15); background:#fff; }
.ref-note { border:1px solid var(--border); border-radius:10px; padding:11px 13px; margin-bottom:10px; font-size:12.5px; }
.ref-note .top { font-weight:700; margin-bottom:3px; }
.ref-note .sec { margin-top:4px; } .ref-note .lbl { color:var(--text-muted); font-weight:700; font-size:10.5px; text-transform:uppercase; }
.lock-badge { font-size:11px; font-weight:700; padding:3px 10px; border-radius:20px; background:var(--green-bg); color:var(--green-text); }
CSS;
$headExtra .= "\n</style>";
require __DIR__ . '/partials/head.php';
$navActive = 'ipd';
require __DIR__ . '/partials/sidebar.php';
?>
        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Discharge Summary</div>
                    <div class="page-sub"><a href="ipd_admission.php?id=<?= $admissionId ?>" style="color:var(--primary);font-weight:600;">&larr; Back to stay</a> &middot; <?= htmlspecialchars($adm['patient_name']) ?> &middot; <span class="mono"><?= htmlspecialchars($adm['mrn']) ?></span></div>
                </div>
                <?php if ($locked): ?><span class="lock-badge">Finalized</span><?php endif; ?>
            </div>

            <?php if (isset($_GET['saved'])): ?><div class="alert success">Discharge summary saved.</div><?php endif; ?>
            <?php if ($err): ?><div class="alert error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

            <div class="two-col">
                <div class="card">
                    <?php if ($locked): ?>
                    <div class="ds-field">
                        <label>Final Diagnosis</label>
                        <div style="font-weight:600;font-size:15px;"><?= htmlspecialchars($vFinalDx ?: '—') ?></div>
                    </div>
                    <div class="ds-field">
                        <label>Summary</label>
                        <div style="white-space:pre-wrap;line-height:1.6;font-size:13.5px;"><?= htmlspecialchars($vText) ?></div>
                    </div>
                    <div class="muted" style="font-size:12px;">Finalized <?= $summary['finalized_at'] ? date('d/m/Y H:i', strtotime($summary['finalized_at'])) : '' ?>. A finalized summary is read-only.</div>
                    <?php else: ?>
                    <form method="POST" action="ipd_discharge_summary.php?id=<?= $admissionId ?>">
                        <div class="ds-field">
                            <label>Final Diagnosis <span class="muted" style="font-weight:500;">(pre-filled from the latest ward round)</span></label>
                            <input type="text" name="final_diagnosis" maxlength="500" value="<?= htmlspecialchars($vFinalDx) ?>" placeholder="e.g. Community Acquired Pneumonia — resolved">
                        </div>
                        <div class="ds-field">
                            <label>Discharge Summary</label>
                            <textarea name="summary_text" placeholder="Course in hospital, treatment given, condition at discharge, medications, follow-up advice, warning signs…"><?= htmlspecialchars($vText) ?></textarea>
                        </div>
                        <div style="display:flex;gap:10px;">
                            <button type="submit" name="action" value="save_summary" class="btn secondary">Save draft</button>
                            <button type="submit" name="action" value="finalize_summary" class="btn" onclick="return confirm('Finalize the discharge summary? It becomes read-only.');">Finalize</button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="section-title">Ward-round notes</div>
                    <div class="section-sub">Reference — the clinical trail for this admission.</div>
                    <div style="margin-top:12px;">
                        <?php if (!$notes): ?>
                        <div class="muted">No ward-round notes recorded.</div>
                        <?php endif; ?>
                        <?php foreach ($notes as $n): ?>
                        <div class="ref-note">
                            <div class="top">Day <?= (int) $n['hospital_day'] ?> &middot; <?= date('d/m H:i', strtotime($n['visited_at'])) ?> &middot; <?= htmlspecialchars($progressLabels[$n['progress']] ?? $n['progress']) ?></div>
                            <div><b><?= htmlspecialchars($n['primary_diagnosis']) ?></b></div>
                            <?php if ($n['clinical_assessment']): ?><div class="sec"><span class="lbl">Assessment</span> <?= htmlspecialchars($n['clinical_assessment']) ?></div><?php endif; ?>
                            <?php if ($n['management_plan']): ?><div class="sec"><span class="lbl">Plan</span> <?= htmlspecialchars($n['management_plan']) ?></div><?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
