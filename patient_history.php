<?php
/**
 * Patient history fragment — this doctor's own previous visits and notes.
 *
 * Returns an HTML fragment (not a full page, not JSON) for the History drawer
 * in my_queue.php. HTML because the markup is trivial and rendering it here
 * keeps escaping in PHP, where htmlspecialchars() is the default habit — a JSON
 * endpoint would push note text through JS string concatenation and invite an
 * injection through a patient's own clinical note.
 *
 * PRIVACY: every note returned belongs to the signed-in doctor. The read goes
 * through consultation_note_history(), which filters doctor_id in SQL. A doctor
 * opening the history of a patient another doctor also treats sees only their
 * OWN notes — the other doctor's entries do not appear, and there is no way to
 * ask for them. See config/consultation_notes.php for the full contract.
 *
 * The visit list is likewise scoped to this doctor's visits, so the dates shown
 * are "when I saw this patient", not the patient's whole clinic history.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/consultation_notes.php';

header('Content-Type: text/html; charset=utf-8');
// A private clinical record must never sit in a shared or browser cache.
header('Cache-Control: no-store, no-cache, must-revalidate, private');

$baseRole = $_SESSION['base_role'] ?? '';
if ($baseRole !== 'DOCTOR' && $baseRole !== 'ADMIN') {
    http_response_code(403);
    exit('<div class="mq-hist-empty">Forbidden.</div>');
}

// Effective user — under "View as staff" this is the impersonated doctor, which
// is the identity whose notes should be shown.
$doctorId  = (int) $_SESSION['user_id'];
$patientId = (int) ($_GET['patient_id'] ?? 0);

if ($patientId <= 0) {
    http_response_code(400);
    exit('<div class="mq-hist-empty">No patient specified.</div>');
}

$pst = $pdo->prepare('SELECT name, mrn FROM patients WHERE id = ?');
$pst->execute([$patientId]);
$patient = $pst->fetch();
if (!$patient) {
    http_response_code(404);
    exit('<div class="mq-hist-empty">Patient not found.</div>');
}

// This doctor's previous visits for this patient — today's visit excluded, since
// the row the doctor is looking at is not "history". Notes are joined in, so a
// visit with no note still shows as a dated attendance.
$vst = $pdo->prepare("
    SELECT v.id AS visit_id, v.visit_date, v.consult_status, v.consultation_fee_type,
           t.label AS type_label,
           n.note, n.created_at AS note_created, n.updated_at AS note_updated
    FROM visits v
    LEFT JOIN doctor_consult_types t ON t.id = v.doctor_consult_type_id
    LEFT JOIN consultation_notes n ON n.visit_id = v.id AND n.doctor_id = ?
    WHERE v.patient_id = ? AND v.doctor_id = ? AND v.visit_date < CURDATE()
    ORDER BY v.visit_date DESC, v.token_no DESC
    LIMIT 100
");
$vst->execute([$doctorId, $patientId, $doctorId]);
$rows = $vst->fetchAll();

if (!$rows) {
    echo '<div class="mq-hist-empty">No previous visits with you for '
        . htmlspecialchars($patient['name']) . '.<br>Notes you write today will appear here next time.</div>';
    exit;
}

$feeLabels = [
    'FREE_FOLLOWUP' => 'Free follow-up',
    'HALF_FOLLOWUP' => '50% follow-up',
    'THREE_QUARTER_FOLLOWUP' => '75% follow-up',
];

foreach ($rows as $r):
    // d/m/Y throughout — the app's display format.
    $dateLabel = date('d/m/Y', strtotime($r['visit_date']));
    $meta = [];
    if (!empty($r['type_label'])) {
        $meta[] = htmlspecialchars($r['type_label']);
    }
    if (isset($feeLabels[$r['consultation_fee_type'] ?? ''])) {
        $meta[] = $feeLabels[$r['consultation_fee_type']];
    }
    // An "edited" marker: updated_at moves on every revision, so a note whose
    // updated_at is later than created_at has been revised since it was written.
    $edited = !empty($r['note_updated']) && !empty($r['note_created'])
        && strtotime($r['note_updated']) > strtotime($r['note_created']) + 1;
?>
<div class="mq-hist-item">
    <div class="mq-hist-date"><?= $dateLabel ?></div>
    <div class="mq-hist-meta">
        <?= $meta ? implode(' · ', $meta) : 'Consultation' ?>
        <?php if ($edited): ?> · edited <?= date('d/m/Y', strtotime($r['note_updated'])) ?><?php endif; ?>
    </div>
    <?php if (!empty($r['note'])): ?>
        <?php // white-space:pre-wrap on .mq-hist-note preserves the doctor's line breaks
              // without needing nl2br, so the escaped text stays a single safe string. ?>
        <div class="mq-hist-note"><?= htmlspecialchars($r['note']) ?></div>
    <?php else: ?>
        <div class="mq-hist-meta" style="margin-top:6px;font-style:italic">No note recorded for this visit.</div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
