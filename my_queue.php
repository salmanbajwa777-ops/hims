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
require_once __DIR__ . '/config/admission_actions.php';
require_once __DIR__ . '/config/tokens.php';
require_once __DIR__ . '/config/consultation_notes.php';
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
// Same shape as the console's queue: active consultation first, then waiting,
// then done; newest token on top within each group.
$queueStmt = $pdo->prepare("
    SELECT v.id AS visit_id, v.token_no, v.token_session, v.consult_status, v.started_at, v.created_at,
           v.patient_id,
           p.name AS patient_name, p.gender, p.dob, p.mrn,
           t.label AS type_label, v.consultation_fee_type,
           a.id AS admission_id, a.admission_type
    FROM visits v
    JOIN patients p ON p.id = v.patient_id
    LEFT JOIN doctor_consult_types t ON t.id = v.doctor_consult_type_id
    LEFT JOIN admissions a ON a.visit_id = v.id
    WHERE v.doctor_id = ? AND v.visit_date = CURDATE()
    ORDER BY FIELD(v.consult_status, 'IN_CONSULT', 'WAITING', 'DONE'), v.token_session DESC, v.token_no DESC
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
.header { display: flex; align-items: center; gap: 16px; padding: 0 24px; height: 64px;
          background: var(--card); border-bottom: 1px solid var(--border); }
.header-greet .greet-line { font-size: 12px; color: var(--text-muted); }
.header-greet .greet-name { font-size: 15px; font-weight: 700; }
.search-box { position: relative; flex: 1; max-width: 420px; margin: 0 auto; }
.search-box input { width: 100%; padding: 9px 12px 9px 36px; border: 1px solid var(--border);
                    border-radius: 999px; background: var(--bg); font-size: 13px; }
.search-box .icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: var(--text-muted); }
.header-right { display: flex; align-items: center; gap: 14px; margin-left: auto; }
.avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--primary); color: #fff;
          display: grid; place-items: center; font-weight: 700; font-size: 13px; }
.logout-link { font-size: 13px; color: var(--text-muted); }

.section-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.section-title { font-size: 15px; font-weight: 700; }
.section-sub { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.q-count-pills { display: flex; gap: 8px; }
.status-pill.waiting { background: #FFFBEB; color: #92400E; }
.status-pill.in-consult { background: var(--primary-light); color: var(--primary-dark); }
.status-pill.done { background: #ECFDF5; color: #047857; }

.q-item { display: grid; grid-template-columns: 56px 1fr auto; gap: 14px; align-items: center;
          padding: 13px 4px; border-top: 1px solid var(--border); }
.q-item:first-of-type { border-top: none; }
.q-item.serving { background: var(--primary-light); border-radius: 12px; padding-left: 10px; padding-right: 10px; }
.q-token { font-weight: 700; font-size: 13px; color: var(--text-secondary); background: var(--bg);
           border: 1px solid var(--border); border-radius: 8px; padding: 5px 0; text-align: center; }
.q-name { font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }
.q-meta { font-size: 12px; color: var(--text-muted); margin-top: 3px; }
.q-tag { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em;
         background: var(--bg); border: 1px solid var(--border); border-radius: 6px; padding: 2px 7px; color: var(--text-secondary); }
.q-tag-admit { background: #EEF2FF; color: #3730A3; border-color: #C7D2FE; }
.q-tag-free { background: #ECFDF5; color: #047857; border-color: #A7F3D0; }
.q-tag-revisit { background: #FFFBEB; color: #92400E; border-color: #FDE68A; }
.q-tag-note { background: var(--primary-light); color: var(--primary-dark); border-color: var(--primary); }
.q-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
.wait-time { font-size: 12px; color: var(--text-muted); min-width: 52px; text-align: right; }
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

@media (max-width: 720px) {
    .header { height: auto; padding: 10px 16px; flex-wrap: wrap; gap: 8px; }
    .search-box { order: 3; margin: 0; max-width: none; flex-basis: 100%; }
    .q-item { grid-template-columns: 44px 1fr; }
    .q-item .q-right { grid-column: 2; justify-content: flex-start; margin-top: 6px; }
    .q-item .wait-time { min-width: 0; text-align: left; }
}
html[data-view="mobile"] .header { height: auto; padding: 10px 16px; flex-wrap: wrap; gap: 8px; }
html[data-view="mobile"] .search-box { order: 3; margin: 0; max-width: none; flex-basis: 100%; }
html[data-view="mobile"] .q-item { grid-template-columns: 44px 1fr; }
html[data-view="mobile"] .q-item .q-right { grid-column: 2; justify-content: flex-start; margin-top: 6px; }
html[data-view="mobile"] .q-item .wait-time { min-width: 0; text-align: left; }
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
        <header class="header">
            <div class="header-greet">
                <div class="greet-line">My Queue — <?= date('l, d/m') ?></div>
                <div class="greet-name"><?= htmlspecialchars($user['name']) ?></div>
            </div>
            <form class="search-box" method="GET" action="patients.php">
                <span class="icon"><?= mq_icon('search') ?></span>
                <input type="text" name="q" placeholder="Search a patient by name, phone or MRN…">
            </form>
            <div class="header-right">
                <?php $nbClass = 'icon-btn'; require __DIR__ . '/partials/notification_bell.php'; ?>
                <a class="avatar" href="profile.php" title="My Profile" style="text-decoration:none;"><?= htmlspecialchars(strtoupper(substr($user['name'], 0, 1))) ?></a>
                <a class="logout-link" href="logout.php">Logout</a>
            </div>
        </header>

        <div class="content">

            <?php if ($flash !== ''): ?>
            <div class="flash"><?= htmlspecialchars($flash) ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="section-head">
                    <div>
                        <div class="section-title">My Queue</div>
                        <div class="section-sub">Patients registered for you today, in token order</div>
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
                <div class="q-item<?= $st === 'IN_CONSULT' ? ' serving' : '' ?>">
                    <div class="q-token tnum"><?= htmlspecialchars($myTokenPrefix) ?>-<?= (int) $v['token_no'] ?></div>
                    <div>
                        <div class="q-name"><?= htmlspecialchars($v['patient_name']) ?>
                            <?php if ($st === 'IN_CONSULT'): ?><span class="pulse"></span><span class="status-pill in-consult">Now serving</span><?php endif; ?>
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
                            <?php if ($st === 'IN_CONSULT' && $v['started_at']): ?> · started <?= date('g:i A', strtotime($v['started_at'])) ?><?php endif; ?>
                        </div>
                    </div>
                    <div class="q-right">
                        <?php if ($st === 'WAITING'): ?>
                            <span class="wait-time tnum"><?= mq_wait_minutes($v['created_at']) ?> min</span>
                            <span class="status-pill waiting">Waiting</span>
                        <?php elseif ($st === 'DONE'): ?>
                            <span class="status-pill done">Done</span>
                        <?php endif; ?>

                        <?php // History is available on EVERY row, whatever the consult state —
                              // the doctor most often wants prior notes BEFORE starting. ?>
                        <button class="btn-ghost" type="button"
                                onclick="mqHistory(<?= (int) $v['patient_id'] ?>, <?= htmlspecialchars(json_encode($v['patient_name']), ENT_QUOTES) ?>)"><?= mq_icon('history', 12) ?> History</button>

                        <?php // Notes can be written from the moment the patient is in the room and
                              // revised afterwards (always editable by the author), so this is not
                              // gated on IN_CONSULT — only a WAITING row has nothing to write yet. ?>
                        <?php if ($st !== 'WAITING'): ?>
                        <button class="btn-ghost" type="button"
                                onclick="mqNote(<?= $vid ?>, <?= htmlspecialchars(json_encode($v['patient_name']), ENT_QUOTES) ?>)"><?= mq_icon('pen', 12) ?> <?= $hasNote ? 'Edit note' : 'Add note' ?></button>
                        <?php endif; ?>

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

                        <?php if ($canDoctorAdmit): ?>
                            <?php if (!empty($v['admission_id'])): ?>
                                <a class="btn-ghost" href="admission.php?id=<?= (int) $v['admission_id'] ?>">Manage stay</a>
                            <?php else: ?>
                                <button class="btn-ghost" type="button"
                                    onclick="openAdmit(<?= $vid ?>, <?= htmlspecialchars(json_encode($v['patient_name']), ENT_QUOTES) ?>, <?= $doctorId ?>, '', false)">Admit</button>
                            <?php endif; ?>
                        <?php endif; ?>
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
