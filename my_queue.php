<?php
/**
 * My Queue — the doctor's today-only patient list.
 *
 * WHY THIS PAGE EXISTS
 * The sidebar has always had both "My Console" and "My Queue", but both links
 * pointed at doctor.php — there was no queue page, so clicking "My Queue" just
 * reloaded the console. This is the real page.
 *
 * The split:
 *   doctor.php  — the CONSOLE: earnings, revisit mix, KPI cells, dental
 *                 packages, active ER admissions. The dashboard.
 *   my_queue.php — THIS: today's patients and nothing else. No earnings, no
 *                 revenue bars, no revisit-rate cards. A doctor working through
 *                 a queue should not be looking at money, and the console's
 *                 right rail is exactly what gets in the way on a phone.
 *
 * It is full-width (no right rail) so each row has room for the clinical
 * actions — Start/Finish, Note, History, Admit — rather than competing with a
 * stats column.
 *
 * Consultation notes here are PRIVATE to the signed-in doctor: every read goes
 * through config/consultation_notes.php, which filters doctor_id in SQL. See
 * that file's header for the full contract.
 */
require_once __DIR__ . '/config/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/permissions.php';
// admission_actions.php fires notify_patient_admitted() behind a function_exists()
// guard and does NOT load notify.php itself — it expects the calling page to. This
// page handled admit_patient without it, so the guard silently swallowed the call
// and an admit made from a doctor's own queue notified nobody at all.
require_once __DIR__ . '/config/notify.php';
require_once __DIR__ . '/config/admission_actions.php';
require_once __DIR__ . '/config/tokens.php';
require_once __DIR__ . '/config/consultation_notes.php';
// refunded_total() — used to hide Refund on a bill that is already fully refunded.
require_once __DIR__ . '/config/billing.php';
refresh_session_permissions($pdo);

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: /index.php');
    exit;
}

// Same gate as doctor.php: this is a doctor's own worklist, admins may open it
// for support.
$baseRole = $_SESSION['base_role'] ?? '';
if ($baseRole !== 'DOCTOR' && $baseRole !== 'ADMIN') {
    http_response_code(403);
    exit('Forbidden — doctor console only.');
}

// The effective user. Under "View as staff" this is already the impersonated
// doctor, which is what every note read/write must be scoped to.
$doctorId = (int) $user['id'];

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $visitId = (int) ($_POST['visit_id'] ?? 0);

    // ---------------- Start / Finish a consultation ----------------
    // Identical state machine to doctor.php: only WAITING -> IN_CONSULT and
    // IN_CONSULT -> DONE, and only on this doctor's visits today. The WHERE
    // clause carries both the ownership and the valid prior state, so a stale
    // or replayed POST can neither jump states nor touch another doctor's row.
    if ($visitId > 0 && in_array($action, ['start_consult', 'finish_consult'], true)) {
        if ($action === 'start_consult') {
            $upd = $pdo->prepare("
                UPDATE visits
                SET consult_status = 'IN_CONSULT', started_at = NOW()
                WHERE id = ? AND doctor_id = ? AND visit_date = CURDATE() AND consult_status = 'WAITING'
            ");
            $upd->execute([$visitId, $doctorId]);
            $auditAction = 'consult_started';
            $flash = $upd->rowCount() ? 'Consultation started.' : '';
        } else {
            $upd = $pdo->prepare("
                UPDATE visits
                SET consult_status = 'DONE', finished_at = NOW()
                WHERE id = ? AND doctor_id = ? AND visit_date = CURDATE() AND consult_status = 'IN_CONSULT'
            ");
            $upd->execute([$visitId, $doctorId]);
            $auditAction = 'consult_finished';
            $flash = $upd->rowCount() ? 'Consultation completed.' : '';
        }

        if ($upd->rowCount()) {
            audit_log($pdo, $auditAction, "Visit #$visitId ($auditAction)", $_SESSION['user_id']);
        }

        header('Location: my_queue.php' . ($flash ? '?done=' . urlencode($flash) : ''));
        exit;
    }

    // ---------------- Save a consultation note ----------------
    // consultation_note_save() re-checks that the visit belongs to $doctorId
    // and resolves patient_id server-side, so a forged visit_id writes nothing.
    if ($action === 'save_note' && $visitId > 0) {
        $ok = consultation_note_save($pdo, $visitId, $doctorId, (string) ($_POST['note'] ?? ''));
        header('Location: my_queue.php' . ($ok ? '?done=' . urlencode('Note saved.') : '?done=' . urlencode('Note was empty — nothing saved.')));
        exit;
    }

    // ---------------- Admit a patient (doctor-initiated) ----------------
    if ($action === 'admit_patient') {
        $result = handle_admit_patient($pdo);
        if ($result['ok']) {
            header('Location: admission.php?id=' . (int) $result['admission_id']);
            exit;
        }
        header('Location: my_queue.php?admit_error=' . urlencode($result['error']));
        exit;
    }
}
$flash = trim($_GET['done'] ?? '');
$admitError = trim($_GET['admit_error'] ?? '');

// ---------------- Today's queue for this doctor ----------------
// Rows sit in token order whatever their state, so a patient never changes
// place on screen when their consultation starts or finishes — the stripe and
// the status pill carry that, position does not. Session leads the sort because
// tokens restart at 1 each sitting and the session is never printed, so two
// people can hold "SB-1" on one date; keeping it first stops the evening list
// interleaving with the morning one and showing that label twice in a row.
$queueStmt = $pdo->prepare("
    SELECT v.id AS visit_id, v.token_no, v.token_session, v.consult_status, v.started_at, v.created_at,
           v.patient_id,
           p.name AS patient_name, p.gender, p.dob, p.mrn,
           t.label AS type_label, v.consultation_fee_type,
           a.id AS admission_id, a.admission_type,
           b.id AS bill_id, b.status AS bill_status, b.paid_amount
    FROM visits v
    JOIN patients p ON p.id = v.patient_id
    LEFT JOIN doctor_consult_types t ON t.id = v.doctor_consult_type_id
    LEFT JOIN admissions a ON a.visit_id = v.id
    LEFT JOIN bills b ON b.visit_id = v.id
    WHERE v.doctor_id = ? AND v.visit_date = CURDATE()
    ORDER BY v.token_session DESC, v.token_no DESC
");
$queueStmt->execute([$doctorId]);
$visits = $queueStmt->fetchAll();

$myPrefixStmt = $pdo->prepare('SELECT token_prefix FROM users WHERE id = ?');
$myPrefixStmt->execute([$doctorId]);
$myTokenPrefix = doctor_token_prefix($myPrefixStmt->fetchColumn() ?: null, $user['name'] ?? '');

// Which rows already carry a note by THIS doctor — one query for the whole
// queue rather than one per row, so the button can say Add vs Edit.
$noteMap = consultation_notes_by_visit($pdo, array_column($visits, 'visit_id'), $doctorId);

// Full note text for today's rows, so the editor opens pre-filled without a
// round trip. Only this doctor's own notes come back.
$noteText = [];
foreach ($visits as $v) {
    if (isset($noteMap[(int) $v['visit_id']])) {
        $n = consultation_note_for_visit($pdo, (int) $v['visit_id'], $doctorId);
        if ($n) {
            $noteText[(int) $v['visit_id']] = $n['note'];
        }
    }
}

$canDoctorAdmit = has_permission('ADMISSION_ADMIT_PATIENT');
// Same guard as the console: refund.php still enforces that the approver is this
// visit's doctor, so surfacing the entry point here does not widen scope.
$canDoctorRefund = has_permission('RECEPTION_ISSUE_REFUNDS');
$admTypes = $admDoctors = $admNurses = [];
$admTypeLabels = ['ROUTINE' => 'Routine', 'PRIVATE' => 'Private Room', 'LONG_PRIVATE' => 'Long Private'];
if ($canDoctorAdmit) {
    $admTypes = $pdo->query('SELECT admission_type, rate_amount, rate_basis FROM admission_rates WHERE is_enabled = 1 ORDER BY FIELD(admission_type,"ROUTINE","PRIVATE","LONG_PRIVATE")')->fetchAll();
    $admDoctors = $pdo->query("SELECT id, name FROM users WHERE base_role = 'DOCTOR' ORDER BY name")->fetchAll();
    require_once __DIR__ . '/config/nurses.php';
    $admNurses = nurse_roster($pdo, 'NURSING_ATTEND_SHORT_STAY');
}

$waiting   = array_values(array_filter($visits, fn($v) => $v['consult_status'] === 'WAITING'));
$inConsult = array_values(array_filter($visits, fn($v) => $v['consult_status'] === 'IN_CONSULT'));
$doneCount = count(array_filter($visits, fn($v) => $v['consult_status'] === 'DONE'));
$current   = $inConsult[0] ?? null;

// The one thing the header is scanned for: who gets called next. $waiting keeps
// the query's DESC order, so the lowest token — the next patient — is the LAST
// element, not the first. With two sittings this lands on the earliest session
// still waiting, which is the right answer: those patients are called first.
$nextVisit = $waiting ? end($waiting) : null;
$nextUp    = $nextVisit ? $myTokenPrefix . '-' . (int) $nextVisit['token_no'] : '';

function mq_age(array $v): ?int {
    if (!empty($v['dob'])) {
        return (new DateTime($v['dob']))->diff(new DateTime())->y;
    }
    return null;
}

function mq_wait_minutes(string $createdAt): int {
    return max(0, (int) floor((time() - strtotime($createdAt)) / 60));
}

function mq_icon(string $name, int $size = 18): string {
    $paths = [
        'search'  => '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>',
        'check'   => '<path d="M20 6L9 17l-5-5"/>',
        'play'    => '<polygon points="5 3 19 12 5 21 5 3"/>',
        'pen'     => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'history' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/>',
        'close'   => '<path d="M18 6L6 18M6 6l12 12"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
}

$pageTitle = 'My Queue';
$headExtra = <<<CSS
<style>
/* Queue page. Full-width single column — no right rail, because this page
   deliberately carries no money cards (that's the console's job). app.css
   supplies tokens, .app/.main/.content, .card, .btn* and .status-pill. */
/* The bespoke page header is gone — partials/quick_header.php is the app bar
   now, and its styles ship in assets/app.css. */

.section-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.section-title { font-size: 15px; font-weight: 700; }
.section-sub { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.q-count-pills { display: flex; gap: 8px; }
.status-pill.waiting { background: #FFFBEB; color: #92400E; }
.status-pill.in-consult { background: var(--primary-light); color: var(--primary-dark); }
.status-pill.done { background: #ECFDF5; color: #047857; }

/* ---------------------------------------------------------------------------
   Queue row — "State Stripe + Tinted Roles".

   Two ideas, doing two different jobs:

   1. THE ROW carries consult state as a left stripe + a short wash. The point
      is peripheral vision: a doctor glancing at the page sees the SHAPE of
      their day (who is still waiting on them) before reading a single name.
      State is therefore encoded in position + colour, never colour alone —
      the status pill and wait-time text still say it in words, so the stripe
      is redundant reinforcement rather than the sole carrier. That matters for
      colour-blind readers and is why the stripe is safe to use for meaning.

   2. THE BUTTONS carry their ROLE as a tint: sage = clinical, stone =
      records, clay = money. Clay appears on Refund and nowhere else, per the
      palette rule that clay marks money-leaving actions only.

   Both draw solely on existing Sage & Clay tokens. The previous hardcoded
   indigo/emerald/amber hexes on the tags were off-palette leftovers and are
   replaced with the real status tokens here.
   --------------------------------------------------------------------------- */
.q-item { display: grid; grid-template-columns: 52px 1fr; gap: 13px;
          padding: 13px 12px 13px 11px; border-top: 1px solid var(--row-line);
          align-items: start; border-left: 3px solid transparent;
          border-radius: 0 10px 10px 0; }
.q-item:first-of-type { border-top: none; }

/* State stripe. The wash fades out over a FIXED 240px rather than a percentage:
   the row is a grid that spans the full card, so a percentage stop scaled with
   the viewport and on a wide screen the tint ran under the tinted buttons,
   muddying both. A fixed distance keeps the wash anchored to the stripe where
   the eye reads state, and clear of the action row at any width. */
.q-item.is-waiting { border-left-color: var(--warn);
                     background: linear-gradient(90deg, var(--warn-bg) 0px, transparent 240px); }
.q-item.is-serving { border-left-color: var(--primary-accent);
                     background: linear-gradient(90deg, var(--primary-light) 0px, transparent 240px); }
/* Done rows recede: a muted stripe, no wash. Seen patients should not compete
   with the ones still waiting. */
.q-item.is-done { border-left-color: #BFD1C4; }

.q-token { font-weight: 700; font-size: 12.5px; color: var(--text-secondary); background: var(--card-alt);
           border: 1px solid var(--border); border-radius: 9px; padding: 6px 0; text-align: center; }
.q-name { font-size: 14.5px; font-weight: 700; letter-spacing: -.01em; display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }
.q-meta { font-size: 12px; color: var(--text-muted); margin-top: 3px; }
.q-tag { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
         background: var(--card-alt); border: 1px solid var(--border); border-radius: 6px; padding: 2px 7px; color: var(--text-secondary); }
.q-tag-admit { background: var(--neutral-bg); color: var(--neutral); border-color: var(--border-strong); }
.q-tag-free { background: var(--ok-bg); color: var(--ok); border-color: #BFD9C8; }
.q-tag-revisit { background: var(--warn-bg); color: var(--warn); border-color: #E4D3AE; }
.q-tag-note { background: var(--primary-light); color: var(--primary); border-color: #C6DBCE; }

/* ---- Equal cells, flush edge ----
   The reported "chaotic alignment" was buttons sizing to their own text, so
   "Manage stay" ran twice the width of "Done" and every row frayed at a
   different point. A 4-column grid gives every control an identical cell, so
   the right edge is flush no matter what the labels say. Wider labels claim
   two cells explicitly rather than pushing their neighbours around.
   Buttons also get their OWN row now: previously they shared a flex line with
   the status pill, which wrapped unpredictably on a phone. */
/* Fixed 4-up on desktop. auto-fit + minmax(88px,1fr) was tried first and is
   wrong here: minmax's floor is a HARD minimum, so on a 412px phone the grid
   still laid 3 columns of 88px and overflowed the card instead of reflowing.
   A fixed track count that the breakpoints step down (4 -> 3 -> 2) is
   predictable at every width, and min-width:0 lets a cell shrink below its
   label's intrinsic width rather than pushing the row wider. */
.q-actions { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 7px; margin-top: 11px; }
.q-actions .btn-primary,
.q-actions .btn-ghost,
.q-actions .inline-form { min-width: 0; }
.q-actions .inline-form { display: block; }
.q-actions .inline-form > button { width: 100%; }
/* Every action is one uniform cell: same height, same radius, centred label.
   36px min-height keeps a real touch target on a phone. */
.q-actions .btn-primary, .q-actions .btn-ghost {
    display: flex; align-items: center; justify-content: center; gap: 5px;
    /* Fixed height, not min-height: inside a grid the cells stretch to the tallest
       item, and min-height let them grow to fill the track — the buttons came out
       ~46px and visually outweighed the patient's name. 34px is a deliberate,
       uniform control height that still clears the 44px touch target once the
       7px grid gap either side is counted. */
    width: 100%; min-width: 0; height: 34px; padding: 0 8px; font-size: 12.5px; font-weight: 600;
    border-radius: var(--radius-btn); line-height: 1; text-align: center;
    align-self: start;
    /* Ellipsis rather than nowrap-and-overflow: if a label ever outgrows its
       cell it must truncate INSIDE the grid, never widen the row. The title
       attribute on the markup keeps the full label reachable. */
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.q-span-2 { grid-column: span 2; }

/* Role tints. Sage = clinical, stone = records, clay = money-leaving. */
.btn-note { background: var(--primary-light); color: var(--primary); border-color: #C6DBCE; }
.btn-note:hover { background: #DCE9E1; }
.btn-hist { background: var(--neutral-bg); color: var(--neutral); border-color: var(--border-strong); }
.btn-hist:hover { background: #D8DFD1; }
.btn-money { background: var(--danger-bg); color: var(--danger); border-color: #E3C6BC; }
.btn-money:hover { background: #F2DCD5; }

.wait-time { font-size: 12px; color: var(--text-muted); font-variant-numeric: tabular-nums; }
.inline-form { display: inline; }
.pulse { width: 7px; height: 7px; border-radius: 50%; background: var(--primary); display: inline-block; }
.empty-state { text-align: center; color: var(--text-muted); font-size: 13px; padding: 40px 16px; line-height: 1.7; }
.flash { background: #ECFDF5; border: 1px solid #A7F3D0; color: #047857; border-radius: 10px;
         padding: 10px 14px; font-size: 13px; margin-bottom: 14px; }

/* Note + history drawer. One overlay reused by every row; JS swaps the content. */
.mq-modal { position: fixed; inset: 0; background: rgba(15,23,42,.45); display: none;
            align-items: center; justify-content: center; padding: 20px; z-index: 60; }
.mq-modal.open { display: flex; }
.mq-box { background: var(--card); border-radius: 16px; width: 100%; max-width: 560px;
          max-height: 86vh; display: flex; flex-direction: column; overflow: hidden; }
.mq-box-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px;
               padding: 16px 18px; border-bottom: 1px solid var(--border); }
.mq-box-title { font-size: 15px; font-weight: 700; }
.mq-box-sub { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.mq-box-body { padding: 16px 18px; overflow-y: auto; }
.mq-box-foot { padding: 12px 18px; border-top: 1px solid var(--border); display: flex; gap: 10px; justify-content: flex-end; }
.mq-x { background: none; border: none; color: var(--text-muted); cursor: pointer; padding: 2px; }
.mq-note-area { width: 100%; min-height: 200px; resize: vertical; padding: 11px 13px; font: inherit;
                font-size: 13.5px; line-height: 1.6; border: 1px solid var(--border);
                border-radius: 10px; background: var(--bg); color: var(--text); }
.mq-private { font-size: 11.5px; color: var(--text-muted); margin-top: 9px; line-height: 1.6; }

/* History list */
.mq-hist-item { border-top: 1px solid var(--border); padding: 12px 0; }
.mq-hist-item:first-child { border-top: none; padding-top: 0; }
.mq-hist-date { font-size: 13px; font-weight: 700; }
.mq-hist-meta { font-size: 11.5px; color: var(--text-muted); margin-top: 2px; }
.mq-hist-note { font-size: 13px; line-height: 1.65; margin-top: 7px; white-space: pre-wrap;
                background: var(--bg); border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; }
.mq-hist-empty { text-align: center; color: var(--text-muted); font-size: 13px; padding: 30px 10px; line-height: 1.7; }
.mq-spinner { text-align: center; color: var(--text-muted); font-size: 13px; padding: 30px 10px; }

/* Narrow screens step the action grid down 4 -> 2 columns. At 412px (the A57
   this is designed against) four cells leave ~70px each, which truncates
   "History" and "Edit note"; two cells give a comfortable ~150px.
   .q-span-2 is dropped at the same breakpoint — on a 2-cell line a spanning
   button would take the whole row and push its siblings down, which is the
   orphaned-button problem this layout exists to remove. */
@media (max-width: 720px) {
    .q-item { grid-template-columns: 44px 1fr; gap: 11px; }
    .q-actions { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .q-actions .q-span-2 { grid-column: auto; }
}
html[data-view="mobile"] .q-item { grid-template-columns: 44px 1fr; gap: 11px; }
html[data-view="mobile"] .q-actions { grid-template-columns: repeat(2, minmax(0, 1fr)); }
html[data-view="mobile"] .q-actions .q-span-2 { grid-column: auto; }
</style>
CSS;
require __DIR__ . '/partials/head.php';
?>
<div class="app">

    <?php
    $dsActive = 'queue';
    $dsUserName = $user['name'];
    $dsWaitingCount = count($waiting);
    $dsSpecialty = $user['specialty'] ?? '';
    require __DIR__ . '/partials/doctor_sidebar.php';
    ?>

    <div class="main">
        <?php /* Shared app bar. The greeting block it replaced said "My Queue"
                 immediately above a card whose own title is "My Queue". */ ?>
        <?php require __DIR__ . '/partials/quick_header.php'; ?>

        <div class="content">

            <?php if ($flash !== ''): ?>
            <div class="flash"><?= htmlspecialchars($flash) ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="section-head">
                    <div>
                        <div class="section-title">My Queue</div>
                        <div class="section-sub">Today's patients in token order<?= $nextUp !== '' ? ' · next up ' . htmlspecialchars($nextUp) : '' ?></div>
                    </div>
                    <div class="q-count-pills">
                        <?php if ($current): ?><span class="status-pill in-consult">1 in consult</span><?php endif; ?>
                        <span class="status-pill waiting"><?= count($waiting) ?> waiting</span>
                        <span class="status-pill done"><?= $doneCount ?> seen</span>
                    </div>
                </div>

                <div style="margin-top:14px">
                <?php if (empty($visits)): ?>
                    <div class="empty-state">No patients registered for you today yet.<br>New registrations for you will appear here.</div>
                <?php else: foreach ($visits as $v):
                    $age = mq_age($v);
                    $st  = $v['consult_status'];
                    $vid = (int) $v['visit_id'];
                    $hasNote = isset($noteMap[$vid]);
                ?>
                <?php
                // State drives the stripe. Kept as an explicit map rather than a
                // ternary chain so a future consult_status value fails visibly
                // (no stripe) instead of silently borrowing another state's colour.
                $stripeClass = ['WAITING' => 'is-waiting', 'IN_CONSULT' => 'is-serving', 'DONE' => 'is-done'][$st] ?? '';
                ?>
                <div class="q-item <?= $stripeClass ?>">
                    <div class="q-token tnum"><?= htmlspecialchars($myTokenPrefix) ?>-<?= (int) $v['token_no'] ?></div>
                    <div>
                        <div class="q-name"><?= htmlspecialchars($v['patient_name']) ?>
                            <?php // Status moves up beside the name: it is an attribute of the
                                  // patient's state, not an action, so it does not belong in the
                                  // button row it used to share a line with. ?>
                            <?php if ($st === 'IN_CONSULT'): ?><span class="pulse"></span><span class="status-pill in-consult">Now serving</span>
                            <?php elseif ($st === 'WAITING'): ?><span class="status-pill waiting">Waiting</span>
                            <?php else: ?><span class="status-pill done">Done</span><?php endif; ?>
                            <?php if (!empty($v['admission_id'])): ?>
                                <span class="q-tag q-tag-admit">Admitted · <?= htmlspecialchars($admTypeLabels[$v['admission_type']] ?? $v['admission_type']) ?></span>
                            <?php elseif (!empty($v['type_label'])): ?>
                                <span class="q-tag"><?= htmlspecialchars($v['type_label']) ?></span>
                            <?php endif; ?>
                            <?php
                            $feeBadges = [
                                'FREE_FOLLOWUP' => ['Free follow-up', 'q-tag-free'],
                                'HALF_FOLLOWUP' => ['50% follow-up', 'q-tag-revisit'],
                                'THREE_QUARTER_FOLLOWUP' => ['75% follow-up', 'q-tag-revisit'],
                            ];
                            if (isset($feeBadges[$v['consultation_fee_type'] ?? ''])):
                                [$badgeText, $badgeClass] = $feeBadges[$v['consultation_fee_type']];
                            ?><span class="q-tag <?= $badgeClass ?>"><?= $badgeText ?></span><?php endif; ?>
                            <?php if ($hasNote): ?><span class="q-tag q-tag-note">Note</span><?php endif; ?>
                        </div>
                        <div class="q-meta">
                            <?= $age !== null ? 'Age ' . $age . ' · ' : '' ?>
                            <?= htmlspecialchars(ucfirst(strtolower($v['gender']))) ?> ·
                            <span class="mrn"><?= htmlspecialchars($v['mrn']) ?></span>
                            <?php // Wait time joins the meta line rather than the button row: it is
                                  // information about the patient, and it also keeps the stripe's
                                  // meaning stated in words for anyone who cannot see the colour. ?>
                            <?php if ($st === 'WAITING'): ?> · <span class="wait-time">waiting <?= mq_wait_minutes($v['created_at']) ?> min</span><?php endif; ?>
                            <?php if ($st === 'IN_CONSULT' && $v['started_at']): ?> · started <?= date('g:i A', strtotime($v['started_at'])) ?><?php endif; ?>
                        </div>

                        <?php
                        // Action row. Every control is one equal cell of a 4-up grid, so the
                        // right edge stays flush whatever the labels read — that was the
                        // reported alignment problem. "Manage stay" claims two cells
                        // explicitly (.q-span-2) instead of stretching its neighbours.
                        ?>
                        <div class="q-actions">
                            <?php if ($st === 'WAITING'): ?>
                                <?php if (!$current): ?>
                                <form class="inline-form" method="POST" action="my_queue.php">
                                    <input type="hidden" name="action" value="start_consult">
                                    <input type="hidden" name="visit_id" value="<?= $vid ?>">
                                    <button class="btn-primary" type="submit"><?= mq_icon('play', 12) ?> Start</button>
                                </form>
                                <?php else: ?>
                                    <button class="btn-ghost" type="button" disabled title="Finish the current consultation first">Start</button>
                                <?php endif; ?>
                            <?php elseif ($st === 'IN_CONSULT'): ?>
                                <form class="inline-form" method="POST" action="my_queue.php">
                                    <input type="hidden" name="action" value="finish_consult">
                                    <input type="hidden" name="visit_id" value="<?= $vid ?>">
                                    <button class="btn-primary" type="submit"><?= mq_icon('check', 12) ?> Finish</button>
                                </form>
                            <?php endif; ?>

                            <?php // Notes can be written from the moment the patient is in the room
                                  // and revised afterwards, so this is not gated on IN_CONSULT —
                                  // only a WAITING row has nothing to write yet. ?>
                            <?php if ($st !== 'WAITING'): ?>
                            <button class="btn-ghost btn-note" type="button"
                                    onclick="mqNote(<?= $vid ?>, <?= htmlspecialchars(json_encode($v['patient_name']), ENT_QUOTES) ?>)"><?= mq_icon('pen', 12) ?> <?= $hasNote ? 'Edit note' : 'Note' ?></button>
                            <?php endif; ?>

                            <?php // History is on EVERY row whatever the state — prior notes are
                                  // most wanted BEFORE starting, not after. ?>
                            <button class="btn-ghost btn-hist" type="button"
                                    onclick="mqHistory(<?= (int) $v['patient_id'] ?>, <?= htmlspecialchars(json_encode($v['patient_name']), ENT_QUOTES) ?>)"><?= mq_icon('history', 12) ?> History</button>

                            <?php if ($canDoctorAdmit): ?>
                                <?php if (!empty($v['admission_id'])): ?>
                                    <a class="btn-ghost q-span-2" href="admission.php?id=<?= (int) $v['admission_id'] ?>">Manage stay</a>
                                <?php else: ?>
                                    <button class="btn-ghost" type="button"
                                        onclick="openAdmit(<?= $vid ?>, <?= htmlspecialchars(json_encode($v['patient_name']), ENT_QUOTES) ?>, <?= $doctorId ?>, '', false)">Admit</button>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php // The ONLY clay control on this page. Hidden once the bill is
                                  // fully refunded, so a settled row offers no dead action. ?>
                            <?php if ($canDoctorRefund && !empty($v['bill_id']) && $v['bill_status'] === 'paid'
                                      && refunded_total($pdo, (int) $v['bill_id']) < (float) $v['paid_amount']): ?>
                                <a class="btn-ghost btn-money" href="refund.php?bill_id=<?= (int) $v['bill_id'] ?>">Refund</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Note editor. One form reused by every row. -->
<div class="mq-modal" id="mqNoteModal" onclick="if(event.target===this)mqClose('mqNoteModal')">
    <form class="mq-box" method="POST" action="my_queue.php">
        <div class="mq-box-head">
            <div>
                <div class="mq-box-title">Consultation note</div>
                <div class="mq-box-sub" id="mqNotePatient"></div>
            </div>
            <button class="mq-x" type="button" onclick="mqClose('mqNoteModal')" aria-label="Close"><?= mq_icon('close', 18) ?></button>
        </div>
        <div class="mq-box-body">
            <input type="hidden" name="action" value="save_note">
            <input type="hidden" name="visit_id" id="mqNoteVisitId">
            <textarea class="mq-note-area" name="note" id="mqNoteText"
                      placeholder="History, examination, assessment, advice…"></textarea>
            <div class="mq-private">
                Private to you. This note is visible only to <b><?= htmlspecialchars($user['name']) ?></b> —
                not to reception, other doctors, or the admin. You can edit it at any time.
            </div>
        </div>
        <div class="mq-box-foot">
            <button class="btn-ghost" type="button" onclick="mqClose('mqNoteModal')">Cancel</button>
            <button class="btn-primary" type="submit">Save note</button>
        </div>
    </form>
</div>

<!-- Patient history. Content is fetched per patient. -->
<div class="mq-modal" id="mqHistModal" onclick="if(event.target===this)mqClose('mqHistModal')">
    <div class="mq-box">
        <div class="mq-box-head">
            <div>
                <div class="mq-box-title">Patient history</div>
                <div class="mq-box-sub" id="mqHistPatient"></div>
            </div>
            <button class="mq-x" type="button" onclick="mqClose('mqHistModal')" aria-label="Close"><?= mq_icon('close', 18) ?></button>
        </div>
        <div class="mq-box-body" id="mqHistBody">
            <div class="mq-spinner">Loading…</div>
        </div>
        <div class="mq-box-foot">
            <button class="btn-ghost" type="button" onclick="mqClose('mqHistModal')">Close</button>
        </div>
    </div>
</div>

<?php if ($canDoctorAdmit) { require __DIR__ . '/partials/admit_modal.php'; } ?>
<?php if ($admitError): ?>
<script>window.addEventListener('load', function () { alert(<?= json_encode($admitError) ?>); });</script>
<?php endif; ?>
<script>
// Today's note text, keyed by visit id, so the editor opens filled with no
// round trip. Only this doctor's own notes are ever emitted here (the PHP that
// built it filtered on doctor_id).
var MQ_NOTES = <?= json_encode($noteText, JSON_UNESCAPED_UNICODE) ?>;

function mqClose(id) { document.getElementById(id).classList.remove('open'); }

function mqNote(visitId, patientName) {
    document.getElementById('mqNoteVisitId').value = visitId;
    document.getElementById('mqNotePatient').textContent = patientName;
    document.getElementById('mqNoteText').value = MQ_NOTES[visitId] || '';
    document.getElementById('mqNoteModal').classList.add('open');
    document.getElementById('mqNoteText').focus();
}

function mqHistory(patientId, patientName) {
    var body = document.getElementById('mqHistBody');
    document.getElementById('mqHistPatient').textContent = patientName;
    body.innerHTML = '<div class="mq-spinner">Loading…</div>';
    document.getElementById('mqHistModal').classList.add('open');

    fetch('patient_history.php?patient_id=' + encodeURIComponent(patientId), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function (r) {
        if (!r.ok) { throw new Error('HTTP ' + r.status); }
        return r.text();
    })
    .then(function (html) { body.innerHTML = html; })
    .catch(function () {
        body.innerHTML = '<div class="mq-hist-empty">Could not load history. Please try again.</div>';
    });
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { mqClose('mqNoteModal'); mqClose('mqHistModal'); }
});
</script>
<script src="assets/js/date-picker.js?v=<?= @filemtime(__DIR__ . "/assets/js/date-picker.js") ?: 1 ?>"></script>
</body>
</html>
