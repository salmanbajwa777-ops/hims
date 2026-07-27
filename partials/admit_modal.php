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
 *
 * In-Door (IPD) is offered as one more admission type in the SAME picker, so
 * reception makes a single "where is this patient going" choice instead of
 * hunting for a second button. It stays a separate module underneath: choosing
 * it swaps the body to the ward fields and re-points the form at the IPD
 * handler (action=ipd_admit_patient, config/ipd_actions.php). Enable by setting
 * $admitShowIpd = true and supplying:
 *   $ipdWards   — [['ward','per_day_rate','consultant_visit_fee'], ...] enabled.
 *   $ipdDoctors — [['id','name'], ...] consultants.
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
// The submit button must not be dead when In-Door is the only route available
// (e.g. no hourly admission rates enabled but wards are).
$admitHasAnyRoute = $admTypes || ($admitShowIpd && $ipdWards);
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
                        <label class="type-opt">
                            <input type="radio" name="admission_type" value="<?= htmlspecialchars($t['admission_type']) ?>" <?= $i === 0 ? 'checked' : '' ?>>
                            <span class="type-body">
                                <span class="type-name"><?= htmlspecialchars($admTypeLabels[$t['admission_type']] ?? $t['admission_type']) ?></span>
                                <span class="type-rate">Rs <?= number_format((float) $t['rate_amount']) ?>/<?= $t['rate_basis'] === 'DAILY' ? 'day' : 'hr' ?></span>
                            </span>
                        </label>
                        <?php endforeach; ?>
                        <?php if ($admitShowIpd): ?>
                        <!-- Third route: In-Door. Same picker, different module — the
                             JS below re-points the form at the IPD handler. -->
                        <label class="type-opt">
                            <input type="radio" name="admission_type" value="__IPD__" id="admitTypeIpd" <?= $admTypes ? '' : 'checked' ?> <?= $ipdWards ? '' : 'disabled' ?>>
                            <span class="type-body">
                                <span class="type-name">In-Door (IPD)</span>
                                <span class="type-rate"><?= $ipdWards ? 'Ward admission &mdash; per-day room rate' : 'No wards enabled' ?></span>
                            </span>
                        </label>
                        <?php endif; ?>
                        <?php if (!$admTypes && !($admitShowIpd && $ipdWards)): ?>
                        <div class="muted">No admission types are enabled. Set them under ER Services &amp; Rates.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ---- Hourly/room admission fields ---- -->
                <div id="admitOpdFields">
                    <div class="admit-field">
                        <label>Admitting doctor</label>
                        <select name="admitting_doctor_id" id="admitDoctor">
                            <option value="">— manual entry below —</option>
                            <?php foreach ($admDoctors as $d): ?>
                            <option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="admitting_doctor_manual" id="admitDoctorManual" class="uc" placeholder="Or type the doctor's name" style="margin-top:8px;">
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
                        <label>Admitting consultant</label>
                        <select name="admitting_consultant_id" id="admitIpdDoctor">
                            <option value="">&mdash; manual entry below &mdash;</option>
                            <?php foreach ($ipdDoctors as $d): ?>
                            <option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="admitting_consultant_manual" id="admitIpdDoctorManual" class="uc" placeholder="Or type the consultant's name" style="margin-top:8px;">
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
function openAdmit(id, patientName, doctorId, doctorName, byPatient) {
    var vEl = document.getElementById('admitVisitId');
    var pEl = document.getElementById('admitPatientId');
    if (byPatient) { pEl.value = id; vEl.value = ''; }
    else { vEl.value = id; pEl.value = ''; }
    document.getElementById('admitTitle').textContent = patientName || 'Patient';
    var sel = document.getElementById('admitDoctor');
    if (doctorId && sel.querySelector('option[value="' + doctorId + '"]')) {
        sel.value = String(doctorId);
        document.getElementById('admitDoctorManual').value = '';
    }
    <?php if ($admitShowIpd): ?>
    // Preselect the consultant too — the same doctor is the likely admitter on
    // either route, and the picker starts on whichever route is checked.
    var isel = document.getElementById('admitIpdDoctor');
    if (doctorId && isel && isel.querySelector('option[value="' + doctorId + '"]')) {
        isel.value = String(doctorId);
        document.getElementById('admitIpdDoctorManual').value = '';
    }
    admitSyncRoute();
    <?php endif; ?>
    document.getElementById('admitOverlay').classList.add('open');
}
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

    // Only the visible route's fields may block submission.
    var reqs = document.querySelectorAll('#admitIpdFields [data-ipd-required]');
    for (var i = 0; i < reqs.length; i++) { reqs[i].required = isIpd; }
}
document.addEventListener('change', function (e) {
    if (e.target && e.target.name === 'admission_type') { admitSyncRoute(); }
});
// Strip the sentinel value before it reaches the IPD handler.
document.getElementById('admitForm').addEventListener('submit', function () {
    var ipdRadio = document.getElementById('admitTypeIpd');
    if (ipdRadio && ipdRadio.checked) { ipdRadio.disabled = true; }
});
admitSyncRoute();
<?php endif; ?>
</script>
