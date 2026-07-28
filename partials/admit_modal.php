<?php
/**
 * Shared "Admit patient" modal + openAdmit()/closeAdmit() JS. Included by any page
 * with an Admit action (receptionist.php, patients.php, doctor.php).
 *
 * Expects in scope:
 *   $admitFormAction  — where the form POSTs (defaults to the current script).
 *   $admTypes         — [['admission_type','rate_amount','rate_basis'], ...] (enabled).
 *   $admDoctors       — [['id','name'], ...] system doctors for the picker.
 *   $admTypeLabels    — ['ROUTINE'=>'Routine', ...] display labels.
 *   $admNurses        — [['id','name'], ...] eligible PRIMARY nurses (short-stay).
 *
 * Assigning a primary nurse is MANDATORY on both routes — the handlers reject an
 * admit without one, and the picker below is `required`. It records who is
 * accountable for the stay; it does not restrict who may work on it (any staff
 * member with the relevant nursing permission still logs vitals and services).
 * The two routes draw from different rosters ($admNurses vs $ipdNurses) because
 * short-stay and In-Door nursing are separate permissions.
 *
 * In-Door (IPD) is offered as one more admission type in the SAME picker, so
 * reception makes a single "where is this patient going" choice instead of
 * hunting for a second button. It stays a separate module underneath: choosing
 * it swaps the body to the ward fields and re-points the form at the IPD
 * handler (action=ipd_admit_patient, config/ipd_actions.php). Enable by setting
 * $admitShowIpd = true and supplying:
 *   $ipdWards   — [['ward','per_day_rate','consultant_visit_fee'], ...] enabled.
 *   $ipdDoctors — [['id','name'], ...] consultants.
 *   $ipdNurses  — [['id','name'], ...] eligible primary nurses (In-Door).
 * Left false (doctor.php) the modal is exactly the pre-merge admit-only dialog.
 *
 * openAdmit(idPayload) accepts either a visit id (queue context) OR a patient id
 * (all-patients context) — pass which via the second arg. The hidden field is chosen
 * accordingly so the shared handler resolves/creates the visit as needed.
 */
$admitFormAction = $admitFormAction ?? basename($_SERVER['SCRIPT_NAME'] ?? 'receptionist.php');
$admTypes = $admTypes ?? [];
$admDoctors = $admDoctors ?? [];
$admTypeLabels = $admTypeLabels ?? ['ROUTINE' => 'Routine', 'PRIVATE' => 'Private Room', 'LONG_PRIVATE' => 'Long Private'];
$admitShowIpd = !empty($admitShowIpd);
$ipdWards = $ipdWards ?? [];
$ipdDoctors = $ipdDoctors ?? [];
$admNurses = $admNurses ?? [];
$ipdNurses = $ipdNurses ?? [];
// A primary nurse is mandatory, so a route with an EMPTY nurse roster cannot be
// completed at all — treat it as unavailable here rather than letting the user
// fill the form and take a server-side rejection. Each route is judged on its
// own roster because the two use different nursing permissions.
$admitOpdOk = $admTypes && $admNurses;
$admitIpdOk = $admitShowIpd && $ipdWards && $ipdNurses;
// The submit button must not be dead when In-Door is the only route available
// (e.g. no hourly admission rates enabled but wards are).
$admitHasAnyRoute = $admitOpdOk || $admitIpdOk;
?>
<style>
.admit-overlay { display: none; position: fixed; inset: 0; background: rgba(15,23,42,.45); z-index: 60; align-items: center; justify-content: center; padding: 20px; }
.admit-overlay.open { display: flex; }
.admit-modal { background: var(--card,#fff); border-radius: 16px; width: 100%; max-width: 460px; box-shadow: 0 20px 50px rgba(0,0,0,.25); overflow: hidden; }
.admit-head { display: flex; align-items: flex-start; justify-content: space-between; padding: 20px 22px 6px; }
.admit-eyebrow { font-size: 11px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--text-muted,#64748b); }
.admit-name { font-size: 18px; font-weight: 700; margin-top: 2px; }
.admit-x { background: none; border: none; font-size: 24px; line-height: 1; color: var(--text-muted,#64748b); cursor: pointer; }
.admit-body { padding: 10px 22px 4px; display: flex; flex-direction: column; gap: 16px; }
.admit-field > label { display: block; font-size: 12.5px; font-weight: 600; color: var(--text-secondary,#475569); margin-bottom: 8px; }
.type-opts { display: flex; flex-direction: column; gap: 8px; }
.type-opt { display: flex; align-items: center; gap: 12px; border: 1px solid var(--border,#e2e8f0); border-radius: 12px; padding: 11px 14px; cursor: pointer; }
.type-opt:has(input:checked) { border-color: var(--primary,#1a7f7e); background: var(--primary-light,#e6f4f4); }
.type-opt .type-body { display: flex; flex-direction: column; }
.type-opt .type-name { font-size: 13.5px; font-weight: 600; }
.type-opt .type-rate { font-size: 12px; color: var(--text-muted,#64748b); }
.admit-field select, .admit-field input[type="text"], .admit-field input[type="number"], .admit-field textarea { width: 100%; padding: 10px 12px; border: 1px solid var(--border,#e2e8f0); border-radius: 12px; font: inherit; font-size: 13.5px; background: var(--bg,#f8fafc); }
.admit-field textarea { resize: vertical; min-height: 60px; }
.admit-foot { display: flex; justify-content: flex-end; gap: 10px; padding: 16px 22px 20px; }
/* In-Door merge: the body can now grow past the viewport (ward + room +
   consultant + diagnosis), so it scrolls instead of pushing the footer off. */
.admit-body { max-height: 70vh; overflow-y: auto; }
.admit-row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
/* Route-specific blocks — JS toggles [hidden] as the admission type changes. */
#admitOpdFields[hidden], #admitIpdFields[hidden] { display: none; }
</style>
<div class="admit-overlay" id="admitOverlay" onclick="if(event.target===this)closeAdmit()">
    <div class="admit-modal" role="dialog" aria-modal="true" aria-labelledby="admitTitle">
        <form method="POST" action="<?= htmlspecialchars($admitFormAction) ?>" id="admitForm">
            <input type="hidden" name="action" id="admitAction" value="admit_patient">
            <input type="hidden" name="visit_id" id="admitVisitId" value="">
            <input type="hidden" name="patient_id" id="admitPatientId" value="">
            <div class="admit-head">
                <div>
                    <div class="admit-eyebrow">Admit patient</div>
                    <div class="admit-name" id="admitTitle">—</div>
                </div>
                <button type="button" class="admit-x" onclick="closeAdmit()" aria-label="Close">&times;</button>
            </div>

            <div class="admit-body">
                <div class="admit-field">
                    <label>Admission type</label>
                    <div class="type-opts">
                        <?php foreach ($admTypes as $i => $t): ?>
                        <!-- Disabled with an empty short-stay nurse roster: a primary
                             nurse is mandatory, so this route cannot be completed. -->
                        <label class="type-opt">
                            <input type="radio" name="admission_type" value="<?= htmlspecialchars($t['admission_type']) ?>" <?= ($i === 0 && $admitOpdOk) ? 'checked' : '' ?> <?= $admitOpdOk ? '' : 'disabled' ?>>
                            <span class="type-body">
                                <span class="type-name"><?= htmlspecialchars($admTypeLabels[$t['admission_type']] ?? $t['admission_type']) ?></span>
                                <span class="type-rate"><?= $admitOpdOk ? 'Rs ' . number_format((float) $t['rate_amount']) . '/' . ($t['rate_basis'] === 'DAILY' ? 'day' : 'hr') : 'No nursing staff available' ?></span>
                            </span>
                        </label>
                        <?php endforeach; ?>
                        <?php if ($admitShowIpd): ?>
                        <!-- Third route: In-Door. Same picker, different module — the
                             JS below re-points the form at the IPD handler. -->
                        <label class="type-opt">
                            <input type="radio" name="admission_type" value="__IPD__" id="admitTypeIpd" <?= $admitOpdOk ? '' : 'checked' ?> <?= $admitIpdOk ? '' : 'disabled' ?>>
                            <span class="type-body">
                                <span class="type-name">In-Door (IPD)</span>
                                <span class="type-rate"><?php
                                    if (!$ipdWards)      { echo 'No wards enabled'; }
                                    elseif (!$ipdNurses) { echo 'No nursing staff available'; }
                                    else                 { echo 'Ward admission &mdash; per-day room rate'; }
                                ?></span>
                            </span>
                        </label>
                        <?php endif; ?>
                        <?php if (!$admitHasAnyRoute): ?>
                        <div class="muted">
                            <?php if ($admTypes || ($admitShowIpd && $ipdWards)): ?>
                            No staff hold the nursing permission required to take a patient, so admitting is blocked. Grant it under Permissions.
                            <?php else: ?>
                            No admission types are enabled. Set them under ER Services &amp; Rates.
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ---- Hourly/room admission fields ---- -->
                <div id="admitOpdFields">
                    <!-- Admitting doctor — mandatory. Defaults to the doctor who last
                         saw this patient (set by openAdmit); "Other" reveals the
                         free-text box for a visiting/locum doctor. -->
                    <div class="admit-field">
                        <label>Admitting doctor <span style="color:var(--red,#dc2626);">*</span></label>
                        <select name="admitting_doctor_id" id="admitDoctor" data-opd-required>
                            <option value="">Select doctor&hellip;</option>
                            <?php foreach ($admDoctors as $d): ?>
                            <option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                            <?php endforeach; ?>
                            <option value="__OTHER__">Other (type a name)&hellip;</option>
                        </select>
                        <input type="text" name="admitting_doctor_manual" id="admitDoctorManual" class="uc" placeholder="Doctor's name" style="margin-top:8px;" hidden>
                    </div>

                    <!-- Primary nurse — mandatory. `required` is toggled by JS so a
                         hidden picker can never block the other route's submit. -->
                    <div class="admit-field" style="margin-top:16px;">
                        <label>Primary nurse <span style="color:var(--red,#dc2626);">*</span></label>
                        <select name="assigned_nurse_id" id="admitNurse" data-opd-required>
                            <option value="">Select primary nurse&hellip;</option>
                            <?php foreach ($admNurses as $n): ?>
                            <option value="<?= (int) $n['id'] ?>"><?= htmlspecialchars($n['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$admNurses): ?>
                        <div class="muted" style="margin-top:6px;font-size:12px;">
                            No staff hold the short-stay nursing permission — grant it under Permissions before admitting.
                        </div>
                        <?php else: ?>
                        <div class="muted" style="margin-top:6px;font-size:12px;">
                            Accountable for this stay. Any nursing staff can still record vitals and services.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($admitShowIpd): ?>
                <!-- ---- In-Door fields (mirror partials/ipd_admit_modal.php) ----
                     `required` is applied by JS only while this route is active, so a
                     hidden ward select can never block an hourly-admission submit. -->
                <div id="admitIpdFields" hidden>
                    <div class="admit-row2">
                        <div class="admit-field">
                            <label>Ward</label>
                            <select name="ward" id="admitIpdWard" data-ipd-required>
                                <option value="">Select ward&hellip;</option>
                                <?php foreach ($ipdWards as $w): ?>
                                <option value="<?= htmlspecialchars($w['ward']) ?>">
                                    <?= htmlspecialchars($w['ward']) ?><?= (float) $w['per_day_rate'] > 0 ? ' (Rs ' . number_format((float) $w['per_day_rate']) . '/day)' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!$ipdWards): ?>
                            <div class="muted" style="margin-top:6px;font-size:12px;">No wards enabled. Set them under In-Door Ward Rates.</div>
                            <?php endif; ?>
                        </div>
                        <div class="admit-field">
                            <label>Room (1&ndash;4)</label>
                            <input type="number" name="room_no" min="1" max="4" placeholder="1&ndash;4" data-ipd-required>
                        </div>
                    </div>

                    <div class="admit-field" style="margin-top:16px;">
                        <label>Admitting consultant <span style="color:var(--red,#dc2626);">*</span></label>
                        <select name="admitting_consultant_id" id="admitIpdDoctor" data-ipd-required>
                            <option value="">Select consultant&hellip;</option>
                            <?php foreach ($ipdDoctors as $d): ?>
                            <option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                            <?php endforeach; ?>
                            <option value="__OTHER__">Other (type a name)&hellip;</option>
                        </select>
                        <input type="text" name="admitting_consultant_manual" id="admitIpdDoctorManual" class="uc" placeholder="Consultant's name" style="margin-top:8px;" hidden>
                    </div>

                    <div class="admit-field" style="margin-top:16px;">
                        <label>Primary nurse <span style="color:var(--red,#dc2626);">*</span></label>
                        <select name="assigned_nurse_id" id="admitIpdNurse" data-ipd-required>
                            <option value="">Select primary nurse&hellip;</option>
                            <?php foreach ($ipdNurses as $n): ?>
                            <option value="<?= (int) $n['id'] ?>"><?= htmlspecialchars($n['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$ipdNurses): ?>
                        <div class="muted" style="margin-top:6px;font-size:12px;">
                            No staff hold the In-Door nursing permission — grant it under Permissions before admitting.
                        </div>
                        <?php else: ?>
                        <div class="muted" style="margin-top:6px;font-size:12px;">
                            Accountable for this stay. Any nursing staff can still log care and vitals.
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="admit-field" style="margin-top:16px;">
                        <label>Provisional diagnosis <span class="muted" style="font-weight:500;">(working diagnosis &mdash; seeds the first ward round)</span></label>
                        <textarea name="provisional_diagnosis" maxlength="500" placeholder="e.g. Community Acquired Pneumonia"></textarea>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="admit-foot">
                <button type="button" class="btn secondary" onclick="closeAdmit()">Cancel</button>
                <button type="submit" class="btn" id="admitSubmit" <?= $admitHasAnyRoute ? '' : 'disabled' ?>>Admit &amp; start stay</button>
            </div>
        </form>
    </div>
</div>
<script>
// openAdmit(id, patientName, doctorId, doctorName, byPatient)
//   byPatient=false (default): id is a VISIT id (queue context).
//   byPatient=true:            id is a PATIENT id (all-patients context) — the handler
//                              reuses today's visit or creates a shell.
//   doctorId is the patient's LAST-SEEN doctor (or this visit's doctor) — the
//   picker defaults to it, which is nearly always the doctor at the counter.
function openAdmit(id, patientName, doctorId, doctorName, byPatient) {
    var vEl = document.getElementById('admitVisitId');
    var pEl = document.getElementById('admitPatientId');
    if (byPatient) { pEl.value = id; vEl.value = ''; }
    else { vEl.value = id; pEl.value = ''; }
    document.getElementById('admitTitle').textContent = patientName || 'Patient';
    admitPreselectDoctor(document.getElementById('admitDoctor'), document.getElementById('admitDoctorManual'), doctorId, doctorName);
    <?php if ($admitShowIpd): ?>
    // Preselect the consultant too — the same doctor is the likely admitter on
    // either route, and the picker starts on whichever route is checked.
    admitPreselectDoctor(document.getElementById('admitIpdDoctor'), document.getElementById('admitIpdDoctorManual'), doctorId, doctorName);
    admitSyncRoute();
    <?php endif; ?>
    document.getElementById('admitOverlay').classList.add('open');
}

// Default a doctor picker to the last-seen doctor. When that doctor isn't a
// selectable system user but we know their name, fall back to "Other" with the
// name prefilled so the mandatory field still starts satisfied.
function admitPreselectDoctor(sel, manual, doctorId, doctorName) {
    if (!sel || !manual) { return; }
    if (doctorId && sel.querySelector('option[value="' + doctorId + '"]')) {
        sel.value = String(doctorId);
        manual.value = '';
    } else if (doctorName) {
        sel.value = '__OTHER__';
        manual.value = doctorName;
    } else {
        sel.value = '';
        manual.value = '';
    }
    admitSyncOther(sel, manual);
}

// Show/require the free-text box only while "Other" is the selection.
function admitSyncOther(sel, manual) {
    if (!sel || !manual) { return; }
    var isOther = sel.value === '__OTHER__';
    manual.hidden = !isOther;
    manual.required = isOther;
    if (!isOther) { manual.value = ''; }
    // Re-apply route state: on a two-route modal this must not leave the hidden
    // route's box required (see admitSyncRoute).
    if (typeof admitSyncRoute === 'function') { admitSyncRoute(); }
}
document.addEventListener('change', function (e) {
    if (e.target && e.target.id === 'admitDoctor') {
        admitSyncOther(e.target, document.getElementById('admitDoctorManual'));
    }
    if (e.target && e.target.id === 'admitIpdDoctor') {
        admitSyncOther(e.target, document.getElementById('admitIpdDoctorManual'));
    }
});
// "__OTHER__" is a UI sentinel, never a doctor id — drop it before it posts.
// The typed name in the companion field is what identifies the doctor.
document.getElementById('admitForm').addEventListener('submit', function () {
    ['admitDoctor', 'admitIpdDoctor'].forEach(function (idAttr) {
        var s = document.getElementById(idAttr);
        if (s && s.value === '__OTHER__') { s.disabled = true; }
    });
});
function closeAdmit() { document.getElementById('admitOverlay').classList.remove('open'); }
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeAdmit(); } });
<?php if ($admitShowIpd): ?>
// ---- Route switching (hourly admission <-> In-Door) ----------------------
// In-Door is a separate module with its own handler and field set, so picking
// it rewrites three things: which fields show, which POST action fires, and
// which inputs are `required`. The __IPD__ radio is disabled on submit so the
// IPD handler never receives a bogus admission_type.
function admitSyncRoute() {
    var ipdRadio = document.getElementById('admitTypeIpd');
    var isIpd = !!(ipdRadio && ipdRadio.checked);
    var opd = document.getElementById('admitOpdFields');
    var ipd = document.getElementById('admitIpdFields');
    if (opd) { opd.hidden = isIpd; }
    if (ipd) { ipd.hidden = !isIpd; }

    document.getElementById('admitAction').value = isIpd ? 'ipd_admit_patient' : 'admit_patient';
    document.getElementById('admitSubmit').textContent = isIpd ? 'Admit to In-Door' : 'Admit & start stay';

    // Only the visible route's fields may block submission. Both routes now carry
    // required inputs (the mandatory primary nurse), so each set is toggled.
    var ipdReqs = document.querySelectorAll('#admitIpdFields [data-ipd-required]');
    for (var i = 0; i < ipdReqs.length; i++) { ipdReqs[i].required = isIpd; }
    var opdReqs = document.querySelectorAll('#admitOpdFields [data-opd-required]');
    for (var j = 0; j < opdReqs.length; j++) { opdReqs[j].required = !isIpd; }

    // The "Other" free-text boxes aren't in those sets, and a HIDDEN required
    // field blocks submit with nothing focusable to fix — so the inactive
    // route's box is always un-required, and the active one follows its select.
    var opdOther = document.getElementById('admitDoctorManual');
    var ipdOther = document.getElementById('admitIpdDoctorManual');
    if (opdOther) { opdOther.required = !isIpd && document.getElementById('admitDoctor').value === '__OTHER__'; }
    if (ipdOther) { ipdOther.required = isIpd && document.getElementById('admitIpdDoctor').value === '__OTHER__'; }

    // Both routes carry a select called `assigned_nurse_id`. `hidden` does NOT
    // stop a field from being submitted, so without this the inactive route's
    // EMPTY nurse select posts a second assigned_nurse_id and PHP keeps the LAST
    // one — silently discarding the nurse the user picked and failing the
    // mandatory-nurse guard with "Assign a primary nurse to admit this patient."
    // Disabling is what actually removes a control from the submission.
    admitSetRouteDisabled(opd, isIpd);   // OPD fields off when IPD is active
    admitSetRouteDisabled(ipd, !isIpd);  // and vice versa
}

// Disables/enables every control inside an inactive route container. Skips the
// route radios themselves — they live outside these containers, and disabling
// the checked one would drop admission_type from the POST entirely.
function admitSetRouteDisabled(box, off) {
    if (!box) { return; }
    var f = box.querySelectorAll('input, select, textarea');
    for (var i = 0; i < f.length; i++) {
        if (f[i].name === 'admission_type') { continue; }
        f[i].disabled = off;
    }
}
document.addEventListener('change', function (e) {
    if (e.target && e.target.name === 'admission_type') { admitSyncRoute(); }
});
// Strip the sentinel value before it reaches the IPD handler, and re-assert the
// route disabling. admitSyncRoute() already did this on `change`, but repeating
// it at submit time means a stray re-enable (autofill, an extension, a future
// handler) can never let the inactive route's duplicate `assigned_nurse_id`
// reach the server and blank out the chosen nurse.
document.getElementById('admitForm').addEventListener('submit', function () {
    var ipdRadio = document.getElementById('admitTypeIpd');
    var isIpd = !!(ipdRadio && ipdRadio.checked);
    admitSetRouteDisabled(document.getElementById('admitOpdFields'), isIpd);
    admitSetRouteDisabled(document.getElementById('admitIpdFields'), !isIpd);
    if (isIpd) { ipdRadio.disabled = true; }
});
admitSyncRoute();
<?php endif; ?>
</script>
