<?php
/**
 * Settings → Notifications. The clinic-wide EMAIL switchboard.
 *
 * One row per event. In-app notifications are NOT on this screen and are not
 * configurable: every event always writes to the bell. The only thing an admin
 * sets here is whether the matching email is allowed to go out — which is why
 * each row shows "Always on" for in-app and a checkbox for email.
 *
 * GLOBAL and OVERRIDING: this beats any per-user opt-in (see the note on the
 * invoice row). Saving is idempotent and repeatable — an admin can come back and
 * change these as often as they like.
 *
 * staff_welcome is rendered checked + disabled and is force-set to 1 on every
 * save: that email carries the temporary password for a brand-new account, so
 * switching it off would create accounts nobody can sign into.
 */
require_once __DIR__ . '/config/guard_admin.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/notifications.php';

// The catalogue. Order here is the render order — grouped the way an admin
// thinks about the clinic, not alphabetically or by the order they were built.
// 'locked' means the checkbox cannot be turned off (see file header).
// 'warn' surfaces a consequence that isn't obvious from the label alone.
$eventGroups = [
    'Accounts' => [
        ['type' => 'staff_welcome', 'label' => 'New staff / doctor account created',
         'who' => 'The new user', 'locked' => true,
         'note' => 'Carries the sign-in link and temporary password — this is the only way a new account receives it.'],
    ],
    'Patients & Clinical' => [
        ['type' => 'invoice_raised', 'label' => 'Patient registered / follow-up invoice raised',
         'who' => 'The visit’s doctor',
         'note' => 'The highest-volume event in the app — it fires on every registration and every follow-up. Doctors also have a personal opt-in on their profile; this switch overrides it, so leaving it off means no doctor is emailed regardless of their own setting.'],
        ['type' => 'patient_admitted', 'label' => 'Patient admitted (ER / OPD)',
         'who' => 'Admin address + the admitting doctor'],
        ['type' => 'ipd_patient_admitted', 'label' => 'In-Door (IPD) admission',
         'who' => 'Admin address + the admitting consultant'],
        ['type' => 'patient_discharged', 'label' => 'Patient discharged (including write-offs)',
         'who' => 'Admin address'],
    ],
    'Bookings' => [
        ['type' => 'booking_created', 'label' => 'Phone booking created', 'who' => 'The booked doctor'],
        ['type' => 'booking_cancelled', 'label' => 'Booking cancelled', 'who' => 'The booked doctor'],
    ],
    'Money' => [
        ['type' => 'refund_issued', 'label' => 'Refund issued',
         'who' => 'Admin address + the approving doctor'],
        ['type' => 'expense_approval', 'label' => 'Counter expense posted (awaiting approval)',
         'who' => 'Admin address + everyone who can approve expenses',
         'warn' => 'This email carries the 60-minute one-click approve/reject link. Switching it off removes that path entirely — approvals would then only be possible from the Expenses page after signing in.'],
        ['type' => 'expense_decided', 'label' => 'Expense approved or rejected',
         'who' => 'Admin address + whoever posted it'],
        ['type' => 'procedure_discount', 'label' => 'Reception gave a discount on a procedure bill',
         'who' => 'The performing doctor',
         'note' => 'The doctor has 24 hours to record their view; silence auto-approves. Their bell notification fires either way.'],
    ],
    'Day Closing' => [
        ['type' => 'day_closed', 'label' => 'Shift / day closed',
         'who' => 'Admin address + the admin receiving the handover'],
        ['type' => 'closing_edited', 'label' => 'Closed shift edited by the cashier',
         'who' => 'Admin address + the admin receiving the handover'],
    ],
    'Scheduled' => [
        ['type' => 'daily_summary', 'label' => 'Daily summary (sent each evening at 21:00)',
         'who' => 'Admin address', 'email_only' => true,
         'note' => 'A scheduled digest, not a reaction to an action — there is no bell notification for this one.'],
    ],
];

// Flatten once: used by the save handler to bound what can be written, so a
// posted key that isn't in the catalogue is ignored rather than inserted.
$knownTypes = [];
$lockedTypes = [];
foreach ($eventGroups as $rows) {
    foreach ($rows as $row) {
        $knownTypes[] = $row['type'];
        if (!empty($row['locked'])) { $lockedTypes[] = $row['type']; }
    }
}

$error = '';
$success = '';
$migrationMissing = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_notification_settings') {
    // Unchecked boxes post nothing, so the posted list is the whole truth about
    // what should be ON — everything else in the catalogue goes OFF.
    $checked = array_values(array_intersect((array) ($_POST['email_types'] ?? []), $knownTypes));
    // Locked events are forced on regardless of what was posted. A disabled
    // checkbox submits nothing, so without this every save would switch the
    // welcome email off — and a hand-crafted POST must not be able to either.
    $checked = array_values(array_unique(array_merge($checked, $lockedTypes)));

    try {
        $pdo->beginTransaction();
        // UPSERT per event: the row is seeded by the migration, but INSERT ...
        // ON DUPLICATE KEY keeps this correct if a new event type reaches the
        // catalogue before its seed row does.
        $stmt = $pdo->prepare('
            INSERT INTO notification_settings (event_type, email_enabled, updated_at, updated_by_id)
            VALUES (?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE email_enabled = VALUES(email_enabled),
                                    updated_at = NOW(),
                                    updated_by_id = VALUES(updated_by_id)
        ');
        foreach ($knownTypes as $t) {
            $stmt->execute([$t, in_array($t, $checked, true) ? 1 : 0, (int) $_SESSION['user_id']]);
        }
        $pdo->commit();

        audit_log($pdo, 'notification_settings_updated',
            'Email notifications enabled for: ' . (implode(', ', $checked) ?: 'none'),
            $_SESSION['user_id']);

        $success = 'Notification settings saved. ' . count($checked) . ' of ' . count($knownTypes) . ' emails are on.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = 'Could not save — sql/add_notification_settings.sql may not have been run yet.';
    }
}

// Current state. A missing table is reported rather than rendered as "all off":
// notif_email_enabled() treats an absent row as ON, so showing empty checkboxes
// would tell the admin the exact opposite of what the app is doing.
$enabled = [];
try {
    foreach ($pdo->query('SELECT event_type, email_enabled FROM notification_settings')->fetchAll() as $r) {
        $enabled[$r['event_type']] = (bool) $r['email_enabled'];
    }
} catch (Throwable $e) {
    $migrationMissing = true;
}

$onCount = count(array_filter($enabled));

$pageTitle = 'Notification Settings';
$headExtra = <<<CSS
<style>
.ns-group { margin-bottom: 22px; }
.ns-group:last-of-type { margin-bottom: 6px; }
.ns-group-head { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px;
                 color: var(--text-muted); padding-bottom: 8px; margin-bottom: 4px; border-bottom: 1px solid var(--border); }

.ns-row { display: flex; align-items: flex-start; gap: 14px; padding: 13px 4px; border-bottom: 1px solid var(--border); }
.ns-row:last-child { border-bottom: none; }
.ns-main { flex: 1; min-width: 0; }
.ns-label { font-size: 14px; color: var(--text); font-weight: 600; }
.ns-who { font-size: 12px; color: var(--text-muted); margin-top: 3px; }
.ns-note { font-size: 12px; color: var(--text-muted); margin-top: 6px; line-height: 1.5; }
.ns-warn { font-size: 12px; color: #9A3412; background: #FFF4ED; border: 1px solid #FBCFB0;
           border-radius: 7px; padding: 8px 11px; margin-top: 8px; line-height: 1.5; }

/* In-app column: a fixed statement, never a control. */
.ns-inapp { flex-shrink: 0; width: 96px; text-align: center; padding-top: 2px; }
.ns-always { display: inline-block; font-size: 11px; font-weight: 700; letter-spacing: .3px;
             color: var(--primary); background: rgba(14,84,86,.08); border-radius: 20px; padding: 4px 10px; }
.ns-dash { font-size: 12px; color: var(--text-muted); }

.ns-email { flex-shrink: 0; width: 76px; display: flex; justify-content: center; padding-top: 2px; }
.ns-email input[type="checkbox"] { width: 19px; height: 19px; accent-color: var(--primary); cursor: pointer; }
.ns-email input[type="checkbox"]:disabled { cursor: not-allowed; opacity: .55; }
.ns-locked { font-size: 10px; color: var(--text-muted); text-align: center; margin-top: 4px; letter-spacing: .3px; }

.ns-colhead { display: flex; align-items: flex-end; gap: 14px; padding: 0 4px 8px; }
.ns-colhead .ns-main { font-size: 12px; color: var(--text-muted); }
.ns-colhead .ns-inapp, .ns-colhead .ns-email {
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted); }
.ns-colhead .ns-email { display: block; text-align: center; }

.form-footer { display: flex; align-items: center; justify-content: space-between; gap: 10px;
               padding-top: 16px; margin-top: 8px; border-top: 1px solid var(--border); }
.ns-count { font-size: 12.5px; color: var(--text-muted); }

@media (max-width: 640px) {
    .ns-inapp { width: 70px; }
    .ns-email { width: 58px; }
    .ns-always { font-size: 10px; padding: 3px 7px; }
}
</style>
CSS;
require __DIR__ . '/partials/head.php';
$navActive = 'notification_settings';
require __DIR__ . '/partials/sidebar.php';
?>

        <div class="content">
            <div class="page-head">
                <div>
                    <div class="page-title">Notification Settings</div>
                    <div class="page-sub">Every action below always sends an in-app notification to the bell. Use the checkboxes to choose which ones <em>also</em> send an email.</div>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($migrationMissing): ?>
                <div class="alert error">
                    <strong>Not set up yet.</strong> Run <code>sql/add_notification_settings.sql</code> in phpMyAdmin.
                    Until then every email below sends as it always has, and nothing saved on this page will stick.
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="section-title">Email Notifications</div>
                <div class="section-sub">
                    This is a clinic-wide switchboard and it overrides personal preferences — if an event is off here,
                    nobody is emailed about it even if they opted in on their own profile. In-app notifications are
                    always delivered and cannot be switched off.
                </div>

                <form method="POST" action="notification_settings.php">
                    <input type="hidden" name="action" value="save_notification_settings">

                    <div class="ns-colhead">
                        <div class="ns-main">Action</div>
                        <div class="ns-inapp">In-app</div>
                        <div class="ns-email">Email</div>
                    </div>

                    <?php foreach ($eventGroups as $groupName => $rows): ?>
                    <div class="ns-group">
                        <div class="ns-group-head"><?= htmlspecialchars($groupName) ?></div>
                        <?php foreach ($rows as $row): ?>
                        <?php
                            $t = $row['type'];
                            $isLocked = !empty($row['locked']);
                            // A missing row means notif_email_enabled() returns true,
                            // so the box must render ticked to match reality.
                            $isOn = $isLocked || (array_key_exists($t, $enabled) ? $enabled[$t] : true);
                        ?>
                        <div class="ns-row">
                            <div class="ns-main">
                                <label class="ns-label" for="ns_<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($row['label']) ?></label>
                                <div class="ns-who">Goes to: <?= htmlspecialchars($row['who']) ?></div>
                                <?php if (!empty($row['note'])): ?>
                                    <div class="ns-note"><?= htmlspecialchars($row['note']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($row['warn'])): ?>
                                    <div class="ns-warn"><?= htmlspecialchars($row['warn']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="ns-inapp">
                                <?php if (!empty($row['email_only'])): ?>
                                    <span class="ns-dash">—</span>
                                <?php else: ?>
                                    <span class="ns-always">Always on</span>
                                <?php endif; ?>
                            </div>
                            <div class="ns-email" style="<?= $isLocked ? 'display:block;' : '' ?>">
                                <input type="checkbox" id="ns_<?= htmlspecialchars($t) ?>"
                                       name="email_types[]" value="<?= htmlspecialchars($t) ?>"
                                       <?= $isOn ? 'checked' : '' ?> <?= $isLocked ? 'disabled' : '' ?>>
                                <?php if ($isLocked): ?><div class="ns-locked">Required</div><?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>

                    <div class="form-footer">
                        <span class="ns-count"><?= $onCount ?> of <?= count($knownTypes) ?> emails currently on</span>
                        <button type="submit" class="btn">Save Notification Settings</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script src="assets/js/date-picker.js?v=<?= @filemtime(__DIR__ . "/assets/js/date-picker.js") ?: 1 ?>"></script>
</body>
</html>
